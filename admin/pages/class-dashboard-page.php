<?php
/**
 * Dashboard page.
 *
 * Phase 1 shows system status and setup guidance. Contact, campaign and email
 * statistics are added in Phase 10.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Admin\Pages;

use IBG\Outreach\Admin\Menu;
use IBG\Outreach\Capabilities;
use IBG\Outreach\Database;
use IBG\Outreach\Email\Provider_Registry;
use IBG\Outreach\Installer;
use IBG\Outreach\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Dashboard_Page
 */
final class Dashboard_Page extends Abstract_Page {

	public const SLUG              = Menu::PARENT_SLUG;
	public const ACTION_REPAIR     = 'ibg_repair_tables';
	public const NOTICE_COMPLIANCE = 'compliance_responsibility';

	/** @inheritDoc */
	public function get_slug(): string {
		return self::SLUG;
	}

	/** @inheritDoc */
	public function get_page_title(): string {
		return __( 'IBG Client Outreach', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_menu_title(): string {
		return __( 'Dashboard', 'ibg-client-outreach' );
	}

	/** @inheritDoc */
	public function get_capability(): string {
		return Capabilities::VIEW;
	}

	/** @inheritDoc */
	public function get_position(): int {
		return 10;
	}

	/**
	 * Handle the "repair tables" action.
	 *
	 * @return void
	 */
	public function load(): void {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( self::ACTION_REPAIR !== $action ) {
			return;
		}

		$this->require_capability( Capabilities::MANAGE_SETTINGS );
		check_admin_referer( self::ACTION_REPAIR );

		/** @var Installer $installer */
		$installer = $this->plugin->get( 'installer' );
		$installer->install();

		/** @var Database $db */
		$db      = $this->plugin->get( 'database' );
		$missing = $db->get_missing_tables();

		if ( empty( $missing ) ) {
			$this->notices->add( __( 'Database tables verified and repaired.', 'ibg-client-outreach' ), 'success' );
		} else {
			$this->notices->add(
				sprintf(
					/* translators: %s: comma-separated table names */
					__( 'Some tables could not be created: %s. Check the database user has CREATE privileges.', 'ibg-client-outreach' ),
					implode( ', ', $missing )
				),
				'error'
			);
		}

		wp_safe_redirect( $this->get_url() );
		exit;
	}

	/** @inheritDoc */
	public function render(): void {
		$this->require_capability();

		$this->render_view(
			'dashboard',
			array(
				'status'               => $this->get_status_rows(),
				'has_missing_tables'   => ! empty( $this->plugin->get( 'database' )->get_missing_tables() ),
				'repair_url'           => wp_nonce_url( $this->get_url( array( 'action' => self::ACTION_REPAIR ) ), self::ACTION_REPAIR ),
				'can_repair'           => current_user_can( Capabilities::MANAGE_SETTINGS ),
				'settings_url'         => add_query_arg( 'page', Settings_Page::SLUG, admin_url( 'admin.php' ) ),
				'compliance_dismissed' => $this->notices->is_dismissed( self::NOTICE_COMPLIANCE ),
				'compliance_dismiss'   => $this->notices->get_dismiss_url( self::NOTICE_COMPLIANCE ),
			)
		);
	}

	/**
	 * Build the system status rows.
	 *
	 * @return array<int, array{label:string, value:string, state:string, help:string}>
	 */
	private function get_status_rows(): array {
		/** @var Database $db */
		$db = $this->plugin->get( 'database' );
		/** @var Installer $installer */
		$installer = $this->plugin->get( 'installer' );
		/** @var Settings $settings */
		$settings = $this->plugin->get( 'settings' );
		/** @var Provider_Registry $providers */
		$providers = $this->plugin->get( 'providers' );

		$rows    = array();
		$missing = $db->get_missing_tables();

		$rows[] = array(
			'label' => __( 'Database tables', 'ibg-client-outreach' ),
			'value' => empty( $missing )
				? sprintf(
					/* translators: %d: number of tables */
					__( 'All %d tables present', 'ibg-client-outreach' ),
					count( Database::TABLES )
				)
				: sprintf(
					/* translators: %s: comma-separated table names */
					__( 'Missing: %s', 'ibg-client-outreach' ),
					implode( ', ', $missing )
				),
			'state' => empty( $missing ) ? 'ok' : 'error',
			'help'  => '',
		);

		$rows[] = array(
			'label' => __( 'Schema version', 'ibg-client-outreach' ),
			'value' => sprintf( '%s (%s %s)', $installer->get_installed_version(), __( 'plugin', 'ibg-client-outreach' ), IBG_OUTREACH_VERSION ),
			'state' => version_compare( $installer->get_installed_version(), IBG_OUTREACH_DB_VERSION, '>=' ) ? 'ok' : 'warning',
			'help'  => '',
		);

		$provider = $providers->get_active();
		$rows[]   = array(
			'label' => __( 'Email provider', 'ibg-client-outreach' ),
			'value' => $provider->get_name(),
			'state' => $provider->is_configured() ? 'ok' : 'error',
			'help'  => $provider->is_configured() ? '' : __( 'The selected provider is not configured.', 'ibg-client-outreach' ),
		);

		$from_email = (string) $settings->get( 'from_email' );
		$rows[]     = array(
			'label' => __( 'From email', 'ibg-client-outreach' ),
			'value' => '' !== $from_email ? $from_email : __( 'Not set', 'ibg-client-outreach' ),
			'state' => is_email( $from_email ) ? 'ok' : 'error',
			'help'  => __( 'Must be on a domain you control with SPF/DKIM/DMARC configured.', 'ibg-client-outreach' ),
		);

		$address = trim( (string) $settings->get( 'business_address' ) );
		$rows[]  = array(
			'label' => __( 'Business address', 'ibg-client-outreach' ),
			'value' => '' !== $address ? __( 'Set', 'ibg-client-outreach' ) : __( 'Not set', 'ibg-client-outreach' ),
			'state' => '' !== $address ? 'ok' : 'error',
			'help'  => __( 'A physical postal address is required in every marketing email. Campaigns cannot be started until this is set.', 'ibg-client-outreach' ),
		);

		$privacy = (string) $settings->get( 'privacy_policy_url' );
		$rows[]  = array(
			'label' => __( 'Privacy policy URL', 'ibg-client-outreach' ),
			'value' => '' !== $privacy ? $privacy : __( 'Not set', 'ibg-client-outreach' ),
			'state' => '' !== $privacy ? 'ok' : 'warning',
			'help'  => '',
		);

		$cron   = \IBG\Outreach\Queue\Cron::get_status();
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$rows[] = array(
			'label' => __( 'Email queue (WP-Cron)', 'ibg-client-outreach' ),
			'value' => sprintf(
				/* translators: 1: next run, 2: last run */
				__( 'Next run %1$s · last run %2$s', 'ibg-client-outreach' ),
				$cron['next_run'] ? wp_date( $format, (int) $cron['next_run'] ) : __( 'not scheduled', 'ibg-client-outreach' ),
				$cron['last_run'] ? wp_date( $format, (int) $cron['last_run'] ) : __( 'never', 'ibg-client-outreach' )
			),
			'state' => $cron['next_run'] ? ( $cron['disabled'] ? 'ok' : 'info' ) : 'error',
			'help'  => $cron['disabled']
				? __( 'DISABLE_WP_CRON is set: make sure a system cron runs wp-cron.php every minute.', 'ibg-client-outreach' )
				: __( 'WP-Cron is triggered by site traffic, so sending pauses when nobody visits. For reliable delivery set DISABLE_WP_CRON and run wp-cron.php from a real cron every minute.', 'ibg-client-outreach' ),
		);

		$rows[] = array(
			'label' => __( 'Environment', 'ibg-client-outreach' ),
			'value' => sprintf( 'PHP %s · WordPress %s', PHP_VERSION, $GLOBALS['wp_version'] ),
			'state' => 'ok',
			'help'  => '',
		);

		return $rows;
	}
}
