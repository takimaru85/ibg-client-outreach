<?php
/**
 * Lists / Segments page.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Admin\Pages;

use IBG\Outreach\Admin\Tables\Lists_List_Table;
use IBG\Outreach\Capabilities;
use IBG\Outreach\Contacts\Contact;
use IBG\Outreach\Lists\Contact_List;
use IBG\Outreach\Lists\List_Service;
use IBG\Outreach\Lists\Segment_Criteria;

defined( 'ABSPATH' ) || exit;

/**
 * Class Lists_Page
 */
final class Lists_Page extends Abstract_Page {

	public const SLUG = 'ibg-outreach-lists';

	private const NONCE_SAVE   = 'ibg_save_list';
	private const NONCE_DELETE = 'ibg_delete_list_';

	/**
	 * Table, built in load().
	 *
	 * @var Lists_List_Table|null
	 */
	private ?Lists_List_Table $table = null;

	/**
	 * Validation error from a failed save.
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
		return __( 'Lists & Segments', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_menu_title(): string {
		return __( 'Lists / Segments', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_capability(): string {
		return Capabilities::VIEW;
	}

	/** @inheritDoc */
	public function get_position(): int {
		return 40;
	}

	/**
	 * Delete URL.
	 *
	 * @param int $id List id.
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
			$this->table = new Lists_List_Table(
				$this->plugin->get( 'lists' )->all(),
				$this->plugin->get( 'list_service' ),
				$this,
				current_user_can( Capabilities::MANAGE_CONTACTS )
			);
			$this->handle_single_delete();
			$this->handle_bulk_delete();
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
				'lists-list',
				array(
					'page'       => $this,
					'table'      => $this->table,
					'can_manage' => current_user_can( Capabilities::MANAGE_CONTACTS ),
				)
			);
			return;
		}

		$this->require_capability( Capabilities::MANAGE_CONTACTS );

		/** @var List_Service $service */
		$service = $this->plugin->get( 'list_service' );
		$lists   = $this->plugin->get( 'lists' );
		$list    = null;

		if ( 'edit' === $view ) {
			$list = $lists->find( $this->requested_id() );
			if ( ! $list ) {
				wp_die( esc_html__( 'List not found.', 'ibg-client-outreach' ), 404 );
			}
		}

		$values = array(
			'name'        => $list ? $list->name : '',
			'description' => $list ? $list->description : '',
			'type'        => $list ? $list->type : Contact_List::TYPE_STATIC,
			'criteria'    => $list ? $list->criteria : array(),
		);
		if ( ! empty( $this->form_data ) ) {
			$values = array_merge( $values, $this->form_data );
		}

		$contacts = $this->plugin->get( 'contacts' );

		$this->render_view(
			'list-form',
			array(
				'page'         => $this,
				'list'         => $list,
				'values'       => $values,
				'error'        => $this->form_error,
				'nonce'        => self::NONCE_SAVE,
				'count'        => $list ? $service->count( $list ) : null,
				'static_lists' => $lists->all( Contact_List::TYPE_STATIC ),
				'options'      => array(
					'contact_status'   => Contact::contact_statuses(),
					'marketing_status' => Contact::marketing_statuses(),
					'consent_basis'    => array_filter( Contact::consent_bases(), 'strlen', ARRAY_FILTER_USE_KEY ),
					'industry'         => array_combine( $contacts->get_distinct( 'industry' ), $contacts->get_distinct( 'industry' ) ),
					'country'          => array_combine( $contacts->get_distinct( 'country' ), $contacts->get_distinct( 'country' ) ),
					'source'           => array_combine( $contacts->get_distinct( 'source' ), $contacts->get_distinct( 'source' ) ),
				),
				'list_url'     => $this->get_url(),
				'contacts_url' => $list ? add_query_arg(
					array(
						'page'    => Contacts_Page::SLUG,
						'list_id' => $list->id,
					),
					admin_url( 'admin.php' )
				) : '',
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
	 * Requested list id.
	 *
	 * @return int
	 */
	private function requested_id(): int {
		return isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Handle the add/edit form.
	 *
	 * @param string $view add|edit.
	 * @return void
	 */
	private function handle_save( string $view ): void {
		if ( ! isset( $_POST['ibg_action'] ) || 'save_list' !== $_POST['ibg_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		check_admin_referer( self::NONCE_SAVE );

		$raw  = isset( $_POST['list'] ) && is_array( $_POST['list'] ) ? wp_unslash( $_POST['list'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by List_Service.
		$data = array(
			'name'        => (string) ( $raw['name'] ?? '' ),
			'description' => (string) ( $raw['description'] ?? '' ),
			'type'        => (string) ( $raw['type'] ?? Contact_List::TYPE_STATIC ),
			'criteria'    => is_array( $raw['criteria'] ?? null ) ? $raw['criteria'] : array(),
		);

		$result = $this->plugin->get( 'list_service' )->save( $data, 'edit' === $view ? $this->requested_id() : 0 );

		if ( is_wp_error( $result ) ) {
			$this->form_error = $result;
			$this->form_data  = array(
				'name'        => sanitize_text_field( $data['name'] ),
				'description' => sanitize_textarea_field( $data['description'] ),
				'type'        => $data['type'],
				'criteria'    => Segment_Criteria::sanitize( $data['criteria'] ),
			);
			return;
		}

		$this->notices->add(
			'edit' === $view ? __( 'List updated.', 'ibg-client-outreach' ) : __( 'List created.', 'ibg-client-outreach' ),
			'success'
		);

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
	 * Single delete link.
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

		$deleted = $this->plugin->get( 'list_service' )->delete( array( $id ) );
		$this->notices->add(
			$deleted ? __( 'List deleted.', 'ibg-client-outreach' ) : __( 'List could not be deleted.', 'ibg-client-outreach' ),
			$deleted ? 'success' : 'error'
		);

		wp_safe_redirect( $this->get_url() );
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

		$this->require_capability( Capabilities::MANAGE_CONTACTS );
		check_admin_referer( 'bulk-lists' );

		$ids   = isset( $_REQUEST['list'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['list'] ) ) : array();
		$count = $this->plugin->get( 'list_service' )->delete( $ids );

		/* translators: %d: number of lists */
		$this->notices->add( sprintf( _n( '%d list deleted.', '%d lists deleted.', $count, 'ibg-client-outreach' ), $count ), 'success' );

		wp_safe_redirect( $this->get_url() );
		exit;
	}
}
