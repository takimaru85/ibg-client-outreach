<?php
/**
 * Applies provider delivery events to contacts, suppressions and the event log.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Email;

use IBG\Outreach\Contacts\Contact;
use IBG\Outreach\Contacts\Contact_Repository;
use IBG\Outreach\Contacts\Contact_Service;
use IBG\Outreach\Database;
use IBG\Outreach\Events\Event_Repository;
use IBG\Outreach\Queue\Email_Log_Repository;
use IBG\Outreach\Settings;
use IBG\Outreach\Unsubscribe\Suppression_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Class Delivery_Event_Processor
 */
final class Delivery_Event_Processor {

	/**
	 * Constructor.
	 *
	 * @param Contact_Repository     $contacts     Contacts.
	 * @param Contact_Service        $service      Contact service.
	 * @param Suppression_Repository $suppressions Suppressions.
	 * @param Email_Log_Repository   $logs         Logs.
	 * @param Event_Repository       $events       Events.
	 * @param Settings               $settings     Settings.
	 * @param Database               $db           Database.
	 */
	public function __construct(
		private readonly Contact_Repository $contacts,
		private readonly Contact_Service $service,
		private readonly Suppression_Repository $suppressions,
		private readonly Email_Log_Repository $logs,
		private readonly Event_Repository $events,
		private readonly Settings $settings,
		private readonly Database $db
	) {}

	/**
	 * Process a batch of events.
	 *
	 * @param Delivery_Event[] $events      Events.
	 * @param string           $provider_id Provider id.
	 * @return array<string, int> Counts per outcome.
	 */
	public function process( array $events, string $provider_id ): array {
		$counts = array(
			'applied' => 0,
			'ignored' => 0,
			'unknown' => 0,
		);

		foreach ( $events as $event ) {
			if ( ! $event instanceof Delivery_Event || ! in_array( $event->type, Delivery_Event::types(), true ) ) {
				++$counts['ignored'];
				continue;
			}
			$result = $this->apply( $event, $provider_id );
			++$counts[ $result ];
		}

		return $counts;
	}

	/**
	 * Apply one event.
	 *
	 * @param Delivery_Event $event       Event.
	 * @param string         $provider_id Provider id.
	 * @return string applied|ignored|unknown
	 */
	private function apply( Delivery_Event $event, string $provider_id ): string {
		$log     = '' !== $event->message_id ? $this->logs->find_by_message_id( $event->message_id ) : null;
		$email   = Contact_Service::normalize_email( $event->email );
		$contact = $log && (int) $log->contact_id > 0 ? $this->contacts->find( (int) $log->contact_id ) : null;
		if ( ! $contact && '' !== $email ) {
			$contact = $this->contacts->find_by_email( $email );
		}
		if ( ! $contact ) {
			return 'unknown';
		}

		$campaign_id = $log ? (int) $log->campaign_id : 0;
		$queue_id    = $log ? (int) $log->queue_id : 0;
		$data        = array_merge( $event->data, array( 'provider' => $provider_id, 'message_id' => $event->message_id ) );
		$now         = $this->db->now();

		switch ( $event->type ) {
			case Delivery_Event::BOUNCED:
				$this->contacts->update_many( array( $contact->id ), array( 'email_status' => Contact::EMAIL_BOUNCED ) );
				$this->suppressions->add( $contact->email, Suppression_Repository::REASON_BOUNCED, Suppression_Repository::SOURCE_SYSTEM, $contact->id, $campaign_id ?: null );
				$this->events->log( 'email.bounced', $contact->id, $data, $campaign_id, $queue_id );
				break;

			case Delivery_Event::SOFT_BOUNCED:
				$this->events->log( 'email.soft_bounced', $contact->id, $data, $campaign_id, $queue_id );
				break;

			case Delivery_Event::COMPLAINED:
				$this->service->update(
					$contact->id,
					array( 'marketing_status' => Contact::MARKETING_UNSUBSCRIBED ),
					array(
						'source'      => Contact_Service::SOURCE_API,
						'campaign_id' => $campaign_id,
					)
				);
				$this->suppressions->add( $contact->email, Suppression_Repository::REASON_COMPLAINT, Suppression_Repository::SOURCE_SYSTEM, $contact->id, $campaign_id ?: null );
				$this->events->log( 'email.complained', $contact->id, $data, $campaign_id, $queue_id );
				break;

			case Delivery_Event::DELIVERED:
				$this->events->log( 'email.delivered', $contact->id, $data, $campaign_id, $queue_id );
				break;

			case Delivery_Event::OPENED:
				if ( ! $this->settings->get( 'track_opens', false ) ) {
					return 'ignored';
				}
				$this->contacts->update_many( array( $contact->id ), array( 'last_opened_at' => $now ) );
				$this->events->log( 'email.opened', $contact->id, $data, $campaign_id, $queue_id );
				break;

			case Delivery_Event::CLICKED:
				if ( ! $this->settings->get( 'track_clicks', false ) ) {
					return 'ignored';
				}
				$this->contacts->update_many( array( $contact->id ), array( 'last_clicked_at' => $now ) );
				$this->events->log( 'email.clicked', $contact->id, $data, $campaign_id, $queue_id );
				break;
		}

		/**
		 * Fires after a delivery event has been applied.
		 *
		 * @param Delivery_Event $event       Event.
		 * @param Contact        $contact     Contact.
		 * @param int            $campaign_id Campaign id or 0.
		 * @param string         $provider_id Provider id.
		 */
		do_action( 'ibg_outreach_delivery_event', $event, $contact, $campaign_id, $provider_id );

		return 'applied';
	}
}
