<?php
/**
 * The skin catalogue and what each one implies.
 *
 * A skin is a layout, not a colour scheme — colours come from the preset. What
 * varies here is the shape of the player: whether it draws a waveform, whether
 * that waveform is grounded or mirrored, and how the pieces sit relative to each
 * other.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Player;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Skins {

	public const DEFAULT_SKIN = 'wave';

	/**
	 * Skins that draw a canvas waveform rather than a plain progress bar.
	 */
	private const WAVEFORM = array( 'wave', 'wave-centered', 'card' );

	/**
	 * Skins whose waveform grows from the middle in both directions.
	 */
	private const CENTERED = array( 'wave-centered' );

	/**
	 * Skins with no scrubber at all.
	 */
	private const NO_SCRUBBER = array( 'minimal' );

	/**
	 * @return array<string, string> Skin key => translated label.
	 */
	public static function all(): array {
		return apply_filters(
			'imagina_player_skins',
			array(
				'wave'          => __( 'Waveform', 'imagina-player' ),
				'wave-centered' => __( 'Waveform, mirrored', 'imagina-player' ),
				'card'          => __( 'Card with cover', 'imagina-player' ),
				'compact'       => __( 'Compact, one line', 'imagina-player' ),
				'pill'          => __( 'Pill', 'imagina-player' ),
				'bar'           => __( 'Progress bar', 'imagina-player' ),
				'minimal'       => __( 'Minimal', 'imagina-player' ),
			)
		);
	}

	/**
	 * Short descriptions for the settings screen, so a skin can be chosen without
	 * trying all of them.
	 *
	 * @return array<string, string>
	 */
	public static function descriptions(): array {
		return array(
			'wave'          => __( 'The full waveform with its reflection, and the elapsed time riding the playhead.', 'imagina-player' ),
			'wave-centered' => __( 'The waveform mirrored around the centre line.', 'imagina-player' ),
			'card'          => __( 'Cover art alongside the waveform and the controls.', 'imagina-player' ),
			'compact'       => __( 'Everything on one line: play button, title and a slim bar.', 'imagina-player' ),
			'pill'          => __( 'A rounded bar that sits inline with your text.', 'imagina-player' ),
			'bar'           => __( 'A plain progress bar. The lightest option.', 'imagina-player' ),
			'minimal'       => __( 'Play button and title only, with no scrubber.', 'imagina-player' ),
		);
	}

	public static function exists( string $skin ): bool {
		return array_key_exists( $skin, self::all() );
	}

	public static function normalize( string $skin ): string {
		return self::exists( $skin ) ? $skin : self::DEFAULT_SKIN;
	}

	public static function uses_waveform( string $skin ): bool {
		return in_array( $skin, self::WAVEFORM, true );
	}

	public static function is_centered( string $skin ): bool {
		return in_array( $skin, self::CENTERED, true );
	}

	public static function has_scrubber( string $skin ): bool {
		return ! in_array( $skin, self::NO_SCRUBBER, true );
	}

	/**
	 * How the parts are arranged.
	 *
	 * Three arrangements cover every skin, and they differ in DOM order, not
	 * only in CSS: a cover image that leads a card cannot be the same node that
	 * sits inside a control row, and CSS ordering alone leaves the pieces in the
	 * wrong reading order for a screen reader.
	 *
	 * - `stacked` scrubber above, controls below.
	 * - `card`    cover art leading, everything else beside it.
	 * - `inline`  one row: play, title, scrubber, controls.
	 */
	public static function layout( string $skin ): string {
		return match ( $skin ) {
			'card'             => 'card',
			'compact', 'pill'  => 'inline',
			default            => 'stacked',
		};
	}
}
