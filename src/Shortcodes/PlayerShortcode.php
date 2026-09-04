<?php
/**
 * `[imagina_player]` — for page builders and themes that cannot use blocks.
 *
 * Shortcode attributes use snake_case; the block uses camelCase. Both map onto
 * the same schema so neither is the "real" API.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Shortcodes;

use ImaginaPlayer\Player\Attributes;
use ImaginaPlayer\Render\PlayerRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PlayerShortcode {

	public const TAG = 'imagina_player';

	/**
	 * Alternative spellings accepted for the `src` attribute.
	 */
	private const SRC_ALIASES = array( 'source', 'mp3', 'url' );

	/** `field="video_url"` reads more naturally than `source_field`. */
	private const FIELD_ALIAS = 'field';

	public function hooks(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 */
	public function render( $atts = array() ): string {
		$atts = is_array( $atts ) ? $atts : array();

		$defaults = array();

		foreach ( array_keys( Attributes::schema() ) as $name ) {
			$defaults[ self::to_snake( $name ) ] = null;
		}

		foreach ( self::SRC_ALIASES as $alias ) {
			$defaults[ $alias ] = null;
		}

		$defaults[ self::FIELD_ALIAS ] = null;

		$atts = shortcode_atts( $defaults, array_change_key_case( $atts, CASE_LOWER ), self::TAG );

		$mapped = array();

		foreach ( Attributes::schema() as $name => $definition ) {
			$value = $atts[ self::to_snake( $name ) ] ?? null;

			if ( null !== $value ) {
				$mapped[ $name ] = $value;
			}
		}

		// `src` is the documented name, but `source` and `mp3` read naturally in a
		// shortcode and cost nothing to accept.
		foreach ( self::SRC_ALIASES as $alias ) {
			if ( empty( $mapped['src'] ) && ! empty( $atts[ $alias ] ) ) {
				$mapped['src'] = $atts[ $alias ];
			}
		}

		if ( empty( $mapped['sourceField'] ) && ! empty( $atts[ self::FIELD_ALIAS ] ) ) {
			$mapped['sourceField'] = $atts[ self::FIELD_ALIAS ];
		}

		$renderer = new PlayerRenderer();

		return $renderer->render( $mapped );
	}

	/**
	 * `showVolume` => `show_volume`.
	 */
	private static function to_snake( string $value ): string {
		return strtolower( (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $value ) );
	}
}
