<?php
/**
 * HTML → plain text conversion for the text/plain alternative.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Class Html_To_Text
 */
final class Html_To_Text {

	/**
	 * Convert HTML to readable plain text.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function convert( string $html ): string {
		$text = $html;

		// Drop non-content blocks entirely.
		$text = (string) preg_replace( '#<(head|style|script|title)\b[^>]*>.*?</\1>#is', '', $text );
		$text = (string) preg_replace( '#<!--.*?-->#s', '', $text );

		// Links: "text (url)" unless the text already is the URL.
		$text = (string) preg_replace_callback(
			'#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
			static function ( array $m ): string {
				$url   = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
				$label = trim( wp_strip_all_tags( $m[2] ) );
				if ( '' === $label || $label === $url || str_starts_with( $url, 'mailto:' ) && str_contains( $label, '@' ) ) {
					return '' === $label ? $url : $label;
				}
				return $label . ' (' . $url . ')';
			},
			$text
		);

		// Images: alt text.
		$text = (string) preg_replace( '#<img\b[^>]*alt=["\']([^"\']*)["\'][^>]*>#i', '$1', $text );
		$text = (string) preg_replace( '#<img\b[^>]*>#i', '', $text );

		// Line structure.
		$text = (string) preg_replace( '#<br\s*/?>#i', "\n", $text );
		$text = (string) preg_replace( '#<li\b[^>]*>#i', "\n- ", $text );
		$text = (string) preg_replace( '#</li>#i', '', $text );
		$text = (string) preg_replace( '#</(p|div|h[1-6]|tr|table|ul|ol|blockquote|section|article|header|footer)>#i', "\n\n", $text );
		$text = (string) preg_replace( '#<hr\b[^>]*>#i', "\n----------\n", $text );
		$text = (string) preg_replace( '#</t[dh]>#i', "\t", $text );

		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text ); // nbsp.

		// Tidy whitespace.
		$text = (string) preg_replace( "/[ \t]+\n/", "\n", $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );
		$text = (string) preg_replace( '/^[ \t]+/m', '', $text );

		return trim( $text );
	}
}
