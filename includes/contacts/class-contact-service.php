<?php
/**
 * Contact service: the single write path for contacts.
 *
 * Responsibilities:
 *  - sanitise and validate input from any source (admin form, importer, REST, integrations)
 *  - normalise emails so the UNIQUE index is meaningful
 *  - enforce the suppression policy (an unsubscribed / do-not-contact address is
 *    never upgraded by an import or an unconfirmed edit)
 *  - keep the suppression list in sync and write audit events
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Contacts;

use IBG\Outreach\Database;
use IBG\Outreach\Events\Event_Repository;
use IBG\Outreach\Formatting;
use IBG\Outreach\Unsubscribe\Suppression_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Class Contact_Service
 */
final class Contact_Service {

	public const SOURCE_ADMIN  = 'admin';
	public const SOURCE_IMPORT = 'import';
	public const SOURCE_API    = 'api';
	public const SOURCE_PUBLIC = 'public';

	/**
	 * Fields accepted from input.
	 *
	 * @var string[]
	 */
	private const WRITABLE = array(
		'email',
		'first_name',
		'last_name',
		'full_name',
		'company',
		'website',
		'phone',
		'country',
		'industry',
		'source',
		'notes',
		'contact_status',
		'marketing_status',
		'email_status',
		'consent_basis',
		'consent_at',
	);

	/**
	 * Human-readable notices from the last operation (e.g. "status preserved").
	 *
	 * @var string[]
	 */
	private array $notices = array();

	/**
	 * Events produced by the policy step, flushed once the contact id is known.
	 *
	 * @var array<int, array{type:string, data:array<string, mixed>}>
	 */
	private array $pending_events = array();

	/**
	 * Constructor.
	 *
	 * @param Contact_Repository     $contacts     Contact repository.
	 * @param Suppression_Repository $suppressions Suppression repository.
	 * @param Event_Repository       $events       Event repository.
	 * @param Database               $db           Database helper.
	 */
	public function __construct(
		private readonly Contact_Repository $contacts,
		private readonly Suppression_Repository $suppressions,
		private readonly Event_Repository $events,
		private readonly Database $db
	) {}

	/**
	 * Normalise an email for storage and comparison.
	 *
	 * @param string $email Raw email.
	 * @return string
	 */
	public static function normalize_email( string $email ): string {
		return strtolower( trim( sanitize_email( trim( $email ) ) ) );
	}

	/**
	 * Notices produced by the last create/update call (and clear them).
	 *
	 * @return string[]
	 */
	public function take_notices(): array {
		$notices       = $this->notices;
		$this->notices = array();
		return $notices;
	}

	/**
	 * Sanitise input. Only keys present in $data are returned, so partial
	 * updates are possible.
	 *
	 * @param array<string, mixed> $data Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $data ): array {
		$clean = array();

		foreach ( self::WRITABLE as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}
			$value = $data[ $field ];

			switch ( $field ) {
				case 'email':
					$clean[ $field ] = self::normalize_email( (string) $value );
					break;

				case 'website':
					$url = trim( (string) $value );
					if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
						$url = 'https://' . $url;
					}
					$clean[ $field ] = mb_substr( esc_url_raw( $url ), 0, Contact::MAX_LENGTHS['website'] );
					break;

				case 'notes':
					$clean[ $field ] = sanitize_textarea_field( (string) $value );
					break;

				case 'contact_status':
				case 'marketing_status':
				case 'email_status':
				case 'consent_basis':
					$clean[ $field ] = sanitize_key( (string) $value );
					break;

				case 'consent_at':
					$clean[ $field ] = ( null === $value || '' === $value ) ? null : Formatting::local_to_utc( (string) $value );
					break;

				default:
					$text            = sanitize_text_field( (string) $value );
					$clean[ $field ] = isset( Contact::MAX_LENGTHS[ $field ] ) ? mb_substr( $text, 0, Contact::MAX_LENGTHS[ $field ] ) : $text;
			}
		}

		return $clean;
	}

	/**
	 * Validate sanitised data.
	 *
	 * @param array<string, mixed> $clean    Sanitised data.
	 * @param Contact|null         $existing Contact being updated, if any.
	 * @return \WP_Error Empty when valid.
	 */
	public function validate( array $clean, ?Contact $existing = null ): \WP_Error {
		$errors = new \WP_Error();

		if ( array_key_exists( 'email', $clean ) || null === $existing ) {
			$email = (string) ( $clean['email'] ?? '' );

			if ( '' === $email ) {
				$errors->add( 'email_required', __( 'An email address is required.', 'ibg-client-outreach' ) );
			} elseif ( ! is_email( $email ) ) {
				$errors->add( 'email_invalid', __( 'The email address is not valid.', 'ibg-client-outreach' ) );
			} else {
				$duplicate = $this->contacts->find_by_email( $email );
				if ( $duplicate && ( null === $existing || $duplicate->id !== $existing->id ) ) {
					$errors->add(
						'email_duplicate',
						__( 'A contact with this email address already exists.', 'ibg-client-outreach' ),
						array( 'contact_id' => $duplicate->id )
					);
				}
			}
		}

		$maps = array(
			'contact_status'   => Contact::contact_statuses(),
			'marketing_status' => Contact::marketing_statuses(),
			'email_status'     => Contact::email_statuses(),
			'consent_basis'    => Contact::consent_bases(),
		);
		foreach ( $maps as $field => $map ) {
			if ( array_key_exists( $field, $clean ) && ! array_key_exists( (string) $clean[ $field ], $map ) ) {
				$errors->add(
					$field . '_invalid',
					sprintf(
						/* translators: %s: field name */
						__( 'Invalid value for %s.', 'ibg-client-outreach' ),
						str_replace( '_', ' ', $field )
					)
				);
			}
		}

		return $errors;
	}

	/**
	 * Create a contact.
	 *
	 * Options:
	 *   source              admin|import|api|public (default admin)
	 *   confirm_resubscribe bool – admin explicitly confirmed lifting a suppression
	 *
	 * @param array<string, mixed> $data    Raw input.
	 * @param array<string, mixed> $options Options.
	 * @return Contact|\WP_Error
	 */
	public function create( array $data, array $options = array() ): Contact|\WP_Error {
		$this->notices        = array();
		$this->pending_events = array();

		$clean  = $this->sanitize( $data );
		$errors = $this->validate( $clean );
		if ( $errors->has_errors() ) {
			return $errors;
		}

		$contact = Contact::from_row( $clean );
		$this->derive_full_name( $contact, null );
		$this->apply_marketing_policy( $contact, null, $options );

		$id = $this->contacts->insert( $contact );
		if ( ! $id ) {
			$this->pending_events = array();
			return new \WP_Error( 'db_error', __( 'The contact could not be saved.', 'ibg-client-outreach' ) );
		}

		if ( $contact->is_suppressed() ) {
			// Link the suppression row (written before the id existed) to the new contact.
			$this->suppressions->add( $contact->email, $this->reason_for( $contact->marketing_status ), (string) ( $options['source'] ?? self::SOURCE_ADMIN ), $id );
		}

		$this->flush_events( $id );
		$this->events->log(
			Event_Repository::CONTACT_CREATED,
			$id,
			array(
				'source'           => (string) ( $options['source'] ?? self::SOURCE_ADMIN ),
				'marketing_status' => $contact->marketing_status,
			)
		);

		/**
		 * Fires after a contact is created.
		 *
		 * @param Contact $contact Contact.
		 * @param array   $options Options passed to create().
		 */
		do_action( 'ibg_outreach_contact_created', $contact, $options );

		return $contact;
	}

	/**
	 * Update a contact. Only keys present in $data are changed.
	 *
	 * @param int                  $id      Contact id.
	 * @param array<string, mixed> $data    Raw input (partial allowed).
	 * @param array<string, mixed> $options See create().
	 * @return Contact|\WP_Error
	 */
	public function update( int $id, array $data, array $options = array() ): Contact|\WP_Error {
		$this->notices        = array();
		$this->pending_events = array();

		$existing = $this->contacts->find( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'not_found', __( 'Contact not found.', 'ibg-client-outreach' ) );
		}

		$clean  = $this->sanitize( $data );
		$errors = $this->validate( $clean, $existing );
		if ( $errors->has_errors() ) {
			return $errors;
		}

		$contact = clone $existing;
		foreach ( $clean as $field => $value ) {
			$contact->{$field} = $value;
		}

		$this->derive_full_name( $contact, $existing );
		$this->apply_marketing_policy( $contact, $existing, $options );

		if ( ! $this->contacts->update( $contact ) ) {
			$this->pending_events = array();
			return new \WP_Error( 'db_error', __( 'The contact could not be saved.', 'ibg-client-outreach' ) );
		}

		$this->flush_events( $id );

		$changed = array();
		foreach ( $contact->to_row() as $field => $value ) {
			if ( 'updated_at' !== $field && $existing->{$field} !== $value ) {
				$changed[] = $field;
			}
		}

		if ( ! empty( $changed ) ) {
			$this->events->log(
				Event_Repository::CONTACT_UPDATED,
				$id,
				array(
					'source' => (string) ( $options['source'] ?? self::SOURCE_ADMIN ),
					'fields' => $changed,
				)
			);
		}

		if ( $existing->contact_status !== $contact->contact_status ) {
			$this->events->log(
				Event_Repository::CONTACT_STATUS_CHANGED,
				$id,
				array(
					'from' => $existing->contact_status,
					'to'   => $contact->contact_status,
				)
			);
		}

		/**
		 * Fires after a contact is updated.
		 *
		 * @param Contact $contact  Updated contact.
		 * @param Contact $existing Contact before the update.
		 * @param array   $options  Options passed to update().
		 */
		do_action( 'ibg_outreach_contact_updated', $contact, $existing, $options );

		return $contact;
	}

	/**
	 * Delete contacts. Suppression records are intentionally preserved so a
	 * deleted-and-reimported unsubscriber stays blocked.
	 *
	 * @param int[] $ids Contact ids.
	 * @return int Number deleted.
	 */
	public function delete( array $ids ): int {
		$contacts = $this->contacts->find_many( $ids );
		if ( empty( $contacts ) ) {
			return 0;
		}

		$ids = array_keys( $contacts );

		/**
		 * Fires before contacts are deleted.
		 *
		 * @param Contact[] $contacts Contacts about to be deleted.
		 */
		do_action( 'ibg_outreach_contacts_before_delete', $contacts );

		$deleted = $this->contacts->delete_many( $ids );
		$this->events->delete_for_contacts( $ids );

		return $deleted;
	}

	/**
	 * Bulk-set the contact (CRM) status.
	 *
	 * @param int[]  $ids    Contact ids.
	 * @param string $status Contact status.
	 * @return int Rows affected.
	 */
	public function bulk_set_contact_status( array $ids, string $status ): int {
		if ( ! array_key_exists( $status, Contact::contact_statuses() ) ) {
			return 0;
		}
		$affected = $this->contacts->update_many( $ids, array( 'contact_status' => $status ) );
		foreach ( $ids as $id ) {
			$this->events->log( Event_Repository::CONTACT_STATUS_CHANGED, (int) $id, array( 'to' => $status, 'bulk' => true ) );
		}
		return $affected;
	}

	/**
	 * Bulk-mark contacts as Do Not Contact (adds suppressions).
	 *
	 * @param int[] $ids Contact ids.
	 * @return int Contacts updated.
	 */
	public function bulk_mark_do_not_contact( array $ids ): int {
		$contacts = $this->contacts->find_many( $ids );
		$count    = 0;

		foreach ( $contacts as $contact ) {
			if ( Contact::MARKETING_DNC === $contact->marketing_status ) {
				continue;
			}
			$result = $this->update(
				$contact->id,
				array( 'marketing_status' => Contact::MARKETING_DNC ),
				array( 'source' => self::SOURCE_ADMIN )
			);
			if ( ! is_wp_error( $result ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Fill full_name from first/last when empty or previously auto-derived.
	 *
	 * @param Contact      $contact  Contact being saved.
	 * @param Contact|null $existing Previous state.
	 * @return void
	 */
	private function derive_full_name( Contact $contact, ?Contact $existing ): void {
		$derived = trim( $contact->first_name . ' ' . $contact->last_name );

		if ( '' === trim( $contact->full_name ) ) {
			$contact->full_name = $derived;
			return;
		}

		if ( $existing && $contact->full_name === trim( $existing->first_name . ' ' . $existing->last_name ) && '' !== $derived ) {
			$contact->full_name = $derived;
		}
	}

	/**
	 * Enforce the suppression policy and keep the suppression list in sync.
	 *
	 * @param Contact              $contact  Contact about to be saved (mutated).
	 * @param Contact|null         $existing Previous state, or null on create.
	 * @param array<string, mixed> $options  Options (source, confirm_resubscribe).
	 * @return void
	 */
	private function apply_marketing_policy( Contact $contact, ?Contact $existing, array $options ): void {
		$source      = (string) ( $options['source'] ?? self::SOURCE_ADMIN );
		$requested   = $contact->marketing_status;
		$email       = $contact->email;
		$campaign_id = ! empty( $options['campaign_id'] ) ? (int) $options['campaign_id'] : null;
		// The suppression list records where a block came from.
		$supp_source = self::SOURCE_PUBLIC === $source ? Suppression_Repository::SOURCE_LINK : $source;

		// What the address is currently locked to, if anything.
		$locked      = null;
		$suppression = $this->suppressions->find( $email );
		if ( $suppression ) {
			$locked = Suppression_Repository::REASON_DNC === $suppression->reason
				? Contact::MARKETING_DNC
				: Contact::MARKETING_UNSUBSCRIBED;
		} elseif ( $existing && $existing->is_suppressed() ) {
			$locked = $existing->marketing_status;
		}

		$requested_is_suppressed = in_array( $requested, Contact::suppressed_statuses(), true );
		$contact_id              = $contact->id > 0 ? $contact->id : null;

		// Who may lift a suppression: an admin who ticked the confirmation box (any block),
		// or the recipient themselves via the public resubscribe link (plain unsubscribes only,
		// never Do Not Contact). Imports and the API never can.
		$confirmed = ! empty( $options['confirm_resubscribe'] ) && (
			self::SOURCE_ADMIN === $source
			|| ( self::SOURCE_PUBLIC === $source && Contact::MARKETING_DNC !== $locked )
		);

		if ( null !== $locked ) {
			if ( $requested_is_suppressed ) {
				$downgrade = Contact::MARKETING_DNC === $locked && Contact::MARKETING_DNC !== $requested;
				if ( $downgrade && ! $confirmed ) {
					$contact->marketing_status = $locked;
					$this->notices[]           = __( 'Marketing status kept as Do Not Contact. Tick the confirmation box to change it.', 'ibg-client-outreach' );
				} elseif ( $requested !== $locked ) {
					$this->suppressions->add( $email, $this->reason_for( $requested ), $supp_source, $contact_id, $campaign_id );
					$this->log_marketing_change( $contact, $existing, $source );
				}
			} elseif ( $confirmed ) {
				$this->suppressions->remove( $email );
				$contact->unsubscribed_at = null;
				$this->queue_event(
					Event_Repository::CONTACT_RESUBSCRIBED,
					array(
						'from' => $locked,
						'to'   => $requested,
					)
				);
				$this->notices[] = __( 'Suppression removed: this contact can receive campaigns again.', 'ibg-client-outreach' );
			} else {
				$contact->marketing_status = $locked;
				$contact->unsubscribed_at  = $existing->unsubscribed_at ?? ( $suppression->created_at ?? $this->db->now() );
				$this->queue_event(
					Event_Repository::SUPPRESSION_PRESERVED,
					array(
						'requested' => $requested,
						'kept'      => $locked,
						'source'    => $source,
					)
				);
				$this->notices[] = sprintf(
					/* translators: %s: marketing status label */
					__( 'Marketing status kept as "%s": this address is on the suppression list. Only an explicit, confirmed resubscribe can change it.', 'ibg-client-outreach' ),
					Contact::label( Contact::marketing_statuses(), $locked )
				);
			}
		} elseif ( $requested_is_suppressed ) {
			$this->suppressions->add( $email, $this->reason_for( $requested ), $supp_source, $contact_id, $campaign_id );
			if ( empty( $contact->unsubscribed_at ) ) {
				$contact->unsubscribed_at = $this->db->now();
			}
			$this->log_marketing_change( $contact, $existing, $source );
		} else {
			$contact->unsubscribed_at = null;
			if ( $existing && $existing->marketing_status !== $contact->marketing_status ) {
				$this->log_marketing_change( $contact, $existing, $source );
			}
		}

		if ( Contact::MARKETING_SUBSCRIBED === $contact->marketing_status && empty( $contact->consent_at ) ) {
			$contact->consent_at = $this->db->now();
		}
	}

	/**
	 * Log a marketing status change event when the status actually changed.
	 *
	 * @param Contact      $contact  New state.
	 * @param Contact|null $existing Previous state.
	 * @param string       $source   Source.
	 * @return void
	 */
	private function log_marketing_change( Contact $contact, ?Contact $existing, string $source ): void {
		if ( $existing && $existing->marketing_status === $contact->marketing_status ) {
			return;
		}
		$this->queue_event(
			Event_Repository::MARKETING_STATUS_CHANGED,
			array(
				'from'   => $existing ? $existing->marketing_status : null,
				'to'     => $contact->marketing_status,
				'source' => $source,
			)
		);
	}

	/**
	 * Queue an event to be written once the contact id is known.
	 *
	 * @param string               $type Event type.
	 * @param array<string, mixed> $data Event data.
	 * @return void
	 */
	private function queue_event( string $type, array $data ): void {
		$this->pending_events[] = array(
			'type' => $type,
			'data' => $data,
		);
	}

	/**
	 * Write queued events for a contact.
	 *
	 * @param int $contact_id Contact id.
	 * @return void
	 */
	private function flush_events( int $contact_id ): void {
		foreach ( $this->pending_events as $event ) {
			$this->events->log( $event['type'], $contact_id, $event['data'] );
		}
		$this->pending_events = array();
	}

	/**
	 * Suppression reason for a suppressed marketing status.
	 *
	 * @param string $marketing_status Marketing status.
	 * @return string
	 */
	private function reason_for( string $marketing_status ): string {
		return Contact::MARKETING_DNC === $marketing_status
			? Suppression_Repository::REASON_DNC
			: Suppression_Repository::REASON_UNSUBSCRIBED;
	}
}
