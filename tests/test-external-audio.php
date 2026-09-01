<?php
/**
 * Tracks that are not in the media library.
 *
 * The reason this matters here: these clients are moving their audio to
 * streaming providers, so a pasted address is the normal case rather than the
 * exception. And before this, such a track could not get a waveform by any
 * route at all — ffmpeg reads local files, the generate and store endpoints
 * were keyed on an attachment, and the editor's notice filtered out id 0, so
 * there was not even a message to say why.
 *
 * The interesting part is the proxy. A route that fetches a URL on demand is
 * server-side request forgery unless it is fenced in, so most of this file is
 * about what it refuses.
 */

$plugin = dirname( __DIR__ ) . '/';

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

require $plugin . 'tests/bootstrap.php';

use ImaginaPlayer\Media\Track;
use ImaginaPlayer\Rest\PeaksController;

echo PHP_EOL . '# An external track has a place to keep a waveform' . PHP_EOL;

$track = Track::from_attributes(
	array( 'src' => 'https://cdn.example.com/podcast/ep-12.mp3' )
);

check( 'it has a key', '' !== $track->peaks_key() );
check(
	'keyed on the address, since there is no attachment',
	str_starts_with( $track->peaks_key(), 'url_' ),
	$track->peaks_key()
);
check(
	'and the same address gives the same key',
	$track->peaks_key() === Track::from_attributes(
		array( 'src' => 'https://cdn.example.com/podcast/ep-12.mp3' )
	)->peaks_key()
);
check(
	'while a different one does not',
	$track->peaks_key() !== Track::from_attributes(
		array( 'src' => 'https://cdn.example.com/podcast/ep-13.mp3' )
	)->peaks_key()
);

echo PHP_EOL . '# The endpoints accept an address, not only an id' . PHP_EOL;

$controller = (string) file_get_contents( $plugin . 'src/Rest/PeaksController.php' );

check( 'status takes urls', str_contains( $controller, "'urls'" ) );
check(
	'and splits them on newlines, not commas',
	str_contains( $controller, 'explode( "\n", (string) $request->get_param( \'urls\' ) )' ),
	'a URL may legally contain a comma, and splitting on one cuts a signed link in half'
);
check( 'store takes a src', str_contains( $controller, "'src'          => array(" ) );
check(
	'and keys it the same way the renderer will look it up',
	str_contains( $controller, "'url_' . md5( \$src )" ),
	'a mismatch here stores a waveform nothing will ever find'
);
check(
	'storing an external track needs the right to add media',
	str_contains( $controller, "current_user_can( 'upload_files' )" ),
	'there is no post to check rights over, so this is the nearest honest bar'
);

echo PHP_EOL . '# The editor offers it for external tracks too' . PHP_EOL;

$notice = (string) file_get_contents( $plugin . 'assets/src/editor/waveform-notice.tsx' );
$edit   = (string) file_get_contents( $plugin . 'assets/src/editor/edit.tsx' );
$list   = (string) file_get_contents( $plugin . 'assets/src/editor/playlist.tsx' );

check( 'the notice takes addresses', str_contains( $notice, 'urls' ) );
check( 'the block passes its own when there is no attachment', str_contains( $edit, 'urls={' ) );
check( 'and the playlist passes its external items', str_contains( $list, 'urls={' ) );
/*
 * The fallback moved out of this component and into a shared one, because the
 * settings screen needed it too and did not have it — so "Generate missing
 * waveforms" could only ever measure files on this domain. Checked where it
 * lives now, and that this component uses it.
 */
check(
	'the measuring falls back to the proxy',
	str_contains(
		(string) file_get_contents( $plugin . 'assets/src/shared/measure-track.ts' ),
		'measure( proxied( src ),'
	),
	'a direct fetch fails on any host that does not send CORS headers, which is most of them'
);
check(
	'and the notice measures through it',
	str_contains( $notice, 'measureTrack( track.src' )
);
check(
	'and the store call names the address',
	str_contains( $notice, 'src: track.src' )
);

$editor_bundle = (string) file_get_contents( $plugin . 'build/editor.js' );

check( 'the built editor knows the proxy route', str_contains( $editor_bundle, 'peaks/proxy' ) );

echo PHP_EOL . '# What the proxy refuses' . PHP_EOL;

/*
 * These are the shapes that turn a fetching endpoint into a way of reading the
 * inside of somebody's network. WordPress's own validator is what refuses
 * them, so this checks the plugin actually asks it — a proxy that skips this
 * one call is the whole vulnerability.
 */
check(
	'it runs the URL through wp_http_validate_url',
	str_contains( $controller, 'wp_http_validate_url( $src )' ),
	'that is the check that refuses private addresses, loopback and odd ports'
);
check(
	'it fetches with the safe client, so redirects are checked too',
	str_contains( $controller, 'wp_safe_remote_get' ) && str_contains( $controller, 'wp_safe_remote_head' ),
	'the unsafe client would follow a redirect straight to 169.254.169.254'
);
check(
	'redirects are limited',
	str_contains( $controller, "'redirection' => 3" )
);
check( 'it needs the right to add media', str_contains( $controller, "current_user_can( 'upload_files' )" ) );
check(
	'it caps the size',
	str_contains( $controller, 'PROXY_MAX_BYTES' ) && str_contains( $controller, '413' )
);
check(
	'it refuses anything that is not media',
	str_contains( $controller, 'looks_like_media' ) && str_contains( $controller, '415' )
);
check(
	'it sends a content type of its own choosing, not the remote one',
	str_contains( $controller, "header( 'Content-Type: ' . ( str_starts_with( \$type, 'video/' ) ? 'video/mp4' : 'audio/mpeg' ) )" ),
	'passing a remote content type through is how a proxy becomes an XSS'
);
check( 'and nosniff', str_contains( $controller, 'X-Content-Type-Options: nosniff' ) );
check(
	'the refusal says nothing about what is reachable',
	str_contains( $controller, "echo 'No'" ),
	'the reasons name what this server can see, which is not the caller’s business'
);
check(
	'the temp file is removed on every path',
	2 <= substr_count( $controller, '@unlink( $temp )' ),
	'one on the failure path and one on the success path'
);
check(
	'and the body is streamed rather than held in memory',
	str_contains( $controller, 'fread( $handle, 65536 )' ),
	'a 200 MB file read into a string is a 200 MB spike on a shared host'
);

/*
 * Blocking loopback, private ranges and odd schemes is WordPress's own job, in
 * wp_http_validate_url — the check above proves the plugin calls it, and
 * re-testing it here would only test a stub of it.
 *
 * What *is* ours is the content-type gate, so that gets a real test.
 */
$looks_like_media = new ReflectionMethod( PeaksController::class, 'looks_like_media' );
$looks_like_media->setAccessible( true );

$controller_instance = new PeaksController();

$types = array(
	'audio/mpeg'                       => true,
	'audio/mp4; charset=utf-8'         => true,
	'video/mp4'                        => true,
	'application/octet-stream'         => true,
	// Plenty of storage buckets send nothing at all.
	''                                 => true,
	'text/html'                        => false,
	'text/html; charset=utf-8'         => false,
	'application/json'                 => false,
	'image/svg+xml'                    => false,
	'application/x-httpd-php'          => false,
);

foreach ( $types as $type => $expected ) {
	check(
		sprintf(
			'%s is %s',
			'' === $type ? '(no content type)' : $type,
			$expected ? 'accepted' : 'refused'
		),
		$expected === $looks_like_media->invoke( $controller_instance, $type )
	);
}

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "{$failures} check(s) failed." . PHP_EOL;
	exit( 1 );
}
echo 'All checks passed.' . PHP_EOL;
