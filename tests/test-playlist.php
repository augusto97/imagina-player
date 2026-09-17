<?php
/**
 * Playlists.
 *
 * The property worth protecting here is that the list works before any
 * JavaScript does. Every item is a link to its own file, so a visitor with a
 * blocked bundle, a reader mode, or a search engine crawler still finds a page
 * of playable tracks rather than a list of dead text. The runtime's whole job
 * is to catch the click and hand it to the player already on the page.
 */

$plugin = dirname( __DIR__ ) . '/';

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

require $plugin . 'tests/bootstrap.php';

use ImaginaPlayer\Render\PlaylistRenderer;

echo PHP_EOL . '# What a playlist renders' . PHP_EOL;

$renderer = new PlaylistRenderer();

$items = array(
	array( 'src' => 'https://example.test/wp-content/uploads/1.mp3', 'title' => 'Uno', 'artist' => 'Imagina', 'duration' => 185 ),
	array( 'src' => 'https://example.test/wp-content/uploads/2.mp3', 'title' => 'Dos', 'duration' => 61 ),
	array( 'src' => 'https://example.test/wp-content/uploads/3.mp3', 'title' => 'Tres' ),
);

$html = $renderer->render( array( 'items' => $items, 'heading' => 'El curso' ) );

check( 'the playlist renders', str_contains( $html, 'imgp-playlist' ), substr( $html, 0, 120 ) );
check( 'the heading is shown', str_contains( $html, 'El curso' ) );
check( 'every track is listed', 3 === substr_count( $html, 'imgp-playlist__link' ), (string) substr_count( $html, 'imgp-playlist__link' ) );
check( 'the first track is the one loaded', str_contains( $html, 'imgp-playlist__item is-current' ) );

check(
	'a real player is rendered, not a placeholder',
	str_contains( $html, 'data-imagina-player' ),
	'the playlist drives the ordinary player rather than reimplementing one'
);

// The property this whole design exists for.
check(
	'each item is a link to its own file',
	3 === substr_count( $html, 'href="https://example.test/wp-content/uploads/' ),
	'without JavaScript, clicking a track must still play it'
);

check( 'durations are shown as minutes and seconds', str_contains( $html, '3:05' ) && str_contains( $html, '1:01' ), $html );
check( 'a track with no known duration shows none', 2 === substr_count( $html, 'imgp-playlist__time' ), (string) substr_count( $html, 'imgp-playlist__time' ) );
check( 'the tracks reach the client for swapping', str_contains( $html, 'data-imagina-playlist' ) );

$grid = $renderer->render( array( 'items' => $items, 'layout' => 'grid' ) );

check( 'a grid asks for the grid', str_contains( $grid, 'imgp-playlist--grid' ) );
check( 'and a list for the list', str_contains( $html, 'imgp-playlist--list' ) );
check( 'an unknown layout falls back to a list', str_contains( $renderer->render( array( 'items' => $items, 'layout' => 'carousel' ) ), 'imgp-playlist--list' ) );

$empty = $renderer->render( array( 'items' => array() ) );

check( 'an empty playlist says so rather than rendering nothing', str_contains( $empty, 'imgp--empty' ), $empty );

echo PHP_EOL . '# What does not survive' . PHP_EOL;

$hostile = PlaylistRenderer::sanitize_items(
	array(
		array( 'src' => 'javascript:alert(1)', 'title' => 'x' ),
		array( 'src' => '', 'title' => 'sin archivo' ),
		array( 'src' => 'https://example.test/ok.mp3', 'title' => '<img src=x onerror=alert(1)>' ),
		'not an array',
		array( 'src' => 'https://example.test/neg.mp3', 'title' => 'Negativa', 'duration' => -50 ),
	)
);

// Five went in; three of them are not tracks: a javascript: URL, an item with
// no file, and something that is not an array at all.
check( 'only the two real tracks survive', 2 === count( $hostile ), wp_json_encode( array_column( $hostile, 'src' ) ) );
check( 'and a javascript: source is not one of them', ! in_array( 'javascript:alert(1)', array_column( $hostile, 'src' ), true ) );
check( 'so is an item with no file', ! in_array( 'sin archivo', array_column( $hostile, 'title' ), true ) );
check( 'a tag in a title does not survive', ! str_contains( $hostile[0]['title'], '<img' ), $hostile[0]['title'] );
check( 'a negative duration becomes zero', 0.0 === $hostile[1]['duration'], (string) $hostile[1]['duration'] );

$injected = $renderer->render(
	array(
		'items' => array( array( 'src' => 'https://example.test/a.mp3', 'title' => '"><script>alert(1)</script>' ) ),
	)
);

check( 'a title cannot break out of the markup', ! str_contains( $injected, '<script>alert' ), $injected );

echo PHP_EOL . '# The video layouts' . PHP_EOL;

$videos = array(
	array( 'src' => 'https://example.test/wp-content/uploads/a.mp4', 'title' => 'Primera clase', 'thumbnail' => 'https://example.test/a.jpg', 'duration' => 125 ),
	array( 'src' => 'https://example.test/wp-content/uploads/b.mp4', 'title' => 'Segunda clase', 'duration' => 61 ),
	array( 'src' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'title' => 'En YouTube' ),
);

$side   = $renderer->render( array( 'items' => $videos, 'layout' => 'side' ) );
$rail   = $renderer->render( array( 'items' => $videos, 'layout' => 'rail' ) );
$slider = $renderer->render( array( 'items' => $videos, 'layout' => 'slider' ) );

check( 'the list beside the video asks for its layout', str_contains( $side, 'imgp-playlist--side' ) );
check( 'so does the rail', str_contains( $rail, 'imgp-playlist--rail' ) );
check( 'and the slider', str_contains( $slider, 'imgp-playlist--slider' ) );
check( 'the slider gets arrows, hidden until the cards overflow', preg_match( '/imgp-playlist__nav--prev"[^>]*hidden/', $slider ) === 1 && preg_match( '/imgp-playlist__nav--next"[^>]*hidden/', $slider ) === 1 );
check( 'the rail gets none', ! str_contains( $rail, 'imgp-playlist__nav' ) );
check( 'a video item is marked as one, with a play glyph over its picture', 3 === substr_count( $rail, 'imgp-playlist__cover--video' ), (string) substr_count( $rail, 'imgp-playlist__cover--video' ) );
check( 'a video with no picture of its own gets a blank one rather than none', str_contains( $rail, 'imgp-playlist__cover--blank imgp-playlist__cover--video' ) );
check( 'a YouTube item gets YouTube’s still', str_contains( $rail, 'img.youtube.com/vi/dQw4w9WgXcQ' ) || str_contains( $rail, 'i.ytimg.com/vi/dQw4w9WgXcQ' ), $rail );
check( 'in a sideways layout the length sits on the picture', preg_match( '/imgp-playlist__cover[^>]*>\s*<img[^>]*>\s*<span class="imgp-playlist__time">2:05<\/span>/', $rail ) === 1 );
check( 'in the list it sits after the words', preg_match( '/imgp-playlist__text">.*?<\/span>\s*<\/span>\s*<span class="imgp-playlist__time">2:05/s', $side ) === 1 );
check( 'the first video is the player, with its poster', str_contains( $side, 'imgp--video' ) && str_contains( $side, 'imgp__poster' ) && str_contains( $side, 'https://example.test/a.jpg' ) );

preg_match( '/data-imagina-playlist="([^"]+)"/', $rail, $m );
$data = json_decode( html_entity_decode( $m[1] ?? '[]' ), true );

check( 'the runtime is told which item is a provider’s, and its name for it', 'youtube' === ( $data[2]['provider'] ?? '' ) && 'dQw4w9WgXcQ' === ( $data[2]['providerId'] ?? '' ), wp_json_encode( $data[2] ?? null ) );
check( 'and a file item carries no provider', ! isset( $data[0]['provider'] ) );
check( 'each item carries its poster for the switch', 'https://example.test/a.jpg' === ( $data[0]['poster'] ?? '' ), wp_json_encode( $data[0] ?? null ) );

echo PHP_EOL . '# The chunk stays out of everything else' . PHP_EOL;

$core = (string) file_get_contents( $plugin . 'build/frontend.js' );

check( 'the playlist chunk exists', is_readable( $plugin . 'build/imagina-playlist.js' ) );
check(
	'and its code is not in the core bundle',
	! str_contains( $core, 'imagina-player-playlist' ),
	'a page with no playlist should not carry playlist code'
);
check( 'but the core knows how to fetch it', str_contains( $core, 'imagina-playlist' ) );

$chunk = (string) @file_get_contents( $plugin . 'build/imagina-playlist.js' );

check( 'the chunk is where the resume key lives', str_contains( $chunk, 'imagina-player-playlist' ), 'otherwise the check above passes for the wrong reason' );

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
	echo 'SKIP  no Chromium found; the list was not driven' . PHP_EOL;
} else {
	$workdir = $plugin . 'build/.playlist-test';

	if ( ! is_dir( $workdir ) ) {
		mkdir( $workdir, 0777, true );
	}

	foreach ( (array) glob( $plugin . 'build/*.{js,css}', GLOB_BRACE ) as $asset ) {
		copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
	}

	$many = array();

	for ( $i = 1; $i <= 8; $i++ ) {
		$many[] = array( 'src' => "https://example.test/wp-content/uploads/v{$i}.mp4", 'title' => "Clase {$i}", 'thumbnail' => "https://example.test/v{$i}.jpg", 'duration' => 60 * $i );
	}

	$mixed = array(
		array( 'src' => 'https://example.test/wp-content/uploads/v1.mp4', 'title' => 'Archivo', 'thumbnail' => 'https://example.test/v1.jpg' ),
		array( 'src' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'title' => 'En YouTube' ),
	);

	$slider_html = $renderer->render( array( 'items' => $many, 'layout' => 'slider' ) );
	$mixed_html  = $renderer->render( array( 'items' => $mixed, 'layout' => 'rail' ) );

	$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>html,body{margin:0}#a,#b{width:640px}</style>
</head><body><div id="a">{$slider_html}</div><div id="b">{$mixed_html}</div>
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
setTimeout(function () {
	var out = {};
	var a = document.querySelector('#a .imgp-playlist');
	var list = a.querySelector('.imgp-playlist__items');
	var next = a.querySelector('.imgp-playlist__nav--next');
	var prev = a.querySelector('.imgp-playlist__nav--prev');
	out.overflowing = list.scrollWidth > list.clientWidth;
	out.arrowsShown = !next.hidden && !prev.hidden;
	out.prevDisabledAtStart = prev.disabled;
	var media = a.querySelector('.imgp__media');
	var poster = a.querySelector('.imgp__poster img');
	out.srcBefore = media.currentSrc || media.src;
	// The fifth card: off screen to the right.
	a.querySelectorAll('.imgp-playlist__link')[4].click();
	setTimeout(function () {
		out.srcAfter = media.src;
		out.posterAfter = poster ? poster.src : '';
		out.currentAfter = a.querySelector('.imgp-playlist__item.is-current .imgp-playlist__title').textContent;
		out.scrolledToIt = list.scrollLeft > 0;
		out.notStarted = !a.querySelector('.imgp').classList.contains('is-started');
		// Mixed: a YouTube item under a file player is left to its link.
		var b = document.querySelector('#b .imgp-playlist');
		var yt = b.querySelectorAll('.imgp-playlist__link')[1];
		var prevented = null;
		yt.addEventListener('click', function (e) { prevented = e.defaultPrevented; e.preventDefault(); });
		yt.click();
		out.youtubeLeftToLink = prevented === false;
		var pre = document.createElement('pre');
		pre.textContent = 'RESULT:' + JSON.stringify(out);
		document.body.appendChild(pre);
	}, 700);
}, 900);
</script>
</body></html>
HTML;

	$file_path = $workdir . '/playlist.html';
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
	} else {
		$r = json_decode( html_entity_decode( $matches[1] ), true );

		if ( isset( $r['error'] ) ) {
			check( 'the probe ran without throwing', false, (string) $r['error'] );
		} else {
			check( 'eight cards overflow a slider 640 pixels wide', true === ( $r['overflowing'] ?? false ) );
			check( 'so the arrows are shown', true === ( $r['arrowsShown'] ?? false ) );
			check( 'with the back arrow disabled at the start', true === ( $r['prevDisabledAtStart'] ?? false ) );
			check( 'pressing the fifth card loads its file', str_ends_with( (string) ( $r['srcAfter'] ?? '' ), '/v5.mp4' ), (string) ( $r['srcAfter'] ?? '' ) );
			check( 'and its poster', str_ends_with( (string) ( $r['posterAfter'] ?? '' ), '/v5.jpg' ), (string) ( $r['posterAfter'] ?? '' ) );
			check( 'and marks it as current', 'Clase 5' === ( $r['currentAfter'] ?? '' ), (string) ( $r['currentAfter'] ?? '' ) );
			check( 'and scrolls the row to it', true === ( $r['scrolledToIt'] ?? false ) );
			check( 'the picture is not started again until it plays', true === ( $r['notStarted'] ?? false ) );
			check( 'a YouTube item under a file player is left to its link', true === ( $r['youtubeLeftToLink'] ?? false ) );
		}
	}

	/*
	 * A list of YouTube videos switches inside YouTube's own frame, through
	 * its API, without rebuilding the player. Driven against a stand-in for
	 * that API which records what it is told.
	 */
	$tube = array(
		array( 'src' => 'https://www.youtube.com/watch?v=aaaaaaaaaaa', 'title' => 'Primero' ),
		array( 'src' => 'https://www.youtube.com/watch?v=bbbbbbbbbbb', 'title' => 'Segundo' ),
		array( 'src' => 'https://www.youtube.com/watch?v=ccccccccccc', 'title' => 'Tercero' ),
	);

	$tube_html = $renderer->render( array( 'items' => $tube, 'layout' => 'side' ) );

	$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>html,body{margin:0}#host{width:900px}</style>
</head><body><div id="host">{$tube_html}</div>
<script>
window.addEventListener('error', function (e) {
	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify({ error: e.message + ' @' + e.lineno });
	document.body.appendChild(pre);
});
window.__yt = [];
window.YT = {
	Player: function (element, options) {
		var self = this;
		element.appendChild(document.createElement('iframe'));
		window.__yt.push('new:' + options.videoId);
		this.playVideo = function () { window.__yt.push('play'); options.events.onStateChange({ data: 1 }); };
		this.pauseVideo = function () { window.__yt.push('pause'); options.events.onStateChange({ data: 2 }); };
		this.loadVideoById = function (id) { window.__yt.push('load:' + id); options.events.onStateChange({ data: 1 }); };
		this.cueVideoById = function (id) { window.__yt.push('cue:' + id); };
		this.seekTo = function () {};
		this.setVolume = function () {};
		this.mute = function () {};
		this.unMute = function () {};
		this.setPlaybackRate = function () {};
		this.getCurrentTime = function () { return 1; };
		this.getDuration = function () { return 200; };
		this.getPlayerState = function () { return 1; };
		this.destroy = function () {};
		setTimeout(function () { options.events.onReady({ target: self }); }, 10);
	}
};
window.imaginaPlayer = { restUrl: '', lazyInit: false, maxComputeBytes: 0, assetUrl: './', i18n: {} };
</script>
<script src="./frontend.js"></script>
<script>
setTimeout(function () {
	var out = {};
	var links = document.querySelectorAll('.imgp-playlist__link');
	// Press play on the first, so the frame exists; then the third.
	document.querySelector('.imgp__bigplay, .imgp__play').click();
	setTimeout(function () {
		out.built = window.__yt.slice();
		links[2].click();
		setTimeout(function () {
			out.after = window.__yt.slice(out.built.length);
			out.current = document.querySelector('.imgp-playlist__item.is-current .imgp-playlist__title').textContent;
			out.title = document.querySelector('.imgp__title') ? document.querySelector('.imgp__title').textContent : '';
			out.frames = document.querySelectorAll('iframe').length;
			var pre = document.createElement('pre');
			pre.textContent = 'RESULT:' + JSON.stringify(out);
			document.body.appendChild(pre);
		}, 600);
	}, 1200);
}, 900);
</script>
</body></html>
HTML;

	$workdir = $plugin . 'build/.playlist-tube-test';

	if ( ! is_dir( $workdir ) ) {
		mkdir( $workdir, 0777, true );
	}

	foreach ( (array) glob( $plugin . 'build/*.{js,css}', GLOB_BRACE ) as $asset ) {
		copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
	}

	$file_path = $workdir . '/tube.html';
	file_put_contents( $file_path, $page );

	$dom = (string) shell_exec(
		sprintf(
			'%s --headless=new --no-sandbox --disable-gpu --window-size=1000,900 --virtual-time-budget=9000 --dump-dom %s 2>/dev/null',
			escapeshellarg( $browser ),
			escapeshellarg( 'file://' . $file_path )
		)
	);

	if ( getenv( 'PLAYLIST_DEBUG' ) ) {
		copy( $file_path, (string) getenv( 'PLAYLIST_DEBUG' ) );
		file_put_contents( (string) getenv( 'PLAYLIST_DEBUG' ) . '.dom', $dom );
	}

	exec( 'rm -rf ' . escapeshellarg( $workdir ) );

	if ( ! preg_match( '/RESULT:(\{.*?\})</s', $dom, $matches ) ) {
		check( 'the YouTube page reported', false, 'no result' );
	} else {
		$r = json_decode( html_entity_decode( $matches[1] ), true );

		if ( isset( $r['error'] ) ) {
			check( 'the YouTube probe ran without throwing', false, (string) $r['error'] );
		} else {
			check( 'pressing play builds YouTube’s frame for the first video', in_array( 'new:aaaaaaaaaaa', (array) ( $r['built'] ?? array() ), true ), wp_json_encode( $r['built'] ?? null ) );
			check( 'pressing the third item loads it into the same frame', array( 'load:ccccccccccc' ) === array_values( array_filter( (array) ( $r['after'] ?? array() ), static fn( $e ) => str_starts_with( (string) $e, 'load:' ) || str_starts_with( (string) $e, 'new:' ) ) ), wp_json_encode( $r['after'] ?? null ) );
			check( 'one frame, not two', 1 === (int) ( $r['frames'] ?? 0 ), (string) ( $r['frames'] ?? '?' ) );
			check( 'and the list and the title follow', 'Tercero' === ( $r['current'] ?? '' ) && 'Tercero' === ( $r['title'] ?? '' ), wp_json_encode( array( $r['current'] ?? null, $r['title'] ?? null ) ) );
		}
	}
}

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "{$failures} check(s) failed." . PHP_EOL;
	exit( 1 );
}
echo 'All checks passed.' . PHP_EOL;
