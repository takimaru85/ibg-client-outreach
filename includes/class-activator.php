<?php
/**
 * Activation routine.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach;

defined( 'ABSPATH' ) || exit;

/**
 * Class Activator
 */
final class Activator {

	public const OPTION_SECRET    = 'ibg_outreach_secret_key';
	public const TRANSIENT_WELCOME = 'ibg_outreach_activated';

	/**
	 * Activation hook callback.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 * @return void
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install_site();
				restore_current_blog();
			}
			return;
		}

		self::install_site();
	}

	/**
	 * Install the plugin for the current site.
	 *
	 * @return void
	 */
	public static function install_site(): void {
		$plugin = Plugin::instance();

		$plugin->get( 'installer' )->install();
		$plugin->get( 'settings' )->ensure_defaults();

		$capabilities = $plugin->get( 'capabilities' );
		/**
		 * Filter the roles that receive the plugin capabilities on activation.
		 *
		 * @param string[] $roles Role slugs.
		 */
		$roles = apply_filters( 'ibg_outreach_default_roles', array( 'administrator' ) );
		foreach ( $roles as $role ) {
			$capabilities->grant_to_role( (string) $role );
		}

		// Plugin-specific secret for signing unsubscribe/tracking tokens.
		// Independent from WP salts so rotating either one does not break the other.
		if ( ! get_option( self::OPTION_SECRET ) ) {
			add_option( self::OPTION_SECRET, bin2hex( random_bytes( 32 ) ), '', 'no' );
		}

		set_transient( self::TRANSIENT_WELCOME, 1, MINUTE_IN_SECONDS );
	}
}
