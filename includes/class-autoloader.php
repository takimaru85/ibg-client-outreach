<?php
/**
 * PSR-4 style autoloader that maps the IBG\Outreach namespace onto
 * WordPress-style file names.
 *
 * Mapping rules:
 *   IBG\Outreach\Foo_Bar                    -> includes/class-foo-bar.php
 *   IBG\Outreach\Email\Provider_Registry    -> includes/email/class-provider-registry.php
 *   IBG\Outreach\Email\Providers\Email_Provider (interface)
 *                                           -> includes/email/providers/interface-email-provider.php
 *   IBG\Outreach\Admin\Pages\Settings_Page  -> admin/pages/class-settings-page.php
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach;

defined( 'ABSPATH' ) || exit;

/**
 * Class Autoloader
 */
final class Autoloader {

	private const PREFIX = 'IBG\\Outreach\\';

	/**
	 * Register the autoloader with SPL.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Resolve and load a class, interface or trait.
	 *
	 * @param string $class Fully-qualified class name.
	 * @return void
	 */
	public static function autoload( string $class ): void {
		if ( 0 !== strncmp( $class, self::PREFIX, strlen( self::PREFIX ) ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );

		// The Admin namespace lives in /admin, everything else in /includes.
		if ( isset( $parts[0] ) && 'Admin' === $parts[0] ) {
			array_shift( $parts );
			$base = IBG_OUTREACH_PATH . 'admin/';
		} else {
			$base = IBG_OUTREACH_PATH . 'includes/';
		}

		$directory = $base;
		if ( ! empty( $parts ) ) {
			$directory .= strtolower( implode( '/', $parts ) ) . '/';
		}

		$slug = strtolower( str_replace( '_', '-', $name ) );

		foreach ( array( 'class-', 'interface-', 'trait-' ) as $file_prefix ) {
			$file = $directory . $file_prefix . $slug . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
}
