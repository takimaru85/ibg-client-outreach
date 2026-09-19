<?php
/**
 * Date/time formatting helpers.
 *
 * The database stores UTC; the admin UI displays the site timezone.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach;

defined( 'ABSPATH' ) || exit;

/**
 * Class Formatting
 */
final class Formatting {

	/**
	 * Format a UTC MySQL datetime in the site timezone.
	 *
	 * @param string|null $utc    UTC datetime (Y-m-d H:i:s) or null.
	 * @param string      $format PHP date format; defaults to the site date + time format.
	 * @return string Formatted date, or an em dash when empty.
	 */
	public static function datetime( ?string $utc, string $format = '' ): string {
		if ( empty( $utc ) || '0000-00-00 00:00:00' === $utc ) {
			return '—';
		}

		$timestamp = strtotime( $utc . ' UTC' );
		if ( false === $timestamp ) {
			return '—';
		}

		if ( '' === $format ) {
			$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		}

		return wp_date( $format, $timestamp );
	}

	/**
	 * Human-readable relative time ("3 days ago").
	 *
	 * @param string|null $utc UTC datetime.
	 * @return string
	 */
	public static function relative( ?string $utc ): string {
		if ( empty( $utc ) || '0000-00-00 00:00:00' === $utc ) {
			return '—';
		}
		$timestamp = strtotime( $utc . ' UTC' );
		if ( false === $timestamp ) {
			return '—';
		}
		/* translators: %s: human-readable time difference */
		return sprintf( __( '%s ago', 'ibg-client-outreach' ), human_time_diff( $timestamp, time() ) );
	}

	/**
	 * Convert a date or datetime entered in the site timezone to UTC MySQL format.
	 *
	 * Accepts "Y-m-d", "Y-m-d H:i", "Y-m-d\TH:i" and "Y-m-d H:i:s".
	 *
	 * @param string $input User input.
	 * @return string|null UTC datetime or null when empty/invalid.
	 */
	public static function local_to_utc( string $input ): ?string {
		$input = trim( str_replace( 'T', ' ', $input ) );
		if ( '' === $input ) {
			return null;
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $input ) ) {
			$input .= ' 00:00:00';
		} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $input ) ) {
			$input .= ':00';
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $input ) ) {
			return null;
		}

		$utc = get_gmt_from_date( $input, 'Y-m-d H:i:s' );

		return $utc ? $utc : null;
	}

	/**
	 * Convert a UTC datetime to a value for an <input type="date">.
	 *
	 * @param string|null $utc UTC datetime.
	 * @return string "Y-m-d" in the site timezone, or empty string.
	 */
	public static function utc_to_local_date( ?string $utc ): string {
		if ( empty( $utc ) || '0000-00-00 00:00:00' === $utc ) {
			return '';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $utc ) ) {
			return $utc; // Already a bare date (e.g. re-rendering submitted input).
		}
		return (string) get_date_from_gmt( $utc, 'Y-m-d' );
	}
}
