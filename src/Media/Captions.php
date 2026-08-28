<?php
/**
 * Subtitle files, in the one format browsers actually read.
 *
 * `<track>` accepts WebVTT and nothing else. SRT is what most people have —
 * it is what every transcription service and every subtitle editor produces —
 * so a plugin that only takes VTT is really telling its users to go and find a
 * converter. The two formats differ by a header line and a decimal separator.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Captions {

	/**
	 * Files larger than this are refused rather than read into memory.
	 *
	 * A feature-length film's subtitles are around 100 KB. A megabyte is not a
	 * subtitle file, it is something that will be, at best, a waste of a request.
	 */
	private const MAX_BYTES = 1048576;

	public static function is_srt( string $url ): bool {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return 'srt' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	/**
	 * Convert SubRip to WebVTT.
	 *
	 * The differences that matter: VTT needs its magic first line, uses a dot for
	 * the fractional second where SRT uses a comma, and has no use for SRT's cue
	 * numbers. Everything else — the text, the blank-line separators, the
	 * ordering — carries across untouched.
	 */
	public static function srt_to_vtt( string $srt ): string {
		// A BOM before the WEBVTT line makes the whole file invalid.
		$srt = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $srt );
		$srt = str_replace( array( "\r\n", "\r" ), "\n", $srt );

		$out = array( 'WEBVTT', '' );

		foreach ( explode( "\n\n", trim( $srt ) ) as $block ) {
			$lines = explode( "\n", trim( $block ) );

			if ( array() === $lines || '' === trim( $lines[0] ) ) {
				continue;
			}

			// Drop the cue number, which VTT does not use. Only when it is a bare
			// number: a cue whose first line of text happens to be "42" is text.
			if ( preg_match( '/^\d+$/', trim( $lines[0] ) ) && isset( $lines[1] ) && str_contains( $lines[1], '-->' ) ) {
				array_shift( $lines );
			}

			if ( ! isset( $lines[0] ) || ! str_contains( $lines[0], '-->' ) ) {
				continue;
			}

			$lines[0] = self::timing( $lines[0] );

			$out[] = implode( "\n", $lines );
			$out[] = '';
		}

		return implode( "\n", $out );
	}

	/**
	 * `00:00:01,500 --> 00:00:04,000` becomes the same with dots.
	 *
	 * Rewritten from the parsed numbers rather than search-and-replaced, so a
	 * malformed line produces nothing instead of a cue the browser will reject
	 * halfway through the file — which takes every cue after it down too.
	 */
	private static function timing( string $line ): string {
		if ( ! preg_match_all( '/(\d{1,2}):(\d{2}):(\d{2})[,.](\d{1,3})/', $line, $stamps, PREG_SET_ORDER ) ) {
			return $line;
		}

		if ( count( $stamps ) < 2 ) {
			return $line;
		}

		$format = static fn( array $s ): string => sprintf(
			'%02d:%02d:%02d.%03d',
			(int) $s[1],
			(int) $s[2],
			(int) $s[3],
			(int) str_pad( $s[4], 3, '0' )
		);

		// Cue settings (`align:start position:10%`) live after the second stamp
		// and are valid in both formats, so they are carried across as they are.
		$tail = trim( (string) substr( $line, (int) strpos( $line, $stamps[1][0] ) + strlen( $stamps[1][0] ) ) );

		return $format( $stamps[0] ) . ' --> ' . $format( $stamps[1] ) . ( '' === $tail ? '' : ' ' . $tail );
	}

	/**
	 * Read a subtitle file that belongs to this site and return it as WebVTT.
	 *
	 * Local files only, and resolved through the uploads directory: this is
	 * reached from a public endpoint, so a URL is never turned into a path that
	 * could point anywhere but where the site's own media lives.
	 *
	 * @return string|null Null when the file is not ours, not there, or too big.
	 */
	public static function read( string $url ): ?string {
		$path = self::local_path( $url );

		if ( null === $path ) {
			return null;
		}

		$size = filesize( $path );

		if ( false === $size || $size > self::MAX_BYTES ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, size checked.
		$contents = file_get_contents( $path );

		if ( ! is_string( $contents ) ) {
			return null;
		}

		return self::is_srt( $url ) ? self::srt_to_vtt( $contents ) : $contents;
	}

	/**
	 * The file on disk behind an uploads URL, or null.
	 *
	 * Everything here is about refusing to read a file the caller only *said*
	 * was a subtitle: the URL must sit under this site's uploads directory, the
	 * resolved real path must still be inside it once `..` has been collapsed,
	 * and the extension must be one of two.
	 */
	private static function local_path( string $url ): ?string {
		$uploads = wp_get_upload_dir();
		$baseurl = trailingslashit( (string) $uploads['baseurl'] );
		$basedir = trailingslashit( (string) $uploads['basedir'] );

		// Compare without a scheme: a site reached over both http and https would
		// otherwise fail here for half its visitors.
		$strip = static fn( string $value ): string => (string) preg_replace( '#^https?://#', '', $value );

		$plain = $strip( (string) strtok( $url, '?' ) );
		$base  = $strip( $baseurl );

		if ( ! str_starts_with( $plain, $base ) ) {
			return null;
		}

		$extension = strtolower( pathinfo( $plain, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, array( 'vtt', 'srt' ), true ) ) {
			return null;
		}

		$candidate = $basedir . substr( $plain, strlen( $base ) );
		$real      = realpath( $candidate );
		$root      = realpath( $basedir );

		if ( false === $real || false === $root || ! str_starts_with( $real, $root ) ) {
			return null;
		}

		return $real;
	}

	/**
	 * Chapters as a WebVTT track.
	 *
	 * Small enough to inline as a data URI, which saves a request and — more
	 * usefully — means chapters work on a site whose REST API is behind a
	 * security plugin, which is common enough to design around.
	 *
	 * @param array<int, array{start: float, title: string}> $chapters Sorted chapters.
	 * @param float                                          $duration Track length, or 0 if unknown.
	 */
	public static function chapters_vtt( array $chapters, float $duration = 0.0 ): string {
		if ( array() === $chapters ) {
			return '';
		}

		$lines = array( 'WEBVTT', '' );
		$count = count( $chapters );

		foreach ( $chapters as $index => $chapter ) {
			$start = (float) $chapter['start'];

			// A cue runs to the next chapter. The last one runs to the end of the
			// track, or — when nobody has told us how long that is — to a point
			// far enough out that it covers whatever the file turns out to be.
			if ( $index + 1 < $count ) {
				$end = (float) $chapters[ $index + 1 ]['start'];
			} else {
				$end = $duration > $start ? $duration : $start + DAY_IN_SECONDS;
			}

			$lines[] = self::stamp( $start ) . ' --> ' . self::stamp( $end );
			$lines[] = $chapter['title'];
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	private static function stamp( float $seconds ): string {
		$seconds = max( 0.0, $seconds );
		$whole   = (int) floor( $seconds );

		return sprintf(
			'%02d:%02d:%02d.%03d',
			intdiv( $whole, 3600 ),
			intdiv( $whole % 3600, 60 ),
			$whole % 60,
			(int) round( ( $seconds - $whole ) * 1000 )
		);
	}

	/**
	 * A `data:` URI a `<track>` element can load without a request.
	 */
	public static function data_uri( string $vtt ): string {
		return 'data:text/vtt;charset=utf-8;base64,' . base64_encode( $vtt ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- building a data URI.
	}
}
