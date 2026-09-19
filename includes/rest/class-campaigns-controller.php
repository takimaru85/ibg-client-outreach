<?php
/**
 * REST: /ibg/v1/campaigns
 *
 * @package IBG\Outreach
 */

namespace IBG\Outreach\Rest;

use IBG\Outreach\Campaigns\Campaign;
use IBG\Outreach\Campaigns\Campaign_Service;
use IBG\Outreach\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Class Campaigns_Controller
 */
final class Campaigns_Controller extends Abstract_Controller {

	/** @inheritDoc */
	public function register_routes(): void {
		$this->rest_base = 'campaigns';

		$writable = array(
			'name'        => array( 'type' => 'string' ),
			'subject'     => array( 'type' => 'string' ),
			'from_name'   => array( 'type' => 'string' ),
			'from_email'  => array( 'type' => 'string', 'format' => 'email' ),
			'reply_to'    => array( 'type' => 'string' ),
			'template_id' => array( 'type' => 'integer' ),
			'list_id'     => array( 'type' => 'integer' ),
			'scope'       => array( 'type' => 'string', 'enum' => array_keys( Campaign::scopes() ) ),
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => $this->can( Capabilities::VIEW ),
					'args'                => $this->paging_params( array( 'id', 'name', 'status', 'scheduled_at', 'created_at', 'updated_at' ) ) + array( 'status' => array( 'type' => 'string', 'enum' => array_keys( Campaign::statuses() ) ) ),
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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/action',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'do_action' ),
				'permission_callback' => $this->can( Capabilities::SEND_CAMPAIGNS ),
				'args'                => $this->id_param() + array(
					'action'       => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'schedule', 'unschedule', 'start', 'pause', 'resume', 'cancel' ),
					),
					'scheduled_at' => array(
						'type'        => 'string',
						'description' => 'Site-timezone datetime "Y-m-d H:i" (schedule only).',
					),
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
		$result   = $this->plugin->get( 'campaigns' )->query(
			array(
				'search'   => (string) ( $request['search'] ?? '' ),
				'status'   => (string) ( $request['status'] ?? '' ),
				'orderby'  => (string) ( $request['orderby'] ?? 'updated_at' ),
				'order'    => (string) ( $request['order'] ?? 'desc' ),
				'per_page' => $per_page,
				'page'     => (int) $request['page'],
			)
		);
		return $this->collection( array_map( fn( Campaign $c ): array => $this->prepare( $c, false ), $result['items'] ), $result['total'], $per_page );
	}

	/**
	 * Single, with pre-flight summary and engagement.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ): \WP_REST_Response|\WP_Error {
		$campaign = $this->plugin->get( 'campaigns' )->find( (int) $request['id'] );
		if ( ! $campaign ) {
			return new \WP_Error( 'ibg_not_found', __( 'Campaign not found.', 'ibg-client-outreach' ), array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( $this->prepare( $campaign, true ), 200 );
	}

	/**
	 * Create draft.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ): \WP_REST_Response|\WP_Error {
		$result = $this->plugin->get( 'campaign_service' )->save( $this->input( $request ) );
		if ( is_wp_error( $result ) ) {
			return $this->error( $result );
		}
		return new \WP_REST_Response( $this->prepare( $result, true ), 201 );
	}

	/**
	 * Update draft/scheduled.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ): \WP_REST_Response|\WP_Error {
		$existing = $this->plugin->get( 'campaigns' )->find( (int) $request['id'] );
		if ( ! $existing ) {
			return new \WP_Error( 'ibg_not_found', __( 'Campaign not found.', 'ibg-client-outreach' ), array( 'status' => 404 ) );
		}
		$data = array_merge(
			array(
				'name'        => $existing->name,
				'subject'     => $existing->subject,
				'from_name'   => $existing->from_name,
				'from_email'  => $existing->from_email,
				'reply_to'    => $existing->reply_to,
				'template_id' => (int) $existing->template_id,
				'list_id'     => (int) $existing->list_id,
				'scope'       => $existing->scope,
			),
			$this->input( $request )
		);
		$result = $this->plugin->get( 'campaign_service' )->save( $data, $existing->id );
		if ( is_wp_error( $result ) ) {
			return $this->error( $result );
		}
		return new \WP_REST_Response( $this->prepare( $result, true ), 200 );
	}

	/**
	 * Delete.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ): \WP_REST_Response|\WP_Error {
		$result = $this->plugin->get( 'campaign_service' )->delete( array( (int) $request['id'] ) );
		if ( $result['blocked'] > 0 ) {
			return new \WP_Error( 'ibg_running', __( 'Cancel the campaign before deleting it.', 'ibg-client-outreach' ), array( 'status' => 409 ) );
		}
		if ( 0 === $result['deleted'] ) {
			return new \WP_Error( 'ibg_not_found', __( 'Campaign not found.', 'ibg-client-outreach' ), array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * State transitions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function do_action( $request ): \WP_REST_Response|\WP_Error {
		$campaign = $this->plugin->get( 'campaigns' )->find( (int) $request['id'] );
		if ( ! $campaign ) {
			return new \WP_Error( 'ibg_not_found', __( 'Campaign not found.', 'ibg-client-outreach' ), array( 'status' => 404 ) );
		}

		/** @var Campaign_Service $service */
		$service = $this->plugin->get( 'campaign_service' );

		$result = match ( (string) $request['action'] ) {
			'schedule'   => $service->schedule( $campaign, (string) ( $request['scheduled_at'] ?? '' ) ),
			'unschedule' => $service->unschedule( $campaign ),
			'start'      => $service->start( $campaign, 'api' ),
			'pause'      => $service->pause( $campaign ),
			'resume'     => $service->resume( $campaign ),
			'cancel'     => $service->cancel( $campaign ),
			default      => new \WP_Error( 'invalid', __( 'Unknown action.', 'ibg-client-outreach' ) ),
		};

		if ( is_wp_error( $result ) ) {
			return $this->error( $result );
		}
		return new \WP_REST_Response( $this->prepare( $result, true ), 200 );
	}

	/**
	 * Serialise.
	 *
	 * @param Campaign $campaign Campaign.
	 * @param bool     $detailed Include summary, pre-flight and engagement.
	 * @return array<string, mixed>
	 */
	private function prepare( Campaign $campaign, bool $detailed ): array {
		$row = $campaign->to_row();
		unset( $row['segment'], $row['body_html'], $row['body_text'] );

		$data = array( 'id' => $campaign->id ) + $row + array(
			'scope'    => $campaign->scope,
			'criteria' => $campaign->criteria,
		);

		if ( $detailed ) {
			$summary             = $this->plugin->get( 'audience' )->summarize( $campaign );
			$data['audience']    = is_wp_error( $summary ) ? array( 'error' => $summary->get_error_message() ) : $summary;
			$data['preflight']   = $this->plugin->get( 'campaign_service' )->preflight( $campaign );
			$data['engagement']  = $this->plugin->get( 'stats' )->campaign_engagement( $campaign->id );
			$data['has_started'] = $campaign->has_started();
		}

		return $data;
	}

	/**
	 * Writable input present in the request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function input( \WP_REST_Request $request ): array {
		$data = array();
		foreach ( array( 'name', 'subject', 'from_name', 'from_email', 'reply_to', 'template_id', 'list_id', 'scope' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = $request[ $field ];
			}
		}
		return $data;
	}
}
