<?php
/**
 * Registers the plugin's REST controllers.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Rest;

use IBG\Outreach\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Class Rest_Api
 */
final class Rest_Api {

	public const NAMESPACE = 'ibg/v1';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( private readonly Plugin $plugin ) {}

	/**
	 * Hook rest_api_init.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Instantiate controllers and register their routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$controllers = array(
			new Contacts_Controller( $this->plugin ),
			new Templates_Controller( $this->plugin ),
			new Campaigns_Controller( $this->plugin ),
			new Stats_Controller( $this->plugin ),
		);

		/**
		 * Filter the REST controllers (extensions may add their own WP_REST_Controller instances).
		 *
		 * @param \WP_REST_Controller[] $controllers Controllers.
		 * @param Plugin                $plugin      Container.
		 */
		$controllers = (array) apply_filters( 'ibg_outreach_rest_controllers', $controllers, $this->plugin );

		foreach ( $controllers as $controller ) {
			if ( $controller instanceof \WP_REST_Controller ) {
				$controller->register_routes();
			}
		}
	}
}
