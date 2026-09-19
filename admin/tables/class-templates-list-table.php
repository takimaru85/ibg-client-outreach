<?php
/**
 * Templates list table.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Admin\Tables;

use IBG\Outreach\Admin\Pages\Templates_Page;
use IBG\Outreach\Formatting;
use IBG\Outreach\Templates\Email_Template;
use IBG\Outreach\Templates\Template_Repository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Templates_List_Table
 */
final class Templates_List_Table extends \WP_List_Table {

	public const PER_PAGE_OPTION = 'ibg_templates_per_page';

	/**
	 * Constructor.
	 *
	 * @param Template_Repository $repository Repository.
	 * @param Templates_Page      $page       Page.
	 * @param bool                $can_manage Whether the user may edit.
	 */
	public function __construct(
		private readonly Template_Repository $repository,
		private readonly Templates_Page $page,
		private readonly bool $can_manage
	) {
		parent::__construct(
			array(
				'singular' => 'template',
				'plural'   => 'templates',
				'ajax'     => false,
				'screen'   => get_current_screen(),
			)
		);
	}

	/**
	 * Current filters from the query string.
	 *
	 * @return array<string, string>
	 */
	public function get_filter_args(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$args = array();
		foreach ( array( 's', 'status', 'orderby', 'order', 'paged' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && '' !== $_GET[ $key ] ) {
				$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		// phpcs:enable
		return $args;
	}

	/** @inheritDoc */
	public function get_columns(): array {
		$columns = $this->can_manage ? array( 'cb' => '<input type="checkbox" />' ) : array();
		return $columns + array(
			'name'       => __( 'Name', 'ibg-client-outreach' ),
			'subject'    => __( 'Subject', 'ibg-client-outreach' ),
			'is_active'  => __( 'Status', 'ibg-client-outreach' ),
			'campaigns'  => __( 'Used by', 'ibg-client-outreach' ),
			'updated_at' => __( 'Updated', 'ibg-client-outreach' ),
		);
	}

	/** @inheritDoc */
	protected function get_sortable_columns(): array {
		return array(
			'name'       => array( 'name', false ),
			'subject'    => array( 'subject', false ),
			'is_active'  => array( 'is_active', false ),
			'updated_at' => array( 'updated_at', true ),
		);
	}

	/** @inheritDoc */
	protected function get_bulk_actions(): array {
		if ( ! $this->can_manage ) {
			return array();
		}
		return array(
			'activate'   => __( 'Activate', 'ibg-client-outreach' ),
			'deactivate' => __( 'Deactivate', 'ibg-client-outreach' ),
			'delete'     => __( 'Delete', 'ibg-client-outreach' ),
		);
	}

	/** @inheritDoc */
	protected function get_views(): array {
		$counts  = $this->repository->counts();
		$current = $this->get_filter_args()['status'] ?? '';
		$views   = array();

		foreach ( array( '' => __( 'All', 'ibg-client-outreach' ), 'active' => __( 'Active', 'ibg-client-outreach' ), 'inactive' => __( 'Inactive', 'ibg-client-outreach' ) ) as $key => $label ) {
			$views[ '' === $key ? 'all' : $key ] = sprintf(
				'<a href="%s" %s>%s <span class="count">(%d)</span></a>',
				esc_url( $this->page->get_url( '' === $key ? array() : array( 'status' => $key ) ) ),
				$key === $current ? 'class="current" aria-current="page"' : '',
				esc_html( $label ),
				(int) $counts[ '' === $key ? 'all' : $key ]
			);
		}
		return $views;
	}

	/** @inheritDoc */
	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( self::PER_PAGE_OPTION, 20 );
		$filters  = $this->get_filter_args();

		$result = $this->repository->query(
			array(
				'search'    => $filters['s'] ?? '',
				'is_active' => isset( $filters['status'] ) ? ( 'active' === $filters['status'] ? 1 : 0 ) : '',
				'orderby'   => $filters['orderby'] ?? 'updated_at',
				'order'     => $filters['order'] ?? 'DESC',
				'per_page'  => $per_page,
				'page'      => $this->get_pagenum(),
			)
		);

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'name' );

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/** @inheritDoc */
	public function no_items(): void {
		esc_html_e( 'No templates yet.', 'ibg-client-outreach' );
	}

	/**
	 * Checkbox.
	 *
	 * @param Email_Template $item Template.
	 * @return string
	 */
	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="template[]" value="%d" />', $item->id );
	}

	/**
	 * Name + row actions.
	 *
	 * @param Email_Template $item Template.
	 * @return string
	 */
	public function column_name( $item ): string {
		$edit_url = $this->page->get_url(
			array(
				'view' => 'edit',
				'id'   => $item->id,
			)
		);

		$name = $this->can_manage
			? sprintf( '<a class="row-title" href="%s">%s</a>', esc_url( $edit_url ), esc_html( $item->name ) )
			: sprintf( '<strong>%s</strong>', esc_html( $item->name ) );

		$actions = array(
			'preview' => sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $this->page->get_preview_url( $item->id ) ), esc_html__( 'Preview', 'ibg-client-outreach' ) ),
		);
		if ( $this->can_manage ) {
			$actions['edit']      = sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'ibg-client-outreach' ) );
			$actions['duplicate'] = sprintf( '<a href="%s">%s</a>', esc_url( $this->page->get_action_url( 'duplicate', $item->id ) ), esc_html__( 'Duplicate', 'ibg-client-outreach' ) );
			if ( 0 === (int) $item->campaign_count ) {
				$actions['delete'] = sprintf(
					'<a href="%s" class="submitdelete" data-ibg-confirm="%s">%s</a>',
					esc_url( $this->page->get_action_url( 'delete', $item->id ) ),
					esc_attr__( 'Delete this template?', 'ibg-client-outreach' ),
					esc_html__( 'Delete', 'ibg-client-outreach' )
				);
			}
		}

		return $name . $this->row_actions( $actions );
	}

	/**
	 * Active badge.
	 *
	 * @param Email_Template $item Template.
	 * @return string
	 */
	public function column_is_active( $item ): string {
		return $item->is_active
			? '<span class="ibg-badge ibg-badge-ok">' . esc_html__( 'Active', 'ibg-client-outreach' ) . '</span>'
			: '<span class="ibg-badge">' . esc_html__( 'Inactive', 'ibg-client-outreach' ) . '</span>';
	}

	/**
	 * Campaign usage.
	 *
	 * @param Email_Template $item Template.
	 * @return string
	 */
	public function column_campaigns( $item ): string {
		$n = (int) $item->campaign_count;
		/* translators: %d: number of campaigns */
		return $n > 0 ? esc_html( sprintf( _n( '%d campaign', '%d campaigns', $n, 'ibg-client-outreach' ), $n ) ) : '—';
	}

	/**
	 * Updated date.
	 *
	 * @param Email_Template $item Template.
	 * @return string
	 */
	public function column_updated_at( $item ): string {
		return esc_html( Formatting::datetime( $item->updated_at ) );
	}

	/** @inheritDoc */
	public function column_default( $item, $column_name ): string {
		return isset( $item->{$column_name} ) ? esc_html( (string) $item->{$column_name} ) : '';
	}
}
