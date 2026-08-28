<?php
/**
 * A resolved media item: everything the renderer needs about one track.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Media;

use ImaginaPlayer\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Track {

	public function __construct(
		public readonly string $src = '',
		public readonly int $attachment_id = 0,
		public readonly string $mime = '',
		public readonly string $title = '',
		public readonly string $artist = '',
		public readonly string $thumbnail = '',
		public readonly float $duration = 0.0,
		public readonly string $download_url = '',
		public readonly string $poster = '',
		public readonly string $aspect_ratio = '16:9'
	) {}

	public function is_playable(): bool {
		return '' !== $this->src;
	}

	/**
	 * Stable identifier used to key cached waveform peaks.
	 *
	 * Attachments key on their ID so re-uploads of the same URL cannot serve a
	 * stale waveform; external URLs key on a hash of the URL.
	 */
	public function peaks_key(): string {
		if ( $this->attachment_id > 0 ) {
			return 'att_' . $this->attachment_id;
		}

		if ( '' === $this->src ) {
			return '';
		}

		return 'url_' . md5( $this->src );
	}

	public function is_video(): bool {
		return str_starts_with( $this->mime, 'video/' ) || $this->is_hls();
	}

	/**
	 * An HLS manifest.
	 *
	 * Checked by extension, because `.m3u8` is not an upload type WordPress
	 * knows and so `wp_check_filetype()` reports nothing for it. Without this a
	 * stream rendered as an audio player — a row of controls with no picture —
	 * which is exactly what happened before a test caught it.
	 *
	 * Treated as video: a manifest says nothing about its own contents until it
	 * is fetched, and in practice a stream on a WordPress site is a recording of
	 * something to watch.
	 */
	public function is_hls(): bool {
		if ( 'application/vnd.apple.mpegurl' === $this->mime || 'application/x-mpegurl' === $this->mime ) {
			return true;
		}

		$path = (string) wp_parse_url( $this->src, PHP_URL_PATH );

		return 'm3u8' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	/**
	 * Build a track from sanitised player attributes.
	 *
	 * @param array<string, mixed> $atts Sanitised attributes.
	 */
	public static function from_attributes( array $atts ): self {
		$attachment_id = (int) ( $atts['attachmentId'] ?? 0 );
		$src           = (string) ( $atts['src'] ?? '' );
		$title         = (string) ( $atts['title'] ?? '' );
		$artist        = (string) ( $atts['artist'] ?? '' );
		$thumbnail     = (string) ( $atts['thumbnail'] ?? '' );
		$thumbnail_id  = (int) ( $atts['thumbnailId'] ?? 0 );
		$download      = (string) ( $atts['downloadUrl'] ?? '' );
		$poster        = (string) ( $atts['poster'] ?? '' );
		$poster_id     = (int) ( $atts['posterId'] ?? 0 );
		$ratio         = (string) ( $atts['aspectRatio'] ?? '16:9' );
		$mime          = '';
		$duration      = 0.0;

		if ( $attachment_id > 0 ) {
			$attachment_url = wp_get_attachment_url( $attachment_id );

			if ( $attachment_url ) {
				// The attachment's current URL wins over whatever was stored in the
				// block: a file moved into the protected vault answers on a signed
				// URL now, and the saved one would bypass it — or 404.
				$src  = $attachment_url;
				$mime = (string) get_post_mime_type( $attachment_id );

				$meta     = wp_get_attachment_metadata( $attachment_id );
				$settings = Settings::metadata();

				if ( is_array( $meta ) ) {
					$duration = isset( $meta['length'] ) ? (float) $meta['length'] : 0.0;

					// The file's own tags, if they are wanted.
					if ( '' === $title && in_array( $settings['title_from'], array( 'auto', 'tags' ), true ) && ! empty( $meta['title'] ) ) {
						$title = (string) $meta['title'];
					}

					if ( '' === $artist && in_array( $settings['artist_from'], array( 'auto', 'tags' ), true ) ) {
						// `artist` is the performer; `album_artist` is who the
						// record is filed under. For a lecture series the second
						// is usually the one that is filled in.
						foreach ( array( 'artist', 'album_artist', 'author' ) as $field ) {
							if ( ! empty( $meta[ $field ] ) ) {
								$artist = (string) $meta[ $field ];
								break;
							}
						}
					}
				}

				// The name it was given in the library.
				if ( '' === $title && in_array( $settings['title_from'], array( 'auto', 'post' ), true ) ) {
					$title = (string) get_the_title( $attachment_id );
				}

				// The cover art WordPress pulled out of the file on upload.
				if ( '' === $thumbnail && ! empty( $settings['use_cover'] ) ) {
					$cover = wp_get_attachment_image_url( $attachment_id, 'medium' );

					if ( $cover ) {
						$thumbnail = (string) $cover;
					}
				}
			} else {
				// The attachment was deleted; fall back to whatever URL was stored.
				$attachment_id = 0;
			}
		}

		/*
		 * Last resort, and the only one an external address has: the file name
		 * itself. A track pasted from a streaming provider has no attachment to
		 * ask and no tags we can read, so without this it is nameless.
		 */
		$settings = Settings::metadata();

		if ( '' === $title && '' !== $src && ! empty( $settings['from_filename'] )
			&& in_array( $settings['title_from'], array( 'auto', 'file' ), true ) ) {
			$title = self::title_from_filename( $src );
		}

		if ( '' === $mime && '' !== $src ) {
			$checked = wp_check_filetype( self::path_from_url( $src ) );
			$mime    = (string) ( $checked['type'] ?: '' );
		}

		if ( '' === $thumbnail && $thumbnail_id > 0 ) {
			$thumbnail = (string) ( wp_get_attachment_image_url( $thumbnail_id, 'medium' ) ?: '' );
		}

		if ( '' === $poster && $poster_id > 0 ) {
			// `large` rather than `full`: a poster is a still behind a player,
			// not a photograph to be examined, and full size on a 4K upload is
			// megabytes the visitor pays for before pressing play.
			$poster = (string) ( wp_get_attachment_image_url( $poster_id, 'large' ) ?: '' );
		}

		// A video with no poster of its own borrows the cover art. Better than
		// a black rectangle, and it is the image the author already chose.
		if ( '' === $poster && str_starts_with( $mime, 'video/' ) ) {
			$poster = $thumbnail;
		}

		return new self(
			src: $src,
			attachment_id: $attachment_id,
			mime: $mime,
			title: $title,
			artist: $artist,
			thumbnail: $thumbnail,
			duration: $duration,
			download_url: $download,
			poster: $poster,
			aspect_ratio: $ratio
		);
	}

	/**
	 * A readable title out of a file name.
	 *
	 * `2024-03-11_mi-conferencia_01.mp3` becomes "Mi conferencia 01". Dashes and
	 * underscores become spaces, the extension goes, and a leading date — which
	 * is how most people keep recordings in order — is dropped, because it is
	 * filing, not a title.
	 *
	 * Deliberately not title-cased word by word: doing that to Spanish turns
	 * "La historia de un quiste" into "La Historia De Un Quiste", which is
	 * wrong in a way that looks deliberate.
	 */
	public static function title_from_filename( string $src ): string {
		$path = (string) wp_parse_url( $src, PHP_URL_PATH );
		$name = pathinfo( '' !== $path ? $path : $src, PATHINFO_FILENAME );
		$name = rawurldecode( (string) $name );

		// A leading date, in the shapes people actually use.
		$name = (string) preg_replace( '/^\d{4}[-_.]?\d{2}[-_.]?\d{2}[-_. ]+/', '', $name );

		$name = str_replace( array( '-', '_', '+' ), ' ', $name );
		$name = trim( (string) preg_replace( '/\s+/', ' ', $name ) );

		if ( '' === $name ) {
			return '';
		}

		// Only the first letter, and only when the name is not already using
		// capitals of its own — an acronym should stay one.
		return $name === strtolower( $name )
			? ( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $name, 0, 1 ) ) . mb_substr( $name, 1 ) : ucfirst( $name ) )
			: $name;
	}

	/**
	 * Strip the query string so `wp_check_filetype()` sees a real extension.
	 */
	private static function path_from_url( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		return is_string( $path ) ? $path : $url;
	}
}
