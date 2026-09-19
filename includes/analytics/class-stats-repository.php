<?php
/**
 * Aggregate statistics for the dashboard, campaign screens and REST API.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Analytics;

use IBG\Outreach\Campaigns\Campaign;
use IBG\Outreach\Campaigns\Campaign_Repository;
use IBG\Outreach\Contacts\Contact;
use IBG\Outreach\Contacts\Contact_Repository;
use IBG\Outreach\Database;
use IBG\Outreach\Unsubscribe\Suppression_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Class Stats_Repository
 */
final class Stats_Repository {

	/**
	 * Constructor.
	 *
	 * @param Database               $db           Database.
	 * @param Contact_Repository     $contacts     Contacts.
	 * @param Campaign_Repository    $campaigns    Campaigns.
	 * @param Suppression_Repository $suppressions Suppressions.
	 */
	public function __construct(
		private readonly Database $db,
		private readonly Contact_Repository $contacts,
		private readonly Campaign_Repository $campaigns,
		private readonly Suppression_Repository $suppressions
	) {}

	/**
	 * Contact counts.
	 *
	 * @return array<string, int>
	 */
	public function contacts(): array {
		$by_status    = $this->contacts->count_by( 'contact_status' );
		$by_marketing = $this->contacts->count_by( 'marketing_status' );

		return array(
			'total'          => array_sum( $by_status ),
			'leads'          => $by_status[ Contact::STATUS_LEAD ] ?? 0,
			'contacts'       => $by_status[ Contact::STATUS_CONTACT ] ?? 0,
			'customers'      => $by_status[ Contact::STATUS_CUSTOMER ] ?? 0,
			'inactive'       => $by_status[ Contact::STATUS_INACTIVE ] ?? 0,
			'subscribed'     => $by_marketing[ Contact::MARKETING_SUBSCRIBED ] ?? 0,
			'pending'        => $by_marketing[ Contact::MARKETING_PENDING ] ?? 0,
			'unsubscribed'   => $by_marketing[ Contact::MARKETING_UNSUBSCRIBED ] ?? 0,
			'do_not_contact' => $by_marketing[ Contact::MARKETING_DNC ] ?? 0,
		);
	}

	/**
	 * Campaign counts.
	 *
	 * @return array<string, int>
	 */
	public function campaigns(): array {
		$counts          = $this->campaigns->count_by_status();
		$counts['total'] = array_sum( $counts );
		return $counts;
	}

	/**
	 * Lifetime email totals (campaign counters survive log retention).
	 *
	 * @return array<string, int>
	 */
	public function emails(): array {
		$wpdb = $this->db->wpdb();
		$row  = $wpdb->get_row(
			"SELECT COALESCE(SUM(total_sent),0) AS sent, COALESCE(SUM(total_failed),0) AS failed, COALESCE(SUM(total_skipped),0) AS skipped FROM {$this->db->table( 'campaigns' )}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$reasons = $this->suppressions->count_by_reason();

		return array(
			'sent'         => (int) ( $row['sent'] ?? 0 ),
			'failed'       => (int) ( $row['failed'] ?? 0 ),
			'skipped'      => (int) ( $row['skipped'] ?? 0 ),
			'unsubscribed' => $this->count_events( 'contact.unsubscribed' ),
			'bounced'      => $reasons[ Suppression_Repository::REASON_BOUNCED ] ?? 0,
			'complaints'   => $reasons[ Suppression_Repository::REASON_COMPLAINT ] ?? 0,
		);
	}

	/**
	 * Emails sent per day for the last N days (from logs; UTC dates).
	 *
	 * @param int $days Days.
	 * @return array<string, int> Y-m-d => count, oldest first, every day present.
	 */
	public function daily_sends( int $days = 14 ): array {
		$wpdb   = $this->db->wpdb();
		$days   = max( 1, $days );
		$cutoff = gmdate( 'Y-m-d 00:00:00', time() - ( $days - 1 ) * DAY_IN_SECONDS );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS day, COUNT(*) AS total FROM {$this->db->table( 'email_logs' )} WHERE status = %s AND created_at >= %s GROUP BY DATE(created_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'sent',
				$cutoff
			),
			ARRAY_A
		);

		$series = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$series[ gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) ] = 0;
		}
		foreach ( (array) $rows as $row ) {
			if ( isset( $series[ $row['day'] ] ) ) {
				$series[ $row['day'] ] = (int) $row['total'];
			}
		}
		return $series;
	}

	/**
	 * Engagement for one campaign from the events table.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array<string, int>
	 */
	public function campaign_engagement( int $campaign_id ): array {
		$wpdb = $this->db->wpdb();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_type, COUNT(*) AS total, COUNT(DISTINCT contact_id) AS contacts FROM {$this->db->table( 'events' )} WHERE campaign_id = %d GROUP BY event_type", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$campaign_id
			),
			ARRAY_A
		);

		$by_type = array();
		foreach ( (array) $rows as $row ) {
			$by_type[ (string) $row['event_type'] ] = array(
				'total'    => (int) $row['total'],
				'contacts' => (int) $row['contacts'],
			);
		}

		return array(
			'opened_unique'  => $by_type['email.opened']['contacts'] ?? 0,
			'opened_total'   => $by_type['email.opened']['total'] ?? 0,
			'clicked_unique' => $by_type['email.clicked']['contacts'] ?? 0,
			'clicked_total'  => $by_type['email.clicked']['total'] ?? 0,
			'unsubscribed'   => $by_type['contact.unsubscribed']['contacts'] ?? 0,
			'bounced'        => $by_type['email.bounced']['contacts'] ?? 0,
			'complained'     => $by_type['email.complained']['contacts'] ?? 0,
			'delivered'      => $by_type['email.delivered']['contacts'] ?? 0,
		);
	}

	/**
	 * Recent notable events with contact and campaign names.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, object>
	 */
	public function recent_activity( int $limit = 12 ): array {
		$wpdb      = $this->db->wpdb();
		$events    = $this->db->table( 'events' );
		$contacts  = $this->db->table( 'contacts' );
		$campaigns = $this->db->table( 'campaigns' );

		$types = array(
			'campaign.started',
			'campaign.completed',
			'campaign.cancelled',
			'campaign.scheduled',
			'campaign.start_failed',
			'contact.unsubscribed',
			'contact.resubscribed',
			'email.bounced',
			'email.complained',
			'import.completed',
		);
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.*, c.email AS contact_email, c.full_name AS contact_name, ca.name AS campaign_name
				FROM {$events} e
				LEFT JOIN {$contacts} c ON c.id = e.contact_id
				LEFT JOIN {$campaigns} ca ON ca.id = e.campaign_id
				WHERE e.event_type IN ({$placeholders})
				ORDER BY e.id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( $types, array( max( 1, $limit ) ) )
			)
		);

		foreach ( (array) $rows as $row ) {
			$decoded   = $row->event_data ? json_decode( (string) $row->event_data, true ) : array();
			$row->data = is_array( $decoded ) ? $decoded : array();
		}

		return (array) $rows;
	}

	/**
	 * Count events of a type.
	 *
	 * @param string $type Event type.
	 * @return int
	 */
	private function count_events( string $type ): int {
		$wpdb = $this->db->wpdb();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$this->db->table( 'events' )} WHERE event_type = %s", $type ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}
