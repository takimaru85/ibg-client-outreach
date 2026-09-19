<?php
/**
 * Suppression list table.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Admin\Tables;

use IBG\Outreach\Admin\Pages\Campaigns_Page;
use IBG\Outreach\Admin\Pages\Contacts_Page;
use IBG\Outreach\Admin\Pages\Suppressions_Page;
use IBG\Outreach\Formatting;
use IBG\Outreach\Unsubscribe\Suppression_Repository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Suppressions_List_Table
 */
final class Suppressions_List_Table extends \WP_List_Table {

	public const PER_PAGE_OPTION = 'ibg_suppressions_per_page';

	/**
	 * Constructor.
	 *
	 * @param Suppression_Repository $repository Repository.
	 * @param Suppressions_Page      $page       Page.
	 * @param bool                   $can_manage Whether the user may remove entries.
	 */
	public function __construct(
		private readonly Suppression_Repository $repository,
		private readonly Suppressions_Page $page,
		private readonly bool $can_manage
	) {
		parent::__construct(
			array(
				'singular' => 'suppression',
				'plural'   => 'suppressions',
				'ajax'     => false,
				'screen'   => get_current_screen(),
			)
		);
	}

	/**
	 * Filters from the query string.
	 *
	 * @return array<string, string>
	 */
	public function get_filter_args(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$args = array();
		foreach ( array( 's', 'reason', 'orderby', 'order', 'paged' ) as $key ) {
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
			'email'      => __( 'Email', 'ibg-client-outreach' ),
			'reason'     => __( 'Reason', 'ibg-client-outreach' ),
			'source'     => __( 'Source', 'ibg-client-outreach' ),
			'contact'    => __( 'Contact', 'ibg-client-outreach' ),
			'campaign'   => __( 'Campaign', 'ibg-client-outreach' ),
			'created_at' => __( 'Since', 'ibg-client-outreach' ),
		);
	}

	/** @inheritDoc */
	protected function get_sortable_columns(): array {
		return array(
			'email'      => array( 'email', false ),
			'reason'     => array( 'reason', false ),
			'source'     => array( 'source', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/** @inheritDoc */
	protected function get_bulk_actions(): array {
		return $this->can_manage ? array( 'remove' => __( 'Remove from suppression list', 'ibg-client-outreach' ) ) : array();
	}

	/** @inheritDoc */
	protected function get_views(): array {
		$counts  = $this->repository->count_by_reason();
		$current = $this->get_filter_args()['reason'] ?? '';
		$views   = array(
			'all' => sprintf(
				'<a href="%s" %s>%s <span class="count">(%d)</span></a>',
				esc_url( $this->page->get_url() ),
				'' === $current ? 'class="current" aria-current="page"' : '',
				esc_html__( 'All', 'ibg-client-outreach' ),
				array_sum( $counts )
			),
		);
		foreach ( Suppression_Repository::reasons() as $reason => $label ) {
			if ( 0 === $counts[ $reason ] && $reason !== $current ) {
				continue;
			}
			$views[ $reason ] = sprintf(
				'<a href="%s" %s>%s <span class="count">(%d)</span></a>',
				esc_url( $this->page->get_url( array( 'reason' => $reason ) ) ),
				$reason === $current ? 'class="current" aria-current="page"' : '',
				esc_html( $label ),
				$counts[ $reason ]
			);
		}
		return $views;
	}

	/** @inheritDoc */
	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( self::PER_PAGE_OPTION, 50 );
		$filters  = $this->get_filter_args();

		$result = $this->repository->query(
			array(
				'search'   => $filters['s'] ?? '',
				'reason'   => $filters['reason'] ?? '',
				'orderby'  => $filters['orderby'] ?? 'created_at',
				'order'    => $filters['order'] ?? 'DESC',
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
			)
		);

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'email' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/** @inheritDoc */
	public function no_items(): void {
		esc_html_e( 'The suppression list is empty.', 'ibg-client-outreach' );
	}

	/**
	 * Checkbox.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="suppression[]" value="%d" />', (int) $item->id );
	}

	/**
	 * Email + row actions.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_email( $item ): string {
		$email   = '' !== (string) $item->email ? (string) $item->email : __( '(erased – hash only)', 'ibg-client-outreach' );
		$out     = '<strong>' . esc_html( $email ) . '</strong>';
		$actions = array();
		if ( $this->can_manage ) {
			$actions['remove'] = sprintf(
				'<a href="%s" class="submitdelete" data-ibg-confirm="%s">%s</a>',
				esc_url( $this->page->get_remove_url( (int) $item->id ) ),
				esc_attr__( 'Remove this address from the suppression list? It becomes eligible for campaigns again (as Pending). Only do this if the person asked to be contacted again.', 'ibg-client-outreach' ),
				esc_html__( 'Remove', 'ibg-client-outreach' )
			);
		}
		return $out . $this->row_actions( $actions );
	}

	/**
	 * Reason badge.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_reason( $item ): string {
		$reason = (string) $item->reason;
		$class  = Suppression_Repository::REASON_DNC === $reason ? 'ibg-badge-marketing-do_not_contact' : 'ibg-badge-marketing-unsubscribed';
		return sprintf( '<span class="ibg-badge %s">%s</span>', esc_attr( $class ), esc_html( Suppression_Repository::reasons()[ $reason ] ?? $reason ) );
	}

	/**
	 * Contact link.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_contact( $item ): string {
		if ( (int) $item->contact_id <= 0 ) {
			return '—';
		}
		$url = add_query_arg(
			array(
				'page' => Contacts_Page::SLUG,
				'view' => 'edit',
				'id'   => (int) $item->contact_id,
			),
			admin_url( 'admin.php' )
		);
		return sprintf( '<a href="%s">#%d</a>', esc_url( $url ), (int) $item->contact_id );
	}

	/**
	 * Campaign link.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_campaign( $item ): string {
		if ( (int) $item->campaign_id <= 0 ) {
			return '—';
		}
		$url = add_query_arg(
			array(
				'page' => Campaigns_Page::SLUG,
				'view' => 'edit',
				'id'   => (int) $item->campaign_id,
			),
			admin_url( 'admin.php' )
		);
		return sprintf( '<a href="%s">#%d</a>', esc_url( $url ), (int) $item->campaign_id );
	}

	/**
	 * Since.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_created_at( $item ): string {
		return esc_html( Formatting::datetime( (string) $item->created_at ) );
	}

	/** @inheritDoc */
	public function column_default( $item, $column_name ): string {
		return isset( $item->{$column_name} ) ? esc_html( (string) $item->{$column_name} ) : '';
	}
}
