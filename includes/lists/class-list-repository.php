<?php
/**
 * List repository: ibg_lists and ibg_contact_lists.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Lists;

use IBG\Outreach\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Class List_Repository
 */
final class List_Repository {

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
	 * @param int $id List id.
	 * @return Contact_List|null
	 */
	public function find( int $id ): ?Contact_List {
		$wpdb = $this->db->wpdb();
		$row  = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->db->table( 'lists' )} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ? Contact_List::from_row( $row ) : null;
	}

	/**
	 * Find by slug.
	 *
	 * @param string $slug Slug.
	 * @return Contact_List|null
	 */
	public function find_by_slug( string $slug ): ?Contact_List {
		$wpdb = $this->db->wpdb();
		$row  = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->db->table( 'lists' )} WHERE slug = %s", $slug ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ? Contact_List::from_row( $row ) : null;
	}

	/**
	 * All lists ordered by name, with static member counts. Segment counts are
	 * computed by List_Service because they need the contact repository.
	 *
	 * @param string $type Optional type filter.
	 * @return array<int, Contact_List> Keyed by id.
	 */
	public function all( string $type = '' ): array {
		$wpdb   = $this->db->wpdb();
		$lists  = $this->db->table( 'lists' );
		$member = $this->db->table( 'contact_lists' );

		$sql = "SELECT l.*, (SELECT COUNT(*) FROM {$member} cl WHERE cl.list_id = l.id) AS member_count FROM {$lists} l";
		if ( '' !== $type ) {
			$sql .= $wpdb->prepare( ' WHERE l.type = %s', $type );
		}
		$sql .= ' ORDER BY l.name ASC';

		$rows   = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = array();
		foreach ( (array) $rows as $row ) {
			$list                = Contact_List::from_row( $row );
			$result[ $list->id ] = $list;
		}
		return $result;
	}

	/**
	 * Insert.
	 *
	 * @param Contact_List $list List.
	 * @return int New id or 0.
	 */
	public function insert( Contact_List $list ): int {
		$wpdb             = $this->db->wpdb();
		$list->created_at = $this->db->now();
		$list->updated_at = $list->created_at;

		$result = $wpdb->insert( $this->db->table( 'lists' ), $list->to_row(), Contact_List::row_formats() );
		if ( false === $result ) {
			return 0;
		}
		$list->id = (int) $wpdb->insert_id;
		return $list->id;
	}

	/**
	 * Update.
	 *
	 * @param Contact_List $list List.
	 * @return bool
	 */
	public function update( Contact_List $list ): bool {
		if ( $list->id <= 0 ) {
			return false;
		}
		$wpdb             = $this->db->wpdb();
		$list->updated_at = $this->db->now();

		$result = $wpdb->update(
			$this->db->table( 'lists' ),
			$list->to_row(),
			array( 'id' => $list->id ),
			Contact_List::row_formats(),
			array( '%d' )
		);
		return false !== $result;
	}

	/**
	 * Delete lists and their memberships.
	 *
	 * @param int[] $ids List ids.
	 * @return int Lists deleted.
	 */
	public function delete_many( array $ids ): int {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$wpdb         = $this->db->wpdb();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->db->table( 'contact_lists' )} WHERE list_id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->db->table( 'lists' )} WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Generate a unique slug from a name.
	 *
	 * @param string $name       Name.
	 * @param int    $exclude_id List id being edited (its own slug is allowed).
	 * @return string
	 */
	public function unique_slug( string $name, int $exclude_id = 0 ): string {
		$base = sanitize_title( $name );
		if ( '' === $base ) {
			$base = 'list';
		}
		$base = mb_substr( $base, 0, 180 );

		$slug   = $base;
		$suffix = 2;
		while ( true ) {
			$existing = $this->find_by_slug( $slug );
			if ( ! $existing || $existing->id === $exclude_id ) {
				return $slug;
			}
			$slug = $base . '-' . $suffix;
			++$suffix;
		}
	}

	/**
	 * Add contacts to a static list (ignores existing memberships).
	 *
	 * @param int   $list_id     List id.
	 * @param int[] $contact_ids Contact ids.
	 * @return int Rows inserted.
	 */
	public function add_contacts( int $list_id, array $contact_ids ): int {
		$contact_ids = array_values( array_unique( array_filter( array_map( 'intval', $contact_ids ) ) ) );
		if ( $list_id <= 0 || empty( $contact_ids ) ) {
			return 0;
		}

		$wpdb     = $this->db->wpdb();
		$now      = $this->db->now();
		$inserted = 0;

		foreach ( array_chunk( $contact_ids, 500 ) as $chunk ) {
			$values = array();
			$params = array();
			foreach ( $chunk as $contact_id ) {
				$values[] = '(%d, %d, %s)';
				array_push( $params, $contact_id, $list_id, $now );
			}
			$sql    = "INSERT IGNORE INTO {$this->db->table( 'contact_lists' )} (contact_id, list_id, added_at) VALUES " . implode( ', ', $values );
			$result = $wpdb->query( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( false !== $result ) {
				$inserted += (int) $result;
			}
		}

		return $inserted;
	}

	/**
	 * Remove contacts from a list.
	 *
	 * @param int   $list_id     List id.
	 * @param int[] $contact_ids Contact ids.
	 * @return int Rows deleted.
	 */
	public function remove_contacts( int $list_id, array $contact_ids ): int {
		$contact_ids = array_values( array_unique( array_filter( array_map( 'intval', $contact_ids ) ) ) );
		if ( $list_id <= 0 || empty( $contact_ids ) ) {
			return 0;
		}
		$wpdb         = $this->db->wpdb();
		$placeholders = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );
		$deleted      = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$this->db->table( 'contact_lists' )} WHERE list_id = %d AND contact_id IN ({$placeholders})", array_merge( array( $list_id ), $contact_ids ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Ids of static lists a contact belongs to.
	 *
	 * @param int $contact_id Contact id.
	 * @return int[]
	 */
	public function get_list_ids_for_contact( int $contact_id ): array {
		$wpdb = $this->db->wpdb();
		$ids  = $wpdb->get_col(
			$wpdb->prepare( "SELECT list_id FROM {$this->db->table( 'contact_lists' )} WHERE contact_id = %d", $contact_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Replace a contact's list memberships.
	 *
	 * @param int   $contact_id Contact id.
	 * @param int[] $list_ids   Desired list ids.
	 * @return array{added: int[], removed: int[]}
	 */
	public function set_contact_lists( int $contact_id, array $list_ids ): array {
		$list_ids = array_values( array_unique( array_filter( array_map( 'intval', $list_ids ) ) ) );
		$current  = $this->get_list_ids_for_contact( $contact_id );

		$added   = array_values( array_diff( $list_ids, $current ) );
		$removed = array_values( array_diff( $current, $list_ids ) );

		foreach ( $added as $list_id ) {
			$this->add_contacts( $list_id, array( $contact_id ) );
		}
		foreach ( $removed as $list_id ) {
			$this->remove_contacts( $list_id, array( $contact_id ) );
		}

		return array(
			'added'   => $added,
			'removed' => $removed,
		);
	}

	/**
	 * Static member count.
	 *
	 * @param int $list_id List id.
	 * @return int
	 */
	public function count_members( int $list_id ): int {
		$wpdb = $this->db->wpdb();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$this->db->table( 'contact_lists' )} WHERE list_id = %d", $list_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}
