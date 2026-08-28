<?php
/**
 * Adaptive streaming, and the thing that makes protecting it real.
 *
 * A protected HLS stream is not one file, it is a manifest and a few hundred
 * segments. Signing only the manifest protects nothing at all: the segment URLs
 * are listed inside it in plain text, so anyone who can fetch the manifest has
 * them, unsigned, for as long as they exist.
 *
 * hls.js resolves segment URLs against the manifest but does not carry its
 * query string across, so the signature has to be put back on every request by
 * hand. That is a claim about what the browser actually sends, so it is checked
 * by watching what a real server receives.
 */

$plugin = dirname( __DIR__ ) . '/';

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

require $plugin . 'tests/bootstrap.php';

use ImaginaPlayer\Render\PlayerRenderer;

function find_browser(): string {
	$candidates = array_filter(
		array_merge(
			array( (string) getenv( 'CHROMIUM_BIN' ) ),
			glob( '/opt/pw-browsers/chromium_headless_shell-*/chrome-linux/headless_shell' ) ?: array(),
			glob( '/opt/pw-browsers/chromium-*/chrome-linux/chrome' ) ?: array()
		)
	);

	foreach ( $candidates as $candidate ) {
		if ( '' !== $candidate && is_executable( $candidate ) ) {
			return $candidate;
		}
	}

	foreach ( array( 'chromium', 'chromium-browser', 'google-chrome' ) as $name ) {
		$found = trim( (string) shell_exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null' ) );

		if ( '' !== $found ) {
			return $found;
		}
	}

	return '';
}

echo PHP_EOL . '# What the server renders for a stream' . PHP_EOL;

$renderer = new PlayerRenderer();

$html = $renderer->render(
	array(
		'src'   => 'https://example.test/wp-content/uploads/stream.m3u8?imagina-token=abc',
		'title' => 'En directo',
	)
);

check( 'a manifest is treated as video', str_contains( $html, 'imgp--video' ), substr( $html, 0, 120 ) );
check( 'and flagged as a stream for the client', str_contains( $html, '&quot;hls&quot;:true' ), $html );
check( 'the quality button is rendered', str_contains( $html, 'imgp__vbtn--quality' ) );
check( 'but starts hidden, since one rendition is not a choice', (bool) preg_match( '#imgp__vbtn--quality[^>]*hidden#', $html ) );

$mp4 = $renderer->render( array( 'src' => 'https://example.test/wp-content/uploads/lesson.mp4' ) );

check( 'a plain MP4 is not flagged as a stream', str_contains( $mp4, '&quot;hls&quot;:false' ), 'this is what keeps 400 KB of hls.js off ordinary pages' );

echo PHP_EOL . '# Every segment is signed, not just the manifest' . PHP_EOL;

$browser = find_browser();

if ( '' === $browser ) {
	echo 'SKIP  no browser available; segment signing is not checked' . PHP_EOL;
	echo PHP_EOL . ( $failures ? "{$failures} check(s) failed." . PHP_EOL : 'All checks passed.' . PHP_EOL );
	exit( $failures ? 1 : 0 );
}

$docroot = sys_get_temp_dir() . '/imgp-hls-' . bin2hex( random_bytes( 4 ) );
mkdir( $docroot . '/build', 0777, true );

foreach ( glob( $plugin . 'build/*.{js,css}', GLOB_BRACE ) as $asset ) {
	copy( $asset, $docroot . '/build/' . basename( $asset ) );
}

/*
 * A router that both serves a small HLS stream and writes down every request it
 * receives, query string included. The segments are not real MPEG-TS — hls.js
 * will fail to parse them and give up — but it requests them first, and the
 * request is the whole point.
 */
file_put_contents(
	$docroot . '/router.php',
	'<?php' . PHP_EOL
	. '$path = parse_url( $_SERVER["REQUEST_URI"], PHP_URL_PATH );' . PHP_EOL
	. 'if ( str_starts_with( $path, "/build/" ) || "/index.html" === $path || "/" === $path ) { return false; }' . PHP_EOL
	. 'file_put_contents( __DIR__ . "/requests.log", $_SERVER["REQUEST_URI"] . PHP_EOL, FILE_APPEND );' . PHP_EOL
	. 'if ( "/stream.m3u8" === $path ) {' . PHP_EOL
	. '  header( "Content-Type: application/vnd.apple.mpegurl" );' . PHP_EOL
	. '  echo "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:4\n#EXT-X-MEDIA-SEQUENCE:0\n";' . PHP_EOL
	. '  echo "#EXTINF:4.0,\nseg0.ts\n#EXTINF:4.0,\nseg1.ts\n#EXT-X-ENDLIST\n";' . PHP_EOL
	. '  return true;' . PHP_EOL
	. '}' . PHP_EOL
	. 'header( "Content-Type: video/mp2t" );' . PHP_EOL
	. 'echo str_repeat( "\x47", 188 );' . PHP_EOL
	. 'return true;' . PHP_EOL
);

// The token is in the manifest URL and nowhere else, exactly as a signed link
// from the vault or a Bunny token would be.
$stream_html = str_replace(
	'https://example.test/wp-content/uploads/stream.m3u8?imagina-token=abc',
	'/stream.m3u8?imagina-token=abc&amp;expires=99999',
	$html
);

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="/build/style-frontend.css">
<style>body{margin:0}#host{width:640px}</style>
</head><body>
<div id="host">{$stream_html}</div>
<script>
window.imaginaPlayer = { restUrl: '', lazyInit: false, maxComputeBytes: 0, i18n: {}, assetUrl: '/build/' };
</script>
<script src="/build/frontend.js"></script>
<script>
setTimeout(function () {
	var out = document.createElement('pre');
	out.id = 'result';
	out.textContent = 'RESULT:' + JSON.stringify({
		enhanced: document.querySelector('.imgp').classList.contains('is-enhanced')
	});
	document.body.appendChild(out);
}, 5000);
</script>
</body></html>
HTML;

file_put_contents( $docroot . '/index.html', $page );

$probe = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
$port  = (int) explode( ':', (string) stream_socket_get_name( $probe, false ) )[1];
fclose( $probe );

$server = proc_open(
	array( PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot, $docroot . '/router.php' ),
	array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
	$pipes,
	$docroot
);

for ( $attempt = 0; $attempt < 50; $attempt++ ) {
	$socket = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.2 );
	if ( $socket ) { fclose( $socket ); break; }
	usleep( 100000 );
}

$dom = (string) shell_exec(
	sprintf(
		'%s --headless --no-sandbox --disable-gpu --virtual-time-budget=12000 --dump-dom %s 2>/dev/null',
		escapeshellarg( $browser ),
		escapeshellarg( "http://127.0.0.1:{$port}/" )
	)
);

foreach ( $pipes as $pipe ) {
	if ( is_resource( $pipe ) ) { fclose( $pipe ); }
}
proc_terminate( $server );
proc_close( $server );

$log      = (string) @file_get_contents( $docroot . '/requests.log' );
$requests = array_values( array_filter( array_map( 'trim', explode( "\n", $log ) ) ) );

$manifests = array_values( array_filter( $requests, static fn( string $r ): bool => str_contains( $r, '.m3u8' ) ) );
$segments  = array_values( array_filter( $requests, static fn( string $r ): bool => str_contains( $r, '.ts' ) ) );

check( 'the manifest was fetched', count( $manifests ) > 0, $log );
check(
	'the segments were fetched, so hls.js took over',
	count( $segments ) > 0,
	'without this the signing check below would pass by having nothing to check'
);

$unsigned = array_values(
	array_filter(
		$segments,
		static fn( string $r ): bool => ! str_contains( $r, 'imagina-token=abc' )
	)
);

check(
	'every segment carried the token from the manifest',
	count( $segments ) > 0 && array() === $unsigned,
	array() === $unsigned ? '' : implode( ' | ', $unsigned )
);

check(
	'and the second query parameter too, not only the first',
	count( $segments ) > 0 && count( array_filter( $segments, static fn( string $r ): bool => str_contains( $r, 'expires=99999' ) ) ) === count( $segments ),
	implode( ' | ', $segments )
);

// Clean up.
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $docroot, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $entry ) {
	$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
}
rmdir( $docroot );

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "{$failures} check(s) failed." . PHP_EOL;
	exit( 1 );
}
echo 'All checks passed.' . PHP_EOL;
