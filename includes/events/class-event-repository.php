<?php
/**
 * Event log repository (ibg_events).
 *
 * Records audit events (status changes, resubscribes) now and engagement
 * events (opens, clicks, bounces, replies) in later phases.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Events;

use IBG\Outreach\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Class Event_Repository
 */
final class Event_Repository {

	public const CONTACT_CREATED          = 'contact.created';
	public const CONTACT_UPDATED          = 'contact.updated';
	public const CONTACT_DELETED          = 'contact.deleted';
	public const CONTACT_STATUS_CHANGED   = 'contact.status_changed';
	public const MARKETING_STATUS_CHANGED = 'contact.marketing_status_changed';
	public const SUPPRESSION_PRESERVED    = 'contact.suppression_preserved';
	public const CONTACT_RESUBSCRIBED     = 'contact.resubscribed';

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
	 * Record an event.
	 *
	 * @param string               $type        Event type (dot-namespaced, max 30 chars).
	 * @param int                  $contact_id  Contact id.
	 * @param array<string, mixed> $data        Extra data (stored as JSON). The acting user id is added automatically.
	 * @param int                  $campaign_id Campaign id.
	 * @param int                  $queue_id    Queue row id.
	 * @return int Event id, or 0 on failure.
	 */
	public function log( string $type, int $contact_id = 0, array $data = array(), int $campaign_id = 0, int $queue_id = 0 ): int {
		$wpdb = $this->db->wpdb();

		if ( ! isset( $data['user_id'] ) && function_exists( 'get_current_user_id' ) ) {
			$data['user_id'] = get_current_user_id();
		}

		$result = $wpdb->insert(
			$this->db->table( 'events' ),
			array(
				'event_type'  => substr( $type, 0, 30 ),
				'campaign_id' => $campaign_id,
				'contact_id'  => $contact_id,
				'queue_id'    => $queue_id,
				'event_data'  => empty( $data ) ? null : wp_json_encode( $data ),
				'created_at'  => $this->db->now(),
			),
			array( '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		return false === $result ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Recent events for a contact, newest first.
	 *
	 * @param int $contact_id Contact id.
	 * @param int $limit      Max rows.
	 * @return array<int, object> Rows with event_data decoded into ->data (array).
	 */
	public function get_for_contact( int $contact_id, int $limit = 25 ): array {
		$wpdb = $this->db->wpdb();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->db->table( 'events' )} WHERE contact_id = %d ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$contact_id,
				max( 1, $limit )
			)
		);

		foreach ( (array) $rows as $row ) {
			$decoded   = $row->event_data ? json_decode( (string) $row->event_data, true ) : array();
			$row->data = is_array( $decoded ) ? $decoded : array();
		}

		return (array) $rows;
	}

	/**
	 * Delete events for contacts (used on contact deletion / erasure).
	 *
	 * @param int[] $contact_ids Contact ids.
	 * @return int Rows deleted.
	 */
	public function delete_for_contacts( array $contact_ids ): int {
		$ids = array_values( array_filter( array_map( 'intval', $contact_ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$wpdb         = $this->db->wpdb();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$deleted      = $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->db->table( 'events' )} WHERE contact_id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return false === $deleted ? 0 : (int) $deleted;
	}
}
