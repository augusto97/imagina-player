<?php
/**
 * Release hygiene: one version number, and a plugin header WordPress can read.
 *
 * Version strings live in five files and drift silently — a plugin whose header
 * says one thing and whose `Stable tag` says another is the classic broken
 * release.
 */

require __DIR__ . '/bootstrap.php';

$plugin = dirname( __DIR__ ) . '/';

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

$header = (string) file_get_contents( $plugin . 'imagina-player.php' );

/**
 * Read a header field the way WordPress's get_plugin_data() does.
 */
function header_field( string $header, string $field ): string {
	return preg_match( '/^[\s*#@]*' . preg_quote( $field, '/' ) . ':(.*)$/mi', $header, $m )
		? trim( $m[1] )
		: '';
}

$version = header_field( $header, 'Version' );

check( 'the plugin header declares a version', '' !== $version, $version );
check( 'the version is semver', 1 === preg_match( '/^\d+\.\d+\.\d+$/', $version ), $version );

// Every field WordPress shows on the Plugins screen.
foreach ( array( 'Plugin Name', 'Description', 'Author', 'License', 'Text Domain', 'Requires at least', 'Requires PHP' ) as $field ) {
	check( "header declares {$field}", '' !== header_field( $header, $field ) );
}

check(
	'the text domain matches the plugin folder',
	'imagina-player' === header_field( $header, 'Text Domain' )
);

// The runtime constant must agree with the header: the header is what WordPress
// shows, the constant is what cache-busts the assets.
check(
	'the VERSION constant matches the header',
	1 === preg_match( "/const VERSION\s*=\s*'" . preg_quote( $version, '/' ) . "'/", $header ),
	$version
);

$readme = (string) file_get_contents( $plugin . 'readme.txt' );

check(
	'readme.txt Stable tag matches',
	1 === preg_match( '/^Stable tag:\s*' . preg_quote( $version, '/' ) . '\s*$/m', $readme ),
	$version
);

check(
	'readme.txt has a changelog entry for this version',
	str_contains( $readme, "= {$version} =" ),
	$version
);

check(
	'readme.txt Requires PHP matches the header',
	header_field( $readme, 'Requires PHP' ) === header_field( $header, 'Requires PHP' )
);

$package = json_decode( (string) file_get_contents( $plugin . 'package.json' ), true );

check( 'package.json version matches', ( $package['version'] ?? '' ) === $version, (string) ( $package['version'] ?? '' ) );

$block = json_decode( (string) file_get_contents( $plugin . 'blocks/audio/block.json' ), true );

check( 'block.json version matches', ( $block['version'] ?? '' ) === $version, (string) ( $block['version'] ?? '' ) );
check( 'block.json names the editor script', ! empty( $block['editorScript'] ) );
check( 'block.json names the view script', ! empty( $block['viewScript'] ) );

// The built assets have to exist, or a fresh clone installs a player with no
// player in it.
foreach ( array( 'build/frontend.js', 'build/frontend.asset.php', 'build/editor.js', 'build/editor.asset.php', 'build/style-frontend.css', 'build/editor.css' ) as $asset ) {
	check( "built asset present: {$asset}", is_readable( $plugin . $asset ) );
}

check( 'the admin script ships', is_readable( $plugin . 'assets/admin/settings.js' ) );
check( 'uninstall.php ships', is_readable( $plugin . 'uninstall.php' ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
