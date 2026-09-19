<?php
/**
 * Suppression list page: view, add, bulk add, remove, export.
 *
 * Removing an entry always goes through Contact_Service with an explicit
 * admin confirmation so the contact record and the suppression list agree.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Admin\Pages;

use IBG\Outreach\Admin\Tables\Suppressions_List_Table;
use IBG\Outreach\Capabilities;
use IBG\Outreach\Contacts\Contact;
use IBG\Outreach\Contacts\Contact_Service;
use IBG\Outreach\Csv;
use IBG\Outreach\Unsubscribe\Suppression_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Class Suppressions_Page
 */
final class Suppressions_Page extends Abstract_Page {

	public const SLUG = 'ibg-outreach-suppressions';

	private const NONCE_ADD    = 'ibg_add_suppression';
	private const NONCE_REMOVE = 'ibg_remove_suppression_';
	private const NONCE_EXPORT = 'ibg_export_suppressions';
	private const EXPORT_ACTION = 'ibg_outreach_export_suppressions';

	/**
	 * Table.
	 *
	 * @var Suppressions_List_Table|null
	 */
	private ?Suppressions_List_Table $table = null;

	/** @inheritDoc */
	public function get_slug(): string {
		return self::SLUG;
	}

	/** @inheritDoc */
	public function get_page_title(): string {
		return __( 'Suppression List', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_menu_title(): string {
		return __( 'Suppressions', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_capability(): string {
		return Capabilities::VIEW;
	}

	/** @inheritDoc */
	public function get_position(): int {
		return 45;
	}

	/** @inheritDoc */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'export' ) );
		add_filter(
			'set_screen_option_' . Suppressions_List_Table::PER_PAGE_OPTION,
			static fn( $status, $option, $value ): int => max( 1, min( 500, (int) $value ) ),
			10,
			3
		);
	}

	/**
	 * Nonce-protected remove URL.
	 *
	 * @param int $id Suppression row id.
	 * @return string
	 */
	public function get_remove_url( int $id ): string {
		return wp_nonce_url(
			$this->get_url(
				array(
					'action' => 'remove',
					'id'     => $id,
				)
			),
			self::NONCE_REMOVE . $id
		);
	}

	/** @inheritDoc */
	public function load(): void {
		$this->require_capability();

		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Entries per page', 'ibg-client-outreach' ),
				'default' => 50,
				'option'  => Suppressions_List_Table::PER_PAGE_OPTION,
			)
		);

		$this->table = new Suppressions_List_Table( $this->plugin->get( 'suppressions' ), $this, current_user_can( Capabilities::MANAGE_CONTACTS ) );

		$this->handle_add();
		$this->handle_single_remove();
		$this->handle_bulk_remove();
		$this->redirect_clean_filter_form();
	}

	/** @inheritDoc */
	public function render(): void {
		$this->require_capability();
		$this->table->prepare_items();

		$this->render_view(
			'suppressions',
			array(
				'page'       => $this,
				'table'      => $this->table,
				'can_manage' => current_user_can( Capabilities::MANAGE_CONTACTS ),
				'nonce'      => self::NONCE_ADD,
				'reasons'    => array_intersect_key( Suppression_Repository::reasons(), array_flip( array( Suppression_Repository::REASON_UNSUBSCRIBED, Suppression_Repository::REASON_DNC ) ) ),
				'export_url' => wp_nonce_url( add_query_arg( 'action', self::EXPORT_ACTION, admin_url( 'admin-post.php' ) ), self::NONCE_EXPORT ),
			)
		);
	}

	/**
	 * admin-post: stream the suppression list as CSV.
	 *
	 * @return void
	 */
	public function export(): void {
		check_admin_referer( self::NONCE_EXPORT );
		if ( ! current_user_can( Capabilities::MANAGE_CONTACTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'ibg-client-outreach' ), 403 );
		}

		$csv = new Csv( 'ibg-suppressions-' . gmdate( 'Y-m-d' ) . '.csv' );
		$csv->row( array( 'email', 'email_hash', 'reason', 'source', 'created_at_utc' ) );
		foreach ( $this->plugin->get( 'suppressions' )->iterate() as $row ) {
			$csv->row( array( (string) $row->email, (string) $row->email_hash, (string) $row->reason, (string) $row->source, (string) $row->created_at ) );
		}
		$csv->finish();
	}

	/**
	 * Add one or many addresses.
	 *
	 * @return void
	 */
	private function handle_add(): void {
		if ( ! isset( $_POST['ibg_action'] ) || 'add_suppressions' !== $_POST['ibg_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$this->require_capability( Capabilities::MANAGE_CONTACTS );
		check_admin_referer( self::NONCE_ADD );

		$reason = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : Suppression_Repository::REASON_UNSUBSCRIBED;
		if ( ! in_array( $reason, array( Suppression_Repository::REASON_UNSUBSCRIBED, Suppression_Repository::REASON_DNC ), true ) ) {
			$reason = Suppression_Repository::REASON_UNSUBSCRIBED;
		}

		$raw    = isset( $_POST['emails'] ) ? sanitize_textarea_field( wp_unslash( $_POST['emails'] ) ) : '';
		$emails = array_unique( array_filter( array_map( array( Contact_Service::class, 'normalize_email' ), preg_split( '/[\s,;]+/', $raw ) ?: array() ) ) );

		$added   = 0;
		$invalid = 0;

		/** @var Contact_Service $service */
		$service      = $this->plugin->get( 'contact_service' );
		$contacts     = $this->plugin->get( 'contacts' );
		$suppressions = $this->plugin->get( 'suppressions' );
		$status       = Suppression_Repository::REASON_DNC === $reason ? Contact::MARKETING_DNC : Contact::MARKETING_UNSUBSCRIBED;

		foreach ( $emails as $email ) {
			if ( ! is_email( $email ) ) {
				++$invalid;
				continue;
			}
			$contact = $contacts->find_by_email( $email );
			if ( $contact ) {
				$result = $service->update( $contact->id, array( 'marketing_status' => $status ), array( 'source' => Contact_Service::SOURCE_ADMIN ) );
				if ( ! is_wp_error( $result ) ) {
					++$added;
				}
			} elseif ( $suppressions->add( $email, $reason, Suppression_Repository::SOURCE_ADMIN ) ) {
				++$added;
			}
		}

		/* translators: 1: number added, 2: number invalid */
		$this->notices->add( sprintf( __( '%1$d address(es) suppressed, %2$d invalid entries ignored.', 'ibg-client-outreach' ), $added, $invalid ), $invalid > 0 ? 'warning' : 'success' );

		wp_safe_redirect( $this->get_url() );
		exit;
	}

	/**
	 * Row action remove.
	 *
	 * @return void
	 */
	private function handle_single_remove(): void {
		if ( ! isset( $_GET['action'] ) || 'remove' !== $_GET['action'] || ! isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$id = absint( wp_unslash( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->require_capability( Capabilities::MANAGE_CONTACTS );
		check_admin_referer( self::NONCE_REMOVE . $id );

		$count = $this->remove_entries( array( $id ) );
		/* translators: %d: number */
		$this->notices->add( sprintf( _n( '%d address removed from the suppression list.', '%d addresses removed from the suppression list.', $count, 'ibg-client-outreach' ), $count ), 'success' );

		wp_safe_redirect( $this->get_url( $this->table->get_filter_args() ) );
		exit;
	}

	/**
	 * Bulk remove.
	 *
	 * @return void
	 */
	private function handle_bulk_remove(): void {
		if ( 'remove' !== $this->table->current_action() ) {
			return;
		}

		$this->require_capability( Capabilities::MANAGE_CONTACTS );
		check_admin_referer( 'bulk-suppressions' );

		$ids   = isset( $_REQUEST['suppression'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['suppression'] ) ) : array();
		$count = $this->remove_entries( $ids );

		/* translators: %d: number */
		$this->notices->add( sprintf( _n( '%d address removed from the suppression list.', '%d addresses removed from the suppression list.', $count, 'ibg-client-outreach' ), $count ), 'success' );

		wp_safe_redirect( $this->get_url( $this->table->get_filter_args() ) );
		exit;
	}

	/**
	 * Remove suppression rows, keeping contact records consistent.
	 *
	 * @param int[] $ids Row ids.
	 * @return int Removed.
	 */
	private function remove_entries( array $ids ): int {
		/** @var Suppression_Repository $suppressions */
		$suppressions = $this->plugin->get( 'suppressions' );
		/** @var Contact_Service $service */
		$service  = $this->plugin->get( 'contact_service' );
		$contacts = $this->plugin->get( 'contacts' );
		$count    = 0;

		foreach ( array_filter( array_map( 'intval', $ids ) ) as $id ) {
			$row = $suppressions->find_by_id( $id );
			if ( ! $row ) {
				continue;
			}

			$contact = '' !== (string) $row->email ? $contacts->find_by_email( (string) $row->email ) : null;

			if ( $contact && $contact->is_suppressed() ) {
				// Lifts the block on the contact AND deletes the suppression row (service does both).
				$result = $service->update(
					$contact->id,
					array( 'marketing_status' => Contact::MARKETING_PENDING ),
					array(
						'source'              => Contact_Service::SOURCE_ADMIN,
						'confirm_resubscribe' => true,
					)
				);
				if ( ! is_wp_error( $result ) ) {
					++$count;
				}
				continue;
			}

			// No contact (or an erased address known only by hash): delete the row directly.
			if ( $suppressions->delete_by_id( $id ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Clean the URL after a filter submit.
	 *
	 * @return void
	 */
	private function redirect_clean_filter_form(): void {
		if ( ! isset( $_GET['filter_action'] ) && ! isset( $_GET['_wp_http_referer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		wp_safe_redirect( $this->get_url( $this->table->get_filter_args() ) );
		exit;
	}
}
