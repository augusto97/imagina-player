<?php
/**
 * Boots a plugin directory in isolation and reports on it.
 *
 *   php tests/package-verify.php /path/to/extracted/imagina-player/
 *
 * Run as its own process on purpose: registering the repository's autoloader
 * alongside the archive's would let the repository satisfy every class, and the
 * test would pass with files missing from the archive.
 */

$plugin = rtrim( $argv[1] ?? '', '/' ) . '/';

if ( ! is_dir( $plugin ) ) {
	echo "FAIL  the plugin directory does not exist\n";
	exit( 1 );
}

require __DIR__ . '/wp-stubs.php';

// The archive's autoloader, and only it.
require_once $plugin . 'src/Support/Autoloader.php';
ImaginaPlayer\Support\Autoloader::register( 'ImaginaPlayer', $plugin . 'src' );

$header = (string) file_get_contents( $plugin . 'imagina-player.php' );
preg_match( '/^\s*\*\s*Version:\s*(.+)$/m', $header, $matches );

define( 'ImaginaPlayer\VERSION', trim( $matches[1] ?? '0.0.0' ) );
define( 'ImaginaPlayer\PATH', $plugin );
define( 'ImaginaPlayer\URL', 'https://example.test/wp-content/plugins/imagina-player/' );

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

$classes = array(
	'ImaginaPlayer\Plugin',
	'ImaginaPlayer\Assets',
	'ImaginaPlayer\Settings',
	'ImaginaPlayer\Peaks\PeaksRepository',
	'ImaginaPlayer\Peaks\PeaksGenerator',
	'ImaginaPlayer\Peaks\PeaksToken',
	'ImaginaPlayer\Rest\PeaksController',
	'ImaginaPlayer\Rest\StreamController',
	'ImaginaPlayer\Protection\Vault',
	'ImaginaPlayer\Protection\ProtectedMedia',
	'ImaginaPlayer\Protection\StreamServer',
	'ImaginaPlayer\Protection\Integration',
	'ImaginaPlayer\Blocks\BlockRegistrar',
	'ImaginaPlayer\Shortcodes\PlayerShortcode',
	'ImaginaPlayer\Admin\SettingsPage',
	'ImaginaPlayer\Render\PlayerRenderer',
	'ImaginaPlayer\Render\Icons',
	'ImaginaPlayer\Media\Track',
	'ImaginaPlayer\Player\Attributes',
	'ImaginaPlayer\Player\Config',
	'ImaginaPlayer\Support\Signature',
);

foreach ( $classes as $class ) {
	check( "class resolves from the archive: {$class}", class_exists( $class ) );
}

// Boot every service the way the plugin file does. Reaching the next line at all
// means nothing fatalled on the way.
ImaginaPlayer\Plugin::instance()->boot();
check( 'every service booted without a fatal', true );

$data = ImaginaPlayer\Assets::runtime_data();
check( 'runtime data is produced', isset( $data['restUrl'], $data['i18n'] ) );

$asset = require $plugin . 'build/frontend.asset.php';
check( 'the bundle manifest is readable', is_array( $asset ) && isset( $asset['version'] ) );

$renderer = new ImaginaPlayer\Render\PlayerRenderer();
$html     = $renderer->render( array(
	'src'    => 'https://cdn.example.com/pista.mp3',
	'title'  => 'Test track',
	'artist' => 'Artist',
) );

check( 'a player renders', str_contains( $html, 'data-imagina-player=' ) );
check( 'with its waveform canvas', str_contains( $html, 'imgp__wave' ) );
check( 'and its native fallback', str_contains( $html, '<audio' ) && str_contains( $html, 'controls' ) );

$shortcode = ( new ImaginaPlayer\Shortcodes\PlayerShortcode() )->render( array( 'source' => 'https://cdn.example.com/a.mp3' ) );
check( 'the shortcode renders', str_contains( $shortcode, 'data-imagina-player=' ) );

foreach ( array( 'tests', 'docs', 'assets/src', 'node_modules', 'package.json', 'webpack.config.js', 'tsconfig.json', '.git' ) as $unwanted ) {
	check( "the archive excludes {$unwanted}", ! file_exists( $plugin . $unwanted ) );
}

check( 'no source maps ship', array() === glob( $plugin . 'build/*.map' ) );
check( 'the licence ships', is_readable( $plugin . 'LICENSE' ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
