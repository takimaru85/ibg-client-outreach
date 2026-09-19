<?php
/**
 * Opt-in open/click tracking.
 *
 * Nothing is injected unless the corresponding setting is enabled, and never
 * into test emails. Every URL is signed with the queue and contact ids; the
 * click redirect also signs the destination so it cannot be abused as an open
 * redirector. No IP address or user agent is stored.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Analytics;

use IBG\Outreach\Contacts\Contact_Repository;
use IBG\Outreach\Database;
use IBG\Outreach\Email\Merge_Context;
use IBG\Outreach\Events\Event_Repository;
use IBG\Outreach\Queue\Queue_Repository;
use IBG\Outreach\Settings;
use IBG\Outreach\Signer;

defined( 'ABSPATH' ) || exit;

/**
 * Class Tracking
 */
final class Tracking {

	public const QUERY_VAR = 'ibg_track';

	/**
	 * Constructor.
	 *
	 * @param Signer             $signer   Signer.
	 * @param Settings           $settings Settings.
	 * @param Event_Repository   $events   Events.
	 * @param Contact_Repository $contacts Contacts.
	 * @param Queue_Repository   $queue    Queue.
	 * @param Database           $db       Database.
	 */
	public function __construct(
		private readonly Signer $signer,
		private readonly Settings $settings,
		private readonly Event_Repository $events,
		private readonly Contact_Repository $contacts,
		private readonly Queue_Repository $queue,
		private readonly Database $db
	) {}

	/**
	 * Hook the public endpoint.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_handle' ), 1 );
	}

	/**
	 * Whether open tracking is enabled.
	 *
	 * @return bool
	 */
	public function opens_enabled(): bool {
		return (bool) $this->settings->get( 'track_opens', false );
	}

	/**
	 * Whether click tracking is enabled.
	 *
	 * @return bool
	 */
	public function clicks_enabled(): bool {
		return (bool) $this->settings->get( 'track_clicks', false );
	}

	/**
	 * Rewrite links and append the pixel for a real campaign email.
	 *
	 * @param string        $html Rendered HTML document.
	 * @param Merge_Context $ctx  Context.
	 * @return string
	 */
	public function instrument_html( string $html, Merge_Context $ctx ): string {
		if ( ! $ctx->has_real_contact() || $ctx->is_test || $ctx->queue_id <= 0 ) {
			return $html;
		}

		$queue_id   = $ctx->queue_id;
		$contact_id = $ctx->contact->id;

		if ( $this->clicks_enabled() ) {
			$html = (string) preg_replace_callback(
				'#href=(["\'])(https?://[^"\']+)\1#i',
				function ( array $m ) use ( $queue_id, $contact_id ): string {
					$url = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
					if ( str_contains( $url, 'ibg_unsubscribe=' ) || str_contains( $url, self::QUERY_VAR . '=' ) ) {
						return $m[0];
					}
					return 'href="' . esc_url( $this->click_url( $queue_id, $contact_id, $url ) ) . '"';
				},
				$html
			);
		}

		if ( $this->opens_enabled() ) {
			$pixel = '<img src="' . esc_url( $this->open_url( $queue_id, $contact_id ) ) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;" />';
			$html  = preg_match( '#</body>#i', $html )
				? (string) preg_replace( '#</body>#i', $pixel . '</body>', $html, 1 )
				: $html . $pixel;
		}

		return $html;
	}

	/**
	 * Signed open-pixel URL.
	 *
	 * @param int $queue_id   Queue row id.
	 * @param int $contact_id Contact id.
	 * @return string
	 */
	public function open_url( int $queue_id, int $contact_id ): string {
		return add_query_arg(
			array(
				self::QUERY_VAR => 'open',
				't'             => $queue_id . '.' . $contact_id . '.' . $this->signer->sign( "open|{$queue_id}|{$contact_id}" ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Signed click-redirect URL.
	 *
	 * @param int    $queue_id   Queue row id.
	 * @param int    $contact_id Contact id.
	 * @param string $url        Destination.
	 * @return string
	 */
	public function click_url( int $queue_id, int $contact_id, string $url ): string {
		return add_query_arg(
			array(
				self::QUERY_VAR => 'click',
				't'             => $queue_id . '.' . $contact_id . '.' . $this->signer->sign( "click|{$queue_id}|{$contact_id}" ),
				'u'             => $url,
				's'             => $this->signer->sign( "url|{$queue_id}|{$contact_id}|{$url}" ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Serve the pixel or perform the redirect.
	 *
	 * @return void
	 */
	public function maybe_handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public endpoint authenticated by HMAC.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		$kind  = sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		$token = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';
		// phpcs:enable

		$parsed = $this->parse_token( $kind, $token );

		if ( 'open' === $kind ) {
			if ( $parsed && $this->opens_enabled() ) {
				$this->record( 'email.opened', $parsed[0], $parsed[1] );
			}
			$this->serve_pixel();
		}

		if ( 'click' === $kind ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$url = isset( $_GET['u'] ) ? sanitize_text_field( wp_unslash( $_GET['u'] ) ) : '';
			$sig = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
			// phpcs:enable

			if ( ! $parsed || ! wp_http_validate_url( $url ) || ! $this->signer->verify( "url|{$parsed[0]}|{$parsed[1]}|{$url}", $sig ) ) {
				status_header( 400 );
				nocache_headers();
				wp_die( esc_html__( 'This link is not valid.', 'ibg-client-outreach' ), '', array( 'response' => 400 ) );
			}

			if ( $this->clicks_enabled() ) {
				$this->record( 'email.clicked', $parsed[0], $parsed[1], array( 'url' => $url ) );
			}

			nocache_headers();
			wp_redirect( esc_url_raw( $url ), 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external destination signed by us.
			exit;
		}
	}

	/**
	 * Validate "queue.contact.signature".
	 *
	 * @param string $kind  open|click.
	 * @param string $token Token.
	 * @return array{0:int,1:int}|null [queue_id, contact_id].
	 */
	private function parse_token( string $kind, string $token ): ?array {
		if ( ! preg_match( '/^(\d{1,20})\.(\d{1,20})\.([a-f0-9]{40})$/', $token, $m ) ) {
			return null;
		}
		$queue_id   = (int) $m[1];
		$contact_id = (int) $m[2];
		if ( ! $this->signer->verify( "{$kind}|{$queue_id}|{$contact_id}", $m[3] ) ) {
			return null;
		}
		return array( $queue_id, $contact_id );
	}

	/**
	 * Store the event and bump the contact's last_* timestamp.
	 *
	 * @param string               $type       email.opened|email.clicked.
	 * @param int                  $queue_id   Queue row id.
	 * @param int                  $contact_id Contact id.
	 * @param array<string, mixed> $data       Extra data.
	 * @return void
	 */
	private function record( string $type, int $queue_id, int $contact_id, array $data = array() ): void {
		$item        = $this->queue->find( $queue_id );
		$campaign_id = $item ? $item->campaign_id : 0;

		$data['user_id'] = 0; // Public request: never attribute to a logged-in admin previewing.
		$this->events->log( $type, $contact_id, $data, $campaign_id, $queue_id );

		$column = 'email.opened' === $type ? 'last_opened_at' : 'last_clicked_at';
		$this->contacts->update_many( array( $contact_id ), array( $column => $this->db->now() ) );

		/**
		 * Fires when an open or click is recorded.
		 *
		 * @param string $type        Event type.
		 * @param int    $contact_id  Contact id.
		 * @param int    $campaign_id Campaign id.
		 * @param int    $queue_id    Queue row id.
		 * @param array  $data        Extra data.
		 */
		do_action( 'ibg_outreach_tracking_event', $type, $contact_id, $campaign_id, $queue_id, $data );
	}

	/**
	 * Output a 1×1 transparent GIF and stop.
	 *
	 * @return never
	 */
	private function serve_pixel(): never {
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: image/gif' );
		header( 'Content-Length: 43' );
		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary image.
		exit;
	}
}
