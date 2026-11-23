<?php
/**
 * The play-button-only skin.
 *
 * For a radio station, or a sound a page plays with no player on show: one
 * button that plays and pauses. It only earns its place if it is truly only
 * that — nothing else drawn, nothing fetched before it is pressed — if a
 * screen reader still knows what it plays, and if a station paused for a
 * while picks up where the broadcast is rather than where it was left.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Player\Attributes;
use ImaginaPlayer\Player\Skins;
use ImaginaPlayer\Render\PlayerRenderer;
use ImaginaPlayer\Shortcodes\PlayerShortcode;

$root = dirname( __DIR__ );

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

function find_browser(): string {
	$candidates = array_filter(
		array_merge(
			array( (string) getenv( 'CHROMIUM_BIN' ) ),
			glob( '/opt/pw-browsers/chromium-*/chrome-linux*/chrome' ) ?: array(),
			glob( '/opt/pw-browsers/chromium_headless_shell-*/chrome*linux*/{headless_shell,chrome-headless-shell}', GLOB_BRACE ) ?: array()
		)
	);

	foreach ( $candidates as $candidate ) {
		if ( '' !== $candidate && is_executable( $candidate ) ) {
			return $candidate;
		}
	}

	return '';
}

/** The client config a player was drawn with. */
function config_of( string $html ): array {
	preg_match( '/data-imagina-player="([^"]+)"/', $html, $m );
	return (array) json_decode( html_entity_decode( $m[1] ?? '', ENT_QUOTES ), true );
}

echo '# A skin like the others' . PHP_EOL;

check( 'offered with the audio skins', isset( Skins::all()['button'] ) && isset( Skins::descriptions()['button'] ) );
check( 'with no scrubber and no waveform', ! Skins::has_scrubber( 'button' ) && ! Skins::uses_waveform( 'button' ) );
check( 'and an arrangement of its own', 'button' === Skins::layout( 'button' ) );
check( 'a video asked for it gets a video skin instead', 'theater' === Skins::resolve( 'button', true ) );

echo PHP_EOL . '# What is drawn' . PHP_EOL;

$renderer = new PlayerRenderer();
$station  = 'https://radio.example.com:8000/live';
$html     = $renderer->render( array( 'src' => $station, 'title' => 'Radio Imagina en directo', 'artist' => 'Radio Imagina', 'thumbnail' => 'https://example.com/cover.jpg', 'skin' => 'button' ) );

check( 'a stream address with no extension is a player', str_contains( $html, 'imgp--skin-button' ) && str_contains( $html, 'src="' . $station . '"' ) );
check( 'the button, alone', 1 === substr_count( $html, 'class="imgp__play"' ) && str_contains( $html, 'class="imgp__solo"' ) );

foreach ( array( 'imgp__scrubber', 'imgp__controls', 'imgp__meta', 'imgp__thumb', 'imgp__wave', 'imgp__volume', 'imgp__unstick' ) as $part ) {
	check( "no {$part}", ! str_contains( $html, 'class="' . $part ) );
}

preg_match( '/aria-describedby="([^"]+)"/', $html, $described );
check( 'a screen reader hears what it plays', isset( $described[1] ) && str_contains( $html, 'class="imgp__sr" id="' . $described[1] . '">Radio Imagina en directo<' ) );
check( 'and the pointer is told it too', str_contains( $html, 'title="Radio Imagina en directo"' ) );
check( 'nothing is fetched until it is pressed', str_contains( $html, 'preload="none"' ) );

$config = config_of( $html );
check( 'no position to come back to, and no bar to float', false === $config['remember'] && false === $config['sticky'] );
check( 'and no waveform asked for', '' === $config['peaksKey'] );
check( 'medium and at the start, unless told otherwise', str_contains( $html, 'imgp--button-medium imgp--button-start' ) );

$large = $renderer->render( array( 'src' => $station, 'title' => 'Radio', 'skin' => 'button', 'buttonSize' => 'large', 'buttonAlign' => 'center' ) );
check( 'a size and a side of its column', str_contains( $large, 'imgp--button-large imgp--button-center' ) );

$odd = Attributes::sanitize( array( 'buttonSize' => 'huge" onclick="x', 'buttonAlign' => 'left', 'buttonShape' => 'star', 'buttonStyle' => 'neon', 'buttonIconColor' => 'red;background:url(x)', 'buttonLabel' => '<b>Escuchar</b> ' . str_repeat( 'a', 90 ) ) );
check( 'anything else is the default', 'medium' === $odd['buttonSize'] && 'start' === $odd['buttonAlign'] && 'circle' === $odd['buttonShape'] && 'filled' === $odd['buttonStyle'] );
check( 'a colour that is not one is dropped', '' === $odd['buttonIconColor'] );
check( 'words without markup, and a few of them', ! str_contains( $odd['buttonLabel'], '<' ) && 60 === mb_strlen( $odd['buttonLabel'] ) );

echo PHP_EOL . '# Its look' . PHP_EOL;

check( 'a filled circle unless told otherwise', str_contains( $html, 'imgp--button-circle imgp--button-filled' ) && ! str_contains( $html, 'imgp--button-labelled' ) );

$styled = $renderer->render( array( 'src' => $station, 'title' => 'Radio', 'skin' => 'button', 'buttonShape' => 'rounded', 'buttonStyle' => 'outline', 'buttonIconColor' => '#facc15', 'accent' => '#dc2626', 'buttonLabel' => 'Escuchar en vivo' ) );
check( 'a shape and a style', str_contains( $styled, 'imgp--button-rounded imgp--button-outline' ) );
check( 'the icon’s own colour', str_contains( $styled, '--imgp-button-icon:#facc15' ) );
check( 'the button’s colour is the accent, as on every player', str_contains( $styled, '--imgp-accent:#dc2626' ) );
check( 'words beside the icon', str_contains( $styled, 'imgp--button-labelled' ) && str_contains( $styled, '<span class="imgp__play-label">Escuchar en vivo</span>' ) );
check( 'which name the button themselves: no "Play" laid over them', ! preg_match( '/class="imgp__play"[^>]*aria-label=/s', $styled ) && str_contains( $styled, 'aria-pressed="false"' ) );
check( 'with no words, it is still called "Play"', (bool) preg_match( '/class="imgp__play"\s+aria-label="Play"/s', $html ) );

$untitled = $renderer->render( array( 'src' => 'https://radio.example.com/emisora-central.mp3', 'skin' => 'button' ) );
preg_match( '/aria-describedby="([^"]+)"/', $untitled, $named );
check( 'with no title, the name comes from the address, as on any player', isset( $named[1] ) && str_contains( $untitled, 'id="' . $named[1] . '">Emisora central<' ) );

$wave = $renderer->render( array( 'src' => 'https://cdn.example.com/a.mp3', 'title' => 'Episodio' ) );
check( 'the other skins are as they were', ! str_contains( $wave, 'imgp--button-' ) && ! str_contains( $wave, 'aria-describedby' ) && ! str_contains( $wave, 'imgp__solo' ) );

$short = $renderer->render( PlayerShortcode::attributes( array( 'src' => $station, 'title' => 'Radio', 'skin' => 'button', 'button_size' => 'small', 'button_align' => 'end' ) ) );
check( 'the shortcode takes it, with its size and side', str_contains( $short, 'imgp--skin-button' ) && str_contains( $short, 'imgp--button-small imgp--button-end' ) );

$css = (string) file_get_contents( $root . '/build/style-frontend.css' );
check( 'a lone button is no width container, so a box with no width does not crush it', (bool) preg_match( '/\.imgp\.imgp--skin-button\{container-type:normal\}/', $css ) );
check( 'its hover only where there is a pointer to hover with, so a tap leaves none stuck on', (bool) preg_match( '/@media\s*\(hover:\s*hover\)\{\.imgp\.imgp--skin-button \.imgp__play:hover\{/', $css ) );

// The block's colour swatches on this skin name the button's fill as such.
$edit = (string) file_get_contents( $root . '/assets/src/editor/edit.tsx' );
check( 'the block editor offers "Button background" and "Icon colour"', (bool) preg_match( "/'accent',\s*__\(\s*'Button background'/", $edit ) && (bool) preg_match( "/'buttonIconColor',\s*__\(\s*'Icon colour'/", $edit ) );

echo PHP_EOL . '# In a browser' . PHP_EOL;

$browser = find_browser();

if ( '' === $browser ) {
	echo 'SKIP  no Chromium found' . PHP_EOL;
	echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
	exit( $failures ? 1 : 0 );
}

$workdir = $root . '/build/.button-skin-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

foreach ( (array) glob( $root . '/build/*.{js,css}', GLOB_BRACE ) as $asset ) {
	copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
}

$players = array(
	'pill'    => $renderer->render( array( 'src' => $station, 'title' => 'Radio', 'skin' => 'button', 'accent' => '#dc2626', 'buttonLabel' => 'Escuchar en vivo' ) ),
	'outline' => $renderer->render( array( 'src' => $station, 'title' => 'Radio', 'skin' => 'button', 'accent' => '#7c3aed', 'buttonStyle' => 'outline', 'buttonShape' => 'rounded' ) ),
	'square'  => $renderer->render( array( 'src' => $station, 'title' => 'Radio', 'skin' => 'button', 'accent' => '#facc15', 'buttonIconColor' => '#111111', 'buttonShape' => 'square' ) ),
	'small'  => $renderer->render( array( 'src' => $station, 'title' => 'Radio', 'skin' => 'button', 'buttonSize' => 'small' ) ),
	'medium' => $renderer->render( array( 'src' => $station, 'title' => 'Radio', 'skin' => 'button', 'buttonAlign' => 'center' ) ),
	'large'  => $renderer->render( array( 'src' => 'https://cdn.example.com/episodio.mp3', 'title' => 'Episodio', 'skin' => 'button', 'buttonSize' => 'large', 'buttonAlign' => 'end' ) ),
);

$sections = '';
foreach ( $players as $name => $player ) {
	$sections .= '<section class="w" id="' . $name . '">' . $player . '</section>';
}

/*
 * Boxes with no width of their own: Elementor's "Position: Fixed" in a
 * corner, a fixed pill, an item of a flex row. A player is a container for
 * its own width queries, which made it zero wide in each.
 */
$sections .= '<section id="corner" style="position:fixed;right:24px;bottom:24px">' . $renderer->render( array( 'src' => $station, 'title' => 'Radio', 'skin' => 'button', 'accent' => '#facc15', 'buttonShape' => 'square' ) ) . '</section>'
	. '<section id="floatpill" style="position:fixed;left:24px;bottom:24px">' . $renderer->render( array( 'src' => $station, 'title' => 'Radio', 'skin' => 'button', 'accent' => '#dc2626', 'buttonLabel' => 'Escuchar en vivo' ) ) . '</section>'
	. '<section id="row" style="display:flex;align-items:center;gap:12px;width:600px"><span>Radio</span><span style="flex:1"></span>' . $renderer->render( array( 'src' => $station, 'title' => 'Radio', 'skin' => 'button', 'buttonSize' => 'small' ) ) . '<span>En vivo</span></section>';

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>html,body{margin:0;font:16px/1.5 sans-serif}.w{width:600px;margin:0 0 20px}
/* What a block theme does: a ring on whatever has focus, a click included. */
:where(body *:focus){outline:2px solid #f0f}</style>
</head><body>
<script>
window.addEventListener('error', function (e) {
	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify({ error: e.message + ' @' + e.lineno });
	document.body.appendChild(pre);
});
document.addEventListener('error', function (e) {
	if (e.target instanceof HTMLMediaElement) { e.stopImmediatePropagation(); }
}, true);
// A stand-in with a clock this test owns: the station is endless, as one is.
window.loads = {};
(function () {
	var state = new WeakMap();
	function st(el) {
		var s = state.get(el);
		if (!s) { s = { t: 0, p: true, d: /radio/.test(el.getAttribute('src') || '') ? Infinity : 95 }; state.set(el, s); }
		return s;
	}
	var P = HTMLMediaElement.prototype;
	var src = Object.getOwnPropertyDescriptor(P, 'src');
	Object.defineProperty(P, 'src', {
		get: function () { return src.get.call(this); },
		set: function (v) { var id = this.closest('section').id; window.loads[id] = (window.loads[id] || 0) + 1; st(this).t = 0; src.set.call(this, v); },
		configurable: true
	});
	Object.defineProperty(P, 'duration', { get: function () { return st(this).d; }, configurable: true });
	Object.defineProperty(P, 'currentTime', { get: function () { return st(this).t; }, set: function (v) { st(this).t = v; }, configurable: true });
	Object.defineProperty(P, 'paused', { get: function () { return st(this).p; }, configurable: true });
	Object.defineProperty(P, 'readyState', { get: function () { return 4; }, configurable: true });
	P.play = function () { var s = st(this); s.p = false; this.dispatchEvent(new Event('play')); return Promise.resolve(); };
	P.pause = function () { var s = st(this); s.p = true; this.dispatchEvent(new Event('pause')); };
	P.load = function () {};
})();
window.imaginaPlayer = { restUrl: '', lazyInit: false, maxComputeBytes: 0, assetUrl: './', i18n: { play: 'Play', pause: 'Pause' } };
</script>
{$sections}
<script src="./frontend.js"></script>
<script>
function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
function box(el) { var r = el.getBoundingClientRect(); return { l: Math.round(r.left), r: Math.round(r.right), w: Math.round(r.width) }; }
(async function () {
	await wait(800);
	var out = {};
	['small', 'medium', 'large'].forEach(function (name) {
		var button = document.querySelector('#' + name + ' .imgp__play');
		out[name] = { box: box(button), column: box(document.querySelector('#' + name)) };
	});

	// Its look, as drawn.
	var look = function (name) {
		var b = document.querySelector('#' + name + ' .imgp__play');
		var cs = getComputedStyle(b);
		return { w: Math.round(b.getBoundingClientRect().width), h: Math.round(b.getBoundingClientRect().height), radius: cs.borderTopLeftRadius, bg: cs.backgroundColor, border: cs.borderTopWidth + ' ' + cs.borderTopColor, color: cs.color };
	};
	out.pill = look('pill');
	out.outline = look('outline');
	out.square = look('square');
	out.corner = look('corner');
	out.floatpill = look('floatpill');
	out.row = look('row');
	var pill = document.querySelector('#pill .imgp__play');
	pill.click();
	await wait(50);
	out.pillNamed = { label: pill.getAttribute('aria-label'), pressed: pill.getAttribute('aria-pressed'), text: pill.textContent.trim() };
	pill.click();

	// Pressed: it plays, and says so.
	var station = document.querySelector('#medium .imgp');
	var button = station.querySelector('.imgp__play');
	var media = station.querySelector('audio');
	button.click();
	await wait(50);
	out.playing = { paused: media.paused, label: button.getAttribute('aria-label'), pressed: button.getAttribute('aria-pressed'), cls: station.classList.contains('is-playing') };

	// Connecting: a ring turns round it.
	media.dispatchEvent(new Event('waiting'));
	await wait(50);
	var ring = getComputedStyle(button, '::after');
	out.ring = { content: ring.content, animation: ring.animationName, opacity: getComputedStyle(button).opacity };
	media.dispatchEvent(new Event('playing'));
	await wait(50);
	out.ringGone = getComputedStyle(button, '::after').content;

	// Paused a while, pressed again: the station is asked for afresh.
	media.currentTime = 120;
	media.dispatchEvent(new Event('timeupdate'));
	button.click();
	await wait(50);
	out.pausedNow = media.paused;
	var before = window.loads.medium || 0;
	button.click();
	await wait(50);
	out.rejoined = { loads: (window.loads.medium || 0) - before, t: media.currentTime, paused: media.paused };

	// A file is a file: it carries on from where it stopped.
	var episode = document.querySelector('#large .imgp');
	var file = episode.querySelector('audio');
	episode.querySelector('.imgp__play').click();
	file.currentTime = 30;
	episode.querySelector('.imgp__play').click();
	var fileBefore = window.loads.large || 0;
	episode.querySelector('.imgp__play').click();
	await wait(50);
	out.file = { loads: (window.loads.large || 0) - fileBefore, t: file.currentTime };

	// A phone does not shrink the size an author chose.
	document.querySelector('#large').style.width = '320px';
	await wait(200);
	out.phoneLarge = box(document.querySelector('#large .imgp__play')).w;

	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify(out);
	document.body.appendChild(pre);
})().catch(function (e) {
	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify({ error: String(e && e.stack || e) });
	document.body.appendChild(pre);
});
</script>
</body></html>
HTML;

$file = $workdir . '/button.html';
file_put_contents( $file, $page );

$runner = $workdir . '/run.js';
file_put_contents(
	$runner,
	<<<'JS'
const { chromium } = require( 'playwright' );
( async () => {
	const browser = await chromium.launch( { executablePath: process.env.CHROMIUM_BIN } );
	const page = await browser.newPage( { viewport: { width: 900, height: 900 } } );
	await page.goto( 'file://' + process.argv[ 2 ] );
	await page.waitForFunction( () => /RESULT:/.test( document.body.innerText ), null, { timeout: 30000 } );
	const out = JSON.parse( ( await page.textContent( 'pre:last-of-type' ) ).replace( /^RESULT:/, '' ) );
	// A real click, then the keyboard: the theme's ring for a click must not
	// show, and the keyboard's own ring must.
	const ring = ( selector ) => page.$eval( selector, ( el ) => ( { style: getComputedStyle( el ).outlineStyle, focused: el.matches( ':focus' ) } ) );
	await page.click( '#square .imgp__play' );
	out.clickRing = await ring( '#square .imgp__play' );
	await page.keyboard.press( 'Tab' );
	out.keyRing = await page.evaluate( () => ( { style: getComputedStyle( document.activeElement ).outlineStyle, visible: document.activeElement.matches( ':focus-visible' ) } ) );
	// Under the pointer.
	const hovered = async ( selector ) => {
		// A button crushed to nothing cannot be hovered: the checks on its size say why.
		await page.hover( selector, { timeout: 3000 } ).catch( () => undefined );
		await page.waitForTimeout( 300 );
		return page.$eval( selector, ( el ) => { const cs = getComputedStyle( el ); return { shadow: cs.boxShadow, transform: cs.transform, bg: cs.backgroundColor, color: cs.color }; } );
	};
	out.hoverFilled = await hovered( '#corner .imgp__play' );
	out.hoverOutline = await hovered( '#outline .imgp__play' );
	await page.mouse.move( 1, 1 );
	await page.waitForTimeout( 300 );
	out.restOutline = await page.$eval( '#outline .imgp__play', ( el ) => ( { shadow: getComputedStyle( el ).boxShadow, bg: getComputedStyle( el ).backgroundColor } ) );
	await page.emulateMedia( { reducedMotion: 'reduce' } );
	out.hoverStill = await hovered( '#corner .imgp__play' );
	console.log( 'RESULTS:' + JSON.stringify( out ) );
	await browser.close();
} )().catch( ( error ) => { console.log( 'RUNNER:' + error.message ); process.exit( 1 ); } );
JS
);

$dom = (string) shell_exec(
	sprintf(
		'CHROMIUM_BIN=%s NODE_PATH=%s node %s %s 2>&1',
		escapeshellarg( $browser ),
		escapeshellarg( $root . '/node_modules' ),
		escapeshellarg( $runner ),
		escapeshellarg( $file )
	)
);

exec( 'rm -rf ' . escapeshellarg( $workdir ) );

$out = preg_match( '/RESULTS:(\{.*\})/s', $dom, $matches ) ? json_decode( $matches[1], true ) : null;

if ( ! is_array( $out ) || isset( $out['error'] ) ) {
	check( 'the page ran', false, trim( $dom ) );
} else {
	check( 'three sizes: 40, 56 and 80 pixels', 40 === $out['small']['box']['w'] && 56 === $out['medium']['box']['w'] && 80 === $out['large']['box']['w'], $out['small']['box']['w'] . ' / ' . $out['medium']['box']['w'] . ' / ' . $out['large']['box']['w'] );
	check( 'at the start of its column', $out['small']['box']['l'] === $out['small']['column']['l'] );
	check( 'in the middle of it', abs( ( $out['medium']['box']['l'] + $out['medium']['box']['r'] ) / 2 - ( $out['medium']['column']['l'] + $out['medium']['column']['r'] ) / 2 ) <= 1 );
	check( 'or at its end', $out['large']['box']['r'] === $out['large']['column']['r'] );
	check( 'pressed, it plays and says it is playing', false === $out['playing']['paused'] && 'Pause' === $out['playing']['label'] && 'true' === $out['playing']['pressed'] && true === $out['playing']['cls'] );
	check( 'while it connects a ring turns round it', '""' === $out['ring']['content'] && 'imgp-spin' === $out['ring']['animation'] && '1' === (string) $out['ring']['opacity'], json_encode( $out['ring'] ) );
	check( 'and goes once the sound starts', 'none' === $out['ringGone'] );
	check( 'pressed again, it pauses', true === $out['pausedNow'] );
	check( 'a station resumed is asked for afresh, at the live edge', 1 === $out['rejoined']['loads'] && 0 === (int) $out['rejoined']['t'] && false === $out['rejoined']['paused'], json_encode( $out['rejoined'] ) );
	check( 'a file carries on from where it stopped', 0 === $out['file']['loads'] && 30 === (int) $out['file']['t'], json_encode( $out['file'] ) );
	check( 'on a phone the size chosen is kept', 80 === $out['phoneLarge'] );
	check( 'with words, a circle becomes a pill in the accent', $out['pill']['w'] > 2 * $out['pill']['h'] && 56 === $out['pill']['h'] && '999px' === $out['pill']['radius'] && 'rgb(220, 38, 38)' === $out['pill']['bg'] && 'rgb(255, 255, 255)' === $out['pill']['color'], json_encode( $out['pill'] ) );
	check( 'an outline: no fill, an edge and an icon in the accent', 'rgba(0, 0, 0, 0)' === $out['outline']['bg'] && '2px rgb(124, 58, 237)' === $out['outline']['border'] && 'rgb(124, 58, 237)' === $out['outline']['color'] && '15.68px' === $out['outline']['radius'], json_encode( $out['outline'] ) );
	check( 'a square, with the icon in its own colour', '0px' === $out['square']['radius'] && 'rgb(250, 204, 21)' === $out['square']['bg'] && 'rgb(17, 17, 17)' === $out['square']['color'], json_encode( $out['square'] ) );
	check( 'pressed, the words still name it, and it says it is pressed', null === $out['pillNamed']['label'] && 'true' === $out['pillNamed']['pressed'] && 'Escuchar en vivo' === $out['pillNamed']['text'], json_encode( $out['pillNamed'] ) );
	check( 'a click leaves no ring, whatever the theme draws on focus', true === $out['clickRing']['focused'] && 'none' === $out['clickRing']['style'], json_encode( $out['clickRing'] ) );
	check( 'while the keyboard keeps its own', true === $out['keyRing']['visible'] && 'solid' === $out['keyRing']['style'], json_encode( $out['keyRing'] ) );
	check( 'fixed in a corner, in a box with no width, it keeps its size and its fill', 56 === $out['corner']['w'] && 56 === $out['corner']['h'] && 'rgb(250, 204, 21)' === $out['corner']['bg'], json_encode( $out['corner'] ) );
	check( 'and so does a fixed pill, words and all', $out['floatpill']['w'] === $out['pill']['w'] && 'rgb(220, 38, 38)' === $out['floatpill']['bg'], json_encode( array( $out['floatpill'], $out['pill'] ) ) );
	check( 'and a button in a row of a flex container', 40 === $out['row']['w'], json_encode( $out['row'] ) );
	check( 'hovered, a halo of its own colour and a little lift', str_contains( $out['hoverFilled']['shadow'], '0px 0px 0px 5.6px' ) && 'matrix(1.06, 0, 0, 1.06, 0, 0)' === $out['hoverFilled']['transform'], json_encode( $out['hoverFilled'] ) );
	check( 'an outline fills in, its icon in the colour that reads on it', 'rgb(124, 58, 237)' === $out['hoverOutline']['bg'] && 'rgb(255, 255, 255)' === $out['hoverOutline']['color'] && 'none' !== $out['hoverOutline']['shadow'], json_encode( $out['hoverOutline'] ) );
	check( 'and empties again when the pointer leaves', 'rgba(0, 0, 0, 0)' === $out['restOutline']['bg'] && 'none' === $out['restOutline']['shadow'], json_encode( $out['restOutline'] ) );
	check( 'with reduced motion, the halo without the lift', 'none' === $out['hoverStill']['transform'] && 'none' !== $out['hoverStill']['shadow'], json_encode( $out['hoverStill'] ) );
}

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
