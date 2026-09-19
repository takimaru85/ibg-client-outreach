<?php
/**
 * Uninstall handler.
 *
 * Runs when the plugin is deleted from the Plugins screen. Data is removed
 * only when the "Delete data on uninstall" setting is enabled; otherwise
 * contacts, campaigns and logs are preserved for reinstallation.
 * Capabilities and scheduled events are always removed.
 *
 * @package IBG\Outreach
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'IBG_OUTREACH_PATH' ) ) {
	define( 'IBG_OUTREACH_PATH', plugin_dir_path( __FILE__ ) );
}

require_once IBG_OUTREACH_PATH . 'includes/class-autoloader.php';
\IBG\Outreach\Autoloader::register();

/**
 * Uninstall the plugin for the current site.
 *
 * @return void
 */
function ibg_outreach_uninstall_site(): void {
	global $wpdb;

	$settings    = get_option( \IBG\Outreach\Settings::OPTION, array() );
	$delete_data = is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );

	foreach ( \IBG\Outreach\Deactivator::CRON_HOOKS as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	( new \IBG\Outreach\Capabilities() )->revoke_from_all_roles();

	if ( ! $delete_data ) {
		return;
	}

	$database = new \IBG\Outreach\Database( $wpdb );
	foreach ( \IBG\Outreach\Database::TABLES as $name ) {
		$table = $database->table( $name );
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	delete_option( \IBG\Outreach\Settings::OPTION );
	delete_option( \IBG\Outreach\Installer::OPTION_DB_VERSION );
	delete_option( \IBG\Outreach\Activator::OPTION_SECRET );

	// Transients and user meta written by the plugin.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ibg\\_outreach\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ibg\\_outreach\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'ibg\\_outreach\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

if ( is_multisite() ) {
	$ibg_site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $ibg_site_ids as $ibg_site_id ) {
		switch_to_blog( (int) $ibg_site_id );
		ibg_outreach_uninstall_site();
		restore_current_blog();
	}
} else {
	ibg_outreach_uninstall_site();
}
