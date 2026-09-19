<?php
/**
 * Campaign repository (ibg_campaigns).
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Campaigns;

use IBG\Outreach\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Class Campaign_Repository
 */
final class Campaign_Repository {

	/**
	 * Sortable columns.
	 *
	 * @var string[]
	 */
	private const SORTABLE = array( 'id', 'name', 'status', 'scheduled_at', 'started_at', 'completed_at', 'total_sent', 'created_at', 'updated_at' );

	/**
	 * Database helper.
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Constructor.
	 *
	 * @param Database $db Database helper.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Find by id.
	 *
	 * @param int $id Campaign id.
	 * @return Campaign|null
	 */
	public function find( int $id ): ?Campaign {
		$wpdb = $this->db->wpdb();
		$row  = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->db->table( 'campaigns' )} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ? Campaign::from_row( $row ) : null;
	}

	/**
	 * Query campaigns.
	 *
	 * @param array<string, mixed> $args search, status, orderby, order, per_page, page.
	 * @return array{items: Campaign[], total: int}
	 */
	public function query( array $args = array() ): array {
		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'status'   => '',
				'orderby'  => 'updated_at',
				'order'    => 'DESC',
				'per_page' => 20,
				'page'     => 1,
			)
		);

		$wpdb   = $this->db->wpdb();
		$table  = $this->db->table( 'campaigns' );
		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(name LIKE %s OR subject LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		if ( '' !== $args['status'] && array_key_exists( (string) $args['status'], Campaign::statuses() ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}

		$where_sql = implode( ' AND ', $where );
		$orderby   = in_array( $args['orderby'], self::SORTABLE, true ) ? $args['orderby'] : 'updated_at';
		$order     = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$per_page  = max( 1, (int) $args['per_page'] );
		$offset    = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( empty( $params ) ? $count_sql : $wpdb->prepare( $count_sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = Campaign::from_row( $row );
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Counts per status.
	 *
	 * @return array<string, int>
	 */
	public function count_by_status(): array {
		$wpdb = $this->db->wpdb();
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$this->db->table( 'campaigns' )} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$counts = array_fill_keys( array_keys( Campaign::statuses() ), 0 );
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/**
	 * Scheduled campaigns whose time has come (for the cron dispatcher).
	 *
	 * @param string $now_utc UTC datetime.
	 * @return Campaign[]
	 */
	public function find_due( string $now_utc ): array {
		$wpdb = $this->db->wpdb();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->db->table( 'campaigns' )} WHERE status = %s AND scheduled_at IS NOT NULL AND scheduled_at <= %s ORDER BY scheduled_at ASC LIMIT 10", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Campaign::STATUS_SCHEDULED,
				$now_utc
			),
			ARRAY_A
		);
		return array_map( array( Campaign::class, 'from_row' ), (array) $rows );
	}

	/**
	 * Insert.
	 *
	 * @param Campaign $campaign Campaign.
	 * @return int New id or 0.
	 */
	public function insert( Campaign $campaign ): int {
		$wpdb                 = $this->db->wpdb();
		$campaign->created_at = $this->db->now();
		$campaign->updated_at = $campaign->created_at;

		if ( false === $wpdb->insert( $this->db->table( 'campaigns' ), $campaign->to_row(), Campaign::row_formats() ) ) {
			return 0;
		}
		$campaign->id = (int) $wpdb->insert_id;
		return $campaign->id;
	}

	/**
	 * Update all columns.
	 *
	 * @param Campaign $campaign Campaign.
	 * @return bool
	 */
	public function update( Campaign $campaign ): bool {
		if ( $campaign->id <= 0 ) {
			return false;
		}
		$wpdb                 = $this->db->wpdb();
		$campaign->updated_at = $this->db->now();

		return false !== $wpdb->update(
			$this->db->table( 'campaigns' ),
			$campaign->to_row(),
			array( 'id' => $campaign->id ),
			Campaign::row_formats(),
			array( '%d' )
		);
	}

	/**
	 * Atomic status transition: only succeeds if the row is still in $from.
	 * Prevents two admins (or admin + cron) from double-starting a campaign.
	 *
	 * @param int      $id     Campaign id.
	 * @param string   $from   Expected current status.
	 * @param string   $to     New status.
	 * @param string[] $extra  Extra column assignments, e.g. ['started_at' => '2026-01-01 00:00:00'].
	 * @return bool True if exactly one row changed.
	 */
	public function transition( int $id, string $from, string $to, array $extra = array() ): bool {
		$wpdb   = $this->db->wpdb();
		$sets   = array( 'status = %s', 'updated_at = %s' );
		$params = array( $to, $this->db->now() );

		$allowed = array( 'scheduled_at', 'started_at', 'completed_at', 'body_html', 'body_text', 'total_recipients', 'total_excluded' );
		foreach ( $extra as $column => $value ) {
			if ( ! in_array( $column, $allowed, true ) ) {
				continue;
			}
			if ( null === $value ) {
				$sets[] = "{$column} = NULL";
			} else {
				$sets[]   = "{$column} = %s";
				$params[] = (string) $value;
			}
		}

		$params[] = $id;
		$params[] = $from;

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->db->table( 'campaigns' )} SET " . implode( ', ', $sets ) . ' WHERE id = %d AND status = %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			)
		);

		return 1 === (int) $result;
	}

	/**
	 * Increment a counter column.
	 *
	 * @param int    $id     Campaign id.
	 * @param string $column total_sent|total_failed|total_skipped.
	 * @param int    $by     Amount.
	 * @return void
	 */
	public function increment( int $id, string $column, int $by = 1 ): void {
		if ( ! in_array( $column, array( 'total_sent', 'total_failed', 'total_skipped' ), true ) ) {
			return;
		}
		$wpdb = $this->db->wpdb();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->db->table( 'campaigns' )} SET {$column} = {$column} + %d, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$by,
				$this->db->now(),
				$id
			)
		);
	}

	/**
	 * Delete campaigns.
	 *
	 * @param int[] $ids Campaign ids.
	 * @return int
	 */
	public function delete_many( array $ids ): int {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$wpdb         = $this->db->wpdb();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$result       = $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->db->table( 'campaigns' )} WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return false === $result ? 0 : (int) $result;
	}
}
