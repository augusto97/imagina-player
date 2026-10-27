<?php
/**
 * What the visual audit found, measured in a browser so it stays fixed.
 *
 * A video's bar that wrapped into three rows on a phone and covered the whole
 * picture; menus cut off by the picture, and a search panel nineteen pixels
 * tall; subtitles on top of the controls; a tap on a playing video that paused
 * it; a title, a play button and a playlist invisible on a dark theme; a card
 * squeezed by its own call to action; "compact, one line" in three lines; a
 * theme's list numbers and pink buttons on the playlist.
 *
 * The media element is a stand-in with a clock this test owns.
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

$renderer = new PlayerRenderer();
$list     = new PlaylistRenderer();

echo '# The markup' . PHP_EOL;

$chapters = array(
	array( 'start' => 0, 'title' => 'Uno' ),
	array( 'start' => 50, 'title' => 'Dos' ),
	array( 'start' => 120, 'title' => 'Tres' ),
);
$tracks   = array( array( 'src' => 'https://cdn.example.com/es.vtt', 'srclang' => 'es', 'label' => 'Español' ) );

$video = $renderer->render(
	array(
		'src'         => 'https://cdn.example.com/clase.mp4',
		'title'       => 'Clase 3: el reproductor',
		'artist'      => 'Imagina',
		'chapters'    => $chapters,
		'tracks'      => $tracks,
		'videoSpeed'  => 'yes',
		'videoSkip'   => 'yes',
		'videoSearch' => 'yes',
	)
);

check( "a video's time is text on the bar", str_contains( $video, 'class="imgp__clock"' ) );
check( 'and not two pills on the seek line', ! preg_match( '/class="imgp__seek"[^>]*>\s*<span class="imgp__time/', $video ) );
check( 'its title is over the top of the picture', (bool) preg_match( '/<div class="imgp__top">\s*<div class="imgp__meta">.*Clase 3: el reproductor/s', $video ) );
check( 'and not on the bar', ! preg_match( '/<div class="imgp__bar">.*imgp__meta.*<\/div><\/div>\s*<\/div>/sU', $video ) || ! str_contains( (string) strstr( $video, 'imgp__bar' ), 'imgp__title' ) );
check( 'the bar has a spacer that pushes the right-hand group to the end', str_contains( $video, 'imgp__spacer' ) );

$stacked = $renderer->render( array( 'src' => 'https://cdn.example.com/clase.mp4', 'title' => 'Apilado', 'skin' => 'stacked' ) );
check( 'the stacked skin draws nothing over the picture', ! str_contains( $stacked, 'imgp__top' ) );
check( 'and keeps its title on the bar', (bool) preg_match( '/<div class="imgp__bar">.*imgp__title/s', $stacked ) );

$audio = $renderer->render( array( 'src' => 'https://cdn.example.com/a.mp3', 'title' => 'Audio' ) );
check( 'audio keeps the pills that ride the waveform', (bool) preg_match( '/class="imgp__seek".*class="imgp__time imgp__time--current"/s', $audio ) );

$factory_video = $renderer->render( array( 'src' => 'https://cdn.example.com/clase.mp4' ) );
preg_match( '/--imgp-progress:([^;"]+)/', $factory_video, $progress );
preg_match( '/--imgp-on-chrome:([^;"]+)/', $factory_video, $on_chrome );
check( "with the factory accent, a video's played line is the bar's foreground, not a dark slate", isset( $progress[1], $on_chrome[1] ) && $progress[1] === $on_chrome[1], wp_json_encode( array( $progress[1] ?? null, $on_chrome[1] ?? null ) ) );

$branded_video = $renderer->render( array( 'src' => 'https://cdn.example.com/clase.mp4', 'accent' => '#d6336c' ) );
check( 'with an accent of its own, the played line is that accent', str_contains( $branded_video, '--imgp-progress:#d6336c' ) );

$spots = $renderer->render(
	array(
		'src'    => 'https://cdn.example.com/clase.mp4',
		'layers' => array(
			array( 'type' => 'hotspot', 'x' => 5, 'y' => 8, 'title' => 'Arriba a la izquierda' ),
			array( 'type' => 'hotspot', 'x' => 95, 'y' => 60, 'title' => 'A la derecha' ),
			array( 'type' => 'hotspot', 'x' => 50, 'y' => 50, 'title' => 'En medio' ),
		),
	)
);
check( 'a spot near the top opens its label underneath', (bool) preg_match( '/imgp__layer--hotspot[^"]*imgp__hotspot--below[^"]*imgp__hotspot--left/', $spots ) );
check( 'a spot near the right edge opens it to the left', (bool) preg_match( '/imgp__layer--hotspot imgp__hotspot--right/', $spots ) );
check( 'a spot in the middle opens it centred', 1 === preg_match_all( '/imgp__layer--hotspot"/', $spots ) );

$items = array();
for ( $i = 1; $i <= 6; $i++ ) {
	$items[] = array(
		'src'       => "https://cdn.example.com/t{$i}.mp3",
		'title'     => "Pista {$i}",
		'thumbnail' => 0 === $i % 3 ? '' : "https://cdn.example.com/t{$i}.jpg",
	);
}
$playlist = $list->render( array( 'items' => $items, 'layout' => 'list' ) );
check( 'every item in a list has a cover, a blank one when it has no picture', 6 === substr_count( $playlist, 'class="imgp-playlist__cover' ) && 2 === substr_count( $playlist, 'imgp-playlist__cover--blank' ) );

$branded_list = $list->render( array( 'items' => $items, 'preset' => 'default' ) );
check( 'a list on the factory colours leaves them to the stylesheet', ! preg_match( '/class="imgp-playlist[^"]*"[^>]*style="[^"]*--imgp-accent/', $branded_list ) );

echo PHP_EOL . '# In a browser' . PHP_EOL;

$browser = find_browser();

if ( '' === $browser ) {
	echo 'SKIP  no Chromium found' . PHP_EOL;
	echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
	exit( $failures ? 1 : 0 );
}

$workdir = $root . '/build/.layout-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

foreach ( (array) glob( $root . '/build/*.{js,css}', GLOB_BRACE ) as $asset ) {
	copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
}

$phone = $video;
$wide  = $renderer->render(
	array(
		'src'         => 'https://cdn.example.com/ancho.mp4',
		'title'       => 'Ancho',
		'chapters'    => $chapters,
		'tracks'      => $tracks,
		'videoSpeed'  => 'yes',
		'videoSkip'   => 'yes',
		'videoSearch' => 'yes',
	)
);
$touch = $renderer->render( array( 'src' => 'https://cdn.example.com/toque.mp4', 'title' => 'Toque', 'videoHideAfter' => 600 ) );

$long_caption = 'Una leyenda bastante larga para comprobar que el centro no se queda en la mitad de la imagen';
$decor        = $renderer->render(
	array(
		'src'    => 'https://cdn.example.com/capas.mp4',
		'layers' => array( array( 'type' => 'text', 'title' => $long_caption, 'position' => 'top' ) ),
	)
);

$compact = $renderer->render( array( 'src' => 'https://cdn.example.com/c.mp3', 'title' => 'Compacto', 'artist' => 'Imagina', 'skin' => 'compact' ) );
$card    = $renderer->render(
	array(
		'src'       => 'https://cdn.example.com/card.mp3',
		'title'     => 'Tarjeta',
		'skin'      => 'card',
		'thumbnail' => 'https://cdn.example.com/card.jpg',
		'layers'    => array( array( 'type' => 'cta', 'title' => 'Oferta', 'text' => 'Texto', 'button' => 'Quiero', 'url' => 'https://example.com', 'at' => 0 ) ),
	)
);
$dark    = $renderer->render( array( 'src' => 'https://cdn.example.com/oscuro.mp3', 'title' => 'Oscuro' ) );
$mine    = $renderer->render( array( 'src' => 'https://cdn.example.com/propio.mp3', 'title' => 'Propio', 'accent' => '#d6336c' ) );
$pale    = $renderer->render( array( 'src' => 'https://cdn.example.com/claro.mp3', 'title' => 'Claro', 'accent' => '#f4f4f5' ) );
$grid    = $list->render( array( 'items' => $items, 'layout' => 'grid' ) );
$themed  = $list->render( array( 'items' => $items, 'layout' => 'list' ) );

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>
html,body{margin:0;font-family:sans-serif}
.phone{width:390px}.wide{width:900px}
.dark{background:#111;color:#eee;padding:8px}
.entry-content ol{list-style:decimal;padding-left:2em}
.entry-content button{background:#ff87ac;min-height:48px;border-radius:0}
.entry-content a{text-decoration:underline;box-shadow:inset 0 -2px}
</style>
</head><body>
<script>
window.addEventListener('error', function (e) {
	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify({ error: e.message + ' @' + e.lineno });
	document.body.appendChild(pre);
});
document.addEventListener('error', function (e) {
	if (e.target instanceof HTMLMediaElement || e.target instanceof HTMLImageElement || e.target instanceof HTMLTrackElement) { e.stopImmediatePropagation(); }
}, true);
(function () {
	var state = new WeakMap();
	function st(el) { var s = state.get(el); if (!s) { s = { t: 0, p: true }; state.set(el, s); } return s; }
	var P = HTMLMediaElement.prototype;
	Object.defineProperty(P, 'duration', { get: function () { return 200; }, configurable: true });
	Object.defineProperty(P, 'currentTime', { get: function () { return st(this).t; }, set: function (v) { st(this).t = v; }, configurable: true });
	Object.defineProperty(P, 'paused', { get: function () { return st(this).p; }, configurable: true });
	Object.defineProperty(P, 'readyState', { get: function () { return 4; }, configurable: true });
	P.play = function () { var s = st(this); s.p = false; this.dispatchEvent(new Event('play')); this.dispatchEvent(new Event('playing')); return Promise.resolve(); };
	P.pause = function () { var s = st(this); if (s.p) { return; } s.p = true; this.dispatchEvent(new Event('pause')); };
	P.load = function () {};
})();
window.imaginaPlayer = { restUrl: '', lazyInit: false, maxComputeBytes: 0, assetUrl: './', i18n: {} };
</script>
<div class="phone" id="phone">{$phone}</div>
<div class="wide" id="wide">{$wide}</div>
<div class="phone" id="touch">{$touch}</div>
<div class="phone" id="decor">{$decor}</div>
<div class="wide" id="compact">{$compact}</div>
<div class="phone" id="card">{$card}</div>
<div class="phone" id="sticky-a">{$touch}</div>
<div class="dark phone" id="dark">{$dark}{$mine}</div>
<div class="phone" id="pale">{$pale}</div>
<div class="phone" id="grid">{$grid}</div>
<div class="entry-content wide" id="themed">{$themed}<p>Ir a <button type="button" class="imgp-time" data-imgp-time="30">0:30</button></p></div>
<script src="./frontend.js"></script>
<script>
function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
function rect(el) { var b = el.getBoundingClientRect(); return { top: b.top, bottom: b.bottom, left: b.left, right: b.right, width: b.width, height: b.height }; }
function shown(el) { return !!el && el.getClientRects().length > 0; }
(async function () {
	await wait(1200);
	var out = {};

	// A phone.
	var phone = document.querySelector('#phone .imgp');
	var bar = phone.querySelector('.imgp__bar');
	out.phoneBarHeight = Math.round(rect(bar).height);
	out.phoneButtons = Array.prototype.filter.call(bar.querySelectorAll('button, a'), shown).map(function (b) { return b.getAttribute('aria-label'); });
	out.phoneClock = phone.querySelector('.imgp__clock').textContent.replace(/\s+/g, '');
	out.chromeVar = parseFloat(phone.style.getPropertyValue('--imgp-chrome-h')) || 0;
	out.stageVar = parseFloat(phone.style.getPropertyValue('--imgp-stage-h')) || 0;
	var stage = phone.querySelector('.imgp__stage');
	var chrome = phone.querySelector('.imgp__chrome');
	var hit = document.elementFromPoint(rect(stage).left + rect(stage).width / 2, rect(chrome).top + 6);
	out.gradientClicksThrough = !!hit && hit.classList.contains('imgp__bigplay');
	var gear = phone.querySelector('.imgp__vbtn--settings');
	gear.click();
	var menu = phone.querySelector('.imgp__menu');
	out.gearItems = Array.prototype.map.call(menu.querySelectorAll('.imgp__menuitem'), function (i) { return i.textContent; });
	var chaptersItem = Array.prototype.find.call(menu.querySelectorAll('.imgp__menuitem'), function (i) { return 'Chapters' === i.textContent; });
	if (chaptersItem) { chaptersItem.click(); }
	await wait(50);
	out.chapterList = Array.prototype.map.call(menu.querySelectorAll('.imgp__menuitem'), function (i) { return i.textContent; });
	var m = rect(menu), s = rect(stage), sc = rect(phone.querySelector('.imgp__scrubber'));
	out.menuInsidePicture = !menu.hidden && m.top >= s.top - 1 && m.bottom <= sc.top + 1 && m.left >= s.left - 1 && m.right <= s.right + 1;
	out.hasMenuClass = phone.classList.contains('has-menu');
	document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
	out.focusBackToGear = document.activeElement === gear;
	out.hasMenuCleared = !phone.classList.contains('has-menu');
	// The speed list from the gear has a way back.
	gear.click();
	var speedItem = Array.prototype.find.call(menu.querySelectorAll('.imgp__menuitem'), function (i) { return /^Speed/.test(i.textContent); });
	if (speedItem) { speedItem.click(); }
	out.speedFirst = menu.querySelector('.imgp__menuitem') && menu.querySelector('.imgp__menuitem').textContent;
	menu.querySelector('.imgp__menuitem').click();
	out.backToGear = /^Speed/.test(menu.querySelector('.imgp__menuitem').textContent) && !menu.hidden;
	document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
	// Search, through the gear, is a panel that fits the picture.
	gear.click();
	var searchItem = Array.prototype.find.call(menu.querySelectorAll('.imgp__menuitem'), function (i) { return 'Search what is said' === i.textContent; });
	if (searchItem) { searchItem.click(); }
	await wait(400);
	m = rect(menu);
	out.searchPanel = { open: !menu.hidden && menu.classList.contains('imgp__menu--search'), height: Math.round(m.height), inside: m.top >= s.top - 1 && m.bottom <= sc.top + 1 };
	document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

	// Wide.
	var wide = document.querySelector('#wide .imgp');
	out.wideButtons = Array.prototype.filter.call(wide.querySelectorAll('.imgp__bar button, .imgp__bar a'), shown).map(function (b) { return b.getAttribute('aria-label') || b.className; });
	out.wideBarHeight = Math.round(rect(wide.querySelector('.imgp__bar')).height);
	out.wideTitleOnTop = shown(wide.querySelector('.imgp__top .imgp__title'));

	// A caption in the middle column is not held to half the picture.
	var caption = document.querySelector('#decor [data-layer-index="0"]');
	caption.hidden = false;
	var cr = rect(caption), ds = rect(document.querySelector('#decor .imgp__stage'));
	out.captionShare = Math.round((cr.width / ds.width) * 100);
	out.captionCentred = Math.abs((cr.left + cr.right) / 2 - (ds.left + ds.right) / 2) < 2;

	// Compact is one line.
	var row = document.querySelector('#compact .imgp__row');
	out.compactRow = Math.round(rect(row).height);
	out.compactPlay = Math.round(rect(row.querySelector('.imgp__play')).height);

	// A card keeps its offer under it.
	var card = document.querySelector('#card .imgp');
	var layer = card.querySelector('.imgp__layer');
	if (layer) { layer.hidden = false; }
	var cb = rect(card.querySelector('.imgp__body')), lb = layer ? rect(layer) : null;
	out.cardBodyWidth = Math.round(cb.width);
	out.cardLayerUnder = !!lb && lb.top >= cb.bottom - 1 && lb.width > rect(card).width * 0.8;

	// The card that follows the page: the same width at either corner.
	var stuck = document.querySelector('#sticky-a .imgp');
	stuck.classList.add('is-stuck', 'imgp--stick-bottom');
	var w1 = Math.round(rect(stuck).width);
	stuck.classList.remove('imgp--stick-bottom');
	stuck.classList.add('imgp--stick-bottom-right');
	var w2 = Math.round(rect(stuck).width);
	out.stuckWidths = [w1, w2];
	out.stuckButtons = Array.prototype.filter.call(stuck.querySelectorAll('.imgp__bar button'), shown).map(function (b) { return b.getAttribute('aria-label'); });
	stuck.classList.remove('is-stuck', 'imgp--stick-bottom-right');

	// A dark page.
	var players = document.querySelectorAll('#dark .imgp');
	var dark = players[0], mine = players[1];
	var dcs = getComputedStyle(dark);
	out.darkClass = dark.classList.contains('imgp--on-dark');
	out.darkAccent = dcs.getPropertyValue('--imgp-accent').trim();
	out.darkTitle = getComputedStyle(dark.querySelector('.imgp__title')).color;
	out.darkPlay = [getComputedStyle(dark.querySelector('.imgp__play')).backgroundColor, getComputedStyle(dark.querySelector('.imgp__play')).color];
	out.mineAccent = getComputedStyle(mine).getPropertyValue('--imgp-accent').trim();
	out.lightClass = document.querySelector('#compact .imgp').classList.contains('imgp--on-dark');

	// A pale accent.
	out.paleIcon = getComputedStyle(document.querySelector('#pale .imgp__play')).color;

	// Two columns on a phone; every cover the same size.
	var cells = document.querySelectorAll('#grid .imgp-playlist__item');
	out.gridColumns = new Set(Array.prototype.map.call(cells, function (c) { return Math.round(c.getBoundingClientRect().left); })).size;
	var covers = Array.prototype.map.call(document.querySelectorAll('#grid .imgp-playlist__cover'), function (c) { return Math.round(c.getBoundingClientRect().width); });
	out.gridCovers = covers;

	// A theme's list and buttons stay out.
	var themedList = document.querySelector('#themed .imgp-playlist__items');
	out.themedList = [getComputedStyle(themedList).listStyleType, getComputedStyle(themedList).paddingLeft];
	var link = document.querySelector('#themed .imgp-playlist__item:not(.is-current) .imgp-playlist__link');
	out.themedLink = [getComputedStyle(link).textDecorationLine, getComputedStyle(link).boxShadow];
	var current = document.querySelector('#themed .imgp-playlist__item.is-current .imgp-playlist__link');
	out.currentMarked = getComputedStyle(current).boxShadow !== 'none';
	var stamp = document.querySelector('#themed .imgp-time');
	out.timeButton = [getComputedStyle(stamp).minHeight, getComputedStyle(stamp).backgroundColor];

	// For the runner: where to tap.
	var t = document.querySelector('#touch .imgp');
	var ts = rect(t.querySelector('.imgp__stage'));
	out.tapAt = { side: [ts.left + 30, ts.top + ts.height / 2], disc: [ts.left + ts.width / 2, ts.top + ts.height / 2] };

	var pre = document.createElement('pre');
	pre.textContent = 'PROBE:' + JSON.stringify(out);
	document.body.appendChild(pre);
})();
</script>
</body></html>
HTML;

$file = $workdir . '/layout.html';
file_put_contents( $file, $page );

/*
 * Real time, real taps: a synthetic pointer event is not followed by the
 * click a finger produces, and the click is where the decision is made.
 */
$runner = $workdir . '/run.js';
file_put_contents(
	$runner,
	<<<'JS'
const { chromium } = require( 'playwright' );
( async () => {
	const browser = await chromium.launch( { executablePath: process.env.CHROMIUM_BIN } );
	const page = await browser.newPage( { viewport: { width: 1000, height: 900 }, hasTouch: true } );
	await page.goto( 'file://' + process.argv[ 2 ] );
	await page.waitForFunction( () => /PROBE:|RESULT:/.test( document.body.innerText ), null, { timeout: 30000 } );
	const text = await page.evaluate( () => document.body.innerText );
	const found = /PROBE:(\{.*\})/.exec( text );

	if ( ! found ) {
		console.log( text.slice( -400 ) );
		await browser.close();
		return;
	}

	const out = JSON.parse( found[ 1 ] );
	const video = '#touch video';
	const root = '#touch .imgp';
	await page.evaluate( ( r ) => document.querySelector( r ).scrollIntoView(), root );
	const where = await page.evaluate( ( r ) => { const b = document.querySelector( r + ' .imgp__stage' ).getBoundingClientRect(); return { side: [ b.left + 30, b.top + b.height / 2 ], disc: [ b.left + b.width / 2, b.top + b.height / 2 ] }; }, root );
	await page.touchscreen.tap( where.disc[ 0 ], where.disc[ 1 ] );
	await page.waitForTimeout( 300 );
	out.touchPlays = await page.$eval( video, ( v ) => ! v.paused );
	await page.waitForTimeout( 1200 );
	out.touchIdles = await page.$eval( root, ( r ) => r.classList.contains( 'is-chrome-idle' ) );
	await page.touchscreen.tap( where.side[ 0 ], where.side[ 1 ] );
	await page.waitForTimeout( 400 );
	out.sideTapKeepsPlaying = await page.$eval( video, ( v ) => ! v.paused );
	out.sideTapShows = await page.$eval( root, ( r ) => ! r.classList.contains( 'is-chrome-idle' ) );
	await page.touchscreen.tap( where.disc[ 0 ], where.disc[ 1 ] );
	await page.waitForTimeout( 300 );
	out.discTapPauses = await page.$eval( video, ( v ) => v.paused );
	console.log( 'RESULT:' + JSON.stringify( out ) + '<' );
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

if ( getenv( 'LAYOUT_DEBUG' ) ) {
	exec( 'cp -r ' . escapeshellarg( $workdir ) . '/. ' . escapeshellarg( (string) getenv( 'LAYOUT_DEBUG' ) ) );
}

exec( 'rm -rf ' . escapeshellarg( $workdir ) );

if ( ! preg_match( '/RESULT:(\{.*?\})</s', $dom, $matches ) ) {
	check( 'the page reported', false, substr( trim( $dom ), 0, 400 ) );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

$r = json_decode( $matches[1], true );

if ( isset( $r['error'] ) ) {
	check( 'the probe ran without throwing', false, (string) $r['error'] );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

$json = static fn( $value ): string => (string) wp_json_encode( $value );

echo '## A video on a phone' . PHP_EOL;
check( 'the bar is one row', ( $r['phoneBarHeight'] ?? 99 ) <= 44, $json( $r['phoneBarHeight'] ?? null ) );
check( 'play, sound, subtitles, the gear and full screen', array( 'Play', 'Mute', 'Subtitles', 'Settings', 'Full screen' ) === ( $r['phoneButtons'] ?? null ), $json( $r['phoneButtons'] ?? null ) );
check( 'with the time as text between them', '0:00/3:20' === ( $r['phoneClock'] ?? '' ), $json( $r['phoneClock'] ?? null ) );
check( "the bar's height and the picture's are measured for the stylesheet", ( $r['chromeVar'] ?? 0 ) > 20 && ( $r['stageVar'] ?? 0 ) > 150, $json( array( $r['chromeVar'] ?? null, $r['stageVar'] ?? null ) ) );
check( 'a click on the gradient above the bar goes through to the picture', true === ( $r['gradientClicksThrough'] ?? false ) );
check( 'the gear offers what the narrow bar cannot show', array_intersect( array( 'Chapters', 'Search what is said' ), (array) ( $r['gearItems'] ?? array() ) ) === array( 'Chapters', 'Search what is said' ) && str_starts_with( (string) ( $r['gearItems'][0] ?? '' ), 'Speed' ), $json( $r['gearItems'] ?? null ) );
check( 'and picking one opens its list', array( 'Uno', 'Dos', 'Tres' ) === ( $r['chapterList'] ?? null ), $json( $r['chapterList'] ?? null ) );
check( 'inside the picture, above the seek line', true === ( $r['menuInsidePicture'] ?? false ) );
check( 'the player says a menu is open, so the resume line can step aside', true === ( $r['hasMenuClass'] ?? false ) );
check( 'Escape hands focus back to the gear, since the button it came from is not shown', true === ( $r['focusBackToGear'] ?? false ) );
check( 'and the player says the menu is closed', true === ( $r['hasMenuCleared'] ?? false ) );
check( 'the speed list from the gear starts with a way back', str_contains( (string) ( $r['speedFirst'] ?? '' ), 'Back' ), $json( $r['speedFirst'] ?? null ) );
check( 'which goes back to the gear', true === ( $r['backToGear'] ?? false ) );
check( 'the search panel opens from the gear', true === ( $r['searchPanel']['open'] ?? false ) );
check( 'tall enough to use, and inside the picture', ( $r['searchPanel']['height'] ?? 0 ) >= 60 && true === ( $r['searchPanel']['inside'] ?? false ), $json( $r['searchPanel'] ?? null ) );

echo '## A wide video' . PHP_EOL;
check( 'skip, speed, chapters and search are on the bar', array() === array_diff( array( 'Rewind 15 seconds', 'Forward 15 seconds', 'Playback speed', 'Chapters', 'Search what is said' ), (array) ( $r['wideButtons'] ?? array() ) ), $json( $r['wideButtons'] ?? null ) );
check( 'still one row', ( $r['wideBarHeight'] ?? 99 ) <= 44, $json( $r['wideBarHeight'] ?? null ) );
check( 'with the title over the picture', true === ( $r['wideTitleOnTop'] ?? false ) );

echo '## Touch' . PHP_EOL;
check( 'a tap on the button in the middle plays', true === ( $r['touchPlays'] ?? false ) );
check( 'and the controls fade while it plays', true === ( $r['touchIdles'] ?? false ) );
check( 'a tap on the picture brings them back', true === ( $r['sideTapShows'] ?? false ) );
check( 'without pausing', true === ( $r['sideTapKeepsPlaying'] ?? false ) );
check( 'a tap on the button in the middle, with the controls up, pauses', true === ( $r['discTapPauses'] ?? false ) );

echo '## Over the picture' . PHP_EOL;
check( 'a long caption at the top centre on a phone takes its full share of the picture, not half', ( $r['captionShare'] ?? 0 ) >= 58, $json( $r['captionShare'] ?? null ) );
check( 'and centred', true === ( $r['captionCentred'] ?? false ) );

echo '## Audio' . PHP_EOL;
check( 'compact is one line', ( $r['compactRow'] ?? 999 ) <= ( $r['compactPlay'] ?? 0 ) + 4, $json( array( $r['compactRow'] ?? null, $r['compactPlay'] ?? null ) ) );
check( 'a card keeps the width beside its cover', ( $r['cardBodyWidth'] ?? 0 ) > 200, $json( $r['cardBodyWidth'] ?? null ) );
check( 'and its call to action goes underneath, full width', true === ( $r['cardLayerUnder'] ?? false ) );

echo '## The card that follows the page' . PHP_EOL;
check( 'the same width at the bottom and in a corner', 2 === count( (array) ( $r['stuckWidths'] ?? array() ) ) && $r['stuckWidths'][0] === $r['stuckWidths'][1] && $r['stuckWidths'][0] >= 240, $json( $r['stuckWidths'] ?? null ) );
check( 'with play, sound and full screen only', array( 'Play', 'Mute', 'Full screen' ) === ( $r['stuckButtons'] ?? null ), $json( $r['stuckButtons'] ?? null ) );

echo '## Colours' . PHP_EOL;
check( 'a player on a dark page knows it', true === ( $r['darkClass'] ?? false ) );
check( 'and a player on a light one does not', false === ( $r['lightClass'] ?? true ) );
check( 'the factory accent turns light there', '#e5e7eb' === ( $r['darkAccent'] ?? '' ), $json( $r['darkAccent'] ?? null ) );
check( "the title takes the page's own light text", 'rgb(238, 238, 238)' === ( $r['darkTitle'] ?? '' ), $json( $r['darkTitle'] ?? null ) );
check( 'the play button is a light disc with a dark icon', array( 'rgb(229, 231, 235)', 'rgb(17, 24, 39)' ) === ( $r['darkPlay'] ?? null ), $json( $r['darkPlay'] ?? null ) );
check( 'an accent the author chose is kept on a dark page', '#d6336c' === ( $r['mineAccent'] ?? '' ), $json( $r['mineAccent'] ?? null ) );
check( 'a pale accent gets a dark play icon', 'rgb(17, 17, 17)' === ( $r['paleIcon'] ?? '' ), $json( $r['paleIcon'] ?? null ) );

echo '## Lists' . PHP_EOL;
check( 'a grid on a phone is two columns', 2 === ( $r['gridColumns'] ?? 0 ), $json( $r['gridColumns'] ?? null ) );
check( 'and every cover is the same size, a blank one included', 1 === count( array_unique( (array) ( $r['gridCovers'] ?? array() ) ) ) && 6 === count( (array) ( $r['gridCovers'] ?? array() ) ), $json( $r['gridCovers'] ?? null ) );
check( "a theme's numbered, indented lists stay out of the playlist", array( 'none', '0px' ) === ( $r['themedList'] ?? null ), $json( $r['themedList'] ?? null ) );
check( 'and its underlined links', array( 'none', 'none' ) === ( $r['themedLink'] ?? null ), $json( $r['themedLink'] ?? null ) );
check( 'while the current track keeps its mark', true === ( $r['currentMarked'] ?? false ) );
check( 'a timestamp button is not the theme\'s pink 48-pixel button', '0px' === ( $r['timeButton'][0] ?? '' ) && 'rgb(255, 135, 172)' !== ( $r['timeButton'][1] ?? '' ), $json( $r['timeButton'] ?? null ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
