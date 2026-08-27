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
				'default' => self::DEFAULT_RATIO,
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
		$out['aspectRatio'] = self::sanitize_ratio( (string) $out['aspectRatio'] );

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
