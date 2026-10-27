<?php
/**
 * A playlist that is a course, and the audio player's "more" menu.
 *
 * A course is worth something only if what it remembers is the student's own
 * and nobody else's, survives a reload and another device, and a lesson further
 * on really does stay shut until the one before is done. The menu's sleep timer
 * is worth something only if it stops the sound — including a file set to loop,
 * which never ends by itself — and a bookmark only if it takes the listener
 * back to where they put it.
 *
 * The media element is a stand-in with a clock this test owns.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Player\CourseProgress;
use ImaginaPlayer\Render\PlayerRenderer;
use ImaginaPlayer\Render\PlaylistRenderer;
use ImaginaPlayer\Rest\ProgressController;

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
function course_data( string $html ): array {
	if ( ! preg_match( '/data-imagina-course="([^"]+)"/', $html, $m ) ) {
		return array();
	}

	return (array) json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
}

echo '# What is kept for a student' . PHP_EOL;

$GLOBALS['stub_user_meta'] = array();

$saved = CourseProgress::save( 5, 'Curso-1', array( 'w' => array( 'aaa', 'bbb', '<x>' ), 'c' => 'bbb', 'p' => 42.37 ) );
check( 'a course is named plainly, whatever was sent', null !== CourseProgress::get( 5, 'curso-1' ) );
check( 'the lessons watched, with anything that is not a name dropped', array( 'aaa', 'bbb', 'x' ) === $saved['w'], wp_json_encode( $saved['w'] ) );
check( 'the lesson last loaded and where it was', 'bbb' === $saved['c'] && 42.4 === $saved['p'] );

$merged = CourseProgress::save( 5, 'curso-1', array( 'w' => array( 'ccc' ), 'c' => 'ccc', 'p' => 3 ) );
check( 'a lesson watched on another device stays watched', array( 'aaa', 'bbb', 'x', 'ccc' ) === $merged['w'], wp_json_encode( $merged['w'] ) );
check( 'and the newer answer says where the student is', 'ccc' === $merged['c'] );

$reset = CourseProgress::save( 5, 'curso-1', array( 'w' => array(), 'reset' => true ) );
check( 'starting over is the one way a lesson stops being watched', array() === $reset['w'] );

check( 'nobody else’s progress is read', null === CourseProgress::get( 6, 'curso-1' ) );
check( 'and a visitor has none', null === CourseProgress::get( 0, 'curso-1' ) );

$negative = CourseProgress::save( 5, 'otro', array( 'p' => -40, 'w' => 'not a list' ) );
check( 'a position below nought is nought', 0.0 === $negative['p'] && array() === $negative['w'] );

for ( $i = 0; $i < 305; $i++ ) {
	$GLOBALS['stub_user_meta'][7][ CourseProgress::META ][ 'c' . $i ] = array( 'w' => array(), 'c' => '', 'p' => 0, 't' => 1000 + $i );
}
CourseProgress::save( 7, 'nuevo', array() );
$kept = $GLOBALS['stub_user_meta'][7][ CourseProgress::META ];
check( 'a person keeps three hundred courses at most', 300 === count( $kept ), (string) count( $kept ) );
check( 'losing the ones touched longest ago', isset( $kept['nuevo'] ) && ! isset( $kept['c0'] ) && isset( $kept['c304'] ) );

echo PHP_EOL . '# Where it is sent' . PHP_EOL;

$GLOBALS['stub_routes'] = array();
( new ProgressController() )->register_routes();
$route = $GLOBALS['stub_routes'][0] ?? array();
check( 'a route of its own', 'imagina-player/v1' === ( $route[0] ?? '' ) && '/progress' === ( $route[1] ?? '' ) );
check( 'for writing only', 'POST' === ( $route[2]['methods'] ?? '' ) );

$GLOBALS['stub_current_user'] = 0;
check( 'refused to a visitor, who keeps it in the browser', false === ( $route[2]['permission_callback'] )() );
$GLOBALS['stub_current_user'] = 5;
check( 'open to a student who is signed in', true === ( $route[2]['permission_callback'] )() );

$response = ( new ProgressController() )->save( new WP_REST_Request( array( 'course' => 'curso-2', 'watched' => array( 'l1' ), 'current' => 'l2', 'position' => 12 ) ) );
check( 'written to the account of whoever sent it', 'l2' === ( CourseProgress::get( 5, 'curso-2' )['c'] ?? '' ) && null === CourseProgress::get( 6, 'curso-2' ) );
check( 'and says so', 200 === $response->get_status() && true === $response->get_data()['saved'] );

$bad = ( new ProgressController() )->save( new WP_REST_Request( array( 'course' => '<<>>' ) ) );
check( 'a course with no name is refused', 400 === $bad->get_status() );

echo PHP_EOL . '# The list, drawn' . PHP_EOL;

$GLOBALS['stub_current_user'] = 0;
$GLOBALS['stub_user_meta']    = array();
$lessons                      = array(
	array( 'src' => 'https://cdn.example.com/leccion-1.mp3', 'title' => 'Lección 1' ),
	array( 'src' => 'https://cdn.example.com/leccion-2.mp3', 'title' => 'Lección 2' ),
	array( 'src' => 'https://cdn.example.com/leccion-3.mp3', 'title' => 'Lección 3' ),
);
$list = new PlaylistRenderer();

$plain = $list->render( array( 'items' => $lessons ) );
check( 'a list is not a course unless it is asked to be', ! str_contains( $plain, 'data-imagina-course' ) && ! str_contains( $plain, 'imgp-playlist__progress' ) );

$visitor = $list->render( array( 'items' => $lessons, 'course' => true, 'courseKey' => 'curso-a', 'courseSequential' => true ) );
$data    = course_data( $visitor );
$keys    = (array) ( $data['items'] ?? array() );
check( 'a course says so', str_contains( $visitor, 'imgp-playlist--course' ) && 'curso-a' === ( $data['key'] ?? '' ) );
check( 'a name for each lesson', 3 === count( $keys ) && 3 === count( array_unique( $keys ) ) );
check( 'which is the same on the next render', $keys === ( course_data( $list->render( array( 'items' => $lessons, 'course' => true, 'courseKey' => 'curso-a' ) ) )['items'] ?? null ) );
check( 'for a visitor: nothing to send and no account to read', false === ( $data['sync'] ?? null ) && array_key_exists( 'state', $data ) && null === $data['state'] );
check( 'the count starts at nought', str_contains( $visitor, '0 of 3 watched' ) );
check( 'with nothing to start over', (bool) preg_match( '/class="imgp-playlist__restart" hidden/', $visitor ) );
check( 'one lesson at a time: the second and third are shut', 2 === substr_count( $visitor, 'is-locked' ) );
check( 'each lesson remembers where it stopped', str_contains( $visitor, '&quot;remember&quot;:true' ) );
check( 'and the page can be cached: it is the same for every visitor', ! defined( 'DONOTCACHEPAGE' ) );

$GLOBALS['stub_current_user'] = 5;
CourseProgress::save( 5, 'curso-a', array( 'w' => array( $keys[0] ), 'c' => $keys[2], 'p' => 42 ) );
$student = $list->render( array( 'items' => $lessons, 'course' => true, 'courseKey' => 'curso-a', 'courseSequential' => true ) );
$data    = course_data( $student );
check( 'a student’s own progress, drawn', str_contains( $student, '1 of 3 watched' ) && 1 === substr_count( $student, 'is-watched' ) );
check( 'and handed to the script', $keys[2] === ( $data['state']['c'] ?? '' ) && true === ( $data['sync'] ?? null ) );
check( 'one lesson past the last one watched is open', 1 === substr_count( $student, 'is-locked' ) );
check( 'the bar is a third of the way', str_contains( $student, 'width:33%' ) );
check( 'a mark a screen reader reads on the one watched', (bool) preg_match( '/imgp-playlist__status imgp__sr">Watched</', $student ) );
check( 'and a page drawn for one person is kept out of the cache', defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );

$GLOBALS['stub_current_user'] = 6;
$other = $list->render( array( 'items' => $lessons, 'course' => true, 'courseKey' => 'curso-a' ) );
check( 'another student starts from nought', str_contains( $other, '0 of 3 watched' ) && ! str_contains( $other, 'is-watched' ) );

$GLOBALS['stub_caps'] = array();
$locked = $list->render( array( 'items' => $lessons, 'course' => true, 'accessRule' => 'roles', 'accessIds' => 'administrator' ) );
check( 'a locked course is just locked', ! str_contains( $locked, 'data-imagina-course' ) && str_contains( $locked, 'imgp--locked' ) );

echo PHP_EOL . '# The "more" button' . PHP_EOL;

$renderer = new PlayerRenderer();
$audio    = $renderer->render( array( 'src' => 'https://cdn.example.com/podcast.mp3' ) );
check( 'an audio player has it', str_contains( $audio, 'class="imgp__more"' ) && str_contains( $audio, 'aria-haspopup="menu"' ) );
check( 'with an empty menu the script fills when it is opened', (bool) preg_match( '/<span class="imgp__more-menu imgp__menu" role="menu" hidden><\/span>/', $audio ) );
check( 'the author can leave it out', ! str_contains( $renderer->render( array( 'src' => 'https://cdn.example.com/podcast.mp3', 'showMore' => 'no' ) ), 'imgp__more' ) );
check( 'a video has a gear instead', ! str_contains( $renderer->render( array( 'src' => 'https://cdn.example.com/clase.mp4' ) ), 'imgp__more' ) );

echo PHP_EOL . '# In a browser' . PHP_EOL;

$browser = find_browser();

if ( '' === $browser ) {
	echo 'SKIP  no Chromium found' . PHP_EOL;
	echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
	exit( $failures ? 1 : 0 );
}

$workdir = $root . '/build/.course-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

foreach ( (array) glob( $root . '/build/*.{js,css}', GLOB_BRACE ) as $asset ) {
	copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
}

$GLOBALS['stub_current_user'] = 5;
$GLOBALS['stub_user_meta']    = array();

$seq_items = array(
	array( 'src' => 'https://cdn.example.com/s1.mp3', 'title' => 'Uno' ),
	array( 'src' => 'https://cdn.example.com/s2.mp3', 'title' => 'Dos' ),
	array( 'src' => 'https://cdn.example.com/s3.mp3', 'title' => 'Tres' ),
);
$seq = $list->render( array( 'items' => $seq_items, 'course' => true, 'courseKey' => 'seq', 'courseSequential' => true ) );

$back_items = array(
	array( 'src' => 'https://cdn.example.com/b1.mp3', 'title' => 'B uno' ),
	array( 'src' => 'https://cdn.example.com/b2.mp3', 'title' => 'B dos' ),
	array( 'src' => 'https://cdn.example.com/b3.mp3', 'title' => 'B tres' ),
);
$back_keys = (array) ( course_data( $list->render( array( 'items' => $back_items, 'course' => true, 'courseKey' => 'back' ) ) )['items'] ?? array() );
CourseProgress::save( 5, 'back', array( 'w' => array( $back_keys[0] ), 'c' => $back_keys[2], 'p' => 42 ) );
$back = $list->render( array( 'items' => $back_items, 'course' => true, 'courseKey' => 'back' ) );

$more  = $renderer->render( array( 'src' => 'https://cdn.example.com/podcast.mp3', 'title' => 'Podcast', 'loop' => true, 'showSpeed' => 'yes' ) );
$plain = $renderer->render( array( 'src' => 'https://cdn.example.com/plain.mp3', 'title' => 'Plain', 'showSpeed' => 'no' ) );
$hold  = $list->render(
	array(
		'items' => array(
			array( 'src' => 'https://cdn.example.com/h1.mp3', 'title' => 'H uno' ),
			array( 'src' => 'https://cdn.example.com/h2.mp3', 'title' => 'H dos' ),
		),
	)
);

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>html,body{margin:0}.w{width:640px;margin:0 0 40px}</style>
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
		if (!s) { s = { t: 0, p: true, e: false, d: 200 }; state.set(el, s); }
		return s;
	}
	var P = HTMLMediaElement.prototype;
	Object.defineProperty(P, 'duration', { get: function () { return st(this).d; }, configurable: true });
	Object.defineProperty(P, 'currentTime', { get: function () { return st(this).t; }, set: function (v) { st(this).t = v; }, configurable: true });
	Object.defineProperty(P, 'paused', { get: function () { return st(this).p; }, configurable: true });
	Object.defineProperty(P, 'ended', { get: function () { return st(this).e; }, configurable: true });
	Object.defineProperty(P, 'readyState', { get: function () { return 4; }, configurable: true });
	P.play = function () { var s = st(this); s.p = false; s.e = false; this.dispatchEvent(new Event('play')); return Promise.resolve(); };
	P.pause = function () { var s = st(this); if (s.p) { return; } s.p = true; this.dispatchEvent(new Event('pause')); };
	// A new source starts paused at the beginning, as a real element's does.
	P.load = function () { var s = st(this); s.t = 0; s.e = false; s.p = true; };
	// Played to the very end, as a browser reports it.
	window.__finish = function (el) { var s = st(el); s.t = s.d; s.e = true; s.p = true; el.dispatchEvent(new Event('ended')); };
})();
window.__sent = [];
window.fetch = function (url, options) {
	window.__sent.push({ url: String(url), body: JSON.parse(options.body), keepalive: !!options.keepalive, nonce: options.headers['X-WP-Nonce'] });
	return Promise.resolve(new Response('{}'));
};
localStorage.setItem('imagina-player:speed', '1.5');
window.imaginaPlayer = { restUrl: 'https://site.test/wp-json/imagina-player/v1/', nonce: 'n0nce', lazyInit: false, maxComputeBytes: 0, assetUrl: './', i18n: {} };
</script>
<div class="w" id="seq">{$seq}</div>
<div class="w" id="back">{$back}</div>
<div class="w" id="more">{$more}</div>
<div class="w" id="plain">{$plain}</div>
<div class="w" id="hold">{$hold}</div>
<script src="./frontend.js"></script>
<script>
function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
function key(k) { (document.activeElement || document.body).dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true })); }
function rows(list) { return Array.prototype.slice.call(list.querySelectorAll('.imgp-playlist__item')); }
function current(list) { return rows(list).findIndex(function (row) { return row.classList.contains('is-current'); }); }
function items(menu) { return Array.prototype.map.call(menu.querySelectorAll('.imgp__menuitem, .imgp__more-note'), function (el) { return el.textContent; }); }
function pick(menu, text) { return Array.prototype.find.call(menu.querySelectorAll('.imgp__menuitem'), function (el) { return el.textContent.indexOf(text) === 0; }); }
(async function () {
	await wait(1200);
	var out = {};

	// --- One lesson at a time --------------------------------------------
	var seq = document.querySelector('#seq .imgp-playlist');
	var media = seq.querySelector('.imgp__media');
	var text = seq.querySelector('.imgp-playlist__progress-text');
	var bar = seq.querySelector('.imgp-playlist__progress-bar > span');
	var restart = seq.querySelector('.imgp-playlist__restart');
	out.seqStart = text.textContent.trim();
	out.seqShut = rows(seq).map(function (row) { return row.classList.contains('is-locked'); });
	out.seqShutSaid = rows(seq)[2].querySelector('.imgp-playlist__link').getAttribute('aria-disabled');
	rows(seq)[2].querySelector('.imgp-playlist__link').click();
	await wait(50);
	out.seqClickRefused = current(seq);
	out.seqSrcKept = media.getAttribute('src');

	// Dragged there while paused: not watched.
	media.currentTime = 190;
	media.dispatchEvent(new Event('timeupdate'));
	out.seqDraggedOnly = seq.querySelectorAll('.is-watched').length;
	await media.play();
	media.dispatchEvent(new Event('timeupdate'));
	out.seqWatched = rows(seq).map(function (row) { return row.classList.contains('is-watched'); });
	out.seqCount = text.textContent.trim();
	out.seqBar = bar.style.width;
	out.seqShutAfter = rows(seq).map(function (row) { return row.classList.contains('is-locked'); });
	out.seqRestartShown = !restart.hidden;
	var sent = window.__sent.filter(function (s) { return s.body.course === 'seq'; });
	out.seqSent = sent.length ? { url: sent[sent.length - 1].url, watched: sent[sent.length - 1].body.watched.length, nonce: sent[sent.length - 1].nonce } : null;

	rows(seq)[1].querySelector('.imgp-playlist__link').click();
	await wait(50);
	out.seqSecondOpened = current(seq);
	var stored = JSON.parse(localStorage.getItem('imagina-player-course:seq') || '{}');
	out.seqStoredCurrent = stored.c === rows(seq)[1].getAttribute('data-key');

	// Another tab marked a lesson meanwhile: this one's next save keeps it.
	stored.w.push('othertab');
	localStorage.setItem('imagina-player-course:seq', JSON.stringify(stored));
	media.dispatchEvent(new Event('pause'));
	out.seqOtherTabKept = JSON.parse(localStorage.getItem('imagina-player-course:seq')).w.indexOf('othertab') >= 0;

	// Skipped to the end: the lesson still counts, and the next one opens.
	media.currentTime = 10;
	window.__finish(media);
	await wait(50);
	out.seqAdvanced = current(seq);
	out.seqCountAfterEnd = text.textContent.trim();

	window.__sent.length = 0;
	restart.click();
	await wait(50);
	out.seqRestartCount = text.textContent.trim();
	out.seqRestartWatched = seq.querySelectorAll('.is-watched').length;
	out.seqRestartShut = seq.querySelectorAll('.is-locked').length;
	out.seqRestartCurrent = current(seq);
	out.seqRestartSent = window.__sent.some(function (s) { return s.body.course === 'seq' && s.body.reset === true && s.body.watched.length === 0; });
	out.seqRestartHidden = restart.hidden;
	out.seqRestartAt = media.currentTime;
	out.seqRestartPaused = media.paused;
	out.seqRestartForgot = JSON.parse(localStorage.getItem('imagina-player-course:seq')).w.length;

	// --- Back where the student stopped, on another device ---------------
	var back = document.querySelector('#back .imgp-playlist');
	out.backCurrent = current(back);
	out.backAt = back.querySelector('.imgp__media').currentTime;
	out.backCount = back.querySelector('.imgp-playlist__progress-text').textContent.trim();
	out.backPaused = back.querySelector('.imgp__media').paused;

	// --- The speed the listener chose, on every player that can change it -
	var moreRoot = document.querySelector('#more .imgp');
	var moreMedia = moreRoot.querySelector('.imgp__media');
	var speed = moreRoot.querySelector('.imgp__speed');
	out.speedRate = moreMedia.playbackRate;
	out.speedLabel = speed ? speed.textContent : null;
	out.plainRate = document.querySelector('#plain .imgp__media').playbackRate;
	speed.click();
	out.speedStored = localStorage.getItem('imagina-player:speed');

	// --- The "more" menu --------------------------------------------------
	var button = moreRoot.querySelector('.imgp__more');
	var menu = moreRoot.querySelector('.imgp__more-menu');
	button.focus();
	button.click();
	await wait(400);
	out.menuOpen = !menu.hidden && button.getAttribute('aria-expanded') === 'true';
	out.menuItems = items(menu);
	out.menuFocus = document.activeElement === menu.querySelector('.imgp__menuitem');
	key('ArrowDown');
	out.menuArrow = document.activeElement ? document.activeElement.textContent : null;
	key('Escape');
	out.menuEscape = menu.hidden && document.activeElement === button;

	// A bookmark, and back to it.
	moreMedia.currentTime = 75.6;
	button.click();
	await wait(50);
	pick(menu, 'Bookmark').click();
	out.markClosed = menu.hidden;
	var toast = moreRoot.querySelector('.imgp__toast');
	out.markToast = toast ? toast.textContent : null;
	var markKey = Object.keys(localStorage).filter(function (k) { return k.indexOf('imagina-player:bookmarks:') === 0; });
	out.markStored = markKey.length === 1 ? localStorage.getItem(markKey[0]) : markKey.length;
	moreMedia.currentTime = 3;
	button.click();
	await wait(50);
	out.marksListed = items(menu);
	pick(menu, '1:15').click();
	out.markJumped = moreMedia.currentTime;

	// A timer of fifteen minutes, faded out at the end of it.
	button.click();
	await wait(50);
	pick(menu, 'Sleep timer').click();
	out.timerStays = !menu.hidden;
	out.timerItems = items(menu);
	var realTimeout = window.setTimeout;
	var long = [];
	window.setTimeout = function (fn, ms) {
		if (ms > 60000) { long.push({ fn: fn, ms: ms }); return 0; }
		return realTimeout.apply(window, arguments);
	};
	pick(menu, '15').click();
	window.setTimeout = realTimeout;
	out.timerDelay = long.length ? long[0].ms : null;
	out.timerDot = button.classList.contains('is-sleeping');
	button.click();
	await wait(50);
	out.timerLabel = menu.querySelector('.imgp__menuitem').textContent;
	key('Escape');
	await moreMedia.play();
	moreMedia.volume = 0.8;
	if (long.length) { long[0].fn(); }
	var realNow = Date.now;
	await wait(250);
	out.fading = moreMedia.volume < 0.8 && !moreMedia.paused;
	Date.now = function () { return realNow() + 7000; };
	await wait(500);
	Date.now = realNow;
	out.fadedPaused = moreMedia.paused;
	out.fadedVolume = moreMedia.volume;
	out.fadedDot = button.classList.contains('is-sleeping');

	// "End of this one", on a file that loops.
	out.loopBefore = moreMedia.loop;
	button.click();
	await wait(50);
	pick(menu, 'Sleep timer').click();
	pick(menu, 'End of this one').click();
	out.endLoopOff = moreMedia.loop === false;
	out.endDot = button.classList.contains('is-sleeping');
	await moreMedia.play();
	window.__finish(moreMedia);
	await wait(50);
	out.endStopped = moreMedia.paused;
	out.endLoopBack = moreMedia.loop === true;
	out.endDotGone = !button.classList.contains('is-sleeping');

	// "End of this one", in a list: the next does not start.
	var hold = document.querySelector('#hold .imgp-playlist');
	var holdMedia = hold.querySelector('.imgp__media');
	var holdButton = hold.querySelector('.imgp__more');
	var holdMenu = hold.querySelector('.imgp__more-menu');
	holdButton.click();
	await wait(100);
	pick(holdMenu, 'Sleep timer').click();
	pick(holdMenu, 'End of this one').click();
	await holdMedia.play();
	window.__finish(holdMedia);
	await wait(50);
	out.holdStayed = current(hold);
	window.__finish(holdMedia);
	await wait(50);
	out.holdThenOn = current(hold);

	// --- Leaving the page sends the last word -----------------------------
	window.__sent.length = 0;
	window.dispatchEvent(new Event('pagehide'));
	out.leftSent = window.__sent.some(function (s) { return s.keepalive; });

	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify(out);
	document.body.appendChild(pre);
})();
</script>
</body></html>
HTML;

$file = $workdir . '/course.html';
file_put_contents( $file, $page );

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

if ( getenv( 'COURSE_DEBUG' ) ) {
	exec( 'cp -r ' . escapeshellarg( $workdir ) . '/. ' . escapeshellarg( (string) getenv( 'COURSE_DEBUG' ) ) );
}

exec( 'rm -rf ' . escapeshellarg( $workdir ) );

if ( ! preg_match( '/RESULT:(\{.*?\})</s', $dom, $matches ) ) {
	check( 'the page reported', false, substr( trim( $dom ), 0, 300 ) );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

$out = (array) json_decode( html_entity_decode( $matches[1] ), true );

if ( isset( $out['error'] ) ) {
	check( 'the page ran without an error', false, (string) $out['error'] );
}

$show = static fn( $value ): string => (string) wp_json_encode( $value );

echo '## One lesson at a time' . PHP_EOL;
check( 'the count starts at nought', '0 of 3 watched' === ( $out['seqStart'] ?? '' ), $show( $out['seqStart'] ?? null ) );
check( 'the lessons further on are shut', array( false, true, true ) === ( $out['seqShut'] ?? null ), $show( $out['seqShut'] ?? null ) );
check( 'and say so to a screen reader', 'true' === ( $out['seqShutSaid'] ?? '' ) );
check( 'pressing one does nothing', 0 === ( $out['seqClickRefused'] ?? -1 ) && str_contains( (string) ( $out['seqSrcKept'] ?? '' ), 's1.mp3' ) );
check( 'dragged to the end while paused is not watched', 0 === ( $out['seqDraggedOnly'] ?? -1 ) );
check( 'nine tenths watched marks the lesson', array( true, false, false ) === ( $out['seqWatched'] ?? null ), $show( $out['seqWatched'] ?? null ) );
check( 'the count and the bar follow', '1 of 3 watched' === ( $out['seqCount'] ?? '' ) && '33%' === ( $out['seqBar'] ?? '' ), $show( array( $out['seqCount'] ?? null, $out['seqBar'] ?? null ) ) );
check( 'the next lesson opens, and only that one', array( false, false, true ) === ( $out['seqShutAfter'] ?? null ), $show( $out['seqShutAfter'] ?? null ) );
check( 'there is now something to start over', true === ( $out['seqRestartShown'] ?? null ) );
check( 'the account is told, with the REST nonce', is_array( $out['seqSent'] ?? null ) && 'https://site.test/wp-json/imagina-player/v1/progress' === $out['seqSent']['url'] && 1 === $out['seqSent']['watched'] && 'n0nce' === $out['seqSent']['nonce'], $show( $out['seqSent'] ?? null ) );
check( 'the open lesson can be played', 1 === ( $out['seqSecondOpened'] ?? -1 ) );
check( 'and the browser remembers it is the one loaded', true === ( $out['seqStoredCurrent'] ?? null ) );
check( 'what another tab marked meanwhile is kept, not written over', true === ( $out['seqOtherTabKept'] ?? null ) );
check( 'a lesson skipped to its end still counts, and the next one follows', 2 === ( $out['seqAdvanced'] ?? -1 ) && '2 of 3 watched' === ( $out['seqCountAfterEnd'] ?? '' ), $show( array( $out['seqAdvanced'] ?? null, $out['seqCountAfterEnd'] ?? null ) ) );
check( 'starting over clears the marks and shuts the lessons again', '0 of 3 watched' === ( $out['seqRestartCount'] ?? '' ) && 0 === ( $out['seqRestartWatched'] ?? -1 ) && 2 === ( $out['seqRestartShut'] ?? -1 ), $show( array( $out['seqRestartCount'] ?? null, $out['seqRestartWatched'] ?? null, $out['seqRestartShut'] ?? null ) ) );
check( 'back to the first lesson, at its beginning rather than where it was left', 0 === ( $out['seqRestartCurrent'] ?? -1 ) && true === ( $out['seqRestartHidden'] ?? null ) && 0 === (int) ( $out['seqRestartAt'] ?? -1 ), $show( array( $out['seqRestartCurrent'] ?? null, $out['seqRestartAt'] ?? null ) ) );
check( 'and waiting to be played', true === ( $out['seqRestartPaused'] ?? null ) );
check( 'the browser forgets every mark, the other tab’s too', 0 === ( $out['seqRestartForgot'] ?? -1 ), $show( $out['seqRestartForgot'] ?? null ) );
check( 'and the account is told to forget', true === ( $out['seqRestartSent'] ?? null ) );

echo '## Back where the student stopped' . PHP_EOL;
check( 'the lesson that was playing on another device is loaded', 2 === ( $out['backCurrent'] ?? -1 ), $show( $out['backCurrent'] ?? null ) );
check( 'at the second it stopped', 42 === (int) ( $out['backAt'] ?? 0 ), $show( $out['backAt'] ?? null ) );
check( 'with the lessons watched there marked here', '1 of 3 watched' === ( $out['backCount'] ?? '' ) );
check( 'loaded, not played', true === ( $out['backPaused'] ?? null ) );

echo '## The speed the listener chose' . PHP_EOL;
check( 'comes back on the next page', 1.5 === (float) ( $out['speedRate'] ?? 0 ) && '1.5×' === ( $out['speedLabel'] ?? '' ), $show( array( $out['speedRate'] ?? null, $out['speedLabel'] ?? null ) ) );
check( 'but not on a player with no way to change it back', 1.0 === (float) ( $out['plainRate'] ?? 0 ) );
check( 'and a new choice is kept', '2' === ( $out['speedStored'] ?? '' ), $show( $out['speedStored'] ?? null ) );

echo '## The "more" menu' . PHP_EOL;
check( 'opens on the first press', true === ( $out['menuOpen'] ?? null ) );
check( 'with a timer, a bookmark and a link', array( 'Sleep timer · Off', 'Bookmark this moment', 'Copy link to this moment' ) === ( $out['menuItems'] ?? null ), $show( $out['menuItems'] ?? null ) );
check( 'the keyboard starts on the first item', true === ( $out['menuFocus'] ?? null ) );
check( 'the arrows walk the list', 'Bookmark this moment' === ( $out['menuArrow'] ?? '' ) );
check( 'and Escape closes it, back on the button', true === ( $out['menuEscape'] ?? null ) );
check( 'a bookmark closes the menu and says where', true === ( $out['markClosed'] ?? null ) && 'Bookmarked at 1:15' === ( $out['markToast'] ?? '' ), $show( $out['markToast'] ?? null ) );
check( 'kept in this browser for this file', '[75]' === ( $out['markStored'] ?? '' ), $show( $out['markStored'] ?? null ) );
check( 'listed the next time', array( 'Sleep timer · Off', 'Bookmark this moment', 'Bookmarks', '1:15', 'Clear bookmarks', 'Copy link to this moment' ) === ( $out['marksListed'] ?? null ), $show( $out['marksListed'] ?? null ) );
check( 'and a press goes back to it', 75 === (int) ( $out['markJumped'] ?? 0 ) );

echo '## The sleep timer' . PHP_EOL;
check( 'its own list, in the same menu', true === ( $out['timerStays'] ?? null ) && array( '‹ Back', 'Off', '15 minutes', '30 minutes', '45 minutes', '60 minutes', 'End of this one' ) === ( $out['timerItems'] ?? null ), $show( $out['timerItems'] ?? null ) );
check( 'fifteen minutes, the fade included', 15 * 60000 - 6000 === ( $out['timerDelay'] ?? 0 ), $show( $out['timerDelay'] ?? null ) );
check( 'a dot on the button while it runs', true === ( $out['timerDot'] ?? null ) );
check( 'and the menu says how long is left', 'Sleep timer · 15 min left' === ( $out['timerLabel'] ?? '' ), $show( $out['timerLabel'] ?? null ) );
check( 'the sound fades rather than stopping dead', true === ( $out['fading'] ?? null ) );
check( 'then stops', true === ( $out['fadedPaused'] ?? null ) && false === ( $out['fadedDot'] ?? null ) );
check( 'at the volume it had, for the next time', 0.8 === round( (float) ( $out['fadedVolume'] ?? 0 ), 2 ), $show( $out['fadedVolume'] ?? null ) );
check( '"End of this one" stops a looping file from going round', true === ( $out['loopBefore'] ?? null ) && true === ( $out['endLoopOff'] ?? null ) && true === ( $out['endDot'] ?? null ) );
check( 'stops at its end', true === ( $out['endStopped'] ?? null ) );
check( 'and gives the loop back afterwards', true === ( $out['endLoopBack'] ?? null ) && true === ( $out['endDotGone'] ?? null ) );
check( 'in a list, the next one does not start', 0 === ( $out['holdStayed'] ?? -1 ), $show( $out['holdStayed'] ?? null ) );
check( 'once only: the list carries on after that', 1 === ( $out['holdThenOn'] ?? -1 ), $show( $out['holdThenOn'] ?? null ) );

echo '## Leaving' . PHP_EOL;
check( 'the last word is sent so it outlives the page', true === ( $out['leftSent'] ?? null ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
