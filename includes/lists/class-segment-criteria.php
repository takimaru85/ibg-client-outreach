<?php
/**
 * Segment criteria: sanitisation and description of saved filters.
 *
 * The criteria shape is exactly the argument shape accepted by
 * Contact_Repository::build_conditions(), so segments, the contacts table
 * and campaign audiences all run the same SQL.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Lists;

use IBG\Outreach\Contacts\Contact;

defined( 'ABSPATH' ) || exit;

/**
 * Class Segment_Criteria
 */
final class Segment_Criteria {

	/**
	 * Keys that accept multiple values.
	 *
	 * @var string[]
	 */
	public const MULTI_KEYS = array( 'contact_status', 'marketing_status', 'industry', 'country', 'source', 'consent_basis' );

	/**
	 * Sanitise raw criteria (from a form or JSON). Unknown keys are dropped,
	 * empty values removed.
	 *
	 * @param array<string, mixed> $raw Raw criteria.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $raw ): array {
		$clean = array();

		foreach ( self::MULTI_KEYS as $key ) {
			if ( empty( $raw[ $key ] ) ) {
				continue;
			}
			$values = array_values(
				array_filter(
					array_map( static fn( $v ): string => sanitize_text_field( (string) $v ), (array) $raw[ $key ] ),
					'strlen'
				)
			);
			if ( ! empty( $values ) ) {
				$clean[ $key ] = array_values( array_unique( $values ) );
			}
		}

		if ( ! empty( $raw['list_id'] ) ) {
			$clean['list_id'] = absint( $raw['list_id'] );
		}

		foreach ( array( 'date_from', 'date_to' ) as $key ) {
			$value = isset( $raw[ $key ] ) ? trim( (string) $raw[ $key ] ) : '';
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				$clean[ $key ] = $value;
			}
		}

		$search = isset( $raw['search'] ) ? sanitize_text_field( (string) $raw['search'] ) : '';
		if ( '' !== $search ) {
			$clean['search'] = $search;
		}

		if ( ! empty( $raw['mailable_only'] ) ) {
			$clean['mailable_only'] = true;
		}

		return $clean;
	}

	/**
	 * Convert criteria to Contact_Repository query args.
	 *
	 * @param array<string, mixed> $criteria Sanitised criteria.
	 * @return array<string, mixed>
	 */
	public static function to_query_args( array $criteria ): array {
		return self::sanitize( $criteria );
	}

	/**
	 * Human-readable summary lines.
	 *
	 * @param array<string, mixed>       $criteria Criteria.
	 * @param array<int, Contact_List>   $lists    Lists keyed by id (to name list_id).
	 * @return string[]
	 */
	public static function describe( array $criteria, array $lists = array() ): array {
		$lines  = array();
		$labels = array(
			'contact_status'   => array( __( 'Status', 'ibg-client-outreach' ), Contact::contact_statuses() ),
			'marketing_status' => array( __( 'Marketing', 'ibg-client-outreach' ), Contact::marketing_statuses() ),
			'consent_basis'    => array( __( 'Lawful basis', 'ibg-client-outreach' ), Contact::consent_bases() ),
			'industry'         => array( __( 'Industry', 'ibg-client-outreach' ), array() ),
			'country'          => array( __( 'Country', 'ibg-client-outreach' ), array() ),
			'source'           => array( __( 'Source', 'ibg-client-outreach' ), array() ),
		);

		foreach ( $labels as $key => list( $label, $map ) ) {
			if ( empty( $criteria[ $key ] ) ) {
				continue;
			}
			$values  = array_map( static fn( $v ): string => $map ? Contact::label( $map, (string) $v ) : (string) $v, (array) $criteria[ $key ] );
			$lines[] = $label . ': ' . implode( ', ', $values );
		}

		if ( ! empty( $criteria['list_id'] ) ) {
			$id      = (int) $criteria['list_id'];
			$name    = isset( $lists[ $id ] ) ? $lists[ $id ]->name : '#' . $id;
			$lines[] = __( 'Member of list', 'ibg-client-outreach' ) . ': ' . $name;
		}

		if ( ! empty( $criteria['date_from'] ) || ! empty( $criteria['date_to'] ) ) {
			$lines[] = sprintf(
				/* translators: 1: from date, 2: to date */
				__( 'Added: %1$s – %2$s', 'ibg-client-outreach' ),
				$criteria['date_from'] ?? '…',
				$criteria['date_to'] ?? '…'
			);
		}

		if ( ! empty( $criteria['search'] ) ) {
			$lines[] = __( 'Search', 'ibg-client-outreach' ) . ': "' . $criteria['search'] . '"';
		}

		if ( ! empty( $criteria['mailable_only'] ) ) {
			$lines[] = __( 'Mailable contacts only', 'ibg-client-outreach' );
		}

		return $lines;
	}
}
