<?php
/**
 * Optional contract for providers that report delivery events by webhook.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Email\Providers;

use IBG\Outreach\Email\Delivery_Event;

defined( 'ABSPATH' ) || exit;

/**
 * Interface Webhook_Provider
 */
interface Webhook_Provider {

	/**
	 * Verify the request really came from the provider (signature / token).
	 * This runs as the REST permission callback; return false to reject.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function verify_webhook( \WP_REST_Request $request ): bool;

	/**
	 * Translate the provider payload into normalised events.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return Delivery_Event[]
	 */
	public function parse_webhook( \WP_REST_Request $request ): array;
}
