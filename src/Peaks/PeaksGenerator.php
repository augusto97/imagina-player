<?php
/**
 * Server-side waveform extraction.
 *
 * When the host exposes ffmpeg (most managed WordPress hosts that support media
 * do), peaks are produced once on the server and every visitor gets the waveform
 * for free. Where it is unavailable the front-end falls back to a one-time Web
 * Audio decode and posts the result back — see PeaksToken.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Peaks;

use ImaginaPlayer\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PeaksGenerator {

	/**
	 * Sample rate we downmix to before measuring. High enough to keep transients,
	 * low enough that an hour of audio is a few megabytes of streamed PCM.
	 */
	private const SAMPLE_RATE = 8000;

	/**
	 * Samples per measured window: 800 @ 8 kHz = 10 measurements per second, which
	 * over-samples any realistic bar count and is then resampled down.
	 */
	private const WINDOW = 800;

	/**
	 * Whether server-side generation can run at all on this host.
	 */
	public static function is_available(): bool {
		$peaks_settings = Settings::peaks_settings();

		if ( empty( $peaks_settings['server_generation'] ) ) {
			return false;
		}

		return '' !== self::binary();
	}

	/**
	 * Why server-side generation is or is not working, in the host's own terms.
	 *
	 * "Not found" covered three different situations with three different fixes:
	 * a host that forbids running any process at all, a path typed into the
	 * settings that is wrong, and simply nothing on PATH. Only the last one is
	 * answered by "ask your host to install ffmpeg".
	 *
	 * @return array{state: string, binary: string, configured: string}
	 */
	public static function diagnosis(): array {
		$configured = trim( (string) ( Settings::peaks_settings()['ffmpeg_path'] ?? '' ) );
		$binary     = self::binary();

		if ( '' !== $binary ) {
			return array(
				'state'      => 'ok',
				'binary'     => $binary,
				'configured' => $configured,
			);
		}

		if ( ! self::can_run_processes() ) {
			return array(
				'state'      => 'processes-disabled',
				'binary'     => '',
				'configured' => $configured,
			);
		}

		if ( '' !== $configured ) {
			return array(
				'state'      => str_contains( $configured, '/' ) && ! is_file( $configured ) ? 'path-missing' : 'path-not-ffmpeg',
				'binary'     => '',
				'configured' => $configured,
			);
		}

		return array(
			'state'      => 'not-installed',
			'binary'     => '',
			'configured' => '',
		);
	}

	/**
	 * Locate the ffmpeg binary, honouring an explicit path from settings.
	 */
	public static function binary(): string {
		$peaks_settings = Settings::peaks_settings();
		$configured     = trim( (string) ( $peaks_settings['ffmpeg_path'] ?? '' ) );

		// Keyed on the configured path rather than a single slot: saving a new
		// path and re-reading the status happen in the same request, and a flat
		// cache answered that with the value from before the save.
		static $cache = array();

		if ( isset( $cache[ $configured ] ) ) {
			return $cache[ $configured ];
		}

		$resolved = '';

		if ( ! self::can_run_processes() ) {
			$cache[ $configured ] = $resolved;

			return $resolved;
		}

		$candidates = array_filter( array( $configured, 'ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg' ) );

		foreach ( $candidates as $candidate ) {
			if ( self::probe( $candidate ) ) {
				$resolved = $candidate;
				break;
			}
		}

		/**
		 * Filter the resolved ffmpeg binary path (empty string disables generation).
		 *
		 * @param string $resolved Binary path or command name.
		 */
		$resolved = (string) apply_filters( 'imagina_player_ffmpeg_binary', $resolved );

		$cache[ $configured ] = $resolved;

		return $resolved;
	}

	/**
	 * Generate peaks for a local file.
	 *
	 * @param string $file       Absolute path to a readable media file.
	 * @param int    $resolution Number of bars to return.
	 * @return array<int, float>|null Normalised amplitudes, or null on failure.
	 */
	public static function generate( string $file, int $resolution ): ?array {
		if ( ! self::is_available() ) {
			return null;
		}

		if ( '' === $file || ! is_readable( $file ) ) {
			return null;
		}

		$binary = self::binary();

		if ( '' === $binary ) {
			return null;
		}

		$command = sprintf(
			'%s -v quiet -nostdin -i %s -ac 1 -ar %d -f s16le -acodec pcm_s16le - 2>/dev/null',
			escapeshellcmd( $binary ),
			escapeshellarg( $file ),
			self::SAMPLE_RATE
		);

		// phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions -- guarded by can_run_processes().
		$handle = @popen( $command, 'r' );

		if ( ! is_resource( $handle ) ) {
			return null;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors -- may be disabled in safe mode.
			@set_time_limit( 120 );
		}

		$windows      = array();
		$bytes_needed = self::WINDOW * 2;
		$buffer       = '';

		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, 65536 );

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$buffer .= $chunk;

			while ( strlen( $buffer ) >= $bytes_needed ) {
				$slice  = substr( $buffer, 0, $bytes_needed );
				$buffer = substr( $buffer, $bytes_needed );

				$windows[] = self::window_peak( $slice );
			}
		}

		if ( strlen( $buffer ) >= 2 ) {
			$windows[] = self::window_peak( substr( $buffer, 0, strlen( $buffer ) - ( strlen( $buffer ) % 2 ) ) );
		}

		pclose( $handle );

		if ( array() === $windows ) {
			return null;
		}

		$peaks = PeaksRepository::resample( $windows, $resolution );

		return PeaksRepository::normalize( $peaks );
	}

	/**
	 * Generate and store peaks for an attachment, unless they already exist.
	 *
	 * @return array<int, float>|null
	 */
	public static function generate_for_attachment( int $attachment_id, PeaksRepository $repository, bool $force = false ): ?array {
		$key = 'att_' . $attachment_id;

		if ( ! $force && null !== $repository->get( $key ) ) {
			return null;
		}

		$file = get_attached_file( $attachment_id );

		if ( ! is_string( $file ) || '' === $file ) {
			return null;
		}

		$peaks = self::generate( $file, PeaksRepository::resolution() );

		if ( null === $peaks || array() === $peaks ) {
			return null;
		}

		$meta     = wp_get_attachment_metadata( $attachment_id );
		$duration = is_array( $meta ) && isset( $meta['length'] ) ? (float) $meta['length'] : 0.0;

		$repository->save( $key, $peaks, $duration );

		return $peaks;
	}

	/**
	 * Loudness of one window of little-endian signed 16-bit samples.
	 *
	 * Root mean square, not the loudest sample in the window — the same measure
	 * the editor and the visitor's browser use, so a track measured here and
	 * the same track measured there draw the same picture. The loudest sample
	 * saturates on speech: every window of a lecture holds a syllable at full
	 * volume, so every bar comes out the same height and an hour of teaching is
	 * drawn as a comb.
	 */
	private static function window_peak( string $bytes ): float {
		$samples = unpack( 'v*', $bytes );

		if ( ! is_array( $samples ) ) {
			return 0.0;
		}

		$energy  = 0.0;
		$counted = 0;

		foreach ( $samples as $sample ) {
			// `v` is unsigned; fold the top half back into negative territory.
			if ( $sample > 32767 ) {
				$sample -= 65536;
			}

			$level    = $sample / 32768;
			$energy  += $level * $level;
			$counted++;
		}

		return sqrt( $energy / max( 1, $counted ) );
	}

	private static function can_run_processes(): bool {
		if ( ! function_exists( 'popen' ) || ! function_exists( 'pclose' ) ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		return ! in_array( 'popen', $disabled, true ) && ! in_array( 'pclose', $disabled, true );
	}

	private static function probe( string $binary ): bool {
		// A configured path must actually exist. A bare command name is left to
		// PATH resolution, which popen() handles.
		if ( str_contains( $binary, '/' ) && ! is_file( $binary ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions -- guarded by can_run_processes().
		$handle = @popen( escapeshellcmd( $binary ) . ' -version 2>/dev/null', 'r' );

		if ( ! is_resource( $handle ) ) {
			return false;
		}

		$output = (string) fread( $handle, 256 );

		pclose( $handle );

		return str_contains( strtolower( $output ), 'ffmpeg version' );
	}
}
