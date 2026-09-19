<?php
/**
 * Email queue repository (ibg_email_queue).
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Queue;

use IBG\Outreach\Campaigns\Campaign;
use IBG\Outreach\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Class Queue_Repository
 */
final class Queue_Repository {

	/**
	 * Sortable columns.
	 *
	 * @var string[]
	 */
	private const SORTABLE = array( 'id', 'email', 'status', 'attempts', 'scheduled_at', 'last_attempt_at', 'sent_at', 'created_at' );

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
	 * @param int $id Row id.
	 * @return Queue_Item|null
	 */
	public function find( int $id ): ?Queue_Item {
		$wpdb = $this->db->wpdb();
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->db->table( 'email_queue' )} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? Queue_Item::from_row( $row ) : null;
	}

	/**
	 * Insert pending rows for a campaign. Existing (campaign, contact) pairs are ignored.
	 *
	 * @param int                $campaign_id  Campaign id.
	 * @param array<int, string> $contacts     contact_id => email.
	 * @param string             $scheduled_at UTC datetime.
	 * @return int Rows inserted.
	 */
	public function insert_pending( int $campaign_id, array $contacts, string $scheduled_at ): int {
		if ( $campaign_id <= 0 || empty( $contacts ) ) {
			return 0;
		}

		$wpdb     = $this->db->wpdb();
		$now      = $this->db->now();
		$inserted = 0;

		foreach ( array_chunk( $contacts, 500, true ) as $chunk ) {
			$values = array();
			$params = array();
			foreach ( $chunk as $contact_id => $email ) {
				$values[] = '(%d, %d, %s, %s, %s, %s)';
				array_push( $params, $campaign_id, (int) $contact_id, (string) $email, Queue_Item::STATUS_PENDING, $scheduled_at, $now );
			}
			$sql    = "INSERT IGNORE INTO {$this->db->table( 'email_queue' )} (campaign_id, contact_id, email, status, scheduled_at, created_at) VALUES " . implode( ', ', $values );
			$result = $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( false !== $result ) {
				$inserted += (int) $result;
			}
		}

		return $inserted;
	}

	/**
	 * Atomically claim a batch of due rows belonging to processing campaigns.
	 *
	 * @param string $token Unique lock token for this run.
	 * @param int    $limit Batch size.
	 * @param string $now   UTC datetime.
	 * @return Queue_Item[]
	 */
	public function claim( string $token, int $limit, string $now ): array {
		$wpdb      = $this->db->wpdb();
		$queue     = $this->db->table( 'email_queue' );
		$campaigns = $this->db->table( 'campaigns' );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT q.id FROM {$queue} q INNER JOIN {$campaigns} c ON c.id = q.campaign_id
				WHERE q.status = %s AND q.lock_token IS NULL AND q.scheduled_at <= %s AND c.status = %s
				ORDER BY q.scheduled_at ASC, q.id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Queue_Item::STATUS_PENDING,
				$now,
				Campaign::STATUS_PROCESSING,
				max( 1, $limit )
			)
		);
		$ids = array_map( 'intval', (array) $ids );
		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$queue} SET status = %s, lock_token = %s, locked_at = %s WHERE id IN ({$placeholders}) AND status = %s AND lock_token IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( Queue_Item::STATUS_SENDING, $token, $now ), $ids, array( Queue_Item::STATUS_PENDING ) )
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$queue} WHERE lock_token = %s ORDER BY id ASC", $token ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map( array( Queue_Item::class, 'from_row' ), (array) $rows );
	}

	/**
	 * Put a claimed row back to pending without counting an attempt (time budget / paused).
	 *
	 * @param int    $id    Row id.
	 * @param string $token Lock token that owns it.
	 * @return void
	 */
	public function release( int $id, string $token ): void {
		$wpdb = $this->db->wpdb();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->db->table( 'email_queue' )} SET status = %s, lock_token = NULL, locked_at = NULL WHERE id = %d AND lock_token = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Queue_Item::STATUS_PENDING,
				$id,
				$token
			)
		);
	}

	/**
	 * Recover rows stuck in "sending" (e.g. PHP fatal mid-batch).
	 *
	 * @param int $minutes Age threshold.
	 * @return int Rows released.
	 */
	public function release_stale( int $minutes ): int {
		$wpdb   = $this->db->wpdb();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $minutes ) * MINUTE_IN_SECONDS );
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->db->table( 'email_queue' )} SET status = %s, lock_token = NULL, locked_at = NULL, attempts = attempts + 1, error_message = %s
				WHERE status = %s AND locked_at IS NOT NULL AND locked_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Queue_Item::STATUS_PENDING,
				'Interrupted: lock expired',
				Queue_Item::STATUS_SENDING,
				$cutoff
			)
		);
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Mark sent.
	 *
	 * @param int    $id  Row id.
	 * @param string $now UTC datetime.
	 * @return void
	 */
	public function mark_sent( int $id, string $now ): void {
		$this->finish( $id, Queue_Item::STATUS_SENT, '', $now, $now );
	}

	/**
	 * Mark permanently failed.
	 *
	 * @param int    $id       Row id.
	 * @param string $error    Error message.
	 * @param int    $attempts Attempts so far.
	 * @param string $now      UTC datetime.
	 * @return void
	 */
	public function mark_failed( int $id, string $error, int $attempts, string $now ): void {
		$this->finish( $id, Queue_Item::STATUS_FAILED, $error, $now, null, $attempts );
	}

	/**
	 * Mark skipped with a reason.
	 *
	 * @param int    $id     Row id.
	 * @param string $reason Reason.
	 * @param string $now    UTC datetime.
	 * @return void
	 */
	public function mark_skipped( int $id, string $reason, string $now ): void {
		$this->finish( $id, Queue_Item::STATUS_SKIPPED, $reason, $now );
	}

	/**
	 * Schedule a retry.
	 *
	 * @param int    $id       Row id.
	 * @param string $error    Error message.
	 * @param int    $attempts Attempts so far.
	 * @param string $next_at  UTC datetime of the retry.
	 * @param string $now      UTC datetime.
	 * @return void
	 */
	public function reschedule( int $id, string $error, int $attempts, string $next_at, string $now ): void {
		$wpdb = $this->db->wpdb();
		$wpdb->update(
			$this->db->table( 'email_queue' ),
			array(
				'status'          => Queue_Item::STATUS_PENDING,
				'attempts'        => $attempts,
				'scheduled_at'    => $next_at,
				'lock_token'      => null,
				'locked_at'       => null,
				'last_attempt_at' => $now,
				'error_message'   => mb_substr( $error, 0, 2000 ),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Terminal state update.
	 *
	 * @param int         $id       Row id.
	 * @param string      $status   New status.
	 * @param string      $message  Error/reason.
	 * @param string      $now      UTC datetime.
	 * @param string|null $sent_at  Sent time or null.
	 * @param int|null    $attempts Attempts, or null to leave unchanged.
	 * @return void
	 */
	private function finish( int $id, string $status, string $message, string $now, ?string $sent_at = null, ?int $attempts = null ): void {
		$wpdb = $this->db->wpdb();
		$data = array(
			'status'          => $status,
			'lock_token'      => null,
			'locked_at'       => null,
			'last_attempt_at' => $now,
			'sent_at'         => $sent_at,
			'error_message'   => mb_substr( $message, 0, 2000 ),
		);
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s' );
		if ( null !== $attempts ) {
			$data['attempts'] = $attempts;
			$formats[]        = '%d';
		}
		$wpdb->update( $this->db->table( 'email_queue' ), $data, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Skip all pending rows of a campaign (cancel).
	 *
	 * @param int    $campaign_id Campaign id.
	 * @param string $reason      Reason.
	 * @return int Rows skipped.
	 */
	public function skip_pending_for_campaign( int $campaign_id, string $reason ): int {
		$wpdb   = $this->db->wpdb();
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->db->table( 'email_queue' )} SET status = %s, error_message = %s, lock_token = NULL, locked_at = NULL, last_attempt_at = %s WHERE campaign_id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Queue_Item::STATUS_SKIPPED,
				$reason,
				$this->db->now(),
				$campaign_id,
				Queue_Item::STATUS_PENDING
			)
		);
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Delete all rows of a campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return int
	 */
	public function delete_for_campaign( int $campaign_id ): int {
		$wpdb   = $this->db->wpdb();
		$result = $wpdb->delete( $this->db->table( 'email_queue' ), array( 'campaign_id' => $campaign_id ), array( '%d' ) );
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Pending + sending rows for a campaign.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return int
	 */
	public function count_open( int $campaign_id ): int {
		$wpdb = $this->db->wpdb();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->db->table( 'email_queue' )} WHERE campaign_id = %d AND status IN (%s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$campaign_id,
				Queue_Item::STATUS_PENDING,
				Queue_Item::STATUS_SENDING
			)
		);
	}

	/**
	 * Counts per status, optionally for one campaign.
	 *
	 * @param int $campaign_id Campaign id or 0 for all.
	 * @return array<string, int>
	 */
	public function count_by_status( int $campaign_id = 0 ): array {
		$wpdb = $this->db->wpdb();
		$sql  = "SELECT status, COUNT(*) AS total FROM {$this->db->table( 'email_queue' )}";
		if ( $campaign_id > 0 ) {
			$sql .= $wpdb->prepare( ' WHERE campaign_id = %d', $campaign_id );
		}
		$sql .= ' GROUP BY status';

		$counts = array_fill_keys( array_keys( Queue_Item::statuses() ), 0 );
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/**
	 * Query rows for the admin table.
	 *
	 * @param array<string, mixed> $args campaign_id, status, search, orderby, order, per_page, page.
	 * @return array{items: Queue_Item[], total: int}
	 */
	public function query( array $args = array() ): array {
		$args = wp_parse_args(
			$args,
			array(
				'campaign_id' => 0,
				'status'      => '',
				'search'      => '',
				'orderby'     => 'id',
				'order'       => 'DESC',
				'per_page'    => 50,
				'page'        => 1,
			)
		);

		$wpdb   = $this->db->wpdb();
		$table  = $this->db->table( 'email_queue' );
		$where  = array( '1=1' );
		$params = array();

		if ( (int) $args['campaign_id'] > 0 ) {
			$where[]  = 'campaign_id = %d';
			$params[] = (int) $args['campaign_id'];
		}
		if ( '' !== $args['status'] && array_key_exists( (string) $args['status'], Queue_Item::statuses() ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}
		if ( '' !== $args['search'] ) {
			$where[]  = 'email LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}

		$where_sql = implode( ' AND ', $where );
		$orderby   = in_array( $args['orderby'], self::SORTABLE, true ) ? $args['orderby'] : 'id';
		$order     = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$per_page  = max( 1, (int) $args['per_page'] );
		$offset    = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( empty( $params ) ? $count_sql : $wpdb->prepare( $count_sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'items' => array_map( array( Queue_Item::class, 'from_row' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Requeue failed rows (attempts reset).
	 *
	 * @param int[] $ids Row ids.
	 * @return int
	 */
	public function retry( array $ids ): int {
		return $this->bulk_status( $ids, Queue_Item::STATUS_FAILED, Queue_Item::STATUS_PENDING, 'attempts = 0, scheduled_at = %s, error_message = %s', array( $this->db->now(), '' ) );
	}

	/**
	 * Skip pending rows manually.
	 *
	 * @param int[] $ids Row ids.
	 * @return int
	 */
	public function skip( array $ids ): int {
		return $this->bulk_status( $ids, Queue_Item::STATUS_PENDING, Queue_Item::STATUS_SKIPPED, 'error_message = %s, last_attempt_at = %s', array( 'Skipped manually', $this->db->now() ) );
	}

	/**
	 * Change status for rows currently in $from.
	 *
	 * @param int[]    $ids    Row ids.
	 * @param string   $from   Required current status.
	 * @param string   $to     New status.
	 * @param string   $extra  Extra SET clause with placeholders.
	 * @param string[] $params Params for the extra clause.
	 * @return int Rows changed.
	 */
	private function bulk_status( array $ids, string $from, string $to, string $extra, array $params ): int {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$wpdb         = $this->db->wpdb();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$result       = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->db->table( 'email_queue' )} SET status = %s, lock_token = NULL, locked_at = NULL, {$extra} WHERE id IN ({$placeholders}) AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $to ), $params, $ids, array( $from ) )
			)
		);
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Delete rows.
	 *
	 * @param int[] $ids Row ids.
	 * @return int
	 */
	public function delete_many( array $ids ): int {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$wpdb         = $this->db->wpdb();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$result       = $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->db->table( 'email_queue' )} WHERE id IN ({$placeholders}) AND status <> %s", array_merge( $ids, array( Queue_Item::STATUS_SENDING ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Strip personal data from a contact's queue rows (erasure). Pending rows
	 * are skipped so nothing is sent to an erased person.
	 *
	 * @param int $contact_id Contact id.
	 * @return int Rows anonymised.
	 */
	public function anonymize_contact( int $contact_id ): int {
		$wpdb   = $this->db->wpdb();
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->db->table( 'email_queue' )} SET email = '', error_message = %s,
				status = CASE WHEN status IN (%s, %s) THEN %s ELSE status END,
				lock_token = NULL, locked_at = NULL
				WHERE contact_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'Personal data erased',
				Queue_Item::STATUS_PENDING,
				Queue_Item::STATUS_SENDING,
				Queue_Item::STATUS_SKIPPED,
				$contact_id
			)
		);
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Delete finished rows older than N days.
	 *
	 * @param int $days Retention days.
	 * @return int
	 */
	public function cleanup( int $days ): int {
		$wpdb   = $this->db->wpdb();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->db->table( 'email_queue' )} WHERE status IN (%s, %s, %s) AND created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Queue_Item::STATUS_SENT,
				Queue_Item::STATUS_FAILED,
				Queue_Item::STATUS_SKIPPED,
				$cutoff
			)
		);
		return false === $result ? 0 : (int) $result;
	}
}
