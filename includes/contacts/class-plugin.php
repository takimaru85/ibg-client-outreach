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

use IBG\Outreach\Contacts\Contact_Repository;
use IBG\Outreach\Contacts\Contact_Service;
use IBG\Outreach\Email\Provider_Registry;
use IBG\Outreach\Events\Event_Repository;
use IBG\Outreach\Unsubscribe\Suppression_Repository;

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

		$this->register( 'admin', static fn( Plugin $p ): Admin\Admin => new Admin\Admin( $p ) );
	}
}
