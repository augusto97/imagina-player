<?php
/**
 * REST endpoints for waveform peaks.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Rest;

use ImaginaPlayer\Blocks\BlockRegistrar;
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
							// Core validates the schema before any callback
							// runs, so an oversized array is refused before it
							// costs anything.
							'maxItems' => self::MAX_SUBMITTED_BARS,
						),
						'duration' => array(
							'type'    => 'number',
							'default' => 0,
							'minimum' => 0,
							'maximum' => DAY_IN_SECONDS,
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
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				// Settings-screen tools: they list every file on the site and
				// describe the server, which is more than an author needs.
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
						'maxItems' => self::MAX_SUBMITTED_BARS,
					),
					'duration'     => array(
						'type'    => 'number',
						'default' => 0,
						'minimum' => 0,
						'maximum' => DAY_IN_SECONDS,
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
		/*
		 * Why a file could not be measured, answered by this server rather than
		 * guessed at from the outside.
		 *
		 * Everything the browser can see is that a request failed. Whether the
		 * far end refused us, whether something in front of WordPress refused
		 * the request before PHP ever saw it, whether PHP is allowed to run
		 * other programs — all of that is knowable here and nowhere else, and
		 * without it the conversation is somebody reading a status code to
		 * somebody else who is inventing reasons for it.
		 */
		register_rest_route(
			self::REST_NAMESPACE,
			'/peaks/diagnose',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'diagnose' ),
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				// Settings-screen tools: they list every file on the site and
				// describe the server, which is more than an author needs.
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

		/*
		 * The same visibility core gives an attachment: readable when it hangs
		 * off nothing or off a post the visitor may read. Without this the
		 * route answered for any id — confirming that an attachment exists,
		 * and how long it is, for private, draft and protected media alike.
		 */
		$attachment_id = PeaksRepository::attachment_id_from_key( $key );

		if ( $attachment_id > 0 && ! $this->attachment_is_visible( $attachment_id ) ) {
			return new WP_REST_Response( array( 'peaks' => null ), 404 );
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
		} else {
			/*
			 * A miss must not be remembered. With no header at all a cache is
			 * allowed to keep a 404 heuristically, and a CDN that did would go
			 * on answering "no waveform" after one had been stored — sending
			 * every visitor back to the slow path for as long as it held it.
			 */
			$response->header( 'Cache-Control', 'no-store' );
		}

		return $response;
	}

	/**
	 * Whether the current visitor may know this attachment exists.
	 *
	 * Mirrors the rule core's own media endpoint applies: an attachment is
	 * public when it belongs to no post, or to a post the visitor can read.
	 *
	 * @param int $attachment_id The attachment.
	 */
	private function attachment_is_visible( int $attachment_id ): bool {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}

		$parent = (int) get_post_field( 'post_parent', $attachment_id );

		if ( $parent <= 0 ) {
			return true;
		}

		return 'publish' === get_post_status( $parent ) || current_user_can( 'read_post', $parent );
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
	 * How many times one slice is asked for before giving up on it.
	 *
	 * Three, not one, because a large file is a dozen or more requests to the
	 * same host in a row and a far end that drops one of them is unremarkable.
	 * Only a transport failure is retried — a refusal is an answer.
	 */
	private const FETCH_ATTEMPTS = 3;

	/**
	 * Hand a remote media file to the editor's browser, same-origin.
	 *
	 * Never echoes anything from the remote server but its bytes: no headers
	 * are passed through, and the content type is one we chose from a short
	 * list rather than one it told us.
	 */
	/**
	 * Walk the steps the doorway takes, reporting what each one did.
	 *
	 * Deliberately returns JSON with a 200 whatever it finds: the point is to
	 * be readable, and an endpoint that fails when the thing it is describing
	 * fails is another mystery rather than an answer.
	 *
	 * Reaching this at all is itself a result. It has the same shape as the
	 * doorway — a URL inside a query string — which is a shape security layers
	 * and firewalls are suspicious of. If the browser gets a report back, the
	 * request reaches PHP; if it gets a gateway error, something in front of
	 * WordPress is answering, and no amount of PHP configuration will change
	 * that.
	 */
	public function diagnose( WP_REST_Request $request ): WP_REST_Response {
		$src   = Attributes::sanitize_media_url( (string) $request->get_param( 'src' ) );
		$steps = array();

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		$environment = array(
			'php'               => PHP_VERSION,
			'sapi'              => PHP_SAPI,
			'maxExecutionTime'  => (string) ini_get( 'max_execution_time' ),
			'memoryLimit'       => (string) ini_get( 'memory_limit' ),
			// The question the ffmpeg notice answers, with the evidence beside
			// it: a php.ini edited for the wrong SAPI is the usual reason that
			// notice does not go away.
			'popenDisabled'     => in_array( 'popen', $disabled, true ) || ! function_exists( 'popen' ),
			'disableFunctions'  => (string) ini_get( 'disable_functions' ),
			'ffmpeg'            => PeaksGenerator::diagnosis(),
		);

		$steps[] = array(
			'step'   => 'url',
			'ok'     => '' !== $src && (bool) wp_http_validate_url( $src ),
			'detail' => '' === $src ? 'empty after sanitising' : $src,
		);

		if ( '' === $src || ! wp_http_validate_url( $src ) ) {
			return new WP_REST_Response(
				array( 'environment' => $environment, 'steps' => $steps ),
				200
			);
		}

		/*
		 * The same request twice, once anonymous and once saying who is asking.
		 *
		 * A media host with hotlink protection decides by `Referer`: a browser
		 * on the site sends one and is allowed, a request from the site's own
		 * server sends none and is refused. That is how a file can play
		 * perfectly on the page and be impossible to measure — and the two
		 * lines below are what tells that apart from every other reason for a
		 * refusal, rather than leaving it to be guessed at.
		 */
		$steps[] = $this->probe_step( 'head-anonymous', $src, array( 'method' => 'HEAD' ) );
		$steps[] = $this->probe_step(
			'head-as-this-site',
			$src,
			array( 'method' => 'HEAD', 'headers' => $this->fetch_headers() )
		);

		// Does the far end serve byte ranges? Everything about fetching a large
		// file one piece at a time depends on the answer.
		$steps[] = $this->probe_step(
			'range',
			$src,
			array( 'headers' => $this->fetch_headers() + array( 'Range' => 'bytes=0-1023' ) )
		);

		/*
		 * And the last piece, which is a different request from the first.
		 *
		 * The check used to ask for `bytes=0-1023` and stop there, so it came
		 * back entirely green about a file whose measurement was failing on the
		 * thirteenth of thirteen slices. The first kilobyte of a file is the
		 * one request that is always cheap, always cached and always allowed;
		 * proving it works proves very little.
		 *
		 * The tail is where the interesting failures live — a range that runs
		 * to the last byte, on a connection the host has already served a dozen
		 * times — so the check asks for exactly that, using the size the far
		 * end just reported.
		 */
		$known = 0;

		foreach ( $steps as $step ) {
			if ( 'head-as-this-site' === ( $step['step'] ?? '' ) ) {
				$known = (int) ( $step['length'] ?? 0 );
			}
		}

		if ( $known > 0 ) {
			$tail = max( 0, $known - 262144 );

			$step = $this->probe_step(
				'range-tail',
				$src,
				array( 'headers' => $this->fetch_headers() + array( 'Range' => 'bytes=' . $tail . '-' . ( $known - 1 ) ) )
			);

			/*
			 * A truncated tail is a fact about that host, not a fault here.
			 *
			 * Some CDNs answer a range ending at the last byte, promise a
			 * length, and then close the connection a few kilobytes short.
			 * cURL calls that error 18. It is why measuring a long file used to
			 * fail on its final piece, and it is now handled — the piece is
			 * asked for again, and a tail that never arrives is left out of a
			 * waveform that is otherwise complete.
			 *
			 * So it is reported as something to know rather than something to
			 * fix. A red line beside a waveform that works is its own kind of
			 * wrong answer.
			 */
			if ( false === ( $step['ok'] ?? false ) && str_contains( (string) ( $step['error'] ?? '' ), 'error 18' ) ) {
				$step['ok']      = true;
				$step['handled'] = 'this host cuts the last few kilobytes short; measuring retries and copes without them';
			}

			$steps[] = $step;
		}

		$steps[] = array(
			'step'   => 'sent-as',
			'ok'     => true,
			'detail' => 'Referer: ' . home_url( '/' ),
		);

		return new WP_REST_Response(
			array( 'environment' => $environment, 'steps' => $steps ),
			200
		);
	}

	/**
	 * One request to the far end, described.
	 *
	 * @param string               $name What this step is called.
	 * @param string               $src  Where to ask.
	 * @param array<string, mixed> $args Extra arguments for the HTTP client.
	 * @return array<string, mixed>
	 */
	private function probe_step( string $name, string $src, array $args ): array {
		$started = microtime( true );

		$response = wp_safe_remote_request(
			$src,
			$args + array(
				'method'      => 'GET',
				'timeout'     => 30,
				'redirection' => 3,
			)
		);

		$seconds = round( microtime( true ) - $started, 2 );

		if ( is_wp_error( $response ) ) {
			return array(
				'step'    => $name,
				'ok'      => false,
				'seconds' => $seconds,
				'error'   => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return array(
			'step'        => $name,
			'ok'          => $code >= 200 && $code < 300,
			'seconds'     => $seconds,
			'status'      => $code,
			'type'        => (string) wp_remote_retrieve_header( $response, 'content-type' ),
			'length'      => (string) wp_remote_retrieve_header( $response, 'content-length' ),
			'acceptsRanges' => (string) wp_remote_retrieve_header( $response, 'accept-ranges' ),
			'contentRange'  => (string) wp_remote_retrieve_header( $response, 'content-range' ),
			'bytes'       => strlen( (string) wp_remote_retrieve_body( $response ) ),
		);
	}

	public function proxy( WP_REST_Request $request ): void {
		$src = Attributes::sanitize_media_url( (string) $request->get_param( 'src' ) );

		// WordPress's own check: http and https only, no private or loopback
		// addresses, no unusual ports.
		if ( '' === $src || ! wp_http_validate_url( $src ) ) {
			$this->refuse( 400, 'bad-url' );
		}

		/*
		 * Asked for once, not once per slice.
		 *
		 * A large file arrives in a dozen or more pieces, and each piece used to
		 * begin with its own HEAD — two requests to the file's host for every
		 * four megabytes. That is a lot of asking for something already known,
		 * and every extra request is another chance for a rate limit or a
		 * flaky moment to refuse one piece and fail the whole measurement.
		 *
		 * For a slice, the download's own answer carries everything that has to
		 * be checked: its status, its type and its length.
		 */
		$range_header = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_RANGE'] ) ) : '';
		$slicing      = '' !== $range_header;

		$head = $slicing
			? null
			: wp_safe_remote_head(
				$src,
				array(
					'timeout'     => 15,
					'redirection' => 3,
					'headers'     => $this->fetch_headers(),
				)
			);

		if ( is_wp_error( $head ) ) {
			$this->refuse( 502, 'upstream-unreachable', $head->get_error_message() );
		}

		/*
		 * The file's own server said no to us as well. Worth passing on
		 * exactly: a bucket or a CDN that refuses this site is almost always
		 * hotlink protection or a signed-URL rule, and that is a setting on
		 * that service rather than anything here.
		 */
		$upstream = null === $head ? 0 : (int) wp_remote_retrieve_response_code( $head );

		if ( $upstream >= 400 ) {
			$this->refuse( 502, 'upstream-' . $upstream );
		}

		$length = null === $head ? 0 : (int) wp_remote_retrieve_header( $head, 'content-length' );
		$total  = $length;

		/*
		 * Worked out before the size check, because the size that matters is
		 * the size of what was asked for. A three hundred megabyte recording is
		 * refused as a whole and perfectly reasonable four megabytes at a time.
		 */
		$range = $this->requested_range( $total );

		if ( null === $range && $length > self::PROXY_MAX_BYTES ) {
			$this->refuse( 413, 'too-large' );
		}

		$type = null === $head ? '' : strtolower( (string) wp_remote_retrieve_header( $head, 'content-type' ) );

		// Checked against the download's own answer when there was no head
		// request to check, which is the same guarantee a step later.
		if ( null !== $head && ! $this->looks_like_media( $type ) ) {
			$this->refuse( 415, 'not-media' );
		}

		/*
		 * A slice is what stops a large file being one long request.
		 *
		 * The whole file used to come down in a single call: fetched to a
		 * temporary file, then read back and echoed — two full transfers of a
		 * fifty megabyte recording inside one PHP request, with no time limit
		 * raised. On a host where `max_execution_time` is thirty seconds, PHP
		 * is killed part-way through and the web server answers with its own
		 * 502, carrying none of the reasons this endpoint sends. Which is
		 * exactly how it was reported: "the server answered 502" and nothing
		 * more. A file small enough to finish in time worked; the same file on
		 * the same site failed once it grew, so it looked arbitrary.
		 */

		/*
		 * And more time even so, because a slow remote server can outlast the
		 * limit on a slice as easily as on a file. Best effort: plenty of hosts
		 * refuse this, which is why the slicing above is the actual fix rather
		 * than the fallback.
		 */
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors -- refused on some hosts, and the request is still worth trying.
			@set_time_limit( 120 );
		}

		$temp = wp_tempnam( 'imagina-peaks' );

		if ( ! $temp ) {
			$this->refuse( 500, 'no-temp-file' );
		}

		/*
		 * Removed on the way out, whichever way out it is.
		 *
		 * There are half a dozen exits below — refusals that call exit(), and
		 * the browser closing the connection part-way through the stream, which
		 * ends the script wherever it happens to be — and the unlink at the end
		 * of the happy path covered exactly one of them. Each of the others
		 * left up to 250 MB in the temp directory, and an author who asked for
		 * a large non-media file with a Range header could do that on purpose.
		 */
		register_shutdown_function(
			static function () use ( $temp ): void {
				if ( is_file( $temp ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- our own temp file.
					@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best effort.
				}
			}
		);

		$get_args = array(
			'timeout'     => 120,
			'redirection' => 3,
			'stream'      => true,
			'filename'    => $temp,
			'headers'     => $this->fetch_headers(),
			/*
			 * Enforced while downloading, not checked afterwards. The size was
			 * compared against Content-Length before the fetch and against
			 * the file after it — and a response with no Content-Length (a
			 * live stream, a chunked answer, a host that ignores Range) has
			 * neither, so it was written to disk for as long as the timeout
			 * allowed.
			 */
			'limit_response_size' => self::PROXY_MAX_BYTES,
		);

		if ( null !== $range ) {
			$get_args['headers']['Range'] = 'bytes=' . $range[0] . '-' . $range[1];
		}

		/*
		 * Asked for more than once before giving up.
		 *
		 * A fifty megabyte recording is thirteen requests to the same host in a
		 * row, and there was no retry anywhere in the chain: one connection
		 * reset — the thirteenth, in the case that prompted this — threw away
		 * twelve slices that had arrived perfectly and failed the whole
		 * measurement. A far end that drops one request in a dozen is
		 * completely ordinary, and every other client on the web copes with it
		 * by asking again.
		 *
		 * Only a transport failure is retried. A refusal is an answer, and
		 * asking a host that said no to say it twice more is just rudeness.
		 */
		$body = null;

		for ( $attempt = 1; $attempt <= self::FETCH_ATTEMPTS; $attempt++ ) {
			$body = wp_safe_remote_get( $src, $get_args );

			if ( ! is_wp_error( $body ) ) {
				break;
			}

			if ( $attempt < self::FETCH_ATTEMPTS ) {
				usleep( 300000 * $attempt );
			}
		}

		$size = (int) ( @filesize( $temp ) ?: 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- missing file is handled below.

		$got = is_wp_error( $body ) ? 0 : (int) wp_remote_retrieve_response_code( $body );

		if ( ! is_wp_error( $body ) && null === $head ) {
			// The checks the head request would have made, made here instead.
			$type = strtolower( (string) wp_remote_retrieve_header( $body, 'content-type' ) );

			if ( ! $this->looks_like_media( $type ) ) {
				$this->refuse( 415, 'not-media' );
			}

			$range_of = explode( '/', (string) wp_remote_retrieve_header( $body, 'content-range' ) );
			$total    = (int) ( $range_of[1] ?? 0 );
		}

		/*
		 * 200 or 206. A server answering a `Range` request correctly answers
		 * 206, and this demanded exactly 200 — so the moment slices were added,
		 * every successful ranged fetch was refused as a failure, and the
		 * message told the site owner their media host had refused them with a
		 * 206. Which is a success code. The Range header was added in one place
		 * and the test for what counts as success was left alone in another.
		 */
		if ( is_wp_error( $body ) || ( 200 !== $got && 206 !== $got ) || $size > self::PROXY_MAX_BYTES ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- our own temp file.
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best effort.
			$this->refuse(
				is_wp_error( $body ) || $got >= 400 ? 502 : 413,
				is_wp_error( $body )
					? 'upstream-unreachable'
					: ( $got >= 400 ? 'upstream-' . $got : 'too-large' ),
				is_wp_error( $body )
					? $body->get_error_message()
					: sprintf( 'asked for %s, got %d bytes with status %d', $range_header, $size, $got )
			);
		}

		nocache_headers();

		/*
		 * Only 206 when the far end actually gave us the slice. A server that
		 * ignores `Range` answers 200 with the whole thing, and claiming
		 * otherwise would have the browser stitch a file out of repeated copies
		 * of the beginning.
		 */
		$partial = null !== $range && 206 === (int) wp_remote_retrieve_response_code( $body );

		status_header( $partial ? 206 : 200 );

		if ( $partial ) {
			header( sprintf( 'Content-Range: bytes %d-%d/%s', $range[0], $range[0] + $size - 1, $total > 0 ? (string) $total : '*' ) );
		}

		// Said on every answer, so the browser knows it may ask for a slice
		// next time rather than finding out by trying.
		header( 'Accept-Ranges: bytes' );
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
	/**
	 * The byte range the browser asked for, or null for the whole file.
	 *
	 * Only the one shape that matters here — `bytes=start-end`, one range,
	 * counted from the beginning. A suffix range (`bytes=-500`) and a list of
	 * ranges are both legal HTTP and neither is something the measuring code
	 * asks for, so they are treated as no range at all rather than answered
	 * badly.
	 *
	 * @param int $total What the far end said the file weighs, or 0 if it did not say.
	 * @return array{0: int, 1: int}|null
	 */
	private function requested_range( int $total ): ?array {
		$header = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_RANGE'] ) ) : '';

		if ( ! preg_match( '/^bytes=(\d+)-(\d*)$/', trim( $header ), $matches ) ) {
			return null;
		}

		$start = (int) $matches[1];
		$end   = '' === $matches[2] ? PHP_INT_MAX : (int) $matches[2];

		if ( $total > 0 ) {
			$end = min( $end, $total - 1 );
		}

		if ( $end < $start ) {
			return null;
		}

		// A slice this side will not fetch in one go, whatever was asked for.
		$end = min( $end, $start + self::PROXY_MAX_BYTES - 1 );

		return array( $start, $end );
	}

	/**
	 * Headers that say who is asking, and on whose behalf.
	 *
	 * A media host with hotlink protection decides by `Referer`: a browser on
	 * the site sends one, the domain is on the allowed list, and the file
	 * plays. A request made by the site's own server sends none at all, so it
	 * looks like nobody, and it is refused — which is why a file can play
	 * perfectly on the page and be impossible to measure.
	 *
	 * This is not a disguise. The request really is made by that site, for a
	 * file that site is displaying, at the request of somebody who can edit it;
	 * saying so is the accurate thing to do, and it is what makes an allow-list
	 * the site's owner has already configured actually work.
	 *
	 * @return array<string, string>
	 */
	private function fetch_headers(): array {
		return array(
			'Referer'    => home_url( '/' ),
			'User-Agent' => sprintf(
				'Mozilla/5.0 (compatible; ImaginaPlayer/%s; +%s)',
				\ImaginaPlayer\VERSION,
				home_url( '/' )
			),
		);
	}

	private function looks_like_media( string $type ): bool {
		$type = trim( explode( ';', $type )[0] );

		/*
		 * A missing type is accepted, and that is a trade-off worth naming.
		 * Plenty of storage buckets send no Content-Type at all, and refusing
		 * them would refuse the files this doorway exists for. The cost is that
		 * an internal service which also says nothing would pass this check —
		 * behind an author's login, on a public-looking name, on ports 80, 443
		 * or 8080 only, and never rendered as anything but audio bytes.
		 */
		return str_starts_with( $type, 'audio/' )
			|| str_starts_with( $type, 'video/' )
			|| 'application/octet-stream' === $type
			|| '' === $type;
	}

	/**
	 * Say no without saying why: the reasons name what is reachable from this
	 * server, and that is not the caller's business even when they are staff.
	 */
	/**
	 * Turn the request away, saying which step gave up.
	 *
	 * The reason travels in a header rather than the body because the caller is
	 * an audio decoder being pointed at a URL, not something that reads JSON —
	 * and "the fetch failed" on its own sent somebody looking at their browser
	 * when the answer was on the file's own server.
	 *
	 * @param int    $status Status for this request.
	 * @param string $reason A short machine-readable tag.
	 * @param string $detail What the far end or the HTTP client actually said.
	 */
	private function refuse( int $status, string $reason = '', string $detail = '' ): void {
		/*
		 * Never a 5xx, whatever the reason.
		 *
		 * A web server in front of PHP is entitled to treat a 5xx from its
		 * backend as the backend having failed, and to replace the whole
		 * response with its own error page. LiteSpeed does. So a refusal sent
		 * as 502 arrived as a bare 502 with the reason header stripped and the
		 * body swapped — which is exactly what was reported, and why two
		 * explanations in a row were guesses: the message that said what had
		 * actually happened was being thrown away in transit.
		 *
		 * 424 says the request failed because something it depended on failed,
		 * which is the truth, and no gateway rewrites a 4xx.
		 */
		if ( $status >= 500 ) {
			$status = 424;
		}

		status_header( $status );
		header( 'Content-Type: text/plain; charset=utf-8' );

		if ( '' !== $reason ) {
			header( 'X-Imagina-Reason: ' . $reason );
		}

		/*
		 * The sentence the HTTP client wrote, passed on rather than binned.
		 *
		 * `upstream-unreachable` is the tag for "the request did not come
		 * back", and on its own it is nearly useless: a name resolution
		 * failure, a certificate that did not verify, a connection reset at
		 * byte forty million and a timeout are four completely different
		 * problems with four different fixes, and cURL says which one it was
		 * every time. That sentence was being read into a variable and dropped,
		 * so what reached the person was "could not reach the server" — and
		 * three explanations in a row got invented to fill the gap.
		 *
		 * Header only, on one line, and short: this is somebody else's text
		 * arriving in ours, so it is stripped of anything that could end a
		 * header early or start a new one.
		 */
		$said = trim( preg_replace( '/[^\x20-\x7e]+/', ' ', $detail ) ?? '' );

		if ( '' !== $said ) {
			header( 'X-Imagina-Detail: ' . substr( $said, 0, 200 ) );
		}

		/*
		 * And in the body as well. Two reasons: a caching layer or a security
		 * plugin that strips unknown headers would otherwise take the
		 * explanation away, and a header cannot be seen from a command line,
		 * which is where this is tested.
		 */
		echo 'No' . ( '' === $reason ? '' : ': ' . $reason ); // phpcs:ignore WordPress.Security.EscapeOutput -- one of our own tags.

		if ( '' !== $said ) {
			// Second line, for the same reason the tag is in the body at all:
			// a layer in front of WordPress that strips headers it does not
			// recognise would otherwise take the explanation with it.
			echo "\nWhy: " . esc_html( substr( $said, 0, 200 ) );
		}

		exit;
	}

	/**
	 * What the editor needs to know about one track's waveform.
	 *
	 * Two separate facts, because they lead to two different offers and
	 * squeezing them into one flag hid the second of them entirely. A track
	 * with no waveform gets a warning; a track whose waveform was measured an
	 * older way is fine as it stands and can be improved, which is a different
	 * sentence and a different button.
	 *
	 * @param int    $id  Attachment id, or zero for a plain address.
	 * @param string $src Where the audio is.
	 * @param string $key Where its waveform would be stored.
	 * @return array{id:int, src:string, hasPeaks:bool, current:bool}
	 */
	private function track_state( int $id, string $src, string $key ): array {
		$stored = $this->repository->get( $key );

		return array(
			'id'       => $id,
			'src'      => $src,
			'hasPeaks' => null !== $stored,
			'current'  => PeaksRepository::is_current( $stored ),
		);
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
			$out[] = $this->track_state( $id, (string) ( wp_get_attachment_url( $id ) ?: '' ), 'att_' . $id );
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
			$out[] = $this->track_state( 0, $url, 'url_' . md5( $url ) );
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
	/**
	 * Tracks played from an address rather than from the media library.
	 *
	 * There is no row anywhere listing these — a waveform is stored under a
	 * hash of the address, and a hash cannot be turned back into one — so the
	 * only place they exist is inside the posts that play them. Which is where
	 * this looks.
	 *
	 * Block attributes rather than a search of the rendered page: the address
	 * has to be exactly the string the block holds, because that is what the
	 * renderer will hash when it goes looking for the waveform. Anything picked
	 * out of rendered HTML has been through escaping and would key differently.
	 *
	 * @param int $limit How many to return at most.
	 * @return list<array{id:int, title:string, url:string, bytes:int}>
	 */
	private function tracks_in_posts( int $limit ): array {
		$posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 200,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				/*
				 * The block namespace, from the class that registers the
				 * blocks. This was a string typed here — the wrong one, the
				 * REST namespace rather than the block one — so the search
				 * matched nothing on any real site, and the test that covered
				 * it had been written with the same wrong string in its
				 * fixtures. Two copies that had to agree, agreeing with each
				 * other and with nothing else.
				 */
				's'              => BlockRegistrar::NAMESPACE_PREFIX,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$found = array();

		foreach ( $posts as $post_id ) {
			$content = (string) get_post_field( 'post_content', (int) $post_id );

			if ( ! function_exists( 'parse_blocks' ) || ! has_blocks( $content ) ) {
				continue;
			}

			foreach ( $this->tracks_in_blocks( parse_blocks( $content ) ) as $url => $title ) {
				if ( isset( $found[ $url ] ) ) {
					continue;
				}

				$found[ $url ] = array(
					// Zero says "not an upload", which is what tells the
					// browser side to store this against the address.
					'id'    => 0,
					'title' => '' !== $title ? $title : $url,
					'url'   => $url,
					// Unknown until it is fetched, and nothing here can ask a
					// media host how big a file is without fetching it.
					'bytes' => 0,
				);

				if ( count( $found ) >= $limit ) {
					break 2;
				}
			}
		}

		return array_values( $found );
	}

	/**
	 * Addresses and titles out of this plugin's blocks, however deeply nested.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<string, string> Address to title.
	 */
	private function tracks_in_blocks( array $blocks ): array {
		$found = array();

		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );

			if ( str_starts_with( $name, BlockRegistrar::NAMESPACE_PREFIX ) ) {
				$atts = (array) ( $block['attrs'] ?? array() );

				// A single track, when it is not an upload.
				if ( BlockRegistrar::PLAYLIST_BLOCK !== $name && empty( $atts['attachmentId'] ) ) {
					$src = Attributes::sanitize_media_url( (string) ( $atts['src'] ?? '' ) );

					if ( '' !== $src ) {
						$found[ $src ] = (string) ( $atts['title'] ?? '' );
					}
				}

				// And a playlist, which holds several — under the attribute the
				// playlist block actually uses, read from its registration.
				foreach ( (array) ( $atts[ BlockRegistrar::PLAYLIST_ITEMS ] ?? array() ) as $track ) {
					if ( ! is_array( $track ) || ! empty( $track['id'] ) ) {
						continue;
					}

					$src = Attributes::sanitize_media_url( (string) ( $track['src'] ?? '' ) );

					if ( '' !== $src ) {
						$found[ $src ] = (string) ( $track['title'] ?? '' );
					}
				}
			}

			// A player inside a column, a group, a synced pattern.
			if ( ! empty( $block['innerBlocks'] ) ) {
				$found += $this->tracks_in_blocks( (array) $block['innerBlocks'] );
			}
		}

		return $found;
	}

	public function list_pending( WP_REST_Request $request ): WP_REST_Response {
		$limit = max( 1, min( 500, (int) $request->get_param( 'limit' ) ) );

		/*
		 * Every attachment, not only the ones with nothing stored.
		 *
		 * This used to ask for attachments where the waveform meta did not
		 * exist, which quietly excluded two whole groups: a file measured by an
		 * older version — the ones that most need doing again — and, because a
		 * media library has no row for them at all, every track played from an
		 * address rather than an upload. On a site whose audio all lives on a
		 * media host, "Generate missing waveforms" had nothing to generate and
		 * said so, which reads as "everything is fine".
		 *
		 * So the filter moves out of the query and into the loop, where it can
		 * ask the question that matters: is what is stored the way this version
		 * measures?
		 */
		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'audio', 'video' ),
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$pending = array();

		foreach ( $query->posts as $attachment_id ) {
			$attachment_id = (int) $attachment_id;

			if ( PeaksRepository::is_current( $this->repository->get( 'att_' . $attachment_id ) ) ) {
				continue;
			}

			$file = get_attached_file( $attachment_id );

			$pending[] = array(
				'id'    => $attachment_id,
				'title' => (string) get_the_title( $attachment_id ),
				// So the browser can fetch it when the server cannot measure it.
				'url'   => (string) ( wp_get_attachment_url( $attachment_id ) ?: '' ),
				'bytes' => is_string( $file ) && is_readable( $file ) ? (int) filesize( $file ) : 0,
			);
		}

		foreach ( $this->tracks_in_posts( $limit ) as $track ) {
			if ( count( $pending ) >= $limit ) {
				break;
			}

			if ( PeaksRepository::is_current( $this->repository->get( 'url_' . md5( $track['url'] ) ) ) ) {
				continue;
			}

			$pending[] = $track;
		}

		return new WP_REST_Response(
			array(
				'pending'   => $pending,
				'total'     => count( $pending ),
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

		/*
		 * One run per file per minute, forced or not. `force` bypasses the
		 * stored result, which is what it is for, but it also bypassed every
		 * other guard — and each run is ffmpeg over a whole file for up to two
		 * minutes, on demand, for anyone who may edit that attachment.
		 */
		$busy = 'imagina_peaks_running_' . $attachment_id;

		if ( get_transient( $busy ) ) {
			return new WP_Error(
				'imagina_player_generating',
				__( 'This file is already being measured. Try again in a minute.', 'imagina-player' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $busy, 1, MINUTE_IN_SECONDS );

		$peaks = PeaksGenerator::generate_for_attachment(
			$attachment_id,
			$this->repository,
			(bool) $request->get_param( 'force' )
		);

		delete_transient( $busy );

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
