<?php
/**
 * WP-Cron integration: queue processing every minute, cleanup daily.
 *
 * Handlers resolve their services lazily so front-end requests that merely
 * register the hooks pay nothing.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Queue;

use IBG\Outreach\Campaigns\Campaign_Service;
use IBG\Outreach\Import\Import_Storage;
use IBG\Outreach\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cron
 */
final class Cron {

	public const HOOK_PROCESS = 'ibg_outreach_process_queue';
	public const HOOK_CLEANUP = 'ibg_outreach_cleanup';
	public const SCHEDULE     = 'ibg_outreach_every_minute';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container (services resolved lazily).
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		self::register_schedule();
		add_action( self::HOOK_PROCESS, array( $this, 'process' ) );
		add_action( self::HOOK_CLEANUP, array( $this, 'cleanup' ) );
		add_action( 'init', array( __CLASS__, 'schedule_events' ) );
	}

	/**
	 * Register the custom interval (idempotent; also needed during activation,
	 * which runs before the plugin has booted).
	 *
	 * @return void
	 */
	private static function register_schedule(): void {
		if ( ! has_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ) ) {
			add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- one-minute interval is the documented design.
		}
	}

	/**
	 * Add the one-minute schedule.
	 *
	 * @param array<string, array<string, mixed>> $schedules Schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (IBG Outreach)', 'ibg-client-outreach' ),
		);
		return $schedules;
	}

	/**
	 * Make sure both events exist (activation, and self-healing on init).
	 *
	 * @return void
	 */
	public static function schedule_events(): void {
		self::register_schedule();
		if ( ! wp_next_scheduled( self::HOOK_PROCESS ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK_PROCESS );
		}
		if ( ! wp_next_scheduled( self::HOOK_CLEANUP ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_CLEANUP );
		}
	}

	/**
	 * Every minute: start due scheduled campaigns, then send a batch.
	 *
	 * @return void
	 */
	public function process(): void {
		$this->dispatch_scheduled();
		$this->plugin->get( 'queue_worker' )->run( false );
	}

	/**
	 * Start scheduled campaigns whose time has come.
	 *
	 * @return void
	 */
	public function dispatch_scheduled(): void {
		/** @var Campaign_Service $service */
		$service = $this->plugin->get( 'campaign_service' );
		$due     = $this->plugin->get( 'campaigns' )->find_due( $this->plugin->get( 'database' )->now() );

		foreach ( $due as $campaign ) {
			$result = $service->start( $campaign, 'cron' );
			if ( is_wp_error( $result ) ) {
				// Pre-flight failed since scheduling (e.g. template deactivated): park it as draft.
				$service->unschedule( $campaign );
				$this->plugin->get( 'events' )->log( 'campaign.start_failed', 0, array( 'error' => $result->get_error_message() ), $campaign->id );
			}
		}
	}

	/**
	 * Daily: retention cleanup.
	 *
	 * @return void
	 */
	public function cleanup(): void {
		$settings = $this->plugin->get( 'settings' );

		$this->plugin->get( 'logs' )->cleanup( (int) $settings->get( 'log_retention_days', 90 ) );
		$this->plugin->get( 'queue' )->cleanup( (int) $settings->get( 'queue_retention_days', 30 ) );
		$this->plugin->get( 'events' )->cleanup_engagement( (int) $settings->get( 'log_retention_days', 90 ) );

		$dir = Import_Storage::get_dir();
		if ( ! is_wp_error( $dir ) ) {
			Import_Storage::cleanup_stale( $dir );
		}

		/**
		 * Fires after the daily cleanup.
		 */
		do_action( 'ibg_outreach_cleanup_done' );
	}

	/**
	 * Status for the dashboard.
	 *
	 * @return array{disabled:bool, next_run:int|false, last_run:int, last_stats:array<string, mixed>}
	 */
	public static function get_status(): array {
		$stats = get_option( Queue_Worker::OPTION_LAST_STATS, array() );
		return array(
			'disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'next_run'   => wp_next_scheduled( self::HOOK_PROCESS ),
			'last_run'   => (int) get_option( Queue_Worker::OPTION_LAST_RUN, 0 ),
			'last_stats' => is_array( $stats ) ? $stats : array(),
		);
	}
}
