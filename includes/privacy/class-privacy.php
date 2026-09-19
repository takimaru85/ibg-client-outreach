<?php
/**
 * WordPress Privacy API integration: exporter, eraser, policy text.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Privacy;

use IBG\Outreach\Contacts\Contact;
use IBG\Outreach\Contacts\Contact_Repository;
use IBG\Outreach\Contacts\Contact_Service;
use IBG\Outreach\Events\Event_Repository;
use IBG\Outreach\Formatting;
use IBG\Outreach\Lists\List_Repository;
use IBG\Outreach\Queue\Email_Log_Repository;
use IBG\Outreach\Queue\Queue_Repository;
use IBG\Outreach\Unsubscribe\Suppression_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Class Privacy
 */
final class Privacy {

	/**
	 * Constructor.
	 *
	 * @param Contact_Repository     $contacts     Contacts.
	 * @param List_Repository        $lists        Lists.
	 * @param Event_Repository       $events       Events.
	 * @param Email_Log_Repository   $logs         Logs.
	 * @param Queue_Repository       $queue        Queue.
	 * @param Suppression_Repository $suppressions Suppressions.
	 * @param Erasure_Service        $erasure      Erasure service.
	 */
	public function __construct(
		private readonly Contact_Repository $contacts,
		private readonly List_Repository $lists,
		private readonly Event_Repository $events,
		private readonly Email_Log_Repository $logs,
		private readonly Queue_Repository $queue,
		private readonly Suppression_Repository $suppressions,
		private readonly Erasure_Service $erasure
	) {}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['ibg-client-outreach'] = array(
			'exporter_friendly_name' => __( 'IBG Client Outreach', 'ibg-client-outreach' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['ibg-client-outreach'] = array(
			'eraser_friendly_name' => __( 'IBG Client Outreach', 'ibg-client-outreach' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exporter callback.
	 *
	 * @param string $email_address Email.
	 * @param int    $page          Page (unused; everything fits in one).
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$email   = Contact_Service::normalize_email( $email_address );
		$contact = '' !== $email ? $this->contacts->find_by_email( $email ) : null;
		$data    = array();

		if ( $contact ) {
			$data[] = array(
				'group_id'          => 'ibg_contact',
				'group_label'       => __( 'Outreach contact', 'ibg-client-outreach' ),
				'group_description' => __( 'Business contact record held for email outreach.', 'ibg-client-outreach' ),
				'item_id'           => 'ibg-contact-' . $contact->id,
				'data'              => $this->contact_fields( $contact ),
			);

			foreach ( $this->events->get_all_for_contact( $contact->id ) as $event ) {
				$data[] = array(
					'group_id'          => 'ibg_events',
					'group_label'       => __( 'Outreach activity', 'ibg-client-outreach' ),
					'group_description' => __( 'Status changes, unsubscribes and engagement recorded for the contact.', 'ibg-client-outreach' ),
					'item_id'           => 'ibg-event-' . $event->id,
					'data'              => array(
						array( 'name' => __( 'Event', 'ibg-client-outreach' ), 'value' => $event->event_type ),
						array( 'name' => __( 'Date', 'ibg-client-outreach' ), 'value' => Formatting::datetime( (string) $event->created_at ) ),
						array( 'name' => __( 'Details', 'ibg-client-outreach' ), 'value' => wp_json_encode( array_diff_key( $event->data, array( 'user_id' => 1 ) ) ) ),
					),
				);
			}

			foreach ( $this->queue->query( array( 'campaign_id' => 0, 'search' => $contact->email, 'per_page' => 1000 ) )['items'] as $item ) {
				if ( $item->contact_id !== $contact->id ) {
					continue;
				}
				$data[] = array(
					'group_id'          => 'ibg_queue',
					'group_label'       => __( 'Outreach email queue', 'ibg-client-outreach' ),
					'group_description' => __( 'Campaign emails queued for the contact.', 'ibg-client-outreach' ),
					'item_id'           => 'ibg-queue-' . $item->id,
					'data'              => array(
						array( 'name' => __( 'Campaign ID', 'ibg-client-outreach' ), 'value' => $item->campaign_id ),
						array( 'name' => __( 'Status', 'ibg-client-outreach' ), 'value' => $item->status ),
						array( 'name' => __( 'Queued', 'ibg-client-outreach' ), 'value' => Formatting::datetime( $item->created_at ) ),
						array( 'name' => __( 'Sent', 'ibg-client-outreach' ), 'value' => Formatting::datetime( $item->sent_at ) ),
					),
				);
			}
		}

		if ( '' !== $email ) {
			foreach ( $this->logs->query( array( 'search' => $email, 'per_page' => 1000 ) )['items'] as $log ) {
				if ( (string) $log->email !== $email ) {
					continue;
				}
				$data[] = array(
					'group_id'          => 'ibg_logs',
					'group_label'       => __( 'Outreach email log', 'ibg-client-outreach' ),
					'group_description' => __( 'Emails sent or attempted to this address. Message bodies are not stored.', 'ibg-client-outreach' ),
					'item_id'           => 'ibg-log-' . $log->id,
					'data'              => array(
						array( 'name' => __( 'Subject', 'ibg-client-outreach' ), 'value' => (string) $log->subject ),
						array( 'name' => __( 'Status', 'ibg-client-outreach' ), 'value' => (string) $log->status ),
						array( 'name' => __( 'Date', 'ibg-client-outreach' ), 'value' => Formatting::datetime( (string) $log->created_at ) ),
					),
				);
			}

			$suppression = $this->suppressions->find( $email );
			if ( $suppression ) {
				$data[] = array(
					'group_id'          => 'ibg_suppression',
					'group_label'       => __( 'Outreach suppression', 'ibg-client-outreach' ),
					'group_description' => __( 'Record that this address must not receive marketing email.', 'ibg-client-outreach' ),
					'item_id'           => 'ibg-suppression-' . $suppression->id,
					'data'              => array(
						array( 'name' => __( 'Reason', 'ibg-client-outreach' ), 'value' => (string) $suppression->reason ),
						array( 'name' => __( 'Since', 'ibg-client-outreach' ), 'value' => Formatting::datetime( (string) $suppression->created_at ) ),
					),
				);
			}
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Eraser callback.
	 *
	 * @param string $email_address Email.
	 * @param int    $page          Page (unused).
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$result   = $this->erasure->erase( $email_address );
		$removed  = $result['contact_deleted'] || $result['events_deleted'] > 0 || $result['logs_anonymized'] > 0 || $result['queue_anonymized'] > 0;
		$messages = array();

		if ( $result['suppression_retained'] ) {
			$messages[] = __( 'IBG Client Outreach: the address remains on the suppression list as an irreversible hash only, so the request not to be emailed continues to be honoured.', 'ibg-client-outreach' );
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $result['suppression_retained'],
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Suggested privacy policy text (Settings → Privacy → Policy Guide).
	 *
	 * @return void
	 */
	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p class="privacy-policy-tutorial">' . esc_html__( 'Adapt this to your situation. Mention it only if you use the plugin to email people.', 'ibg-client-outreach' ) . '</p>'
			. '<h3>' . esc_html__( 'Business contacts and email outreach', 'ibg-client-outreach' ) . '</h3>'
			. '<p>' . esc_html__( 'We keep a record of business contacts (name, company, email address, website, phone number, country, industry and where we obtained the contact) in order to send occasional information about our services. We record whether and when you consented or unsubscribed, and which of our emails were sent to you. We do not store the content of sent emails.', 'ibg-client-outreach' ) . '</p>'
			. '<p>' . esc_html__( 'Every marketing email contains an unsubscribe link. Unsubscribing takes effect immediately; we keep an irreversible hash of unsubscribed addresses so we never email them again, even if the address is later imported from another source.', 'ibg-client-outreach' ) . '</p>'
			. '<p>' . esc_html__( 'If enabled, our emails may contain a tracking image and tracked links that record when an email is opened or a link is clicked. We do not record IP addresses or device information for these events.', 'ibg-client-outreach' ) . '</p>'
			. '<p>' . esc_html__( 'You can request a copy of the data we hold about you, or its deletion, by contacting us at the address below.', 'ibg-client-outreach' ) . '</p>';

		wp_add_privacy_policy_content( __( 'IBG Client Outreach', 'ibg-client-outreach' ), $content );
	}

	/**
	 * Contact fields for the export.
	 *
	 * @param Contact $contact Contact.
	 * @return array<int, array{name:string, value:string}>
	 */
	private function contact_fields( Contact $contact ): array {
		$fields = array(
			__( 'Email', 'ibg-client-outreach' )            => $contact->email,
			__( 'First name', 'ibg-client-outreach' )       => $contact->first_name,
			__( 'Last name', 'ibg-client-outreach' )        => $contact->last_name,
			__( 'Full name', 'ibg-client-outreach' )        => $contact->full_name,
			__( 'Company', 'ibg-client-outreach' )          => $contact->company,
			__( 'Website', 'ibg-client-outreach' )          => $contact->website,
			__( 'Phone', 'ibg-client-outreach' )            => $contact->phone,
			__( 'Country', 'ibg-client-outreach' )          => $contact->country,
			__( 'Industry', 'ibg-client-outreach' )         => $contact->industry,
			__( 'Source', 'ibg-client-outreach' )           => $contact->source,
			__( 'Notes', 'ibg-client-outreach' )            => $contact->notes,
			__( 'Contact status', 'ibg-client-outreach' )   => Contact::label( Contact::contact_statuses(), $contact->contact_status ),
			__( 'Marketing status', 'ibg-client-outreach' ) => Contact::label( Contact::marketing_statuses(), $contact->marketing_status ),
			__( 'Lawful basis', 'ibg-client-outreach' )     => Contact::label( Contact::consent_bases(), $contact->consent_basis ),
			__( 'Consent date', 'ibg-client-outreach' )     => Formatting::datetime( $contact->consent_at ),
			__( 'Unsubscribed', 'ibg-client-outreach' )     => Formatting::datetime( $contact->unsubscribed_at ),
			__( 'Last contacted', 'ibg-client-outreach' )   => Formatting::datetime( $contact->last_contacted_at ),
			__( 'Added', 'ibg-client-outreach' )            => Formatting::datetime( $contact->created_at ),
		);

		$list_ids = $this->lists->get_list_ids_for_contact( $contact->id );
		if ( ! empty( $list_ids ) ) {
			$names = array();
			foreach ( $this->lists->all() as $list ) {
				if ( in_array( $list->id, $list_ids, true ) ) {
					$names[] = $list->name;
				}
			}
			$fields[ __( 'Lists', 'ibg-client-outreach' ) ] = implode( ', ', $names );
		}

		$out = array();
		foreach ( $fields as $name => $value ) {
			if ( '' !== (string) $value && '—' !== $value ) {
				$out[] = array(
					'name'  => $name,
					'value' => (string) $value,
				);
			}
		}
		return $out;
	}
}
