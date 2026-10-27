<?php
/**
 * What the front-end audit found, driven in a browser so it stays fixed.
 *
 * Keys pressed on the scrub bar acting twice; a tap on a phone that hid the
 * controls it had just shown; a player moved by a page builder left dead; a
 * floating player that never went home; a "resume from" chip that lied; a
 * position that was not saved when the tab was hidden; a browser's refusal to
 * autoplay taken for a broken file; menus that needed two presses and lost
 * focus; slider arrows that did nothing right to left.
 *
 * The media element is a stand-in with a clock this test owns: what is under
 * test is the player's behaviour, not a decoder.
 */

require __DIR__ . '/bootstrap.php';

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

echo '# Names that survive a page load' . PHP_EOL;

$items = array(
	array( 'src' => 'https://cdn.example.com/a.mp4', 'title' => 'A' ),
	array( 'src' => 'https://cdn.example.com/b.mp4', 'title' => 'B' ),
);
$list = new PlaylistRenderer();
preg_match( '/data-imagina-playlist-key="([^"]+)"/', $list->render( array( 'items' => $items ) ), $one );
preg_match( '/data-imagina-playlist-key="([^"]+)"/', $list->render( array( 'items' => $items ) ), $two );
check( 'a playlist is named the same on two renders', ! empty( $one[1] ) && $one[1] === ( $two[1] ?? '' ), wp_json_encode( array( $one[1] ?? null, $two[1] ?? null ) ) );

$renderer = new PlayerRenderer();
$signed_a = $renderer->render( array( 'src' => 'https://site.test/?imagina_media=7&imgpt=one' ) );
$signed_b = $renderer->render( array( 'src' => 'https://site.test/?imagina_media=7&imgpt=two' ) );
preg_match( '/&quot;layerKey&quot;:&quot;([a-f0-9]+)&quot;/', $signed_a, $ka );
preg_match( '/&quot;layerKey&quot;:&quot;([a-f0-9]+)&quot;/', $signed_b, $kb );
check( 'a signed address gives the same key whatever its signature', ! empty( $ka[1] ) && $ka[1] === ( $kb[1] ?? '' ), wp_json_encode( array( $ka[1] ?? null, $kb[1] ?? null ) ) );

echo PHP_EOL . '# In a browser' . PHP_EOL;

$browser = find_browser();

if ( '' === $browser ) {
	echo 'SKIP  no Chromium found' . PHP_EOL;
	echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
	exit( $failures ? 1 : 0 );
}

$workdir = $root . '/build/.runtime-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

foreach ( (array) glob( $root . '/build/*.{js,css}', GLOB_BRACE ) as $asset ) {
	copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
}

$src_main   = 'https://cdn.example.com/main.mp4';
$src_resume = 'https://cdn.example.com/resume.mp4';
$src_short  = 'https://cdn.example.com/short.mp4';
$key        = static fn( string $src ): string => 'imagina-player:position:' . substr( md5( 'url_' . md5( $src ) ), 0, 12 );

$main   = $renderer->render( array( 'src' => $src_main, 'title' => 'Main', 'videoSpeed' => 'yes', 'rememberPosition' => 'yes', 'sticky' => 'yes', 'videoHideAfter' => 2000 ) );
$resume = $renderer->render( array( 'src' => $src_resume, 'title' => 'Resume', 'rememberPosition' => 'yes', 'startTime' => 10 ) );
$short  = str_replace( '<video', '<video data-fake-duration="60"', $renderer->render( array( 'src' => $src_short, 'title' => 'Short', 'rememberPosition' => 'yes' ) ) );
$moved  = $renderer->render( array( 'src' => 'https://cdn.example.com/moved.mp4', 'title' => 'Moved' ) );

$cards = array();
for ( $i = 1; $i <= 8; $i++ ) {
	$cards[] = array( 'src' => "https://cdn.example.com/v{$i}.mp4", 'title' => "Clase {$i}", 'thumbnail' => "https://cdn.example.com/v{$i}.jpg" );
}
$slider = $list->render( array( 'items' => $cards, 'layout' => 'slider' ) );

$keys = wp_json_encode( array( 'main' => $key( $src_main ), 'resume' => $key( $src_resume ), 'short' => $key( $src_short ) ) );

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>html,body{margin:0}.w{width:640px}.spacer{height:2600px}</style>
</head><body>
<script>
window.addEventListener('error', function (e) {
	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify({ error: e.message + ' @' + e.lineno });
	document.body.appendChild(pre);
});
// The addresses lead nowhere, so the browser's own load fails; that is not
// the player's error and is kept from it.
document.addEventListener('error', function (e) {
	if (e.target instanceof HTMLMediaElement) { e.stopImmediatePropagation(); }
}, true);
// A media element whose clock this page owns.
(function () {
	var state = new WeakMap();
	function st(el) {
		var s = state.get(el);
		if (!s) { s = { t: 0, p: true, d: Number(el.getAttribute('data-fake-duration') || 200) }; state.set(el, s); }
		return s;
	}
	var P = HTMLMediaElement.prototype;
	Object.defineProperty(P, 'duration', { get: function () { return st(this).d; }, configurable: true });
	Object.defineProperty(P, 'currentTime', { get: function () { return st(this).t; }, set: function (v) { st(this).t = v; }, configurable: true });
	Object.defineProperty(P, 'paused', { get: function () { return st(this).p; }, configurable: true });
	Object.defineProperty(P, 'readyState', { get: function () { return 4; }, configurable: true });
	P.play = function () {
		if (window.__refuse) { return Promise.reject(new DOMException('no', 'NotAllowedError')); }
		var s = st(this); s.p = false; this.dispatchEvent(new Event('play')); this.dispatchEvent(new Event('playing'));
		return Promise.resolve();
	};
	P.pause = function () { var s = st(this); if (s.p) { return; } s.p = true; this.dispatchEvent(new Event('pause')); };
	P.load = function () {};
})();
var KEYS = {$keys};
localStorage.setItem(KEYS.resume, '40');
localStorage.setItem(KEYS.short, '500');
window.imaginaPlayer = { restUrl: '', lazyInit: false, maxComputeBytes: 0, assetUrl: './', i18n: {} };
</script>
<div class="w" id="main">{$main}</div>
<div class="w" id="resume">{$resume}</div>
<div class="w" id="short">{$short}</div>
<div class="w" id="moved-from">{$moved}</div>
<div id="moved-to"></div>
<div class="w" dir="rtl" id="slider">{$slider}</div>
<div class="spacer"></div>
<script src="./frontend.js"></script>
<script>
function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
function key(el, k) { el.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true })); }
function tap(el, x, y) {
	el.dispatchEvent(new PointerEvent('pointerdown', { pointerType: 'touch', bubbles: true, clientX: x, clientY: y }));
	el.dispatchEvent(new PointerEvent('pointerup', { pointerType: 'touch', bubbles: true, clientX: x, clientY: y }));
}
(async function () {
	await wait(900);
	var out = {};
	var main = document.querySelector('#main .imgp');
	var media = main.querySelector('.imgp__media');
	var seek = main.querySelector('.imgp__seek');

	// Keys on the scrub bar act once.
	media.currentTime = 30;
	seek.focus();
	key(seek, 'ArrowLeft');
	out.afterLeft = media.currentTime;
	key(seek, ' ');
	await wait(50);
	out.playingAfterSpace = !media.paused;
	out.errorAfterSpace = main.classList.contains('has-error');
	media.pause();

	// A tap shows the controls when they are hidden, and hides them when shown.
	var stage = main.querySelector('.imgp__stage');
	var box = stage.getBoundingClientRect();
	await media.play();
	main.classList.add('is-chrome-idle');
	tap(stage, box.left + box.width / 2, box.top + 20);
	out.shownByTap = !main.classList.contains('is-chrome-idle');
	await wait(400);
	tap(stage, box.left + box.width / 2, box.top + 20);
	out.hiddenBySecondTap = main.classList.contains('is-chrome-idle');
	media.pause();

	// Position saved on each new second and on pause.
	media.currentTime = 33.4;
	media.dispatchEvent(new Event('timeupdate'));
	out.savedOnSecond = localStorage.getItem(KEYS.main);
	media.currentTime = 37.9;
	media.dispatchEvent(new Event('pause'));
	out.savedOnPause = localStorage.getItem(KEYS.main);
	media.currentTime = 52;
	document.dispatchEvent(new Event('visibilitychange'));
	window.dispatchEvent(new Event('pagehide'));
	out.savedOnLeave = localStorage.getItem(KEYS.main);

	// Menus: a second button swaps its own list in; Escape returns focus.
	var speed = main.querySelector('.imgp__speed');
	var gear = main.querySelector('.imgp__vbtn--settings');
	var menu = main.querySelector('.imgp__menu');
	speed.click();
	out.speedOpen = !menu.hidden;
	gear.click();
	out.gearSwapped = !menu.hidden && /Speed/.test(menu.textContent) && /Copy link/.test(menu.textContent);
	out.gearExpanded = gear.getAttribute('aria-expanded');
	out.speedCollapsed = speed.getAttribute('aria-expanded');
	key(document.activeElement || document.body, 'ArrowDown');
	var gearItems = menu.querySelectorAll('.imgp__menuitem');
	out.arrowMoved = document.activeElement && gearItems[1] === document.activeElement ? document.activeElement.textContent : null;
	key(document.activeElement || document.body, 'Escape');
	out.closedByEscape = menu.hidden;
	out.focusBackOnGear = document.activeElement === gear;

	// Resume beats the start time, and says so truthfully.
	var resume = document.querySelector('#resume .imgp');
	out.resumeAt = resume.querySelector('.imgp__media').currentTime;
	await wait(300);
	var chip = resume.querySelector('.imgp__resume-text');
	out.resumeChip = chip ? chip.textContent : null;

	// A position past the end is forgotten, not offered.
	var short = document.querySelector('#short .imgp');
	out.shortAt = short.querySelector('.imgp__media').currentTime;
	out.shortChip = !!short.querySelector('.imgp__resume');
	out.shortForgotten = localStorage.getItem(KEYS.short) === null;

	// The browser declining to autoplay is not a broken file.
	window.__refuse = true;
	short.querySelector('.imgp__play').click();
	await wait(100);
	out.refusalIsNotError = !short.classList.contains('has-error');
	window.__refuse = false;

	// A player moved by a page builder keeps working.
	var movedRoot = document.querySelector('#moved-from .imgp');
	document.querySelector('#moved-to').appendChild(movedRoot);
	await wait(100);
	movedRoot.querySelector('.imgp__vbtn--settings').click();
	out.movedStillWorks = !movedRoot.querySelector('.imgp__menu').hidden;
	key(document, 'Escape');

	// The floating player goes home when the reader scrolls back.
	await media.play();
	window.scrollTo(0, 2400);
	await wait(400);
	out.stuckAway = main.classList.contains('is-stuck');
	window.scrollTo(0, 0);
	await wait(400);
	out.homeAgain = !main.classList.contains('is-stuck');
	media.pause();

	// Right to left, the slider's arrows move the row.
	var slider = document.querySelector('#slider .imgp-playlist');
	var row = slider.querySelector('.imgp-playlist__items');
	var next = slider.querySelector('.imgp-playlist__nav--next');
	var prev = slider.querySelector('.imgp-playlist__nav--prev');
	out.rtlShown = !next.hidden;
	out.rtlPrevAtStart = prev.disabled;
	next.click();
	await wait(800);
	out.rtlMoved = row.scrollLeft;
	out.rtlPrevAfter = prev.disabled;
	var first = Array.prototype.find.call(slider.querySelectorAll('.imgp-playlist__item'), function (item) {
		var r = item.getBoundingClientRect(); var b = row.getBoundingClientRect();
		return r.right <= b.right + 1 && r.left >= b.left - 1;
	});
	var b2 = row.getBoundingClientRect();
	out.rtlCardAligned = !!first && Math.abs(first.getBoundingClientRect().right - b2.right) < 12;

	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify(out);
	document.body.appendChild(pre);
})();
</script>
</body></html>
HTML;

$file = $workdir . '/runtime.html';
file_put_contents( $file, $page );

/*
 * In a real-time browser rather than `--dump-dom`. Scrolling, the observer
 * that notices it, and a smooth scroll along a row all run on rendering
 * frames, which a virtual-time budget does not reliably hand out — the
 * floating player and the slider looked broken there and worked on a page.
 */
$runner = $workdir . '/run.js';
file_put_contents(
	$runner,
	<<<'JS'
const { chromium } = require( 'playwright' );
( async () => {
	const browser = await chromium.launch( { executablePath: process.env.CHROMIUM_BIN } );
	const page = await browser.newPage( { viewport: { width: 900, height: 700 } } );
	await page.goto( 'file://' + process.argv[ 2 ] );
	await page.waitForFunction( () => /RESULT:/.test( document.body.innerText ), null, { timeout: 30000 } );
	console.log( await page.content() );
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

if ( getenv( 'RUNTIME_DEBUG' ) ) {
	exec( 'cp -r ' . escapeshellarg( $workdir ) . '/. ' . escapeshellarg( (string) getenv( 'RUNTIME_DEBUG' ) ) );
}

exec( 'rm -rf ' . escapeshellarg( $workdir ) );

if ( ! preg_match( '/RESULT:(\{.*?\})</s', $dom, $matches ) ) {
	check( 'the page reported', false, substr( trim( $dom ), 0, 300 ) );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

$r = json_decode( html_entity_decode( $matches[1] ), true );

if ( isset( $r['error'] ) ) {
	check( 'the probe ran without throwing', false, (string) $r['error'] );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

echo '## Keys on the scrub bar' . PHP_EOL;
check( 'a left arrow seeks five seconds, not fifteen', 25 === (int) ( $r['afterLeft'] ?? -1 ), (string) ( $r['afterLeft'] ?? '?' ) );
check( 'space plays, once', true === ( $r['playingAfterSpace'] ?? false ) );
check( 'and is not taken for a broken file', false === ( $r['errorAfterSpace'] ?? true ) );

echo '## Touch' . PHP_EOL;
check( 'a tap brings hidden controls back', true === ( $r['shownByTap'] ?? false ) );
check( 'and the next tap puts them away', true === ( $r['hiddenBySecondTap'] ?? false ) );

echo '## Remembering where the viewer is' . PHP_EOL;
check( 'saved on each new second', '33' === ( $r['savedOnSecond'] ?? null ), (string) ( $r['savedOnSecond'] ?? 'null' ) );
check( 'and on pause', '37' === ( $r['savedOnPause'] ?? null ), (string) ( $r['savedOnPause'] ?? 'null' ) );
check( 'and when the page is hidden or closed', '52' === ( $r['savedOnLeave'] ?? null ), (string) ( $r['savedOnLeave'] ?? 'null' ) );
check( 'a remembered position beats the start time', 40 === (int) ( $r['resumeAt'] ?? -1 ), (string) ( $r['resumeAt'] ?? '?' ) );
check( 'and the chip says where it really is', 'Resume from 0:40' === ( $r['resumeChip'] ?? '' ), (string) ( $r['resumeChip'] ?? 'null' ) );
check( 'a position past the end is not resumed', 0 === (int) ( $r['shortAt'] ?? -1 ), (string) ( $r['shortAt'] ?? '?' ) );
check( 'nor offered', false === ( $r['shortChip'] ?? true ) );
check( 'and is forgotten', true === ( $r['shortForgotten'] ?? false ) );
check( 'a refused autoplay is not shown as an error', true === ( $r['refusalIsNotError'] ?? false ) );

echo '## Menus' . PHP_EOL;
check( 'the speed button opens its list', true === ( $r['speedOpen'] ?? false ) );
check( 'pressing the gear then swaps the gear’s list in, in one press', true === ( $r['gearSwapped'] ?? false ) );
check( 'which says it is open', 'true' === ( $r['gearExpanded'] ?? '' ) );
check( 'while the speed button says it is not', 'false' === ( $r['speedCollapsed'] ?? '' ) );
check( 'the arrows walk the list', is_string( $r['arrowMoved'] ?? null ), (string) ( $r['arrowMoved'] ?? 'null' ) );
check( 'Escape closes it', true === ( $r['closedByEscape'] ?? false ) );
check( 'and hands focus back to the gear', true === ( $r['focusBackOnGear'] ?? false ) );

echo '## Moved, floating, mirrored' . PHP_EOL;
check( 'a player moved in the page still works', true === ( $r['movedStillWorks'] ?? false ) );
check( 'a playing player floats when scrolled away', true === ( $r['stuckAway'] ?? false ) );
check( 'and goes home when the reader scrolls back', true === ( $r['homeAgain'] ?? false ) );
check( 'right to left, the slider has arrows', true === ( $r['rtlShown'] ?? false ) );
check( 'with back disabled at the start', true === ( $r['rtlPrevAtStart'] ?? false ) );
check( 'next moves the row', ( $r['rtlMoved'] ?? 0 ) < 0, (string) ( $r['rtlMoved'] ?? '?' ) );
check( 'and enables back', false === ( $r['rtlPrevAfter'] ?? true ) );
check( 'leaving a whole card at the leading edge', true === ( $r['rtlCardAligned'] ?? false ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
