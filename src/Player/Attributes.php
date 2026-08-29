<?php
/**
 * The canonical per-instance attribute schema.
 *
 * Block attributes, shortcode attributes and the REST preview endpoint all read
 * from this schema so the three entry points can never drift apart.
 *
 * Visual toggles are tri-state strings — `''` means "inherit from the preset",
 * `'yes'`/`'no'` force the value. Booleans would have made "inherit" impossible
 * to express in a shortcode.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Player;

use ImaginaPlayer\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Attributes {

	public const INHERIT = '';

	/**
	 * Preset keys that a single instance may override, mapped to the attribute
	 * name used by blocks and shortcodes.
	 *
	 * @var array<string, string>
	 */
	private const OVERRIDE_MAP = array(
		'skin'              => 'skin',
		'accent'            => 'accent',
		'wave_color'        => 'waveColor',
		'wave_progress'     => 'waveProgress',
		'text_color'        => 'textColor',
		'meta_color'        => 'metaColor',
		'background'        => 'background',
		'height'            => 'height',
		'preload'           => 'preload',
		'show_artist'       => 'showArtist',
		'show_title'        => 'showTitle',
		'show_thumbnail'    => 'showThumbnail',
		'show_volume'       => 'showVolume',
		'show_time'         => 'showTime',
		'show_download'     => 'showDownload',
		'show_speed'        => 'showSpeed',
		'show_skip'         => 'showSkip',
		'skip_seconds'      => 'skipSeconds',
		'sticky'            => 'sticky',
		'remember_position' => 'rememberPosition',
	);

	/**
	 * @return array<string, string> Preset key => attribute name.
	 */
	public static function override_map(): array {
		return self::OVERRIDE_MAP;
	}

	/**
	 * Full schema: attribute name => [ type, default ].
	 *
	 * `type` is one of string|int|float|bool|tristate|array.
	 *
	 * @return array<string, array{type: string, default: mixed}>
	 */
	/** Landscape 16:9, which is what almost every recording is. */
	public const DEFAULT_RATIO = '16:9';

	public static function schema(): array {
		$schema = array(
			'src'          => array(
				'type'    => 'string',
				'default' => '',
			),
			'attachmentId' => array(
				'type'    => 'int',
				'default' => 0,
			),
			'title'        => array(
				'type'    => 'string',
				'default' => '',
			),
			'artist'       => array(
				'type'    => 'string',
				'default' => '',
			),
			'thumbnail'    => array(
				'type'    => 'string',
				'default' => '',
			),
			'thumbnailId'  => array(
				'type'    => 'int',
				'default' => 0,
			),
			'downloadUrl'  => array(
				'type'    => 'string',
				'default' => '',
			),
			'preset'       => array(
				'type'    => 'string',
				'default' => Settings::DEFAULT_PRESET,
			),
			'autoplay'     => array(
				'type'    => 'bool',
				'default' => false,
			),
			'loop'         => array(
				'type'    => 'bool',
				'default' => false,
			),
			'muted'        => array(
				'type'    => 'bool',
				'default' => false,
			),
			'startTime'    => array(
				'type'    => 'float',
				'default' => 0.0,
			),
			'className'    => array(
				'type'    => 'string',
				'default' => '',
			),

			/*
			 * The video settings a block may answer for itself. Tristate, like
			 * every other override: an unset one inherits whatever the site was
			 * set up with, which is what an author who configured it once
			 * expects and what keeps a block from freezing today's defaults
			 * into every post.
			 */
			'videoBigPlay'       => array(
				'type'    => 'tristate',
				'default' => self::INHERIT,
			),
			'videoFullscreen'    => array(
				'type'    => 'tristate',
				'default' => self::INHERIT,
			),
			'videoPip'           => array(
				'type'    => 'tristate',
				'default' => self::INHERIT,
			),
			'videoSpeed'         => array(
				'type'    => 'tristate',
				'default' => self::INHERIT,
			),
			'videoBlockDownload' => array(
				'type'    => 'tristate',
				'default' => self::INHERIT,
			),
			'videoPosterFit'     => array(
				'type'    => 'string',
				'default' => self::INHERIT,
			),
			// Milliseconds before the controls fade during playback; empty
			// inherits, and zero is a real answer meaning "never".
			'videoHideAfter'     => array(
				'type'    => 'string',
				'default' => self::INHERIT,
			),
			/*
			 * A WebVTT storyboard: cues whose payload is a sprite sheet with a
			 * `#xywh=` fragment naming the tile. Every tool that makes these
			 * produces that shape, and one image holds a hundred stills, so the
			 * whole feature costs one request — and only for a reader who
			 * actually drags the bar.
			 */
			// How the picture and its bar are painted, per block. Presto keeps
			// these on the preset and Fluent on the player; a block that can
			// answer for one video is the more useful of the two, and leaving
			// them unset still follows the site.
			'videoCaptions'      => array(
				'type'    => 'tristate',
				'default' => self::INHERIT,
			),
			'videoChapters'      => array(
				'type'    => 'tristate',
				'default' => self::INHERIT,
			),
			'videoSkip'          => array(
				'type'    => 'tristate',
				'default' => self::INHERIT,
			),
			'videoTime'          => array(
				'type'    => 'tristate',
				'default' => self::INHERIT,
			),
			'videoVolume'        => array(
				'type'    => 'tristate',
				'default' => self::INHERIT,
			),
			'videoTitle'         => array(
				'type'    => 'tristate',
				'default' => self::INHERIT,
			),
			// A mark over the picture: a logo in a corner, faint, the whole way
			// through. Presto keeps one per preset; this one is per block,
			// because the reason to put a mark on a video is usually that this
			// particular video is going somewhere it should be traceable from.
			'watermark'          => array(
				'type'    => 'string',
				'default' => '',
			),
			'watermarkId'        => array(
				'type'    => 'int',
				'default' => 0,
			),
			'watermarkPosition'  => array(
				'type'    => 'string',
				'default' => 'top-right',
			),
			'watermarkOpacity'   => array(
				'type'    => 'int',
				'default' => 55,
			),
			'videoFocusMode'     => array(
				'type'    => 'tristate',
				'default' => self::INHERIT,
			),
			'videoCaptionsOn'    => array(
				'type'    => 'tristate',
				'default' => self::INHERIT,
			),
			'videoCaptionSize'   => array(
				'type'    => 'string',
				'default' => self::INHERIT,
			),
			'videoCaptionBg'     => array(
				'type'    => 'string',
				'default' => self::INHERIT,
			),
			'videoChromeColor'   => array(
				'type'    => 'string',
				'default' => self::INHERIT,
			),
			'videoCaptionColor'  => array(
				'type'    => 'string',
				'default' => self::INHERIT,
			),
			'storyboard'         => array(
				'type'    => 'string',
				'default' => '',
			),
			'storyboardId'       => array(
				'type'    => 'int',
				'default' => 0,
			),

			// Video. Ignored for audio, which is why they carry empty defaults
			// rather than being a separate schema: one block, one shape, and a
			// track that changes kind does not lose its settings.
			'poster'       => array(
				'type'    => 'string',
				'default' => '',
			),
			'posterId'     => array(
				'type'    => 'int',
				'default' => 0,
			),
			'aspectRatio'  => array(
				'type'    => 'string',
				// Empty, not 16:9: an unset block follows the site's setting.
				'default' => '',
			),
			'tracks'       => array(
				'type'    => 'array',
				'default' => array(),
			),
			'chapters'     => array(
				'type'    => 'array',
				'default' => array(),
			),
			'layers'       => array(
				'type'    => 'array',
				'default' => array(),
			),
		);

		$preset_defaults = Settings::preset_defaults();

		foreach ( self::OVERRIDE_MAP as $preset_key => $attribute ) {
			$default = $preset_defaults[ $preset_key ] ?? '';

			$schema[ $attribute ] = array(
				'type'    => is_bool( $default ) ? 'tristate' : 'string',
				'default' => self::INHERIT,
			);
		}

		/**
		 * Filter the player attribute schema.
		 *
		 * @param array<string, array{type: string, default: mixed}> $schema Attribute schema.
		 */
		return apply_filters( 'imagina_player_attribute_schema', $schema );
	}

	/**
	 * @return array<string, mixed> Attribute name => default value.
	 */
	public static function defaults(): array {
		return array_map(
			static fn( array $definition ): mixed => $definition['default'],
			self::schema()
		);
	}

	/**
	 * Coerce raw input (shortcode strings, block JSON) into the schema.
	 *
	 * @param array<string, mixed> $input Raw attributes.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$out = array();

		foreach ( self::schema() as $name => $definition ) {
			$raw = $input[ $name ] ?? $definition['default'];

			$out[ $name ] = match ( $definition['type'] ) {
				'int'      => (int) $raw,
				'float'    => (float) $raw,
				'bool'     => self::to_bool( $raw ),
				'tristate' => self::to_tristate( $raw ),
				'array'    => self::to_array( $raw ),
				default    => is_scalar( $raw ) ? (string) $raw : '',
			};
		}

		$out['src']         = self::sanitize_media_url( (string) $out['src'] );
		$out['downloadUrl'] = self::sanitize_media_url( (string) $out['downloadUrl'] );
		$out['thumbnail']   = self::sanitize_media_url( (string) $out['thumbnail'] );
		$out['title']       = sanitize_text_field( (string) $out['title'] );
		$out['artist']      = sanitize_text_field( (string) $out['artist'] );
		$out['preset']      = sanitize_key( (string) $out['preset'] ) ?: Settings::DEFAULT_PRESET;
		$out['className']   = trim( preg_replace( '/[^A-Za-z0-9 _-]/', '', (string) $out['className'] ) ?? '' );
		$out['startTime']   = max( 0.0, (float) $out['startTime'] );
		$out['poster']      = self::sanitize_media_url( (string) $out['poster'] );
		// An empty ratio means "whatever the site says", not "16:9 regardless":
		// a site that works in vertical video should not have to set it on
		// every block.
		$out['aspectRatio'] = '' === trim( (string) $out['aspectRatio'] )
			? self::sanitize_ratio( (string) ( Settings::video()['ratio'] ?? self::DEFAULT_RATIO ) )
			: self::sanitize_ratio( (string) $out['aspectRatio'] );
		$out['tracks']      = self::sanitize_tracks( (array) $out['tracks'] );
		$out['chapters']    = self::sanitize_chapters( (array) $out['chapters'] );
		$out['layers']      = Layers::sanitize( (array) $out['layers'] );

		return $out;
	}

	/**
	 * Media URLs may be relative (`/wp-content/...`) or protocol-relative, and
	 * `esc_url_raw` keeps both while dropping `javascript:` and friends.
	 */
	public static function sanitize_media_url( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/**
	 * An aspect ratio, as `w:h`.
	 *
	 * This reaches CSS, so it is rebuilt from two integers rather than escaped:
	 * a value that arrives as anything other than two numbers cannot leave here
	 * as anything at all. Bounded because a ratio of 1:900 is a page-breaking
	 * sliver, not a design choice.
	 */
	public static function sanitize_ratio( string $ratio ): string {
		if ( ! preg_match( '/^\s*(\d{1,4})\s*[:\/]\s*(\d{1,4})\s*$/', $ratio, $parts ) ) {
			return self::DEFAULT_RATIO;
		}

		$width  = (int) $parts[1];
		$height = (int) $parts[2];

		if ( $width < 1 || $height < 1 ) {
			return self::DEFAULT_RATIO;
		}

		$factor = $width / $height;

		if ( $factor < 0.25 || $factor > 4.0 ) {
			return self::DEFAULT_RATIO;
		}

		return $width . ':' . $height;
	}

	/**
	 * A list, from a block's JSON or a shortcode's JSON string.
	 *
	 * @return array<int, mixed>
	 */
	public static function to_array( mixed $value ): array {
		if ( is_array( $value ) ) {
			return array_values( $value );
		}

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$decoded = json_decode( $value, true );

			return is_array( $decoded ) ? array_values( $decoded ) : array();
		}

		return array();
	}

	/**
	 * Subtitle tracks.
	 *
	 * A track with no source is not a track. A language code reaches the `srclang`
	 * attribute, which browsers parse, so it is cut down to what BCP 47 allows
	 * rather than escaped and hoped for.
	 *
	 * @param array<int, mixed> $tracks Raw tracks.
	 * @return array<int, array{src: string, srclang: string, label: string, kind: string, default: bool}>
	 */
	public static function sanitize_tracks( array $tracks ): array {
		$clean   = array();
		$default = false;

		foreach ( $tracks as $track ) {
			if ( ! is_array( $track ) ) {
				continue;
			}

			$src = self::sanitize_media_url( (string) ( $track['src'] ?? '' ) );

			if ( '' === $src ) {
				continue;
			}

			$kind = (string) ( $track['kind'] ?? 'subtitles' );

			if ( ! in_array( $kind, array( 'subtitles', 'captions', 'descriptions' ), true ) ) {
				$kind = 'subtitles';
			}

			$language = strtolower( (string) preg_replace( '/[^A-Za-z0-9-]/', '', (string) ( $track['srclang'] ?? '' ) ) );
			$language = substr( $language, 0, 20 );

			// Only the first default wins: two default tracks is not a state a
			// browser has an answer for.
			$is_default = ! $default && ! empty( $track['default'] );
			$default    = $default || $is_default;

			$clean[] = array(
				'src'     => $src,
				'srclang' => $language,
				'label'   => sanitize_text_field( (string) ( $track['label'] ?? $language ) ),
				'kind'    => $kind,
				'default' => $is_default,
			);
		}

		return $clean;
	}

	/**
	 * Chapters, in order and without overlaps.
	 *
	 * Sorted here rather than trusted from the editor, because the VTT built from
	 * them has to be monotonic and a browser given cues out of order simply drops
	 * the ones it does not like — silently.
	 *
	 * @param array<int, mixed> $chapters Raw chapters.
	 * @return array<int, array{start: float, title: string}>
	 */
	public static function sanitize_chapters( array $chapters ): array {
		$clean = array();

		foreach ( $chapters as $chapter ) {
			if ( ! is_array( $chapter ) ) {
				continue;
			}

			$title = sanitize_text_field( (string) ( $chapter['title'] ?? '' ) );

			if ( '' === $title ) {
				continue;
			}

			$clean[] = array(
				'start' => max( 0.0, self::to_seconds( $chapter['start'] ?? 0 ) ),
				'title' => $title,
			);
		}

		usort( $clean, static fn( array $a, array $b ): int => $a['start'] <=> $b['start'] );

		// Two chapters starting at the same second would produce a zero-length
		// cue, which is not a cue.
		$seen = array();

		return array_values(
			array_filter(
				$clean,
				static function ( array $chapter ) use ( &$seen ): bool {
					$key = (string) $chapter['start'];

					if ( isset( $seen[ $key ] ) ) {
						return false;
					}

					$seen[ $key ] = true;

					return true;
				}
			)
		);
	}

	/**
	 * Seconds from a number or a `mm:ss` / `hh:mm:ss` string.
	 */
	public static function to_seconds( mixed $value ): float {
		if ( is_numeric( $value ) ) {
			return (float) $value;
		}

		if ( ! is_string( $value ) ) {
			return 0.0;
		}

		$parts = array_reverse( array_map( 'trim', explode( ':', $value ) ) );
		$total = 0.0;

		foreach ( $parts as $index => $part ) {
			if ( ! is_numeric( $part ) || $index > 2 ) {
				return 0.0;
			}

			$total += (float) $part * ( 60 ** $index );
		}

		return $total;
	}

	public static function to_bool( mixed $value ): bool {
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'on', 'yes', 'true' ), true );
		}

		return (bool) $value;
	}

	/**
	 * Normalise anything into `''` (inherit), `'yes'` or `'no'`.
	 */
	public static function to_tristate( mixed $value ): string {
		if ( null === $value || '' === $value || 'inherit' === $value || 'default' === $value ) {
			return self::INHERIT;
		}

		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}

		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );

			if ( in_array( $value, array( '1', 'on', 'yes', 'true' ), true ) ) {
				return 'yes';
			}

			if ( in_array( $value, array( '0', 'off', 'no', 'false' ), true ) ) {
				return 'no';
			}

			return self::INHERIT;
		}

		return $value ? 'yes' : 'no';
	}
}
