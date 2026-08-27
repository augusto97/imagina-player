<?php
/**
 * Front-end states in a real browser.
 *
 * These two bugs reached a live site and neither is visible from PHP: a player
 * with no waveform kept the "analysing" highlight running for ever, and drew a
 * row of stubs instead of a seek bar. Both are DOM facts, so they are checked
 * in a browser.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Peaks\PeaksRepository;
use ImaginaPlayer\Render\PlayerRenderer;

$root = dirname( __DIR__ );

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

/**
 * Find a Chromium to drive, or nothing.
 */
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

$browser = find_browser();

if ( '' === $browser ) {
	echo 'SKIP  no Chromium found; front-end states not checked' . PHP_EOL;
	exit( 0 );
}

$renderer = new PlayerRenderer();

// Distinct sources on purpose: players sharing a track also share a waveform
// through the in-page cache, which would hide the state under test.
$markup = static fn( string $id ): string => $renderer->render( array(
	'src'    => './' . $id . '.mp3',
	'title'  => $id,
	'artist' => 'Artist',
) );

// One player with a cached waveform, one without: the two states that matter.
$with_peaks = $markup( 'with-peaks' );
$fake_peaks = array();

for ( $i = 0; $i < 400; $i++ ) {
	$fake_peaks[] = 0.2 + 0.8 * abs( sin( $i / 9 ) );
}

$with_peaks = str_replace(
	'data-imagina-player=',
	'data-peaks="' . PeaksRepository::encode( $fake_peaks ) . '" data-imagina-player=',
	$with_peaks
);

$without_peaks = $markup( 'without-peaks' );

$css = (string) file_get_contents( $root . '/build/style-frontend.css' );
$js  = (string) file_get_contents( $root . '/build/frontend.js' );

// No REST endpoint and no reachable media: the worst case, and the one the
// client hit.
$runtime = wp_json_encode(
	array(
		'restUrl'         => '',
		'lazyInit'        => false,
		'maxComputeBytes' => 25 * 1024 * 1024,
		'i18n'            => array(),
	)
);

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>{$css}</style></head><body>
<div id="a">{$with_peaks}</div>
<div id="b">{$without_peaks}</div>
<script>window.imaginaPlayer = {$runtime};</script>
<script>{$js}</script>
<script>
setTimeout(function () {
	function classesOf(scope) {
		var el = document.querySelector(scope + ' .imgp');
		return el ? el.className : '';
	}

	// Count painted pixels on the waveform canvas: proof that something drew.
	function painted(scope) {
		var canvas = document.querySelector(scope + ' .imgp__wave');
		if (!canvas || !canvas.width) { return 0; }
		var data = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
		var count = 0;
		for (var i = 3; i < data.length; i += 4) { if (data[i] > 0) { count++; } }
		return count;
	}

	var result = {
		withPeaks: classesOf('#a'),
		withoutPeaks: classesOf('#b'),
		paintedWith: painted('#a'),
		paintedWithout: painted('#b')
	};

	var out = document.createElement('pre');
	out.id = 'result';
	out.textContent = 'RESULT:' + JSON.stringify(result);
	document.body.appendChild(out);
}, 2500);
</script>
</body></html>
HTML;

$page_file = sys_get_temp_dir() . '/imgp-frontend-' . getmypid() . '.html';
file_put_contents( $page_file, $page );

$command = sprintf(
	'%s --headless --no-sandbox --disable-gpu --virtual-time-budget=9000 --dump-dom %s 2>/dev/null',
	escapeshellarg( $browser ),
	escapeshellarg( 'file://' . $page_file )
);

$dom = (string) shell_exec( $command );

@unlink( $page_file );

if ( ! preg_match( '/RESULT:(\{.*?\})</s', $dom, $matches ) ) {
	echo 'FAIL  the page did not report a result' . PHP_EOL;
	exit( 1 );
}

$result = json_decode( $matches[1], true );

check( 'the browser reported a result', is_array( $result ) );

// Both players must be enhanced at all.
check( 'a player with peaks is enhanced', str_contains( (string) $result['withPeaks'], 'is-enhanced' ), (string) $result['withPeaks'] );
check( 'a player without peaks is enhanced', str_contains( (string) $result['withoutPeaks'], 'is-enhanced' ), (string) $result['withoutPeaks'] );

// The regression: the analysing highlight ran for ever on a file the browser
// could not analyse.
check(
	'the analysing highlight is not left running without peaks',
	! str_contains( (string) $result['withoutPeaks'], 'is-analyzing' ),
	(string) $result['withoutPeaks']
);
check(
	'nor with peaks',
	! str_contains( (string) $result['withPeaks'], 'is-analyzing' ),
	(string) $result['withPeaks']
);

// The other regression: a waveform-less player drew a row of stubs.
check(
	'a player without peaks falls back to a plain bar',
	str_contains( (string) $result['withoutPeaks'], 'imgp--no-peaks' ),
	(string) $result['withoutPeaks']
);
check(
	'a player with peaks does not',
	! str_contains( (string) $result['withPeaks'], 'imgp--no-peaks' ),
	(string) $result['withPeaks']
);

// And the waveform genuinely renders.
check( 'the waveform paints its bars', (int) $result['paintedWith'] > 500, (string) $result['paintedWith'] );
check( 'the fallback paints a bar too', (int) $result['paintedWithout'] > 0, (string) $result['paintedWithout'] );
check(
	'the waveform paints more than the fallback bar',
	(int) $result['paintedWith'] > (int) $result['paintedWithout'],
	$result['paintedWith'] . ' vs ' . $result['paintedWithout']
);

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
