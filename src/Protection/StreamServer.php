<?php
/**
 * Serves protected media over a signed URL.
 *
 * Handles HTTP range requests itself so scrubbing works, and can hand the
 * transfer to the web server (`X-Accel-Redirect` on nginx, `X-Sendfile` on
 * Apache/LiteSpeed) — which matters, because streaming a 50-minute file through
 * PHP occupies a worker for the whole playback.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Protection;

use ImaginaPlayer\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StreamServer {

	private const CHUNK = 65536;

	public function hooks(): void {
		// `parse_request` runs after authentication cookies are resolved but before
		// the main query, so nothing expensive has happened yet.
		add_action( 'parse_request', array( $this, 'maybe_serve' ), 1 );
	}

	public function maybe_serve(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public media URL, authorised by a signed token.
		if ( ! isset( $_GET[ ProtectedMedia::QUERY_VAR ] ) ) {
			return;
		}

		$attachment_id = (int) $_GET[ ProtectedMedia::QUERY_VAR ];
		$token         = isset( $_GET[ ProtectedMedia::TOKEN_VAR ] )
			? sanitize_text_field( wp_unslash( $_GET[ ProtectedMedia::TOKEN_VAR ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			$this->deny( 404, 'not_found' );
		}

		if ( ! Vault::is_protected( $attachment_id ) ) {
			// Not protected: nothing to gate, send the visitor to the real file.
			$url = wp_get_attachment_url( $attachment_id );

			if ( $url ) {
				wp_safe_redirect( $url, 302 );
				exit;
			}

			$this->deny( 404, 'not_found' );
		}

		$authorized = ProtectedMedia::authorize( $attachment_id, $token );

		if ( true !== $authorized ) {
			$this->deny( 'login_required' === $authorized ? 401 : 403, (string) $authorized );
		}

		$path = get_attached_file( $attachment_id );

		if ( ! is_string( $path ) || ! is_readable( $path ) ) {
			$this->deny( 404, 'file_missing' );
		}

		$this->serve( $path, (string) get_post_mime_type( $attachment_id ) );
	}

	/**
	 * Parse a `Range` header against a known file size.
	 *
	 * @return array{0:int,1:int}|null|false Byte range, null to serve the whole
	 *                                       file, or false when unsatisfiable.
	 */
	public static function parse_range( string $header, int $size ) {
		$header = trim( $header );

		if ( '' === $header || $size <= 0 ) {
			return null;
		}

		if ( ! preg_match( '/^bytes=(.+)$/i', $header, $matches ) ) {
			return null;
		}

		$spec = trim( $matches[1] );

		// Multipart ranges are legal but rare from media elements; serving the
		// whole file is a valid response and far simpler than multipart/byteranges.
		if ( str_contains( $spec, ',' ) ) {
			return null;
		}

		if ( ! preg_match( '/^(\d*)-(\d*)$/', $spec, $parts ) ) {
			return null;
		}

		[ , $from, $to ] = $parts;

		if ( '' === $from && '' === $to ) {
			return null;
		}

		if ( '' === $from ) {
			// `bytes=-500`: the final 500 bytes.
			$length = (int) $to;

			if ( $length <= 0 ) {
				return false;
			}

			$start = max( 0, $size - $length );
			$end   = $size - 1;
		} else {
			$start = (int) $from;
			$end   = '' === $to ? $size - 1 : (int) $to;
		}

		if ( $start > $end || $start >= $size ) {
			return false;
		}

		return array( $start, min( $end, $size - 1 ) );
	}

	/**
	 * Send the file, or hand it to the web server.
	 */
	private function serve( string $path, string $mime ): void {
		$settings = Settings::protection();
		$size     = (int) filesize( $path );
		$mime     = '' !== $mime ? $mime : 'application/octet-stream';

		nocache_headers();
		header_remove( 'Cache-Control' );
		header_remove( 'Expires' );
		header_remove( 'Pragma' );

		// `private` keeps shared caches and CDNs out of it; the browser may still
		// reuse what it already downloaded.
		header( 'Cache-Control: private, max-age=1800' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: inline; filename="' . rawurlencode( basename( $path ) ) . '"' );
		header( 'Accept-Ranges: bytes' );

		$delivery = (string) ( $settings['delivery'] ?? 'php' );

		if ( 'xaccel' === $delivery ) {
			$prefix   = trailingslashit( (string) ( $settings['xaccel_prefix'] ?: '/imagina-protected/' ) );
			$relative = ltrim( str_replace( Vault::base_dir(), '', $path ), '/' );

			// nginx takes it from here, range handling included.
			header( 'X-Accel-Redirect: ' . $prefix . $relative );
			header( 'X-Accel-Buffering: no' );
			exit;
		}

		if ( 'xsendfile' === $delivery ) {
			header( 'X-Sendfile: ' . $path );
			exit;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed by parse_range().
		$range_header = isset( $_SERVER['HTTP_RANGE'] ) ? (string) $_SERVER['HTTP_RANGE'] : '';
		$range        = self::parse_range( $range_header, $size );

		if ( false === $range ) {
			status_header( 416 );
			header( 'Content-Range: bytes */' . $size );
			exit;
		}

		if ( null === $range ) {
			$start = 0;
			$end   = $size - 1;
			status_header( 200 );
		} else {
			[ $start, $end ] = $range;
			status_header( 206 );
			header( sprintf( 'Content-Range: bytes %d-%d/%d', $start, $end, $size ) );
		}

		$length = $end - $start + 1;
		header( 'Content-Length: ' . $length );

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			exit;
		}

		$this->pump( $path, $start, $length );
		exit;
	}

	/**
	 * Copy bytes to the client in chunks, stopping as soon as they hang up.
	 */
	private function pump( string $path, int $start, int $length ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors -- may be disabled.
			@set_time_limit( 0 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $path, 'rb' );

		if ( ! is_resource( $handle ) ) {
			return;
		}

		fseek( $handle, $start );

		$remaining = $length;

		while ( $remaining > 0 && ! feof( $handle ) ) {
			if ( connection_aborted() ) {
				break;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$buffer = fread( $handle, (int) min( self::CHUNK, $remaining ) );

			if ( false === $buffer || '' === $buffer ) {
				break;
			}

			echo $buffer; // phpcs:ignore WordPress.Security.EscapeOutput -- binary media payload.

			$remaining -= strlen( $buffer );

			flush();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );
	}

	/**
	 * Refuse the request without leaking whether the file exists.
	 */
	private function deny( int $status, string $reason ): void {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Imagina-Reason: ' . $reason );

		echo esc_html__( 'This media is not available.', 'imagina-player' );

		exit;
	}
}
