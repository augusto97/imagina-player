<?php
/**
 * Addresses people gave, and where they go.
 *
 * A table of its own rather than a custom post type: these are rows, not
 * content. They are never edited, never shown on the front end, never queried
 * by taxonomy, and there can be a great many of them. A CPT would put every one
 * into `wp_posts` alongside the site's actual pages, and every listing query
 * would pay for it.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Leads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LeadRepository {

	private const DB_VERSION_OPTION = 'imagina_player_leads_db';

	private const DB_VERSION = 1;

	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'imagina_player_leads';
	}

	public static function maybe_install(): void {
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
		 * `email` and `list` are unique together, not `email` alone: the same
		 * person can join a course list and a newsletter, and should not have
		 * the second silently dropped because of the first.
		 */
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(190) NOT NULL,
			list varchar(100) NOT NULL DEFAULT '',
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_url varchar(255) NOT NULL DEFAULT '',
			position float NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY email_list (email, list),
			KEY created_at (created_at),
			KEY list (list)
		) {$collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Record an address.
	 *
	 * Submitting the same address twice is not an error and is not reported as
	 * one: the person did what was asked, and whether we already had it is our
	 * business, not theirs.
	 *
	 * @param array{email: string, list: string, source_id: int, source_url: string, position: float} $lead Lead.
	 */
	/**
	 * Seconds into a track that can be written to a FLOAT column.
	 *
	 * @param float $position Whatever arrived.
	 */
	public static function sane_position( float $position ): float {
		if ( ! is_finite( $position ) || $position < 0.0 ) {
			return 0.0;
		}

		return min( $position, (float) DAY_IN_SECONDS );
	}

	public function save( array $lead ): bool {
		global $wpdb;

		$email = sanitize_email( (string) $lead['email'] );

		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- own table, no core API for it.
		$written = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::table_name() . ' (email, list, source_id, source_url, position, created_at)'
				. ' VALUES (%s, %s, %d, %s, %f, %s)'
				// Not INSERT IGNORE, which would swallow every error including
				// the ones worth knowing about. This swallows exactly one.
				. ' ON DUPLICATE KEY UPDATE id = id',
				$email,
				substr( (string) $lead['list'], 0, 100 ),
				(int) $lead['source_id'],
				substr( (string) $lead['source_url'], 0, 255 ),
				// A position of INF is a valid float in PHP and a rejected one
				// in MySQL, which turns a form submission into a 500.
				self::sane_position( (float) $lead['position'] ),
				current_time( 'mysql', true )
			)
		);
		// phpcs:enable

		if ( false === $written ) {
			return false;
		}

		/**
		 * Fires after an address has been captured.
		 *
		 * The hook a CRM integration would use. Nothing in the plugin listens to
		 * it; it exists so that sending these somewhere does not mean editing
		 * this file.
		 *
		 * @param string               $email Address.
		 * @param array<string, mixed> $lead  Full lead.
		 */
		do_action( 'imagina_player_lead_captured', $email, $lead );

		return true;
	}

	/**
	 * A page of leads, newest first.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public function page( int $per_page = 50, int $offset = 0, string $list = '' ): array {
		global $wpdb;

		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = max( 0, $offset );
		$table    = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- own table.
		if ( '' !== $list ) {
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE list = %s ORDER BY id DESC LIMIT %d OFFSET %d", $list, $per_page, $offset ), ARRAY_A );
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE list = %s", $list ) );
		} else {
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}
		// phpcs:enable

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Every list name that has at least one address in it.
	 *
	 * @return array<int, string>
	 */
	public function lists(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- own table.
		$names = $wpdb->get_col( 'SELECT DISTINCT list FROM ' . self::table_name() . ' ORDER BY list ASC' );

		return is_array( $names ) ? array_map( 'strval', $names ) : array();
	}

	public function delete( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own table.
		return (bool) $wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Everything, as CSV rows.
	 *
	 * @return array<int, array<int, string>>
	 */
	public function export( string $list = '' ): array {
		global $wpdb;

		$table = self::table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- own table.
		if ( '' !== $list ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT email, list, source_url, created_at FROM {$table} WHERE list = %s ORDER BY id DESC", $list ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( "SELECT email, list, source_url, created_at FROM {$table} ORDER BY id DESC", ARRAY_A );
		}
		// phpcs:enable

		$out = array( array( 'email', 'list', 'source', 'captured' ) );

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array_map( array( self::class, 'csv_cell' ), array_values( $row ) );
		}

		return $out;
	}

	/**
	 * Neutralise a cell a spreadsheet would treat as a formula.
	 *
	 * An address of `=HYPERLINK(...)` is a real attack on whoever opens the
	 * export, and the person opening it is the site owner.
	 */
	public static function csv_cell( mixed $value ): string {
		$value = (string) $value;

		return '' !== $value && str_contains( "=+-@\t\r", $value[0] ) ? "'" . $value : $value;
	}
}
