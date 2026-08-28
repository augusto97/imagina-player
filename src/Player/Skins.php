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

	/** What a video skin decides, and the one it starts with. */
	public const DEFAULT_VIDEO_SKIN = 'theater';

	/**
	 * Skins for a picture rather than for a waveform.
	 *
	 * The seven below are all audio skins: each arranges a waveform, a row of
	 * transport buttons and a title beside them. A video block offered them
	 * anyway, so choosing one either did nothing visible or did something that
	 * made no sense — a "card with cover" on a video that already has a poster,
	 * a "waveform, mirrored" on a picture with no waveform.
	 *
	 * A video skin decides different things: where the control bar sits, how it
	 * behaves when the pointer leaves, and whether the title is drawn over the
	 * picture. Presto ships three of these and Fluent two; the shapes below are
	 * the ones both of them converged on.
	 *
	 * @return array<string, string> Skin key => translated label.
	 */
	public static function video(): array {
		return apply_filters(
			'imagina_player_video_skins',
			array(
				'theater'  => __( 'Theater', 'imagina-player' ),
				'minimal'  => __( 'Minimal', 'imagina-player' ),
				'stacked'  => __( 'Stacked', 'imagina-player' ),
			)
		);
	}

	/** What each video skin is, for a screen that has to choose one. */
	public static function video_descriptions(): array {
		return array(
			'theater' => __( 'Controls over the picture, fading out while it plays. What most video players do.', 'imagina-player' ),
			'minimal' => __( 'A thin progress line and nothing else until the pointer arrives. For a video that is part of the page rather than the point of it.', 'imagina-player' ),
			'stacked' => __( 'Controls in a solid bar under the picture, always visible. Nothing ever covers the video.', 'imagina-player' ),
		);
	}

	public static function is_video_skin( string $skin ): bool {
		return array_key_exists( $skin, self::video() );
	}

	/**
	 * The skin a player should actually use.
	 *
	 * A block carries one skin, and a track can change medium — an author
	 * replaces an audio file with a video and the saved skin is now meaningless.
	 * Rather than render something wrong, each medium falls back to its own
	 * default when the saved skin is not one of its own.
	 */
	public static function resolve( string $skin, bool $is_video ): string {
		if ( $is_video ) {
			return self::is_video_skin( $skin ) ? $skin : self::DEFAULT_VIDEO_SKIN;
		}

		return array_key_exists( $skin, self::all() ) ? $skin : self::DEFAULT_SKIN;
	}

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

	/**
	 * Keep a skin that any medium recognises.
	 *
	 * A preset is shared between audio and video blocks, so a saved skin has to
	 * survive being stored even when it belongs to the other medium — deciding
	 * which one actually applies is `resolve()`'s job, at render time, where the
	 * medium is known.
	 */
	public static function normalize( string $skin ): string {
		return self::exists( $skin ) || self::is_video_skin( $skin )
			? $skin
			: self::DEFAULT_SKIN;
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
