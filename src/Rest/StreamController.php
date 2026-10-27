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
					'id'    => array(
						'type'     => 'integer',
						'required' => true,
					),
					// The link that expired: the proof this visitor was shown
					// the player. See `ProtectedMedia::can_refresh()`.
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	public function get_url( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$attachment_id = (int) $request->get_param( 'id' );
		$token         = (string) $request->get_param( 'token' );

		/*
		 * One answer for every refusal. "No such file", "not protected" and
		 * "not yours" used to be three different answers — a way to learn,
		 * one number at a time, which attachments exist and which are kept
		 * in the vault.
		 */
		$refused = new WP_Error(
			'imagina_player_unavailable',
			__( 'That file is not available.', 'imagina-player' ),
			array( 'status' => 404 )
		);

		// Asked from another site's page: WordPress would answer it with the
		// caller's own cookies, and the fresh link would be handed to them.
		if ( ! self::same_site( (string) $request->get_header( 'origin' ) ) ) {
			return $refused;
		}

		if ( ! self::within_limit() ) {
			return new WP_Error(
				'imagina_player_too_many',
				__( 'Too many attempts. Try again in a few minutes.', 'imagina-player' ),
				array( 'status' => 429 )
			);
		}

		if (
			$attachment_id <= 0
			|| ! Vault::is_protected( $attachment_id )
			|| ! ProtectedMedia::can_refresh( $attachment_id, $token )
		) {
			return $refused;
		}

		$response = new WP_REST_Response(
			array( 'url' => ProtectedMedia::signed_url( $attachment_id ) ),
			200
		);

		// A cached copy of this response would defeat the point of asking.
		$response->header( 'Cache-Control', 'private, no-store' );
		$response->header( 'Vary', 'Cookie, Origin' );

		return $response;
	}

	/**
	 * Whether a request came from this site's own pages.
	 *
	 * A request with no `Origin` is a same-site navigation or a server; one
	 * whose `Origin` names this site's host is ours.
	 */
	private static function same_site( string $origin ): bool {
		if ( '' === $origin ) {
			return true;
		}

		$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );

		return strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) ) === $host;
	}

	/**
	 * A ceiling per network: a player asks once per expired link, so dozens
	 * of asks a minute from one place are not a player.
	 */
	private static function within_limit(): bool {
		$network = ProtectedMedia::client_fingerprint();

		if ( '' === $network ) {
			return true;
		}

		$key   = 'imgp_stream_net_' . md5( $network );
		$count = (int) get_transient( $key );

		if ( $count >= (int) apply_filters( 'imagina_player_stream_refresh_limit', 120 ) ) {
			return false;
		}

		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );

		return true;
	}
}
