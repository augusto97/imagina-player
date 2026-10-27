<?php
/**
 * Where a course's progress is sent, for a student who is signed in.
 *
 * Signed in only, with the REST nonce: this writes to the person's own
 * account and to nobody else's, and a visitor who is not signed in keeps their
 * progress in their browser instead.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Rest;

use ImaginaPlayer\Player\CourseProgress;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProgressController {

	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			PeaksController::REST_NAMESPACE,
			'/progress',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save' ),
				'permission_callback' => static fn(): bool => is_user_logged_in(),
				'args'                => array(
					'course'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'watched'  => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
						'maxItems' => 500,
					),
					'current'  => array( 'type' => 'string' ),
					'position' => array( 'type' => 'number' ),
					'reset'    => array( 'type' => 'boolean' ),
				),
			)
		);
	}

	public function save( WP_REST_Request $request ): WP_REST_Response {
		$course = CourseProgress::key( (string) $request->get_param( 'course' ) );

		if ( '' === $course ) {
			return new WP_REST_Response( array( 'saved' => false ), 400 );
		}

		$state = CourseProgress::save(
			get_current_user_id(),
			$course,
			array(
				'w'     => (array) ( $request->get_param( 'watched' ) ?? array() ),
				'c'     => (string) ( $request->get_param( 'current' ) ?? '' ),
				'p'     => (float) ( $request->get_param( 'position' ) ?? 0 ),
				'reset' => (bool) $request->get_param( 'reset' ),
			)
		);

		return new WP_REST_Response( array( 'saved' => true, 'state' => $state ), 200 );
	}
}
