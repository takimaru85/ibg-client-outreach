<?php
/**
 * Queue worker: sends one batch per run.
 *
 * Safety properties:
 *  - rows are claimed atomically (see Queue_Repository::claim) so concurrent
 *    runs never send the same email twice;
 *  - eligibility is re-checked at send time, so an unsubscribe that happened
 *    after queueing is honoured;
 *  - the run stops when its time budget is exhausted and releases what it
 *    could not process;
 *  - a campaign that is paused or cancelled mid-batch stops immediately.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Queue;

use IBG\Outreach\Campaigns\Campaign;
use IBG\Outreach\Campaigns\Campaign_Repository;
use IBG\Outreach\Campaigns\Campaign_Service;
use IBG\Outreach\Contacts\Contact;
use IBG\Outreach\Contacts\Contact_Repository;
use IBG\Outreach\Database;
use IBG\Outreach\Email\Email_Composer;
use IBG\Outreach\Email\Merge_Context;
use IBG\Outreach\Email\Provider_Registry;
use IBG\Outreach\Settings;
use IBG\Outreach\Unsubscribe\Suppression_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Class Queue_Worker
 */
final class Queue_Worker {

	public const OPTION_LAST_RUN   = 'ibg_outreach_queue_last_run';
	public const OPTION_LAST_STATS = 'ibg_outreach_queue_last_stats';
	public const STALE_MINUTES     = 10;

	/**
	 * Constructor.
	 *
	 * @param Queue_Repository       $queue            Queue.
	 * @param Campaign_Repository    $campaigns        Campaigns.
	 * @param Campaign_Service       $campaign_service Campaign service.
	 * @param Contact_Repository     $contacts         Contacts.
	 * @param Suppression_Repository $suppressions     Suppressions.
	 * @param Email_Composer         $composer         Composer.
	 * @param Provider_Registry      $providers        Providers.
	 * @param Email_Log_Repository   $logs             Logs.
	 * @param Settings               $settings         Settings.
	 * @param Database               $db               Database.
	 */
	public function __construct(
		private readonly Queue_Repository $queue,
		private readonly Campaign_Repository $campaigns,
		private readonly Campaign_Service $campaign_service,
		private readonly Contact_Repository $contacts,
		private readonly Suppression_Repository $suppressions,
		private readonly Email_Composer $composer,
		private readonly Provider_Registry $providers,
		private readonly Email_Log_Repository $logs,
		private readonly Settings $settings,
		private readonly Database $db
	) {}

	/**
	 * Process one batch.
	 *
	 * @param bool $force Ignore the batch delay (admin "Run now").
	 * @return array<string, mixed> Stats.
	 */
	public function run( bool $force = false ): array {
		$started = microtime( true );
		$stats   = array(
			'ran'       => false,
			'reason'    => '',
			'released'  => 0,
			'claimed'   => 0,
			'sent'      => 0,
			'failed'    => 0,
			'retried'   => 0,
			'skipped'   => 0,
			'deferred'  => 0,
			'completed' => array(),
			'duration'  => 0.0,
		);

		$delay    = (int) $this->settings->get( 'batch_delay', 60 );
		$last_run = (int) get_option( self::OPTION_LAST_RUN, 0 );
		if ( ! $force && $delay > 0 && ( time() - $last_run ) < $delay ) {
			$stats['reason'] = 'delay';
			return $stats;
		}

		update_option( self::OPTION_LAST_RUN, time(), false );
		$stats['ran']      = true;
		$stats['released'] = $this->queue->release_stale( self::STALE_MINUTES );

		$batch_size  = max( 1, (int) $this->settings->get( 'batch_size', 25 ) );
		$max_retries = max( 0, (int) $this->settings->get( 'max_retries', 3 ) );
		$retry_delay = max( 1, (int) $this->settings->get( 'retry_delay', 15 ) );

		/**
		 * Filter the time budget (seconds) for one queue run.
		 *
		 * @param int $seconds Default 20.
		 */
		$budget = (int) apply_filters( 'ibg_outreach_queue_time_budget', 20 );

		$token = bin2hex( random_bytes( 16 ) );
		$items = $this->queue->claim( $token, $batch_size, $this->db->now() );

		$stats['claimed'] = count( $items );
		$provider         = $this->providers->get_active();
		$campaign_cache   = array();
		$touched          = array();

		foreach ( $items as $item ) {
			if ( ( microtime( true ) - $started ) > $budget ) {
				$this->queue->release( $item->id, $token );
				++$stats['deferred'];
				continue;
			}

			if ( ! isset( $campaign_cache[ $item->campaign_id ] ) ) {
				$campaign_cache[ $item->campaign_id ] = $this->campaigns->find( $item->campaign_id );
			}
			$campaign = $campaign_cache[ $item->campaign_id ];

			if ( ! $campaign instanceof Campaign || Campaign::STATUS_PROCESSING !== $campaign->status ) {
				// Paused/cancelled/deleted since the claim: give the row back.
				$this->queue->release( $item->id, $token );
				++$stats['deferred'];
				continue;
			}

			$touched[ $campaign->id ] = true;
			$now                      = $this->db->now();
			$contact                  = $this->contacts->find( $item->contact_id );
			$reason                   = $this->skip_reason( $contact, $item, $campaign );

			if ( null !== $reason ) {
				$this->queue->mark_skipped( $item->id, $reason, $now );
				$this->campaigns->increment( $campaign->id, 'total_skipped' );
				$this->logs->log(
					array(
						'campaign_id'   => $campaign->id,
						'contact_id'    => $item->contact_id,
						'queue_id'      => $item->id,
						'email'         => $item->email,
						'subject'       => $campaign->subject,
						'status'        => Email_Log_Repository::STATUS_SKIPPED,
						'provider'      => $provider->get_id(),
						'error_message' => $reason,
						'retry_count'   => $item->attempts,
					)
				);
				++$stats['skipped'];
				continue;
			}

			$message = $this->composer->compose(
				array(
					'to_email'   => $contact->email,
					'to_name'    => $contact->get_display_name(),
					'subject'    => $campaign->subject,
					'body_html'  => $campaign->body_html,
					'body_text'  => $campaign->body_text,
					'from_name'  => $campaign->from_name,
					'from_email' => $campaign->from_email,
					'reply_to'   => $campaign->reply_to,
					'context'    => new Merge_Context( $contact, $campaign->id, $item->id, false ),
				)
			);

			$result   = $provider->send( $message );
			$attempts = $item->attempts + 1;

			if ( $result->is_success() ) {
				$this->queue->mark_sent( $item->id, $now );
				$this->campaigns->increment( $campaign->id, 'total_sent' );
				$this->contacts->update_many( array( $contact->id ), array( 'last_contacted_at' => $now ) );
				$this->logs->log(
					array(
						'campaign_id'         => $campaign->id,
						'contact_id'          => $contact->id,
						'queue_id'            => $item->id,
						'email'               => $contact->email,
						'subject'             => $message->get_subject(),
						'status'              => Email_Log_Repository::STATUS_SENT,
						'provider'            => $provider->get_id(),
						'provider_message_id' => $result->get_message_id(),
						'retry_count'         => $item->attempts,
						'sent_at'             => $now,
					)
				);
				++$stats['sent'];

				/**
				 * Fires after a campaign email was accepted by the provider.
				 *
				 * @param Queue_Item $item     Queue row.
				 * @param Contact    $contact  Contact.
				 * @param Campaign   $campaign Campaign.
				 */
				do_action( 'ibg_outreach_email_sent', $item, $contact, $campaign );
				continue;
			}

			$error = $result->get_error();
			$this->logs->log(
				array(
					'campaign_id'   => $campaign->id,
					'contact_id'    => $contact->id,
					'queue_id'      => $item->id,
					'email'         => $contact->email,
					'subject'       => $message->get_subject(),
					'status'        => Email_Log_Repository::STATUS_FAILED,
					'provider'      => $provider->get_id(),
					'error_message' => $error,
					'retry_count'   => $attempts,
				)
			);

			if ( $result->is_retryable() && $attempts <= $max_retries ) {
				// Linear backoff: retry_delay × attempt number.
				$next = gmdate( 'Y-m-d H:i:s', time() + $retry_delay * MINUTE_IN_SECONDS * $attempts );
				$this->queue->reschedule( $item->id, $error, $attempts, $next, $now );
				++$stats['retried'];
			} else {
				$this->queue->mark_failed( $item->id, $error, $attempts, $now );
				$this->campaigns->increment( $campaign->id, 'total_failed' );
				++$stats['failed'];
			}
		}

		$stats['completed'] = $this->complete_drained_campaigns();
		$stats['campaigns'] = array_keys( $touched );
		$stats['duration']  = round( microtime( true ) - $started, 2 );

		update_option( self::OPTION_LAST_STATS, $stats, false );

		/**
		 * Fires after a queue run.
		 *
		 * @param array $stats Run statistics.
		 */
		do_action( 'ibg_outreach_queue_processed', $stats );

		return $stats;
	}

	/**
	 * Why a row must not be sent, or null if it is eligible.
	 *
	 * @param Contact|null $contact  Contact (fresh from the DB).
	 * @param Queue_Item   $item     Queue row.
	 * @param Campaign     $campaign Campaign.
	 * @return string|null
	 */
	private function skip_reason( ?Contact $contact, Queue_Item $item, Campaign $campaign ): ?string {
		if ( ! $contact ) {
			return __( 'Contact no longer exists', 'ibg-client-outreach' );
		}
		if ( $contact->email !== $item->email ) {
			return __( 'Email address changed since queued', 'ibg-client-outreach' );
		}
		if ( Contact::MARKETING_DNC === $contact->marketing_status ) {
			return __( 'Do not contact', 'ibg-client-outreach' );
		}
		if ( Contact::MARKETING_UNSUBSCRIBED === $contact->marketing_status ) {
			return __( 'Unsubscribed', 'ibg-client-outreach' );
		}
		if ( Contact::EMAIL_VALID !== $contact->email_status ) {
			return __( 'Invalid or bounced email', 'ibg-client-outreach' );
		}
		if ( $this->suppressions->is_suppressed( $contact->email ) ) {
			return __( 'Address on suppression list', 'ibg-client-outreach' );
		}
		$allowed = Campaign::SCOPE_SUBSCRIBED_PENDING === $campaign->scope
			? array( Contact::MARKETING_SUBSCRIBED, Contact::MARKETING_PENDING )
			: array( Contact::MARKETING_SUBSCRIBED );
		if ( ! in_array( $contact->marketing_status, $allowed, true ) ) {
			return __( 'Outside the campaign consent scope', 'ibg-client-outreach' );
		}

		/**
		 * Filter the skip reason for a queued email. Return a non-empty string
		 * to skip (never send) this row.
		 *
		 * @param string|null $reason   Null to send.
		 * @param Contact     $contact  Contact.
		 * @param Campaign    $campaign Campaign.
		 * @param Queue_Item  $item     Queue row.
		 */
		$reason = apply_filters( 'ibg_outreach_queue_skip_reason', null, $contact, $campaign, $item );

		return is_string( $reason ) && '' !== $reason ? $reason : null;
	}

	/**
	 * Mark processing campaigns with no open rows as completed. Sweeps all
	 * processing campaigns so one drained by a cancel/skip elsewhere also closes.
	 *
	 * @return int[] Completed campaign ids.
	 */
	private function complete_drained_campaigns(): array {
		$processing = $this->campaigns->query(
			array(
				'status'   => Campaign::STATUS_PROCESSING,
				'per_page' => 100,
				'orderby'  => 'id',
				'order'    => 'ASC',
			)
		)['items'];

		$completed = array();
		foreach ( $processing as $campaign ) {
			if ( 0 === $this->queue->count_open( $campaign->id ) && $this->campaign_service->complete( $campaign ) ) {
				$completed[] = $campaign->id;
			}
		}
		return $completed;
	}
}
