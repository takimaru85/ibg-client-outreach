<?php
/**
 * PHPUnit bootstrap.
 *
 * Two modes:
 *  - Unit (default): no WordPress. A small set of WP function stubs and
 *    in-memory repository doubles let the pure classes run in isolation.
 *  - Integration: set WP_TESTS_DIR to the WordPress test suite; the plugin is
 *    loaded for real and the stubs are skipped.
 *
 * @package IBG\Outreach
 */

declare( strict_types=1 );

$ibg_plugin_dir = dirname( __DIR__ );

if ( getenv( 'WP_TESTS_DIR' ) ) {
	$ibg_tests_dir = rtrim( (string) getenv( 'WP_TESTS_DIR' ), '/\\' );
	require_once $ibg_tests_dir . '/includes/functions.php';
	tests_add_filter(
		'muplugins_loaded',
		static function () use ( $ibg_plugin_dir ): void {
			require $ibg_plugin_dir . '/ibg-client-outreach.php';
		}
	);
	require $ibg_tests_dir . '/includes/bootstrap.php';
	return;
}

define( 'ABSPATH', $ibg_plugin_dir . '/tests/stubs/' );
define( 'IBG_OUTREACH_PATH', $ibg_plugin_dir . '/' );
define( 'IBG_OUTREACH_VERSION', 'test' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MB_IN_BYTES', 1048576 );

require_once __DIR__ . '/stubs/wp-functions.php';
require_once __DIR__ . '/stubs/fakes.php';

require_once $ibg_plugin_dir . '/includes/class-autoloader.php';
\IBG\Outreach\Autoloader::register();
