<?php
/**
 * Serves a subtitle file as WebVTT, converting SubRip on the way.
 *
 * Browsers read WebVTT and nothing else, but SRT is what people have. Rather
 * than convert on upload — which would mean a second file to keep in step with
 * the first, and a migration for everything already in the library — this
 * converts on read and lets caching do the rest.
 *
 * Public on purpose: a subtitle is not a secret, it is displayed over the video
 * to everyone who can watch it. What the endpoint refuses is being used to read
 * anything that is not a subtitle file inside this site's own uploads.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Rest;

use ImaginaPlayer\Media\Captions;
use ImaginaPlayer\Player\Attributes;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CaptionController {

	/**
	 * A day. The file behind a given URL does not change — WordPress gives a
	 * re-upload a new name — so this can be cached hard.
	 */
	private const CACHE_SECONDS = DAY_IN_SECONDS;

	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			PeaksController::REST_NAMESPACE,
			'/caption',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'serve' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'src' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Send the file, or 404 without saying which of the reasons applied.
	 */
	public function serve( WP_REST_Request $request ): void {
		$src = Attributes::sanitize_media_url( (string) $request->get_param( 'src' ) );
		$vtt = '' === $src ? null : Captions::read( $src );

		if ( null === $vtt ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Not found';
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: text/vtt; charset=utf-8' );
		header( 'Cache-Control: public, max-age=' . self::CACHE_SECONDS . ', immutable' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . strlen( $vtt ) );

		echo $vtt; // phpcs:ignore WordPress.Security.EscapeOutput -- a VTT body, not HTML.
		exit;
	}

	/**
	 * The URL a `<track>` should point at for a given subtitle file.
	 *
	 * A VTT file is already what the browser wants, so it is linked directly and
	 * this endpoint is not involved at all. Only SRT takes the long way round.
	 */
	public static function track_url( string $src ): string {
		if ( ! Captions::is_srt( $src ) ) {
			return $src;
		}

		return add_query_arg(
			array( 'src' => rawurlencode( $src ) ),
			rest_url( PeaksController::REST_NAMESPACE . '/caption' )
		);
	}
}
