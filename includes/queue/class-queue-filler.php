<?php
/**
 * Fills the queue when a campaign starts; reacts to cancel/delete.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Queue;

use IBG\Outreach\Campaigns\Audience_Resolver;
use IBG\Outreach\Campaigns\Campaign;
use IBG\Outreach\Campaigns\Campaign_Repository;
use IBG\Outreach\Campaigns\Campaign_Service;
use IBG\Outreach\Contacts\Contact_Repository;
use IBG\Outreach\Database;
use IBG\Outreach\Events\Event_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Class Queue_Filler
 */
final class Queue_Filler {

	private const PAGE = 1000;

	/**
	 * Constructor.
	 *
	 * @param Queue_Repository    $queue            Queue.
	 * @param Audience_Resolver   $audience         Audience resolver.
	 * @param Contact_Repository  $contacts         Contacts.
	 * @param Campaign_Repository $campaigns        Campaigns.
	 * @param Campaign_Service    $campaign_service Campaign service.
	 * @param Event_Repository    $events           Events.
	 * @param Database            $db               Database.
	 */
	public function __construct(
		private readonly Queue_Repository $queue,
		private readonly Audience_Resolver $audience,
		private readonly Contact_Repository $contacts,
		private readonly Campaign_Repository $campaigns,
		private readonly Campaign_Service $campaign_service,
		private readonly Event_Repository $events,
		private readonly Database $db
	) {}

	/**
	 * Hook into the campaign lifecycle.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( Campaign_Service::HOOK_STARTED, array( $this, 'fill' ), 10, 1 );
		add_action( Campaign_Service::HOOK_CANCELLED, array( $this, 'on_cancelled' ), 10, 1 );
		add_action( Campaign_Service::HOOK_DELETED, array( $this, 'on_deleted' ), 10, 1 );
	}

	/**
	 * Insert a pending row for every eligible recipient.
	 *
	 * @param Campaign $campaign Started campaign.
	 * @return int Rows inserted.
	 */
	public function fill( Campaign $campaign ): int {
		$after = 0;
		$total = 0;
		$now   = $this->db->now();

		while ( true ) {
			$ids = $this->audience->get_recipient_ids( $campaign, $after, self::PAGE );
			if ( is_wp_error( $ids ) || empty( $ids ) ) {
				break;
			}

			$map = array();
			foreach ( $this->contacts->find_many( $ids ) as $contact ) {
				$map[ $contact->id ] = $contact->email;
			}

			$total += $this->queue->insert_pending( $campaign->id, $map, $now );
			$after  = max( $ids );

			if ( count( $ids ) < self::PAGE ) {
				break;
			}
		}

		$this->events->log( 'queue.filled', 0, array( 'rows' => $total ), $campaign->id );

		if ( 0 === $total ) {
			// Nothing to send (audience changed between pre-flight and start).
			$fresh = $this->campaigns->find( $campaign->id );
			if ( $fresh ) {
				$this->campaign_service->complete( $fresh );
			}
		}

		return $total;
	}

	/**
	 * Skip unsent rows of a cancelled campaign.
	 *
	 * @param Campaign $campaign Campaign.
	 * @return void
	 */
	public function on_cancelled( Campaign $campaign ): void {
		$skipped = $this->queue->skip_pending_for_campaign( $campaign->id, __( 'Campaign cancelled', 'ibg-client-outreach' ) );
		if ( $skipped > 0 ) {
			$this->campaigns->increment( $campaign->id, 'total_skipped', $skipped );
		}
	}

	/**
	 * Remove queue rows of a deleted campaign (logs are kept).
	 *
	 * @param int $campaign_id Campaign id.
	 * @return void
	 */
	public function on_deleted( int $campaign_id ): void {
		$this->queue->delete_for_campaign( $campaign_id );
	}
}
