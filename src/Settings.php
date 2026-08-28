<?php
/**
 * Global settings and player presets.
 *
 * A "preset" is a named bundle of look-and-feel options (skin, colours, which
 * controls are visible). Blocks and shortcodes reference a preset by key and may
 * override individual keys, which keeps per-instance markup small and lets a
 * site restyle every player from one place.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	public const OPTION_KEY = 'imagina_player_settings';

	public const DEFAULT_PRESET = 'default';

	/**
	 * Memoised resolved settings for the current request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Available skins.
	 *
	 * @return array<string, string> Skin key => translated label.
	 */
	public static function skins(): array {
		return Player\Skins::all();
	}

	/**
	 * Look-and-feel keys a preset owns, with their defaults.
	 *
	 * These double as the schema for preset sanitisation and for the block's
	 * attribute overrides, so there is a single source of truth.
	 *
	 * @return array<string, mixed>
	 */
	public static function preset_defaults(): array {
		return array(
			'label'             => __( 'Default', 'imagina-player' ),
			'description'       => '',
			'skin'              => 'wave',
			// Factory colours are deliberately neutral: a fresh install should not
			// arrive wearing another client's brand. Light waveform that darkens
			// as it plays, which reads on any background.
			'accent'            => '#1f2937',
			'wave_color'        => '#c9ced6',
			'wave_progress'     => '#1f2937',
			'wave_bars'         => 3,
			'wave_gap'          => 1,
			'wave_reflection'   => 0.25,
			'text_color'        => '#111827',
			'meta_color'        => '#6b7280',
			'background'        => 'transparent',
			'height'            => 60,
			'border_radius'     => 0,
			'rounded_bars'      => false,
			'show_artist'       => true,
			'show_title'        => true,
			'show_thumbnail'    => true,
			'show_volume'       => true,
			'show_time'         => true,
			'show_download'     => false,
			'show_speed'        => false,
			'show_skip'         => false,
			'skip_seconds'      => 15,
			'sticky'            => false,
			'sticky_position'   => 'bottom',
			'on_end'            => 'reset',
			'preload'           => 'metadata',
			'remember_position' => false,
		);
	}

	/**
	 * Non-visual plugin options.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'version'  => VERSION,
			'presets'  => array(
				self::DEFAULT_PRESET => self::preset_defaults(),
			),
			'peaks'    => array(
				'resolution'        => 400,
				'server_generation' => true,
				'client_fallback'   => true,
				'ffmpeg_path'       => '',
				// Above this, the browser is not asked to build a waveform: decoding
				// expands a file to raw float PCM, and an hour of stereo audio is
				// well over a gigabyte in memory.
				'max_client_bytes'  => 25 * 1024 * 1024,
			),
			'advanced'   => array(
				'load_frontend_css' => true,
				'lazy_init'         => true,
				'custom_css'        => '',
			),
			// Site-wide defaults. A new preset starts from these, so a brand change
			// is one edit rather than one edit per preset.
			'branding'   => array(
				'accent'        => '#1f2937',
				'wave_color'    => '#c9ced6',
				'text_color'    => '#111827',
				'meta_color'    => '#6b7280',
				'logo'          => '',
				'logo_link'     => '',
				'logo_height'   => 20,
			),
			/*
			 * Video. These were hardcoded in the renderer until somebody went
			 * looking for them and, quite reasonably, concluded the feature was
			 * not finished. A setting nobody can reach is not a setting.
			 */
			'video'      => array(
				'ratio'           => '16:9',
				// Milliseconds of stillness before the controls fade during
				// playback. Zero keeps them up for good.
				'hide_after'      => 2600,
				'show_pip'        => true,
				'show_fullscreen' => true,
				'show_speed'      => true,
				// How the poster fills its box. `cover` crops to fill, `contain`
				// shows the whole image and lets the black show through.
				'poster_fit'      => 'cover',
				// The browser's own download button, "Save video as" and casting
				// the raw file. Off by default, since that is the point.
				'block_download'  => true,
				'caption_size'    => 'medium',
				'caption_bg'      => 'solid',
				'big_play'        => true,
				/*
				 * For a video on YouTube: use the domain that sets no cookie
				 * until playback begins. On by default, because the alternative
				 * is a third-party cookie on every visitor who never presses
				 * play, and in the EU that needs a banner.
				 */
				'provider_privacy' => true,
			),
			/*
			 * Where a track's name comes from when the block does not say.
			 *
			 * This already happened — ID3 tags, then the attachment's title —
			 * but it was neither configurable nor visible, so an author saw two
			 * empty fields and no reason to believe anything would fill them.
			 */
			'metadata'   => array(
				// auto: the file's own tags, then the library title, then the
				// file name. Or pin it to one of those.
				'title_from'  => 'auto',
				'artist_from' => 'auto',
				// A file called `mi-conferencia_01.mp3` becomes "Mi conferencia
				// 01" rather than being left blank.
				'from_filename' => true,
				// Audio files often carry their own cover art, and WordPress
				// already extracts it on upload.
				'use_cover'   => true,
			),
			'protection' => array(
				'enabled'       => false,
				'require_login' => false,
				'bind_to_user'  => false,
				'bind_to_ip'    => false,
				'ttl'           => 12 * HOUR_IN_SECONDS,
				'delivery'      => 'php',
				'xaccel_prefix' => '/imagina-protected/',
			),
		);
	}

	/**
	 * Where track details come from.
	 *
	 * @return array<string, mixed>
	 */
	public static function metadata(): array {
		$all = self::all();

		return is_array( $all['metadata'] ?? null ) ? $all['metadata'] : self::defaults()['metadata'];
	}

	/**
	 * Video defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function video(): array {
		$all = self::all();

		return is_array( $all['video'] ?? null ) ? $all['video'] : self::defaults()['video'];
	}

	/**
	 * The stored settings, merged over defaults so a partial option never
	 * produces undefined-index notices after an upgrade.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION_KEY, array() );
			$stored = is_array( $stored ) ? $stored : array();

			$resolved = array_replace_recursive( self::defaults(), $stored );

			// `array_replace_recursive` would merge preset arrays key by key, which is
			// what we want, but it must not resurrect presets the user deleted.
			if ( isset( $stored['presets'] ) && is_array( $stored['presets'] ) ) {
				$resolved['presets'] = array();

				foreach ( $stored['presets'] as $key => $preset ) {
					$resolved['presets'][ $key ] = array_replace( self::preset_defaults(), is_array( $preset ) ? $preset : array() );
				}
			}

			if ( empty( $resolved['presets'] ) ) {
				$resolved['presets'] = array( self::DEFAULT_PRESET => self::preset_defaults() );
			}

			self::$cache = $resolved;
		}

		/**
		 * Filter the resolved plugin settings.
		 *
		 * @param array<string, mixed> $settings Resolved settings.
		 */
		return apply_filters( 'imagina_player_settings', self::$cache );
	}

	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * @param array<string, mixed> $settings Full settings array to persist.
	 */
	public static function update( array $settings ): void {
		update_option( self::OPTION_KEY, $settings );
		self::flush_cache();
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function presets(): array {
		$settings = self::all();

		return is_array( $settings['presets'] ) ? $settings['presets'] : array();
	}

	/**
	 * Resolve a preset by key, falling back to the default preset.
	 *
	 * @return array<string, mixed>
	 */
	public static function preset( string $key ): array {
		$presets = self::presets();

		if ( isset( $presets[ $key ] ) ) {
			return $presets[ $key ];
		}

		return $presets[ self::DEFAULT_PRESET ] ?? self::preset_defaults();
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function peaks_settings(): array {
		$settings = self::all();

		return is_array( $settings['peaks'] ) ? $settings['peaks'] : array();
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function advanced(): array {
		$settings = self::all();

		return is_array( $settings['advanced'] ) ? $settings['advanced'] : array();
	}

	/**
	 * Site-wide brand defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function branding(): array {
		$settings = self::all();

		return is_array( $settings['branding'] ?? null ) ? $settings['branding'] : self::defaults()['branding'];
	}

	/**
	 * A preset for a brand-new entry: the brand colours rather than the
	 * plugin's own.
	 *
	 * @return array<string, mixed>
	 */
	public static function preset_from_branding(): array {
		$branding = self::branding();
		$preset   = self::preset_defaults();

		$preset['accent']        = (string) $branding['accent'];
		$preset['wave_color']    = (string) $branding['wave_color'];
		$preset['wave_progress'] = (string) $branding['accent'];
		$preset['text_color']    = (string) $branding['text_color'];
		$preset['meta_color']    = (string) $branding['meta_color'];

		return $preset;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function protection(): array {
		$settings = self::all();

		return is_array( $settings['protection'] ?? null ) ? $settings['protection'] : self::defaults()['protection'];
	}

	/**
	 * Coerce arbitrary input into the preset schema.
	 *
	 * @param array<string, mixed> $input Raw preset values.
	 * @return array<string, mixed>
	 */
	public static function sanitize_preset( array $input ): array {
		$defaults = self::preset_defaults();
		$out      = array();

		foreach ( $defaults as $key => $default ) {
			$value = $input[ $key ] ?? $default;

			$out[ $key ] = match ( true ) {
				is_bool( $default )   => (bool) rest_sanitize_boolean( $value ),
				is_int( $default )    => (int) $value,
				is_float( $default )  => (float) $value,
				default               => (string) $value,
			};
		}

		$out['label']       = sanitize_text_field( (string) $out['label'] );
		$out['description'] = sanitize_text_field( (string) $out['description'] );
		$out['skin']        = Player\Skins::normalize( (string) $out['skin'] );

		foreach ( array( 'accent', 'wave_color', 'wave_progress', 'text_color', 'meta_color' ) as $color_key ) {
			$out[ $color_key ] = self::sanitize_color( (string) $out[ $color_key ], $defaults[ $color_key ] );
		}

		$out['background']      = 'transparent' === $out['background'] ? 'transparent' : self::sanitize_color( (string) $out['background'], 'transparent' );
		$out['wave_bars']       = max( 1, min( 40, (int) $out['wave_bars'] ) );
		$out['wave_gap']        = max( 0, min( 20, (int) $out['wave_gap'] ) );
		$out['wave_reflection'] = max( 0.0, min( 0.8, (float) $out['wave_reflection'] ) );
		$out['height']          = max( 24, min( 400, (int) $out['height'] ) );
		$out['skip_seconds']    = max( 1, min( 120, (int) $out['skip_seconds'] ) );
		$out['preload']         = in_array( $out['preload'], array( 'none', 'metadata', 'auto' ), true ) ? $out['preload'] : 'metadata';
		$out['border_radius']   = max( 0, min( 40, (int) $out['border_radius'] ) );
		$out['on_end']          = in_array( $out['on_end'], array( 'reset', 'loop', 'stop' ), true ) ? $out['on_end'] : 'reset';
		$out['sticky_position'] = in_array( $out['sticky_position'], array( 'bottom', 'bottom-left', 'bottom-right' ), true ) ? $out['sticky_position'] : 'bottom';

		return $out;
	}

	/**
	 * Accept hex colours and CSS custom properties; anything else falls back.
	 */
	public static function sanitize_color( string $value, string $fallback ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return $fallback;
		}

		if ( str_starts_with( $value, 'var(' ) && preg_match( '/^var\(--[A-Za-z0-9_-]+\)$/', $value ) ) {
			return $value;
		}

		$hex = sanitize_hex_color( $value );

		if ( $hex ) {
			return $hex;
		}

		// Allow bare hex without the leading hash, which is how most shortcode
		// authors write colours.
		$hex = sanitize_hex_color( '#' . ltrim( $value, '#' ) );

		return $hex ? $hex : $fallback;
	}
}
