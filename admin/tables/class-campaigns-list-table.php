<?php
/**
 * Campaigns list table.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Admin\Tables;

use IBG\Outreach\Admin\Pages\Campaigns_Page;
use IBG\Outreach\Campaigns\Campaign;
use IBG\Outreach\Campaigns\Campaign_Repository;
use IBG\Outreach\Formatting;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Campaigns_List_Table
 */
final class Campaigns_List_Table extends \WP_List_Table {

	public const PER_PAGE_OPTION = 'ibg_campaigns_per_page';

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository      $repository Repository.
	 * @param array<int, string>       $lists      List names keyed by id.
	 * @param array<int, string>       $templates  Template names keyed by id.
	 * @param Campaigns_Page           $page       Page.
	 * @param bool                     $can_manage Whether the user may edit.
	 */
	public function __construct(
		private readonly Campaign_Repository $repository,
		private readonly array $lists,
		private readonly array $templates,
		private readonly Campaigns_Page $page,
		private readonly bool $can_manage
	) {
		parent::__construct(
			array(
				'singular' => 'campaign',
				'plural'   => 'campaigns',
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
			'name'      => __( 'Campaign', 'ibg-client-outreach' ),
			'status'    => __( 'Status', 'ibg-client-outreach' ),
			'audience'  => __( 'Audience', 'ibg-client-outreach' ),
			'template'  => __( 'Template', 'ibg-client-outreach' ),
			'progress'  => __( 'Sent / Failed', 'ibg-client-outreach' ),
			'when'      => __( 'Scheduled / Started', 'ibg-client-outreach' ),
			'created_at' => __( 'Created', 'ibg-client-outreach' ),
		);
	}

	/** @inheritDoc */
	protected function get_sortable_columns(): array {
		return array(
			'name'       => array( 'name', false ),
			'status'     => array( 'status', false ),
			'progress'   => array( 'total_sent', false ),
			'when'       => array( 'scheduled_at', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/** @inheritDoc */
	protected function get_bulk_actions(): array {
		return $this->can_manage ? array( 'delete' => __( 'Delete', 'ibg-client-outreach' ) ) : array();
	}

	/** @inheritDoc */
	protected function get_views(): array {
		$counts  = $this->repository->count_by_status();
		$current = $this->get_filter_args()['status'] ?? '';
		$views   = array();

		$views['all'] = sprintf(
			'<a href="%s" %s>%s <span class="count">(%d)</span></a>',
			esc_url( $this->page->get_url() ),
			'' === $current ? 'class="current" aria-current="page"' : '',
			esc_html__( 'All', 'ibg-client-outreach' ),
			array_sum( $counts )
		);
		foreach ( Campaign::statuses() as $status => $label ) {
			if ( 0 === $counts[ $status ] && $status !== $current ) {
				continue;
			}
			$views[ $status ] = sprintf(
				'<a href="%s" %s>%s <span class="count">(%d)</span></a>',
				esc_url( $this->page->get_url( array( 'status' => $status ) ) ),
				$status === $current ? 'class="current" aria-current="page"' : '',
				esc_html( $label ),
				$counts[ $status ]
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
				'search'   => $filters['s'] ?? '',
				'status'   => $filters['status'] ?? '',
				'orderby'  => $filters['orderby'] ?? 'updated_at',
				'order'    => $filters['order'] ?? 'DESC',
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
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
		esc_html_e( 'No campaigns yet.', 'ibg-client-outreach' );
		if ( $this->can_manage ) {
			printf( ' <a href="%s">%s</a>', esc_url( $this->page->get_url( array( 'view' => 'add' ) ) ), esc_html__( 'Create your first campaign', 'ibg-client-outreach' ) );
		}
	}

	/**
	 * Checkbox.
	 *
	 * @param Campaign $item Campaign.
	 * @return string
	 */
	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="campaign[]" value="%d" />', $item->id );
	}

	/**
	 * Name + row actions.
	 *
	 * @param Campaign $item Campaign.
	 * @return string
	 */
	public function column_name( $item ): string {
		$url  = $this->page->get_url(
			array(
				'view' => 'edit',
				'id'   => $item->id,
			)
		);
		$name = sprintf( '<a class="row-title" href="%s">%s</a>', esc_url( $url ), esc_html( $item->name ) );
		$name .= '<div class="ibg-subject">' . esc_html( $item->subject ) . '</div>';

		$actions = array(
			'view' => sprintf( '<a href="%s">%s</a>', esc_url( $url ), $item->is_editable() ? esc_html__( 'Edit', 'ibg-client-outreach' ) : esc_html__( 'View', 'ibg-client-outreach' ) ),
		);
		if ( $this->can_manage ) {
			$actions['duplicate'] = sprintf( '<a href="%s">%s</a>', esc_url( $this->page->get_action_url( 'duplicate', $item->id ) ), esc_html__( 'Duplicate', 'ibg-client-outreach' ) );
			if ( ! in_array( $item->status, array( Campaign::STATUS_PROCESSING, Campaign::STATUS_PAUSED ), true ) ) {
				$actions['delete'] = sprintf(
					'<a href="%s" class="submitdelete" data-ibg-confirm="%s">%s</a>',
					esc_url( $this->page->get_action_url( 'delete', $item->id ) ),
					esc_attr__( 'Delete this campaign? Logs are kept.', 'ibg-client-outreach' ),
					esc_html__( 'Delete', 'ibg-client-outreach' )
				);
			}
		}

		return $name . $this->row_actions( $actions );
	}

	/**
	 * Status badge.
	 *
	 * @param Campaign $item Campaign.
	 * @return string
	 */
	public function column_status( $item ): string {
		return sprintf(
			'<span class="ibg-badge ibg-badge-campaign-%s">%s</span>',
			esc_attr( $item->status ),
			esc_html( Campaign::statuses()[ $item->status ] ?? $item->status )
		);
	}

	/**
	 * Audience description.
	 *
	 * @param Campaign $item Campaign.
	 * @return string
	 */
	public function column_audience( $item ): string {
		$target = $item->list_id ? ( $this->lists[ $item->list_id ] ?? __( '(deleted list)', 'ibg-client-outreach' ) ) : __( 'All contacts', 'ibg-client-outreach' );
		$scope  = Campaign::SCOPE_SUBSCRIBED_PENDING === $item->scope ? __( 'subscribed + pending', 'ibg-client-outreach' ) : __( 'subscribed only', 'ibg-client-outreach' );
		return esc_html( $target ) . '<div class="ibg-subject">' . esc_html( $scope ) . '</div>';
	}

	/**
	 * Template name.
	 *
	 * @param Campaign $item Campaign.
	 * @return string
	 */
	public function column_template( $item ): string {
		if ( ! $item->template_id ) {
			return '—';
		}
		return esc_html( $this->templates[ $item->template_id ] ?? __( '(deleted template)', 'ibg-client-outreach' ) );
	}

	/**
	 * Progress.
	 *
	 * @param Campaign $item Campaign.
	 * @return string
	 */
	public function column_progress( $item ): string {
		if ( ! $item->has_started() ) {
			return '—';
		}
		$expected = max( 0, $item->total_recipients - $item->total_excluded );
		return sprintf(
			'%s / <span class="%s">%s</span> <span class="ibg-subject">%s</span>',
			esc_html( number_format_i18n( $item->total_sent ) ),
			$item->total_failed > 0 ? 'ibg-text-error' : '',
			esc_html( number_format_i18n( $item->total_failed ) ),
			/* translators: %s: number */
			esc_html( sprintf( __( 'of %s', 'ibg-client-outreach' ), number_format_i18n( $expected ) ) )
		);
	}

	/**
	 * Scheduled / started time.
	 *
	 * @param Campaign $item Campaign.
	 * @return string
	 */
	public function column_when( $item ): string {
		if ( Campaign::STATUS_SCHEDULED === $item->status ) {
			return esc_html( Formatting::datetime( $item->scheduled_at ) );
		}
		if ( $item->has_started() ) {
			return esc_html( Formatting::datetime( $item->started_at ) );
		}
		return '—';
	}

	/**
	 * Created.
	 *
	 * @param Campaign $item Campaign.
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
