<?php
/**
 * REST: /ibg/v1/stats
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Rest;

use IBG\Outreach\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Class Stats_Controller
 */
final class Stats_Controller extends Abstract_Controller {

	/** @inheritDoc */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => $this->can( Capabilities::VIEW ),
				'args'                => array(
					'days' => array(
						'type'    => 'integer',
						'default' => 14,
						'minimum' => 1,
						'maximum' => 90,
					),
				),
			)
		);
	}

	/**
	 * Dashboard numbers.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_stats( $request ): \WP_REST_Response {
		$stats = $this->plugin->get( 'stats' );
		return new \WP_REST_Response(
			array(
				'contacts'    => $stats->contacts(),
				'campaigns'   => $stats->campaigns(),
				'emails'      => $stats->emails(),
				'daily_sends' => $stats->daily_sends( (int) $request['days'] ),
				'queue'       => $this->plugin->get( 'queue' )->count_by_status(),
			),
			200
		);
	}
}
