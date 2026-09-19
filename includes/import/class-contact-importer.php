<?php
/**
 * Contact importer: column mapping, analysis and batched import.
 *
 * Every row is written through Contact_Service with source "import", so the
 * suppression policy, validation and audit logging apply exactly as they do
 * for manual edits.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Import;

use IBG\Outreach\Contacts\Contact;
use IBG\Outreach\Contacts\Contact_Repository;
use IBG\Outreach\Contacts\Contact_Service;
use IBG\Outreach\Events\Event_Repository;
use IBG\Outreach\Lists\List_Repository;
use IBG\Outreach\Unsubscribe\Suppression_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Class Contact_Importer
 */
final class Contact_Importer {

	public const STRATEGY_SKIP      = 'skip';
	public const STRATEGY_FILL      = 'fill';
	public const STRATEGY_OVERWRITE = 'overwrite';

	/**
	 * Rows read per analysis window.
	 */
	private const ANALYSIS_WINDOW = 1000;

	/**
	 * Samples of invalid/duplicate rows kept for display.
	 */
	private const SAMPLE_LIMIT = 20;

	/**
	 * Constructor.
	 *
	 * @param Contact_Repository     $contacts     Contacts.
	 * @param Contact_Service        $service      Contact service.
	 * @param Suppression_Repository $suppressions Suppressions.
	 * @param Event_Repository       $events       Events.
	 */
	public function __construct(
		private readonly Contact_Repository $contacts,
		private readonly Contact_Service $service,
		private readonly Suppression_Repository $suppressions,
		private readonly Event_Repository $events,
		private readonly List_Repository $lists
	) {}

	/**
	 * Fields a CSV column may be mapped to. Statuses are intentionally
	 * excluded: they are set per import, never per row.
	 *
	 * @return array<string, string>
	 */
	public static function mappable_fields(): array {
		return array(
			'email'      => __( 'Email (required)', 'ibg-client-outreach' ),
			'first_name' => __( 'First Name', 'ibg-client-outreach' ),
			'last_name'  => __( 'Last Name', 'ibg-client-outreach' ),
			'full_name'  => __( 'Full Name', 'ibg-client-outreach' ),
			'company'    => __( 'Company', 'ibg-client-outreach' ),
			'website'    => __( 'Website', 'ibg-client-outreach' ),
			'phone'      => __( 'Phone', 'ibg-client-outreach' ),
			'country'    => __( 'Country', 'ibg-client-outreach' ),
			'industry'   => __( 'Industry', 'ibg-client-outreach' ),
			'source'     => __( 'Source', 'ibg-client-outreach' ),
			'notes'      => __( 'Notes', 'ibg-client-outreach' ),
		);
	}

	/**
	 * Duplicate strategies with labels.
	 *
	 * @return array<string, string>
	 */
	public static function strategies(): array {
		return array(
			self::STRATEGY_SKIP      => __( 'Skip – leave existing contacts untouched (recommended)', 'ibg-client-outreach' ),
			self::STRATEGY_FILL      => __( 'Fill empty fields only – add missing details, never replace existing values', 'ibg-client-outreach' ),
			self::STRATEGY_OVERWRITE => __( 'Overwrite – replace existing values with non-empty CSV values', 'ibg-client-outreach' ),
		);
	}

	/**
	 * Guess a mapping from header names.
	 *
	 * @param string[] $headers CSV headers.
	 * @return array<int, string> Column index => field or ''.
	 */
	public static function guess_mapping( array $headers ): array {
		$aliases = array(
			'email'      => array( 'email', 'e mail', 'email address', 'emailaddress', 'mail', 'contact email', 'work email' ),
			'first_name' => array( 'first name', 'firstname', 'first', 'given name', 'forename' ),
			'last_name'  => array( 'last name', 'lastname', 'last', 'surname', 'family name' ),
			'full_name'  => array( 'name', 'full name', 'fullname', 'contact', 'contact name', 'person', 'owner' ),
			'company'    => array( 'company', 'company name', 'business', 'business name', 'organization', 'organisation', 'org', 'firm' ),
			'website'    => array( 'website', 'web site', 'web', 'url', 'site', 'domain', 'website url', 'homepage', 'web address' ),
			'phone'      => array( 'phone', 'telephone', 'tel', 'mobile', 'phone number', 'cell', 'contact number' ),
			'country'    => array( 'country', 'nation', 'location' ),
			'industry'   => array( 'industry', 'sector', 'category', 'niche', 'business type', 'type' ),
			'source'     => array( 'source', 'origin', 'lead source', 'channel' ),
			'notes'      => array( 'notes', 'note', 'comments', 'comment', 'description', 'remarks' ),
		);

		/**
		 * Filter header aliases used for automatic column mapping.
		 *
		 * @param array<string, string[]> $aliases Field => header aliases.
		 */
		$aliases = apply_filters( 'ibg_outreach_import_header_aliases', $aliases );

		$mapping = array();
		$used    = array();

		foreach ( $headers as $index => $header ) {
			$normalized        = trim( preg_replace( '/\s+/', ' ', str_replace( array( '_', '-', '.', ':' ), ' ', strtolower( $header ) ) ) );
			$mapping[ $index ] = '';

			foreach ( $aliases as $field => $names ) {
				if ( isset( $used[ $field ] ) ) {
					continue;
				}
				if ( in_array( $normalized, $names, true ) ) {
					$mapping[ $index ] = $field;
					$used[ $field ]    = true;
					break;
				}
			}
		}

		return $mapping;
	}

	/**
	 * Stream through the file and compute the pre-import summary.
	 *
	 * @param Import_Session $session Session with mapping and options.
	 * @return array<string, mixed>
	 */
	public function analyze( Import_Session $session ): array {
		$reader    = new CSV_Reader( $session->file_path, $session->delimiter );
		$email_col = array_search( 'email', $session->mapping, true );

		$total      = 0;
		$invalid    = 0;
		$duplicates = 0;
		$seen       = array(); // email => first row index.
		$skip_rows  = array();
		$samples    = array();

		$offset = 0;
		while ( true ) {
			$rows = $reader->read( $offset, self::ANALYSIS_WINDOW );
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $index => $row ) {
				++$total;
				$raw   = false !== $email_col ? (string) ( $row[ $email_col ] ?? '' ) : '';
				$email = Contact_Service::normalize_email( $raw );

				if ( '' === $email ) {
					++$invalid;
					$skip_rows[] = $index;
					$this->sample( $samples, $index, $raw, __( 'Missing email', 'ibg-client-outreach' ) );
				} elseif ( ! is_email( $email ) ) {
					++$invalid;
					$skip_rows[] = $index;
					$this->sample( $samples, $index, $raw, __( 'Invalid email', 'ibg-client-outreach' ) );
				} elseif ( isset( $seen[ $email ] ) ) {
					++$duplicates;
					$skip_rows[] = $index;
					$this->sample(
						$samples,
						$index,
						$raw,
						sprintf(
							/* translators: %d: row number */
							__( 'Duplicate of row %d', 'ibg-client-outreach' ),
							$seen[ $email ] + 2
						)
					);
				} else {
					$seen[ $email ] = $index;
				}
			}

			$offset += count( $rows );
		}

		$emails     = array_keys( $seen );
		$existing   = $this->contacts->find_ids_by_emails( $emails );
		$suppressed = $this->suppressions->find_suppressed( $emails );

		$existing_count = count( $existing );
		$new_count      = count( $emails ) - $existing_count;
		$strategy       = (string) ( $session->options['duplicate_strategy'] ?? self::STRATEGY_SKIP );

		$suppressed_new = 0;
		foreach ( array_keys( $suppressed ) as $email ) {
			if ( ! isset( $existing[ $email ] ) ) {
				++$suppressed_new;
			}
		}

		return array(
			'total'          => $total,
			'valid'          => count( $emails ),
			'invalid'        => $invalid,
			'duplicates'     => $duplicates,
			'existing'       => $existing_count,
			'to_create'      => $new_count,
			'to_update'      => self::STRATEGY_SKIP === $strategy ? 0 : $existing_count,
			'to_skip'        => self::STRATEGY_SKIP === $strategy ? $existing_count : 0,
			'suppressed_new' => $suppressed_new,
			'skip_rows'      => $skip_rows,
			'samples'        => $samples,
		);
	}

	/**
	 * Import the next window of rows.
	 *
	 * @param Import_Session $session Session (mutated: progress).
	 * @param int            $limit   Rows per batch.
	 * @return array<string, mixed> Updated progress.
	 */
	public function import_batch( Import_Session $session, int $limit ): array {
		$progress = $session->progress;
		if ( ! empty( $progress['done'] ) ) {
			return $progress;
		}
		if ( '' === (string) $progress['started_at'] ) {
			$progress['started_at'] = current_time( 'mysql', true );
		}

		$reader   = new CSV_Reader( $session->file_path, $session->delimiter );
		$rows     = $reader->read( (int) $progress['offset'], max( 1, $limit ) );
		$skip     = array_flip( array_map( 'intval', (array) ( $session->analysis['skip_rows'] ?? array() ) ) );
		$strategy = (string) ( $session->options['duplicate_strategy'] ?? self::STRATEGY_SKIP );

		// One lookup for the whole batch.
		$emails = array();
		foreach ( $rows as $index => $row ) {
			if ( isset( $skip[ $index ] ) ) {
				continue;
			}
			$email_col = array_search( 'email', $session->mapping, true );
			$emails[]  = Contact_Service::normalize_email( (string) ( $row[ $email_col ] ?? '' ) );
		}
		$existing = $this->contacts->find_ids_by_emails( $emails );
		$list_id  = (int) ( $session->options['list_id'] ?? 0 );
		$members  = array(); // Contact ids (new or existing) to add to the target list.

		foreach ( $rows as $index => $row ) {
			++$progress['processed'];

			if ( isset( $skip[ $index ] ) ) {
				++$progress['skipped_invalid'];
				continue;
			}

			$data  = $this->build_row_data( $row, $session->mapping, $session->options );
			$email = $data['email'];

			if ( isset( $existing[ $email ] ) ) {
				$members[] = (int) $existing[ $email ];

				if ( self::STRATEGY_SKIP === $strategy ) {
					++$progress['skipped_existing'];
					continue;
				}

				$update = $this->build_update_data( (int) $existing[ $email ], $data, $strategy );
				if ( empty( $update ) ) {
					++$progress['skipped_existing'];
					continue;
				}

				$result = $this->service->update( (int) $existing[ $email ], $update, array( 'source' => Contact_Service::SOURCE_IMPORT ) );
				if ( is_wp_error( $result ) ) {
					++$progress['failed'];
					$this->record_error( $progress, $index, $email, $result );
				} else {
					++$progress['updated'];
				}
				continue;
			}

			$result = $this->service->create( $data, array( 'source' => Contact_Service::SOURCE_IMPORT ) );
			if ( is_wp_error( $result ) ) {
				++$progress['failed'];
				$this->record_error( $progress, $index, $email, $result );
				continue;
			}

			++$progress['created'];
			if ( ! empty( $this->service->take_notices() ) ) {
				++$progress['preserved'];
			}
			// A newly created address is "existing" for any later row in this batch.
			$existing[ $email ] = $result->id;
			$members[]          = $result->id;
		}

		if ( $list_id > 0 && ! empty( $members ) ) {
			$progress['listed'] = (int) ( $progress['listed'] ?? 0 ) + $this->lists->add_contacts( $list_id, $members );
		}

		$progress['offset'] += count( $rows );

		if ( count( $rows ) < $limit ) {
			$progress['done']        = true;
			$progress['finished_at'] = current_time( 'mysql', true );

			Import_Storage::delete( $session->file_path );

			$summary = array_intersect_key( $progress, array_flip( array( 'processed', 'created', 'updated', 'skipped_invalid', 'skipped_existing', 'preserved', 'failed', 'listed' ) ) );
			$summary['file'] = $session->original_name;
			$this->events->log( 'import.completed', 0, $summary );

			/**
			 * Fires when an import finishes.
			 *
			 * @param array          $summary Counters.
			 * @param Import_Session $session Session.
			 */
			do_action( 'ibg_outreach_import_completed', $summary, $session );
		}

		$session->progress = $progress;
		$session->save();

		return $progress;
	}

	/**
	 * Build Contact_Service input for a new contact from a CSV row.
	 *
	 * @param string[]             $row     Row values by column index.
	 * @param array<int, string>   $mapping Column => field.
	 * @param array<string, mixed> $options Import options.
	 * @return array<string, mixed>
	 */
	public function build_row_data( array $row, array $mapping, array $options ): array {
		$data = array();
		foreach ( $mapping as $index => $field ) {
			if ( '' === $field ) {
				continue;
			}
			$data[ $field ] = (string) ( $row[ $index ] ?? '' );
		}

		$data['email'] = Contact_Service::normalize_email( (string) ( $data['email'] ?? '' ) );

		if ( '' === trim( (string) ( $data['source'] ?? '' ) ) ) {
			$data['source'] = (string) ( $options['source'] ?? '' );
		}

		$data['contact_status']   = (string) ( $options['contact_status'] ?? Contact::STATUS_LEAD );
		$data['marketing_status'] = (string) ( $options['marketing_status'] ?? Contact::MARKETING_PENDING );
		$data['consent_basis']    = (string) ( $options['consent_basis'] ?? '' );

		return $data;
	}

	/**
	 * Fields to change on an existing contact according to the strategy.
	 * Statuses and email are never changed; empty CSV cells never blank data.
	 *
	 * @param int                  $contact_id Existing contact id.
	 * @param array<string, mixed> $data       Row data.
	 * @param string               $strategy   fill|overwrite.
	 * @return array<string, string>
	 */
	private function build_update_data( int $contact_id, array $data, string $strategy ): array {
		$existing = $this->contacts->find( $contact_id );
		if ( ! $existing ) {
			return array();
		}

		$update = array();
		foreach ( array_keys( self::mappable_fields() ) as $field ) {
			if ( 'email' === $field || ! isset( $data[ $field ] ) ) {
				continue;
			}
			$value = trim( (string) $data[ $field ] );
			if ( '' === $value ) {
				continue;
			}
			if ( self::STRATEGY_FILL === $strategy && '' !== trim( (string) $existing->{$field} ) ) {
				continue;
			}
			if ( (string) $existing->{$field} === $value ) {
				continue;
			}
			$update[ $field ] = $value;
		}

		return $update;
	}

	/**
	 * Keep a bounded sample of problem rows.
	 *
	 * @param array<int, array<string, mixed>> $samples Samples (by reference).
	 * @param int                              $index   Row index.
	 * @param string                           $email   Raw email cell.
	 * @param string                           $reason  Reason.
	 * @return void
	 */
	private function sample( array &$samples, int $index, string $email, string $reason ): void {
		if ( count( $samples ) >= self::SAMPLE_LIMIT ) {
			return;
		}
		$samples[] = array(
			'row'    => $index + 2, // 1-based, after the header.
			'email'  => mb_substr( $email, 0, 100 ),
			'reason' => $reason,
		);
	}

	/**
	 * Keep a bounded list of import errors.
	 *
	 * @param array<string, mixed> $progress Progress (by reference).
	 * @param int                  $index    Row index.
	 * @param string               $email    Email.
	 * @param \WP_Error            $error    Error.
	 * @return void
	 */
	private function record_error( array &$progress, int $index, string $email, \WP_Error $error ): void {
		if ( count( $progress['errors'] ) >= self::SAMPLE_LIMIT ) {
			return;
		}
		$progress['errors'][] = array(
			'row'     => $index + 2,
			'email'   => $email,
			'message' => $error->get_error_message(),
		);
	}
}
