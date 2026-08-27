<?php
/**
 * Streaming over real HTTP: range requests, status codes and headers.
 *
 * Runs the stream server under PHP's built-in web server and drives it with
 * actual requests, because the parts most likely to break — 206 responses,
 * Content-Range arithmetic, byte-exact bodies — cannot be checked by calling
 * functions directly.
 */

$plugin = dirname( __DIR__ ) . '/';

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

// A file with recognisable content so byte ranges can be compared exactly.
$media = tempnam( sys_get_temp_dir(), 'imgp' ) . '.mp3';
$body  = '';
for ( $i = 0; $i < 1000; $i++ ) {
	$body .= sprintf( '%04d', $i ); // 4000 bytes, position-encoded.
}
file_put_contents( $media, $body );
$size = strlen( $body );

$docroot = $plugin . 'tests/stream';

// Ask the OS for a free port rather than hoping a fixed one is idle: a stale
// server from an earlier run would otherwise answer these requests and the
// failures would look like bugs in the plugin.
$probe = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
$port  = (int) explode( ':', (string) stream_socket_get_name( $probe, false ) )[1];
fclose( $probe );

$descriptors = array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
$env         = array_merge( $_ENV, getenv(), array( 'IMGP_TEST_MEDIA' => $media ) );

// An array command execs php directly. A string command goes through a shell,
// and proc_terminate() then kills the shell while the server keeps running.
$server = proc_open(
	array( PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot, $docroot . '/index.php' ),
	$descriptors,
	$pipes,
	$docroot,
	$env
);

if ( ! is_resource( $server ) ) {
	echo 'FAIL  could not start the test web server' . PHP_EOL;
	exit( 1 );
}

// Wait for the server to accept connections.
$base = "http://127.0.0.1:{$port}/";
for ( $attempt = 0; $attempt < 50; $attempt++ ) {
	$probe = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.2 );
	if ( $probe ) { fclose( $probe ); break; }
	usleep( 100000 );
}

/**
 * @return array{status:int, headers:array<string,string>, body:string}
 */
function request( string $url, array $headers = array(), string $method = 'GET' ): array {
	$context = stream_context_create( array(
		'http' => array(
			'method'        => $method,
			'header'        => $headers,
			'ignore_errors' => true,
			'timeout'       => 10,
		),
	) );

	$body = @file_get_contents( $url, false, $context );
	$meta = $http_response_header ?? array();

	$status  = 0;
	$parsed  = array();
	foreach ( $meta as $line ) {
		if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $m ) ) {
			$status = (int) $m[1];
			continue;
		}
		$bits = explode( ':', $line, 2 );
		if ( count( $bits ) === 2 ) {
			$parsed[ strtolower( trim( $bits[0] ) ) ] = trim( $bits[1] );
		}
	}

	return array( 'status' => $status, 'headers' => $parsed, 'body' => (string) $body );
}

try {
	// Mint a real signed URL from the plugin itself.
	$minted = request( $base . '?mint=1' );
	$url    = trim( $minted['body'] );
	$url    = str_replace( 'https://example.test/', $base, $url );

	check( 'the plugin mints a signed URL', str_contains( $url, 'imgpt=' ), $url );

	// --- Full request -------------------------------------------------------
	$full = request( $url );
	check( 'full request returns 200', 200 === $full['status'], (string) $full['status'] );
	check( 'full body matches the file byte for byte', $full['body'] === $body );
	check( 'advertises range support', 'bytes' === ( $full['headers']['accept-ranges'] ?? '' ) );
	check( 'sends the right content type', 'audio/mpeg' === ( $full['headers']['content-type'] ?? '' ) );
	check( 'keeps shared caches out', str_contains( $full['headers']['cache-control'] ?? '', 'private' ) );
	check( 'sends nosniff', 'nosniff' === ( $full['headers']['x-content-type-options'] ?? '' ) );

	// --- Range requests -----------------------------------------------------
	$range = request( $url, array( 'Range: bytes=100-199' ) );
	check( 'range request returns 206', 206 === $range['status'], (string) $range['status'] );
	check( 'range body is exactly the requested slice', $range['body'] === substr( $body, 100, 100 ) );
	check(
		'content-range is correct',
		"bytes 100-199/{$size}" === ( $range['headers']['content-range'] ?? '' ),
		$range['headers']['content-range'] ?? 'missing'
	);
	check( 'content-length matches the slice', '100' === ( $range['headers']['content-length'] ?? '' ) );

	$open = request( $url, array( 'Range: bytes=3900-' ) );
	check( 'open-ended range reaches the end', $open['body'] === substr( $body, 3900 ) );
	check( 'open-ended range returns 206', 206 === $open['status'] );

	$suffix = request( $url, array( 'Range: bytes=-50' ) );
	check( 'suffix range returns the tail', $suffix['body'] === substr( $body, -50 ) );

	$unsatisfiable = request( $url, array( 'Range: bytes=99999-' ) );
	check( 'unsatisfiable range returns 416', 416 === $unsatisfiable['status'], (string) $unsatisfiable['status'] );
	check( 'unsatisfiable range reports the size', "bytes */{$size}" === ( $unsatisfiable['headers']['content-range'] ?? '' ) );

	// --- Authorisation ------------------------------------------------------
	$tampered = request( preg_replace( '/imgpt=(.{6})/', 'imgpt=aaaaaa', $url ) ?? '' );
	check( 'a tampered token is refused', 403 === $tampered['status'], (string) $tampered['status'] );

	$naked = request( $base . '?imagina_media=7' );
	check( 'no token is refused', 403 === $naked['status'], (string) $naked['status'] );

	$other = request( str_replace( 'imagina_media=7', 'imagina_media=9', $url ) );
	check( 'a token cannot be reused for another file', in_array( $other['status'], array( 403, 404 ), true ), (string) $other['status'] );

	check( 'the denial body leaks nothing about the file', ! str_contains( $naked['body'], $media ) );

	// --- HEAD ---------------------------------------------------------------
	$head = request( $url, array(), 'HEAD' );
	check( 'HEAD reports the size without a body', '' === $head['body'] && 200 === $head['status'] );
} finally {
	foreach ( $pipes as $pipe ) {
		if ( is_resource( $pipe ) ) { fclose( $pipe ); }
	}
	proc_terminate( $server, 9 );
	proc_close( $server );
	@unlink( $media );
}

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
