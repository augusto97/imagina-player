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
			'--imgp-wave'           => (string) $config['wave_color'],
			'--imgp-wave-progress'  => (string) $config['wave_progress'],
			'--imgp-text'           => (string) $config['text_color'],
			'--imgp-meta'           => (string) $config['meta_color'],
			'--imgp-wave-height'    => (int) $config['height'] . 'px',
			'--imgp-reflection'     => (string) (float) $config['wave_reflection'],
			'--imgp-bar-radius'     => $config['rounded_bars'] ? '999px' : '0',
		);

		if ( 'transparent' !== $config['background'] ) {
			$vars['--imgp-bg'] = (string) $config['background'];
		}

		return $vars;
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
