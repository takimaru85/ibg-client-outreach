<?php
/**
 * Plugin container.
 *
 * A minimal service container: services are registered as factories and
 * instantiated lazily on first use. This gives us dependency injection without
 * a third-party library and keeps every module testable in isolation.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach;

use IBG\Outreach\Analytics\Stats_Repository;
use IBG\Outreach\Analytics\Tracking;
use IBG\Outreach\Campaigns\Audience_Resolver;
use IBG\Outreach\Rest\Rest_Api;
use IBG\Outreach\Campaigns\Campaign_Repository;
use IBG\Outreach\Campaigns\Campaign_Service;
use IBG\Outreach\Contacts\Contact_Repository;
use IBG\Outreach\Contacts\Contact_Service;
use IBG\Outreach\Email\Delivery_Event_Processor;
use IBG\Outreach\Email\Email_Composer;
use IBG\Outreach\Email\Merge_Tags;
use IBG\Outreach\Email\Provider_Registry;
use IBG\Outreach\Email\Webhook_Endpoint;
use IBG\Outreach\Events\Event_Repository;
use IBG\Outreach\Import\Contact_Importer;
use IBG\Outreach\Lists\List_Repository;
use IBG\Outreach\Lists\List_Service;
use IBG\Outreach\Queue\Cron;
use IBG\Outreach\Queue\Email_Log_Repository;
use IBG\Outreach\Queue\Queue_Filler;
use IBG\Outreach\Queue\Queue_Repository;
use IBG\Outreach\Queue\Queue_Worker;
use IBG\Outreach\Templates\Template_Repository;
use IBG\Outreach\Templates\Template_Service;
use IBG\Outreach\Unsubscribe\Suppression_Repository;
use IBG\Outreach\Unsubscribe\Unsubscribe_Endpoint;
use IBG\Outreach\Unsubscribe\Unsubscribe_Token;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Service factories keyed by service id.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Instantiated services keyed by service id.
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Get the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Registers the core services.
	 */
	private function __construct() {
		$this->register_core_services();
	}

	/**
	 * Register a service factory.
	 *
	 * @param string   $id      Service identifier.
	 * @param callable $factory Factory receiving the container and returning the service.
	 * @return void
	 */
	public function register( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->services[ $id ] );
	}

	/**
	 * Whether a service is registered.
	 *
	 * @param string $id Service identifier.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] ) || isset( $this->services[ $id ] );
	}

	/**
	 * Resolve a service (instantiating it on first call).
	 *
	 * @param string $id Service identifier.
	 * @return object
	 *
	 * @throws \InvalidArgumentException When the service is unknown.
	 */
	public function get( string $id ): object {
		if ( ! isset( $this->services[ $id ] ) ) {
			if ( ! isset( $this->factories[ $id ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Unknown IBG Outreach service "%s".', esc_html( $id ) ) );
			}
			$this->services[ $id ] = ( $this->factories[ $id ] )( $this );
		}
		return $this->services[ $id ];
	}

	/**
	 * Boot the plugin. Called once on plugins_loaded.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'wp_initialize_site', array( $this, 'on_new_site' ), 10, 1 );

		// Queue lifecycle hooks and cron run on every request type (cron may fire on the front end).
		$this->get( 'queue_filler' )->register();
		$this->get( 'cron' )->register();
		$this->get( 'webhooks' )->register();

		if ( ! is_admin() ) {
			$this->get( 'unsubscribe_endpoint' )->register();
			$this->get( 'tracking' )->register();
		}

		$this->get( 'rest_api' )->register();

		if ( is_admin() ) {
			// Keep the schema current after plugin updates (activation hooks don't run on update).
			$this->get( 'installer' )->maybe_upgrade();
			$this->get( 'admin' )->init();
		}

		/**
		 * Fires once the plugin container is ready. Extensions register their
		 * own services, providers and admin pages here.
		 *
		 * @param Plugin $plugin The container.
		 */
		do_action( 'ibg_outreach_loaded', $this );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'ibg-client-outreach', false, dirname( IBG_OUTREACH_BASENAME ) . '/languages' );
	}

	/**
	 * Install tables for a newly created site when network-activated.
	 *
	 * @param \WP_Site $site The new site.
	 * @return void
	 */
	public function on_new_site( \WP_Site $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( IBG_OUTREACH_BASENAME ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		Activator::install_site();
		restore_current_blog();
	}

	/**
	 * Register the services that ship with the core plugin.
	 *
	 * @return void
	 */
	private function register_core_services(): void {
		$this->register( 'database', static fn(): Database => new Database( $GLOBALS['wpdb'] ) );

		$this->register( 'installer', static fn( Plugin $p ): Installer => new Installer( $p->get( 'database' ) ) );

		$this->register( 'capabilities', static fn(): Capabilities => new Capabilities() );

		$this->register(
			'settings',
			static function ( Plugin $p ): Settings {
				$settings = new Settings();
				// The provider dropdown is populated lazily from the registry to avoid a construction cycle.
				$settings->set_dynamic_options( 'provider', static fn(): array => $p->get( 'providers' )->get_options() );
				$settings->set_dynamic_fields( 'email', static fn(): array => $p->get( 'providers' )->get_all_settings_fields() );
				return $settings;
			}
		);

		$this->register( 'providers', static fn( Plugin $p ): Provider_Registry => new Provider_Registry( $p->get( 'settings' ) ) );

		$this->register( 'events', static fn( Plugin $p ): Event_Repository => new Event_Repository( $p->get( 'database' ) ) );

		$this->register( 'suppressions', static fn( Plugin $p ): Suppression_Repository => new Suppression_Repository( $p->get( 'database' ) ) );

		$this->register( 'contacts', static fn( Plugin $p ): Contact_Repository => new Contact_Repository( $p->get( 'database' ) ) );

		$this->register(
			'contact_service',
			static fn( Plugin $p ): Contact_Service => new Contact_Service(
				$p->get( 'contacts' ),
				$p->get( 'suppressions' ),
				$p->get( 'events' ),
				$p->get( 'database' )
			)
		);

		$this->register( 'unsubscribe_token', static fn(): Unsubscribe_Token => new Unsubscribe_Token() );

		$this->register(
			'unsubscribe_endpoint',
			static fn( Plugin $p ): Unsubscribe_Endpoint => new Unsubscribe_Endpoint(
				$p->get( 'unsubscribe_token' ),
				$p->get( 'contacts' ),
				$p->get( 'contact_service' ),
				$p->get( 'events' ),
				$p->get( 'settings' )
			)
		);

		$this->register( 'merge_tags', static fn( Plugin $p ): Merge_Tags => new Merge_Tags( $p->get( 'settings' ), $p->get( 'unsubscribe_token' ) ) );

		$this->register( 'signer', static fn(): Signer => new Signer() );

		$this->register(
			'tracking',
			static fn( Plugin $p ): Tracking => new Tracking(
				$p->get( 'signer' ),
				$p->get( 'settings' ),
				$p->get( 'events' ),
				$p->get( 'contacts' ),
				$p->get( 'queue' ),
				$p->get( 'database' )
			)
		);

		$this->register( 'composer', static fn( Plugin $p ): Email_Composer => new Email_Composer( $p->get( 'merge_tags' ), $p->get( 'settings' ), $p->get( 'tracking' ) ) );

		$this->register(
			'stats',
			static fn( Plugin $p ): Stats_Repository => new Stats_Repository(
				$p->get( 'database' ),
				$p->get( 'contacts' ),
				$p->get( 'campaigns' ),
				$p->get( 'suppressions' )
			)
		);

		$this->register( 'templates', static fn( Plugin $p ): Template_Repository => new Template_Repository( $p->get( 'database' ) ) );

		$this->register( 'template_service', static fn( Plugin $p ): Template_Service => new Template_Service( $p->get( 'templates' ), $p->get( 'merge_tags' ) ) );

		$this->register( 'lists', static fn( Plugin $p ): List_Repository => new List_Repository( $p->get( 'database' ) ) );

		$this->register(
			'list_service',
			static fn( Plugin $p ): List_Service => new List_Service(
				$p->get( 'lists' ),
				$p->get( 'contacts' ),
				$p->get( 'events' )
			)
		);

		$this->register( 'campaigns', static fn( Plugin $p ): Campaign_Repository => new Campaign_Repository( $p->get( 'database' ) ) );

		$this->register(
			'audience',
			static fn( Plugin $p ): Audience_Resolver => new Audience_Resolver(
				$p->get( 'contacts' ),
				$p->get( 'lists' ),
				$p->get( 'list_service' )
			)
		);

		$this->register(
			'campaign_service',
			static fn( Plugin $p ): Campaign_Service => new Campaign_Service(
				$p->get( 'campaigns' ),
				$p->get( 'templates' ),
				$p->get( 'lists' ),
				$p->get( 'audience' ),
				$p->get( 'providers' ),
				$p->get( 'settings' ),
				$p->get( 'events' ),
				$p->get( 'database' )
			)
		);

		$this->register( 'queue', static fn( Plugin $p ): Queue_Repository => new Queue_Repository( $p->get( 'database' ) ) );

		$this->register( 'logs', static fn( Plugin $p ): Email_Log_Repository => new Email_Log_Repository( $p->get( 'database' ) ) );

		$this->register(
			'queue_filler',
			static fn( Plugin $p ): Queue_Filler => new Queue_Filler(
				$p->get( 'queue' ),
				$p->get( 'audience' ),
				$p->get( 'contacts' ),
				$p->get( 'campaigns' ),
				$p->get( 'campaign_service' ),
				$p->get( 'events' ),
				$p->get( 'database' )
			)
		);

		$this->register(
			'queue_worker',
			static fn( Plugin $p ): Queue_Worker => new Queue_Worker(
				$p->get( 'queue' ),
				$p->get( 'campaigns' ),
				$p->get( 'campaign_service' ),
				$p->get( 'contacts' ),
				$p->get( 'suppressions' ),
				$p->get( 'composer' ),
				$p->get( 'providers' ),
				$p->get( 'logs' ),
				$p->get( 'settings' ),
				$p->get( 'database' )
			)
		);

		$this->register( 'cron', static fn( Plugin $p ): Cron => new Cron( $p ) );

		$this->register(
			'delivery_events',
			static fn( Plugin $p ): Delivery_Event_Processor => new Delivery_Event_Processor(
				$p->get( 'contacts' ),
				$p->get( 'contact_service' ),
				$p->get( 'suppressions' ),
				$p->get( 'logs' ),
				$p->get( 'events' ),
				$p->get( 'settings' ),
				$p->get( 'database' )
			)
		);

		$this->register( 'webhooks', static fn( Plugin $p ): Webhook_Endpoint => new Webhook_Endpoint( $p->get( 'providers' ), $p->get( 'delivery_events' ) ) );

		$this->register( 'rest_api', static fn( Plugin $p ): Rest_Api => new Rest_Api( $p ) );

		$this->register(
			'importer',
			static fn( Plugin $p ): Contact_Importer => new Contact_Importer(
				$p->get( 'contacts' ),
				$p->get( 'contact_service' ),
				$p->get( 'suppressions' ),
				$p->get( 'events' ),
				$p->get( 'lists' )
			)
		);

		$this->register( 'admin', static fn( Plugin $p ): Admin\Admin => new Admin\Admin( $p ) );
	}
}
