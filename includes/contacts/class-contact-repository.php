<?php
/**
 * Contact repository: all SQL for the ibg_contacts table.
 *
 * No business rules live here. Use Contact_Service for writes.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Contacts;

use IBG\Outreach\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Class Contact_Repository
 */
final class Contact_Repository {

	/**
	 * Columns that may be used in ORDER BY.
	 *
	 * @var string[]
	 */
	private const SORTABLE = array(
		'id',
		'email',
		'first_name',
		'last_name',
		'full_name',
		'company',
		'country',
		'industry',
		'source',
		'contact_status',
		'marketing_status',
		'created_at',
		'updated_at',
		'last_contacted_at',
	);

	/**
	 * Columns that may be used for equality filters and grouped counts.
	 *
	 * @var string[]
	 */
	private const FILTERABLE = array(
		'contact_status',
		'marketing_status',
		'email_status',
		'industry',
		'country',
		'source',
		'consent_basis',
	);

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
	 * @param int $id Contact id.
	 * @return Contact|null
	 */
	public function find( int $id ): ?Contact {
		$wpdb = $this->db->wpdb();
		$row  = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->db->table( 'contacts' )} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ? Contact::from_row( $row ) : null;
	}

	/**
	 * Find by (normalised) email.
	 *
	 * @param string $email Normalised email.
	 * @return Contact|null
	 */
	public function find_by_email( string $email ): ?Contact {
		if ( '' === $email ) {
			return null;
		}
		$wpdb = $this->db->wpdb();
		$row  = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->db->table( 'contacts' )} WHERE email = %s", $email ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ? Contact::from_row( $row ) : null;
	}

	/**
	 * Find several contacts by id.
	 *
	 * @param int[] $ids Contact ids.
	 * @return Contact[] Keyed by id.
	 */
	public function find_many( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$wpdb         = $this->db->wpdb();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->db->table( 'contacts' )} WHERE id IN ({$placeholders})", $ids ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$contacts = array();
		foreach ( (array) $rows as $row ) {
			$contact                  = Contact::from_row( $row );
			$contacts[ $contact->id ] = $contact;
		}
		return $contacts;
	}

	/**
	 * Map of email => id for a set of normalised emails (used by the importer).
	 *
	 * @param string[] $emails Normalised emails.
	 * @return array<string, int>
	 */
	public function find_ids_by_emails( array $emails ): array {
		$emails = array_values( array_unique( array_filter( $emails ) ) );
		if ( empty( $emails ) ) {
			return array();
		}

		$wpdb = $this->db->wpdb();
		$map  = array();

		foreach ( array_chunk( $emails, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows         = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, email FROM {$this->db->table( 'contacts' )} WHERE email IN ({$placeholders})", $chunk ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$map[ (string) $row['email'] ] = (int) $row['id'];
			}
		}

		return $map;
	}

	/**
	 * Insert a contact. Sets created_at/updated_at and the new id.
	 *
	 * @param Contact $contact Contact.
	 * @return int New id, or 0 on failure.
	 */
	public function insert( Contact $contact ): int {
		$wpdb = $this->db->wpdb();
		$now  = $this->db->now();

		$contact->created_at = $now;
		$contact->updated_at = $now;

		$result = $wpdb->insert( $this->db->table( 'contacts' ), $contact->to_row(), Contact::row_formats() );
		if ( false === $result ) {
			return 0;
		}

		$contact->id = (int) $wpdb->insert_id;
		return $contact->id;
	}

	/**
	 * Update a contact. Sets updated_at.
	 *
	 * @param Contact $contact Contact with id.
	 * @return bool
	 */
	public function update( Contact $contact ): bool {
		if ( $contact->id <= 0 ) {
			return false;
		}

		$wpdb                = $this->db->wpdb();
		$contact->updated_at = $this->db->now();

		$result = $wpdb->update(
			$this->db->table( 'contacts' ),
			$contact->to_row(),
			array( 'id' => $contact->id ),
			Contact::row_formats(),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Update specific columns for a set of contacts (bulk actions).
	 *
	 * @param int[]                $ids    Contact ids.
	 * @param array<string, mixed> $fields Column => value. Only whitelisted columns are applied.
	 * @return int Rows affected.
	 */
	public function update_many( array $ids, array $fields ): int {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$allowed = array( 'contact_status', 'marketing_status', 'email_status', 'industry', 'country', 'source', 'unsubscribed_at', 'last_contacted_at', 'last_opened_at', 'last_clicked_at' );
		$fields  = array_intersect_key( $fields, array_flip( $allowed ) );
		if ( empty( $fields ) ) {
			return 0;
		}

		$wpdb   = $this->db->wpdb();
		$sets   = array();
		$params = array();

		foreach ( $fields as $column => $value ) {
			if ( null === $value ) {
				$sets[] = "{$column} = NULL";
			} else {
				$sets[]   = "{$column} = %s";
				$params[] = (string) $value;
			}
		}
		$sets[]   = 'updated_at = %s';
		$params[] = $this->db->now();

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_merge( $params, $ids );

		$sql = "UPDATE {$this->db->table( 'contacts' )} SET " . implode( ', ', $sets ) . " WHERE id IN ({$placeholders})";

		$result = $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Delete contacts and their meta / list memberships.
	 *
	 * @param int[] $ids Contact ids.
	 * @return int Contacts deleted.
	 */
	public function delete_many( array $ids ): int {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$wpdb         = $this->db->wpdb();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->db->table( 'contact_meta' )} WHERE contact_id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->db->table( 'contact_lists' )} WHERE contact_id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->db->table( 'contacts' )} WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Query contacts with filters, search, sorting and pagination.
	 *
	 * @param array<string, mixed> $args See build_conditions() plus orderby, order, per_page, page.
	 * @return array{items: Contact[], total: int}
	 */
	public function query( array $args = array() ): array {
		$args = wp_parse_args(
			$args,
			array(
				'orderby'  => 'created_at',
				'order'    => 'DESC',
				'per_page' => 20,
				'page'     => 1,
			)
		);

		$wpdb  = $this->db->wpdb();
		$table = $this->db->table( 'contacts' );

		list( $join, $where, $params ) = $this->build_conditions( $args );

		$orderby = in_array( $args['orderby'], self::SORTABLE, true ) ? $args['orderby'] : 'created_at';
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$count_sql = "SELECT COUNT(*) FROM {$table} c {$join} WHERE {$where}";
		$total     = (int) $wpdb->get_var( empty( $params ) ? $count_sql : $wpdb->prepare( $count_sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$sql  = "SELECT c.* FROM {$table} c {$join} WHERE {$where} ORDER BY c.{$orderby} {$order}, c.id {$order} LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = Contact::from_row( $row );
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Count contacts matching the given filters.
	 *
	 * @param array<string, mixed> $args Filters (see build_conditions()).
	 * @return int
	 */
	public function count( array $args = array() ): int {
		$wpdb = $this->db->wpdb();
		list( $join, $where, $params ) = $this->build_conditions( $args );

		$sql = "SELECT COUNT(*) FROM {$this->db->table( 'contacts' )} c {$join} WHERE {$where}";

		return (int) $wpdb->get_var( empty( $params ) ? $sql : $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Counts grouped by a filterable column, e.g. contact_status => count.
	 *
	 * @param string               $column Column name (must be in FILTERABLE).
	 * @param array<string, mixed> $args   Additional filters.
	 * @return array<string, int>
	 */
	public function count_by( string $column, array $args = array() ): array {
		if ( ! in_array( $column, self::FILTERABLE, true ) ) {
			return array();
		}

		$wpdb = $this->db->wpdb();
		list( $join, $where, $params ) = $this->build_conditions( $args );

		$sql  = "SELECT c.{$column} AS value, COUNT(*) AS total FROM {$this->db->table( 'contacts' )} c {$join} WHERE {$where} GROUP BY c.{$column}";
		$rows = $wpdb->get_results( empty( $params ) ? $sql : $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['value'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/**
	 * Distinct non-empty values of a filterable column, for filter dropdowns.
	 *
	 * @param string $column Column name (must be in FILTERABLE).
	 * @return string[]
	 */
	public function get_distinct( string $column ): array {
		if ( ! in_array( $column, self::FILTERABLE, true ) ) {
			return array();
		}

		$wpdb   = $this->db->wpdb();
		$values = $wpdb->get_col(
			"SELECT DISTINCT {$column} FROM {$this->db->table( 'contacts' )} WHERE {$column} <> '' ORDER BY {$column} ASC LIMIT 1000" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return array_map( 'strval', (array) $values );
	}

	/**
	 * Build JOIN / WHERE / params for a filter set.
	 *
	 * Supported args:
	 *   search, contact_status, marketing_status, email_status, industry, country,
	 *   source, consent_basis (string or string[]), list_id (int), date_from, date_to
	 *   (Y-m-d in site timezone), ids (int[]), mailable_only (bool).
	 *
	 * @param array<string, mixed> $args Filters.
	 * @return array{0: string, 1: string, 2: array<int, mixed>}
	 */
	public function build_conditions( array $args ): array {
		$wpdb   = $this->db->wpdb();
		$join   = '';
		$where  = array( '1=1' );
		$params = array();

		foreach ( self::FILTERABLE as $column ) {
			if ( empty( $args[ $column ] ) ) {
				continue;
			}
			$values = array_values( array_filter( array_map( 'strval', (array) $args[ $column ] ), 'strlen' ) );
			if ( empty( $values ) ) {
				continue;
			}
			$where[] = "c.{$column} IN (" . implode( ',', array_fill( 0, count( $values ), '%s' ) ) . ')';
			$params  = array_merge( $params, $values );
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( trim( (string) $args['search'] ) ) . '%';
			$where[]  = '(c.email LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s OR c.full_name LIKE %s OR c.company LIKE %s OR c.website LIKE %s)';
			$params   = array_merge( $params, array_fill( 0, 6, $like ) );
		}

		if ( ! empty( $args['list_id'] ) ) {
			$join     = "INNER JOIN {$this->db->table( 'contact_lists' )} cl ON cl.contact_id = c.id AND cl.list_id = %d";
			$params   = array_merge( array( (int) $args['list_id'] ), $params );
		}

		if ( ! empty( $args['date_from'] ) ) {
			$from = \IBG\Outreach\Formatting::local_to_utc( (string) $args['date_from'] );
			if ( $from ) {
				$where[]  = 'c.created_at >= %s';
				$params[] = $from;
			}
		}

		if ( ! empty( $args['date_to'] ) ) {
			$to = \IBG\Outreach\Formatting::local_to_utc( (string) $args['date_to'] );
			if ( $to ) {
				$where[]  = 'c.created_at < %s';
				$params[] = gmdate( 'Y-m-d H:i:s', strtotime( $to . ' UTC' ) + DAY_IN_SECONDS );
			}
		}

		if ( ! empty( $args['ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'intval', (array) $args['ids'] ) ) );
			if ( ! empty( $ids ) ) {
				$where[] = 'c.id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
				$params  = array_merge( $params, $ids );
			} else {
				$where[] = '1=0';
			}
		}

		if ( ! empty( $args['mailable_only'] ) ) {
			$suppressed = Contact::suppressed_statuses();
			$where[]    = 'c.marketing_status NOT IN (' . implode( ',', array_fill( 0, count( $suppressed ), '%s' ) ) . ')';
			$params     = array_merge( $params, $suppressed );
			$where[]    = 'c.email_status = %s';
			$params[]   = Contact::EMAIL_VALID;
		}

		return array( $join, implode( ' AND ', $where ), $params );
	}
}
