<?php
/**
 * Things that appear over a player part-way through, and ask something of the
 * listener.
 *
 * Three kinds ask something, because they answer three different questions:
 *
 * - **cta**    — "now that you have seen this, do that." Interrupts: it pauses
 *                playback and covers the picture, so it is used sparingly and
 *                usually at the end.
 * - **bar**    — the same offer without the interruption. A strip along the
 *                edge that appears and stays. Nothing pauses.
 * - **email**  — a gate. Playback stops until an address is given, or until the
 *                listener skips, if skipping is allowed.
 *
 * Four more decorate the picture and ask nothing: **text** (a caption in a
 * corner), **image** (a logo or a still), **hotspot** (a spot to press, with a
 * label and maybe a link) and **shortcode** (whatever another plugin prints).
 * They sit where the author put them, between the moments they were given,
 * and never pause anything.
 *
 * Deliberately not video-only. An email gate two thirds of the way through a
 * podcast episode is exactly the same feature, and the player it hangs on is
 * the same player.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Player;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Layers {

	public const TYPES = array( 'cta', 'bar', 'email', 'text', 'image', 'hotspot', 'shortcode' );

	/** The kinds that stop playback: they are asking a question. */
	public const INTERRUPTING = array( 'cta', 'email' );

	/**
	 * The kinds that decorate the picture rather than ask anything of the
	 * viewer: a caption, a logo, a spot to press, a form from another plugin.
	 * They never pause, and they sit over the picture at a place of the
	 * author's choosing.
	 */
	public const DECOR = array( 'text', 'image', 'hotspot', 'shortcode' );

	public const POSITIONS = array( 'top-left', 'top', 'top-right', 'left', 'center', 'right', 'bottom-left', 'bottom', 'bottom-right' );

	/**
	 * The kinds rendered over the picture, as opposed to under it.
	 *
	 * @return array<int, string>
	 */
	public static function over(): array {
		return array_values( array_diff( self::TYPES, array( 'bar' ) ) );
	}

	/**
	 * Clean a list of layers from block JSON.
	 *
	 * Anything that would not produce a working layer is dropped rather than
	 * rendered broken: a call to action with no button, a gate with nowhere to
	 * send the address.
	 *
	 * @param array<int, mixed> $layers Raw layers.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize( array $layers ): array {
		$clean = array();

		foreach ( $layers as $layer ) {
			if ( ! is_array( $layer ) ) {
				continue;
			}

			$type = (string) ( $layer['type'] ?? '' );

			if ( ! in_array( $type, self::TYPES, true ) ) {
				continue;
			}

			// A decoration is there from the start unless told otherwise; an
			// offer that interrupts belongs at the end.
			$at = min( 100, max( 0, (int) ( $layer['at'] ?? ( in_array( $type, self::DECOR, true ) ? 0 : 100 ) ) ) );

			/*
			 * When it goes away again.
			 *
			 * Both of the players this one is measured against have this and it
			 * did not: Presto's overlays "appear at a specific time and
			 * disappear at another", and Fluent's say how long they stay
			 * visible. Without it every layer, once shown, was on the screen
			 * for the rest of the video — which for a bar meant it covered the
			 * end of a lesson, and for a mid-roll offer meant there was no way
			 * to make it a moment rather than a permanent fixture.
			 *
			 * Zero means "stays", which is the old behaviour and still the
			 * right answer for a call to action at the end.
			 */
			$until = min( 100, max( 0, (int) ( $layer['until'] ?? 0 ) ) );

			$common = array(
				'type'  => $type,
				/*
				 * Where in the track it appears, as a percentage. A bar is a
				 * standing offer, so zero — visible from the moment the page
				 * loads — is its natural answer; a call to action interrupts,
				 * so the end is.
				 */
				'at'    => $at,
				// An end before the beginning is not an end.
				'until' => $until > $at ? $until : 0,
				'title' => sanitize_text_field( (string) ( $layer['title'] ?? '' ) ),
				'text'  => sanitize_text_field( (string) ( $layer['text'] ?? '' ) ),
				'skip'  => ! empty( $layer['skip'] ),
			);

			$clean[] = match ( $type ) {
				'text' => $common + self::link( $layer ) + array(
					'position' => self::position( $layer, 'bottom-left' ),
				),
				'image' => $common + self::link( $layer ) + array(
					'image'    => Attributes::sanitize_media_url( (string) ( $layer['image'] ?? '' ) ),
					'imageId'  => max( 0, (int) ( $layer['imageId'] ?? 0 ) ),
					// As a share of the picture's width, so it scales with it.
					'width'    => min( 100, max( 5, (int) ( $layer['width'] ?? 25 ) ) ),
					'position' => self::position( $layer, 'top-right' ),
				),
				'hotspot' => $common + self::link( $layer ) + array(
					'x' => min( 100, max( 0, (int) ( $layer['x'] ?? 50 ) ) ),
					'y' => min( 100, max( 0, (int) ( $layer['y'] ?? 50 ) ) ),
				),
				'shortcode' => $common + array(
					// The shortcode's own output is what the viewer sees; this
					// is the text that names it, and it runs when rendered.
					'shortcode' => sanitize_text_field( (string) ( $layer['shortcode'] ?? '' ) ),
					'position'  => self::position( $layer, 'center' ),
				),
				'email' => $common + array(
					// Where an address goes after it is captured. Stored on the
					// layer rather than globally so one site can run a course
					// list and a newsletter list from different players.
					'list'    => sanitize_text_field( (string) ( $layer['list'] ?? '' ) ),
					'button'  => self::label( $layer, __( 'Send', 'imagina-player' ) ),
					'consent' => sanitize_text_field( (string) ( $layer['consent'] ?? '' ) ),
					'thanks'  => sanitize_text_field( (string) ( $layer['thanks'] ?? __( 'Thank you.', 'imagina-player' ) ) ),
				),
				default => $common + array(
					'button' => self::label( $layer, __( 'Find out more', 'imagina-player' ) ),
					'url'    => Attributes::sanitize_media_url( (string) ( $layer['url'] ?? '' ) ),
					'newTab' => ! empty( $layer['newTab'] ),
				),
			};
		}

		// A button that goes nowhere is not a call to action, a caption with
		// no words is not a caption, and a picture with no picture is nothing.
		return array_values(
			array_filter(
				$clean,
				static fn( array $layer ): bool => match ( $layer['type'] ) {
					'email'     => true,
					'text'      => '' !== $layer['title'] || '' !== $layer['text'],
					'image'     => '' !== $layer['image'],
					'hotspot'   => '' !== $layer['title'] || '' !== $layer['text'] || '' !== $layer['url'],
					'shortcode' => str_contains( $layer['shortcode'], '[' ),
					default     => '' !== $layer['url'],
				}
			)
		);
	}

	/**
	 * Where a decoration sits, from the nine places it can.
	 *
	 * @param array<string, mixed> $layer Raw layer.
	 */
	private static function position( array $layer, string $fallback ): string {
		$position = (string) ( $layer['position'] ?? '' );

		return in_array( $position, self::POSITIONS, true ) ? $position : $fallback;
	}

	/**
	 * An optional link: a decoration may take the viewer somewhere, or not.
	 *
	 * @param array<string, mixed> $layer Raw layer.
	 * @return array{url: string, newTab: bool}
	 */
	private static function link( array $layer ): array {
		return array(
			'url'    => Attributes::sanitize_media_url( (string) ( $layer['url'] ?? '' ) ),
			'newTab' => ! empty( $layer['newTab'] ),
		);
	}

	/**
	 * @param array<string, mixed> $layer Raw layer.
	 */
	private static function label( array $layer, string $fallback ): string {
		$label = sanitize_text_field( (string) ( $layer['button'] ?? '' ) );

		return '' === $label ? $fallback : $label;
	}

	/**
	 * Whether any of these stops playback.
	 *
	 * Used to decide whether the runtime needs loading at all: a player carrying
	 * only a bar still needs it, but knowing which kinds are present lets the
	 * module skip work.
	 *
	 * @param array<int, array<string, mixed>> $layers Sanitised layers.
	 */
	public static function interrupts( array $layers ): bool {
		foreach ( $layers as $layer ) {
			if ( in_array( $layer['type'], self::INTERRUPTING, true ) ) {
				return true;
			}
		}

		return false;
	}
}
