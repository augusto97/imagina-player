<?php
/**
 * Clips: a link that plays from one second to another, and what it looks like
 * where it is shared.
 *
 * A clip is worth sharing only if the player really stops at its end — not a
 * quarter of a second into the next sentence — offers the clip again or the
 * rest, and lets go the moment the viewer goes somewhere else. Its card is
 * worth having only if it names the clip, and if it hands a stranger's site
 * nothing the visitor could not already see: no frame for a player behind a
 * rule, nothing for a draft or a post behind a password.
 *
 * The media element is a stand-in with a clock this test owns.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Render\PlayerRenderer;
use ImaginaPlayer\Share\Clip;
use ImaginaPlayer\Share\ClipShare;
use ImaginaPlayer\Media\Track;
use ImaginaPlayer\Player\Attributes;

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

// Just enough of WordPress for a post, its address and its page.
if ( ! class_exists( 'WP_Post' ) ) {
	final class WP_Post {
		public int $ID = 0;
		public string $post_content = '';
		public string $post_status = 'publish';
		public string $post_password = '';
		public string $post_type = 'post';
		public string $post_excerpt = '';
		public string $post_title = '';
		public function __construct( array $fields = array() ) {
			foreach ( $fields as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}
function get_post( $id ) { return $GLOBALS['stub_posts_objects'][ (int) $id ] ?? null; }
function url_to_postid( $url ) {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
	return (int) ( $query['p'] ?? 0 );
}
function is_post_type_viewable( $type ) { return 'post' === $type; }
function get_bloginfo( $what = '' ) { return 'charset' === $what ? 'UTF-8' : 'Lab'; }
function wp_trim_words( $text, $words = 55, $more = '…' ) {
	$parts = preg_split( '/\s+/', trim( (string) $text ) );
	return count( $parts ) > $words ? implode( ' ', array_slice( $parts, 0, $words ) ) . $more : implode( ' ', $parts );
}
function strip_shortcodes( $text ) { return (string) preg_replace( '/\[[^\]]+\]/', '', (string) $text ); }
function get_the_post_thumbnail_url( $post, $size = '' ) { return ''; }
function get_language_attributes() { return 'lang="es"'; }
function wp_print_styles( $handles = array() ) { echo '<link rel="stylesheet" href="frontend.css">'; }
function wp_print_scripts( $handles = array() ) { echo '<script src="frontend.js"></script>'; }
function get_shortcode_regex( $tags = array() ) {
	return '\[(\[?)(' . implode( '|', $tags ) . ')(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)';
}
function shortcode_parse_atts( $text ) {
	preg_match_all( '/([\w-]+)\s*=\s*"([^"]*)"/', (string) $text, $m, PREG_SET_ORDER );
	$atts = array();
	foreach ( $m as $pair ) { $atts[ strtolower( $pair[1] ) ] = $pair[2]; }
	return $atts;
}

echo '# Reading a clip off an address' . PHP_EOL;

check( 'seconds', 95.0 === Clip::moment( '95' ) );
check( 'minutes and seconds', 95.0 === Clip::moment( '1:35' ) );
check( 'hours too', 3723.0 === Clip::moment( '1:02:03' ) );
check( 'as YouTube writes it', 95.0 === Clip::moment( '1m35s' ) && 7200.0 === Clip::moment( '2h' ) );
check( 'and nothing else', -1.0 === Clip::moment( 'abc' ) && -1.0 === Clip::moment( '' ) && -1.0 === Clip::moment( '1:2:3:4' ) );

check( 'a clip', array( 'start' => 5.0, 'end' => 12.0, 'player' => 'clase' ) === Clip::from_query( array( 't' => '5', 'end' => '0:12', 'player' => 'clase' ) ) );
check( 'a clip as a media fragment writes it', 12.0 === ( Clip::from_query( array( 't' => '5,12' ) )['end'] ?? 0 ) );
check( 'an end before the start is a moment, not a clip', 0.0 === ( Clip::from_query( array( 't' => '30', 'end' => '10' ) )['end'] ?? -1 ) );
check( 'no start, no clip', null === Clip::from_query( array( 'end' => '10' ) ) && null === Clip::from_query( array( 't' => 'soon' ) ) );
check( 'a player’s name keeps nothing that could leave an attribute', 'x' === Clip::from_query( array( 't' => '1', 'player' => '"><x' ) )['player'] );
check( 'and something that is not text is no name', '' === Clip::from_query( array( 't' => '1', 'player' => array( 'a' ) ) )['player'] );

echo PHP_EOL . '# Which player a post means' . PHP_EOL;

$blocks = array(
	array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerHTML' => '<p>[imagina_player mp3="https://cdn.example.com/intro.mp3" title="Intro"]</p>', 'innerBlocks' => array() ),
	array(
		'blockName'   => 'core/group',
		'attrs'       => array(),
		'innerHTML'   => '',
		'innerBlocks' => array(
			array( 'blockName' => 'imagina/video-player', 'attrs' => array( 'anchor' => 'clase', 'src' => 'https://cdn.example.com/clase.mp4', 'title' => 'Clase' ), 'innerHTML' => '', 'innerBlocks' => array() ),
		),
	),
	array( 'blockName' => 'imagina/playlist', 'attrs' => array( 'anchor' => 'lista', 'items' => array( array( 'src' => 'https://cdn.example.com/uno.mp3', 'title' => 'Uno' ) ) ), 'innerHTML' => '', 'innerBlocks' => array() ),
);

check( 'by its name, inside another block', 'Clase' === ( Clip::find( $blocks, 'clase' )['title'] ?? '' ) );
check( 'with no name, the first on the page — a shortcode too', 'https://cdn.example.com/intro.mp3' === ( Clip::find( $blocks )['src'] ?? '' ) && 'Intro' === ( Clip::find( $blocks )['title'] ?? '' ) );
check( 'a playlist is its first item', 'Uno' === ( Clip::find( $blocks, 'lista' )['title'] ?? '' ) );
check( 'a name nobody has is the first player', 'Intro' === ( Clip::find( $blocks, 'nadie' )['title'] ?? '' ) );
check( 'a post with no player has none', null === Clip::find( array( array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerHTML' => '<p>Hola</p>', 'innerBlocks' => array() ) ) ) );

echo PHP_EOL . '# What the card says' . PHP_EOL;

$post = new WP_Post(
	array(
		'ID'           => 5,
		'post_content' => '<!-- wp:imagina/video-player {"anchor":"clase","src":"https://cdn.example.com/clase.mp4","title":"Clase 3","poster":"https://cdn.example.com/poster.jpg"} /-->'
			. '<!-- wp:imagina/audio-player {"anchor":"podcast","src":"https://cdn.example.com/ep.mp3","title":"Episodio 7"} /-->'
			. '<!-- wp:imagina/video-player {"anchor":"privada","src":"https://cdn.example.com/secret.mp4","accessRule":"logged_in"} /-->',
		'post_excerpt' => 'Una clase sobre <b>tiempos</b>.',
	)
);
$GLOBALS['stub_posts_objects'][5] = $post;

$clip  = array( 'start' => 95.0, 'end' => 130.0, 'player' => 'clase' );
$track = Track::from_attributes( Attributes::sanitize( Clip::player( $post, 'clase' ) ?? array() ) );
check( 'the player’s title with the clip’s times', 'Clase 3 (1:35–2:10)' === Clip::title( $track, $post, $clip ), Clip::title( $track, $post, $clip ) );
check( 'or the moment it opens at', 'Clase 3, from 1:35' === Clip::title( $track, $post, array( 'end' => 0.0 ) + $clip ) );
check( 'its picture is the poster', 'https://cdn.example.com/poster.jpg' === Clip::image( $track, $post ) );
check( 'the clip’s own address, and nothing else on it', 'https://example.test/?p=5&t=95&end=130&player=clase' === Clip::url( $post, $clip ), Clip::url( $post, $clip ) );

$share = new ClipShare();
$card  = $share->card( $post, $clip, Clip::player( $post, 'clase' ) ?? array() );
check( 'names the oEmbed endpoint for the clip', str_contains( $card, 'type="application/json+oembed"' ) && str_contains( $card, 'imagina-player/v1/oembed?url=https%3A%2F%2Fexample.test%2F%3Fp%3D5%26t%3D95%26end%3D130%26player%3Dclase' ), $card );
check( 'with Open Graph tags for the clip', str_contains( $card, '<meta property="og:title" content="Clase 3 (1:35–2:10)" />' ) && str_contains( $card, 'og:image" content="https://cdn.example.com/poster.jpg"' ) && str_contains( $card, 'og:type" content="video.other"' ) );
check( 'the excerpt as plain text', str_contains( $card, 'og:description" content="Una clase sobre tiempos."' ) );
check( 'and a large card for X', str_contains( $card, 'twitter:card" content="summary_large_image"' ) );

add_filter( 'imagina_player_clip_open_graph', '__return_false' );
$plain = $share->card( $post, $clip, Clip::player( $post, 'clase' ) ?? array() );
check( 'tags a site turns off are not printed, the endpoint still is', ! str_contains( $plain, 'og:title' ) && str_contains( $plain, 'json+oembed' ) );
remove_all_filters( 'imagina_player_clip_open_graph' );

echo PHP_EOL . '# oEmbed' . PHP_EOL;

$GLOBALS['stub_current_user'] = 0;
$GLOBALS['stub_caps']         = array();
$ask = static fn( string $url, array $more = array() ) => $share->oembed( new WP_REST_Request( array( 'url' => $url ) + $more ) );

$video = $ask( 'https://example.test/?p=5&t=95&end=130&player=clase' );
$data  = (array) $video->get_data();
check( 'a video clip is a video', 200 === $video->get_status() && 'video' === ( $data['type'] ?? '' ) && '1.0' === ( $data['version'] ?? '' ) );
check( 'named for the clip, with its picture', 'Clase 3 (1:35–2:10)' === ( $data['title'] ?? '' ) && 'https://cdn.example.com/poster.jpg' === ( $data['thumbnail_url'] ?? '' ) );
check( 'in a frame of the clip’s own page', (bool) preg_match( '#<iframe src="https://example\.test/\?p=5(&\#038;|&amp;)t=95\1end=130\1player=clase\1imagina_embed=1"#', (string) ( $data['html'] ?? '' ) ), (string) ( $data['html'] ?? '' ) );
check( 'the picture’s shape', 640 === ( $data['width'] ?? 0 ) && 360 === ( $data['height'] ?? 0 ) );

$narrow = (array) $ask( 'https://example.test/?p=5&t=95&player=clase', array( 'maxwidth' => 320 ) )->get_data();
check( 'as narrow as the site asking wants', 320 === ( $narrow['width'] ?? 0 ) && 180 === ( $narrow['height'] ?? 0 ) );
$short = (array) $ask( 'https://example.test/?p=5&t=95&player=clase', array( 'maxheight' => 180 ) )->get_data();
check( 'and as short, narrower rather than cropped', 320 === ( $short['width'] ?? 0 ) && 180 === ( $short['height'] ?? 0 ), wp_json_encode( array( $short['width'] ?? null, $short['height'] ?? null ) ) );

$audio = (array) $ask( 'https://example.test/?p=5&t=40&player=podcast' )->get_data();
check( 'an audio clip is a frame of its own height', 'rich' === ( $audio['type'] ?? '' ) && 180 === ( $audio['height'] ?? 0 ) );

$locked = (array) $ask( 'https://example.test/?p=5&t=10&player=privada' )->get_data();
check( 'a player behind a rule is only a link: no frame for a stranger’s site', 'link' === ( $locked['type'] ?? '' ) && ! isset( $locked['html'] ) );

check( 'only JSON', 501 === $ask( 'https://example.test/?p=5&t=1', array( 'format' => 'xml' ) )->get_status() );
check( 'nothing for another site’s address', 404 === $ask( 'https://elsewhere.test/?p=5&t=1' )->get_status() );
check( 'nor for an address without a clip', 404 === $ask( 'https://example.test/?p=5' )->get_status() );

$GLOBALS['stub_posts_objects'][6] = new WP_Post( array( 'ID' => 6, 'post_status' => 'draft' ) + array( 'post_content' => $post->post_content ) );
$GLOBALS['stub_posts_objects'][7] = new WP_Post( array( 'ID' => 7, 'post_password' => 'x' ) + array( 'post_content' => $post->post_content ) );
check( 'nor for a draft', 404 === $ask( 'https://example.test/?p=6&t=1' )->get_status() );
check( 'nor for a post behind a password', 404 === $ask( 'https://example.test/?p=7&t=1' )->get_status() );
unset( $GLOBALS['stub_caps'] );

// Last of the card's checks: a constant cannot be taken back.
define( 'WPSEO_VERSION', '99.0' );
$with_seo = $share->card( $post, $clip, Clip::player( $post, 'clase' ) ?? array() );
check( 'with an SEO plugin writing the page’s tags, none of ours beside them', ! str_contains( $with_seo, 'og:' ) && ! str_contains( $with_seo, 'twitter:' ) && str_contains( $with_seo, 'json+oembed' ) );

echo PHP_EOL . '# The frame' . PHP_EOL;

$page = $share->embed_page( $post, Clip::player( $post, 'clase' ) ?? array() );
check( 'one player and nothing else', 1 === substr_count( $page, 'data-imagina-player=' ) && ! str_contains( $page, 'Episodio 7' ) );
check( 'with the plugin’s own styles and script only', str_contains( $page, 'frontend.css' ) && str_contains( $page, 'frontend.js' ) );
check( 'not to be indexed', str_contains( $page, '<meta name="robots" content="noindex">' ) );
check( 'and not following the page, which in a frame is nowhere', ! str_contains( $page, '&quot;sticky&quot;:true' ) );

echo PHP_EOL . '# In a browser' . PHP_EOL;

$browser = find_browser();

if ( '' === $browser ) {
	echo 'SKIP  no Chromium found' . PHP_EOL;
	echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
	exit( $failures ? 1 : 0 );
}

$workdir = $root . '/build/.clips-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

foreach ( (array) glob( $root . '/build/*.{js,css}', GLOB_BRACE ) as $asset ) {
	copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
}

$renderer = new PlayerRenderer();
$player   = $renderer->render( array( 'src' => 'https://cdn.example.com/clase.mp4', 'title' => 'Clase', 'videoSpeed' => 'yes' ) );
$second   = $renderer->render( array( 'src' => 'https://cdn.example.com/otra.mp4', 'title' => 'Otra' ) );

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
	if (e.target instanceof HTMLMediaElement) { e.stopImmediatePropagation(); }
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
	P.play = function () { var s = st(this); if (!s.p) { return Promise.resolve(); } s.p = false; this.dispatchEvent(new Event('play')); return Promise.resolve(); };
	P.pause = function () { var s = st(this); if (s.p) { return; } s.p = true; this.dispatchEvent(new Event('pause')); };
	P.load = function () {};
})();
window.imaginaPlayer = { restUrl: '', lazyInit: false, maxComputeBytes: 0, assetUrl: './', i18n: {} };
</script>
<div class="w imgp-block" id="clase">{$player}</div>
<div class="w imgp-block" id="otra">{$second}</div>
<script src="./frontend.js"></script>
<script>
function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
function tick(media, t) { media.currentTime = t; media.dispatchEvent(new Event('timeupdate')); }
(async function () {
	await wait(1200);
	var out = {};
	var root = document.querySelector('#clase .imgp');
	var media = root.querySelector('video');
	out.openedAt = media.currentTime;
	out.otherUntouched = document.querySelector('#otra video').currentTime;

	if (location.search.indexOf('form') < 0) {
		var fragment = location.search.indexOf(',') >= 0;
		var START = fragment ? 3 : 5;
		var END = fragment ? 9 : 12;
		await media.play();
		tick(media, START + 2);
		out.playingInside = !media.paused;
		// The last stretch is timed, not left to the next timeupdate.
		tick(media, END - 0.3);
		out.notYet = !media.paused;
		await wait(450);
		out.stoppedAtEnd = media.paused;
		var chip = root.querySelector('.imgp__resume--clip');
		out.chip = chip ? chip.querySelector('.imgp__resume-text').textContent : null;
		out.buttons = chip ? Array.prototype.map.call(chip.querySelectorAll('button'), function (b) { return b.textContent; }) : null;

		chip.querySelectorAll('button')[0].click();
		await wait(50);
		out.replayAt = media.currentTime;
		out.replayPlaying = !media.paused;
		out.chipGone = !root.querySelector('.imgp__resume--clip');
		tick(media, END - 0.01);
		out.stoppedAgain = media.paused;

		if (!fragment) {
			root.querySelector('.imgp__resume--clip').querySelectorAll('button')[1].click();
			await wait(50);
			out.keptPlaying = !media.paused;
			tick(media, END + 0.4);
			tick(media, END + 8);
			out.notStoppedAfterwards = !media.paused;
		} else {
			// Somebody who drags outside the clip has left it.
			root.querySelector('.imgp__resume--clip').querySelectorAll('button')[0].click();
			await wait(50);
			media.currentTime = 30;
			media.dispatchEvent(new Event('seeking'));
			tick(media, 30.5);
			tick(media, 31);
			out.leftClip = !media.paused && !root.querySelector('.imgp__resume--clip');
		}
	} else {
		// Choosing a clip, from the settings menu.
		media.currentTime = 63;
		root.querySelector('.imgp__vbtn--settings').click();
		await wait(50);
		var item = Array.prototype.find.call(root.querySelectorAll('.imgp__menuitem'), function (b) { return b.textContent === 'Share a clip'; });
		out.offered = !!item;
		item.click();
		await wait(500);
		var form = root.querySelector('.imgp__clip');
		out.form = !!form;
		var inputs = form.querySelectorAll('.imgp__clip-input');
		out.prefilled = [inputs[0].value, inputs[1].value];
		out.focused = document.activeElement === inputs[0];
		media.currentTime = 75;
		form.querySelectorAll('.imgp__clip-now')[1].click();
		out.nowSet = inputs[1].value;
		inputs[1].value = '0:30';
		form.querySelector('.imgp__clip-copy').click();
		await wait(50);
		var note = form.querySelector('.imgp__clip-note');
		out.refused = !note.hidden ? note.textContent : null;
		inputs[0].value = '1:03';
		inputs[1].value = '1m20s';
		form.querySelector('.imgp__clip-copy').click();
		await wait(300);
		var link = form.querySelector('.imgp__clip-link');
		var toast = root.querySelector('.imgp__toast');
		out.copied = toast && toast.textContent === 'Clip link copied' ? await navigator.clipboard.readText().catch(function () { return 'unreadable'; }) : (link && !link.hidden ? link.value : null);
	}

	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify(out);
	document.body.appendChild(pre);
})();
</script>
</body></html>
HTML;

$file = $workdir . '/clips.html';
file_put_contents( $file, $page );

$runner = $workdir . '/run.js';
file_put_contents(
	$runner,
	<<<'JS'
const { chromium } = require( 'playwright' );
( async () => {
	const browser = await chromium.launch( { executablePath: process.env.CHROMIUM_BIN } );
	const out = [];
	for ( const query of process.argv.slice( 3 ) ) {
		const context = await browser.newContext( { viewport: { width: 900, height: 900 } } );
		await context.grantPermissions( [ 'clipboard-read', 'clipboard-write' ] ).catch( () => undefined );
		const page = await context.newPage();
		await page.goto( 'file://' + process.argv[ 2 ] + query );
		await page.waitForFunction( () => /RESULT:/.test( document.body.innerText ), null, { timeout: 30000 } );
		out.push( ( await page.textContent( 'pre:last-of-type' ) ).replace( /^RESULT:/, '' ) );
		await context.close();
	}
	console.log( 'RESULTS:' + JSON.stringify( out ) );
	await browser.close();
} )().catch( ( error ) => { console.log( 'RUNNER:' + error.message ); process.exit( 1 ); } );
JS
);

$dom = (string) shell_exec(
	sprintf(
		'CHROMIUM_BIN=%s NODE_PATH=%s node %s %s %s %s %s 2>&1',
		escapeshellarg( $browser ),
		escapeshellarg( $root . '/node_modules' ),
		escapeshellarg( $runner ),
		escapeshellarg( $file ),
		escapeshellarg( '?t=5&end=12&player=clase' ),
		escapeshellarg( '?t=0:03,0:09' ),
		escapeshellarg( '?form=1' )
	)
);

exec( 'rm -rf ' . escapeshellarg( $workdir ) );

if ( ! preg_match( '/RESULTS:(\[.*\])/s', $dom, $matches ) ) {
	check( 'the pages reported', false, substr( trim( $dom ), 0, 300 ) );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

[ $clipped, $fragment, $chosen ] = array_map( static fn( $raw ) => (array) json_decode( (string) $raw, true ), (array) json_decode( $matches[1], true ) );
$show = static fn( $value ): string => (string) wp_json_encode( $value );

foreach ( array( $clipped, $fragment, $chosen ) as $result ) {
	if ( isset( $result['error'] ) ) {
		check( 'a page ran without an error', false, (string) $result['error'] );
	}
}

echo '## A clip link' . PHP_EOL;
check( 'opens the named player at the start', 5 === (int) ( $clipped['openedAt'] ?? -1 ) && 0 === (int) ( $clipped['otherUntouched'] ?? -1 ) );
check( 'plays through the inside of the clip', true === ( $clipped['playingInside'] ?? null ) && true === ( $clipped['notYet'] ?? null ) );
check( 'and stops at its end, on time', true === ( $clipped['stoppedAtEnd'] ?? null ) );
check( 'saying so', 'End of the clip · 0:05–0:12' === ( $clipped['chip'] ?? '' ), $show( $clipped['chip'] ?? null ) );
check( 'with the clip again or the rest', array( 'Replay clip', 'Keep watching' ) === ( $clipped['buttons'] ?? null ), $show( $clipped['buttons'] ?? null ) );
check( 'again goes back to the start and plays', 5 === (int) ( $clipped['replayAt'] ?? -1 ) && true === ( $clipped['replayPlaying'] ?? null ) && true === ( $clipped['chipGone'] ?? null ) );
check( 'and stops at the end once more', true === ( $clipped['stoppedAgain'] ?? null ) );
check( 'the rest plays on past the end', true === ( $clipped['keptPlaying'] ?? null ) && true === ( $clipped['notStoppedAfterwards'] ?? null ) );

echo '## Written as a media fragment' . PHP_EOL;
check( '`t=0:03,0:09` opens the first player at three seconds', 3 === (int) ( $fragment['openedAt'] ?? -1 ) );
check( 'and stops at nine', true === ( $fragment['stoppedAtEnd'] ?? null ) && 'End of the clip · 0:03–0:09' === ( $fragment['chip'] ?? '' ), $show( $fragment['chip'] ?? null ) );
check( 'until the viewer goes somewhere outside it', true === ( $fragment['leftClip'] ?? null ) );

echo '## Choosing a clip' . PHP_EOL;
check( 'offered in the settings menu', true === ( $chosen['offered'] ?? null ) && true === ( $chosen['form'] ?? null ) );
check( 'starting where the video is, half a minute long', array( '1:03', '1:33' ) === ( $chosen['prefilled'] ?? null ), $show( $chosen['prefilled'] ?? null ) );
check( 'the keyboard on the start', true === ( $chosen['focused'] ?? null ) );
check( '"Now" takes the second playing', '1:15' === ( $chosen['nowSet'] ?? '' ) );
check( 'an end before the start is refused, and said why', 'The end has to come after the start.' === ( $chosen['refused'] ?? '' ) );
check( 'the link it gives plays that clip of this player', is_string( $chosen['copied'] ?? null ) && (bool) preg_match( '/\?form=1&t=63&end=80&player=clase$/', (string) $chosen['copied'] ), $show( $chosen['copied'] ?? null ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
