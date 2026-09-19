<?php
/**
 * Custom capabilities.
 *
 * Managing and sending are separate capabilities so a future assistant role
 * could draft campaigns without being able to send them.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach;

defined( 'ABSPATH' ) || exit;

/**
 * Class Capabilities
 */
final class Capabilities {

	/** Access the IBG Outreach menu, dashboard, queue and logs. */
	public const VIEW = 'ibg_view_outreach';

	/** Create, edit and delete contacts and lists. */
	public const MANAGE_CONTACTS = 'ibg_manage_contacts';

	/** Upload and import CSV files. */
	public const IMPORT_CONTACTS = 'ibg_import_contacts';

	/** Create and edit campaigns and templates. */
	public const MANAGE_CAMPAIGNS = 'ibg_manage_campaigns';

	/** Start, schedule, pause and cancel campaigns; send test emails. */
	public const SEND_CAMPAIGNS = 'ibg_send_campaigns';

	/** Change plugin settings and run maintenance tools. */
	public const MANAGE_SETTINGS = 'ibg_manage_settings';

	/**
	 * All plugin capabilities.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::VIEW,
			self::MANAGE_CONTACTS,
			self::IMPORT_CONTACTS,
			self::MANAGE_CAMPAIGNS,
			self::SEND_CAMPAIGNS,
			self::MANAGE_SETTINGS,
		);
	}

	/**
	 * Grant every plugin capability to a role.
	 *
	 * @param string $role_name Role slug.
	 * @return void
	 */
	public function grant_to_role( string $role_name ): void {
		$role = get_role( $role_name );
		if ( ! $role instanceof \WP_Role ) {
			return;
		}
		foreach ( self::all() as $cap ) {
			$role->add_cap( $cap );
		}
	}

	/**
	 * Remove every plugin capability from every role. Used on uninstall.
	 *
	 * @return void
	 */
	public function revoke_from_all_roles(): void {
		$roles = wp_roles();
		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role instanceof \WP_Role ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
