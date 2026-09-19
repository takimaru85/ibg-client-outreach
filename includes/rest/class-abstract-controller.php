<?php
/**
 * Base REST controller.
 *
 * Authentication is WordPress' own (cookie + nonce, or Application Passwords).
 * Every route has a permission callback tied to a plugin capability; nothing
 * here is ever public.
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Rest;

use IBG\Outreach\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Class Abstract_Controller
 */
abstract class Abstract_Controller extends \WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( protected readonly Plugin $plugin ) {
		$this->namespace = Rest_Api::NAMESPACE;
	}

	/**
	 * Permission callback factory for a capability.
	 *
	 * @param string $capability Capability.
	 * @return callable
	 */
	protected function can( string $capability ): callable {
		return static function (): bool|\WP_Error {
			if ( current_user_can( $capability ) ) {
				return true;
			}
			return new \WP_Error(
				'ibg_forbidden',
				__( 'You are not allowed to do that.', 'ibg-client-outreach' ),
				array( 'status' => rest_authorization_required_code() )
			);
		};
	}

	/**
	 * Turn a service WP_Error into a REST error with a sensible status.
	 *
	 * @param \WP_Error $error  Error.
	 * @param int       $status HTTP status.
	 * @return \WP_Error
	 */
	protected function error( \WP_Error $error, int $status = 400 ): \WP_Error {
		$code = $error->get_error_code();
		if ( 'not_found' === $code ) {
			$status = 404;
		} elseif ( in_array( $code, array( 'locked', 'race', 'status' ), true ) ) {
			$status = 409;
		}
		return new \WP_Error( 'ibg_' . $code, $error->get_error_message(), array( 'status' => $status ) + (array) $error->get_error_data() );
	}

	/**
	 * Response for a paged collection with X-WP-Total headers.
	 *
	 * @param array<int, mixed> $items    Prepared items.
	 * @param int               $total    Total items.
	 * @param int               $per_page Per page.
	 * @return \WP_REST_Response
	 */
	protected function collection( array $items, int $total, int $per_page ): \WP_REST_Response {
		$response = new \WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / max( 1, $per_page ) ) );
		return $response;
	}

	/**
	 * Common collection params.
	 *
	 * @param string[] $orderby Allowed orderby values.
	 * @return array<string, array<string, mixed>>
	 */
	protected function paging_params( array $orderby ): array {
		return array(
			'page'     => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			),
			'search'   => array( 'type' => 'string' ),
			'orderby'  => array(
				'type' => 'string',
				'enum' => $orderby,
			),
			'order'    => array(
				'type' => 'string',
				'enum' => array( 'asc', 'desc' ),
			),
		);
	}

	/**
	 * Integer id param.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function id_param(): array {
		return array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
		);
	}
}
