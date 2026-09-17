<?php
/**
 * Decorations over the picture: text, an image, a hotspot, a shortcode.
 *
 * Unlike a call to action they cover nothing and stop nothing. What has to
 * hold: nothing broken is rendered, nothing hostile reaches the page, each
 * sits where it was put, appears at its moment and leaves at its end, and
 * playback carries on underneath.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Player\Layers;
use ImaginaPlayer\Render\PlayerRenderer;

$root = dirname( __DIR__ );

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

echo '# What survives sanitising' . PHP_EOL;

$clean = Layers::sanitize(
	array(
		array( 'type' => 'text', 'title' => 'Capítulo 2', 'text' => 'La parte del medio', 'position' => 'top', 'at' => 10, 'until' => 40 ),
		array( 'type' => 'text', 'title' => '', 'text' => '' ),
		array( 'type' => 'image', 'image' => 'https://cdn.example.com/logo.png', 'width' => 400, 'url' => 'https://example.test/', 'position' => 'nowhere' ),
		array( 'type' => 'image', 'image' => 'javascript:alert(1)' ),
		array( 'type' => 'hotspot', 'x' => 130, 'y' => -5, 'title' => 'La silla', 'url' => 'https://example.test/silla' ),
		array( 'type' => 'hotspot', 'title' => '', 'text' => '' ),
		array( 'type' => 'shortcode', 'shortcode' => '[stub_form id="3"]' ),
		array( 'type' => 'shortcode', 'shortcode' => 'not a shortcode' ),
	)
);

check( 'four decorations survive, four empty ones do not', 4 === count( $clean ), (string) count( $clean ) );
check( 'a decoration appears from the start unless told otherwise', 0 === ( Layers::sanitize( array( array( 'type' => 'text', 'title' => 'x' ) ) )[0]['at'] ?? -1 ) );
check( 'and a call to action still waits for the end', 100 === ( Layers::sanitize( array( array( 'type' => 'cta', 'url' => 'https://e.test' ) ) )[0]['at'] ?? -1 ) );
check( 'the text keeps its place and window', 'top' === $clean[0]['position'] && 10 === $clean[0]['at'] && 40 === $clean[0]['until'] );
check( 'an image wider than the picture is clamped', 100 === $clean[1]['width'], (string) $clean[1]['width'] );
check( 'a place that does not exist falls back to the kind’s own', 'top-right' === $clean[1]['position'], $clean[1]['position'] );
check( 'a hotspot off the picture is pulled back on', 100 === $clean[2]['x'] && 0 === $clean[2]['y'], $clean[2]['x'] . ',' . $clean[2]['y'] );
check( 'a shortcode is kept as written', '[stub_form id="3"]' === $clean[3]['shortcode'], $clean[3]['shortcode'] );
check( 'none of them interrupts', ! Layers::interrupts( $clean ) );
check( 'a bar is the only kind under the picture', array( 'cta', 'email', 'text', 'image', 'hotspot', 'shortcode' ) === Layers::over() );

echo PHP_EOL . '# What reaches the page' . PHP_EOL;

add_shortcode( 'stub_form', static fn() => '<form class="stub-form"><button>Send</button></form>' );

$renderer = new PlayerRenderer();
$file     = 'https://cdn.example.com/clip.mp4';

$html = $renderer->render(
	array(
		'src'    => $file,
		'layers' => array(
			array( 'type' => 'text', 'title' => 'Capítulo <b>2</b>', 'text' => 'La parte del medio', 'position' => 'top', 'url' => 'https://example.test/cap2', 'newTab' => true ),
			array( 'type' => 'image', 'image' => 'https://cdn.example.com/logo.png', 'width' => 30, 'title' => 'Logo' ),
			array( 'type' => 'hotspot', 'x' => 70, 'y' => 40, 'title' => 'La silla', 'text' => 'Ver en la tienda', 'url' => 'https://example.test/silla' ),
			array( 'type' => 'hotspot', 'x' => 20, 'y' => 60, 'title' => 'Sin enlace' ),
			array( 'type' => 'shortcode', 'shortcode' => '[stub_form id="3"]', 'skip' => true ),
		),
	)
);

check( 'the text sits at the top', str_contains( $html, 'imgp__layer--text imgp__layer--at-top' ) );
check( 'as a link, in a new tab', preg_match( '/imgp__layer--text[^>]*>\s*<a class="imgp__layer-link" href="https:\/\/example\.test\/cap2" target="_blank" rel="noopener noreferrer">/', $html ) === 1 );
check( 'with markup in the headline stripped, not printed', str_contains( $html, 'Capítulo 2' ) && ! str_contains( $html, '<b>2' ) );
check( 'the image is sized as a share of the picture', str_contains( $html, 'imgp__layer--image imgp__layer--at-top-right' ) && str_contains( $html, '--imgp-layer-width:30%' ) );
check( 'and carries its alternative text', str_contains( $html, 'alt="Logo"' ) );
check( 'the hotspot is placed by its coordinates', str_contains( $html, '--imgp-x:70%;--imgp-y:40%' ) );
check( 'a hotspot with a link is a link', preg_match( '/imgp__layer--hotspot[^>]*--imgp-x:70%[^>]*>\s*<a class="imgp__layer-link" href="https:\/\/example\.test\/silla"/', $html ) === 1 );
check( 'a hotspot without one is a button that opens its label', preg_match( '/--imgp-x:20%[^>]*>\s*<button type="button" class="imgp__layer-link" aria-expanded="false">/', $html ) === 1 );
check( 'the shortcode ran, and its form is in the page', str_contains( $html, '<div class="imgp__layer-shortcode"><form class="stub-form">' ) );
check( 'a closable decoration gets a close button', preg_match( '/imgp__layer--shortcode.*?imgp__layer-close/s', $html ) === 1 );
check( 'and one that is not, does not', ! preg_match( '/imgp__layer--text.*?imgp__layer-close.*?imgp__layer--image/s', $html ) );
check( 'every one is hidden until its moment', 5 === preg_match_all( '/imgp__layer--decor[^>]*hidden>/', $html ) );
check( 'every one carries its index for the script', 5 === preg_match_all( '/imgp__layer--decor[^>]*data-layer-index="\d"/', $html ) );
check( 'all of them are over the picture, none under it', ! str_contains( $html, 'imgp__layers--under' ) && substr_count( $html, 'class="imgp__layers"' ) === 1 );

$audio = $renderer->render( array( 'src' => 'https://cdn.example.com/talk.mp3', 'layers' => array( array( 'type' => 'text', 'title' => 'Nota' ) ) ) );

check( 'an audio player renders the text too, in the flow', str_contains( $audio, 'imgp__layer--text' ) );

echo PHP_EOL . '# In a browser' . PHP_EOL;

function find_browser(): string {
	$candidates = array_filter(
		array_merge(
			array( (string) getenv( 'CHROMIUM_BIN' ) ),
			glob( '/opt/pw-browsers/chromium-*/chrome-linux/chrome' ) ?: array(),
			glob( '/opt/pw-browsers/chromium_headless_shell-*/chrome-linux/headless_shell' ) ?: array()
		)
	);

	foreach ( $candidates as $candidate ) {
		if ( '' !== $candidate && is_executable( $candidate ) ) {
			return $candidate;
		}
	}

	return '';
}

$browser = find_browser();

if ( '' === $browser ) {
	echo 'SKIP  no Chromium found; the decorations were not driven' . PHP_EOL;
	echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
	exit( $failures ? 1 : 0 );
}

$workdir = $root . '/build/.overlays-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

foreach ( (array) glob( $root . '/build/*.{js,css}', GLOB_BRACE ) as $asset ) {
	copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
}

$markup = $renderer->render(
	array(
		'src'         => $file,
		'aspectRatio' => '16:9',
		'layers'      => array(
			array( 'type' => 'text', 'title' => 'Desde el principio', 'position' => 'top-left' ),
			array( 'type' => 'text', 'title' => 'Un rato', 'at' => 20, 'until' => 40, 'position' => 'bottom-right' ),
			array( 'type' => 'hotspot', 'x' => 50, 'y' => 50, 'title' => 'Toca', 'at' => 30 ),
			array( 'type' => 'image', 'image' => 'https://cdn.example.com/logo.png', 'at' => 50 ),
		),
	)
);

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>html,body{margin:0}#host{width:640px}</style>
</head><body><div id="host">{$markup}</div>
<script>
window.addEventListener('error', function (e) {
	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify({ error: e.message + ' @' + e.lineno });
	document.body.appendChild(pre);
});
window.imaginaPlayer = { restUrl: '', lazyInit: false, maxComputeBytes: 0, assetUrl: './', i18n: {} };
</script>
<script src="./frontend.js"></script>
<script>
var media = document.querySelector('.imgp__media');
var clock = 0;
var paused = true;
Object.defineProperty(media, 'duration', { get: function () { return 200; }, configurable: true });
Object.defineProperty(media, 'currentTime', { get: function () { return clock; }, set: function (v) { clock = v; }, configurable: true });
Object.defineProperty(media, 'paused', { get: function () { return paused; }, configurable: true });
media.play = function () { paused = false; media.dispatchEvent(new Event('play')); return Promise.resolve(); };
media.pause = function () { paused = true; media.dispatchEvent(new Event('pause')); };

function at(seconds) { clock = seconds; media.dispatchEvent(new Event('timeupdate')); }
function visible(i) { var el = document.querySelector('[data-layer-index="' + i + '"]'); return !!el && !el.hidden; }

setTimeout(function () {
	var out = {};
	media.dispatchEvent(new Event('loadedmetadata'));
	media.play();
	at(1);
	out.start = [visible(0), visible(1), visible(2), visible(3)];
	out.playingAtStart = !paused;
	at(50);
	out.middle = [visible(0), visible(1), visible(2), visible(3)];
	out.playingInMiddle = !paused;
	at(90);
	out.later = [visible(0), visible(1), visible(2), visible(3)];
	at(110);
	out.end = [visible(0), visible(1), visible(2), visible(3)];
	out.stillPlaying = !paused;
	// The hotspot without a link opens its label when pressed.
	var spot = document.querySelector('[data-layer-index="2"] button.imgp__layer-link');
	spot.click();
	out.spotOpen = document.querySelector('[data-layer-index="2"]').classList.contains('is-open') && spot.getAttribute('aria-expanded') === 'true';
	spot.click();
	out.spotClosed = !document.querySelector('[data-layer-index="2"]').classList.contains('is-open');
	// Where the text sits.
	var box = document.querySelector('[data-layer-index="0"]').getBoundingClientRect();
	var stage = document.querySelector('.imgp__stage').getBoundingClientRect();
	out.textTopLeft = box.left - stage.left < 30 && box.top - stage.top < 30;
	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify(out);
	document.body.appendChild(pre);
}, 900);
</script>
</body></html>
HTML;

$file_path = $workdir . '/overlays.html';
file_put_contents( $file_path, $page );

$dom = (string) shell_exec(
	sprintf(
		'%s --headless=new --no-sandbox --disable-gpu --window-size=900,900 --virtual-time-budget=9000 --dump-dom %s 2>/dev/null',
		escapeshellarg( $browser ),
		escapeshellarg( 'file://' . $file_path )
	)
);

exec( 'rm -rf ' . escapeshellarg( $workdir ) );

if ( ! preg_match( '/RESULT:(\{.*?\})</s', $dom, $matches ) ) {
	check( 'the page reported', false, 'no result' );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

$r = json_decode( html_entity_decode( $matches[1] ), true );

if ( isset( $r['error'] ) ) {
	check( 'the probe ran without throwing', false, (string) $r['error'] );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

check( 'at the start only the text from the start is up', array( true, false, false, false ) === ( $r['start'] ?? null ), wp_json_encode( $r['start'] ?? null ) );
check( 'and nothing paused', true === ( $r['playingAtStart'] ?? false ) );
check( 'at a quarter the windowed text has joined it', array( true, true, false, false ) === ( $r['middle'] ?? null ), wp_json_encode( $r['middle'] ?? null ) );
check( 'still nothing paused', true === ( $r['playingInMiddle'] ?? false ) );
check( 'at 45% the windowed text has gone and the hotspot has come', array( true, false, true, false ) === ( $r['later'] ?? null ), wp_json_encode( $r['later'] ?? null ) );
check( 'at 55% the image has come, and the rest stay', array( true, false, true, true ) === ( $r['end'] ?? null ), wp_json_encode( $r['end'] ?? null ) );
check( 'playback never stopped', true === ( $r['stillPlaying'] ?? false ) );
check( 'pressing a hotspot with no link opens its label', true === ( $r['spotOpen'] ?? false ) );
check( 'and pressing again closes it', true === ( $r['spotClosed'] ?? false ) );
check( 'the text at the top left is at the top left', true === ( $r['textTopLeft'] ?? false ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
