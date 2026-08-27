<?php
/**
 * Shared harness bootstrap.
 *
 * Loads the WordPress stubs and the plugin's autoloader, and defines the
 * namespaced constants the bootstrap file would normally define. The version is
 * read from the plugin header rather than repeated here, so the harness can
 * never disagree with what actually ships.
 *
 * Set `$imgp_base_url` before requiring this file to change the plugin URL.
 */

$imgp_plugin_dir = dirname( __DIR__ ) . '/';

require_once $imgp_plugin_dir . 'tests/wp-stubs.php';
require_once $imgp_plugin_dir . 'src/Support/Autoloader.php';

ImaginaPlayer\Support\Autoloader::register( 'ImaginaPlayer', $imgp_plugin_dir . 'src' );

/**
 * Read `Version:` out of the plugin header, the same way WordPress does.
 */
function imgp_test_plugin_version(): string {
	$header = (string) file_get_contents( dirname( __DIR__ ) . '/imagina-player.php' );

	return preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', $header, $matches )
		? trim( $matches[1] )
		: '0.0.0';
}

define( 'ImaginaPlayer\VERSION', imgp_test_plugin_version() );
define( 'ImaginaPlayer\PATH', $imgp_plugin_dir );
define( 'ImaginaPlayer\URL', $imgp_base_url ?? 'https://example.test/wp-content/plugins/imagina-player/' );
