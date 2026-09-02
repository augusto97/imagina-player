<?php
/**
 * Uninstall leaves nothing behind — including the vault directory.
 *
 * Found on a real site: every protected file had been moved back, and the
 * vault stayed, empty but for its deny rules. This runs the real uninstall
 * script against a real directory tree, then asks the same code what it does
 * with a tree that still holds a file — which is the case that must not be
 * "tidied": the rules that keep that file from being served stay beside it.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Protection\Vault;

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

function make_vault( string $dir, bool $with_stray ): void {
	mkdir( $dir . '/2026/09', 0777, true );
	mkdir( $dir . '/empty', 0777, true );
	file_put_contents( $dir . '/index.php', '<?php // Silence.' );
	file_put_contents( $dir . '/.htaccess', 'Deny from all' );
	file_put_contents( $dir . '/web.config', '<configuration />' );

	if ( $with_stray ) {
		file_put_contents( $dir . '/2026/09/left-behind.mp3', 'not ours to delete' );
	}
}

function tree( string $dir ): array {
	if ( ! is_dir( $dir ) ) {
		return array();
	}

	$out = array();
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );

	foreach ( $it as $path => $info ) {
		$out[] = substr( $path, strlen( $dir ) + 1 );
	}

	sort( $out );

	return $out;
}

$uploads = sys_get_temp_dir() . '/imgp-uninstall-' . getmypid();
$GLOBALS['stub_uploads_dir'] = $uploads;
mkdir( $uploads, 0777, true );

echo '# The real script, against a vault with nothing left in it' . PHP_EOL;

$vault = Vault::base_dir();
make_vault( $vault, false );

check( 'the vault is there before', is_dir( $vault ) && is_file( $vault . '/.htaccess' ) );

define( 'WP_UNINSTALL_PLUGIN', true );
require dirname( __DIR__ ) . '/uninstall.php';

check( 'and gone after', ! is_dir( $vault ), implode( ' ', tree( $vault ) ) );
check( 'the uploads folder itself is untouched', is_dir( $uploads ) );
check( 'the scheduled generation hook was cleared', in_array( 'imagina_player_generate_peaks', (array) ( $GLOBALS['stub_cleared_hooks'] ?? array() ), true ) );

echo PHP_EOL . '# A vault that still holds a file keeps its rules' . PHP_EOL;

make_vault( $vault, true );

$removed = imagina_player_remove_empty_vault( $vault );

check( 'the script says it did not remove it', false === $removed );
check( 'the file is still there', is_file( $vault . '/2026/09/left-behind.mp3' ) );
check( 'so are the deny rules beside it', is_file( $vault . '/.htaccess' ) && is_file( $vault . '/index.php' ) && is_file( $vault . '/web.config' ), implode( ' ', tree( $vault ) ) );
check( 'the folder that was empty is gone', ! is_dir( $vault . '/empty' ) );

// Clean up after ourselves, whatever happened above.
foreach ( array_reverse( tree( $vault ) ) as $entry ) {
	$path = $vault . '/' . $entry;
	is_dir( $path ) ? rmdir( $path ) : unlink( $path );
}
@rmdir( $vault );
@rmdir( $uploads );

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "{$failures} check(s) failed." . PHP_EOL;
	exit( 1 );
}
echo 'All uninstall checks passed.' . PHP_EOL;
