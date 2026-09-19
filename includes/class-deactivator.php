<?php
/**
 * Deactivation routine.
 *
 * Deactivation must be non-destructive: no data or capabilities are removed.
 * Only scheduled events and runtime locks are cleared.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach;

defined( 'ABSPATH' ) || exit;

/**
 * Class Deactivator
 */
final class Deactivator {

	/**
	 * Cron hooks owned by the plugin (registered in later phases).
	 *
	 * @var string[]
	 */
	public const CRON_HOOKS = array(
		'ibg_outreach_process_queue',
		'ibg_outreach_cleanup',
	);

	/**
	 * Deactivation hook callback.
	 *
	 * @param bool $network_wide Whether the plugin is being network-deactivated.
	 * @return void
	 */
	public static function deactivate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::deactivate_site();
				restore_current_blog();
			}
			return;
		}

		self::deactivate_site();
	}

	/**
	 * Clear scheduled events and locks for the current site.
	 *
	 * @return void
	 */
	private static function deactivate_site(): void {
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		delete_transient( 'ibg_outreach_queue_lock' );
		delete_transient( Activator::TRANSIENT_WELCOME );
	}
}
