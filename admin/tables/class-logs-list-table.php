<?php
/**
 * Email logs list table.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Admin\Tables;

use IBG\Outreach\Admin\Pages\Campaigns_Page;
use IBG\Outreach\Admin\Pages\Contacts_Page;
use IBG\Outreach\Admin\Pages\Logs_Page;
use IBG\Outreach\Formatting;
use IBG\Outreach\Queue\Email_Log_Repository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class Logs_List_Table
 */
final class Logs_List_Table extends \WP_List_Table {

	public const PER_PAGE_OPTION = 'ibg_logs_per_page';

	/**
	 * Constructor.
	 *
	 * @param Email_Log_Repository $repository Repository.
	 * @param array<int, string>   $campaigns  Campaign names keyed by id.
	 * @param Logs_Page            $page       Page.
	 * @param bool                 $can_manage Whether the user may delete.
	 */
	public function __construct(
		private readonly Email_Log_Repository $repository,
		private readonly array $campaigns,
		private readonly Logs_Page $page,
		private readonly bool $can_manage
	) {
		parent::__construct(
			array(
				'singular' => 'log',
				'plural'   => 'logs',
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
		foreach ( array( 's', 'status', 'campaign_id', 'date_from', 'date_to', 'orderby', 'order', 'paged' ) as $key ) {
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
			'created_at'  => __( 'Date', 'ibg-client-outreach' ),
			'email'       => __( 'Recipient', 'ibg-client-outreach' ),
			'campaign'    => __( 'Campaign', 'ibg-client-outreach' ),
			'subject'     => __( 'Subject', 'ibg-client-outreach' ),
			'status'      => __( 'Status', 'ibg-client-outreach' ),
			'provider'    => __( 'Provider', 'ibg-client-outreach' ),
			'retry_count' => __( 'Retries', 'ibg-client-outreach' ),
			'error'       => __( 'Error / reason', 'ibg-client-outreach' ),
		);
	}

	/** @inheritDoc */
	protected function get_sortable_columns(): array {
		return array(
			'created_at' => array( 'created_at', true ),
			'email'      => array( 'email', false ),
			'status'     => array( 'status', false ),
			'provider'   => array( 'provider', false ),
		);
	}

	/** @inheritDoc */
	protected function get_bulk_actions(): array {
		return $this->can_manage ? array( 'delete' => __( 'Delete', 'ibg-client-outreach' ) ) : array();
	}

	/** @inheritDoc */
	protected function get_views(): array {
		$filters = $this->get_filter_args();
		$current = $filters['status'] ?? '';
		$counts  = $this->repository->count_by_status( (int) ( $filters['campaign_id'] ?? 0 ) );
		$base    = array_intersect_key( $filters, array_flip( array( 'campaign_id', 's', 'date_from', 'date_to' ) ) );

		$views        = array();
		$views['all'] = sprintf(
			'<a href="%s" %s>%s <span class="count">(%d)</span></a>',
			esc_url( $this->page->get_url( $base ) ),
			'' === $current ? 'class="current" aria-current="page"' : '',
			esc_html__( 'All', 'ibg-client-outreach' ),
			array_sum( $counts )
		);
		foreach ( Email_Log_Repository::statuses() as $status => $label ) {
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
		<div class="alignleft actions ibg-filters">
			<label class="screen-reader-text" for="ibg-logs-campaign"><?php esc_html_e( 'Filter by campaign', 'ibg-client-outreach' ); ?></label>
			<select name="campaign_id" id="ibg-logs-campaign">
				<option value=""><?php esc_html_e( 'All campaigns', 'ibg-client-outreach' ); ?></option>
				<?php foreach ( $this->campaigns as $id => $name ) : ?>
					<option value="<?php echo (int) $id; ?>" <?php selected( (int) ( $filters['campaign_id'] ?? 0 ), $id ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ?? '' ); ?>" title="<?php esc_attr_e( 'From', 'ibg-client-outreach' ); ?>">
			<input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ?? '' ); ?>" title="<?php esc_attr_e( 'To', 'ibg-client-outreach' ); ?>">
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
				'date_from'   => $filters['date_from'] ?? '',
				'date_to'     => $filters['date_to'] ?? '',
				'orderby'     => $filters['orderby'] ?? 'id',
				'order'       => $filters['order'] ?? 'DESC',
				'per_page'    => $per_page,
				'page'        => $this->get_pagenum(),
			)
		);

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'created_at' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/** @inheritDoc */
	public function no_items(): void {
		esc_html_e( 'No log entries.', 'ibg-client-outreach' );
	}

	/**
	 * Checkbox.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="log[]" value="%d" />', (int) $item->id );
	}

	/**
	 * Date.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_created_at( $item ): string {
		return esc_html( Formatting::datetime( (string) $item->created_at ) );
	}

	/**
	 * Recipient (linked when a contact exists).
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_email( $item ): string {
		if ( (int) $item->contact_id > 0 ) {
			$url = add_query_arg(
				array(
					'page' => Contacts_Page::SLUG,
					'view' => 'edit',
					'id'   => (int) $item->contact_id,
				),
				admin_url( 'admin.php' )
			);
			return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( (string) $item->email ) );
		}
		return esc_html( (string) $item->email );
	}

	/**
	 * Campaign.
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
		return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $this->campaigns[ (int) $item->campaign_id ] ?? '#' . (int) $item->campaign_id ) );
	}

	/**
	 * Status.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_status( $item ): string {
		return sprintf(
			'<span class="ibg-badge ibg-badge-log-%s">%s</span>',
			esc_attr( (string) $item->status ),
			esc_html( Email_Log_Repository::statuses()[ $item->status ] ?? (string) $item->status )
		);
	}

	/**
	 * Error.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	public function column_error( $item ): string {
		return '<span class="ibg-subject">' . esc_html( mb_substr( (string) $item->error_message, 0, 200 ) ) . '</span>';
	}

	/** @inheritDoc */
	public function column_default( $item, $column_name ): string {
		return isset( $item->{$column_name} ) ? esc_html( (string) $item->{$column_name} ) : '';
	}
}
