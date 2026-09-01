<?php
/**
 * Storage for pre-computed waveform peaks.
 *
 * Peaks are stored as base64-encoded unsigned bytes (one byte per bar, 0-255)
 * rather than a JSON array of floats: a 400-bar waveform costs ~536 bytes
 * instead of ~2.5 KB, which matters because the payload is inlined into the
 * page for every player.
 *
 * Attachment peaks live in post meta so they are deleted with the attachment and
 * travel with an export. Peaks for external URLs live in a small dedicated table
 * — deliberately not in `wp_options`, which is autoloaded and where the legacy
 * ZoomSounds plugin wrote one row per track.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Peaks;

use ImaginaPlayer\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PeaksRepository {

	public const META_KEY = '_imagina_player_peaks';

	/**
	 * What a stored waveform was measured with.
	 *
	 * 1 — the loudest instant in each bar. Correct arithmetic, and the wrong
	 *     statistic for anything long: every few seconds of somebody talking
	 *     holds a syllable at full volume, so an hour of teaching came out as a
	 *     comb of identical teeth. It looked made up, and somebody reasonably
	 *     asked whether it was.
	 * 2 — loudness across each bar, which is what the ear calls loud because it
	 *     counts the silence between the words as well as the words.
	 *
	 * A waveform stored at an older version is still drawn — an old picture
	 * beats no picture — but the editor counts it as one worth measuring again,
	 * so the offer appears by itself rather than needing somebody to know that
	 * it should be deleted first.
	 */
	public const FORMAT_VERSION = 2;

	/**
	 * 2 added `format_version`, so that a stored waveform says how it was
	 * measured instead of the reader assuming it was measured the current way.
	 */
	public const DB_VERSION = 2;

	public const DB_VERSION_OPTION = 'imagina_player_peaks_db_version';

	public function hooks(): void {
		add_action( 'plugins_loaded', array( $this, 'maybe_install_table' ) );
		add_action( 'imagina_player_generate_peaks', array( $this, 'handle_scheduled_generation' ) );
	}

	/**
	 * Cron target: generate peaks for an attachment out of the request path.
	 *
	 * Rendering a page never blocks on ffmpeg — a player whose peaks are missing
	 * schedules this and draws a flat placeholder until the job lands.
	 */
	public function handle_scheduled_generation( int $attachment_id ): void {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 ) {
			return;
		}

		PeaksGenerator::generate_for_attachment( $attachment_id, $this );
	}

	/**
	 * Queue background generation for an attachment, at most once per hour.
	 */
	public static function schedule_generation( int $attachment_id ): void {
		if ( $attachment_id <= 0 || ! PeaksGenerator::is_available() ) {
			return;
		}

		$flag = 'imagina_peaks_queued_' . $attachment_id;

		if ( get_transient( $flag ) ) {
			return;
		}

		set_transient( $flag, 1, HOUR_IN_SECONDS );

		wp_schedule_single_event( time() + 5, 'imagina_player_generate_peaks', array( $attachment_id ) );
	}

	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'imagina_player_peaks';
	}

	/**
	 * Create or upgrade the remote-URL peaks table.
	 */
	public function maybe_install_table(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) === self::DB_VERSION ) {
			return;
		}

		self::install_table();
	}

	public static function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();

		/*
		 * `format_version` defaults to 1 on purpose. Every row that already
		 * exists was written before there was a column to write it in, and
		 * every one of those was measured the old way — so the default is the
		 * truth about them rather than a placeholder.
		 */
		$sql = "CREATE TABLE {$table} (
			peaks_key varchar(64) NOT NULL,
			format_version tinyint unsigned NOT NULL DEFAULT 1,
			resolution smallint unsigned NOT NULL DEFAULT 0,
			duration float NOT NULL DEFAULT 0,
			peaks longtext NOT NULL,
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (peaks_key),
			KEY updated_at (updated_at)
		) {$collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Read stored peaks for a key.
	 *
	 * @return array{version:int, resolution:int, duration:float, peaks:string}|null
	 */
	public function get( string $key ): ?array {
		if ( '' === $key ) {
			return null;
		}

		$attachment_id = self::attachment_id_from_key( $key );

		if ( $attachment_id > 0 ) {
			$stored = get_post_meta( $attachment_id, self::META_KEY, true );

			return is_array( $stored ) ? self::normalize_record( $stored ) : null;
		}

		global $wpdb;

		$cache_key = self::cache_key( $key );
		$cached    = wp_cache_get( $cache_key, 'imagina_player' );

		if ( is_array( $cached ) ) {
			return self::normalize_record( $cached );
		}

		if ( 'miss' === $cached ) {
			return null;
		}

		if ( ! self::table_exists() ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated table, cached below.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT format_version, resolution, duration, peaks FROM ' . self::table_name() . ' WHERE peaks_key = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$key
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			wp_cache_set( $cache_key, 'miss', 'imagina_player', HOUR_IN_SECONDS );

			return null;
		}

		/*
		 * What the row says, not what this version happens to be.
		 *
		 * This used to read `self::FORMAT_VERSION` — the constant, ignoring the
		 * row entirely — so every stored waveform claimed to have been measured
		 * the current way whatever it had actually been measured with. The
		 * moment the measure changed, that made the offer to measure a stale
		 * waveform again impossible to reach: nothing was ever stale. The check
		 * that was supposed to catch it asked `is_current()` about an array
		 * built by hand in the test, and never once stored a waveform and read
		 * it back.
		 *
		 * A row from before the column existed reports 1, which is what it is.
		 */
		$record = array(
			'version'    => max( 1, (int) ( $row['format_version'] ?? 1 ) ),
			'resolution' => (int) $row['resolution'],
			'duration'   => (float) $row['duration'],
			'peaks'      => (string) $row['peaks'],
		);

		wp_cache_set( $cache_key, $record, 'imagina_player', DAY_IN_SECONDS );

		return $record;
	}

	/**
	 * Persist peaks for a key.
	 *
	 * @param string             $key      Peaks key.
	 * @param array<int, float>  $peaks    Normalised amplitudes, 0..1.
	 * @param float              $duration Track duration in seconds.
	 */
	public function save( string $key, array $peaks, float $duration = 0.0 ): bool {
		if ( '' === $key || array() === $peaks ) {
			return false;
		}

		$encoded = self::encode( $peaks );

		$record = array(
			'version'    => self::FORMAT_VERSION,
			'resolution' => count( $peaks ),
			'duration'   => max( 0.0, $duration ),
			'peaks'      => $encoded,
		);

		$attachment_id = self::attachment_id_from_key( $key );

		if ( $attachment_id > 0 ) {
			return (bool) update_post_meta( $attachment_id, self::META_KEY, $record );
		}

		if ( ! self::table_exists() ) {
			self::install_table();
		}

		global $wpdb;

		$row = array(
			'peaks_key'      => $key,
			'format_version' => $record['version'],
			'resolution'     => $record['resolution'],
			'duration'       => $record['duration'],
			'peaks'          => $record['peaks'],
			'updated_at'     => current_time( 'mysql', true ),
		);

		$types = array( '%s', '%d', '%d', '%f', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated table.
		$result = $wpdb->replace( self::table_name(), $row, $types );

		/*
		 * A write that failed is worth one look at the table before giving up.
		 *
		 * The failure this catches is a column the code knows about and the
		 * table does not — which happened, because a column was added and
		 * nothing ran the migration on a site updated by uploading the plugin.
		 * Every write failed silently from then on: the editor drew the
		 * waveform it had just measured and looked perfectly correct, while
		 * nothing was ever stored and every visitor downloaded the whole file
		 * to work it out again.
		 *
		 * Once, not in a loop — if the table cannot be brought up to date the
		 * second attempt fails too and the caller is told, which is the honest
		 * outcome.
		 */
		if ( false === $result ) {
			self::install_table();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated table.
			$result = $wpdb->replace( self::table_name(), $row, $types );
		}

		wp_cache_set( self::cache_key( $key ), $record, 'imagina_player', DAY_IN_SECONDS );

		return false !== $result;
	}

	public function delete( string $key ): void {
		$attachment_id = self::attachment_id_from_key( $key );

		if ( $attachment_id > 0 ) {
			delete_post_meta( $attachment_id, self::META_KEY );

			return;
		}

		if ( self::table_exists() ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- dedicated table.
			$wpdb->delete( self::table_name(), array( 'peaks_key' => $key ), array( '%s' ) );
		}

		wp_cache_delete( self::cache_key( $key ), 'imagina_player' );
	}

	/**
	 * Where a record is cached.
	 *
	 * One place, because three of them built this string by hand and nothing
	 * made them agree — a read that looks under one key while a write goes to
	 * another is a bug that shows up as data that will not go away.
	 *
	 * The format is part of it, so the records cached before this — each
	 * carrying the version the reader made up rather than the one it was
	 * actually measured at — are not found rather than believed.
	 *
	 * @param string $key Which waveform.
	 */
	private static function cache_key( string $key ): string {
		return 'imagina_peaks_v' . self::FORMAT_VERSION . '_' . $key;
	}

	/**
	 * Encode normalised floats (0..1) as base64 bytes.
	 *
	 * @param array<int, float> $peaks Amplitudes.
	 */
	public static function encode( array $peaks ): string {
		$bytes = '';

		foreach ( $peaks as $peak ) {
			$value  = (int) round( max( 0.0, min( 1.0, (float) $peak ) ) * 255 );
			$bytes .= chr( $value );
		}

		return base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- binary payload, not obfuscation.
	}

	/**
	 * @return array<int, float>
	 */
	public static function decode( string $encoded ): array {
		$bytes = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- binary payload.

		if ( false === $bytes ) {
			return array();
		}

		$out = array();

		for ( $i = 0, $len = strlen( $bytes ); $i < $len; $i++ ) {
			$out[] = (float) ( ord( $bytes[ $i ] ) / 255 );
		}

		return $out;
	}

	/**
	 * Resample an arbitrary-length peaks array to the configured resolution,
	 * taking the maximum of each bucket so transients stay visible.
	 *
	 * @param array<int, float> $peaks      Source amplitudes.
	 * @param int               $resolution Target bar count.
	 * @return array<int, float>
	 */
	public static function resample( array $peaks, int $resolution ): array {
		$count = count( $peaks );

		if ( $count === 0 || $resolution < 1 ) {
			return array();
		}

		if ( $count === $resolution ) {
			return $peaks;
		}

		$out         = array();
		$bucket_size = $count / $resolution;

		for ( $i = 0; $i < $resolution; $i++ ) {
			$start = (int) floor( $i * $bucket_size );
			$end   = (int) min( $count, max( $start + 1, ceil( ( $i + 1 ) * $bucket_size ) ) );

			/*
			 * Combined by energy rather than by the loudest of them, to match
			 * what a window already holds. Taking the largest would undo the
			 * measurement wherever a bar spans more than one window: that bar
			 * would come out as loud as its loudest part, which is the very
			 * saturation the measure was changed to avoid.
			 */
			$energy = 0.0;

			for ( $j = $start; $j < $end; $j++ ) {
				$level   = (float) $peaks[ $j ];
				$energy += $level * $level;
			}

			$out[] = sqrt( $energy / max( 1, $end - $start ) );
		}

		return $out;
	}

	/**
	 * Scale peaks so the loudest bar reaches 1.0.
	 *
	 * @param array<int, float> $peaks Amplitudes.
	 * @return array<int, float>
	 */
	public static function normalize( array $peaks ): array {
		$max = 0.0;

		foreach ( $peaks as $peak ) {
			$max = max( $max, abs( (float) $peak ) );
		}

		if ( $max <= 0.0 ) {
			return $peaks;
		}

		return array_map(
			static fn( $peak ): float => abs( (float) $peak ) / $max,
			$peaks
		);
	}

	/**
	 * Was this measured the way this version measures?
	 *
	 * @param array{version?:int}|null $record What was stored, if anything.
	 */
	public static function is_current( ?array $record ): bool {
		return null !== $record && (int) ( $record['version'] ?? 1 ) >= self::FORMAT_VERSION;
	}

	public static function resolution(): int {
		$peaks_settings = Settings::peaks_settings();

		return max( 32, min( 2000, (int) ( $peaks_settings['resolution'] ?? 400 ) ) );
	}

	public static function attachment_id_from_key( string $key ): int {
		if ( ! str_starts_with( $key, 'att_' ) ) {
			return 0;
		}

		return (int) substr( $key, 4 );
	}

	private static function table_exists(): bool {
		static $exists = null;

		if ( null !== $exists ) {
			return $exists;
		}

		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		$exists = ( $found === $table );

		return $exists;
	}

	/**
	 * @param array<string, mixed> $record Stored record.
	 * @return array{version:int, resolution:int, duration:float, peaks:string}|null
	 */
	private static function normalize_record( array $record ): ?array {
		if ( empty( $record['peaks'] ) || ! is_string( $record['peaks'] ) ) {
			return null;
		}

		return array(
			// Absent means it predates the field, which means the old measure.
			'version'    => max( 1, (int) ( $record['version'] ?? 1 ) ),
			'resolution' => (int) ( $record['resolution'] ?? 0 ),
			'duration'   => (float) ( $record['duration'] ?? 0.0 ),
			'peaks'      => $record['peaks'],
		);
	}
}
