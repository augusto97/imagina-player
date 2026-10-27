<?php
/**
 * The same video in other qualities and other languages.
 *
 * A quality is worth offering only if switching to it carries on from the same
 * second, playing if it was, and a protected file stays behind its signed link
 * whichever quality is chosen. A soundtrack is worth offering only if it stays
 * with the picture — playing, pausing, seeking and catching up with it — and
 * the player's volume and mute go on doing what they say while it plays,
 * giving the picture its own sound back, at the listener's volume, when it is
 * put away.
 *
 * The media elements are stand-ins with clocks this test owns.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Player\Attributes;
use ImaginaPlayer\Protection\Vault;
use ImaginaPlayer\Render\PlayerRenderer;

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

/** @return array<string, mixed> */
function client_config( string $html ): array {
	if ( ! preg_match( '/data-imagina-player="([^"]+)"/', $html, $m ) ) {
		return array();
	}

	return (array) json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
}

echo '# What is kept' . PHP_EOL;

$many = array();
for ( $i = 1; $i <= 11; $i++ ) {
	$many[] = array( 'src' => "https://cdn.example.com/v{$i}.mp4", 'label' => "{$i}p" );
}

$clean = Attributes::sanitize(
	array(
		'src'          => 'https://cdn.example.com/clase.mp4',
		'qualityLabel' => '<b>1080p</b>',
		'sources'      => array(
			array( 'src' => 'javascript:alert(1)', 'label' => 'bad' ),
			array( 'src' => 'https://cdn.example.com/clase-720.mp4', 'label' => '<b>720p</b>', 'id' => -4 ),
			'not a source',
			array( 'label' => 'nothing to play' ),
		),
		'audioTracks'  => array(
			array( 'src' => 'https://cdn.example.com/en.mp3', 'label' => 'English', 'srclang' => 'en"><x' ),
			array( 'src' => '', 'label' => 'empty' ),
		),
	)
);
check( 'an address that is not one is dropped, and so is an entry with nothing to play', 1 === count( $clean['sources'] ), wp_json_encode( $clean['sources'] ) );
check( 'a name is plain text', '720p' === trim( $clean['sources'][0]['label'] ) && '1080p' === $clean['qualityLabel'], wp_json_encode( array( $clean['sources'][0]['label'], $clean['qualityLabel'] ) ) );
check( 'a library number is never below nought', 0 === $clean['sources'][0]['id'] );
check( 'a language code is cut down to what one may hold', 'enx' === $clean['audioTracks'][0]['srclang'] && 1 === count( $clean['audioTracks'] ), wp_json_encode( $clean['audioTracks'] ) );
check( 'eight choices at most', 8 === count( Attributes::sanitize( array( 'sources' => $many ) )['sources'] ) );
check( 'a shortcode can give them as JSON', 'https://cdn.example.com/b.mp4' === ( Attributes::sanitize( array( 'sources' => '[{"src":"https://cdn.example.com/b.mp4","label":"480p"}]' ) )['sources'][0]['src'] ?? '' ) );

echo PHP_EOL . '# What the player is told' . PHP_EOL;

$renderer = new PlayerRenderer();

$GLOBALS['stub_posts'][31] = array( 'url' => 'https://site.test/?imagina_media=31&imgpt=tok31', 'mime' => 'video/mp4' );
$GLOBALS['stub_posts'][32] = array( 'url' => 'https://site.test/wp-content/uploads/doblaje-es.mp3', 'mime' => 'audio/mpeg' );
$GLOBALS['stub_meta'][31][ Vault::META_PROTECTED ] = '1';

$html   = $renderer->render(
	array(
		'src'          => 'https://cdn.example.com/clase.mp4',
		'qualityLabel' => '1080p',
		'sources'      => array(
			array( 'src' => 'https://cdn.example.com/clase-720.mp4', 'label' => '720p' ),
			array( 'src' => 'https://old.example.com/stale.mp4', 'id' => 31, 'label' => '480p' ),
			array( 'src' => 'https://cdn.example.com/clase_movil.mp4' ),
		),
		'audioTracks'  => array(
			array( 'src' => 'https://cdn.example.com/en.mp3', 'label' => 'English', 'srclang' => 'en' ),
			array( 'id' => 32, 'srclang' => 'es' ),
		),
	)
);
$config = client_config( $html );
$q      = (array) ( $config['video']['qualities'] ?? array() );
$d      = (array) ( $config['video']['dubs'] ?? array() );

check( 'the main file first, under its own name', 'https://cdn.example.com/clase.mp4' === ( $q[0]['src'] ?? '' ) && '1080p' === ( $q[0]['label'] ?? '' ) );
check( 'then each other quality', array( '1080p', '720p', '480p', 'Clase movil' ) === array_column( $q, 'label' ), wp_json_encode( array_column( $q, 'label' ) ) );
check( 'a file from the library asked for its address now, not the one saved', 'https://site.test/?imagina_media=31&imgpt=tok31' === ( $q[2]['src'] ?? '' ) );
check( 'and a protected one says which file to refresh its link from', 31 === ( $q[2]['protectedId'] ?? 0 ) && 0 === ( $q[1]['protectedId'] ?? -1 ) );
check( 'the video’s own sound first, with no address', '' === ( $d[0]['src'] ?? 'x' ) && 'Original' === ( $d[0]['label'] ?? '' ) );
check( 'a soundtrack with no name is named by its language', 'es' === ( $d[2]['label'] ?? '' ) && 'https://site.test/wp-content/uploads/doblaje-es.mp3' === ( $d[2]['src'] ?? '' ), wp_json_encode( $d[2] ?? null ) );
check( 'with its language, for the next video', 'en' === ( $d[1]['srclang'] ?? '' ) );
check( 'the quality button is there for the script to show', str_contains( $html, 'imgp__vbtn--quality' ) );

$short = client_config(
	( new \ImaginaPlayer\Shortcodes\PlayerShortcode() )->render(
		array(
			'src'           => 'https://cdn.example.com/clase.mp4',
			'quality_label' => '1080p',
			'sources'       => '[{"src":"https://cdn.example.com/clase-720.mp4","label":"720p"}]',
			'audio_tracks'  => '[{"src":"https://cdn.example.com/en.mp3","label":"English","srclang":"en"}]',
		)
	)
);
check( 'the shortcode takes both as JSON', array( '1080p', '720p' ) === array_column( (array) ( $short['video']['qualities'] ?? array() ), 'label' ) && 'en' === ( $short['video']['dubs'][1]['srclang'] ?? '' ), wp_json_encode( $short['video'] ?? null ) );

$plain = client_config( $renderer->render( array( 'src' => 'https://cdn.example.com/clase.mp4' ) ) );
check( 'nothing to choose, nothing sent: the code for it is never fetched', ! isset( $plain['video']['qualities'] ) && ! isset( $plain['video']['dubs'] ) );

$stream = client_config( $renderer->render( array( 'src' => 'https://cdn.example.com/live.m3u8', 'sources' => array( array( 'src' => 'https://cdn.example.com/b.mp4' ) ), 'audioTracks' => array( array( 'src' => 'https://cdn.example.com/en.mp3' ) ) ) ) );
check( 'a stream picks its own quality', ! isset( $stream['video']['qualities'] ) );
check( 'but can still have a soundtrack beside it', 2 === count( (array) ( $stream['video']['dubs'] ?? array() ) ) );

$youtube = client_config( $renderer->render( array( 'src' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'sources' => array( array( 'src' => 'https://cdn.example.com/b.mp4' ) ) ) ) );
check( 'a YouTube video has neither', ! isset( $youtube['video']['qualities'] ) && ! isset( $youtube['video']['dubs'] ) );

$audio = client_config( $renderer->render( array( 'src' => 'https://cdn.example.com/a.mp3', 'sources' => array( array( 'src' => 'https://cdn.example.com/b.mp3' ) ) ) ) );
check( 'nor does audio', ! isset( $audio['video'] ) );

$GLOBALS['stub_current_user'] = 0;
$GLOBALS['stub_caps']         = array();
$locked = $renderer->render(
	array(
		'src'         => 'https://cdn.example.com/clase.mp4',
		'sources'     => array( array( 'src' => 'https://cdn.example.com/secret-720.mp4' ) ),
		'audioTracks' => array( array( 'src' => 'https://cdn.example.com/secret-en.mp3' ) ),
		'accessRule'  => 'logged_in',
	)
);
check( 'a locked video hands over no other file either', ! str_contains( $locked, 'secret-720' ) && ! str_contains( $locked, 'secret-en' ) );
unset( $GLOBALS['stub_caps'] );

echo PHP_EOL . '# In a browser' . PHP_EOL;

$browser = find_browser();

if ( '' === $browser ) {
	echo 'SKIP  no Chromium found' . PHP_EOL;
	echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
	exit( $failures ? 1 : 0 );
}

$workdir = $root . '/build/.sources-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

foreach ( (array) glob( $root . '/build/*.{js,css}', GLOB_BRACE ) as $asset ) {
	copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
}

$GLOBALS['stub_posts'][41] = array( 'url' => 'https://site.test/?imagina_media=41&imgpt=old41', 'mime' => 'audio/mpeg' );
$GLOBALS['stub_meta'][41][ Vault::META_PROTECTED ] = '1';

$first = $renderer->render(
	array(
		'src'          => 'https://cdn.example.com/a-1080.mp4',
		'title'        => 'A',
		'qualityLabel' => '1080p',
		'sources'      => array( array( 'src' => 'https://cdn.example.com/a-720.mp4', 'label' => '720p' ) ),
		'audioTracks'  => array( array( 'id' => 41, 'label' => 'English', 'srclang' => 'en' ) ),
		'videoSpeed'   => 'yes',
	)
);
$second = $renderer->render(
	array(
		'src'         => 'https://cdn.example.com/b-1080.mp4',
		'title'       => 'B',
		'sources'     => array( array( 'src' => 'https://cdn.example.com/b-480.mp4', 'label' => '480p' ) ),
		'audioTracks' => array( array( 'src' => 'https://cdn.example.com/b-es.mp3', 'label' => 'Español', 'srclang' => 'es' ) ),
	)
);

$third = $renderer->render( array( 'src' => 'https://cdn.example.com/live.m3u8', 'title' => 'C' ) );

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>html,body{margin:0}.w{width:720px;margin:0 0 30px}</style>
</head><body>
<script>
window.addEventListener('error', function (e) {
	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify({ error: e.message + ' @' + e.lineno });
	document.body.appendChild(pre);
});
document.addEventListener('error', function (e) {
	if (e.target instanceof HTMLMediaElement && !window.__realErrors) { e.stopImmediatePropagation(); }
}, true);
(function () {
	var state = new WeakMap();
	function st(el) {
		var s = state.get(el);
		if (!s) { s = { t: 0, p: true, d: 600 }; state.set(el, s); }
		return s;
	}
	var P = HTMLMediaElement.prototype;
	Object.defineProperty(P, 'duration', { get: function () { return st(this).d; }, configurable: true });
	Object.defineProperty(P, 'currentTime', { get: function () { return st(this).t; }, set: function (v) { st(this).t = v; }, configurable: true });
	Object.defineProperty(P, 'paused', { get: function () { return st(this).p; }, configurable: true });
	Object.defineProperty(P, 'readyState', { get: function () { return 4; }, configurable: true });
	window.__audios = [];
	P.play = function () {
		if (this.tagName === 'AUDIO' && window.__audios.indexOf(this) < 0) { window.__audios.push(this); }
		var s = st(this); if (!s.p) { return Promise.resolve(); } s.p = false; this.dispatchEvent(new Event('play')); this.dispatchEvent(new Event('playing'));
		return Promise.resolve();
	};
	P.pause = function () { var s = st(this); if (s.p) { return; } s.p = true; this.dispatchEvent(new Event('pause')); };
	P.load = function () { var s = st(this); s.t = 0; s.p = true; };
	// The addresses lead nowhere, and the browser's own failure to fetch them
	// is not what is under test: only the errors this page raises reach the
	// player. The soundtrack's element is not in the page, so the listener
	// that keeps them from the video never sees its errors.
	// A browser that plays a stream by itself, as Safari does, and lists the
	// stream's soundtracks in the element.
	P.canPlayType = function (type) { return /mpegurl/i.test(type) ? 'maybe' : ''; };
	var lists = new WeakMap();
	Object.defineProperty(P, 'audioTracks', { configurable: true, get: function () {
		if (!/m3u8/.test(this.getAttribute('src') || '')) { return undefined; }
		if (!lists.has(this)) {
			lists.set(this, { length: 2, 0: { label: 'Español', language: 'es', enabled: false }, 1: { label: 'English', language: 'en', enabled: true }, addEventListener: function () {} });
		}
		return lists.get(this);
	} });
	var add = P.addEventListener;
	P.addEventListener = function (type, listener, options) {
		if (type === 'error' && typeof listener === 'function') {
			var inner = listener;
			listener = function (event) { if (!event.isTrusted) { return inner.call(this, event); } };
		}
		return add.call(this, type, listener, options);
	};
})();
window.__sent = [];
window.fetch = function (url) {
	window.__sent.push(String(url));
	return Promise.resolve(new Response(JSON.stringify({ url: 'https://site.test/?imagina_media=41&imgpt=new41' }), { status: 200 }));
};
localStorage.setItem('imagina-player:quality', '480p');
localStorage.setItem('imagina-player:audio', 'es');
window.imaginaPlayer = { restUrl: 'https://site.test/wp-json/imagina-player/v1', nonce: 'n0nce', lazyInit: false, maxComputeBytes: 0, assetUrl: './', i18n: {} };
</script>
<div class="w" id="first">{$first}</div>
<div class="w" id="second">{$second}</div>
<div class="w" id="third">{$third}</div>
<script src="./frontend.js"></script>
<script>
function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
function items(root) { return Array.prototype.map.call(root.querySelectorAll('.imgp__menu .imgp__menuitem'), function (el) { return el.textContent + (el.getAttribute('aria-checked') === 'true' ? '*' : ''); }); }
function pick(root, text) { var el = Array.prototype.find.call(root.querySelectorAll('.imgp__menu .imgp__menuitem'), function (b) { return b.textContent.indexOf(text) === 0; }); if (el) { el.click(); } return !!el; }
var MUTED = Object.getOwnPropertyDescriptor(HTMLMediaElement.prototype, 'muted');
var VOLUME = Object.getOwnPropertyDescriptor(HTMLMediaElement.prototype, 'volume');
(async function () {
	await wait(1500);
	var out = {};

	// --- A remembered choice, on a video that has it ------------------------
	var second = document.querySelector('#second .imgp');
	var secondVideo = second.querySelector('video');
	out.rememberedQuality = secondVideo.getAttribute('src');
	out.rememberedDub = MUTED.get.call(secondVideo) === true && secondVideo.muted === false;

	// --- A stream's own soundtracks, where the browser plays it ------------
	var third = document.querySelector('#third .imgp');
	var tracks = third.querySelector('video').audioTracks;
	out.nativePreferred = [tracks[0].enabled, tracks[1].enabled];
	third.querySelector('.imgp__vbtn--settings').click();
	await wait(50);
	out.nativeGear = items(third).filter(function (t) { return t.indexOf('Audio') === 0; })[0] || null;
	pick(third, 'Audio');
	pick(third, 'English');
	out.nativePicked = [tracks[0].enabled, tracks[1].enabled];
	out.nativeStored = localStorage.getItem('imagina-player:audio');

	// --- Qualities ----------------------------------------------------------
	var root = document.querySelector('#first .imgp');
	var video = root.querySelector('video');
	var quality = root.querySelector('.imgp__vbtn--quality');
	out.qualityShown = !quality.hidden;
	out.firstStayed = video.getAttribute('src');
	quality.click();
	await wait(50);
	out.qualityItems = items(root);
	video.currentTime = 42;
	await video.play();
	pick(root, '720p');
	out.switchedSrc = video.getAttribute('src');
	video.dispatchEvent(new Event('loadedmetadata'));
	await wait(50);
	out.switchedAt = video.currentTime;
	out.switchedPlaying = !video.paused;
	out.qualityStored = localStorage.getItem('imagina-player:quality');
	video.pause();

	// --- The soundtrack, from the gear --------------------------------------
	var gear = root.querySelector('.imgp__vbtn--settings');
	gear.click();
	await wait(50);
	out.gearItems = items(root);
	pick(root, 'Audio');
	await wait(50);
	out.audioItems = items(root);
	video.volume = 0.6;
	pick(root, 'English');
	await wait(50);
	out.audioStored = localStorage.getItem('imagina-player:audio');
	out.ownSoundMuted = MUTED.get.call(video) === true;
	out.controlsSayNotMuted = video.muted === false;
	video.volume = 0.3;
	out.volumeToSoundtrack = VOLUME.get.call(video) === 0.6 && video.volume === 0.3;

	video.currentTime = 100;
	await video.play();
	await wait(50);
	var dub = window.__audios[window.__audios.length - 1];
	out.dubSrc = dub ? dub.getAttribute('src') : null;
	out.dubPlaying = dub ? !dub.paused : null;
	out.dubAt = dub ? dub.currentTime : null;
	out.dubVolume = dub ? dub.volume : null;

	// Seeking, drifting and pausing, with the picture.
	video.currentTime = 250;
	video.dispatchEvent(new Event('seeked'));
	out.dubFollowedSeek = dub.currentTime;
	// Corrections are spaced out; a seek by the viewer is not.
	await wait(1100);
	dub.currentTime = 240;
	video.dispatchEvent(new Event('timeupdate'));
	out.dubPutBack = dub.currentTime;
	dub.currentTime = 250.1;
	video.currentTime = 250.2;
	video.dispatchEvent(new Event('timeupdate'));
	out.dubLeftAlone = dub.currentTime;
	video.playbackRate = 1.5;
	video.dispatchEvent(new Event('ratechange'));
	out.dubRate = dub.playbackRate;

	// The soundtrack runs short of data: the picture waits for it.
	dub.dispatchEvent(new Event('waiting'));
	out.heldForDub = video.paused;
	dub.dispatchEvent(new Event('canplay'));
	await wait(20);
	out.resumedWithDub = !video.paused && !dub.paused;
	// Not over and over: a second wait straight after is not held for.
	dub.dispatchEvent(new Event('waiting'));
	out.notHeldAgain = !video.paused;
	video.pause();
	out.dubPausedWithVideo = dub.paused;

	// A protected soundtrack whose link ran out: asked for again.
	window.__sent.length = 0;
	dub.dispatchEvent(new Event('error'));
	await wait(100);
	out.dubRefreshAsked = window.__sent.length === 1 && /stream-url\?id=41&token=old41/.test(window.__sent[0]);
	out.dubRefreshed = dub.getAttribute('src');

	// Back to the picture's own sound.
	gear.click();
	await wait(50);
	out.gearAudioLabel = items(root).filter(function (t) { return t.indexOf('Audio') === 0; })[0] || null;
	pick(root, 'Audio');
	pick(root, 'Original');
	await wait(20);
	out.ownSoundBack = MUTED.get.call(video) === false && video.muted === false;
	out.volumeCarriedBack = VOLUME.get.call(video);
	out.overridesGone = !Object.prototype.hasOwnProperty.call(video, 'volume') && !Object.prototype.hasOwnProperty.call(video, 'muted');
	out.dubSilent = dub.paused && !dub.getAttribute('src');
	out.audioForgotten = localStorage.getItem('imagina-player:audio');

	// A soundtrack its server cannot seek in: corrections that do not take.
	gear.click();
	await wait(50);
	pick(root, 'Audio');
	pick(root, 'English');
	await video.play();
	for (var miss = 0; miss < 2; miss++) {
		video.currentTime = 300 + miss;
		video.dispatchEvent(new Event('seeked'));
		dub.currentTime = 0;
		dub.dispatchEvent(new Event('seeked'));
	}
	await wait(20);
	out.unseekableGivenUp = MUTED.get.call(video) === false && !Object.prototype.hasOwnProperty.call(video, 'muted');
	out.unseekablePlaying = !video.paused;
	video.pause();

	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify(out);
	document.body.appendChild(pre);
})();
</script>
</body></html>
HTML;

$file = $workdir . '/sources.html';
file_put_contents( $file, $page );

$runner = $workdir . '/run.js';
file_put_contents(
	$runner,
	<<<'JS'
const { chromium } = require( 'playwright' );
( async () => {
	const browser = await chromium.launch( { executablePath: process.env.CHROMIUM_BIN } );
	const page = await browser.newPage( { viewport: { width: 1000, height: 800 } } );
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

if ( getenv( 'SOURCES_DEBUG' ) ) {
	exec( 'cp -r ' . escapeshellarg( $workdir ) . '/. ' . escapeshellarg( (string) getenv( 'SOURCES_DEBUG' ) ) );
}

exec( 'rm -rf ' . escapeshellarg( $workdir ) );

if ( ! preg_match( '/RESULT:(\{.*?\})</s', $dom, $matches ) ) {
	check( 'the page reported', false, substr( trim( $dom ), 0, 300 ) );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

$out  = (array) json_decode( html_entity_decode( $matches[1] ), true );
$show = static fn( $value ): string => (string) wp_json_encode( $value );

if ( isset( $out['error'] ) ) {
	check( 'the page ran without an error', false, (string) $out['error'] );
}

echo '## Remembered' . PHP_EOL;
check( 'the quality chosen on another video, where this one has it', 'https://cdn.example.com/b-480.mp4' === ( $out['rememberedQuality'] ?? '' ), $show( $out['rememberedQuality'] ?? null ) );
check( 'and the language', true === ( $out['rememberedDub'] ?? null ) );
check( 'a video without them keeps its main file', 'https://cdn.example.com/a-1080.mp4' === ( $out['firstStayed'] ?? '' ) );

echo '## A stream the browser plays by itself' . PHP_EOL;
check( 'starts in the language chosen before', array( true, false ) === ( $out['nativePreferred'] ?? null ), $show( $out['nativePreferred'] ?? null ) );
check( 'offers its soundtracks in the gear', 'Audio · Español' === ( $out['nativeGear'] ?? '' ), $show( $out['nativeGear'] ?? null ) );
check( 'and switches between them', array( false, true ) === ( $out['nativePicked'] ?? null ) && 'en' === ( $out['nativeStored'] ?? '' ), $show( array( $out['nativePicked'] ?? null, $out['nativeStored'] ?? null ) ) );

echo '## Qualities' . PHP_EOL;
check( 'the quality button is shown', true === ( $out['qualityShown'] ?? null ) );
check( 'listing the main file and the others, the one playing marked', array( '1080p*', '720p' ) === ( $out['qualityItems'] ?? null ), $show( $out['qualityItems'] ?? null ) );
check( 'a pick loads that file', 'https://cdn.example.com/a-720.mp4' === ( $out['switchedSrc'] ?? '' ) );
check( 'from the same second', 42 === (int) ( $out['switchedAt'] ?? 0 ), $show( $out['switchedAt'] ?? null ) );
check( 'still playing', true === ( $out['switchedPlaying'] ?? null ) );
check( 'and the choice is kept for the next video', '720p' === ( $out['qualityStored'] ?? '' ) );

echo '## A soundtrack' . PHP_EOL;
check( 'offered in the gear, beside the speed', in_array( 'Audio · Original', (array) ( $out['gearItems'] ?? array() ), true ), $show( $out['gearItems'] ?? null ) );
check( 'as a list with the way back', array( '‹ Back', 'Original*', 'English' ) === ( $out['audioItems'] ?? null ), $show( $out['audioItems'] ?? null ) );
check( 'chosen, the picture’s own sound goes quiet', true === ( $out['ownSoundMuted'] ?? null ) );
check( 'while the mute button still says sound is on', true === ( $out['controlsSayNotMuted'] ?? null ) );
check( 'and the volume slider moves the soundtrack', true === ( $out['volumeToSoundtrack'] ?? null ) );
check( 'which starts with the listener’s volume', 0.3 === round( (float) ( $out['dubVolume'] ?? 0 ), 2 ), $show( $out['dubVolume'] ?? null ) );
check( 'it plays with the picture, from its second', 'https://site.test/?imagina_media=41&imgpt=old41' === ( $out['dubSrc'] ?? '' ) && true === ( $out['dubPlaying'] ?? null ) && 100 === (int) ( $out['dubAt'] ?? 0 ), $show( array( $out['dubSrc'] ?? null, $out['dubPlaying'] ?? null, $out['dubAt'] ?? null ) ) );
check( 'follows a seek', 250 === (int) ( $out['dubFollowedSeek'] ?? 0 ) );
check( 'is put back when it drifts', abs( (float) ( $out['dubPutBack'] ?? 0 ) - 250 ) < 0.01, $show( $out['dubPutBack'] ?? null ) );
check( 'and left alone when it has not', abs( (float) ( $out['dubLeftAlone'] ?? 0 ) - 250.1 ) < 0.01 );
check( 'at the picture’s speed', 1.5 === (float) ( $out['dubRate'] ?? 0 ) );
check( 'the picture waits while the soundtrack catches up', true === ( $out['heldForDub'] ?? null ) && true === ( $out['resumedWithDub'] ?? null ) );
check( 'but not over and over', true === ( $out['notHeldAgain'] ?? null ) );
check( 'and pausing the picture pauses it', true === ( $out['dubPausedWithVideo'] ?? null ) );
check( 'the language is kept for the next video', 'en' === ( $out['audioStored'] ?? '' ) );
check( 'a protected soundtrack whose link ran out is asked for again', true === ( $out['dubRefreshAsked'] ?? null ) && 'https://site.test/?imagina_media=41&imgpt=new41' === ( $out['dubRefreshed'] ?? '' ), $show( array( $out['dubRefreshAsked'] ?? null, $out['dubRefreshed'] ?? null ) ) );

echo '## Back to the picture’s own sound' . PHP_EOL;
check( 'the gear says which is playing', 'Audio · English' === ( $out['gearAudioLabel'] ?? '' ), $show( $out['gearAudioLabel'] ?? null ) );
check( 'its sound comes back', true === ( $out['ownSoundBack'] ?? null ) );
check( 'at the volume the listener left', 0.3 === round( (float) ( $out['volumeCarriedBack'] ?? 0 ), 2 ), $show( $out['volumeCarriedBack'] ?? null ) );
check( 'with the player’s controls pointed at it again', true === ( $out['overridesGone'] ?? null ) );
check( 'and the soundtrack stopped and let go', true === ( $out['dubSilent'] ?? null ) );
check( 'the next video starts in its own sound', '' === ( $out['audioForgotten'] ?? 'x' ) );
check( 'a soundtrack that cannot be kept with the picture is put away', true === ( $out['unseekableGivenUp'] ?? null ) );
check( 'and the picture plays on', true === ( $out['unseekablePlaying'] ?? null ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
