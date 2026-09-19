<?php
/**
 * Personal-data erasure for one email address.
 *
 * Used by the WordPress privacy eraser and by the admin "Erase personal data"
 * action, so both paths behave identically.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Privacy;

use IBG\Outreach\Contacts\Contact_Repository;
use IBG\Outreach\Contacts\Contact_Service;
use IBG\Outreach\Events\Event_Repository;
use IBG\Outreach\Queue\Email_Log_Repository;
use IBG\Outreach\Queue\Queue_Repository;
use IBG\Outreach\Unsubscribe\Suppression_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Class Erasure_Service
 */
final class Erasure_Service {

	/**
	 * Constructor.
	 *
	 * @param Contact_Repository     $contacts     Contacts.
	 * @param Contact_Service        $service      Contact service.
	 * @param Event_Repository       $events       Events.
	 * @param Email_Log_Repository   $logs         Logs.
	 * @param Queue_Repository       $queue        Queue.
	 * @param Suppression_Repository $suppressions Suppressions.
	 */
	public function __construct(
		private readonly Contact_Repository $contacts,
		private readonly Contact_Service $service,
		private readonly Event_Repository $events,
		private readonly Email_Log_Repository $logs,
		private readonly Queue_Repository $queue,
		private readonly Suppression_Repository $suppressions
	) {}

	/**
	 * Erase everything held about an email address.
	 *
	 * @param string $email Raw email.
	 * @return array{contact_deleted:bool, events_deleted:int, logs_anonymized:int, queue_anonymized:int, suppression_retained:bool}
	 */
	public function erase( string $email ): array {
		$email   = Contact_Service::normalize_email( $email );
		$contact = '' !== $email ? $this->contacts->find_by_email( $email ) : null;
		$result  = array(
			'contact_deleted'      => false,
			'events_deleted'       => 0,
			'logs_anonymized'      => 0,
			'queue_anonymized'     => 0,
			'suppression_retained' => false,
		);

		if ( '' === $email ) {
			return $result;
		}

		$contact_id = $contact ? $contact->id : 0;

		if ( $contact ) {
			$result['queue_anonymized'] = $this->queue->anonymize_contact( $contact_id );
			$result['events_deleted']   = $this->events->delete_for_contacts( array( $contact_id ) );
			$result['contact_deleted']  = $this->service->delete( array( $contact_id ) ) > 0;
		}

		$result['logs_anonymized'] = $this->logs->anonymize( $contact_id, $email );

		// The suppression hash is kept deliberately: it is the only way to keep
		// honouring the opt-out if the address is imported again.
		$result['suppression_retained'] = $this->suppressions->anonymize( $email );

		/**
		 * Fires after personal data for an email address was erased.
		 *
		 * @param string $email  Normalised email.
		 * @param array  $result Summary.
		 */
		do_action( 'ibg_outreach_personal_data_erased', $email, $result );

		return $result;
	}
}
