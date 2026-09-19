<?php
/**
 * Campaigns page: list, create/edit, pre-flight, schedule/start/pause/cancel, test send.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Admin\Pages;

use IBG\Outreach\Admin\Tables\Campaigns_List_Table;
use IBG\Outreach\Campaigns\Campaign;
use IBG\Outreach\Campaigns\Campaign_Service;
use IBG\Outreach\Capabilities;
use IBG\Outreach\Contacts\Contact;
use IBG\Outreach\Email\Merge_Context;
use IBG\Outreach\Lists\Contact_List;

defined( 'ABSPATH' ) || exit;

/**
 * Class Campaigns_Page
 */
final class Campaigns_Page extends Abstract_Page {

	public const SLUG = 'ibg-outreach-campaigns';

	private const NONCE_SAVE   = 'ibg_save_campaign';
	private const NONCE_STATE  = 'ibg_campaign_state';
	private const NONCE_TEST   = 'ibg_campaign_test';
	private const NONCE_ACTION = 'ibg_campaign_action_';

	/**
	 * List table.
	 *
	 * @var Campaigns_List_Table|null
	 */
	private ?Campaigns_List_Table $table = null;

	/**
	 * Save errors.
	 *
	 * @var \WP_Error|null
	 */
	private ?\WP_Error $form_error = null;

	/**
	 * Submitted values from a failed save.
	 *
	 * @var array<string, mixed>
	 */
	private array $form_data = array();

	/** @inheritDoc */
	public function get_slug(): string {
		return self::SLUG;
	}

	/** @inheritDoc */
	public function get_page_title(): string {
		return __( 'Campaigns', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_menu_title(): string {
		return __( 'Campaigns', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_capability(): string {
		return Capabilities::VIEW;
	}

	/** @inheritDoc */
	public function get_position(): int {
		return 50;
	}

	/** @inheritDoc */
	public function register_hooks(): void {
		add_filter(
			'set_screen_option_' . Campaigns_List_Table::PER_PAGE_OPTION,
			static fn( $status, $option, $value ): int => max( 1, min( 200, (int) $value ) ),
			10,
			3
		);
	}

	/**
	 * Nonce-protected row action URL.
	 *
	 * @param string $action duplicate|delete.
	 * @param int    $id     Campaign id.
	 * @return string
	 */
	public function get_action_url( string $action, int $id ): string {
		return wp_nonce_url(
			$this->get_url(
				array(
					'action' => $action,
					'id'     => $id,
				)
			),
			self::NONCE_ACTION . $action . '_' . $id
		);
	}

	/** @inheritDoc */
	public function load(): void {
		$this->require_capability();

		$view = $this->current_view();

		if ( 'list' === $view ) {
			add_screen_option(
				'per_page',
				array(
					'label'   => __( 'Campaigns per page', 'ibg-client-outreach' ),
					'default' => 20,
					'option'  => Campaigns_List_Table::PER_PAGE_OPTION,
				)
			);
			$this->table = new Campaigns_List_Table(
				$this->plugin->get( 'campaigns' ),
				array_map( static fn( Contact_List $l ): string => $l->name, $this->plugin->get( 'lists' )->all() ),
				$this->plugin->get( 'templates' )->get_options( false ),
				$this,
				current_user_can( Capabilities::MANAGE_CAMPAIGNS )
			);
			$this->handle_row_action();
			$this->handle_bulk_delete();
			return;
		}

		if ( 'add' === $view ) {
			$this->require_capability( Capabilities::MANAGE_CAMPAIGNS );
		}

		$this->handle_state_change();
		$this->handle_test_send();
		$this->handle_save( $view );
	}

	/** @inheritDoc */
	public function render(): void {
		$this->require_capability();

		$view = $this->current_view();

		if ( 'list' === $view ) {
			$this->table->prepare_items();
			$this->render_view(
				'campaigns-list',
				array(
					'page'       => $this,
					'table'      => $this->table,
					'can_manage' => current_user_can( Capabilities::MANAGE_CAMPAIGNS ),
				)
			);
			return;
		}

		$campaign = null;
		if ( 'edit' === $view ) {
			$campaign = $this->plugin->get( 'campaigns' )->find( $this->requested_id() );
			if ( ! $campaign ) {
				wp_die( esc_html__( 'Campaign not found.', 'ibg-client-outreach' ), 404 );
			}
		}

		$settings = $this->plugin->get( 'settings' );
		$values   = array(
			'name'        => $campaign ? $campaign->name : '',
			'subject'     => $campaign ? $campaign->subject : '',
			'from_name'   => $campaign ? $campaign->from_name : (string) $settings->get( 'from_name', '' ),
			'from_email'  => $campaign ? $campaign->from_email : (string) $settings->get( 'from_email', '' ),
			'reply_to'    => $campaign ? $campaign->reply_to : (string) $settings->get( 'reply_to', '' ),
			'template_id' => $campaign ? (int) $campaign->template_id : 0,
			'list_id'     => $campaign ? (int) $campaign->list_id : 0,
			'scope'       => $campaign ? $campaign->scope : Campaign::SCOPE_SUBSCRIBED,
		);
		if ( ! empty( $this->form_data ) ) {
			$values = array_merge( $values, $this->form_data );
		}

		/** @var Campaign_Service $service */
		$service = $this->plugin->get( 'campaign_service' );
		$lists   = $this->plugin->get( 'lists' )->all();

		$summary   = null;
		$preflight = array();
		if ( $campaign ) {
			$summary   = $this->plugin->get( 'audience' )->summarize( $campaign );
			$preflight = $service->preflight( $campaign );
		}

		$this->render_view(
			'campaign-form',
			array(
				'page'            => $this,
				'campaign'        => $campaign,
				'values'          => $values,
				'error'           => $this->form_error,
				'nonce'           => self::NONCE_SAVE,
				'state_nonce'     => self::NONCE_STATE,
				'test_nonce'      => self::NONCE_TEST,
				'templates'       => $this->plugin->get( 'templates' )->get_options( true ),
				'template_names'  => $this->plugin->get( 'templates' )->get_options( false ),
				'lists'           => array_filter( $lists, static fn( Contact_List $l ): bool => ! $l->is_segment() ),
				'segments'        => array_filter( $lists, static fn( Contact_List $l ): bool => $l->is_segment() ),
				'summary'         => $summary,
				'preflight'       => $preflight,
				'can_manage'      => current_user_can( Capabilities::MANAGE_CAMPAIGNS ),
				'can_send'        => current_user_can( Capabilities::SEND_CAMPAIGNS ),
				'test_email'      => wp_get_current_user()->user_email,
				'sample_contacts' => $this->plugin->get( 'contacts' )->query( array( 'per_page' => 25, 'orderby' => 'updated_at' ) )['items'],
				'list_url'        => $this->get_url(),
				'templates_url'   => add_query_arg( 'page', Templates_Page::SLUG, admin_url( 'admin.php' ) ),
				'settings_url'    => add_query_arg( 'page', Settings_Page::SLUG, admin_url( 'admin.php' ) ),
				'delete_url'      => $campaign ? $this->get_action_url( 'delete', $campaign->id ) : '',
			)
		);
	}

	/**
	 * Current sub-view.
	 *
	 * @return string
	 */
	private function current_view(): string {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $view, array( 'list', 'add', 'edit' ), true ) ? $view : 'list';
	}

	/**
	 * Requested id.
	 *
	 * @return int
	 */
	private function requested_id(): int {
		return isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Edit URL for a campaign.
	 *
	 * @param int $id Campaign id.
	 * @return string
	 */
	private function edit_url( int $id ): string {
		return $this->get_url(
			array(
				'view' => 'edit',
				'id'   => $id,
			)
		);
	}

	/**
	 * Save draft.
	 *
	 * @param string $view add|edit.
	 * @return void
	 */
	private function handle_save( string $view ): void {
		if ( ! isset( $_POST['ibg_action'] ) || 'save_campaign' !== $_POST['ibg_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$this->require_capability( Capabilities::MANAGE_CAMPAIGNS );
		check_admin_referer( self::NONCE_SAVE );

		$raw  = isset( $_POST['campaign'] ) && is_array( $_POST['campaign'] ) ? wp_unslash( $_POST['campaign'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by Campaign_Service.
		$data = array();
		foreach ( array( 'name', 'subject', 'from_name', 'from_email', 'reply_to', 'template_id', 'list_id', 'scope' ) as $field ) {
			$data[ $field ] = (string) ( $raw[ $field ] ?? '' );
		}

		$result = $this->plugin->get( 'campaign_service' )->save( $data, 'edit' === $view ? $this->requested_id() : 0 );

		if ( is_wp_error( $result ) ) {
			$this->form_error = $result;
			$this->form_data  = array_map( 'sanitize_text_field', $data );
			$this->form_data['template_id'] = (int) $data['template_id'];
			$this->form_data['list_id']     = (int) $data['list_id'];
			return;
		}

		$this->notices->add( 'edit' === $view ? __( 'Campaign saved.', 'ibg-client-outreach' ) : __( 'Campaign created as a draft.', 'ibg-client-outreach' ), 'success' );
		wp_safe_redirect( $this->edit_url( $result->id ) );
		exit;
	}

	/**
	 * Schedule / unschedule / start / pause / resume / cancel.
	 *
	 * @return void
	 */
	private function handle_state_change(): void {
		if ( ! isset( $_POST['ibg_action'] ) || 'campaign_state' !== $_POST['ibg_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		check_admin_referer( self::NONCE_STATE );
		$this->require_capability( Capabilities::SEND_CAMPAIGNS );

		$campaign = $this->plugin->get( 'campaigns' )->find( $this->requested_id() );
		if ( ! $campaign ) {
			wp_die( esc_html__( 'Campaign not found.', 'ibg-client-outreach' ), 404 );
		}

		$transition = isset( $_POST['transition'] ) ? sanitize_key( wp_unslash( $_POST['transition'] ) ) : '';

		/** @var Campaign_Service $service */
		$service = $this->plugin->get( 'campaign_service' );

		switch ( $transition ) {
			case 'schedule':
				$when   = isset( $_POST['scheduled_at'] ) ? sanitize_text_field( wp_unslash( $_POST['scheduled_at'] ) ) : '';
				$result = $service->schedule( $campaign, $when );
				$ok     = __( 'Campaign scheduled.', 'ibg-client-outreach' );
				break;
			case 'unschedule':
				$result = $service->unschedule( $campaign );
				$ok     = __( 'Campaign moved back to draft.', 'ibg-client-outreach' );
				break;
			case 'start':
				$result = $service->start( $campaign, 'admin' );
				$ok     = __( 'Campaign started. Emails are queued and sent in batches.', 'ibg-client-outreach' );
				break;
			case 'pause':
				$result = $service->pause( $campaign );
				$ok     = __( 'Campaign paused. Queued emails will not be sent until you resume.', 'ibg-client-outreach' );
				break;
			case 'resume':
				$result = $service->resume( $campaign );
				$ok     = __( 'Campaign resumed.', 'ibg-client-outreach' );
				break;
			case 'cancel':
				$result = $service->cancel( $campaign );
				$ok     = __( 'Campaign cancelled. Unsent emails are skipped.', 'ibg-client-outreach' );
				break;
			default:
				$result = new \WP_Error( 'invalid', __( 'Unknown action.', 'ibg-client-outreach' ) );
				$ok     = '';
		}

		if ( is_wp_error( $result ) ) {
			$this->notices->add( $result->get_error_message(), 'error' );
		} else {
			$this->notices->add( $ok, 'success' );
		}

		wp_safe_redirect( $this->edit_url( $campaign->id ) );
		exit;
	}

	/**
	 * Send a test email for the campaign.
	 *
	 * @return void
	 */
	private function handle_test_send(): void {
		if ( ! isset( $_POST['ibg_action'] ) || 'send_test' !== $_POST['ibg_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		check_admin_referer( self::NONCE_TEST );
		$this->require_capability( Capabilities::SEND_CAMPAIGNS );

		$campaign = $this->plugin->get( 'campaigns' )->find( $this->requested_id() );
		if ( ! $campaign ) {
			wp_die( esc_html__( 'Campaign not found.', 'ibg-client-outreach' ), 404 );
		}

		$to         = isset( $_POST['test_email'] ) ? sanitize_email( wp_unslash( $_POST['test_email'] ) ) : '';
		$contact_id = isset( $_POST['sample_contact'] ) ? absint( wp_unslash( $_POST['sample_contact'] ) ) : 0;

		if ( ! is_email( $to ) ) {
			$this->notices->add( __( 'Enter a valid email address for the test.', 'ibg-client-outreach' ), 'error' );
			wp_safe_redirect( $this->edit_url( $campaign->id ) );
			exit;
		}

		// Use the snapshot once started, otherwise the live template.
		$body_html = $campaign->body_html;
		$body_text = $campaign->body_text;
		if ( ! $campaign->has_started() ) {
			$template = $campaign->template_id ? $this->plugin->get( 'templates' )->find( $campaign->template_id ) : null;
			if ( ! $template ) {
				$this->notices->add( __( 'Choose a template before sending a test.', 'ibg-client-outreach' ), 'error' );
				wp_safe_redirect( $this->edit_url( $campaign->id ) );
				exit;
			}
			$body_html = $template->body_html;
			$body_text = $template->body_text;
		}

		$contact = $contact_id > 0 ? $this->plugin->get( 'contacts' )->find( $contact_id ) : null;
		$context = $contact instanceof Contact ? new Merge_Context( $contact, $campaign->id, 0, true ) : Merge_Context::sample();

		$message = $this->plugin->get( 'composer' )->compose(
			array(
				'to_email'   => $to,
				'subject'    => $campaign->subject,
				'body_html'  => $body_html,
				'body_text'  => $body_text,
				'from_name'  => $campaign->from_name,
				'from_email' => $campaign->from_email,
				'reply_to'   => $campaign->reply_to,
				'context'    => $context,
				'is_test'    => true,
			)
		);

		$provider = $this->plugin->get( 'providers' )->get_active();
		$result   = $provider->send( $message );

		$this->notices->add(
			$result->is_success()
				/* translators: 1: email, 2: provider */
				? sprintf( __( 'Test email sent to %1$s via %2$s.', 'ibg-client-outreach' ), $to, $provider->get_name() )
				/* translators: %s: error */
				: sprintf( __( 'Test email failed: %s', 'ibg-client-outreach' ), $result->get_error() ),
			$result->is_success() ? 'success' : 'error'
		);

		wp_safe_redirect( $this->edit_url( $campaign->id ) );
		exit;
	}

	/**
	 * Duplicate / delete row actions.
	 *
	 * @return void
	 */
	private function handle_row_action(): void {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id     = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $action, array( 'duplicate', 'delete' ), true ) || $id <= 0 ) {
			return;
		}

		$this->require_capability( Capabilities::MANAGE_CAMPAIGNS );
		check_admin_referer( self::NONCE_ACTION . $action . '_' . $id );

		/** @var Campaign_Service $service */
		$service = $this->plugin->get( 'campaign_service' );

		if ( 'duplicate' === $action ) {
			$copy = $service->duplicate( $id );
			if ( is_wp_error( $copy ) ) {
				$this->notices->add( $copy->get_error_message(), 'error' );
				wp_safe_redirect( $this->get_url() );
			} else {
				$this->notices->add( __( 'Campaign duplicated as a draft.', 'ibg-client-outreach' ), 'success' );
				wp_safe_redirect( $this->edit_url( $copy->id ) );
			}
			exit;
		}

		$this->report_delete( $service->delete( array( $id ) ) );
		wp_safe_redirect( $this->get_url( $this->table->get_filter_args() ) );
		exit;
	}

	/**
	 * Bulk delete.
	 *
	 * @return void
	 */
	private function handle_bulk_delete(): void {
		if ( 'delete' !== $this->table->current_action() ) {
			return;
		}
		$this->require_capability( Capabilities::MANAGE_CAMPAIGNS );
		check_admin_referer( 'bulk-campaigns' );

		$ids = isset( $_REQUEST['campaign'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['campaign'] ) ) : array();
		$this->report_delete( $this->plugin->get( 'campaign_service' )->delete( $ids ) );

		wp_safe_redirect( $this->get_url( $this->table->get_filter_args() ) );
		exit;
	}

	/**
	 * Flash delete results.
	 *
	 * @param array{deleted:int, blocked:int} $result Result.
	 * @return void
	 */
	private function report_delete( array $result ): void {
		if ( $result['deleted'] > 0 ) {
			/* translators: %d: number */
			$this->notices->add( sprintf( _n( '%d campaign deleted.', '%d campaigns deleted.', $result['deleted'], 'ibg-client-outreach' ), $result['deleted'] ), 'success' );
		}
		if ( $result['blocked'] > 0 ) {
			/* translators: %d: number */
			$this->notices->add( sprintf( _n( '%d running campaign was not deleted. Cancel it first.', '%d running campaigns were not deleted. Cancel them first.', $result['blocked'], 'ibg-client-outreach' ), $result['blocked'] ), 'warning' );
		}
	}
}
