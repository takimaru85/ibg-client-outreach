<?php
/**
 * Email queue list table.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Admin\Tables;

use IBG\Outreach\Admin\Pages\Campaigns_Page;
use IBG\Outreach\Admin\Pages\Contacts_Page;
use IBG\Outreach\Admin\Pages\Queue_Page;
use IBG\Outreach\Formatting;
use IBG\Outreach\Queue\Queue_Item;
use IBG\Outreach\Queue\Queue_Repository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Queue_List_Table
 */
final class Queue_List_Table extends \WP_List_Table {

	public const PER_PAGE_OPTION = 'ibg_queue_per_page';

	/**
	 * Constructor.
	 *
	 * @param Queue_Repository   $repository Repository.
	 * @param array<int, string> $campaigns  Campaign names keyed by id.
	 * @param Queue_Page         $page       Page.
	 * @param bool               $can_manage Whether the user may act on rows.
	 */
	public function __construct(
		private readonly Queue_Repository $repository,
		private readonly array $campaigns,
		private readonly Queue_Page $page,
		private readonly bool $can_manage
	) {
		parent::__construct(
			array(
				'singular' => 'queue_item',
				'plural'   => 'queue_items',
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
		foreach ( array( 's', 'status', 'campaign_id', 'orderby', 'order', 'paged' ) as $key ) {
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
			'email'           => __( 'Recipient', 'ibg-client-outreach' ),
			'campaign'        => __( 'Campaign', 'ibg-client-outreach' ),
			'status'          => __( 'Status', 'ibg-client-outreach' ),
			'attempts'        => __( 'Attempts', 'ibg-client-outreach' ),
			'scheduled_at'    => __( 'Scheduled', 'ibg-client-outreach' ),
			'last_attempt_at' => __( 'Last attempt', 'ibg-client-outreach' ),
			'error_message'   => __( 'Message', 'ibg-client-outreach' ),
		);
	}

	/** @inheritDoc */
	protected function get_sortable_columns(): array {
		return array(
			'email'           => array( 'email', false ),
			'status'          => array( 'status', false ),
			'attempts'        => array( 'attempts', false ),
			'scheduled_at'    => array( 'scheduled_at', false ),
			'last_attempt_at' => array( 'last_attempt_at', false ),
		);
	}

	/** @inheritDoc */
	protected function get_bulk_actions(): array {
		if ( ! $this->can_manage ) {
			return array();
		}
		return array(
			'retry'  => __( 'Retry (failed → pending)', 'ibg-client-outreach' ),
			'skip'   => __( 'Skip (pending → skipped)', 'ibg-client-outreach' ),
			'delete' => __( 'Delete', 'ibg-client-outreach' ),
		);
	}

	/** @inheritDoc */
	protected function get_views(): array {
		$filters = $this->get_filter_args();
		$current = $filters['status'] ?? '';
		$counts  = $this->repository->count_by_status( (int) ( $filters['campaign_id'] ?? 0 ) );
		$base    = array_intersect_key( $filters, array_flip( array( 'campaign_id', 's' ) ) );

		$views        = array();
		$views['all'] = sprintf(
			'<a href="%s" %s>%s <span class="count">(%d)</span></a>',
			esc_url( $this->page->get_url( $base ) ),
			'' === $current ? 'class="current" aria-current="page"' : '',
			esc_html__( 'All', 'ibg-client-outreach' ),
			array_sum( $counts )
		);
		foreach ( Queue_Item::statuses() as $status => $label ) {
			$views[ $status ] = sprintf(
				'<a href="%s" %s>%s <span class="count">(%d)</span></a>',
				esc_url( $this->page->get_url( array_merge( $base, array( 'status' => $status ) ) ) ),
				$status === $current ? 'class="current" aria-current="page"' : '',
				esc_html( $label ),
				$counts[ $status ]
			);
		}
		return $views;
	}

	/** @inheritDoc */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$filters = $this->get_filter_args();
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="ibg-queue-campaign"><?php esc_html_e( 'Filter by campaign', 'ibg-client-outreach' ); ?></label>
			<select name="campaign_id" id="ibg-queue-campaign">
				<option value=""><?php esc_html_e( 'All campaigns', 'ibg-client-outreach' ); ?></option>
				<?php foreach ( $this->campaigns as $id => $name ) : ?>
					<option value="<?php echo (int) $id; ?>" <?php selected( (int) ( $filters['campaign_id'] ?? 0 ), $id ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'ibg-client-outreach' ), 'secondary', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/** @inheritDoc */
	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( self::PER_PAGE_OPTION, 50 );
		$filters  = $this->get_filter_args();

		$result = $this->repository->query(
			array(
				'campaign_id' => (int) ( $filters['campaign_id'] ?? 0 ),
				'status'      => $filters['status'] ?? '',
				'search'      => $filters['s'] ?? '',
				'orderby'     => $filters['orderby'] ?? 'id',
				'order'       => $filters['order'] ?? 'DESC',
				'per_page'    => $per_page,
				'page'        => $this->get_pagenum(),
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
		esc_html_e( 'The queue is empty.', 'ibg-client-outreach' );
	}

	/**
	 * Checkbox.
	 *
	 * @param Queue_Item $item Row.
	 * @return string
	 */
	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="queue_item[]" value="%d" />', $item->id );
	}

	/**
	 * Recipient.
	 *
	 * @param Queue_Item $item Row.
	 * @return string
	 */
	public function column_email( $item ): string {
		$url = add_query_arg(
			array(
				'page' => Contacts_Page::SLUG,
				'view' => 'edit',
				'id'   => $item->contact_id,
			),
			admin_url( 'admin.php' )
		);
		return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $item->email ) );
	}

	/**
	 * Campaign.
	 *
	 * @param Queue_Item $item Row.
	 * @return string
	 */
	public function column_campaign( $item ): string {
		$url = add_query_arg(
			array(
				'page' => Campaigns_Page::SLUG,
				'view' => 'edit',
				'id'   => $item->campaign_id,
			),
			admin_url( 'admin.php' )
		);
		return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $this->campaigns[ $item->campaign_id ] ?? '#' . $item->campaign_id ) );
	}

	/**
	 * Status badge.
	 *
	 * @param Queue_Item $item Row.
	 * @return string
	 */
	public function column_status( $item ): string {
		return sprintf(
			'<span class="ibg-badge ibg-badge-queue-%s">%s</span>',
			esc_attr( $item->status ),
			esc_html( Queue_Item::statuses()[ $item->status ] ?? $item->status )
		);
	}

	/**
	 * Scheduled.
	 *
	 * @param Queue_Item $item Row.
	 * @return string
	 */
	public function column_scheduled_at( $item ): string {
		return esc_html( Formatting::datetime( $item->scheduled_at ) );
	}

	/**
	 * Last attempt.
	 *
	 * @param Queue_Item $item Row.
	 * @return string
	 */
	public function column_last_attempt_at( $item ): string {
		return esc_html( Formatting::datetime( $item->last_attempt_at ) );
	}

	/**
	 * Message / error.
	 *
	 * @param Queue_Item $item Row.
	 * @return string
	 */
	public function column_error_message( $item ): string {
		return '<span class="ibg-subject">' . esc_html( mb_substr( $item->error_message, 0, 160 ) ) . '</span>';
	}

	/** @inheritDoc */
	public function column_default( $item, $column_name ): string {
		return isset( $item->{$column_name} ) ? esc_html( (string) $item->{$column_name} ) : '';
	}
}
