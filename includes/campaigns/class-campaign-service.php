<?php
/**
 * Campaign service: validation, pre-flight checks and the status state machine.
 *
 * Transitions:
 *   draft      → scheduled (schedule) | processing (start)
 *   scheduled  → draft (unschedule) | processing (start / cron) | cancelled
 *   processing → paused | completed (queue drained) | cancelled
 *   paused     → processing (resume) | cancelled
 *   completed, cancelled: terminal
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Campaigns;

use IBG\Outreach\Database;
use IBG\Outreach\Email\Provider_Registry;
use IBG\Outreach\Events\Event_Repository;
use IBG\Outreach\Formatting;
use IBG\Outreach\Lists\List_Repository;
use IBG\Outreach\Settings;
use IBG\Outreach\Templates\Template_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Class Campaign_Service
 */
final class Campaign_Service {

	public const HOOK_STARTED   = 'ibg_outreach_campaign_started';
	public const HOOK_PAUSED    = 'ibg_outreach_campaign_paused';
	public const HOOK_RESUMED   = 'ibg_outreach_campaign_resumed';
	public const HOOK_CANCELLED = 'ibg_outreach_campaign_cancelled';
	public const HOOK_COMPLETED = 'ibg_outreach_campaign_completed';
	public const HOOK_DELETED   = 'ibg_outreach_campaign_deleted';

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository $campaigns Campaigns.
	 * @param Template_Repository $templates Templates.
	 * @param List_Repository     $lists     Lists.
	 * @param Audience_Resolver   $audience  Audience resolver.
	 * @param Provider_Registry   $providers Providers.
	 * @param Settings            $settings  Settings.
	 * @param Event_Repository    $events    Events.
	 * @param Database            $db        Database.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Template_Repository $templates,
		private readonly List_Repository $lists,
		private readonly Audience_Resolver $audience,
		private readonly Provider_Registry $providers,
		private readonly Settings $settings,
		private readonly Event_Repository $events,
		private readonly Database $db
	) {}

	/**
	 * Create or update a draft/scheduled campaign.
	 *
	 * Data: name, subject, from_name, from_email, reply_to, template_id, list_id (0 = all), scope.
	 *
	 * @param array<string, mixed> $data Raw input.
	 * @param int                  $id   Existing id or 0.
	 * @return Campaign|\WP_Error
	 */
	public function save( array $data, int $id = 0 ): Campaign|\WP_Error {
		$existing = $id > 0 ? $this->campaigns->find( $id ) : null;
		if ( $id > 0 && ! $existing ) {
			return new \WP_Error( 'not_found', __( 'Campaign not found.', 'ibg-client-outreach' ) );
		}
		if ( $existing && ! $existing->is_editable() ) {
			return new \WP_Error( 'locked', __( 'This campaign can no longer be edited.', 'ibg-client-outreach' ) );
		}

		$campaign = $existing ?? new Campaign();
		$errors   = new \WP_Error();

		$campaign->name = mb_substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 190 );
		if ( '' === $campaign->name ) {
			$errors->add( 'name', __( 'A campaign name is required.', 'ibg-client-outreach' ) );
		}

		$campaign->from_name = mb_substr( sanitize_text_field( (string) ( $data['from_name'] ?? '' ) ), 0, 190 );

		foreach ( array( 'from_email', 'reply_to' ) as $field ) {
			$value = sanitize_email( (string) ( $data[ $field ] ?? '' ) );
			if ( '' !== $value && ! is_email( $value ) ) {
				$errors->add( $field, 'from_email' === $field ? __( 'The From email is not valid.', 'ibg-client-outreach' ) : __( 'The Reply-To email is not valid.', 'ibg-client-outreach' ) );
			}
			$campaign->{$field} = $value;
		}

		$template_id = absint( $data['template_id'] ?? 0 );
		if ( $template_id > 0 ) {
			$template = $this->templates->find( $template_id );
			if ( ! $template ) {
				$errors->add( 'template', __( 'The selected template does not exist.', 'ibg-client-outreach' ) );
			} else {
				$campaign->template_id = $template_id;
				// Default the subject from the template when none is given.
				if ( '' === trim( (string) ( $data['subject'] ?? '' ) ) ) {
					$data['subject'] = $template->subject;
				}
			}
		} else {
			$campaign->template_id = null;
		}

		$campaign->subject = mb_substr( sanitize_text_field( (string) ( $data['subject'] ?? '' ) ), 0, 255 );

		$list_id = absint( $data['list_id'] ?? 0 );
		if ( $list_id > 0 && ! $this->lists->find( $list_id ) ) {
			$errors->add( 'list', __( 'The selected list or segment does not exist.', 'ibg-client-outreach' ) );
		}
		$campaign->list_id = $list_id > 0 ? $list_id : null;

		$campaign->scope = Campaign::SCOPE_SUBSCRIBED_PENDING === ( $data['scope'] ?? '' )
			? Campaign::SCOPE_SUBSCRIBED_PENDING
			: Campaign::SCOPE_SUBSCRIBED;

		if ( $errors->has_errors() ) {
			return $errors;
		}

		if ( $existing ) {
			if ( ! $this->campaigns->update( $campaign ) ) {
				return new \WP_Error( 'db_error', __( 'The campaign could not be saved.', 'ibg-client-outreach' ) );
			}
		} else {
			$campaign->status     = Campaign::STATUS_DRAFT;
			$campaign->created_by = get_current_user_id();
			if ( ! $this->campaigns->insert( $campaign ) ) {
				return new \WP_Error( 'db_error', __( 'The campaign could not be saved.', 'ibg-client-outreach' ) );
			}
		}

		return $campaign;
	}

	/**
	 * Duplicate a campaign as a new draft.
	 *
	 * @param int $id Campaign id.
	 * @return Campaign|\WP_Error
	 */
	public function duplicate( int $id ): Campaign|\WP_Error {
		$source = $this->campaigns->find( $id );
		if ( ! $source ) {
			return new \WP_Error( 'not_found', __( 'Campaign not found.', 'ibg-client-outreach' ) );
		}
		return $this->save(
			array(
				'name'        => sprintf( /* translators: %s: campaign name */ __( '%s (copy)', 'ibg-client-outreach' ), $source->name ),
				'subject'     => $source->subject,
				'from_name'   => $source->from_name,
				'from_email'  => $source->from_email,
				'reply_to'    => $source->reply_to,
				'template_id' => $source->template_id,
				'list_id'     => $source->list_id,
				'scope'       => $source->scope,
			)
		);
	}

	/**
	 * Delete campaigns that are not running.
	 *
	 * @param int[] $ids Campaign ids.
	 * @return array{deleted:int, blocked:int}
	 */
	public function delete( array $ids ): array {
		$deletable = array();
		$blocked   = 0;
		foreach ( array_filter( array_map( 'intval', $ids ) ) as $id ) {
			$campaign = $this->campaigns->find( $id );
			if ( ! $campaign ) {
				continue;
			}
			if ( in_array( $campaign->status, array( Campaign::STATUS_PROCESSING, Campaign::STATUS_PAUSED ), true ) ) {
				++$blocked;
				continue;
			}
			$deletable[] = $id;
		}

		$deleted = $this->campaigns->delete_many( $deletable );
		foreach ( $deletable as $id ) {
			/**
			 * Fires after a campaign row is deleted (queue rows are cleaned up by the queue module).
			 *
			 * @param int $id Campaign id.
			 */
			do_action( self::HOOK_DELETED, $id );
		}

		return array(
			'deleted' => $deleted,
			'blocked' => $blocked,
		);
	}

	/**
	 * Pre-flight checks that must pass before sending.
	 *
	 * @param Campaign $campaign Campaign.
	 * @return array<int, array{id:string, label:string, ok:bool, message:string, blocking:bool}>
	 */
	public function preflight( Campaign $campaign ): array {
		$checks = array();

		$address  = trim( (string) $this->settings->get( 'business_address', '' ) );
		$checks[] = array(
			'id'       => 'address',
			'label'    => __( 'Business postal address', 'ibg-client-outreach' ),
			'ok'       => '' !== $address,
			'message'  => '' !== $address ? __( 'Included in the footer.', 'ibg-client-outreach' ) : __( 'Set it in Settings → Compliance. Required by anti-spam law.', 'ibg-client-outreach' ),
			'blocking' => true,
		);

		$from     = '' !== $campaign->from_email ? $campaign->from_email : (string) $this->settings->get( 'from_email', '' );
		$checks[] = array(
			'id'       => 'from',
			'label'    => __( 'From email', 'ibg-client-outreach' ),
			'ok'       => is_email( $from ),
			'message'  => is_email( $from ) ? $from : __( 'No valid From email on the campaign or in Settings.', 'ibg-client-outreach' ),
			'blocking' => true,
		);

		$template = $campaign->template_id ? $this->templates->find( $campaign->template_id ) : null;
		if ( $campaign->has_started() ) {
			$checks[] = array(
				'id'       => 'template',
				'label'    => __( 'Email content', 'ibg-client-outreach' ),
				'ok'       => '' !== trim( $campaign->body_html ),
				'message'  => __( 'Snapshot taken when the campaign started.', 'ibg-client-outreach' ),
				'blocking' => true,
			);
		} else {
			$ok       = $template && $template->is_active && '' !== trim( $template->body_html );
			$checks[] = array(
				'id'       => 'template',
				'label'    => __( 'Template', 'ibg-client-outreach' ),
				'ok'       => $ok,
				'message'  => $ok ? $template->name : ( $template ? __( 'The template is inactive or empty.', 'ibg-client-outreach' ) : __( 'Choose an active template.', 'ibg-client-outreach' ) ),
				'blocking' => true,
			);
		}

		$checks[] = array(
			'id'       => 'subject',
			'label'    => __( 'Subject', 'ibg-client-outreach' ),
			'ok'       => '' !== trim( $campaign->subject ),
			'message'  => '' !== trim( $campaign->subject ) ? $campaign->subject : __( 'The subject is empty.', 'ibg-client-outreach' ),
			'blocking' => true,
		);

		$provider = $this->providers->get_active();
		$checks[] = array(
			'id'       => 'provider',
			'label'    => __( 'Sending provider', 'ibg-client-outreach' ),
			'ok'       => $provider->is_configured(),
			'message'  => $provider->get_name(),
			'blocking' => true,
		);

		$summary = $this->audience->summarize( $campaign );
		if ( is_wp_error( $summary ) ) {
			$checks[] = array(
				'id'       => 'audience',
				'label'    => __( 'Audience', 'ibg-client-outreach' ),
				'ok'       => false,
				'message'  => $summary->get_error_message(),
				'blocking' => true,
			);
		} else {
			$checks[] = array(
				'id'       => 'audience',
				'label'    => __( 'Estimated sends', 'ibg-client-outreach' ),
				'ok'       => $summary['sends'] > 0,
				'message'  => $summary['sends'] > 0
					? sprintf( /* translators: %s: number */ __( '%s contacts will receive this campaign.', 'ibg-client-outreach' ), number_format_i18n( $summary['sends'] ) )
					: __( 'No eligible recipients. Check the audience and consent scope.', 'ibg-client-outreach' ),
				'blocking' => ! $campaign->has_started(),
			);
		}

		/**
		 * Filter pre-flight checks (extensions may add their own).
		 *
		 * @param array    $checks   Checks.
		 * @param Campaign $campaign Campaign.
		 */
		return (array) apply_filters( 'ibg_outreach_campaign_preflight', $checks, $campaign );
	}

	/**
	 * Whether all blocking pre-flight checks pass.
	 *
	 * @param Campaign $campaign Campaign.
	 * @return bool|\WP_Error True when ready.
	 */
	public function assert_ready( Campaign $campaign ): bool|\WP_Error {
		$failed = array();
		foreach ( $this->preflight( $campaign ) as $check ) {
			if ( $check['blocking'] && ! $check['ok'] ) {
				$failed[] = $check['label'] . ': ' . $check['message'];
			}
		}
		if ( ! empty( $failed ) ) {
			return new \WP_Error( 'preflight', implode( ' ', $failed ) );
		}
		return true;
	}

	/**
	 * Schedule a draft (or reschedule a scheduled campaign).
	 *
	 * @param Campaign $campaign Campaign.
	 * @param string   $local    Datetime in the site timezone ("Y-m-d\TH:i").
	 * @return Campaign|\WP_Error
	 */
	public function schedule( Campaign $campaign, string $local ): Campaign|\WP_Error {
		if ( ! $campaign->is_editable() ) {
			return new \WP_Error( 'status', __( 'Only draft or scheduled campaigns can be scheduled.', 'ibg-client-outreach' ) );
		}

		$utc = Formatting::local_to_utc( $local );
		if ( ! $utc ) {
			return new \WP_Error( 'datetime', __( 'Enter a valid date and time.', 'ibg-client-outreach' ) );
		}
		if ( strtotime( $utc . ' UTC' ) <= time() ) {
			return new \WP_Error( 'datetime', __( 'The scheduled time must be in the future.', 'ibg-client-outreach' ) );
		}

		$ready = $this->assert_ready( $campaign );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		if ( ! $this->campaigns->transition( $campaign->id, $campaign->status, Campaign::STATUS_SCHEDULED, array( 'scheduled_at' => $utc ) ) ) {
			return new \WP_Error( 'race', __( 'The campaign changed in the meantime. Reload and try again.', 'ibg-client-outreach' ) );
		}

		$this->log( $campaign, 'campaign.scheduled', array( 'scheduled_at' => $utc ) );

		return $this->campaigns->find( $campaign->id );
	}

	/**
	 * Move a scheduled campaign back to draft.
	 *
	 * @param Campaign $campaign Campaign.
	 * @return Campaign|\WP_Error
	 */
	public function unschedule( Campaign $campaign ): Campaign|\WP_Error {
		if ( Campaign::STATUS_SCHEDULED !== $campaign->status ) {
			return new \WP_Error( 'status', __( 'The campaign is not scheduled.', 'ibg-client-outreach' ) );
		}
		if ( ! $this->campaigns->transition( $campaign->id, Campaign::STATUS_SCHEDULED, Campaign::STATUS_DRAFT, array( 'scheduled_at' => null ) ) ) {
			return new \WP_Error( 'race', __( 'The campaign changed in the meantime. Reload and try again.', 'ibg-client-outreach' ) );
		}
		$this->log( $campaign, 'campaign.unscheduled' );
		return $this->campaigns->find( $campaign->id );
	}

	/**
	 * Start sending: pre-flight, snapshot the template, mark processing and
	 * hand over to the queue via the started hook.
	 *
	 * @param Campaign $campaign Campaign.
	 * @param string   $trigger  admin|cron.
	 * @return Campaign|\WP_Error
	 */
	public function start( Campaign $campaign, string $trigger = 'admin' ): Campaign|\WP_Error {
		if ( ! $campaign->is_editable() ) {
			return new \WP_Error( 'status', __( 'Only draft or scheduled campaigns can be started.', 'ibg-client-outreach' ) );
		}

		if ( ! has_action( self::HOOK_STARTED ) ) {
			return new \WP_Error( 'no_engine', __( 'The sending engine (email queue) is not available, so the campaign was not started.', 'ibg-client-outreach' ) );
		}

		$ready = $this->assert_ready( $campaign );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$template = $this->templates->find( (int) $campaign->template_id );
		$summary  = $this->audience->summarize( $campaign );
		if ( ! $template || is_wp_error( $summary ) ) {
			return new \WP_Error( 'preflight', __( 'The campaign is not ready to start.', 'ibg-client-outreach' ) );
		}

		$now  = $this->db->now();
		$done = $this->campaigns->transition(
			$campaign->id,
			$campaign->status,
			Campaign::STATUS_PROCESSING,
			array(
				'started_at'       => $now,
				'scheduled_at'     => null,
				'body_html'        => $template->body_html,
				'body_text'        => $template->body_text,
				'total_recipients' => $summary['recipients'],
				'total_excluded'   => $summary['excluded'],
			)
		);
		if ( ! $done ) {
			return new \WP_Error( 'race', __( 'The campaign was already started or changed. Reload the page.', 'ibg-client-outreach' ) );
		}

		$started = $this->campaigns->find( $campaign->id );
		$this->log( $started, 'campaign.started', array( 'trigger' => $trigger, 'sends' => $summary['sends'] ) );

		/**
		 * Fires once a campaign has been moved to "processing". The queue module
		 * fills the email queue from Audience_Resolver::get_recipient_ids().
		 *
		 * @param Campaign $campaign Campaign (with body snapshot).
		 * @param string   $trigger  admin|cron.
		 */
		do_action( self::HOOK_STARTED, $started, $trigger );

		return $started;
	}

	/**
	 * Pause a processing campaign.
	 *
	 * @param Campaign $campaign Campaign.
	 * @return Campaign|\WP_Error
	 */
	public function pause( Campaign $campaign ): Campaign|\WP_Error {
		if ( ! $this->campaigns->transition( $campaign->id, Campaign::STATUS_PROCESSING, Campaign::STATUS_PAUSED ) ) {
			return new \WP_Error( 'status', __( 'Only a processing campaign can be paused.', 'ibg-client-outreach' ) );
		}
		$this->log( $campaign, 'campaign.paused' );
		do_action( self::HOOK_PAUSED, $campaign );
		return $this->campaigns->find( $campaign->id );
	}

	/**
	 * Resume a paused campaign.
	 *
	 * @param Campaign $campaign Campaign.
	 * @return Campaign|\WP_Error
	 */
	public function resume( Campaign $campaign ): Campaign|\WP_Error {
		if ( ! $this->campaigns->transition( $campaign->id, Campaign::STATUS_PAUSED, Campaign::STATUS_PROCESSING ) ) {
			return new \WP_Error( 'status', __( 'Only a paused campaign can be resumed.', 'ibg-client-outreach' ) );
		}
		$this->log( $campaign, 'campaign.resumed' );
		do_action( self::HOOK_RESUMED, $campaign );
		return $this->campaigns->find( $campaign->id );
	}

	/**
	 * Cancel a scheduled, processing or paused campaign.
	 *
	 * @param Campaign $campaign Campaign.
	 * @return Campaign|\WP_Error
	 */
	public function cancel( Campaign $campaign ): Campaign|\WP_Error {
		if ( ! in_array( $campaign->status, array( Campaign::STATUS_SCHEDULED, Campaign::STATUS_PROCESSING, Campaign::STATUS_PAUSED ), true ) ) {
			return new \WP_Error( 'status', __( 'This campaign cannot be cancelled.', 'ibg-client-outreach' ) );
		}
		if ( ! $this->campaigns->transition( $campaign->id, $campaign->status, Campaign::STATUS_CANCELLED, array( 'completed_at' => $this->db->now() ) ) ) {
			return new \WP_Error( 'race', __( 'The campaign changed in the meantime. Reload and try again.', 'ibg-client-outreach' ) );
		}
		$this->log( $campaign, 'campaign.cancelled' );
		/**
		 * Fires after a campaign is cancelled; pending queue rows are skipped by the queue module.
		 *
		 * @param Campaign $campaign Campaign.
		 */
		do_action( self::HOOK_CANCELLED, $campaign );
		return $this->campaigns->find( $campaign->id );
	}

	/**
	 * Mark a processing campaign completed (called by the queue when drained).
	 *
	 * @param Campaign $campaign Campaign.
	 * @return bool
	 */
	public function complete( Campaign $campaign ): bool {
		if ( ! $this->campaigns->transition( $campaign->id, Campaign::STATUS_PROCESSING, Campaign::STATUS_COMPLETED, array( 'completed_at' => $this->db->now() ) ) ) {
			return false;
		}
		$this->log( $campaign, 'campaign.completed' );
		do_action( self::HOOK_COMPLETED, $this->campaigns->find( $campaign->id ) );
		return true;
	}

	/**
	 * Write a campaign event.
	 *
	 * @param Campaign|null        $campaign Campaign.
	 * @param string               $type     Event type.
	 * @param array<string, mixed> $data     Data.
	 * @return void
	 */
	private function log( ?Campaign $campaign, string $type, array $data = array() ): void {
		if ( $campaign ) {
			$this->events->log( $type, 0, $data, $campaign->id );
		}
	}
}
