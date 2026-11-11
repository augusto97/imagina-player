<?php
/**
 * The settings screen's view of the services captured addresses are sent to:
 * read with the keys masked, saved, and tried.
 *
 * Administrators only, with the REST nonce. Nothing here answers anyone else,
 * because a key is the one thing on the screen that is worth stealing.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Rest;

use ImaginaPlayer\Leads\Delivery;
use ImaginaPlayer\Leads\Integrations;
use ImaginaPlayer\Media\Transcriber;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class IntegrationsController {

	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$can_manage = static fn(): bool => current_user_can( 'manage_options' );

		register_rest_route(
			PeaksController::REST_NAMESPACE,
			'/integrations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => $can_manage,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $can_manage,
				),
			)
		);

		register_rest_route(
			PeaksController::REST_NAMESPACE,
			'/integrations/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => $can_manage,
				'args'                => array(
					'service' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array_merge( Delivery::SERVICES, array( 'transcription' ) ),
					),
				),
			)
		);
	}

	public function read(): WP_REST_Response {
		return new WP_REST_Response( self::payload(), 200 );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response {
		Integrations::save( (array) ( $request->get_json_params() ?: $request->get_params() ) );

		return new WP_REST_Response( self::payload(), 200 );
	}

	public function test( WP_REST_Request $request ): WP_REST_Response {
		$service = (string) $request->get_param( 'service' );

		return new WP_REST_Response( 'transcription' === $service ? Transcriber::test() : Delivery::test( $service ), 200 );
	}

	/** @return array<string, mixed> */
	private static function payload(): array {
		$log = get_option( Delivery::LOG, array() );

		return array(
			'services'  => Integrations::for_admin(),
			'log'       => is_array( $log ) ? $log : array(),
			'fluentcrm' => Delivery::fluentcrm_available(),
		);
	}
}
