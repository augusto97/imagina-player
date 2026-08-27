<?php
/**
 * Removes everything the plugin stored.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'imagina_player_settings' );
delete_option( 'imagina_player_version' );
delete_option( 'imagina_player_peaks_db_version' );

// Waveforms cached against attachments.
delete_post_meta_by_key( '_imagina_player_peaks' );

// Waveforms cached against external URLs.
$table = $wpdb->prefix . 'imagina_player_peaks';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS ' . $table );

wp_clear_scheduled_hook( 'imagina_player_generate_peaks' );
