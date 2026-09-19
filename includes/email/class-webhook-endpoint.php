<?php
/**
 * REST route for provider webhooks: POST /wp-json/ibg/v1/webhooks/{provider}
 *
 * The permission callback is the provider's own signature verification, so an
 * unsigned or mis-signed payload never reaches the processor.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Email;

use IBG\Outreach\Email\Providers\Webhook_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Class Webhook_Endpoint
 */
final class Webhook_Endpoint {

	public const NAMESPACE = 'ibg/v1';

	/**
	 * Constructor.
	 *
	 * @param Provider_Registry        $providers Providers.
	 * @param Delivery_Event_Processor $processor Processor.
	 */
	public function __construct(
		private readonly Provider_Registry $providers,
		private readonly Delivery_Event_Processor $processor
	) {}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/webhooks/(?P<provider>[a-z0-9_]+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'provider' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Permission: the provider must exist, support webhooks, and verify the request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function permission( \WP_REST_Request $request ): bool|\WP_Error {
		$provider = $this->providers->get( (string) $request->get_param( 'provider' ) );

		if ( ! $provider instanceof Webhook_Provider ) {
			return new \WP_Error( 'ibg_no_webhook', __( 'Unknown webhook provider.', 'ibg-client-outreach' ), array( 'status' => 404 ) );
		}
		if ( ! $provider->verify_webhook( $request ) ) {
			return new \WP_Error( 'ibg_bad_signature', __( 'Webhook signature could not be verified.', 'ibg-client-outreach' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Parse and apply events.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$id       = (string) $request->get_param( 'provider' );
		$provider = $this->providers->get( $id );

		if ( ! $provider instanceof Webhook_Provider ) {
			return new \WP_REST_Response( array( 'error' => 'unknown_provider' ), 404 );
		}

		$events = $provider->parse_webhook( $request );
		$counts = $this->processor->process( $events, $id );

		return new \WP_REST_Response( array( 'received' => count( $events ) ) + $counts, 200 );
	}

	/**
	 * Public URL a provider must be pointed at.
	 *
	 * @param string $provider_id Provider id.
	 * @return string
	 */
	public static function get_url( string $provider_id ): string {
		return rest_url( self::NAMESPACE . '/webhooks/' . sanitize_key( $provider_id ) );
	}
}
