<?php
/**
 * Suppression list repository (ibg_suppressions).
 *
 * The suppression list is the authoritative "never email this address"
 * record. It is keyed by a SHA-256 hash so it can outlive the contact row
 * (and a GDPR erasure of the plain email) while still blocking re-import.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Unsubscribe;

use IBG\Outreach\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Class Suppression_Repository
 */
final class Suppression_Repository {

	public const REASON_UNSUBSCRIBED = 'unsubscribed';
	public const REASON_DNC          = 'do_not_contact';
	public const REASON_BOUNCED      = 'bounced';
	public const REASON_COMPLAINT    = 'complaint';

	public const SOURCE_LINK   = 'link';
	public const SOURCE_ADMIN  = 'admin';
	public const SOURCE_IMPORT = 'import';
	public const SOURCE_SYSTEM = 'system';

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
	 * Hash a normalised email.
	 *
	 * @param string $email Normalised (lowercase, trimmed) email.
	 * @return string
	 */
	public static function hash( string $email ): string {
		return hash( 'sha256', $email );
	}

	/**
	 * Find the suppression record for an email.
	 *
	 * @param string $email Normalised email.
	 * @return object|null Row with id, email_hash, email, contact_id, campaign_id, reason, source, created_at.
	 */
	public function find( string $email ): ?object {
		if ( '' === $email ) {
			return null;
		}
		$wpdb = $this->db->wpdb();
		$row  = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->db->table( 'suppressions' )} WHERE email_hash = %s", self::hash( $email ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return $row ? $row : null;
	}

	/**
	 * Whether an email is suppressed.
	 *
	 * @param string $email Normalised email.
	 * @return bool
	 */
	public function is_suppressed( string $email ): bool {
		return null !== $this->find( $email );
	}

	/**
	 * Hashes of suppressed emails from a candidate set (importer / audience checks).
	 *
	 * @param string[] $emails Normalised emails.
	 * @return array<string, string> email => reason for those that are suppressed.
	 */
	public function find_suppressed( array $emails ): array {
		$emails = array_values( array_unique( array_filter( $emails ) ) );
		if ( empty( $emails ) ) {
			return array();
		}

		$wpdb   = $this->db->wpdb();
		$result = array();

		foreach ( array_chunk( $emails, 500 ) as $chunk ) {
			$hashes       = array_map( array( __CLASS__, 'hash' ), $chunk );
			$by_hash      = array_combine( $hashes, $chunk );
			$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
			$rows         = $wpdb->get_results(
				$wpdb->prepare( "SELECT email_hash, reason FROM {$this->db->table( 'suppressions' )} WHERE email_hash IN ({$placeholders})", $hashes ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				if ( isset( $by_hash[ $row['email_hash'] ] ) ) {
					$result[ $by_hash[ $row['email_hash'] ] ] = (string) $row['reason'];
				}
			}
		}

		return $result;
	}

	/**
	 * Add or escalate a suppression.
	 *
	 * Do-not-contact is never downgraded to a weaker reason; other reasons
	 * are updated to the latest one.
	 *
	 * @param string   $email       Normalised email.
	 * @param string   $reason      One of the REASON_* constants.
	 * @param string   $source      One of the SOURCE_* constants.
	 * @param int|null $contact_id  Related contact.
	 * @param int|null $campaign_id Related campaign.
	 * @return bool
	 */
	public function add( string $email, string $reason, string $source, ?int $contact_id = null, ?int $campaign_id = null ): bool {
		if ( '' === $email ) {
			return false;
		}

		$wpdb     = $this->db->wpdb();
		$table    = $this->db->table( 'suppressions' );
		$existing = $this->find( $email );

		if ( $existing ) {
			if ( self::REASON_DNC === $existing->reason && self::REASON_DNC !== $reason ) {
				return true; // Keep the stronger suppression.
			}
			$result = $wpdb->update(
				$table,
				array(
					'email'       => $email,
					'contact_id'  => $contact_id,
					'campaign_id' => $campaign_id,
					'reason'      => $reason,
					'source'      => $source,
				),
				array( 'id' => (int) $existing->id ),
				array( '%s', '%d', '%d', '%s', '%s' ),
				array( '%d' )
			);
			return false !== $result;
		}

		$result = $wpdb->insert(
			$table,
			array(
				'email_hash'  => self::hash( $email ),
				'email'       => $email,
				'contact_id'  => $contact_id,
				'campaign_id' => $campaign_id,
				'reason'      => $reason,
				'source'      => $source,
				'created_at'  => $this->db->now(),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Remove a suppression (explicit, confirmed resubscribe only).
	 *
	 * @param string $email Normalised email.
	 * @return bool
	 */
	public function remove( string $email ): bool {
		if ( '' === $email ) {
			return false;
		}
		$wpdb   = $this->db->wpdb();
		$result = $wpdb->delete( $this->db->table( 'suppressions' ), array( 'email_hash' => self::hash( $email ) ), array( '%s' ) );
		return false !== $result;
	}

	/**
	 * Reason labels.
	 *
	 * @return array<string, string>
	 */
	public static function reasons(): array {
		return array(
			self::REASON_UNSUBSCRIBED => __( 'Unsubscribed', 'ibg-client-outreach' ),
			self::REASON_DNC          => __( 'Do Not Contact', 'ibg-client-outreach' ),
			self::REASON_BOUNCED      => __( 'Bounced', 'ibg-client-outreach' ),
			self::REASON_COMPLAINT    => __( 'Complaint', 'ibg-client-outreach' ),
		);
	}

	/**
	 * Query for the admin table.
	 *
	 * @param array<string, mixed> $args search, reason, orderby, order, per_page, page.
	 * @return array{items: object[], total: int}
	 */
	public function query( array $args = array() ): array {
		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'reason'   => '',
				'orderby'  => 'created_at',
				'order'    => 'DESC',
				'per_page' => 50,
				'page'     => 1,
			)
		);

		$wpdb   = $this->db->wpdb();
		$table  = $this->db->table( 'suppressions' );
		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['search'] ) {
			$where[]  = 'email LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}
		if ( '' !== $args['reason'] && array_key_exists( (string) $args['reason'], self::reasons() ) ) {
			$where[]  = 'reason = %s';
			$params[] = (string) $args['reason'];
		}

		$where_sql = implode( ' AND ', $where );
		$orderby   = in_array( $args['orderby'], array( 'id', 'email', 'reason', 'source', 'created_at' ), true ) ? $args['orderby'] : 'created_at';
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
	 * Counts per reason.
	 *
	 * @return array<string, int>
	 */
	public function count_by_reason(): array {
		$wpdb   = $this->db->wpdb();
		$rows   = $wpdb->get_results( "SELECT reason, COUNT(*) AS total FROM {$this->db->table( 'suppressions' )} GROUP BY reason", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts = array_fill_keys( array_keys( self::reasons() ), 0 );
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['reason'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/**
	 * Find by row id.
	 *
	 * @param int $id Row id.
	 * @return object|null
	 */
	public function find_by_id( int $id ): ?object {
		$wpdb = $this->db->wpdb();
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->db->table( 'suppressions' )} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $row : null;
	}

	/**
	 * Delete a row by id (admin removal of an entry with no contact record).
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public function delete_by_id( int $id ): bool {
		$wpdb = $this->db->wpdb();
		return false !== $wpdb->delete( $this->db->table( 'suppressions' ), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Stream all rows for export (generator; no full-table memory).
	 *
	 * @return \Generator<object>
	 */
	public function iterate(): \Generator {
		$wpdb  = $this->db->wpdb();
		$after = 0;
		while ( true ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->db->table( 'suppressions' )} WHERE id > %d ORDER BY id ASC LIMIT 1000", $after ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( empty( $rows ) ) {
				return;
			}
			foreach ( $rows as $row ) {
				$after = (int) $row->id;
				yield $row;
			}
		}
	}

	/**
	 * Total number of suppressions, optionally by reason.
	 *
	 * @param string $reason Optional reason filter.
	 * @return int
	 */
	public function count( string $reason = '' ): int {
		$wpdb  = $this->db->wpdb();
		$table = $this->db->table( 'suppressions' );
		if ( '' === $reason ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE reason = %s", $reason ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
