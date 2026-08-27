<?php
/**
 * Hands the player a fresh streaming URL.
 *
 * Signed URLs expire, and a full-page cache will happily serve the same HTML
 * long after the URL inside it has. Rather than shortening the cache or
 * lengthening the token, the player asks for a new URL when playback fails and
 * retries once.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Rest;

use ImaginaPlayer\Protection\ProtectedMedia;
use ImaginaPlayer\Protection\Vault;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StreamController {

	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			PeaksController::REST_NAMESPACE,
			'/stream-url',
			array(
				'methods'  => WP_REST_Server::READABLE,
				// The URL is not the authorisation: every check runs again when the
				// stream itself is requested.
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'get_url' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
	}

	public function get_url( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$attachment_id = (int) $request->get_param( 'id' );

		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error(
				'imagina_player_unknown_attachment',
				__( 'That attachment does not exist.', 'imagina-player' ),
				array( 'status' => 404 )
			);
		}

		if ( ! Vault::is_protected( $attachment_id ) ) {
			return new WP_Error(
				'imagina_player_not_protected',
				__( 'That file is not protected.', 'imagina-player' ),
				array( 'status' => 404 )
			);
		}

		$response = new WP_REST_Response(
			array( 'url' => ProtectedMedia::signed_url( $attachment_id ) ),
			200
		);

		// A cached copy of this response would defeat the point of asking.
		$response->header( 'Cache-Control', 'private, no-store' );

		return $response;
	}
}
