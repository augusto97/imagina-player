<?php
/**
 * Resolves a preset plus per-instance overrides into one effective config.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Player;

use ImaginaPlayer\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Config {

	/**
	 * Merge a preset with instance overrides.
	 *
	 * @param array<string, mixed> $atts Sanitised attributes.
	 * @return array<string, mixed> Effective preset-shaped settings.
	 */
	public static function resolve( array $atts ): array {
		$preset   = Settings::preset( (string) ( $atts['preset'] ?? Settings::DEFAULT_PRESET ) );
		$defaults = Settings::preset_defaults();

		foreach ( Attributes::override_map() as $preset_key => $attribute ) {
			$override = $atts[ $attribute ] ?? Attributes::INHERIT;

			if ( Attributes::INHERIT === $override || null === $override ) {
				continue;
			}

			$default = $defaults[ $preset_key ] ?? '';

			$preset[ $preset_key ] = match ( true ) {
				is_bool( $default )  => 'yes' === $override,
				is_int( $default )   => (int) $override,
				is_float( $default ) => (float) $override,
				default              => (string) $override,
			};
		}

		$resolved = Settings::sanitize_preset( $preset );

		/**
		 * Filter the effective player settings for one instance.
		 *
		 * @param array<string, mixed> $resolved Effective settings.
		 * @param array<string, mixed> $atts     Sanitised instance attributes.
		 */
		return apply_filters( 'imagina_player_resolved_config', $resolved, $atts );
	}

	/**
	 * CSS custom properties for one instance.
	 *
	 * Colours ride on custom properties rather than generated stylesheets so a
	 * page with fifty players still ships exactly one stylesheet.
	 *
	 * @param array<string, mixed> $config Effective settings.
	 * @return array<string, string>
	 */
	public static function css_variables( array $config ): array {
		$vars = array(
			'--imgp-accent'         => (string) $config['accent'],
			// Anything printed *on* the accent — a button label, an action bar —
			// needs a foreground that survives the accent the site actually
			// chose. White is right on a deep magenta and close to unreadable on
			// a bright cyan, so it is worked out rather than assumed.
			'--imgp-on-accent'      => self::readable_on( (string) $config['accent'] ),
			'--imgp-wave'           => (string) $config['wave_color'],
			'--imgp-wave-progress'  => (string) $config['wave_progress'],
			'--imgp-text'           => (string) $config['text_color'],
			'--imgp-meta'           => (string) $config['meta_color'],
			'--imgp-wave-height'    => (int) $config['height'] . 'px',
			'--imgp-reflection'     => (string) (float) $config['wave_reflection'],
			'--imgp-bar-radius'     => $config['rounded_bars'] ? '999px' : '0',
			'--imgp-radius'         => (int) $config['border_radius'] . 'px',
		);

		if ( 'transparent' !== $config['background'] ) {
			$vars['--imgp-bg'] = (string) $config['background'];
		}

		return $vars;
	}

	/**
	 * A foreground that reads against a given background.
	 *
	 * Relative luminance by the WCAG definition, with the threshold where the
	 * contrast of black and of white against the colour are equal. Anything the
	 * function cannot parse — a named colour, `rgb()`, a gradient — gets white,
	 * which is the safer half of the guess for the dark accents most players use.
	 *
	 * @param string $color A CSS colour, ideally `#rgb` or `#rrggbb`.
	 */
	public static function readable_on( string $color ): string {
		$hex = ltrim( trim( $color ), '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return '#ffffff';
		}

		$channels = array();

		foreach ( str_split( $hex, 2 ) as $pair ) {
			$value = hexdec( $pair ) / 255;

			$channels[] = $value <= 0.03928
				? $value / 12.92
				: pow( ( $value + 0.055 ) / 1.055, 2.4 );
		}

		$luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

		return $luminance > 0.179 ? '#111111' : '#ffffff';
	}

	/**
	 * Serialise CSS variables into a `style` attribute value.
	 *
	 * @param array<string, string> $vars Custom properties.
	 */
	public static function style_attribute( array $vars ): string {
		$parts = array();

		foreach ( $vars as $name => $value ) {
			if ( '' === $value ) {
				continue;
			}

			$parts[] = $name . ':' . $value;
		}

		return implode( ';', $parts );
	}
}
