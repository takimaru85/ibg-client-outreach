<?php
/**
 * REST: /ibg/v1/templates
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Rest;

use IBG\Outreach\Capabilities;
use IBG\Outreach\Templates\Email_Template;

defined( 'ABSPATH' ) || exit;

/**
 * Class Templates_Controller
 */
final class Templates_Controller extends Abstract_Controller {

	/** @inheritDoc */
	public function register_routes(): void {
		$this->rest_base = 'templates';

		$writable = array(
			'name'      => array( 'type' => 'string' ),
			'subject'   => array( 'type' => 'string' ),
			'body_html' => array( 'type' => 'string' ),
			'body_text' => array( 'type' => 'string' ),
			'is_active' => array( 'type' => 'boolean' ),
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => $this->can( Capabilities::VIEW ),
					'args'                => $this->paging_params( array( 'id', 'name', 'updated_at', 'created_at' ) ) + array( 'is_active' => array( 'type' => 'boolean' ) ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_CAMPAIGNS ),
					'args'                => $writable,
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => $this->can( Capabilities::VIEW ),
					'args'                => $this->id_param(),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_CAMPAIGNS ),
					'args'                => $this->id_param() + $writable,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_CAMPAIGNS ),
					'args'                => $this->id_param(),
				),
			)
		);
	}

	/**
	 * List.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ): \WP_REST_Response {
		$per_page = (int) $request['per_page'];
		$result   = $this->plugin->get( 'templates' )->query(
			array(
				'search'    => (string) ( $request['search'] ?? '' ),
				'is_active' => $request->has_param( 'is_active' ) ? ( $request['is_active'] ? 1 : 0 ) : '',
				'orderby'   => (string) ( $request['orderby'] ?? 'updated_at' ),
				'order'     => (string) ( $request['order'] ?? 'desc' ),
				'per_page'  => $per_page,
				'page'      => (int) $request['page'],
			)
		);
		return $this->collection( array_map( array( $this, 'prepare' ), $result['items'] ), $result['total'], $per_page );
	}

	/**
	 * Single.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ): \WP_REST_Response|\WP_Error {
		$template = $this->plugin->get( 'templates' )->find( (int) $request['id'] );
		if ( ! $template ) {
			return new \WP_Error( 'ibg_not_found', __( 'Template not found.', 'ibg-client-outreach' ), array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( $this->prepare( $template ), 200 );
	}

	/**
	 * Create.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ): \WP_REST_Response|\WP_Error {
		$result = $this->plugin->get( 'template_service' )->save( $this->input( $request, true ) );
		if ( is_wp_error( $result ) ) {
			return $this->error( $result );
		}
		return new \WP_REST_Response( $this->prepare( $result ), 201 );
	}

	/**
	 * Update. The service requires the full template (name/subject/body), so
	 * missing fields are filled from the stored template.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ): \WP_REST_Response|\WP_Error {
		$existing = $this->plugin->get( 'templates' )->find( (int) $request['id'] );
		if ( ! $existing ) {
			return new \WP_Error( 'ibg_not_found', __( 'Template not found.', 'ibg-client-outreach' ), array( 'status' => 404 ) );
		}
		$data = array_merge(
			array(
				'name'      => $existing->name,
				'subject'   => $existing->subject,
				'body_html' => $existing->body_html,
				'body_text' => $existing->body_text,
				'is_active' => $existing->is_active,
			),
			$this->input( $request, false )
		);
		$result = $this->plugin->get( 'template_service' )->save( $data, $existing->id );
		if ( is_wp_error( $result ) ) {
			return $this->error( $result );
		}
		return new \WP_REST_Response( $this->prepare( $result ), 200 );
	}

	/**
	 * Delete (refused when used by a campaign).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ): \WP_REST_Response|\WP_Error {
		$result = $this->plugin->get( 'template_service' )->delete( array( (int) $request['id'] ) );
		if ( $result['blocked'] > 0 ) {
			return new \WP_Error( 'ibg_in_use', __( 'The template is used by a campaign. Deactivate it instead.', 'ibg-client-outreach' ), array( 'status' => 409 ) );
		}
		if ( 0 === $result['deleted'] ) {
			return new \WP_Error( 'ibg_not_found', __( 'Template not found.', 'ibg-client-outreach' ), array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Serialise.
	 *
	 * @param Email_Template $template Template.
	 * @return array<string, mixed>
	 */
	private function prepare( Email_Template $template ): array {
		return array( 'id' => $template->id ) + $template->to_row() + array( 'is_active' => $template->is_active );
	}

	/**
	 * Input fields.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $defaults Whether to include defaults for missing fields.
	 * @return array<string, mixed>
	 */
	private function input( \WP_REST_Request $request, bool $defaults ): array {
		$data = array();
		foreach ( array( 'name', 'subject', 'body_html', 'body_text', 'is_active' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = $request[ $field ];
			} elseif ( $defaults ) {
				$data[ $field ] = 'is_active' === $field ? true : '';
			}
		}
		return $data;
	}
}
