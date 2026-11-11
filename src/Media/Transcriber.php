<?php
/**
 * Subtitles written by a speech-to-text service.
 *
 * Any service that answers OpenAI's transcription call — OpenAI's own Whisper,
 * Groq, or a Whisper server of the site's own — set under Integrations with an
 * address, a key and a model. It is asked for WebVTT, which is what the file
 * becomes: a subtitle file in the media library, attached to the video it was
 * made from, that the block then lists like any other.
 *
 * In the background. A long recording takes minutes to transcribe, longer than
 * a request is allowed to run, so the editor starts a job, the next cron run
 * does it, and the editor asks how it is going.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Media;

use ImaginaPlayer\Leads\Integrations;
use ImaginaPlayer\Peaks\PeaksGenerator;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Transcriber {

	public const HOOK = 'imagina_player_transcribe';

	/** How long a job's answer is kept for the editor to collect. */
	private const KEEP = DAY_IN_SECONDS;

	/** What OpenAI accepts in one request; other services may take more. */
	private const MAX_BYTES = 25 * 1024 * 1024;

	public function hooks(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	/** Whether the site has a service set up to ask. */
	public static function available(): bool {
		$config = Integrations::get()['transcription'];

		return ! empty( $config['enabled'] ) && '' !== (string) $config['endpoint'] && '' !== (string) $config['api_key'];
	}

	/**
	 * Line up a file to be transcribed; the job's name comes back.
	 */
	public static function start( int $attachment_id, string $language, int $user_id ): string {
		$job = bin2hex( random_bytes( 8 ) );

		set_transient(
			self::key( $job ),
			array(
				'status'     => 'queued',
				'attachment' => $attachment_id,
				'language'   => self::language( $language ),
				'user'       => $user_id,
			),
			self::KEEP
		);

		wp_schedule_single_event( time(), self::HOOK, array( $job ) );

		// Started now rather than on the next visitor's page load.
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return $job;
	}

	/** @return array<string, mixed>|null */
	public static function status( string $job ): ?array {
		$state = get_transient( self::key( $job ) );

		if ( ! is_array( $state ) ) {
			return null;
		}

		// Working for longer than any job is let run: it is not coming back.
		if ( 'working' === $state['status'] && time() - (int) ( $state['started'] ?? time() ) > 20 * MINUTE_IN_SECONDS ) {
			return array_merge( $state, self::failed( __( 'The transcription stopped before it finished.', 'imagina-player' ) ) );
		}

		return $state;
	}

	private static function key( string $job ): string {
		return 'imgp_tx_' . preg_replace( '/[^a-f0-9]/', '', $job );
	}

	/** `es`, `pt-br`: what a language code may hold. */
	public static function language( string $code ): string {
		return substr( strtolower( (string) preg_replace( '/[^A-Za-z-]/', '', $code ) ), 0, 12 );
	}

	/**
	 * Do one job: the sound out of the file, to the service, and the answer
	 * into the library.
	 */
	public static function run( string $job ): void {
		$state = self::status( $job );

		if ( null === $state || 'queued' !== $state['status'] ) {
			return;
		}

		$state['status']  = 'working';
		$state['started'] = time();
		set_transient( self::key( $job ), $state, self::KEEP );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 900 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a host may forbid it; the job runs as long as it is let.
		}

		/*
		 * A job that dies — the host's time limit, its memory — would stay
		 * "working" for a day, and the editor would wait for it. Said, if
		 * the request ends with the job still going.
		 */
		register_shutdown_function(
			static function () use ( $job ): void {
				$now = self::status( $job );

				if ( is_array( $now ) && 'working' === $now['status'] ) {
					$error = error_get_last();

					set_transient(
						self::key( $job ),
						array_merge( $now, self::failed( is_array( $error ) ? (string) $error['message'] : __( 'The transcription stopped before it finished.', 'imagina-player' ) ) ),
						self::KEEP
					);
				}
			}
		);

		$result = self::transcribe( (int) $state['attachment'], (string) $state['language'] );

		set_transient( self::key( $job ), array_merge( $state, $result ), self::KEEP );
	}

	/**
	 * @return array{status: string, message?: string, url?: string, id?: int, srclang?: string}
	 */
	public static function transcribe( int $attachment_id, string $language ): array {
		$config = Integrations::get()['transcription'];
		$file   = (string) get_attached_file( $attachment_id );

		if ( '' === $file || ! is_readable( $file ) ) {
			return self::failed( __( 'The file is not on this server, so it cannot be sent to be transcribed.', 'imagina-player' ) );
		}

		$audio = self::audio( $file );

		if ( $audio instanceof WP_Error ) {
			return self::failed( $audio->get_error_message() );
		}

		$response = self::request( $config, $audio['path'], $audio['name'], $language );

		if ( $audio['temporary'] ) {
			wp_delete_file( $audio['path'] );
		}

		if ( $response instanceof WP_Error ) {
			return self::failed( $response->get_error_message() );
		}

		$saved = self::save( $attachment_id, $language, $response );

		if ( $saved instanceof WP_Error ) {
			return self::failed( $saved->get_error_message() );
		}

		return array(
			'status'  => 'done',
			'url'     => $saved['url'],
			'id'      => $saved['id'],
			'srclang' => $language,
		);
	}

	/** @return array{status: string, message: string} */
	private static function failed( string $message ): array {
		return array(
			'status'  => 'failed',
			'message' => $message,
		);
	}

	/**
	 * What is sent: the sound alone, small. With ffmpeg, a mono 32 kbit/s MP3
	 * — an hour in about fourteen megabytes, and none of the picture. Without
	 * it, the file itself, if it is small enough.
	 *
	 * @return array{path: string, name: string, temporary: bool}|WP_Error
	 */
	public static function audio( string $file ) {
		$binary = PeaksGenerator::binary();

		// `wp_tempnam()` is the admin's, and a cron run does not load it.
		if ( ! function_exists( 'wp_tempnam' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( '' !== $binary && function_exists( 'popen' ) && function_exists( 'wp_tempnam' ) ) {
			$base    = wp_tempnam( 'imgp-transcribe' );
			$out     = $base . '.mp3';

			wp_delete_file( $base );

			$command = sprintf(
				'%s -nostdin -loglevel error -y -i %s -vn -ac 1 -ar 16000 -b:a 32k -f mp3 %s 2>&1',
				escapeshellarg( $binary ),
				escapeshellarg( $file ),
				escapeshellarg( $out )
			);

			// phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions -- ffmpeg was found and probed.
			$handle = @popen( $command, 'r' );

			if ( $handle ) {
				while ( ! feof( $handle ) ) {
					fread( $handle, 8192 );
				}

				$code = pclose( $handle );

				if ( 0 === $code && is_file( $out ) && filesize( $out ) > 0 ) {
					return array(
						'path'      => $out,
						'name'      => pathinfo( $file, PATHINFO_FILENAME ) . '.mp3',
						'temporary' => true,
					);
				}
			}

			wp_delete_file( $out );
		}

		/** Filter the largest file sent as it is, in bytes. */
		$max = (int) apply_filters( 'imagina_player_transcribe_max_bytes', self::MAX_BYTES );

		if ( (int) filesize( $file ) > $max ) {
			return new WP_Error(
				'imagina_player_too_large',
				__( 'The file is larger than the service takes in one piece, and ffmpeg is not available here to take the sound out of it.', 'imagina-player' )
			);
		}

		return array(
			'path'      => $file,
			'name'      => basename( $file ),
			'temporary' => false,
		);
	}

	/**
	 * The service's answer: a WebVTT file.
	 *
	 * @param array<string, mixed> $config
	 * @return string|WP_Error
	 */
	public static function request( array $config, string $path, string $name, string $language ) {
		$boundary = 'imagina-' . bin2hex( random_bytes( 8 ) );
		$fields   = array(
			'model'           => (string) ( '' !== (string) $config['model'] ? $config['model'] : 'whisper-1' ),
			'response_format' => 'vtt',
		);

		if ( '' !== $language ) {
			$fields['language'] = $language;
		}

		$body = '';

		foreach ( $fields as $field => $value ) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$field}\"\r\n\r\n{$value}\r\n";
		}

		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"" . str_replace( '"', '', $name ) . "\"\r\n"
			. 'Content-Type: ' . ( wp_check_filetype( $name )['type'] ?: 'application/octet-stream' ) . "\r\n\r\n"
			. (string) file_get_contents( $path ) . "\r\n--{$boundary}--\r\n";

		/*
		 * Not the client that refuses private addresses: a Whisper server on
		 * the site's own network is a reasonable thing to set up, and only an
		 * administrator can name the address.
		 */
		$response = wp_remote_post(
			(string) $config['endpoint'],
			array(
				'timeout' => 600,
				'headers' => array(
					'Authorization' => 'Bearer ' . $config['api_key'],
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);

		if ( $response instanceof WP_Error ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$text   = (string) wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status >= 300 ) {
			$data = json_decode( $text, true );

			return new WP_Error(
				'imagina_player_transcribe_refused',
				(string) ( $data['error']['message'] ?? $data['message'] ?? sprintf(
					/* translators: %d: an HTTP status code. */
					__( 'The service answered with status %d.', 'imagina-player' ),
					$status
				) )
			);
		}

		$text = ltrim( $text, "\xEF\xBB\xBF \n\r\t" );

		if ( ! str_starts_with( $text, 'WEBVTT' ) ) {
			return new WP_Error( 'imagina_player_transcribe_format', __( 'The service did not answer with subtitles.', 'imagina-player' ) );
		}

		return $text;
	}

	/**
	 * The subtitles as a file in the library, beside the video.
	 *
	 * @return array{id: int, url: string}|WP_Error
	 */
	public static function save( int $attachment_id, string $language, string $vtt ) {
		$name   = sanitize_file_name( pathinfo( (string) get_attached_file( $attachment_id ), PATHINFO_FILENAME ) . ( '' !== $language ? '-' . $language : '' ) . '.vtt' );
		$upload = wp_upload_bits( $name, null, $vtt );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'imagina_player_upload', (string) $upload['error'] );
		}

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'text/vtt',
				/* translators: %s: the title of the video the subtitles are for. */
				'post_title'     => sprintf( __( 'Subtitles: %s', 'imagina-player' ), get_the_title( $attachment_id ) ),
				'post_status'    => 'inherit',
				'post_parent'    => $attachment_id,
			),
			(string) $upload['file'],
			$attachment_id,
			true
		);

		if ( $id instanceof WP_Error ) {
			return $id;
		}

		return array(
			'id'  => (int) $id,
			'url' => (string) $upload['url'],
		);
	}

	/**
	 * Whether the address and the key work: the service's list of models,
	 * which any of them answers for a valid key.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public static function test(): array {
		$config = Integrations::get()['transcription'];

		if ( '' === (string) $config['endpoint'] || '' === (string) $config['api_key'] ) {
			return array(
				'ok'      => false,
				'message' => __( 'Enter the service’s address and an API key first.', 'imagina-player' ),
			);
		}

		$models   = (string) preg_replace( '#/audio/transcriptions/?$#', '', (string) $config['endpoint'] ) . '/models';
		$response = wp_remote_get(
			$models,
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $config['api_key'] ),
			)
		);

		if ( $response instanceof WP_Error ) {
			return array(
				'ok'      => false,
				'message' => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		return $status >= 200 && $status < 300
			? array(
				'ok'      => true,
				'message' => __( 'Connected.', 'imagina-player' ),
			)
			: array(
				'ok'      => false,
				/* translators: %d: an HTTP status code. */
				'message' => sprintf( __( 'The service answered with status %d.', 'imagina-player' ), $status ),
			);
	}
}
