<?php
/**
 * REST endpoints for waveform peaks.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Rest;

use ImaginaPlayer\Peaks\PeaksGenerator;
use ImaginaPlayer\Peaks\PeaksRepository;
use ImaginaPlayer\Peaks\PeaksToken;
use ImaginaPlayer\Player\Attributes;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PeaksController {

	public const REST_NAMESPACE = 'imagina-player/v1';

	/**
	 * Upper bound on a submitted waveform. 2000 bars is the highest resolution the
	 * settings allow, so anything larger is either a mistake or an abuse attempt.
	 */
	private const MAX_SUBMITTED_BARS = 2000;

	private PeaksRepository $repository;

	public function __construct( ?PeaksRepository $repository = null ) {
		$this->repository = $repository ?? new PeaksRepository();
	}

	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/peaks',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_peaks' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'key' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => array( $this, 'sanitize_key_arg' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'store_peaks' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'token'    => array(
							'type'     => 'string',
							'required' => true,
						),
						'peaks'    => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array( 'type' => 'number' ),
						),
						'duration' => array(
							'type'    => 'number',
							'default' => 0,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/peaks/pending',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_pending' ),
				'permission_callback' => static fn(): bool => current_user_can( 'upload_files' ),
				'args'                => array(
					'limit' => array(
						'type'    => 'integer',
						'default' => 200,
					),
				),
			)
		);

		/*
		 * Storing peaks measured in the *admin's* browser.
		 *
		 * On a host with no ffmpeg, a long recording never gets a waveform: the
		 * visitor-side fallback refuses anything over the size cap, and rightly
		 * so — nobody should download 90 MB to look at a picture. But the person
		 * editing the post can afford it once, and then nobody else has to.
		 */
		register_rest_route(
			self::REST_NAMESPACE,
			'/peaks/store',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'store_for_attachment' ),
				'permission_callback' => static function ( WP_REST_Request $request ): bool {
					$attachment_id = (int) $request->get_param( 'attachmentId' );

					// An external track belongs to no post, so there is nothing
					// to check rights over. Being able to add media to the site
					// is the right bar: the same person could upload the file.
					return $attachment_id > 0
						? current_user_can( 'edit_post', $attachment_id )
						: current_user_can( 'upload_files' );
				},
				'args'                => array(
					'attachmentId' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'src'          => array(
						'type'    => 'string',
						'default' => '',
					),
					'peaks'        => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'number' ),
					),
					'duration'     => array(
						'type'    => 'number',
						'default' => 0,
					),
				),
			)
		);

		/*
		 * Which of these files already have a waveform.
		 *
		 * For the playlist block, where several files arrive at once and the
		 * author has no way of knowing which of them the site can draw.
		 */
		register_rest_route(
			self::REST_NAMESPACE,
			'/peaks/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => static fn(): bool => current_user_can( 'upload_files' ),
				'args'                => array(
					'ids'  => array( 'type' => 'string' ),
					// A track pasted from a streaming provider has no attachment
					// to name, so it is identified by its address instead.
					'urls' => array( 'type' => 'string' ),
				),
			)
		);

		/*
		 * A same-origin doorway to a remote media file, for measuring it.
		 *
		 * A browser cannot read a file from another domain unless that domain
		 * says it may, and most media hosts do not. Without this, a track
		 * pasted from a streaming provider can never be measured anywhere: not
		 * on the server, which has no decoder, and not in the browser, which is
		 * not allowed to look.
		 *
		 * A route that fetches a URL on request is a server-side request
		 * forgery waiting to happen, so it is fenced in: it needs the right to
		 * add media, the URL goes through WordPress's own validator (which
		 * refuses anything but http and https, and refuses private and loopback
		 * addresses), the fetch uses the `safe` client so redirects are checked
		 * the same way, the size is capped, and the answer is only ever bytes
		 * with a media content type.
		 */
		register_rest_route(
			self::REST_NAMESPACE,
			'/peaks/proxy',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'proxy' ),
				'permission_callback' => static fn(): bool => current_user_can( 'upload_files' ),
				'args'                => array(
					'src' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/peaks/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_peaks' ),
				'permission_callback' => static function ( WP_REST_Request $request ): bool {
					// `upload_files` alone would let any contributor spend server CPU
					// on any attachment; require rights over this one.
					return current_user_can( 'edit_post', (int) $request->get_param( 'attachmentId' ) );
				},
				'args'                => array(
					'attachmentId' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'force'        => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	public function sanitize_key_arg( mixed $value ): string {
		$value = is_string( $value ) ? $value : '';

		return preg_match( '/^(att_\d+|url_[a-f0-9]{32})$/', $value ) ? $value : '';
	}

	public function get_peaks( WP_REST_Request $request ): WP_REST_Response {
		$key = (string) $request->get_param( 'key' );

		if ( '' === $key ) {
			return new WP_REST_Response( array( 'peaks' => null ), 400 );
		}

		$record = $this->repository->get( $key );

		$response = new WP_REST_Response(
			array(
				'key'        => $key,
				'resolution' => $record['resolution'] ?? 0,
				'duration'   => $record['duration'] ?? 0,
				'peaks'      => $record['peaks'] ?? null,
			),
			$record ? 200 : 404
		);

		if ( $record ) {
			$response->header( 'Cache-Control', 'public, max-age=86400' );
		}

		return $response;
	}

	/**
	 * Accept peaks computed by a visitor's browser.
	 *
	 * Authorisation is the signed token minted when the player was rendered, not
	 * a user capability — the whole point is that anonymous visitors warm the
	 * cache on hosts without ffmpeg.
	 */
	/**
	 * Store peaks an editor measured in their own browser.
	 *
	 * The visitor path is write-once and token-gated, because it is public.
	 * This one is not public: it needs rights over the attachment. That also
	 * makes overwriting reasonable — an editor asking again is asking again,
	 * not racing anybody.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	/**
	 * Largest remote file this will fetch. Beyond it, no browser was going to
	 * decode the thing anyway.
	 */
	private const PROXY_MAX_BYTES = 250 * MB_IN_BYTES;

	/**
	 * Hand a remote media file to the editor's browser, same-origin.
	 *
	 * Never echoes anything from the remote server but its bytes: no headers
	 * are passed through, and the content type is one we chose from a short
	 * list rather than one it told us.
	 */
	public function proxy( WP_REST_Request $request ): void {
		$src = Attributes::sanitize_media_url( (string) $request->get_param( 'src' ) );

		// WordPress's own check: http and https only, no private or loopback
		// addresses, no unusual ports.
		if ( '' === $src || ! wp_http_validate_url( $src ) ) {
			$this->refuse( 400 );
		}

		$head = wp_safe_remote_head(
			$src,
			array(
				'timeout'     => 15,
				'redirection' => 3,
			)
		);

		if ( is_wp_error( $head ) ) {
			$this->refuse( 502 );
		}

		$length = (int) wp_remote_retrieve_header( $head, 'content-length' );

		if ( $length > self::PROXY_MAX_BYTES ) {
			$this->refuse( 413 );
		}

		$type = strtolower( (string) wp_remote_retrieve_header( $head, 'content-type' ) );

		if ( ! $this->looks_like_media( $type ) ) {
			$this->refuse( 415 );
		}

		$temp = wp_tempnam( 'imagina-peaks' );

		if ( ! $temp ) {
			$this->refuse( 500 );
		}

		$body = wp_safe_remote_get(
			$src,
			array(
				'timeout'     => 120,
				'redirection' => 3,
				'stream'      => true,
				'filename'    => $temp,
			)
		);

		$size = (int) ( @filesize( $temp ) ?: 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- missing file is handled below.

		if ( is_wp_error( $body ) || 200 !== (int) wp_remote_retrieve_response_code( $body ) || $size > self::PROXY_MAX_BYTES ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- our own temp file.
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best effort.
			$this->refuse( is_wp_error( $body ) ? 502 : 413 );
		}

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: ' . ( str_starts_with( $type, 'video/' ) ? 'video/mp4' : 'audio/mpeg' ) );
		header( 'Content-Length: ' . $size );
		header( 'X-Content-Type-Options: nosniff' );

		// Chunked, because the whole point is that this file is large.
		$handle = fopen( $temp, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- our own temp file.

		if ( $handle ) {
			while ( ! feof( $handle ) ) {
				echo fread( $handle, 65536 ); // phpcs:ignore WordPress.Security.EscapeOutput -- media bytes.
				flush();
			}

			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- as above.
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- our own temp file.
		@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best effort.
		exit;
	}

	/**
	 * Whether a content type is plausibly a media file.
	 *
	 * `octet-stream` is allowed because plenty of storage buckets serve every
	 * file that way, and refusing it would refuse half the world's audio.
	 */
	private function looks_like_media( string $type ): bool {
		$type = trim( explode( ';', $type )[0] );

		return str_starts_with( $type, 'audio/' )
			|| str_starts_with( $type, 'video/' )
			|| 'application/octet-stream' === $type
			|| '' === $type;
	}

	/**
	 * Say no without saying why: the reasons name what is reachable from this
	 * server, and that is not the caller's business even when they are staff.
	 */
	private function refuse( int $status ): void {
		status_header( $status );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'No';
		exit;
	}

	/**
	 * Report which attachments have a stored waveform.
	 *
	 * @return WP_REST_Response
	 */
	public function status( WP_REST_Request $request ): WP_REST_Response {
		$out = array();

		$ids = array_slice(
			array_filter(
				array_map( 'intval', explode( ',', (string) $request->get_param( 'ids' ) ) )
			),
			0,
			200
		);

		foreach ( $ids as $id ) {
			$out[] = array(
				'id'       => $id,
				'src'      => (string) ( wp_get_attachment_url( $id ) ?: '' ),
				'hasPeaks' => null !== $this->repository->get( 'att_' . $id ),
			);
		}

		/*
		 * URLs are newline-separated rather than comma-separated: a URL can
		 * contain a comma perfectly legally, and splitting on one would quietly
		 * cut somebody's signed link in half.
		 */
		$urls = array_slice(
			array_filter(
				array_map(
					array( Attributes::class, 'sanitize_media_url' ),
					array_map( 'trim', explode( "\n", (string) $request->get_param( 'urls' ) ) )
				)
			),
			0,
			200
		);

		foreach ( $urls as $url ) {
			$out[] = array(
				'id'       => 0,
				'src'      => $url,
				'hasPeaks' => null !== $this->repository->get( 'url_' . md5( $url ) ),
			);
		}

		return new WP_REST_Response( array( 'tracks' => $out ), 200 );
	}

	public function store_for_attachment( WP_REST_Request $request ) {
		$attachment_id = (int) $request->get_param( 'attachmentId' );
		$src           = Attributes::sanitize_media_url( (string) $request->get_param( 'src' ) );

		if ( $attachment_id > 0 && 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error(
				'imagina_player_not_attachment',
				__( 'That is not a media file.', 'imagina-player' ),
				array( 'status' => 404 )
			);
		}

		if ( $attachment_id <= 0 && '' === $src ) {
			return new WP_Error(
				'imagina_player_no_track',
				__( 'No track was named.', 'imagina-player' ),
				array( 'status' => 400 )
			);
		}

		// The same key the renderer will look under, so what is stored here is
		// what gets found there.
		$key = $attachment_id > 0 ? 'att_' . $attachment_id : 'url_' . md5( $src );

		$raw = $request->get_param( 'peaks' );

		if ( ! is_array( $raw ) || array() === $raw ) {
			return new WP_Error(
				'imagina_player_invalid_peaks',
				__( 'No waveform data was supplied.', 'imagina-player' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $raw ) > self::MAX_SUBMITTED_BARS ) {
			$raw = array_slice( $raw, 0, self::MAX_SUBMITTED_BARS );
		}

		$peaks = array();

		foreach ( $raw as $value ) {
			if ( ! is_numeric( $value ) ) {
				return new WP_Error(
					'imagina_player_invalid_peaks',
					__( 'The waveform data is malformed.', 'imagina-player' ),
					array( 'status' => 400 )
				);
			}

			$peaks[] = max( 0.0, min( 1.0, (float) $value ) );
		}

		$peaks  = PeaksRepository::normalize( PeaksRepository::resample( $peaks, PeaksRepository::resolution() ) );
		$stored = $this->repository->save( $key, $peaks, (float) $request->get_param( 'duration' ) );

		return new WP_REST_Response( array( 'stored' => $stored ), $stored ? 201 : 500 );
	}

	public function store_peaks( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$token = (string) $request->get_param( 'token' );
		$claim = PeaksToken::verify( $token );

		if ( null === $claim ) {
			return new WP_Error(
				'imagina_player_invalid_token',
				__( 'This waveform grant is missing or expired.', 'imagina-player' ),
				array( 'status' => 403 )
			);
		}

		$key = $claim['key'];

		// Peaks are write-once: whoever gets there first wins, and a second
		// submission for the same track is a no-op rather than an overwrite.
		if ( null !== $this->repository->get( $key ) ) {
			return new WP_REST_Response(
				array(
					'stored' => false,
					'reason' => 'exists',
				),
				200
			);
		}

		$raw = $request->get_param( 'peaks' );

		if ( ! is_array( $raw ) || array() === $raw ) {
			return new WP_Error(
				'imagina_player_invalid_peaks',
				__( 'No waveform data was supplied.', 'imagina-player' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $raw ) > self::MAX_SUBMITTED_BARS ) {
			$raw = array_slice( $raw, 0, self::MAX_SUBMITTED_BARS );
		}

		$peaks = array();

		foreach ( $raw as $value ) {
			if ( ! is_numeric( $value ) ) {
				return new WP_Error(
					'imagina_player_invalid_peaks',
					__( 'The waveform data is malformed.', 'imagina-player' ),
					array( 'status' => 400 )
				);
			}

			$peaks[] = max( 0.0, min( 1.0, (float) $value ) );
		}

		$lock_key = 'imagina_peaks_lock_' . md5( $key );

		if ( get_transient( $lock_key ) ) {
			return new WP_REST_Response(
				array(
					'stored' => false,
					'reason' => 'locked',
				),
				429
			);
		}

		set_transient( $lock_key, 1, MINUTE_IN_SECONDS );

		$resolution = $claim['resolution'] > 0 ? $claim['resolution'] : PeaksRepository::resolution();
		$peaks      = PeaksRepository::normalize( PeaksRepository::resample( $peaks, $resolution ) );

		$stored = $this->repository->save( $key, $peaks, (float) $request->get_param( 'duration' ) );

		delete_transient( $lock_key );

		return new WP_REST_Response(
			array(
				'stored' => $stored,
				'key'    => $key,
			),
			$stored ? 201 : 500
		);
	}

	/**
	 * Audio and video attachments that have no cached waveform yet.
	 *
	 * Long recordings cannot be analysed in the browser, and the background job
	 * depends on WP-Cron firing, so the admin needs a way to see what is missing
	 * and to push it through on demand.
	 */
	public function list_pending( WP_REST_Request $request ): WP_REST_Response {
		$limit = max( 1, min( 500, (int) $request->get_param( 'limit' ) ) );

		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'audio', 'video' ),
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => false,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin-only tool.
					array(
						'key'     => PeaksRepository::META_KEY,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$pending = array();

		foreach ( $query->posts as $attachment_id ) {
			$file = get_attached_file( (int) $attachment_id );

			$pending[] = array(
				'id'    => (int) $attachment_id,
				'title' => (string) get_the_title( $attachment_id ),
				// So the browser can fetch it when the server cannot measure it.
				'url'   => (string) ( wp_get_attachment_url( (int) $attachment_id ) ?: '' ),
				'bytes' => is_string( $file ) && is_readable( $file ) ? (int) filesize( $file ) : 0,
			);
		}

		return new WP_REST_Response(
			array(
				'pending'   => $pending,
				'total'     => (int) $query->found_posts,
				'available' => PeaksGenerator::is_available(),
			),
			200
		);
	}

	public function generate_peaks( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$attachment_id = (int) $request->get_param( 'attachmentId' );

		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error(
				'imagina_player_unknown_attachment',
				__( 'That attachment does not exist.', 'imagina-player' ),
				array( 'status' => 404 )
			);
		}

		if ( ! PeaksGenerator::is_available() ) {
			return new WP_Error(
				'imagina_player_no_ffmpeg',
				__( 'Server-side waveform generation is unavailable on this host.', 'imagina-player' ),
				array( 'status' => 501 )
			);
		}

		$peaks = PeaksGenerator::generate_for_attachment(
			$attachment_id,
			$this->repository,
			(bool) $request->get_param( 'force' )
		);

		if ( null === $peaks ) {
			$record = $this->repository->get( 'att_' . $attachment_id );

			if ( $record ) {
				return new WP_REST_Response(
					array(
						'generated' => false,
						'reason'    => 'exists',
					),
					200
				);
			}

			return new WP_Error(
				'imagina_player_generation_failed',
				__( 'The waveform could not be generated for this file.', 'imagina-player' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'generated'  => true,
				'resolution' => count( $peaks ),
			),
			201
		);
	}
}
