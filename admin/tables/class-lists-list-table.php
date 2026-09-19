<?php
/**
 * Lists / segments table.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Admin\Tables;

use IBG\Outreach\Admin\Pages\Contacts_Page;
use IBG\Outreach\Admin\Pages\Lists_Page;
use IBG\Outreach\Formatting;
use IBG\Outreach\Lists\Contact_List;
use IBG\Outreach\Lists\List_Service;
use IBG\Outreach\Lists\Segment_Criteria;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Lists_List_Table
 */
final class Lists_List_Table extends \WP_List_Table {

	/**
	 * Constructor.
	 *
	 * @param array<int, Contact_List> $lists      All lists keyed by id.
	 * @param List_Service             $service    Service (for counts).
	 * @param Lists_Page               $page       Page.
	 * @param bool                     $can_manage Whether the user may edit.
	 */
	public function __construct(
		private readonly array $lists,
		private readonly List_Service $service,
		private readonly Lists_Page $page,
		private readonly bool $can_manage
	) {
		parent::__construct(
			array(
				'singular' => 'list',
				'plural'   => 'lists',
				'ajax'     => false,
				'screen'   => get_current_screen(),
			)
		);
	}

	/** @inheritDoc */
	public function get_columns(): array {
		$columns = $this->can_manage ? array( 'cb' => '<input type="checkbox" />' ) : array();
		return $columns + array(
			'name'        => __( 'Name', 'ibg-client-outreach' ),
			'type'        => __( 'Type', 'ibg-client-outreach' ),
			'description' => __( 'Description / criteria', 'ibg-client-outreach' ),
			'members'     => __( 'Contacts', 'ibg-client-outreach' ),
			'created_at'  => __( 'Created', 'ibg-client-outreach' ),
		);
	}

	/** @inheritDoc */
	protected function get_bulk_actions(): array {
		return $this->can_manage ? array( 'delete' => __( 'Delete', 'ibg-client-outreach' ) ) : array();
	}

	/** @inheritDoc */
	public function prepare_items(): void {
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$items = $this->lists;
		if ( in_array( $type, array( Contact_List::TYPE_STATIC, Contact_List::TYPE_SEGMENT ), true ) ) {
			$items = array_filter( $items, static fn( Contact_List $l ): bool => $l->type === $type );
		}

		$this->items           = array_values( $items );
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'name' );

		$this->set_pagination_args(
			array(
				'total_items' => count( $this->items ),
				'per_page'    => max( 1, count( $this->items ) ),
			)
		);
	}

	/** @inheritDoc */
	protected function get_views(): array {
		$current = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$counts  = array(
			''                         => count( $this->lists ),
			Contact_List::TYPE_STATIC  => count( array_filter( $this->lists, static fn( Contact_List $l ): bool => ! $l->is_segment() ) ),
			Contact_List::TYPE_SEGMENT => count( array_filter( $this->lists, static fn( Contact_List $l ): bool => $l->is_segment() ) ),
		);
		$labels  = array(
			''                         => __( 'All', 'ibg-client-outreach' ),
			Contact_List::TYPE_STATIC  => __( 'Lists', 'ibg-client-outreach' ),
			Contact_List::TYPE_SEGMENT => __( 'Segments', 'ibg-client-outreach' ),
		);

		$views = array();
		foreach ( $labels as $type => $label ) {
			$views[ '' === $type ? 'all' : $type ] = sprintf(
				'<a href="%s" %s>%s <span class="count">(%d)</span></a>',
				esc_url( $this->page->get_url( '' === $type ? array() : array( 'type' => $type ) ) ),
				$type === $current ? 'class="current" aria-current="page"' : '',
				esc_html( $label ),
				(int) $counts[ $type ]
			);
		}
		return $views;
	}

	/** @inheritDoc */
	public function no_items(): void {
		esc_html_e( 'No lists yet.', 'ibg-client-outreach' );
		if ( $this->can_manage ) {
			printf(
				' <a href="%s">%s</a>',
				esc_url( $this->page->get_url( array( 'view' => 'add' ) ) ),
				esc_html__( 'Create your first list or segment', 'ibg-client-outreach' )
			);
		}
	}

	/**
	 * Checkbox.
	 *
	 * @param Contact_List $item List.
	 * @return string
	 */
	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="list[]" value="%d" />', $item->id );
	}

	/**
	 * Name with row actions.
	 *
	 * @param Contact_List $item List.
	 * @return string
	 */
	public function column_name( $item ): string {
		$edit_url     = $this->page->get_url(
			array(
				'view' => 'edit',
				'id'   => $item->id,
			)
		);
		$contacts_url = add_query_arg(
			array(
				'page'    => Contacts_Page::SLUG,
				'list_id' => $item->id,
			),
			admin_url( 'admin.php' )
		);

		$name = $this->can_manage
			? sprintf( '<a class="row-title" href="%s">%s</a>', esc_url( $edit_url ), esc_html( $item->name ) )
			: sprintf( '<strong>%s</strong>', esc_html( $item->name ) );

		$actions = array(
			'view' => sprintf( '<a href="%s">%s</a>', esc_url( $contacts_url ), esc_html__( 'View contacts', 'ibg-client-outreach' ) ),
		);
		if ( $this->can_manage ) {
			$actions['edit']   = sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'ibg-client-outreach' ) );
			$actions['delete'] = sprintf(
				'<a href="%s" class="submitdelete" data-ibg-confirm="%s">%s</a>',
				esc_url( $this->page->get_delete_url( $item->id ) ),
				esc_attr__( 'Delete this list? Contacts are not deleted, only the list and its memberships.', 'ibg-client-outreach' ),
				esc_html__( 'Delete', 'ibg-client-outreach' )
			);
		}

		return $name . $this->row_actions( $actions );
	}

	/**
	 * Type badge.
	 *
	 * @param Contact_List $item List.
	 * @return string
	 */
	public function column_type( $item ): string {
		return sprintf(
			'<span class="ibg-badge %s">%s</span>',
			$item->is_segment() ? 'ibg-badge-info' : '',
			$item->is_segment() ? esc_html__( 'Segment', 'ibg-client-outreach' ) : esc_html__( 'List', 'ibg-client-outreach' )
		);
	}

	/**
	 * Description or criteria summary.
	 *
	 * @param Contact_List $item List.
	 * @return string
	 */
	public function column_description( $item ): string {
		$out = '' !== $item->description ? '<div>' . esc_html( $item->description ) . '</div>' : '';
		if ( $item->is_segment() ) {
			$lines = Segment_Criteria::describe( $item->criteria, $this->lists );
			$out  .= '<div class="ibg-criteria">' . ( $lines ? esc_html( implode( ' · ', $lines ) ) : '<em>' . esc_html__( 'No criteria (matches everyone)', 'ibg-client-outreach' ) . '</em>' ) . '</div>';
		}
		return $out;
	}

	/**
	 * Member count.
	 *
	 * @param Contact_List $item List.
	 * @return string
	 */
	public function column_members( $item ): string {
		return esc_html( number_format_i18n( $this->service->count( $item ) ) );
	}

	/**
	 * Created date.
	 *
	 * @param Contact_List $item List.
	 * @return string
	 */
	public function column_created_at( $item ): string {
		return esc_html( Formatting::datetime( $item->created_at, get_option( 'date_format' ) ) );
	}

	/** @inheritDoc */
	public function column_default( $item, $column_name ): string {
		return '';
	}
}
