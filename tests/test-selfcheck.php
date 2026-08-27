<?php
/**
 * The protection self-check, run against real web servers.
 *
 * The check exists to catch one specific lie: the plugin writes an `.htaccess`
 * into the vault and calls itself protected, while the server never reads it.
 * Testing that with a stubbed HTTP call would reproduce the same lie one level
 * down, so this starts actual servers.
 *
 * PHP's built-in server is a faithful stand-in for the failure case — like
 * nginx, it has never read an `.htaccess` in its life. A router script that
 * refuses the vault path stands in for a correctly configured one.
 */

$plugin = dirname( __DIR__ ) . '/';

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

require $plugin . 'tests/bootstrap.php';

use ImaginaPlayer\Protection\SelfCheck;
use ImaginaPlayer\Protection\Vault;
use ImaginaPlayer\Settings;

/**
 * Start PHP's web server on a free port and wait for it to answer.
 *
 * @return array{0:resource, 1:int, 2:array}
 */
function serve( string $docroot, ?string $router = null ): array {
	$probe = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
	$port  = (int) explode( ':', (string) stream_socket_get_name( $probe, false ) )[1];
	fclose( $probe );

	$command = array( PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot );

	if ( null !== $router ) {
		$command[] = $router;
	}

	// An array command execs php directly; a string would go through a shell,
	// and proc_terminate() would then kill the shell and leave the server up.
	$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $docroot );

	for ( $attempt = 0; $attempt < 50; $attempt++ ) {
		$socket = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.2 );
		if ( $socket ) { fclose( $socket ); break; }
		usleep( 100000 );
	}

	return array( $process, $port, $pipes );
}

function stop( $process, array $pipes ): void {
	foreach ( $pipes as $pipe ) {
		if ( is_resource( $pipe ) ) { fclose( $pipe ); }
	}
	proc_terminate( $process );
	proc_close( $process );
}

/** Pull one check out of a result set. */
function check_by_id( array $result, string $id ): array {
	foreach ( $result['checks'] as $entry ) {
		if ( $id === $entry['id'] ) { return $entry; }
	}
	return array( 'id' => $id, 'status' => 'missing', 'detail' => '', 'label' => '' );
}

// A throwaway uploads tree, served over HTTP below.
$uploads = sys_get_temp_dir() . '/imgp-selfcheck-' . bin2hex( random_bytes( 4 ) );
mkdir( $uploads . '/wp-content/uploads', 0777, true );

$GLOBALS['stub_uploads_dir'] = $uploads . '/wp-content/uploads';

$settings               = Settings::defaults();
$settings['protection'] = array_replace( $settings['protection'], array( 'enabled' => true ) );
update_option( Settings::OPTION_KEY, $settings );
Settings::flush_cache();

echo PHP_EOL . '# A server that ignores .htaccess (the nginx case)' . PHP_EOL;

[ $process, $port, $pipes ] = serve( $uploads );
$GLOBALS['stub_uploads_url'] = "http://127.0.0.1:{$port}/wp-content/uploads";

$result = SelfCheck::run();
$direct = check_by_id( $result, 'direct' );

check(
	'a vault served in the open is reported as a failure',
	'fail' === $direct['status'],
	$direct['status'] . ': ' . $direct['detail']
);
check(
	'and the overall status is a failure, not a warning',
	'fail' === $result['status'],
	$result['status']
);
check(
	'the advice names the server config, not .htaccess',
	str_contains( $direct['detail'], 'configuration' ),
	$direct['detail']
);
check(
	'the decoy file is cleaned up afterwards',
	array() === glob( Vault::base_dir() . '/imagina-selfcheck-*' ),
	implode( ', ', (array) glob( Vault::base_dir() . '/imagina-selfcheck-*' ) )
);
check(
	'the guard files were written even so',
	file_exists( Vault::base_dir() . '/.htaccess' )
);

stop( $process, $pipes );

echo PHP_EOL . '# A server that denies the vault (a correct configuration)' . PHP_EOL;

$router = $uploads . '/router.php';
file_put_contents(
	$router,
	'<?php' . PHP_EOL
	. '$path = parse_url( $_SERVER["REQUEST_URI"], PHP_URL_PATH );' . PHP_EOL
	. 'if ( str_contains( $path, "imagina-protected-" ) ) { http_response_code( 403 ); echo "Forbidden"; return true; }' . PHP_EOL
	. 'return false;' . PHP_EOL
);

[ $process, $port, $pipes ] = serve( $uploads, $router );
$GLOBALS['stub_uploads_url'] = "http://127.0.0.1:{$port}/wp-content/uploads";

$result = SelfCheck::run();
$direct = check_by_id( $result, 'direct' );

check(
	'a denied vault passes',
	'pass' === $direct['status'],
	$direct['status'] . ': ' . $direct['detail']
);
check(
	'the listing check passes too',
	'pass' === check_by_id( $result, 'listing' )['status'],
	check_by_id( $result, 'listing' )['detail']
);
check(
	'nothing is reported as failed',
	'fail' !== $result['status'],
	$result['status'] . ': ' . $result['summary']
);

// The decoy must not survive on the pass path either.
check(
	'no decoy is left behind on a passing run',
	array() === glob( Vault::base_dir() . '/imagina-selfcheck-*' )
);

stop( $process, $pipes );

echo PHP_EOL . '# When the site cannot reach itself' . PHP_EOL;

// Nothing is listening here. The check must not conclude anything from that.
$GLOBALS['stub_uploads_url'] = 'http://127.0.0.1:1/wp-content/uploads';

$result = SelfCheck::run();
$direct = check_by_id( $result, 'direct' );

check(
	'an unreachable site is never reported as protected',
	'pass' !== $direct['status'],
	$direct['status']
);
check(
	'it is reported as unconfirmed, not as broken',
	'warn' === $direct['status'],
	$direct['status'] . ': ' . $direct['detail']
);
check(
	'and the summary says so rather than claiming everything passed',
	str_contains( $result['summary'], 'could not be confirmed' ),
	$result['summary']
);

echo PHP_EOL . '# Token enforcement over HTTP' . PHP_EOL;

// A protected attachment, and the streaming endpoint served for real.
$media = $GLOBALS['stub_uploads_dir'] . '/track.mp3';
file_put_contents( $media, str_repeat( 'imagina', 500 ) );

$GLOBALS['stub_posts'] = array(
	7 => array( 'type' => 'attachment', 'mime' => 'audio/mpeg', 'file' => $media ),
);
$GLOBALS['stub_meta']  = array( 7 => array( Vault::META_PROTECTED => '1' ) );

check(
	'the check finds the protected attachment',
	7 === ( function () {
		$method = new ReflectionMethod( SelfCheck::class, 'a_protected_attachment' );
		$method->setAccessible( true );
		return $method->invoke( null );
	} )()
);

// With no protected media at all, the token checks must report themselves as
// not run — never as passing.
$GLOBALS['stub_meta'] = array();

$result = SelfCheck::run();
$tokens = check_by_id( $result, 'tokens' );

check(
	'with nothing protected, token checks are skipped rather than passed',
	'skip' === $tokens['status'],
	$tokens['status']
);
check(
	'and it says what to do about it',
	str_contains( $tokens['detail'], 'Protect one' ),
	$tokens['detail']
);

echo PHP_EOL . '# Protection switched off' . PHP_EOL;

$settings['protection']['enabled'] = false;
update_option( Settings::OPTION_KEY, $settings );
Settings::flush_cache();

$result = SelfCheck::run();

check(
	'switching protection off is called out, not silently passed',
	'warn' === check_by_id( $result, 'enabled' )['status'],
	check_by_id( $result, 'enabled' )['status']
);

// Clean up the throwaway tree.
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $uploads, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $entry ) {
	$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
}
rmdir( $uploads );

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "{$failures} check(s) failed." . PHP_EOL;
	exit( 1 );
}
echo 'All checks passed.' . PHP_EOL;
