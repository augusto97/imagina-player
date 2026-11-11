<?php
/**
 * Where the editor starts a transcription and asks how it is going.
 *
 * For someone who may upload files and edit the one being transcribed, and a
 * job's answer only for whoever started it: the file it names is theirs to
 * see, and nobody else's business.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Rest;

use ImaginaPlayer\Media\Transcriber;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TranscriptionController {

	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			PeaksController::REST_NAMESPACE,
			'/transcribe',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'start' ),
				'permission_callback' => static fn( WP_REST_Request $request ): bool => current_user_can( 'upload_files' ) && current_user_can( 'edit_post', (int) $request->get_param( 'attachment' ) ),
				'args'                => array(
					'attachment' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'language'   => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			PeaksController::REST_NAMESPACE,
			'/transcribe/(?P<job>[a-f0-9]{16})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => static fn(): bool => current_user_can( 'upload_files' ),
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function start( WP_REST_Request $request ) {
		$attachment = (int) $request->get_param( 'attachment' );
		$mime       = (string) get_post_mime_type( $attachment );

		if ( ! Transcriber::available() ) {
			return new WP_Error( 'imagina_player_no_service', __( 'No transcription service is set up under Imagina Player → Integrations.', 'imagina-player' ), array( 'status' => 400 ) );
		}

		if ( ! str_starts_with( $mime, 'audio/' ) && ! str_starts_with( $mime, 'video/' ) ) {
			return new WP_Error( 'imagina_player_not_media', __( 'Only an audio or video file from the media library can be transcribed.', 'imagina-player' ), array( 'status' => 400 ) );
		}

		$language = (string) ( $request->get_param( 'language' ) ?? '' );

		if ( '' === trim( $language ) ) {
			$language = (string) ( \ImaginaPlayer\Leads\Integrations::get()['transcription']['language'] ?? '' );
		}

		$job = Transcriber::start( $attachment, $language, get_current_user_id() );

		return new WP_REST_Response( array( 'job' => $job ), 202 );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function status( WP_REST_Request $request ) {
		$state = Transcriber::status( (string) $request->get_param( 'job' ) );

		if ( null === $state || ( (int) $state['user'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) ) {
			return new WP_Error( 'imagina_player_no_job', __( 'There is no such transcription.', 'imagina-player' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response(
			array_intersect_key( $state, array_flip( array( 'status', 'message', 'url', 'id', 'srclang' ) ) ),
			200
		);
	}
}
