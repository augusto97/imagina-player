<?php
/**
 * `[imagina_time at="1:35"]` — a button that jumps a player to a moment.
 *
 * Written into the text around a player: a list of the questions an interview
 * answers, each one a button that takes the viewer straight to it. The button
 * is real markup the server writes, so a reader without the script still sees
 * the time; with it, a click seeks the player and starts it.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Shortcodes;

use ImaginaPlayer\Player\Attributes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TimeShortcode {

	public const TAG = 'imagina_time';

	/** `t="95"` reads like the link parameter, and costs nothing to accept. */
	private const AT_ALIASES = array( 't', 'time', 'seconds' );

	public function hooks(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * @param array<string, mixed>|string $atts    Shortcode attributes.
	 * @param string|null                 $content The text on the button; the time itself when empty.
	 */
	public function render( $atts = array(), ?string $content = null ): string {
		$atts = is_array( $atts ) ? array_change_key_case( $atts, CASE_LOWER ) : array();

		$atts = shortcode_atts(
			array(
				'at'      => null,
				't'       => null,
				'time'    => null,
				'seconds' => null,
				'player'  => '',
				'class'   => '',
			),
			$atts,
			self::TAG
		);

		$at = $atts['at'];

		foreach ( self::AT_ALIASES as $alias ) {
			if ( null === $at && null !== $atts[ $alias ] ) {
				$at = $atts[ $alias ];
			}
		}

		$seconds = self::seconds( $at );

		if ( null === $seconds ) {
			return '';
		}

		$label = trim( (string) $content );

		if ( '' === $label ) {
			$label = self::stamp( $seconds );
		}

		$player  = sanitize_html_class( (string) $atts['player'] );
		$classes = trim( 'imgp-time ' . implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $atts['class'] ) ?: array() ) ) );

		return sprintf(
			'<button type="button" class="%s" data-imgp-time="%s"%s>%s</button>',
			esc_attr( $classes ),
			esc_attr( self::plain( $seconds ) ),
			'' === $player ? '' : ' data-imgp-player="' . esc_attr( $player ) . '"',
			// The shortcode's own content may carry markup the author wrote
			// (bold, an icon); it is trusted as far as post content is.
			wp_kses_post( $label )
		);
	}

	/**
	 * A moment in seconds from `95`, `1:35`, `1:02:03`, `1m35s` or `95s`.
	 *
	 * @return float|null Null when it is not a time at all.
	 */
	public static function seconds( mixed $value ): ?float {
		if ( null === $value ) {
			return null;
		}

		$value = strtolower( trim( (string) $value ) );

		if ( '' === $value ) {
			return null;
		}

		if ( preg_match( '/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+(?:\.\d+)?)s?)?$/', $value, $m ) && '' !== $value ) {
			$hours   = isset( $m[1] ) && '' !== $m[1] ? (float) $m[1] : 0.0;
			$minutes = isset( $m[2] ) && '' !== $m[2] ? (float) $m[2] : 0.0;
			$rest    = isset( $m[3] ) && '' !== $m[3] ? (float) $m[3] : 0.0;

			return max( 0.0, $hours * 3600 + $minutes * 60 + $rest );
		}

		if ( str_contains( $value, ':' ) ) {
			$total = Attributes::to_seconds( $value );

			return $total > 0 || '0:00' === $value ? $total : null;
		}

		return null;
	}

	/** `1:35`, or `1:02:03` past an hour — what a viewer expects to read. */
	public static function stamp( float $seconds ): string {
		$whole   = (int) floor( $seconds );
		$hours   = intdiv( $whole, 3600 );
		$minutes = intdiv( $whole % 3600, 60 );
		$rest    = $whole % 60;

		if ( $hours > 0 ) {
			return sprintf( '%d:%02d:%02d', $hours, $minutes, $rest );
		}

		return sprintf( '%d:%02d', $minutes, $rest );
	}

	/** Seconds as the attribute carries them: `95` rather than `95.0`. */
	private static function plain( float $seconds ): string {
		return rtrim( rtrim( number_format( $seconds, 3, '.', '' ), '0' ), '.' );
	}
}
