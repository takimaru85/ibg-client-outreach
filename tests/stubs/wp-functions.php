<?php
/**
 * Minimal WordPress function stubs for unit tests (no WordPress loaded).
 *
 * Only the behaviour the pure classes depend on is reproduced. Anything
 * needing real WordPress belongs in the integration suite.
 *
 * @package IBG\Outreach
 */

declare( strict_types=1 );

// phpcs:disable WordPress.NamingConventions, Squiz.Commenting, Generic.Files.OneObjectStructurePerFile

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private array $errors = array();
		private array $data   = array();

		public function __construct( string $code = '', string $message = '', $data = null ) {
			if ( '' !== $code ) {
				$this->add( $code, $message, $data );
			}
		}

		public function add( string $code, string $message, $data = null ): void {
			$this->errors[ $code ][] = $message;
			if ( null !== $data ) {
				$this->data[ $code ] = $data;
			}
		}

		public function has_errors(): bool {
			return ! empty( $this->errors );
		}

		public function get_error_code(): string {
			return (string) ( array_key_first( $this->errors ) ?? '' );
		}

		public function get_error_codes(): array {
			return array_keys( $this->errors );
		}

		public function get_error_message( string $code = '' ): string {
			$code = '' !== $code ? $code : $this->get_error_code();
			return (string) ( $this->errors[ $code ][0] ?? '' );
		}

		public function get_error_messages(): array {
			return array_merge( ...array_values( $this->errors ) ?: array( array() ) );
		}

		public function get_error_data( string $code = '' ) {
			$code = '' !== $code ? $code : $this->get_error_code();
			return $this->data[ $code ] ?? null;
		}
	}
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function esc_html__( string $text, string $domain = 'default' ): string {
	return esc_html( $text );
}

function esc_html( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( string $url ): string {
	if ( '' === $url ) {
		return '';
	}
	if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $url ) && ! preg_match( '#^(https?|mailto|tel):#i', $url ) ) {
		return '';
	}
	return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
}

function esc_url_raw( string $url ): string {
	return $url;
}

function sanitize_text_field( $str ): string {
	$str = strip_tags( (string) $str );
	$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
	return trim( (string) $str );
}

function sanitize_textarea_field( $str ): string {
	return trim( strip_tags( (string) $str ) );
}

function sanitize_email( $email ): string {
	return (string) preg_replace( '/[^a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~@\-]/', '', (string) $email );
}

function sanitize_key( $key ): string {
	return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
}

function sanitize_title( string $title ): string {
	$title = strtolower( trim( strip_tags( $title ) ) );
	$title = (string) preg_replace( '/[^a-z0-9\s\-]/', '', $title );
	return trim( (string) preg_replace( '/[\s\-]+/', '-', $title ), '-' );
}

function sanitize_file_name( string $name ): string {
	return (string) preg_replace( '/[^A-Za-z0-9._\-]/', '', $name );
}

function is_email( $email ): bool {
	return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
}

function wp_strip_all_tags( string $string ): string {
	return trim( strip_tags( $string ) );
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}

function wp_http_validate_url( string $url ) {
	return preg_match( '#^https?://[^\s/$.?\#].[^\s]*$#i', $url ) ? $url : false;
}

function wp_date( string $format, ?int $timestamp = null ): string {
	return gmdate( $format, $timestamp ?? time() );
}

function current_time( string $type, bool $gmt = false ): string {
	return gmdate( 'Y-m-d H:i:s' );
}

function get_gmt_from_date( string $date, string $format = 'Y-m-d H:i:s' ): string {
	return gmdate( $format, (int) strtotime( $date . ' UTC' ) );
}

function get_date_from_gmt( string $date, string $format = 'Y-m-d H:i:s' ): string {
	return gmdate( $format, (int) strtotime( $date . ' UTC' ) );
}

function human_time_diff( int $from, int $to = 0 ): string {
	return (string) abs( ( $to ?: time() ) - $from ) . ' secs';
}

function get_option( string $key, $default = false ) {
	return $GLOBALS['ibg_test_options'][ $key ] ?? $default;
}

function add_option( string $key, $value = '', string $deprecated = '', $autoload = 'yes' ): bool {
	if ( isset( $GLOBALS['ibg_test_options'][ $key ] ) ) {
		return false;
	}
	$GLOBALS['ibg_test_options'][ $key ] = $value;
	return true;
}

function update_option( string $key, $value, $autoload = null ): bool {
	$GLOBALS['ibg_test_options'][ $key ] = $value;
	return true;
}

function get_bloginfo( string $show = '' ): string {
	return 'Test Site';
}

function get_locale(): string {
	return 'en_US';
}

function home_url( string $path = '' ): string {
	return 'https://example.test' . $path;
}

function add_query_arg( ...$args ): string {
	if ( is_array( $args[0] ) ) {
		$params = $args[0];
		$url    = (string) ( $args[1] ?? '' );
	} else {
		$params = array( $args[0] => $args[1] );
		$url    = (string) ( $args[2] ?? '' );
	}
	$parts    = explode( '?', $url, 2 );
	$existing = array();
	if ( isset( $parts[1] ) ) {
		parse_str( $parts[1], $existing );
	}
	$params = array_merge( $existing, $params );
	return $parts[0] . ( $params ? '?' . http_build_query( $params ) : '' );
}

function wp_salt( string $scheme = 'auth' ): string {
	return 'test-salt-' . $scheme . '-0123456789abcdefghijklmnopqrstuvwxyz';
}

function wp_kses( string $string, array $allowed ): string {
	return strip_tags( $string, '<' . implode( '><', array_keys( $allowed ) ) . '>' );
}

function wp_kses_allowed_html( string $context = '' ): array {
	return array(
		'a'      => array( 'href' => true, 'title' => true ),
		'p'      => array(),
		'br'     => array(),
		'strong' => array(),
		'em'     => array(),
		'ul'     => array(),
		'li'     => array(),
		'img'    => array( 'src' => true, 'alt' => true ),
		'table'  => array(),
		'tr'     => array(),
		'td'     => array(),
	);
}

function wpautop( string $text ): string {
	$paragraphs = preg_split( '/\n\s*\n/', trim( $text ) ) ?: array();
	return implode( '', array_map( static fn( string $p ): string => '<p>' . str_replace( "\n", '<br />', $p ) . '</p>', $paragraphs ) );
}

function nl2br_stub( string $s ): string {
	return nl2br( $s );
}

function apply_filters( string $tag, $value, ...$args ) {
	return $value;
}

function do_action( string $tag, ...$args ): void {}

function has_action( string $tag ): bool {
	return false;
}

function add_action( string $tag, $callback, int $priority = 10, int $accepted = 1 ): void {}

function add_filter( string $tag, $callback, int $priority = 10, int $accepted = 1 ): void {}

function has_filter( string $tag, $callback = false ): bool {
	return false;
}

function get_current_user_id(): int {
	return 1;
}

function number_format_i18n( $number, int $decimals = 0 ): string {
	return number_format( (float) $number, $decimals );
}

function wp_unslash( $value ) {
	return $value;
}

function absint( $value ): int {
	return abs( (int) $value );
}

function trailingslashit( string $s ): string {
	return rtrim( $s, '/\\' ) . '/';
}

function wp_delete_file( string $file ): void {
	if ( is_file( $file ) ) {
		unlink( $file );
	}
}

function size_format( int $bytes ): string {
	return $bytes . ' B';
}

function wp_max_upload_size(): int {
	return 50 * 1048576;
}

function wp_mkdir_p( string $dir ): bool {
	return is_dir( $dir ) || mkdir( $dir, 0777, true );
}

function wp_upload_dir(): array {
	$dir = sys_get_temp_dir() . '/ibg-tests-uploads';
	wp_mkdir_p( $dir );
	return array( 'basedir' => $dir, 'baseurl' => 'https://example.test/uploads', 'error' => false );
}
