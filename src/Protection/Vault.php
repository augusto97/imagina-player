<?php
/**
 * Where protected media lives.
 *
 * Protecting a file physically moves it out of the ordinary uploads tree into a
 * directory whose name is derived from the site's salts and which carries deny
 * rules for Apache and IIS. Signing URLs while leaving the original file
 * reachable would protect nothing — anyone who once saw the real URL would keep
 * it forever.
 *
 * The directory name is unguessable on purpose: on nginx, where a stray
 * `.htaccess` does nothing, that is the difference between "needs a server
 * config line" and "wide open". The config line is still the right answer, and
 * the settings screen says so.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Protection;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Vault {

	public const META_PROTECTED = '_imagina_protected';

	/**
	 * Directory name inside `wp-content/uploads`, stable for a given site.
	 */
	public static function directory_name(): string {
		static $name = null;

		if ( null === $name ) {
			$name = 'imagina-protected-' . substr( hash_hmac( 'sha256', 'vault-directory', wp_salt( 'imagina_player_vault' ) ), 0, 12 );
		}

		return $name;
	}

	public static function base_dir(): string {
		$uploads = wp_get_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . self::directory_name();
	}

	/**
	 * The address the vault would answer on if the server were not denying it.
	 *
	 * Only the self-check has a use for this: to prove that address is refused.
	 */
	public static function base_url(): string {
		$uploads = wp_get_upload_dir();

		return trailingslashit( $uploads['baseurl'] ) . self::directory_name();
	}

	/**
	 * Create the directory and drop the deny rules in place.
	 */
	public static function ensure(): bool {
		$dir = self::base_dir();

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$guards = array(
			'.htaccess'  => "# Managed by Imagina Player. Direct access to protected media is denied.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);

		foreach ( $guards as $filename => $contents ) {
			$path = trailingslashit( $dir ) . $filename;

			if ( ! file_exists( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing our own guard files.
				file_put_contents( $path, $contents );
			}
		}

		return true;
	}

	public static function is_protected( int $attachment_id ): bool {
		if ( $attachment_id <= 0 ) {
			return false;
		}

		return '1' === (string) get_post_meta( $attachment_id, self::META_PROTECTED, true );
	}

	/**
	 * Only media that is actually played needs this, and moving anything with
	 * generated sizes (images) would strand those sizes.
	 */
	public static function is_eligible( int $attachment_id ): bool {
		$mime = (string) get_post_mime_type( $attachment_id );

		return str_starts_with( $mime, 'audio/' ) || str_starts_with( $mime, 'video/' );
	}

	/**
	 * Move an attachment into the vault.
	 *
	 * @return true|WP_Error
	 */
	public static function protect( int $attachment_id ) {
		if ( self::is_protected( $attachment_id ) ) {
			return true;
		}

		if ( ! self::is_eligible( $attachment_id ) ) {
			return new WP_Error(
				'imagina_player_not_eligible',
				__( 'Only audio and video files can be protected.', 'imagina-player' )
			);
		}

		if ( ! self::ensure() ) {
			return new WP_Error(
				'imagina_player_vault_unwritable',
				__( 'The protected media directory could not be created.', 'imagina-player' )
			);
		}

		$relative = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$source   = get_attached_file( $attachment_id );

		if ( '' === $relative || ! is_string( $source ) || ! file_exists( $source ) ) {
			return new WP_Error(
				'imagina_player_missing_file',
				__( 'The file for this attachment could not be found.', 'imagina-player' )
			);
		}

		$target_relative = self::directory_name() . '/' . ltrim( $relative, '/' );
		$uploads         = wp_get_upload_dir();
		$target          = trailingslashit( $uploads['basedir'] ) . $target_relative;

		if ( ! wp_mkdir_p( dirname( $target ) ) ) {
			return new WP_Error(
				'imagina_player_vault_unwritable',
				__( 'The protected media directory could not be created.', 'imagina-player' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- moving within the uploads directory.
		if ( ! @rename( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- failure is handled.
			return new WP_Error(
				'imagina_player_move_failed',
				__( 'The file could not be moved into the protected directory.', 'imagina-player' )
			);
		}

		self::repoint( $attachment_id, $target_relative );
		update_post_meta( $attachment_id, self::META_PROTECTED, '1' );

		/**
		 * Fires after an attachment has been moved into the protected vault.
		 *
		 * @param int $attachment_id Attachment ID.
		 */
		do_action( 'imagina_player_media_protected', $attachment_id );

		return true;
	}

	/**
	 * Move an attachment back into the ordinary uploads tree.
	 *
	 * @return true|WP_Error
	 */
	public static function unprotect( int $attachment_id ) {
		if ( ! self::is_protected( $attachment_id ) ) {
			return true;
		}

		$relative = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$source   = get_attached_file( $attachment_id );
		$prefix   = self::directory_name() . '/';

		if ( ! str_starts_with( $relative, $prefix ) || ! is_string( $source ) || ! file_exists( $source ) ) {
			// Nothing sane to move back; just clear the flag so the UI is honest.
			delete_post_meta( $attachment_id, self::META_PROTECTED );

			return true;
		}

		$target_relative = substr( $relative, strlen( $prefix ) );
		$uploads         = wp_get_upload_dir();
		$target          = trailingslashit( $uploads['basedir'] ) . $target_relative;

		if ( ! wp_mkdir_p( dirname( $target ) ) ) {
			return new WP_Error(
				'imagina_player_uploads_unwritable',
				__( 'The uploads directory could not be written to.', 'imagina-player' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- moving within the uploads directory.
		if ( ! @rename( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- failure is handled.
			return new WP_Error(
				'imagina_player_move_failed',
				__( 'The file could not be moved back out of the protected directory.', 'imagina-player' )
			);
		}

		self::repoint( $attachment_id, $target_relative );
		delete_post_meta( $attachment_id, self::META_PROTECTED );

		/**
		 * Fires after an attachment has left the protected vault.
		 *
		 * @param int $attachment_id Attachment ID.
		 */
		do_action( 'imagina_player_media_unprotected', $attachment_id );

		return true;
	}

	/**
	 * Point WordPress at the file's new home.
	 *
	 * Both `_wp_attached_file` and the `file` key of the attachment metadata are
	 * consulted by different parts of core, and leaving one behind produces a
	 * file that exists but that `get_attached_file()` cannot find.
	 */
	private static function repoint( int $attachment_id, string $relative ): void {
		update_post_meta( $attachment_id, '_wp_attached_file', $relative );

		$meta = wp_get_attachment_metadata( $attachment_id );

		if ( is_array( $meta ) ) {
			$meta['file'] = $relative;
			wp_update_attachment_metadata( $attachment_id, $meta );
		}
	}

	/**
	 * Whether the web server is one where the bundled deny files actually apply.
	 */
	public static function server_honours_htaccess(): bool {
		$software = strtolower( (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- read-only comparison.

		return str_contains( $software, 'apache' ) || str_contains( $software, 'litespeed' );
	}
}
