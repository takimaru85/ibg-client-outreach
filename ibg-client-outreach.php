<?php
/**
 * Plugin Name:       IBG Client Outreach
 * Plugin URI:        https://ibgolden.com/
 * Description:       A lightweight CRM and permission-aware email outreach system for managing business contacts and sending compliant campaigns.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Ian Olden, IB Golden
 * Author URI:        https://ibgolden.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ibg-client-outreach
 * Domain Path:       /languages
 *
 * @package IBG\Outreach
 */

defined( 'ABSPATH' ) || exit;

define( 'IBG_OUTREACH_VERSION', '1.0.0' );
define( 'IBG_OUTREACH_DB_VERSION', '1.1.0' );
define( 'IBG_OUTREACH_FILE', __FILE__ );
define( 'IBG_OUTREACH_PATH', plugin_dir_path( __FILE__ ) );
define( 'IBG_OUTREACH_URL', plugin_dir_url( __FILE__ ) );
define( 'IBG_OUTREACH_BASENAME', plugin_basename( __FILE__ ) );
define( 'IBG_OUTREACH_MIN_PHP', '8.1' );
define( 'IBG_OUTREACH_MIN_WP', '6.4' );

/**
 * Whether the current environment satisfies the plugin's minimum requirements.
 *
 * Deliberately free of translation calls: this runs before the text domain is
 * loaded and WordPress 6.7+ warns about early translation loading.
 *
 * @return bool
 */
function ibg_outreach_requirements_met(): bool {
	return version_compare( PHP_VERSION, IBG_OUTREACH_MIN_PHP, '>=' )
		&& version_compare( $GLOBALS['wp_version'], IBG_OUTREACH_MIN_WP, '>=' );
}

/**
 * Human-readable list of unmet requirements. Safe to translate (runs after init).
 *
 * @return string[]
 */
function ibg_outreach_requirement_errors(): array {
	$errors = array();

	if ( version_compare( PHP_VERSION, IBG_OUTREACH_MIN_PHP, '<' ) ) {
		$errors[] = sprintf(
			/* translators: 1: required PHP version, 2: current PHP version */
			__( 'IBG Client Outreach requires PHP %1$s or newer. This server is running PHP %2$s.', 'ibg-client-outreach' ),
			IBG_OUTREACH_MIN_PHP,
			PHP_VERSION
		);
	}

	if ( version_compare( $GLOBALS['wp_version'], IBG_OUTREACH_MIN_WP, '<' ) ) {
		$errors[] = sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version */
			__( 'IBG Client Outreach requires WordPress %1$s or newer. This site is running WordPress %2$s.', 'ibg-client-outreach' ),
			IBG_OUTREACH_MIN_WP,
			$GLOBALS['wp_version']
		);
	}

	return $errors;
}

/**
 * Admin notice shown when requirements are not met.
 *
 * @return void
 */
function ibg_outreach_requirements_notice(): void {
	foreach ( ibg_outreach_requirement_errors() as $error ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
	}
}

/**
 * Block activation on unsupported environments.
 *
 * @return void
 */
function ibg_outreach_block_activation(): void {
	wp_die(
		esc_html( implode( ' ', ibg_outreach_requirement_errors() ) ),
		esc_html__( 'Plugin activation failed', 'ibg-client-outreach' ),
		array( 'back_link' => true )
	);
}

if ( ! ibg_outreach_requirements_met() ) {
	add_action( 'admin_notices', 'ibg_outreach_requirements_notice' );
	register_activation_hook( __FILE__, 'ibg_outreach_block_activation' );
	return;
}

require_once IBG_OUTREACH_PATH . 'includes/class-autoloader.php';
\IBG\Outreach\Autoloader::register();

register_activation_hook( __FILE__, array( \IBG\Outreach\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \IBG\Outreach\Deactivator::class, 'deactivate' ) );

/**
 * Access the plugin container.
 *
 * @return \IBG\Outreach\Plugin
 */
function ibg_outreach(): \IBG\Outreach\Plugin {
	return \IBG\Outreach\Plugin::instance();
}

add_action( 'plugins_loaded', static function (): void {
	ibg_outreach()->boot();
}, 5 );
