<?php
/**
 * Counting how players are watched.
 *
 * Only worth having if the numbers are right and cannot be made up: a play
 * counted once, time watched that a clock wound forward cannot inflate, a
 * viewer's furthest point moved rather than added again, a click on the offer
 * and not on its close button — and a report nobody can send for a player or
 * a page the site never showed.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Render\PlayerRenderer;
use ImaginaPlayer\Render\PlaylistRenderer;
use ImaginaPlayer\Settings;
use ImaginaPlayer\Stats\Stats;
use ImaginaPlayer\Stats\StatsRepository;

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

function set_stats( array $stats ): void {
	$settings          = Settings::all();
	$settings['stats'] = array_replace( $settings['stats'], $stats );
	Settings::update( $settings );
}

/** The statements written since the last call, and forget them. */
function written(): array {
	$queries                 = $GLOBALS['stub_queries'] ?? array();
	$GLOBALS['stub_queries'] = array();
	return $queries;
}

function beacon( array $body ): WP_REST_Request {
	$request = new WP_REST_Request();
	$request->set_body( (string) json_encode( $body ) );
	return $request;
}

if ( ! class_exists( 'WP_Post' ) ) {
	final class WP_Post {
		public int $ID = 0;
		public string $post_content = '';
		public string $post_title = '';
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) { return $GLOBALS['stub_posts_objects'][ (int) $id ] ?? null; }
}

$src = 'https://example.test/clase.mp4';
$key = substr( md5( 'url_' . md5( $src ) ), 0, 12 );

echo '# The settings' . PHP_EOL;

check( 'counting is on to begin with', Stats::enabled() );
check( 'and a year and a bit is kept', 400 === (int) Settings::all()['stats']['keep_days'] );

echo PHP_EOL . '# What a page hands a player' . PHP_EOL;

$GLOBALS['stub_current_post'] = 42;
$client                       = Stats::client( $key, Stats::current_post() );
check( 'the player, the page and a token', is_array( $client ) && $key === $client['key'] && 42 === $client['post'] && 1 === preg_match( '/^[a-f0-9]{16}$/', $client['token'] ) );
check( 'a token belongs to its page', Stats::token( $key, 42 ) !== Stats::token( $key, 43 ) );
check( 'and to its player', Stats::token( $key, 42 ) !== Stats::token( substr( md5( 'att_7' ), 0, 12 ), 42 ) );
check( 'a name that is not one gets nothing', null === Stats::client( 'not-a-key', 42 ) );

unset( $GLOBALS['stub_current_post'] );
$GLOBALS['stub_queried_post'] = 9;
check( 'outside the loop, the page being shown', 9 === Stats::current_post() );
unset( $GLOBALS['stub_queried_post'] );

$_GET['elementor-preview'] = '42';
check( 'nor in Elementor’s preview of the page', null === Stats::client( $key, 42 ) );
unset( $_GET['elementor-preview'] );

set_stats( array( 'enabled' => false ) );
check( 'with counting off, nothing to report with', null === Stats::client( $key, 42 ) );
set_stats( array( 'enabled' => true ) );

echo PHP_EOL . '# What a report may say' . PHP_EOL;

check( 'a report for no player is refused', null === Stats::clean( array( 'key' => '../../etc' ) ) );

$clean = Stats::clean(
	array(
		'key'      => $key,
		'post'     => '42',
		'play'     => 'yes',
		'seconds'  => 999999,
		'complete' => 7,
		'from'     => -40,
		'to'       => 512,
		'duration' => 120,
		'clicks'   => array( '0' => 3, '2' => 999, 'x' => 4, '70' => 1, '5' => 0, '-1' => 2 ),
		'title'    => '<b>Clase</b> ' . str_repeat( 'a', 400 ),
	)
);
check( 'a play is one play', 1 === $clean['play'] );
check( 'time watched no more than the file could have played', 300.0 === $clean['seconds'], (string) $clean['seconds'] );
check( 'reaching the end is yes or no', 1 === $clean['complete'] );
check( 'how far, within the file', -1 === $clean['from'] && 99 === $clean['to'] );
check( 'clicks on layers that exist, at most twenty a report', array( 0 => 3, 2 => 20 ) === $clean['clicks'], json_encode( $clean['clicks'] ) );
check( 'a title without markup, and not a novel', ! str_contains( $clean['title'], '<' ) && 200 === mb_strlen( $clean['title'] ) );

$clean = Stats::clean( array( 'key' => $key, 'seconds' => -5, 'duration' => 1e9 ) );
check( 'nothing negative, and no file longer than a day', 0.0 === $clean['seconds'] && 86400.0 === $clean['duration'] && 0 === $clean['play'] );

echo PHP_EOL . '# A report arriving' . PHP_EOL;

$stats  = new Stats();
$token  = Stats::token( $key, 42 );
$report = array(
	'key'      => $key,
	'post'     => 42,
	'token'    => $token,
	'title'    => 'Clase',
	'duration' => 40,
	'play'     => 1,
	'seconds'  => 12,
	'from'     => -1,
	'to'       => 30,
	'clicks'   => array( 1 => 1 ),
);

written();
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$response = $stats->report( beacon( $report ) );
$queries  = written();
check( 'is taken, with nothing to say back', 204 === $response->get_status() );
check( 'as plain text, which is how a beacon sends it', 2 === count( $queries ) );
$insert = (string) ( $queries[0] ?? '' );
check( 'every number added to, never read and written back', str_contains( $insert, 'ON DUPLICATE KEY UPDATE value = value + VALUES(value)' ) );
check( 'a play, the seconds, the point reached and the press', str_contains( $insert, "'plays',0,1)" ) && str_contains( $insert, "'seconds',0,12)" ) && str_contains( $insert, "'reach',30,1)" ) && str_contains( $insert, "'click',1,1)" ) );
check( 'and nothing that was not there', ! str_contains( $insert, "'complete'" ) && 1 === substr_count( $insert, "'reach'" ) );
check( 'the name and length kept beside it', str_contains( (string) ( $queries[1] ?? '' ), 'imagina_player_stats_media' ) && str_contains( (string) ( $queries[1] ?? '' ), "'Clase'" ) );

$stats->report( beacon( array_merge( $report, array( 'play' => 0, 'seconds' => 8, 'from' => 30, 'to' => 55, 'clicks' => array() ) ) ) );
$insert = (string) ( written()[0] ?? '' );
check( 'further on, the same viewing moves its mark rather than adding one', str_contains( $insert, "'reach',30,-1)" ) && str_contains( $insert, "'reach',55,1)" ) && ! str_contains( $insert, "'plays'" ) );

$stats->report( beacon( array_merge( $report, array( 'play' => 0, 'seconds' => 0, 'from' => 55, 'to' => 20, 'clicks' => array() ) ) ) );
check( 'and never back', array() === array_filter( written(), static fn( $q ) => str_contains( (string) $q, "'reach'" ) ) );

$response = $stats->report( beacon( array_merge( $report, array( 'token' => Stats::token( $key, 43 ) ) ) ) );
check( 'a token for another page is refused', 400 === $response->get_status() && array() === written() );

$response = $stats->report( beacon( array_merge( $report, array( 'post' => 43 ) ) ) );
check( 'and so is moving a report to another page', 400 === $response->get_status() && array() === written() );

$response = $stats->report( beacon( array_merge( $report, array( 'key' => substr( md5( 'att_9' ), 0, 12 ) ) ) ) );
check( 'or to a player the page never showed', 400 === $response->get_status() && array() === written() );

set_stats( array( 'enabled' => false ) );
$response = $stats->report( beacon( $report ) );
check( 'with counting off, a page still cached is answered and ignored', 204 === $response->get_status() && array() === written() );
set_stats( array( 'enabled' => true ) );

add_filter( 'imagina_player_stats_network_limit', static fn() => 5 );
$codes = array();
for ( $i = 0; $i < 8; $i++ ) {
	$codes[] = $stats->report( beacon( $report ) )->get_status();
}
check( 'one network is counted, and stopped past its limit', in_array( 429, $codes, true ) && 204 === $codes[0], implode( ',', $codes ) );
$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
check( 'without stopping the next network', 204 === $stats->report( beacon( $report ) )->get_status() );
remove_all_filters( 'imagina_player_stats_network_limit' );
written();

echo PHP_EOL . '# Reading it back' . PHP_EOL;

check( 'where viewers stopped, as how many were still there', array( 1.0, 0.75, 0.75, 0.25 ) === array_slice( StatsRepository::retention( array( 1, 0, 2, 1 ) ), 0, 4 ) );
check( 'and nothing at all is not a hundred per cent', array( 0.0, 0.0 ) === StatsRepository::retention( array( 0, 0 ) ) );

$GLOBALS['stub_rows'] = array(
	array( 'media_key' => 'aaaaaaaaaaaa', 'post_id' => '5', 'metric' => 'plays', 'total' => '3', 'title' => 'A', 'duration' => '60' ),
	array( 'media_key' => 'aaaaaaaaaaaa', 'post_id' => '5', 'metric' => 'seconds', 'total' => '90', 'title' => 'A', 'duration' => '60' ),
	array( 'media_key' => 'bbbbbbbbbbbb', 'post_id' => '5', 'metric' => 'plays', 'total' => '8', 'title' => 'B', 'duration' => '0' ),
	array( 'media_key' => 'aaaaaaaaaaaa', 'post_id' => '7', 'metric' => 'click', 'total' => '2', 'title' => 'A', 'duration' => '60' ),
);
$summary = StatsRepository::summary( 30 );
check( 'a row a player and page, the most played first', 3 === count( $summary ) && 'bbbbbbbbbbbb' === $summary[0]['key'] && 8 === $summary[0]['plays'] );
check( 'with its totals', 3 === $summary[1]['plays'] && 90 === $summary[1]['seconds'] && 60.0 === $summary[1]['duration'] && 0 === $summary[1]['complete'] );
check( 'the same media on another page is another row', 7 === $summary[2]['post'] && 2 === $summary[2]['click'] && 0 === $summary[2]['plays'] );

$today                = gmdate( 'Y-m-d' );
$GLOBALS['stub_rows'] = array( array( 'day' => $today, 'total' => '4' ), array( 'day' => '1999-01-01', 'total' => '9' ) );
$daily                = StatsRepository::daily( 7 );
check( 'every day of the period, the empty ones too', 7 === count( $daily ) && 4 === $daily[ $today ] && 0 === array_sum( array_slice( $daily, 0, 6 ) ) );
check( 'in order, ending today', $today === array_key_last( $daily ) );

$GLOBALS['stub_rows'] = array(
	array( 'metric' => 'reach', 'slot' => '10', 'total' => '3' ),
	array( 'metric' => 'reach', 'slot' => '50', 'total' => '-1' ),
	array( 'metric' => 'reach', 'slot' => '400', 'total' => '9' ),
	array( 'metric' => 'click', 'slot' => '0', 'total' => '2' ),
);
$detail = StatsRepository::detail( $key, 42, 30 );
check( 'a mark moved out of a day that has gone is not a negative audience', 0 === $detail['reach'][50] && 3 === $detail['reach'][10] && 100 === count( $detail['reach'] ) );
check( 'the presses, by layer', array( 0 => 2 ) === $detail['clicks'] );
unset( $GLOBALS['stub_rows'] );

$cell = new ReflectionMethod( Stats::class, 'cell' );
$cell->setAccessible( true );
check( 'a title is never a formula in the spreadsheet', "'=HYPERLINK(\"x\")" === $cell->invoke( null, '=HYPERLINK("x")' ) && "'@SUM(A1)" === $cell->invoke( null, '@SUM(A1)' ) && 'Clase 1' === $cell->invoke( null, 'Clase 1' ) );

written();
StatsRepository::purge( 400 );
$purge = (string) ( written()[0] ?? '' );
check( 'forgetting what is older than a site keeps', str_contains( $purge, 'DELETE FROM wp_imagina_player_stats WHERE day < ' ) && str_contains( $purge, gmdate( 'Y-m-d', time() - 399 * DAY_IN_SECONDS ) ) );

echo PHP_EOL . '# Named from the page, not from the report' . PHP_EOL;

$page               = new WP_Post();
$page->ID           = 42;
$page->post_content = '<!-- wp:imagina/video-player {"src":"https://example.test/clase.mp4","layers":[{"type":"cta","at":50,"title":"Inscríbete","button":"Quiero","url":"https://example.test/curso"},{"type":"email","at":90,"button":"Enviar","list":"x"}]} /-->';
$GLOBALS['stub_posts_objects'][42] = $page;
$layers = Stats::layers( $key, 42 );
check( 'every layer of that player, in its order', 2 === count( $layers ) && 'Inscríbete' === $layers[0]['label'] && 'cta' === $layers[0]['type'] && 'email' === $layers[1]['type'], json_encode( $layers ) );
check( 'with the button for a name when there is no title', 'Enviar' === $layers[1]['label'] );
check( 'another player on the page has none to show', array() === Stats::layers( substr( md5( 'att_99' ), 0, 12 ), 42 ) );

echo PHP_EOL . '# On the page' . PHP_EOL;

$GLOBALS['stub_current_post'] = 42;
$renderer = new PlayerRenderer();
$html     = $renderer->render( array( 'src' => 'https://example.test/clase.mp3', 'title' => 'Clase' ) );
preg_match( '/data-imagina-player="([^"]+)"/', $html, $m );
$config = json_decode( html_entity_decode( $m[1] ?? '', ENT_QUOTES ), true );
check( 'a player carries what it reports with', isset( $config['stats'] ) && $config['layerKey'] === $config['stats']['key'] && 42 === $config['stats']['post'] && 'Clase' === $config['stats']['title'] );
check( 'and the token the site will check', Stats::token( (string) $config['layerKey'], 42 ) === ( $config['stats']['token'] ?? '' ) );

set_stats( array( 'enabled' => false ) );
$html = $renderer->render( array( 'src' => 'https://example.test/clase.mp3', 'title' => 'Clase' ) );
check( 'with counting off, nothing — so nothing is loaded either', ! str_contains( $html, 'token' ) );
set_stats( array( 'enabled' => true ) );

$playlist = ( new PlaylistRenderer() )->render(
	array(
		'items' => array(
			array( 'src' => 'https://example.test/uno.mp3', 'title' => 'Uno' ),
			array( 'src' => 'https://example.test/dos.mp3', 'title' => 'Dos' ),
		),
	)
);
preg_match( '/data-imagina-playlist="([^"]+)"/', $playlist, $m );
$tracks = json_decode( html_entity_decode( $m[1] ?? '', ENT_QUOTES ), true );
check( 'each track of a list reports as itself', is_array( $tracks ) && 2 === count( $tracks ) && $tracks[1]['stats']['key'] === $tracks[1]['layerKey'] && 'Dos' === $tracks[1]['stats']['title'] && $tracks[0]['stats']['key'] !== $tracks[1]['stats']['key'] );
unset( $GLOBALS['stub_current_post'] );

echo PHP_EOL . '# Kept and removed' . PHP_EOL;

$uninstall = (string) file_get_contents( $root . '/uninstall.php' );
check( 'uninstalling drops both tables', str_contains( $uninstall, "'imagina_player_stats', 'imagina_player_stats_media'" ) );
check( 'their version and the daily clean-up', str_contains( $uninstall, "'imagina_player_stats_db'" ) && str_contains( $uninstall, "wp_clear_scheduled_hook( 'imagina_player_purge_stats' )" ) );
$plugin = (string) file_get_contents( $root . '/src/Plugin.php' );
check( 'an update builds the tables, as activation does', 2 === substr_count( $plugin, 'Stats\\StatsRepository::install_table();' ) && str_contains( $plugin, "'|' . Stats\\StatsRepository::DB_VERSION" ) );

echo PHP_EOL . '# In a browser' . PHP_EOL;

$browser = find_browser();

if ( '' === $browser ) {
	echo 'SKIP  no Chromium found' . PHP_EOL;
} else {
	$workdir = $root . '/build/.stats-test';

	if ( ! is_dir( $workdir ) ) {
		mkdir( $workdir, 0777, true );
	}

	foreach ( (array) glob( $root . '/build/*.{js,css}', GLOB_BRACE ) as $asset ) {
		copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
	}

	$GLOBALS['stub_current_post'] = 42;
	$player = $renderer->render(
		array(
			'src'          => 'https://cdn.example.com/clase.mp3',
			'title'        => 'Clase',
			'layers'       => array(
				array( 'type' => 'cta', 'at' => 0, 'skip' => true, 'title' => 'Oferta', 'button' => 'Comprar', 'url' => 'https://example.com/curso' ),
			),
		)
	);
	preg_match( '/data-imagina-player="([^"]+)"/', $player, $m );
	$config = json_decode( html_entity_decode( $m[1] ?? '', ENT_QUOTES ), true );
	set_stats( array( 'enabled' => false ) );
	$silent = $renderer->render( array( 'src' => 'https://cdn.example.com/otra.mp3', 'title' => 'Otra' ) );
	set_stats( array( 'enabled' => true ) );
	unset( $GLOBALS['stub_current_post'] );

	$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
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
// Links pressed in the test go nowhere.
window.addEventListener('click', function (e) { e.preventDefault(); }, true);
window.sent = [];
// Which chunks are asked for: webpack adds a script for each.
window.loads = [];
(function () {
	var append = Node.prototype.appendChild;
	Node.prototype.appendChild = function (node) {
		if (node && node.src) { window.loads.push(node.src); }
		return append.call(this, node);
	};
})();
navigator.sendBeacon = function (url, blob) {
	blob.text().then(function (text) { window.sent.push({ url: url, body: JSON.parse(text) }); });
	return true;
};
(function () {
	var state = new WeakMap();
	function st(el) {
		var s = state.get(el);
		if (!s) { s = { t: 0, p: true, d: 200 }; state.set(el, s); }
		return s;
	}
	var P = HTMLMediaElement.prototype;
	Object.defineProperty(P, 'duration', { get: function () { return st(this).d; }, configurable: true });
	Object.defineProperty(P, 'currentTime', { get: function () { return st(this).t; }, set: function (v) { st(this).t = v; }, configurable: true });
	Object.defineProperty(P, 'paused', { get: function () { return st(this).p; }, configurable: true });
	Object.defineProperty(P, 'ended', { get: function () { return false; }, configurable: true });
	Object.defineProperty(P, 'readyState', { get: function () { return 4; }, configurable: true });
	P.play = function () { var s = st(this); if (!s.p) { return Promise.resolve(); } s.p = false; this.dispatchEvent(new Event('play')); return Promise.resolve(); };
	P.pause = function () { var s = st(this); if (s.p) { return; } s.p = true; this.dispatchEvent(new Event('pause')); };
	P.load = function () {};
})();
window.imaginaPlayer = { restUrl: 'https://site.test/wp-json/imagina-player/v1', lazyInit: false, maxComputeBytes: 0, assetUrl: './', i18n: {} };
</script>
<div style="width:720px" id="one">{$player}</div>
<div style="width:720px" id="two">{$silent}</div>
<script src="./frontend.js"></script>
<script>
function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
function tick(media, t) { media.currentTime = t; media.dispatchEvent(new Event('timeupdate')); }
function hide() {
	Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true });
	document.dispatchEvent(new Event('visibilitychange'));
	Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true });
}
function chunks() {
	return window.loads.filter(function (src) { return /imagina-stats/.test(src); }).length;
}
(async function () {
	await wait(800);
	var out = {};
	var media = document.querySelector('#one audio');
	var other = document.querySelector('#two audio');

	out.loadedBeforePlay = chunks();
	await media.play();
	await wait(600);
	out.loadedOnPlay = chunks();

	// Watching: 0 to 20 in ticks, a seek to 100 that is not watching, then
	// 100 to 104.
	for (var t = 0.25; t <= 20; t += 0.25) { tick(media, t); }
	media.dispatchEvent(new Event('seeking'));
	tick(media, 100);
	for (var u = 100.25; u <= 104; u += 0.25) { tick(media, u); }
	document.querySelector('#one .imgp__layer a').click();
	document.querySelector('#one .imgp__layer-close').click();
	hide();
	await wait(100);
	out.first = window.sent.slice();

	// Nothing new, nothing sent.
	hide();
	await wait(100);
	out.idle = window.sent.length;

	// Played again, to past nine tenths, then to its end.
	media.pause();
	await media.play();
	for (var v = 104.25; v <= 182; v += 0.25) { tick(media, v); }
	media.dispatchEvent(new Event('ended'));
	await wait(100);
	out.second = window.sent.slice(out.first.length);

	// A browser that refuses the beacon, or throws, still gets the report
	// out, as an ordinary request that outlives the page.
	window.fetched = [];
	navigator.sendBeacon = function () { throw new TypeError('refused'); };
	window.fetch = function (url, init) { window.fetched.push({ url: url, keepalive: init.keepalive, body: JSON.parse(init.body) }); return Promise.resolve(new Response(null, { status: 204 })); };
	await media.play();
	tick(media, 1); tick(media, 1.25); tick(media, 1.5);
	hide();
	await wait(100);
	out.fetched = window.fetched;

	// A player on a site that does not count never loads it.
	var before = chunks();
	await other.play();
	tick(other, 1);
	await wait(400);
	hide();
	await wait(100);
	out.silentLoads = chunks() - before;
	out.total = window.sent.length;

	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify(out);
	document.body.appendChild(pre);
})();
</script>
</body></html>
HTML;

	$file = $workdir . '/stats.html';
	file_put_contents( $file, $page );

	$runner = $workdir . '/run.js';
	file_put_contents(
		$runner,
		<<<'JS'
const { chromium } = require( 'playwright' );
( async () => {
	const browser = await chromium.launch( { executablePath: process.env.CHROMIUM_BIN } );
	const page = await browser.newPage();
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
		$first  = $out['first'][0]['body'] ?? array();
		$second = $out['second'][0]['body'] ?? array();

		check( "nothing is downloaded until somebody presses play", 0 === $out["loadedBeforePlay"] && 1 === $out["loadedOnPlay"], $out["loadedBeforePlay"] . "/" . $out["loadedOnPlay"] );
		check( 'one report when the page is put away, to the site', 1 === count( $out['first'] ) && 'https://site.test/wp-json/imagina-player/v1/stats' === ( $out['first'][0]['url'] ?? '' ) );
		check( 'with the player, the page and the token', $first['key'] === $config['layerKey'] && 42 === $first['post'] && '' !== $first['token'] && 'Clase' === $first['title'] );
		check( 'one play', 1 === $first['play'] );
		check( 'the time watched, not the time skipped', $first['seconds'] >= 23 && $first['seconds'] <= 25, (string) $first['seconds'] );
		check( 'how far it got, as a first mark', -1 === $first['from'] && 52 === $first['to'], $first['from'] . '→' . $first['to'] );
		check( 'the offer pressed, and its close button not', array( '0' => 1 ) === $first['clicks'], json_encode( $first['clicks'] ) );
		check( 'not the end yet', 0 === $first['complete'] && 200 === (int) $first['duration'] );
		check( 'nothing new, nothing sent', 1 === $out['idle'] );
		check( 'playing on is not another play', 0 === $second['play'] );
		check( 'its mark moved on to the end', 52 === $second['from'] && 99 === $second['to'], ( $second['from'] ?? '' ) . '→' . ( $second['to'] ?? '' ) );
		check( 'watched to the end, once', 1 === $second['complete'] && 1 === count( $out['second'] ) );
		check( 'a beacon that throws is sent as a request instead', 1 === count( $out['fetched'] ) && true === $out['fetched'][0]['keepalive'] && $first['key'] === $out['fetched'][0]['body']['key'] );
		check( 'a player on a site that does not count never loads it', 0 === $out['silentLoads'] && 2 === $out['total'] );
	}
}

echo PHP_EOL . '# Last: nothing counted from the editor' . PHP_EOL;

define( 'REST_REQUEST', true );
check( 'a player drawn for the editor reports nothing', null === Stats::client( $key, 42 ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
