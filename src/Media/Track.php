<?php
/**
 * A resolved media item: everything the renderer needs about one track.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Media;

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
		public readonly string $download_url = ''
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
		return str_starts_with( $this->mime, 'video/' );
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
		$mime          = '';
		$duration      = 0.0;

		if ( $attachment_id > 0 ) {
			$attachment_url = wp_get_attachment_url( $attachment_id );

			if ( $attachment_url ) {
				$src  = '' !== $src ? $src : $attachment_url;
				$mime = (string) get_post_mime_type( $attachment_id );

				$meta = wp_get_attachment_metadata( $attachment_id );

				if ( is_array( $meta ) ) {
					$duration = isset( $meta['length'] ) ? (float) $meta['length'] : 0.0;

					if ( '' === $title && ! empty( $meta['title'] ) ) {
						$title = (string) $meta['title'];
					}

					if ( '' === $artist && ! empty( $meta['artist'] ) ) {
						$artist = (string) $meta['artist'];
					}
				}

				if ( '' === $title ) {
					$title = (string) get_the_title( $attachment_id );
				}
			} else {
				// The attachment was deleted; fall back to whatever URL was stored.
				$attachment_id = 0;
			}
		}

		if ( '' === $mime && '' !== $src ) {
			$checked = wp_check_filetype( self::path_from_url( $src ) );
			$mime    = (string) ( $checked['type'] ?: '' );
		}

		if ( '' === $thumbnail && $thumbnail_id > 0 ) {
			$thumbnail = (string) ( wp_get_attachment_image_url( $thumbnail_id, 'medium' ) ?: '' );
		}

		return new self(
			src: $src,
			attachment_id: $attachment_id,
			mime: $mime,
			title: $title,
			artist: $artist,
			thumbnail: $thumbnail,
			duration: $duration,
			download_url: $download
		);
	}

	/**
	 * Strip the query string so `wp_check_filetype()` sees a real extension.
	 */
	private static function path_from_url( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		return is_string( $path ) ? $path : $url;
	}
}
