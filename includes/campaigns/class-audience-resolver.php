<?php
/**
 * Resolves a campaign's audience into Contact_Repository query args and counts.
 *
 * Two layers:
 *   base args  – who the campaign targets (list / segment / everyone + ad-hoc criteria)
 *   send args  – base args plus the non-negotiable exclusions (unsubscribed,
 *                do-not-contact, invalid email) and the campaign's consent scope.
 *
 * The queue is filled from the same send args in Phase 7, so the pre-flight
 * "Estimated sends" number and the actual recipient set are identical.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Campaigns;

use IBG\Outreach\Contacts\Contact;
use IBG\Outreach\Contacts\Contact_Repository;
use IBG\Outreach\Lists\List_Repository;
use IBG\Outreach\Lists\List_Service;
use IBG\Outreach\Lists\Segment_Criteria;

defined( 'ABSPATH' ) || exit;

/**
 * Class Audience_Resolver
 */
final class Audience_Resolver {

	/**
	 * Constructor.
	 *
	 * @param Contact_Repository $contacts     Contacts.
	 * @param List_Repository    $lists        Lists.
	 * @param List_Service       $list_service List service.
	 */
	public function __construct(
		private readonly Contact_Repository $contacts,
		private readonly List_Repository $lists,
		private readonly List_Service $list_service
	) {}

	/**
	 * Base query args (who is targeted, before exclusions).
	 *
	 * @param Campaign $campaign Campaign.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_base_args( Campaign $campaign ): array|\WP_Error {
		$args = array();

		if ( null !== $campaign->list_id && $campaign->list_id > 0 ) {
			$list = $this->lists->find( $campaign->list_id );
			if ( ! $list ) {
				return new \WP_Error( 'list_missing', __( 'The target list or segment no longer exists.', 'ibg-client-outreach' ) );
			}
			$args = $this->list_service->get_audience_args( $list );
		}

		if ( ! empty( $campaign->criteria ) ) {
			$args = array_merge( $args, Segment_Criteria::to_query_args( $campaign->criteria ) );
		}

		return $args;
	}

	/**
	 * Query args for the contacts that will actually be sent to.
	 *
	 * @param Campaign $campaign Campaign.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get_send_args( Campaign $campaign ): array|\WP_Error {
		$args = $this->get_base_args( $campaign );
		if ( is_wp_error( $args ) ) {
			return $args;
		}

		$allowed = Campaign::SCOPE_SUBSCRIBED_PENDING === $campaign->scope
			? array( Contact::MARKETING_SUBSCRIBED, Contact::MARKETING_PENDING )
			: array( Contact::MARKETING_SUBSCRIBED );

		// A segment may already restrict marketing status; intersect rather than override
		// so a segment can narrow the scope but never widen it.
		if ( ! empty( $args['marketing_status'] ) ) {
			$allowed = array_values( array_intersect( $allowed, array_map( 'strval', (array) $args['marketing_status'] ) ) );
			if ( empty( $allowed ) ) {
				$args['ids'] = array( 0 ); // Nothing can match.
			}
		}

		$args['marketing_status'] = $allowed;
		$args['mailable_only']    = true;

		/**
		 * Filter the query args used to select a campaign's recipients.
		 * Filters may only narrow the audience; the suppression exclusions
		 * are re-applied by the queue worker at send time regardless.
		 *
		 * @param array    $args     Query args.
		 * @param Campaign $campaign Campaign.
		 */
		return (array) apply_filters( 'ibg_outreach_campaign_send_args', $args, $campaign );
	}

	/**
	 * Pre-flight numbers: recipients, excluded (with breakdown), estimated sends.
	 *
	 * @param Campaign $campaign Campaign.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function summarize( Campaign $campaign ): array|\WP_Error {
		$base = $this->get_base_args( $campaign );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$send = $this->get_send_args( $campaign );
		if ( is_wp_error( $send ) ) {
			return $send;
		}

		$total    = $this->contacts->count( $base );
		$sendable = $this->contacts->count( $send );

		$by_marketing = $this->contacts->count_by( 'marketing_status', $base );
		$by_email     = $this->contacts->count_by( 'email_status', $base );

		$breakdown = array(
			'unsubscribed'   => $by_marketing[ Contact::MARKETING_UNSUBSCRIBED ] ?? 0,
			'do_not_contact' => $by_marketing[ Contact::MARKETING_DNC ] ?? 0,
			'pending'        => Campaign::SCOPE_SUBSCRIBED === $campaign->scope ? ( $by_marketing[ Contact::MARKETING_PENDING ] ?? 0 ) : 0,
			'invalid_email'  => ( $by_email[ Contact::EMAIL_INVALID ] ?? 0 ) + ( $by_email[ Contact::EMAIL_BOUNCED ] ?? 0 ),
		);

		return array(
			'recipients' => $total,
			'excluded'   => max( 0, $total - $sendable ),
			'sends'      => $sendable,
			'breakdown'  => $breakdown,
		);
	}

	/**
	 * Recipient ids for the queue, in stable id order, paged.
	 *
	 * @param Campaign $campaign Campaign.
	 * @param int      $after_id Only ids greater than this (keyset pagination).
	 * @param int      $limit    Max ids.
	 * @return int[]|\WP_Error
	 */
	public function get_recipient_ids( Campaign $campaign, int $after_id = 0, int $limit = 1000 ): array|\WP_Error {
		$args = $this->get_send_args( $campaign );
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		return $this->contacts->get_ids( $args, $after_id, $limit );
	}
}
