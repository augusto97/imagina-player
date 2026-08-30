<?php
/**
 * Things that appear over a player part-way through, and ask something of the
 * listener.
 *
 * Three kinds, because they answer three different questions:
 *
 * - **cta**    — "now that you have seen this, do that." Interrupts: it pauses
 *                playback and covers the picture, so it is used sparingly and
 *                usually at the end.
 * - **bar**    — the same offer without the interruption. A strip along the
 *                edge that appears and stays. Nothing pauses.
 * - **email**  — a gate. Playback stops until an address is given, or until the
 *                listener skips, if skipping is allowed.
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

	public const TYPES = array( 'cta', 'bar', 'email' );

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

			$at = min( 100, max( 0, (int) ( $layer['at'] ?? 100 ) ) );

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

		// A button that goes nowhere is not a call to action.
		return array_values(
			array_filter(
				$clean,
				static fn( array $layer ): bool => 'email' === $layer['type'] || '' !== $layer['url']
			)
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
			if ( in_array( $layer['type'], array( 'cta', 'email' ), true ) ) {
				return true;
			}
		}

		return false;
	}
}
