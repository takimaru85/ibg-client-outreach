<?php
/**
 * REST: /ibg/v1/contacts
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Rest;

use IBG\Outreach\Capabilities;
use IBG\Outreach\Contacts\Contact;
use IBG\Outreach\Contacts\Contact_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Class Contacts_Controller
 */
final class Contacts_Controller extends Abstract_Controller {

	/**
	 * Writable fields accepted from the API.
	 *
	 * @var string[]
	 */
	private const WRITABLE = array( 'email', 'first_name', 'last_name', 'full_name', 'company', 'website', 'phone', 'country', 'industry', 'source', 'notes', 'contact_status', 'marketing_status', 'consent_basis', 'consent_at' );

	/** @inheritDoc */
	public function register_routes(): void {
		$this->rest_base = 'contacts';

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => $this->can( Capabilities::VIEW ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_CONTACTS ),
					'args'                => $this->writable_args(),
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
					'permission_callback' => $this->can( Capabilities::MANAGE_CONTACTS ),
					'args'                => $this->id_param() + $this->writable_args(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => $this->can( Capabilities::MANAGE_CONTACTS ),
					'args'                => $this->id_param(),
				),
			)
		);
	}

	/** @inheritDoc */
	public function get_collection_params(): array {
		$params = $this->paging_params( array( 'id', 'email', 'last_name', 'company', 'created_at', 'updated_at', 'last_contacted_at' ) );
		foreach ( array( 'contact_status', 'marketing_status', 'email_status', 'industry', 'country', 'source', 'consent_basis' ) as $key ) {
			$params[ $key ] = array( 'type' => 'string' );
		}
		$params['list_id']   = array( 'type' => 'integer' );
		$params['date_from'] = array( 'type' => 'string' );
		$params['date_to']   = array( 'type' => 'string' );
		return $params;
	}

	/**
	 * List contacts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ): \WP_REST_Response {
		$args = array(
			'per_page' => (int) $request['per_page'],
			'page'     => (int) $request['page'],
			'orderby'  => (string) ( $request['orderby'] ?? 'created_at' ),
			'order'    => (string) ( $request['order'] ?? 'desc' ),
		);
		foreach ( array( 'search', 'contact_status', 'marketing_status', 'email_status', 'industry', 'country', 'source', 'consent_basis', 'list_id', 'date_from', 'date_to' ) as $key ) {
			if ( isset( $request[ $key ] ) && '' !== $request[ $key ] ) {
				$args[ $key ] = $request[ $key ];
			}
		}

		$result = $this->plugin->get( 'contacts' )->query( $args );
		$items  = array_map( array( $this, 'prepare' ), $result['items'] );

		return $this->collection( $items, $result['total'], $args['per_page'] );
	}

	/**
	 * Single contact.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ): \WP_REST_Response|\WP_Error {
		$contact = $this->plugin->get( 'contacts' )->find( (int) $request['id'] );
		if ( ! $contact ) {
			return new \WP_Error( 'ibg_not_found', __( 'Contact not found.', 'ibg-client-outreach' ), array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( $this->prepare( $contact ), 200 );
	}

	/**
	 * Create.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ): \WP_REST_Response|\WP_Error {
		$result = $this->plugin->get( 'contact_service' )->create( $this->input( $request ), array( 'source' => Contact_Service::SOURCE_API ) );
		if ( is_wp_error( $result ) ) {
			return $this->error( $result );
		}
		$response = new \WP_REST_Response( $this->prepare( $result ), 201 );
		$response->header( 'Location', rest_url( $this->namespace . '/contacts/' . $result->id ) );
		return $response;
	}

	/**
	 * Update (partial).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ): \WP_REST_Response|\WP_Error {
		$result = $this->plugin->get( 'contact_service' )->update( (int) $request['id'], $this->input( $request ), array( 'source' => Contact_Service::SOURCE_API ) );
		if ( is_wp_error( $result ) ) {
			return $this->error( $result );
		}
		$notices = $this->plugin->get( 'contact_service' )->take_notices();
		$data    = $this->prepare( $result );
		if ( ! empty( $notices ) ) {
			$data['notices'] = $notices;
		}
		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Delete.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ): \WP_REST_Response|\WP_Error {
		$deleted = $this->plugin->get( 'contact_service' )->delete( array( (int) $request['id'] ) );
		if ( 0 === $deleted ) {
			return new \WP_Error( 'ibg_not_found', __( 'Contact not found.', 'ibg-client-outreach' ), array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Serialise a contact.
	 *
	 * @param Contact $contact Contact.
	 * @return array<string, mixed>
	 */
	private function prepare( Contact $contact ): array {
		return array( 'id' => $contact->id ) + $contact->to_row() + array(
			'display_name' => $contact->get_display_name(),
			'is_mailable'  => $contact->is_mailable(),
		);
	}

	/**
	 * Only writable fields present in the request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function input( \WP_REST_Request $request ): array {
		$data = array();
		foreach ( self::WRITABLE as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = $request[ $field ];
			}
		}
		return $data;
	}

	/**
	 * Schema for writable fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function writable_args(): array {
		$args = array();
		foreach ( self::WRITABLE as $field ) {
			$args[ $field ] = array( 'type' => 'string' );
		}
		$args['email']['format']         = 'email';
		$args['contact_status']['enum']   = array_keys( Contact::contact_statuses() );
		$args['marketing_status']['enum'] = array_keys( Contact::marketing_statuses() );
		return $args;
	}
}
