<?php
/**
 * Minimal PSR-4 autoloader so the plugin ships without a Composer `vendor/` directory.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Autoloader {

	/**
	 * Map a namespace prefix onto a base directory and register the loader.
	 *
	 * @param string $prefix   Root namespace, e.g. `ImaginaPlayer`.
	 * @param string $base_dir Directory holding that namespace's classes.
	 */
	public static function register( string $prefix, string $base_dir ): void {
		$prefix   = trim( $prefix, '\\' ) . '\\';
		$base_dir = rtrim( $base_dir, '/\\' ) . '/';

		spl_autoload_register(
			static function ( string $class_name ) use ( $prefix, $base_dir ): void {
				if ( ! str_starts_with( $class_name, $prefix ) ) {
					return;
				}

				$relative = substr( $class_name, strlen( $prefix ) );
				$path     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

				if ( is_readable( $path ) ) {
					require_once $path;
				}
			}
		);
	}
}
