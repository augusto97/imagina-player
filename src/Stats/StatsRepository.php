<?php
/**
 * How players are watched, counted by the day.
 *
 * Counters, not a log: a row says "this video, on this page, on this day,
 * this many plays", never who played it. Nothing that identifies a viewer is
 * written — no address, no cookie, no user — so there is nothing personal to
 * keep, export or erase, and a site that serves a million plays a month
 * stores a few thousand rows, not a million.
 *
 * Every number is added to, never read and written back: two viewers
 * finishing at the same moment both count.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Stats;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StatsRepository {

	public const DB_VERSION = 1;

	private const DB_VERSION_OPTION = 'imagina_player_stats_db';

	/** Where a session's furthest point falls: a hundredth of the length each. */
	public const BUCKETS = 100;

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'imagina_player_stats';
	}

	public static function media_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'imagina_player_stats_media';
	}

	public static function install_table(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();

		/*
		 * metric: plays, seconds, complete, reach (slot = the hundredth a
		 * session got to), click (slot = the layer pressed).
		 */
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
			day date NOT NULL,
			media_key char(12) NOT NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			metric varchar(12) NOT NULL,
			slot smallint(5) unsigned NOT NULL DEFAULT 0,
			value bigint(20) NOT NULL DEFAULT 0,
			PRIMARY KEY  (day,media_key,post_id,metric,slot),
			KEY media (media_key,post_id)
		) {$collate};"
		);

		dbDelta(
			'CREATE TABLE ' . self::media_table() . " (
			media_key char(12) NOT NULL,
			title varchar(200) NOT NULL DEFAULT '',
			duration float NOT NULL DEFAULT 0,
			updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (media_key)
		) {$collate};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Add what one report from a browser says.
	 *
	 * @param array{key: string, post: int, play: int, seconds: float, complete: int, from: int, to: int, clicks: array<int, int>, title: string, duration: float} $report
	 */
	public static function add( array $report ): bool {
		global $wpdb;

		$day   = gmdate( 'Y-m-d' );
		$key   = $report['key'];
		$post  = $report['post'];
		$rows  = array();
		$value = static function ( string $metric, int $slot, int $amount ) use ( &$rows, $day, $key, $post ): void {
			if ( 0 !== $amount ) {
				$rows[] = array( $day, $key, $post, $metric, $slot, $amount );
			}
		};

		$value( 'plays', 0, $report['play'] );
		$value( 'seconds', 0, (int) round( $report['seconds'] ) );
		$value( 'complete', 0, $report['complete'] );

		// A session's mark moves on: out of the hundredth it had reached,
		// into the one it reaches now.
		if ( $report['to'] > $report['from'] ) {
			if ( $report['from'] >= 0 ) {
				$value( 'reach', $report['from'], -1 );
			}

			$value( 'reach', $report['to'], 1 );
		}

		foreach ( $report['clicks'] as $layer => $count ) {
			$value( 'click', (int) $layer, (int) $count );
		}

		if ( array() === $rows ) {
			return true;
		}

		$placeholders = implode( ',', array_fill( 0, count( $rows ), '(%s,%s,%d,%s,%d,%d)' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders -- counters of our own, one statement.
		$done = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::table() . " (day, media_key, post_id, metric, slot, value) VALUES {$placeholders} ON DUPLICATE KEY UPDATE value = value + VALUES(value)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( ...$rows )
			)
		);

		if ( '' !== $report['title'] || $report['duration'] > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a table of our own.
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO ' . self::media_table() . ' (media_key, title, duration, updated) VALUES (%s, %s, %f, %s) ON DUPLICATE KEY UPDATE title = VALUES(title), duration = VALUES(duration), updated = VALUES(updated)', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$key,
					$report['title'],
					$report['duration'],
					gmdate( 'Y-m-d H:i:s' )
				)
			);
		}

		return false !== $done;
	}

	/** The first day of a period that ends today. */
	private static function since( int $days ): string {
		return gmdate( 'Y-m-d', time() - max( 0, $days - 1 ) * DAY_IN_SECONDS );
	}

	/**
	 * Every player watched in a period, with its totals, most played first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function summary( int $days ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a table of our own.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.media_key, s.post_id, s.metric, SUM(s.value) AS total, m.title, m.duration FROM ' . self::table() . ' s LEFT JOIN ' . self::media_table() . " m ON m.media_key = s.media_key WHERE s.day >= %s AND s.metric IN ('plays','seconds','complete','click') GROUP BY s.media_key, s.post_id, s.metric, m.title, m.duration", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::since( $days )
			),
			ARRAY_A
		);

		$out = array();

		foreach ( $rows as $row ) {
			$id = $row['media_key'] . ':' . $row['post_id'];

			$out[ $id ] ??= array(
				'key'      => (string) $row['media_key'],
				'post'     => (int) $row['post_id'],
				'title'    => (string) $row['title'],
				'duration' => (float) $row['duration'],
				'plays'    => 0,
				'seconds'  => 0,
				'complete' => 0,
				'click'    => 0,
			);

			$out[ $id ][ (string) $row['metric'] ] = (int) $row['total'];
		}

		$out = array_values( $out );

		usort( $out, static fn( array $a, array $b ): int => $b['plays'] <=> $a['plays'] );

		return $out;
	}

	/**
	 * Plays by day, for the whole site or one player.
	 *
	 * @return array<string, int> Day => plays, every day of the period present.
	 */
	public static function daily( int $days, string $key = '', int $post = -1 ): array {
		global $wpdb;

		$where = '';
		$args  = array( self::since( $days ) );

		if ( '' !== $key ) {
			$where  = ' AND media_key = %s AND post_id = %d';
			$args[] = $key;
			$args[] = $post;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a table of our own.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT day, SUM(value) AS total FROM ' . self::table() . " WHERE day >= %s AND metric = 'plays'{$where} GROUP BY day", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$args
			),
			ARRAY_A
		);

		$out = array();

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$out[ gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) ] = 0;
		}

		foreach ( $rows as $row ) {
			if ( isset( $out[ (string) $row['day'] ] ) ) {
				$out[ (string) $row['day'] ] = (int) $row['total'];
			}
		}

		return $out;
	}

	/**
	 * One player's audience: how many were still watching at each hundredth,
	 * and which layers were pressed.
	 *
	 * @return array{reach: array<int, int>, retention: array<int, float>, clicks: array<int, int>}
	 */
	public static function detail( string $key, int $post, int $days ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a table of our own.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT metric, slot, SUM(value) AS total FROM ' . self::table() . " WHERE day >= %s AND media_key = %s AND post_id = %d AND metric IN ('reach','click') GROUP BY metric, slot", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::since( $days ),
				$key,
				$post
			),
			ARRAY_A
		);

		$reach  = array_fill( 0, self::BUCKETS, 0 );
		$clicks = array();

		foreach ( $rows as $row ) {
			$slot = (int) $row['slot'];

			if ( 'reach' === $row['metric'] && $slot < self::BUCKETS ) {
				$reach[ $slot ] = max( 0, (int) $row['total'] );
			} elseif ( 'click' === $row['metric'] ) {
				$clicks[ $slot ] = (int) $row['total'];
			}
		}

		return array(
			'reach'     => $reach,
			'retention' => self::retention( $reach ),
			'clicks'    => $clicks,
		);
	}

	/**
	 * Where sessions ended, as how many were still there at each point: the
	 * share of sessions that got at least that far.
	 *
	 * @param array<int, int> $reach Sessions whose furthest point fell in each hundredth.
	 * @return array<int, float> Share, 0 to 1, at each hundredth.
	 */
	public static function retention( array $reach ): array {
		$total = array_sum( $reach );
		$out   = array();
		$left  = $total;

		foreach ( $reach as $bucket => $sessions ) {
			$out[ $bucket ] = $total > 0 ? round( $left / $total, 4 ) : 0.0;
			$left          -= $sessions;
		}

		return $out;
	}

	/** Forget what is older than a site keeps. */
	public static function purge( int $keep_days ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a table of our own.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE day < %s', self::since( $keep_days ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
