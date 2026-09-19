<?php
/**
 * Contacts page: list, add, edit, delete, bulk actions.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Admin\Pages;

use IBG\Outreach\Admin\Tables\Contacts_List_Table;
use IBG\Outreach\Capabilities;
use IBG\Outreach\Contacts\Contact;
use IBG\Outreach\Contacts\Contact_Repository;
use IBG\Outreach\Contacts\Contact_Service;
use IBG\Outreach\Events\Event_Repository;
use IBG\Outreach\Lists\Contact_List;
use IBG\Outreach\Lists\List_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Class Contacts_Page
 */
final class Contacts_Page extends Abstract_Page {

	public const SLUG = 'ibg-outreach-contacts';

	private const NONCE_SAVE   = 'ibg_save_contact';
	private const NONCE_DELETE = 'ibg_delete_contact_';

	/**
	 * List table (built in load()).
	 *
	 * @var Contacts_List_Table|null
	 */
	private ?Contacts_List_Table $table = null;

	/**
	 * Validation errors from a failed save, re-rendered with the form.
	 *
	 * @var \WP_Error|null
	 */
	private ?\WP_Error $form_errors = null;

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
		return __( 'Contacts', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_menu_title(): string {
		return __( 'Contacts', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_capability(): string {
		return Capabilities::VIEW;
	}

	/** @inheritDoc */
	public function get_position(): int {
		return 20;
	}

	/** @inheritDoc */
	public function register_hooks(): void {
		add_filter(
			'set_screen_option_' . Contacts_List_Table::PER_PAGE_OPTION,
			static fn( $status, $option, $value ): int => max( 1, min( 500, (int) $value ) ),
			10,
			3
		);
	}

	/**
	 * Nonce-protected delete URL for a contact.
	 *
	 * @param int $id Contact id.
	 * @return string
	 */
	public function get_delete_url( int $id ): string {
		return wp_nonce_url(
			$this->get_url(
				array(
					'action' => 'delete',
					'id'     => $id,
				)
			),
			self::NONCE_DELETE . $id
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
					'label'   => __( 'Contacts per page', 'ibg-client-outreach' ),
					'default' => 20,
					'option'  => Contacts_List_Table::PER_PAGE_OPTION,
				)
			);

			$this->table = new Contacts_List_Table(
				$this->plugin->get( 'contacts' ),
				$this->plugin->get( 'list_service' ),
				$this->plugin->get( 'lists' )->all(),
				$this,
				current_user_can( Capabilities::MANAGE_CONTACTS )
			);

			$this->handle_single_delete();
			$this->handle_bulk_action();
			$this->redirect_clean_filter_form();
			return;
		}

		$this->require_capability( Capabilities::MANAGE_CONTACTS );
		$this->handle_save( $view );
	}

	/** @inheritDoc */
	public function render(): void {
		$this->require_capability();

		$view = $this->current_view();

		if ( 'list' === $view ) {
			$this->table->prepare_items();
			$this->render_view(
				'contacts-list',
				array(
					'page'       => $this,
					'table'      => $this->table,
					'can_manage' => current_user_can( Capabilities::MANAGE_CONTACTS ),
				)
			);
			return;
		}

		$this->require_capability( Capabilities::MANAGE_CONTACTS );

		$contact = null;
		$events  = array();

		if ( 'edit' === $view ) {
			$contact = $this->plugin->get( 'contacts' )->find( $this->requested_id() );
			if ( ! $contact ) {
				wp_die( esc_html__( 'Contact not found.', 'ibg-client-outreach' ), 404 );
			}
			/** @var Event_Repository $event_repo */
			$event_repo = $this->plugin->get( 'events' );
			$events     = $event_repo->get_for_contact( $contact->id, 25 );
		}

		$values = $contact ? $contact->to_row() : ( new Contact() )->to_row();
		if ( ! empty( $this->form_data ) ) {
			$values = array_merge( $values, $this->form_data );
		}

		$member_of = $contact ? $this->plugin->get( 'lists' )->get_list_ids_for_contact( $contact->id ) : array();
		if ( isset( $_POST['contact_lists'] ) && $this->form_errors ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- re-rendering a failed, nonce-checked submission.
			$member_of = array_map( 'absint', (array) wp_unslash( $_POST['contact_lists'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		$this->render_view(
			'contact-form',
			array(
				'page'      => $this,
				'contact'   => $contact,
				'values'    => $values,
				'errors'    => $this->form_errors,
				'events'    => $events,
				'nonce'     => self::NONCE_SAVE,
				'list_url'  => $this->get_url(),
				'lists'     => $this->plugin->get( 'lists' )->all( Contact_List::TYPE_STATIC ),
				'member_of' => $member_of,
				'lists_url' => add_query_arg( 'page', Lists_Page::SLUG, admin_url( 'admin.php' ) ),
			)
		);
	}

	/**
	 * Current sub-view: list|add|edit.
	 *
	 * @return string
	 */
	private function current_view(): string {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $view, array( 'list', 'add', 'edit' ), true ) ? $view : 'list';
	}

	/**
	 * Requested contact id.
	 *
	 * @return int
	 */
	private function requested_id(): int {
		return isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Handle the add/edit form submission.
	 *
	 * @param string $view add|edit.
	 * @return void
	 */
	private function handle_save( string $view ): void {
		if ( ! isset( $_POST['ibg_action'] ) || 'save_contact' !== $_POST['ibg_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		check_admin_referer( self::NONCE_SAVE );

		$raw    = isset( $_POST['contact'] ) && is_array( $_POST['contact'] ) ? wp_unslash( $_POST['contact'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by Contact_Service.
		$fields = array( 'email', 'first_name', 'last_name', 'full_name', 'company', 'website', 'phone', 'country', 'industry', 'source', 'notes', 'contact_status', 'marketing_status', 'consent_basis', 'consent_at' );

		$data = array();
		foreach ( $fields as $field ) {
			$data[ $field ] = isset( $raw[ $field ] ) ? (string) $raw[ $field ] : '';
		}

		$options = array(
			'source'              => Contact_Service::SOURCE_ADMIN,
			'confirm_resubscribe' => ! empty( $_POST['confirm_resubscribe'] ),
		);

		/** @var Contact_Service $service */
		$service = $this->plugin->get( 'contact_service' );

		if ( 'edit' === $view ) {
			$result = $service->update( $this->requested_id(), $data, $options );
		} else {
			$result = $service->create( $data, $options );
		}

		if ( is_wp_error( $result ) ) {
			$this->form_errors = $result;
			$this->form_data   = $data;
			return;
		}

		// List memberships (static lists only; validated inside the service).
		$list_ids = isset( $_POST['contact_lists'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['contact_lists'] ) ) : array();
		$this->plugin->get( 'list_service' )->set_contact_lists( $result->id, $list_ids );

		$this->notices->add(
			'edit' === $view
				? __( 'Contact updated.', 'ibg-client-outreach' )
				: __( 'Contact added.', 'ibg-client-outreach' ),
			'success'
		);
		foreach ( $service->take_notices() as $notice ) {
			$this->notices->add( $notice, 'warning' );
		}

		wp_safe_redirect(
			$this->get_url(
				array(
					'view' => 'edit',
					'id'   => $result->id,
				)
			)
		);
		exit;
	}

	/**
	 * Handle a single-row delete link.
	 *
	 * @return void
	 */
	private function handle_single_delete(): void {
		if ( ! isset( $_GET['action'] ) || 'delete' !== $_GET['action'] || ! isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$id = absint( wp_unslash( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->require_capability( Capabilities::MANAGE_CONTACTS );
		check_admin_referer( self::NONCE_DELETE . $id );

		$deleted = $this->plugin->get( 'contact_service' )->delete( array( $id ) );

		$this->notices->add(
			$deleted
				? __( 'Contact deleted.', 'ibg-client-outreach' )
				: __( 'Contact could not be deleted.', 'ibg-client-outreach' ),
			$deleted ? 'success' : 'error'
		);

		wp_safe_redirect( $this->get_url( $this->preserved_filters() ) );
		exit;
	}

	/**
	 * Handle list-table bulk actions.
	 *
	 * @return void
	 */
	private function handle_bulk_action(): void {
		$action = $this->table->current_action();
		if ( ! $action ) {
			return;
		}

		$this->require_capability( Capabilities::MANAGE_CONTACTS );
		check_admin_referer( 'bulk-contacts' );

		$ids = isset( $_REQUEST['contact'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['contact'] ) ) : array();
		$ids = array_values( array_filter( $ids ) );

		if ( empty( $ids ) ) {
			$this->notices->add( __( 'No contacts selected.', 'ibg-client-outreach' ), 'warning' );
			wp_safe_redirect( $this->get_url( $this->preserved_filters() ) );
			exit;
		}

		/** @var Contact_Service $service */
		$service = $this->plugin->get( 'contact_service' );

		if ( 'delete' === $action ) {
			$count = $service->delete( $ids );
			/* translators: %d: number of contacts */
			$this->notices->add( sprintf( _n( '%d contact deleted.', '%d contacts deleted.', $count, 'ibg-client-outreach' ), $count ), 'success' );
		} elseif ( 'do_not_contact' === $action ) {
			$count = $service->bulk_mark_do_not_contact( $ids );
			/* translators: %d: number of contacts */
			$this->notices->add( sprintf( _n( '%d contact marked Do Not Contact.', '%d contacts marked Do Not Contact.', $count, 'ibg-client-outreach' ), $count ), 'success' );
		} elseif ( str_starts_with( $action, 'status_' ) ) {
			$status = substr( $action, 7 );
			$count  = $service->bulk_set_contact_status( $ids, $status );
			/* translators: %d: number of contacts */
			$this->notices->add( sprintf( _n( 'Status updated for %d contact.', 'Status updated for %d contacts.', $count, 'ibg-client-outreach' ), $count ), 'success' );
		} elseif ( 'add_to_list' === $action || 'remove_from_list' === $action ) {
			$this->handle_bulk_list_action( $action, $ids );
		}

		wp_safe_redirect( $this->get_url( $this->preserved_filters() ) );
		exit;
	}

	/**
	 * Bulk add/remove list membership.
	 *
	 * @param string $action add_to_list|remove_from_list.
	 * @param int[]  $ids    Contact ids.
	 * @return void
	 */
	private function handle_bulk_list_action( string $action, array $ids ): void {
		$list_id = isset( $_REQUEST['ibg_list_target'] ) ? absint( wp_unslash( $_REQUEST['ibg_list_target'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by caller.

		if ( $list_id <= 0 ) {
			$this->notices->add( __( 'Choose a target list next to the bulk action dropdown.', 'ibg-client-outreach' ), 'warning' );
			return;
		}

		/** @var List_Service $lists */
		$lists = $this->plugin->get( 'list_service' );

		$result = 'add_to_list' === $action
			? $lists->add_contacts( $list_id, $ids )
			: $lists->remove_contacts( $list_id, $ids );

		if ( is_wp_error( $result ) ) {
			$this->notices->add( $result->get_error_message(), 'error' );
			return;
		}

		$this->notices->add(
			'add_to_list' === $action
				/* translators: %d: number of contacts */
				? sprintf( _n( '%d contact added to the list.', '%d contacts added to the list.', $result, 'ibg-client-outreach' ), $result )
				/* translators: %d: number of contacts */
				: sprintf( _n( '%d contact removed from the list.', '%d contacts removed from the list.', $result, 'ibg-client-outreach' ), $result ),
			'success'
		);
	}

	/**
	 * After the filter form submits (GET), strip empty params and WP's
	 * _wp_http_referer so the URL stays shareable.
	 *
	 * @return void
	 */
	private function redirect_clean_filter_form(): void {
		if ( ! isset( $_GET['filter_action'] ) && ! isset( $_GET['_wp_http_referer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		wp_safe_redirect( $this->get_url( $this->preserved_filters() ) );
		exit;
	}

	/**
	 * Current list filters, to keep after a redirect.
	 *
	 * @return array<string, string>
	 */
	private function preserved_filters(): array {
		return $this->table ? $this->table->get_filter_args() : array();
	}
}
