<?php
/**
 * Template repository (ibg_templates).
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Templates;

use IBG\Outreach\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Class Template_Repository
 */
final class Template_Repository {

	/**
	 * Sortable columns.
	 *
	 * @var string[]
	 */
	private const SORTABLE = array( 'id', 'name', 'subject', 'is_active', 'created_at', 'updated_at' );

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
	 * @param int $id Template id.
	 * @return Email_Template|null
	 */
	public function find( int $id ): ?Email_Template {
		$wpdb = $this->db->wpdb();
		$row  = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->db->table( 'templates' )} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return $row ? Email_Template::from_row( $row ) : null;
	}

	/**
	 * Active templates for dropdowns: id => name.
	 *
	 * @param bool $active_only Only active templates.
	 * @return array<int, string>
	 */
	public function get_options( bool $active_only = true ): array {
		$wpdb = $this->db->wpdb();
		$sql  = "SELECT id, name FROM {$this->db->table( 'templates' )}" . ( $active_only ? ' WHERE is_active = 1' : '' ) . ' ORDER BY name ASC';
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$options = array();
		foreach ( (array) $rows as $row ) {
			$options[ (int) $row['id'] ] = (string) $row['name'];
		}
		return $options;
	}

	/**
	 * Query with search, status filter, sorting and pagination.
	 *
	 * @param array<string, mixed> $args search, is_active (''|0|1), orderby, order, per_page, page.
	 * @return array{items: Email_Template[], total: int}
	 */
	public function query( array $args = array() ): array {
		$args = wp_parse_args(
			$args,
			array(
				'search'    => '',
				'is_active' => '',
				'orderby'   => 'updated_at',
				'order'     => 'DESC',
				'per_page'  => 20,
				'page'      => 1,
			)
		);

		$wpdb      = $this->db->wpdb();
		$templates = $this->db->table( 'templates' );
		$campaigns = $this->db->table( 'campaigns' );

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(t.name LIKE %s OR t.subject LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		if ( '' !== (string) $args['is_active'] ) {
			$where[]  = 't.is_active = %d';
			$params[] = (int) $args['is_active'];
		}

		$where_sql = implode( ' AND ', $where );
		$orderby   = in_array( $args['orderby'], self::SORTABLE, true ) ? $args['orderby'] : 'updated_at';
		$order     = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$per_page  = max( 1, (int) $args['per_page'] );
		$offset    = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$count_sql = "SELECT COUNT(*) FROM {$templates} t WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( empty( $params ) ? $count_sql : $wpdb->prepare( $count_sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$sql  = "SELECT t.*, (SELECT COUNT(*) FROM {$campaigns} c WHERE c.template_id = t.id) AS campaign_count
			FROM {$templates} t WHERE {$where_sql} ORDER BY t.{$orderby} {$order}, t.id {$order} LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = Email_Template::from_row( $row );
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Counts by active state.
	 *
	 * @return array{all:int, active:int, inactive:int}
	 */
	public function counts(): array {
		$wpdb = $this->db->wpdb();
		$row  = $wpdb->get_row(
			"SELECT COUNT(*) AS total, SUM(is_active = 1) AS active FROM {$this->db->table( 'templates' )}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$total  = (int) ( $row['total'] ?? 0 );
		$active = (int) ( $row['active'] ?? 0 );
		return array(
			'all'      => $total,
			'active'   => $active,
			'inactive' => $total - $active,
		);
	}

	/**
	 * Number of campaigns referencing a template.
	 *
	 * @param int $template_id Template id.
	 * @return int
	 */
	public function count_campaigns_using( int $template_id ): int {
		$wpdb = $this->db->wpdb();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$this->db->table( 'campaigns' )} WHERE template_id = %d", $template_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Insert.
	 *
	 * @param Email_Template $template Template.
	 * @return int New id or 0.
	 */
	public function insert( Email_Template $template ): int {
		$wpdb                 = $this->db->wpdb();
		$template->created_at = $this->db->now();
		$template->updated_at = $template->created_at;

		if ( false === $wpdb->insert( $this->db->table( 'templates' ), $template->to_row(), Email_Template::row_formats() ) ) {
			return 0;
		}
		$template->id = (int) $wpdb->insert_id;
		return $template->id;
	}

	/**
	 * Update.
	 *
	 * @param Email_Template $template Template.
	 * @return bool
	 */
	public function update( Email_Template $template ): bool {
		if ( $template->id <= 0 ) {
			return false;
		}
		$wpdb                 = $this->db->wpdb();
		$template->updated_at = $this->db->now();

		return false !== $wpdb->update(
			$this->db->table( 'templates' ),
			$template->to_row(),
			array( 'id' => $template->id ),
			Email_Template::row_formats(),
			array( '%d' )
		);
	}

	/**
	 * Set is_active for several templates.
	 *
	 * @param int[] $ids    Template ids.
	 * @param bool  $active State.
	 * @return int Rows affected.
	 */
	public function set_active( array $ids, bool $active ): int {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$wpdb         = $this->db->wpdb();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$result       = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->db->table( 'templates' )} SET is_active = %d, updated_at = %s WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $active ? 1 : 0, $this->db->now() ), $ids )
			)
		);
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Delete templates.
	 *
	 * @param int[] $ids Template ids.
	 * @return int
	 */
	public function delete_many( array $ids ): int {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$wpdb         = $this->db->wpdb();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$result       = $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->db->table( 'templates' )} WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return false === $result ? 0 : (int) $result;
	}
}
