<?php
/**
 * The second visual audit, measured in a browser so it stays fixed.
 *
 * "Compact, one line" with a stub of bar ten pixels long between two badges;
 * a card on a phone whose cover kept its desktop size and left the title
 * "Episo…"; a title squeezed to "Episodio 12: C…" by its own controls; a
 * waveform frozen at the width it was first measured, half the width of a
 * player that had grown; a call to action whose only button was an outline;
 * a course's progress in a column beside the video; a faded arrow over the
 * first card of a slider; the playing card boxed in like a selected field.
 *
 * The media element is a stand-in with a clock this test owns.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Player\Config;
use ImaginaPlayer\Render\PlayerRenderer;
use ImaginaPlayer\Render\PlaylistRenderer;

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

echo '# A call to action’s button, from the accent' . PHP_EOL;

$vars = static fn( string $accent ): array => Config::css_variables(
	array(
		'accent'          => $accent,
		'wave_color'      => '#c9ced6',
		'wave_progress'   => $accent,
		'text_color'      => '',
		'meta_color'      => '',
		'control_color'   => '',
		'height'          => 60,
		'wave_reflection' => 0.25,
		'rounded_bars'    => false,
		'border_radius'   => 0,
		'background'      => 'transparent',
	)
);

$factory = $vars( '#1f2937' );
check( 'the factory accent leaves it to the stylesheet', ! isset( $factory['--imgp-cta'] ) && ! isset( $factory['--imgp-on-cta'] ) );

$bright = $vars( '#00c2d8' );
check( 'a bright accent fills the button itself', 'var(--imgp-accent)' === ( $bright['--imgp-cta'] ?? '' ) && 'var(--imgp-on-accent)' === ( $bright['--imgp-on-cta'] ?? '' ) );

$deep = $vars( '#0b3d2e' );
check( 'a dark one, as dark as the panel, swaps with its label', 'var(--imgp-on-accent)' === ( $deep['--imgp-cta'] ?? '' ) && 'var(--imgp-accent)' === ( $deep['--imgp-on-cta'] ?? '' ) );

check( 'the label colour is still worked out the same way', '#111111' === Config::readable_on( '#00c2d8' ) && '#ffffff' === Config::readable_on( '#0b3d2e' ) && '#ffffff' === Config::readable_on( 'rebeccapurple' ) );

echo PHP_EOL . '# In a browser' . PHP_EOL;

$browser = find_browser();

if ( '' === $browser ) {
	echo 'SKIP  no Chromium found' . PHP_EOL;
	echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
	exit( $failures ? 1 : 0 );
}

$workdir = $root . '/build/.visual-polish-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

foreach ( (array) glob( $root . '/build/*.{js,css}', GLOB_BRACE ) as $asset ) {
	copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
}

$renderer = new PlayerRenderer();
$lists    = new PlaylistRenderer();
$cover    = 'https://cdn.example.com/cover.jpg';
$title    = 'Episodio 12: Cómo grabar un pódcast en casa';
$audio    = static fn( array $extra ): string => $renderer->render( array( 'src' => 'https://cdn.example.com/a.mp3', 'title' => $title, 'artist' => 'Radio', 'thumbnail' => $cover ) + $extra );

$players = array(
	'compact' => $audio( array( 'skin' => 'compact' ) ),
	'pill'    => $audio( array( 'skin' => 'pill' ) ),
	'card'    => $audio( array( 'skin' => 'card', 'showSpeed' => 'yes', 'showDownload' => 'yes', 'showSkip' => 'yes' ) ),
	'wave'    => $audio( array( 'skin' => 'wave' ) ),
	'cta'     => $renderer->render(
		array(
			'src'    => 'https://cdn.example.com/v.mp4',
			'title'  => 'Clase',
			'layers' => array( array( 'type' => 'cta', 'at' => 0, 'skip' => true, 'title' => 'Apúntate', 'button' => 'Quiero entrar', 'url' => 'https://example.com' ) ),
		)
	),
	'stacked' => $renderer->render( array( 'src' => 'https://cdn.example.com/v.mp4', 'title' => 'Clase 3: Iluminación', 'skin' => 'stacked' ) ),
	'phone'   => $renderer->render(
		array(
			'src'    => 'https://cdn.example.com/v.mp4',
			'title'  => 'Clase',
			'tracks' => array( array( 'src' => 'https://cdn.example.com/es.vtt', 'srclang' => 'es', 'label' => 'Español' ) ),
		)
	),
);

$items  = array();
for ( $i = 1; $i <= 6; $i++ ) {
	$items[] = array( 'src' => 'https://cdn.example.com/v' . $i . '.mp4', 'title' => 'Lección ' . $i, 'thumbnail' => $cover, 'poster' => $cover );
}
$players['slider'] = $lists->render( array( 'items' => $items, 'layout' => 'slider' ) );
$players['course'] = $lists->render( array( 'items' => $items, 'layout' => 'side', 'course' => true, 'courseKey' => 'curso' ) );

$sections = '';
foreach ( $players as $name => $html ) {
	$sections .= '<section class="w" id="' . $name . '">' . $html . '</section>';
}

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>html,body{margin:0;font:16px/1.5 sans-serif}.w{width:645px;margin:0 0 30px}#course{width:1000px}#phone{width:360px}</style>
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
(function () {
	var state = new WeakMap();
	function st(el) {
		var s = state.get(el);
		if (!s) { s = { t: 0, p: true, d: 95 }; state.set(el, s); }
		return s;
	}
	var P = HTMLMediaElement.prototype;
	Object.defineProperty(P, 'duration', { get: function () { return st(this).d; }, configurable: true });
	Object.defineProperty(P, 'currentTime', { get: function () { return st(this).t; }, set: function (v) { st(this).t = v; }, configurable: true });
	Object.defineProperty(P, 'paused', { get: function () { return st(this).p; }, configurable: true });
	Object.defineProperty(P, 'readyState', { get: function () { return 4; }, configurable: true });
	P.play = function () { var s = st(this); s.p = false; this.dispatchEvent(new Event('play')); return Promise.resolve(); };
	P.pause = function () { var s = st(this); s.p = true; this.dispatchEvent(new Event('pause')); };
	P.load = function () {};
})();
window.imaginaPlayer = { restUrl: '', lazyInit: false, maxComputeBytes: 0, assetUrl: './', i18n: {} };
</script>
{$sections}
<script src="./frontend.js"></script>
<script>
function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
function box(el) { var r = el.getBoundingClientRect(); return { l: r.left, r: r.right, w: r.width, t: r.top, b: r.bottom, h: r.height }; }
(async function () {
	await wait(900);
	var out = {};

	// The one-line skins: the line long enough to aim at, the times beside it.
	['compact', 'pill'].forEach(function (name) {
		var root = document.querySelector('#' + name + ' .imgp');
		var track = box(root.querySelector('.imgp__track'));
		var seek = box(root.querySelector('.imgp__seek'));
		var now = box(root.querySelector('.imgp__time--current'));
		var total = box(root.querySelector('.imgp__time--total'));
		out[name] = { track: Math.round(track.w), player: Math.round(box(root).w), clear: now.r <= track.l + 0.5 && total.l >= track.r - 0.5, seek: Math.abs(seek.l - track.l) < 1 && Math.abs(seek.r - track.r) < 1 };
	});
	var media = document.querySelector('#compact audio');
	media.currentTime = 60;
	media.dispatchEvent(new Event('timeupdate'));
	await wait(50);
	var pinned = box(document.querySelector('#compact .imgp__time--current'));
	out.compactPinned = pinned.r <= box(document.querySelector('#compact .imgp__track')).l + 0.5;

	// The card's title, beside every control there is.
	var card = document.querySelector('#card .imgp');
	out.cardTitle = Math.round(box(card.querySelector('.imgp__title')).w);
	out.cardControlsBelow = box(card.querySelector('.imgp__controls')).t >= box(card.querySelector('.imgp__title')).b - 1;

	// The call to action's button: filled, and lighter than the panel.
	var button = document.querySelector('#cta .imgp__layer-button');
	var cs = getComputedStyle(button);
	out.ctaFill = cs.backgroundColor;
	out.ctaText = cs.color;
	out.ctaBorder = cs.borderTopWidth;

	// The playing card, marked on its picture.
	var current = document.querySelector('#slider .imgp-playlist__item.is-current');
	out.cardRing = getComputedStyle(current.querySelector('.imgp-playlist__cover')).outlineStyle;
	out.cardBox = getComputedStyle(current.querySelector('.imgp-playlist__link')).boxShadow;
	var prev = document.querySelector('#slider .imgp-playlist__nav--prev');
	out.prevArrow = prev ? { disabled: prev.disabled, opacity: getComputedStyle(prev).opacity } : null;

	// A course's progress, across the top of the side layout.
	var progress = document.querySelector('#course .imgp-playlist__progress');
	var player = document.querySelector('#course .imgp');
	out.progress = { width: Math.round(box(progress).w), list: Math.round(box(document.querySelector('#course .imgp-playlist')).w), above: box(progress).b <= box(player).t + 1 };

	// The stacked bar's title, only with room to read it.
	out.stackedTitle = getComputedStyle(document.querySelector('#stacked .imgp__bar .imgp__meta')).display;

	// A waveform that grows with its player.
	var wave = document.querySelector('#wave');
	var canvas = wave.querySelector('canvas');
	wave.style.width = '360px';
	await wait(300);
	var narrow = Math.round(box(canvas).w);
	wave.style.width = '645px';
	await wait(300);
	out.wave = { narrow: narrow, wide: Math.round(box(canvas).w), pixels: canvas.width };

	// Paused part-way with subtitles on, on a phone-sized picture: the disc
	// steps aside for the line being read, and the picture still answers.
	var phone = document.querySelector('#phone .imgp');
	var big = phone.querySelector('.imgp__bigplay');
	var disc = function () { return getComputedStyle(big, '::before').opacity; };
	out.discBefore = disc();
	phone.classList.add('is-started');
	var cc = phone.querySelector('.imgp__vbtn--captions');
	if (cc) { cc.classList.add('is-active'); }
	await wait(50);
	out.discReading = disc();
	out.tapTarget = Math.round(box(big).w) + 'x' + Math.round(box(big).h);
	big.click();
	await wait(50);
	out.resumed = !phone.querySelector('video').paused;
	document.querySelector('#phone').style.width = '645px';
	phone.querySelector('video').pause();
	await wait(300);
	out.discWide = disc();

	// On a phone: the card's cover shrinks and the title takes two lines.
	document.querySelector('#card').style.width = '360px';
	await wait(300);
	out.phoneCover = Math.round(box(card.querySelector('.imgp__thumb img')).w);
	var phoneTitle = card.querySelector('.imgp__title');
	out.phoneTitleLines = Math.round(box(phoneTitle).h / parseFloat(getComputedStyle(phoneTitle).lineHeight));

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

$file = $workdir . '/polish.html';
file_put_contents( $file, $page );

$runner = $workdir . '/run.js';
file_put_contents(
	$runner,
	<<<'JS'
const { chromium } = require( 'playwright' );
( async () => {
	const browser = await chromium.launch( { executablePath: process.env.CHROMIUM_BIN } );
	const page = await browser.newPage( { viewport: { width: 1100, height: 900 } } );
	await page.goto( 'file://' + process.argv[ 2 ] );
	await page.waitForFunction( () => /RESULT:/.test( document.body.innerText ), null, { timeout: 30000 } );
	console.log( 'RESULTS:' + ( await page.textContent( 'pre:last-of-type' ) ).replace( /^RESULT:/, '' ) );
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
	foreach ( array( 'compact', 'pill' ) as $name ) {
		$one = $out[ $name ];
		check( "{$name}: a line long enough to aim at", $one['track'] >= 0.15 * $one['player'], $one['track'] . ' of ' . $one['player'] );
		check( "{$name}: the times either side of it, not over it", true === $one['clear'] );
		check( "{$name}: and the pointer aims at the line it sees", true === $one['seek'] );
	}
	check( 'the elapsed time stays beside the line as it plays', true === $out['compactPinned'] );
	check( 'a title beside every control still has room to be read', $out['cardTitle'] >= 300 && true === $out['cardControlsBelow'], $out['cardTitle'] . 'px' );
	check( 'the call to action’s button is filled, light on the dark panel', 'rgb(255, 255, 255)' === $out['ctaFill'] && 'rgb(31, 41, 55)' === $out['ctaText'] && '0px' === $out['ctaBorder'], $out['ctaFill'] . ' / ' . $out['ctaText'] . ' / ' . $out['ctaBorder'] );
	check( 'the playing card is ringed on its picture', 'solid' === $out['cardRing'] && 'none' === $out['cardBox'], $out['cardRing'] . ' / ' . $out['cardBox'] );
	check( 'and the arrow with nowhere to go is not shown', is_array( $out['prevArrow'] ) && true === $out['prevArrow']['disabled'] && '0' === (string) $out['prevArrow']['opacity'] );
	check( 'a course’s progress runs across the top, not beside the video', $out['progress']['width'] >= $out['progress']['list'] - 2 && true === $out['progress']['above'], json_encode( $out['progress'] ) );
	check( 'the stacked bar leaves its title out where it would be cut to a word', 'none' === $out['stackedTitle'] );
	check( 'a waveform grows with its player', 360 === $out['wave']['narrow'] && 645 === $out['wave']['wide'] && $out['wave']['pixels'] >= 645, json_encode( $out['wave'] ) );
	check( 'paused with subtitles on a small picture, the disc steps aside for the words', '0' === (string) $out['discReading'] && '0' !== (string) $out['discBefore'], $out['discBefore'] . ' → ' . $out['discReading'] );
	check( 'and the picture still answers a tap', true === $out['resumed'] && '360x203' === $out['tapTarget'], $out['tapTarget'] );
	check( 'on a picture with room for both, the disc stays', '0' !== (string) $out['discWide'], (string) $out['discWide'] );
	check( 'on a phone the card’s cover makes room', 72 === $out['phoneCover'], $out['phoneCover'] . 'px' );
	check( 'and the title takes two lines rather than one cut short', 2 === $out['phoneTitleLines'], (string) $out['phoneTitleLines'] );
}

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
