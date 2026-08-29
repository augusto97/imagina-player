<?php
/**
 * Packaging: build the release ZIP and boot the plugin from it.
 *
 * A file left out of the archive is invisible until a client installs it, so the
 * archive is built here, extracted to a temporary directory, and booted with
 * nothing but its own contents on the include path.
 */

require __DIR__ . '/bootstrap.php';

$root = dirname( __DIR__ );

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

// `zip` is not always installed; skipping is better than a false failure.
exec( 'command -v zip', $found, $status );

if ( 0 !== $status ) {
	echo 'SKIP  zip is not installed; packaging not checked' . PHP_EOL;
	exit( 0 );
}

$workdir = sys_get_temp_dir() . '/imgp-package-' . getmypid();
mkdir( $workdir, 0777, true );

exec( escapeshellcmd( $root . '/bin/build-zip.sh' ) . ' ' . escapeshellarg( $workdir ) . ' 2>&1', $output, $build_status );

check( 'the build script succeeds', 0 === $build_status, implode( ' / ', $output ) );

$archives = glob( $workdir . '/imagina-player-*.zip' );

check( 'an archive was produced', ! empty( $archives ) );

if ( empty( $archives ) ) {
	exec( 'rm -rf ' . escapeshellarg( $workdir ) );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

$archive = $archives[0];

check(
	'the archive is named for the shipped version',
	basename( $archive ) === 'imagina-player-' . ImaginaPlayer\VERSION . '.zip',
	basename( $archive )
);

exec( 'unzip -q ' . escapeshellarg( $archive ) . ' -d ' . escapeshellarg( $workdir . '/extracted' ) );

$plugin = $workdir . '/extracted/imagina-player/';

check( 'the archive holds one plugin folder', is_dir( $plugin ) );

/*
 * Translations are a runtime asset like any other, and one whose absence is
 * silent: the plugin installs, runs and stays in English. The archive shipped
 * without a `languages/` folder at all until this was checked.
 */
check(
	'the archive carries the compiled Spanish',
	is_readable( $plugin . 'languages/imagina-player-es_ES.mo' )
);

check(
	'and the template a new language starts from',
	is_readable( $plugin . 'languages/imagina-player.pot' )
);

check(
	'and the editor\'s own catalogue, which is a separate file',
	is_readable( $plugin . 'languages/imagina-player-es_ES-' . md5( 'build/editor.js' ) . '.json' )
);

check(
	'without the translator\'s working copy, which WordPress never reads',
	array() === glob( $plugin . 'languages/*.po' ),
	implode( ', ', array_map( 'basename', (array) glob( $plugin . 'languages/*.po' ) ) )
);

// Verified in its own process: this one has the repository's autoloader
// registered, and it would happily satisfy any class the archive forgot.
$verify = array();
exec(
	escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/package-verify.php' ) . ' ' . escapeshellarg( $plugin ) . ' 2>&1',
	$verify,
	$verify_status
);

foreach ( $verify as $line ) {
	echo $line . PHP_EOL;
}

if ( 0 !== $verify_status ) {
	$failures++;
}

exec( 'rm -rf ' . escapeshellarg( $workdir ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );

