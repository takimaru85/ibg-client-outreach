<?php
/**
 * Schema installer and versioned migrations.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach;

defined( 'ABSPATH' ) || exit;

/**
 * Class Installer
 */
final class Installer {

	public const OPTION_DB_VERSION = 'ibg_outreach_db_version';

	/**
	 * Data migrations keyed by the DB version that introduces them.
	 *
	 * dbDelta handles column/index additions on its own; this map is for
	 * migrations that move or transform data. Each value is a method name.
	 *
	 * @var array<string, string>
	 */
	private const MIGRATIONS = array();

	/**
	 * Database helper.
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Constructor.
	 *
	 * @param Database $db Database helper.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Create or update all plugin tables and record the schema version.
	 *
	 * Safe to run repeatedly: dbDelta only applies differences.
	 *
	 * @return string[] Messages returned by dbDelta (useful for debugging).
	 */
	public function install(): array {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$messages = array();
		foreach ( $this->db->get_schema() as $sql ) {
			$messages = array_merge( $messages, dbDelta( $sql ) );
		}

		update_option( self::OPTION_DB_VERSION, IBG_OUTREACH_DB_VERSION, false );

		/**
		 * Fires after the plugin schema has been installed or updated.
		 *
		 * @param string[] $messages dbDelta output.
		 */
		do_action( 'ibg_outreach_schema_installed', $messages );

		return $messages;
	}

	/**
	 * Upgrade the schema if the stored version is behind the code version.
	 *
	 * Runs on admin requests only; plugin updates don't trigger activation hooks.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		$installed = (string) get_option( self::OPTION_DB_VERSION, '0' );

		if ( version_compare( $installed, IBG_OUTREACH_DB_VERSION, '>=' ) ) {
			return;
		}

		$this->install();
		$this->run_migrations( $installed );
	}

	/**
	 * Stored schema version.
	 *
	 * @return string
	 */
	public function get_installed_version(): string {
		return (string) get_option( self::OPTION_DB_VERSION, '0' );
	}

	/**
	 * Run data migrations newer than the previously installed version.
	 *
	 * @param string $from Previously installed DB version.
	 * @return void
	 */
	private function run_migrations( string $from ): void {
		foreach ( self::MIGRATIONS as $version => $method ) {
			if ( version_compare( $from, $version, '<' ) && method_exists( $this, $method ) ) {
				$this->{$method}();
			}
		}
	}
}
