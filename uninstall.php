<?php
/**
 * Removes everything the plugin stored.
 *
 * Everything, which the first version of this did not: it left the table of
 * captured email addresses — personal data — and, worse, every file that had
 * been moved into the protected vault. The vault directory carries a rule that
 * denies direct access, and with the plugin gone there is nothing left to serve
 * those files through, so they were unreachable by anyone for ever. Moving
 * them back is the first thing done here, before anything that records where
 * they are is deleted.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/*
 * Protected files back into the open, using the plugin's own code while it is
 * still on disk. Done before the options go, because the vault's location is
 * derived from them.
 */
require_once __DIR__ . '/src/Support/Autoloader.php';

ImaginaPlayer\Support\Autoloader::register( 'ImaginaPlayer', __DIR__ . '/src' );

// The main file is not loaded during uninstall, and one default reads this.
if ( ! defined( 'ImaginaPlayer\\VERSION' ) ) {
	define( 'ImaginaPlayer\\VERSION', '0' );
}

if ( class_exists( 'ImaginaPlayer\\Protection\\Vault' ) ) {
	$protected = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_imagina_protected', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- uninstall, runs once.
			'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
		)
	);

	foreach ( $protected as $attachment_id ) {
		ImaginaPlayer\Protection\Vault::unprotect( (int) $attachment_id );
	}
}

/*
 * The vault directory itself, once nothing is left in it. Seen on a real
 * site: every file had been moved back and an empty tree stayed behind, with
 * the deny rules still in it — a folder nobody can open, for nothing. Only
 * the guard files this plugin wrote and empty folders are removed; a file of
 * any other kind, however it got there, stops the removal at that level.
 */
if ( class_exists( 'ImaginaPlayer\\Protection\\Vault' ) ) {
	imagina_player_remove_empty_vault( ImaginaPlayer\Protection\Vault::base_dir() );
}

/**
 * Remove a directory tree that holds nothing but guard files.
 *
 * @param string $dir Absolute path.
 * @return bool Whether the directory is gone.
 */
function imagina_player_remove_empty_vault( string $dir ): bool {
	if ( ! is_dir( $dir ) ) {
		return true;
	}

	$entries = scandir( $dir );

	if ( false === $entries ) {
		return false;
	}

	$empty  = true;
	$guards = array();

	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$path = $dir . DIRECTORY_SEPARATOR . $entry;

		if ( is_dir( $path ) ) {
			if ( ! imagina_player_remove_empty_vault( $path ) ) {
				$empty = false;
			}

			continue;
		}

		if ( in_array( $entry, array( 'index.php', '.htaccess', 'web.config' ), true ) ) {
			$guards[] = $path;

			continue;
		}

		$empty = false;
	}

	/*
	 * The guard files go last, and only when nothing else is left: they are
	 * the rules that keep whatever is in here from being served, and a folder
	 * that still holds a file keeps them.
	 */
	if ( ! $empty ) {
		return false;
	}

	foreach ( $guards as $guard ) {
		wp_delete_file( $guard );
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- own directory, uninstall.
	return @rmdir( $dir );
}
delete_post_meta_by_key( '_imagina_protected' );

// Options, including the ones earlier versions used.
foreach ( array(
	'imagina_player_settings',
	'imagina_player_version',
	'imagina_player_schema',
	'imagina_player_peaks_db_version',
	'imagina_player_leads_db',
) as $option ) {
	delete_option( $option );
}

// Waveforms cached against attachments.
delete_post_meta_by_key( '_imagina_player_peaks' );

// Waveforms cached against external URLs, and the captured addresses.
foreach ( array( 'imagina_player_peaks', 'imagina_player_leads' ) as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- own tables, fixed names.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $table );
}

/*
 * Transients, by prefix. There is no core call for "every transient starting
 * with", and waiting for them to expire leaves rows behind for up to a month.
 */
foreach ( array( 'imgp_vimeo_thumb_', 'imagina_peaks_queued_', 'imagina_peaks_lock_', 'imagina_peaks_running_', 'imgp_lead_' ) as $prefix ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall, runs once.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_' . $prefix ) . '%',
			$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
		)
	);
}

wp_clear_scheduled_hook( 'imagina_player_generate_peaks' );
