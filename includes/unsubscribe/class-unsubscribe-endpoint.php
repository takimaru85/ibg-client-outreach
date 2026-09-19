<?php
/**
 * Public unsubscribe endpoint: /?ibg_unsubscribe=<token>
 *
 * - GET shows a confirmation page (link scanners and mail-client prefetch must
 *   never unsubscribe anyone).
 * - POST with ibg_action=unsubscribe performs it.
 * - POST with List-Unsubscribe=One-Click (RFC 8058) performs it silently.
 * - Resubscribe is offered only for plain unsubscribes, never Do Not Contact.
 *
 * No IP address or user agent is stored.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Unsubscribe;

use IBG\Outreach\Contacts\Contact;
use IBG\Outreach\Contacts\Contact_Repository;
use IBG\Outreach\Contacts\Contact_Service;
use IBG\Outreach\Events\Event_Repository;
use IBG\Outreach\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Unsubscribe_Endpoint
 */
final class Unsubscribe_Endpoint {

	public const EVENT_UNSUBSCRIBED = 'contact.unsubscribed';
	public const EVENT_RESUBSCRIBED = 'contact.resubscribed';

	/**
	 * Constructor.
	 *
	 * @param Unsubscribe_Token  $token    Token helper.
	 * @param Contact_Repository $contacts Contacts.
	 * @param Contact_Service    $service  Contact service.
	 * @param Event_Repository   $events   Events.
	 * @param Settings           $settings Settings.
	 */
	public function __construct(
		private readonly Unsubscribe_Token $token,
		private readonly Contact_Repository $contacts,
		private readonly Contact_Service $service,
		private readonly Event_Repository $events,
		private readonly Settings $settings
	) {}

	/**
	 * Hook early in the front-end request, before any theme output.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_handle' ), 1 );
	}

	/**
	 * Handle the request if it carries our query var.
	 *
	 * @return void
	 */
	public function maybe_handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- public endpoint authenticated by the signed token.
		if ( ! isset( $_GET[ Unsubscribe_Token::QUERY_VAR ] ) && ! isset( $_POST[ Unsubscribe_Token::QUERY_VAR ] ) ) {
			return;
		}

		$raw_token   = isset( $_POST[ Unsubscribe_Token::QUERY_VAR ] ) ? $_POST[ Unsubscribe_Token::QUERY_VAR ] : $_GET[ Unsubscribe_Token::QUERY_VAR ];
		$token       = sanitize_text_field( wp_unslash( (string) $raw_token ) );
		$campaign_id = isset( $_REQUEST['c'] ) ? absint( wp_unslash( $_REQUEST['c'] ) ) : 0;
		$is_post     = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] );
		$action      = $is_post && isset( $_POST['ibg_action'] ) ? sanitize_key( wp_unslash( $_POST['ibg_action'] ) ) : '';
		$one_click   = $is_post && isset( $_POST['List-Unsubscribe'] ) && 'One-Click' === sanitize_text_field( wp_unslash( $_POST['List-Unsubscribe'] ) );
		// phpcs:enable

		if ( Unsubscribe_Token::TEST_TOKEN === $token ) {
			$this->render( 'test' );
		}

		$parsed = $this->token->parse( $token );
		if ( ! $parsed ) {
			$this->render( 'invalid' );
		}

		list( $contact_id, $signature ) = $parsed;
		$contact                        = $this->contacts->find( $contact_id );

		if ( ! $contact || ! $this->token->verify( $contact->id, $contact->email, $signature ) ) {
			$this->render( 'invalid' );
		}

		if ( $one_click ) {
			$this->unsubscribe( $contact, $campaign_id, 'one_click' );
			nocache_headers();
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Unsubscribed';
			exit;
		}

		if ( 'unsubscribe' === $action ) {
			$this->unsubscribe( $contact, $campaign_id, 'link' );
			$this->render( 'done', $contact, $campaign_id );
		}

		if ( 'resubscribe' === $action ) {
			if ( $this->resubscribe( $contact, $campaign_id ) ) {
				$this->render( 'resubscribed', $contact, $campaign_id );
			}
			$this->render( 'invalid' );
		}

		if ( $contact->is_suppressed() ) {
			$this->render( 'already', $contact, $campaign_id );
		}

		$this->render( 'confirm', $contact, $campaign_id );
	}

	/**
	 * Unsubscribe a contact (idempotent).
	 *
	 * @param Contact $contact     Contact.
	 * @param int     $campaign_id Campaign the link came from (0 if unknown).
	 * @param string  $source      link|one_click.
	 * @return void
	 */
	private function unsubscribe( Contact $contact, int $campaign_id, string $source ): void {
		if ( $contact->is_suppressed() ) {
			return;
		}

		$result = $this->service->update(
			$contact->id,
			array( 'marketing_status' => Contact::MARKETING_UNSUBSCRIBED ),
			array(
				'source'      => Contact_Service::SOURCE_PUBLIC,
				'campaign_id' => $campaign_id,
			)
		);

		if ( ! is_wp_error( $result ) ) {
			$this->events->log( self::EVENT_UNSUBSCRIBED, $contact->id, array( 'source' => $source ), $campaign_id );

			/**
			 * Fires after a recipient unsubscribed through the public endpoint.
			 *
			 * @param Contact $contact     Contact (state before the update).
			 * @param int     $campaign_id Campaign id or 0.
			 * @param string  $source      link|one_click.
			 */
			do_action( 'ibg_outreach_contact_unsubscribed', $contact, $campaign_id, $source );
		}
	}

	/**
	 * Resubscribe (only from a plain unsubscribe; restores "pending", not "subscribed").
	 *
	 * @param Contact $contact     Contact.
	 * @param int     $campaign_id Campaign id.
	 * @return bool
	 */
	private function resubscribe( Contact $contact, int $campaign_id ): bool {
		if ( Contact::MARKETING_UNSUBSCRIBED !== $contact->marketing_status ) {
			return false;
		}

		$result = $this->service->update(
			$contact->id,
			array( 'marketing_status' => Contact::MARKETING_PENDING ),
			array(
				'source'              => Contact_Service::SOURCE_PUBLIC,
				'confirm_resubscribe' => true,
				'campaign_id'         => $campaign_id,
			)
		);

		return ! is_wp_error( $result ) && Contact::MARKETING_PENDING === $result->marketing_status;
	}

	/**
	 * Output the standalone page and stop.
	 *
	 * @param string       $variant     test|invalid|confirm|done|already|resubscribed.
	 * @param Contact|null $contact     Contact.
	 * @param int          $campaign_id Campaign id.
	 * @return never
	 */
	private function render( string $variant, ?Contact $contact = null, int $campaign_id = 0 ): never {
		nocache_headers();
		status_header( 200 );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );

		$data = array(
			'variant'         => $variant,
			'masked_email'    => $contact ? self::mask_email( $contact->email ) : '',
			'token'           => $contact ? $this->token->create( $contact->id, $contact->email ) : '',
			'campaign_id'     => $campaign_id,
			'can_resubscribe' => $contact && Contact::MARKETING_UNSUBSCRIBED === $contact->marketing_status,
			'business_name'   => (string) $this->settings->get( 'business_name', get_bloginfo( 'name' ) ),
			'business_url'    => (string) $this->settings->get( 'business_website', home_url( '/' ) ),
			'privacy_url'     => (string) $this->settings->get( 'privacy_policy_url', '' ),
			'action_url'      => add_query_arg( Unsubscribe_Token::QUERY_VAR, $contact ? $this->token->create( $contact->id, $contact->email ) : Unsubscribe_Token::TEST_TOKEN, home_url( '/' ) ),
		);

		/**
		 * Filter the data passed to the unsubscribe page template.
		 *
		 * @param array $data Template data.
		 */
		$data = (array) apply_filters( 'ibg_outreach_unsubscribe_page_data', $data );

		$template = locate_template( 'ibg-outreach/unsubscribe.php' );
		if ( '' === $template ) {
			$template = IBG_OUTREACH_PATH . 'public/views/unsubscribe.php';
		}

		/**
		 * Filter the template file used for the unsubscribe page.
		 *
		 * @param string $template Absolute path.
		 */
		$template = (string) apply_filters( 'ibg_outreach_unsubscribe_template', $template );

		include $template;
		exit;
	}

	/**
	 * Mask an email for display: j***@example.com.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	public static function mask_email( string $email ): string {
		$at = strpos( $email, '@' );
		if ( false === $at ) {
			return '***';
		}
		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at );
		return mb_substr( $local, 0, 1 ) . '***' . $domain;
	}
}
