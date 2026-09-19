<?php
/**
 * Email log repository (ibg_email_logs).
 *
 * One row per send attempt or skip. Bodies are never stored.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Queue;

use IBG\Outreach\Database;
use IBG\Outreach\Email\Email_Message;
use IBG\Outreach\Email\Send_Result;

defined( 'ABSPATH' ) || exit;

/**
 * Class Email_Log_Repository
 */
final class Email_Log_Repository {

	public const STATUS_SENT    = 'sent';
	public const STATUS_FAILED  = 'failed';
	public const STATUS_SKIPPED = 'skipped';
	public const STATUS_TEST    = 'test';

	/**
	 * Sortable columns.
	 *
	 * @var string[]
	 */
	private const SORTABLE = array( 'id', 'email', 'status', 'provider', 'sent_at', 'created_at' );

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
	 * Status labels.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_SENT    => __( 'Sent', 'ibg-client-outreach' ),
			self::STATUS_FAILED  => __( 'Failed', 'ibg-client-outreach' ),
			self::STATUS_SKIPPED => __( 'Skipped', 'ibg-client-outreach' ),
			self::STATUS_TEST    => __( 'Test', 'ibg-client-outreach' ),
		);
	}

	/**
	 * Write a log row.
	 *
	 * @param array<string, mixed> $data campaign_id, contact_id, queue_id, email, subject, status, provider, provider_message_id, error_message, retry_count, sent_at.
	 * @return int Log id.
	 */
	public function log( array $data ): int {
		$wpdb = $this->db->wpdb();
		$row  = array(
			'campaign_id'         => (int) ( $data['campaign_id'] ?? 0 ),
			'contact_id'          => (int) ( $data['contact_id'] ?? 0 ),
			'queue_id'            => (int) ( $data['queue_id'] ?? 0 ),
			'email'               => mb_substr( (string) ( $data['email'] ?? '' ), 0, 190 ),
			'subject'             => mb_substr( (string) ( $data['subject'] ?? '' ), 0, 255 ),
			'status'              => (string) ( $data['status'] ?? self::STATUS_FAILED ),
			'provider'            => mb_substr( (string) ( $data['provider'] ?? '' ), 0, 50 ),
			'provider_message_id' => isset( $data['provider_message_id'] ) && '' !== $data['provider_message_id'] ? mb_substr( (string) $data['provider_message_id'], 0, 190 ) : null,
			'error_message'       => isset( $data['error_message'] ) && '' !== $data['error_message'] ? mb_substr( (string) $data['error_message'], 0, 2000 ) : null,
			'retry_count'         => (int) ( $data['retry_count'] ?? 0 ),
			'sent_at'             => $data['sent_at'] ?? null,
			'created_at'          => $this->db->now(),
		);

		$result = $wpdb->insert( $this->db->table( 'email_logs' ), $row, array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ) );
		return false === $result ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Log a test send.
	 *
	 * @param Email_Message $message  Message.
	 * @param Send_Result   $result   Result.
	 * @param string        $provider Provider id.
	 * @return int
	 */
	public function log_test( Email_Message $message, Send_Result $result, string $provider ): int {
		$context = $message->get_context();
		return $this->log(
			array(
				'campaign_id'         => (int) ( $context['campaign_id'] ?? 0 ),
				'contact_id'          => 0,
				'email'               => $message->get_to_email(),
				'subject'             => $message->get_subject(),
				'status'              => self::STATUS_TEST,
				'provider'            => $provider,
				'provider_message_id' => $result->get_message_id(),
				'error_message'       => $result->is_success() ? '' : $result->get_error(),
				'sent_at'             => $result->is_success() ? $this->db->now() : null,
			)
		);
	}

	/**
	 * Query logs.
	 *
	 * @param array<string, mixed> $args campaign_id, status, search, date_from, date_to, orderby, order, per_page, page.
	 * @return array{items: object[], total: int}
	 */
	public function query( array $args = array() ): array {
		$args = wp_parse_args(
			$args,
			array(
				'campaign_id' => 0,
				'status'      => '',
				'search'      => '',
				'date_from'   => '',
				'date_to'     => '',
				'orderby'     => 'id',
				'order'       => 'DESC',
				'per_page'    => 50,
				'page'        => 1,
			)
		);

		$wpdb   = $this->db->wpdb();
		$table  = $this->db->table( 'email_logs' );
		$where  = array( '1=1' );
		$params = array();

		if ( (int) $args['campaign_id'] > 0 ) {
			$where[]  = 'campaign_id = %d';
			$params[] = (int) $args['campaign_id'];
		}
		if ( '' !== $args['status'] && array_key_exists( (string) $args['status'], self::statuses() ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}
		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(email LIKE %s OR subject LIKE %s OR error_message LIKE %s)';
			$params   = array_merge( $params, array( $like, $like, $like ) );
		}
		$from = \IBG\Outreach\Formatting::local_to_utc( (string) $args['date_from'] );
		if ( $from ) {
			$where[]  = 'created_at >= %s';
			$params[] = $from;
		}
		$to = \IBG\Outreach\Formatting::local_to_utc( (string) $args['date_to'] );
		if ( $to ) {
			$where[]  = 'created_at < %s';
			$params[] = gmdate( 'Y-m-d H:i:s', strtotime( $to . ' UTC' ) + DAY_IN_SECONDS );
		}

		$where_sql = implode( ' AND ', $where );
		$orderby   = in_array( $args['orderby'], self::SORTABLE, true ) ? $args['orderby'] : 'id';
		$order     = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$per_page  = max( 1, (int) $args['per_page'] );
		$offset    = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( empty( $params ) ? $count_sql : $wpdb->prepare( $count_sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$sql   = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";
		$items = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, $offset ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'items' => (array) $items,
			'total' => $total,
		);
	}

	/**
	 * Most recent log row for a provider message id (webhook correlation).
	 *
	 * @param string $message_id Provider message id.
	 * @return object|null
	 */
	public function find_by_message_id( string $message_id ): ?object {
		if ( '' === $message_id ) {
			return null;
		}
		$wpdb = $this->db->wpdb();
		$row  = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->db->table( 'email_logs' )} WHERE provider_message_id = %s ORDER BY id DESC LIMIT 1", $message_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return $row ? $row : null;
	}

	/**
	 * Counts per status, optionally per campaign.
	 *
	 * @param int $campaign_id Campaign id or 0.
	 * @return array<string, int>
	 */
	public function count_by_status( int $campaign_id = 0 ): array {
		$wpdb = $this->db->wpdb();
		$sql  = "SELECT status, COUNT(*) AS total FROM {$this->db->table( 'email_logs' )}";
		if ( $campaign_id > 0 ) {
			$sql .= $wpdb->prepare( ' WHERE campaign_id = %d', $campaign_id );
		}
		$sql .= ' GROUP BY status';

		$counts = array_fill_keys( array_keys( self::statuses() ), 0 );
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/**
	 * Delete selected rows.
	 *
	 * @param int[] $ids Log ids.
	 * @return int
	 */
	public function delete_many( array $ids ): int {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$wpdb         = $this->db->wpdb();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$result       = $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->db->table( 'email_logs' )} WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Strip personal data from log rows (erasure). Campaign statistics are
	 * unaffected because they live on the campaign row.
	 *
	 * @param int    $contact_id Contact id (0 to match by email only).
	 * @param string $email      Normalised email (matches test sends too).
	 * @return int Rows anonymised.
	 */
	public function anonymize( int $contact_id, string $email ): int {
		$wpdb   = $this->db->wpdb();
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->db->table( 'email_logs' )} SET email = '', contact_id = 0, provider_message_id = NULL WHERE contact_id = %d OR email = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$contact_id > 0 ? $contact_id : -1,
				$email
			)
		);
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Delete logs older than N days.
	 *
	 * @param int $days Retention days.
	 * @return int
	 */
	public function cleanup( int $days ): int {
		$wpdb   = $this->db->wpdb();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->db->table( 'email_logs' )} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return false === $result ? 0 : (int) $result;
	}
}
