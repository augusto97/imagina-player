<?php
/**
 * How far one person has got through a course, kept with their account.
 *
 * The browser keeps it too, and that is all a visitor who is not signed in
 * gets. Kept here as well, it follows a student from the laptop to the phone
 * and from one page of the course to the next: the same list on another page
 * is the same course, because the course is named by the block rather than by
 * the page.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Player;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CourseProgress {

	public const META = 'imagina_player_progress';

	/** Courses remembered per person; the least recently touched go first. */
	private const KEEP = 300;

	/** Lessons remembered per course. */
	private const ITEMS = 500;

	/**
	 * A course's or a lesson's name, as these are written: short and plain.
	 */
	public static function key( string $key ): string {
		$key = strtolower( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $key ) );

		return substr( $key, 0, 64 );
	}

	/**
	 * One person's progress through one course, or null.
	 *
	 * @return array{w: array<int, string>, c: string, p: float, t: int}|null
	 */
	public static function get( int $user_id, string $course ): ?array {
		$course = self::key( $course );

		if ( $user_id <= 0 || '' === $course ) {
			return null;
		}

		$all = get_user_meta( $user_id, self::META, true );

		if ( ! is_array( $all ) || ! is_array( $all[ $course ] ?? null ) ) {
			return null;
		}

		return self::clean( $all[ $course ] );
	}

	/**
	 * Keep what the browser reports, merged with what was there: a lesson
	 * watched on another device stays watched.
	 *
	 * @param array<string, mixed> $state What the browser sent.
	 * @return array{w: array<int, string>, c: string, p: float, t: int}
	 */
	public static function save( int $user_id, string $course, array $state ): array {
		$course = self::key( $course );
		$all    = get_user_meta( $user_id, self::META, true );
		$all    = is_array( $all ) ? $all : array();
		$before = is_array( $all[ $course ] ?? null ) ? self::clean( $all[ $course ] ) : null;
		$next   = self::clean( $state );

		// Starting over is the one way a watched lesson stops being one.
		if ( empty( $state['reset'] ) && null !== $before ) {
			$next['w'] = array_values( array_unique( array_merge( $before['w'], $next['w'] ) ) );
		}

		$next['w'] = array_slice( $next['w'], 0, self::ITEMS );
		$next['t'] = time();

		unset( $all[ $course ] );
		$all[ $course ] = $next;

		// The oldest out first, once there are too many.
		if ( count( $all ) > self::KEEP ) {
			uasort( $all, static fn( $a, $b ): int => (int) ( $a['t'] ?? 0 ) <=> (int) ( $b['t'] ?? 0 ) );
			$all = array_slice( $all, -self::KEEP, null, true );
		}

		update_user_meta( $user_id, self::META, $all );

		return $next;
	}

	/**
	 * @param array<string, mixed> $state Raw state.
	 * @return array{w: array<int, string>, c: string, p: float, t: int}
	 */
	private static function clean( array $state ): array {
		$watched = array();

		foreach ( is_array( $state['w'] ?? null ) ? $state['w'] : array() as $item ) {
			$item = self::key( (string) $item );

			if ( '' !== $item ) {
				$watched[] = $item;
			}
		}

		return array(
			'w' => array_values( array_unique( $watched ) ),
			'c' => self::key( (string) ( $state['c'] ?? '' ) ),
			'p' => max( 0.0, round( (float) ( $state['p'] ?? 0 ), 1 ) ),
			't' => max( 0, (int) ( $state['t'] ?? 0 ) ),
		);
	}
}
