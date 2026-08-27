<?php
/**
 * The video shell, in a real browser, over real HTTP.
 *
 * Over HTTP rather than from a `file://` page because the thing most likely to
 * break here cannot be seen any other way: the video chrome ships as a separate
 * webpack chunk, and the browser has to work out for itself where to fetch it
 * from. Get that wrong and nothing throws — the video just quietly falls back
 * to native controls on every site in the world.
 *
 * So this serves the built bundle the way WordPress does, renders the real
 * markup, and asks the browser what it actually loaded.
 */

$plugin = dirname( __DIR__ ) . '/';

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

require $plugin . 'tests/bootstrap.php';

use ImaginaPlayer\Render\PlayerRenderer;

function find_browser(): string {
	$candidates = array_filter(
		array_merge(
			array( (string) getenv( 'CHROMIUM_BIN' ) ),
			glob( '/opt/pw-browsers/chromium_headless_shell-*/chrome-linux/headless_shell' ) ?: array(),
			glob( '/opt/pw-browsers/chromium-*/chrome-linux/chrome' ) ?: array()
		)
	);

	foreach ( $candidates as $candidate ) {
		if ( '' !== $candidate && is_executable( $candidate ) ) {
			return $candidate;
		}
	}

	foreach ( array( 'chromium', 'chromium-browser', 'google-chrome' ) as $name ) {
		$found = trim( (string) shell_exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null' ) );

		if ( '' !== $found ) {
			return $found;
		}
	}

	return '';
}

/* ------------------------------------------------------------------ *
 * Part one: the markup, straight from the renderer.
 * ------------------------------------------------------------------ */

echo PHP_EOL . '# The rendered shell' . PHP_EOL;

$renderer = new PlayerRenderer();

// A plain URL rather than an attachment: the media *kind* is what drives every
// decision under test, and it comes from the file, not from the post.
$video = $renderer->render(
	array(
		'src'         => 'https://example.test/wp-content/uploads/lesson.mp4',
		'title'       => 'Clase 1',
		'artist'      => 'Imagina',
		'poster'      => 'https://example.test/poster.jpg',
		'aspectRatio' => '16:9',
	)
);

// Nothing below means anything if the renderer bailed out.
check( 'the video renders at all', ! str_contains( $video, 'imgp--empty' ), substr( $video, 0, 120 ) );

check( 'a video is marked as one', str_contains( $video, 'imgp--video' ) );
check( 'and gets the theater stage', str_contains( $video, 'imgp__stage' ) );
check( 'the stage carries the ratio so the box is sized before the video loads', str_contains( $video, '--imgp-ratio:16 / 9' ), $video );
check( 'the media element is inside the stage, not beside it', (bool) preg_match( '#imgp__stage.*?<video#s', $video ) );
check( 'there is a play button in the middle', str_contains( $video, 'imgp__bigplay' ) );
check( 'the poster is a real image, so it can be prioritised', str_contains( $video, 'fetchpriority="high"' ) );
check( 'the overlay slot exists for later layers', str_contains( $video, 'imgp__layers' ) );
check( 'full screen and picture-in-picture are rendered', str_contains( $video, 'imgp__vbtn--fullscreen' ) && str_contains( $video, 'imgp__vbtn--pip' ) );
check( 'and start hidden, for the browser to reveal what it supports', (bool) preg_match( '#imgp__vbtn--pip[^>]*hidden#', $video ) );
check( 'a video always gets a scrubber', str_contains( $video, 'imgp__scrubber' ) );
check( 'and never a waveform canvas', ! str_contains( $video, 'imgp__wave' ), 'a waveform means downloading the audio of a video nobody may play' );
check( 'no peaks are measured for it', ! str_contains( $video, 'data-peaks' ) );
check( 'the video config reaches the client', str_contains( $video, '&quot;video&quot;' ), 'the module keys off this' );

// Audio must be untouched by any of it.
$audio = $renderer->render(
	array(
		'src'   => 'https://example.test/wp-content/uploads/episode.mp3',
		'title' => 'Episodio 1',
	)
);

echo PHP_EOL . '# Audio is left alone' . PHP_EOL;

check( 'audio gets no stage', ! str_contains( $audio, 'imgp__stage' ) );
check( 'audio gets no video config', ! str_contains( $audio, '&quot;video&quot;' ), 'this is what keeps the video chunk unloaded' );
check( 'audio keeps its waveform', str_contains( $audio, 'imgp__wave' ) );
check( 'audio is marked as audio', str_contains( $audio, 'imgp--audio' ) );

/* ------------------------------------------------------------------ *
 * Part two: hardening.
 * ------------------------------------------------------------------ */

echo PHP_EOL . '# Making the file harder to walk off with' . PHP_EOL;

check( 'the browser download button is taken off', str_contains( $video, 'controlslist="nodownload' ), $video );
check( 'and casting the raw file to a device is refused', str_contains( $video, 'disableremoteplayback' ) );

// Offering a download and then hiding the browser's own would be theatre.
$with_download = $renderer->render(
	array(
		'src'          => 'https://example.test/wp-content/uploads/lesson.mp4',
		'showDownload' => 'yes',
	)
);

check( 'the download case renders too', ! str_contains( $with_download, 'imgp--empty' ) );

check(
	'unless a download was deliberately offered, and then nothing is hidden',
	! str_contains( $with_download, 'controlslist' ),
	'hiding the browser download next to our own download link would be theatre'
);

$ratio_cases = array(
	'4:3'        => '4:3',
	'9/16'       => '9:16',
	'1:1'        => '1:1',
	'nonsense'   => '16:9',
	'1:900'      => '16:9',
	'0:0'        => '16:9',
	'16:9; }--x' => '16:9',
);

echo PHP_EOL . '# The aspect ratio reaches CSS, so it is rebuilt, not escaped' . PHP_EOL;

foreach ( $ratio_cases as $input => $expected ) {
	check(
		sprintf( '%s becomes %s', var_export( $input, true ), $expected ),
		$expected === ImaginaPlayer\Player\Attributes::sanitize_ratio( (string) $input )
	);
}

/* ------------------------------------------------------------------ *
 * Part three: the browser.
 * ------------------------------------------------------------------ */

$browser = find_browser();

if ( '' === $browser ) {
	echo PHP_EOL . 'SKIP  no browser available; the chunk load is not checked' . PHP_EOL;
	echo PHP_EOL . ( $failures ? "{$failures} check(s) failed." . PHP_EOL : 'All checks passed.' . PHP_EOL );
	exit( $failures ? 1 : 0 );
}

echo PHP_EOL . '# In a browser, over HTTP' . PHP_EOL;

// A tiny real MP4, so play() is not rejected for want of a decodable file.
$mp4 = base64_decode(
	'AAAAIGZ0eXBpc29tAAACAGlzb21pc28yYXZjMW1wNDEAAAAIZnJlZQAAAAhtZGF0AAAC721vb3YAAABsbXZoZAAAAAAAAAAAAAAAAAAAA+gAAAPoAAEAAAEAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAgAAAAAA'
);

$docroot = sys_get_temp_dir() . '/imgp-video-' . bin2hex( random_bytes( 4 ) );
mkdir( $docroot . '/build', 0777, true );

foreach ( glob( $plugin . 'build/*.{js,css}', GLOB_BRACE ) as $asset ) {
	copy( $asset, $docroot . '/build/' . basename( $asset ) );
}

file_put_contents( $docroot . '/lesson.mp4', $mp4 );

$markup = str_replace( 'https://example.test/wp-content/uploads/lesson.mp4', '/lesson.mp4', $video );
$markup = str_replace( 'https://example.test/poster.jpg', '/poster.png', $markup );

// A 1x1 PNG, so the poster resolves rather than erroring.
file_put_contents(
	$docroot . '/poster.png',
	base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' )
);

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="/build/style-frontend.css">
<style>body{margin:0}#host{width:640px}</style>
</head><body>
<div id="host">{$markup}</div>
<script>
window.imaginaPlayer = { restUrl: '', lazyInit: false, maxComputeBytes: 0, i18n: {}, assetUrl: ASSET_URL };
window.__requested = [];
// Record every subresource the page fetches, so the chunk request can be seen.
new PerformanceObserver(function (list) {
	for (const entry of list.getEntries()) { window.__requested.push(entry.name); }
}).observe({ type: 'resource', buffered: true });
</script>
<script src="/build/frontend.js"></script>
<script>
setTimeout(function () {
	var root = document.querySelector('.imgp');
	var media = document.querySelector('.imgp__media');
	var fs = document.querySelector('.imgp__vbtn--fullscreen');
	var pip = document.querySelector('.imgp__vbtn--pip');
	var stage = document.querySelector('.imgp__stage');
	var chrome = document.querySelector('.imgp__chrome');

	var result = {
		enhanced: !!root && root.classList.contains('is-enhanced'),
		// The chunk: did the browser find it, and did the module actually run?
		chunkRequested: window.__requested.some(function (u) { return u.indexOf('imagina-video') !== -1; }),
		chunkFailed: window.__requested.some(function (u) {
			return u.indexOf('imagina-video') !== -1 && u.indexOf('/build/') === -1;
		}),
		// The module reveals only what this browser supports. Headless Chromium
		// has fullscreen; that it un-hid the button proves the module ran.
		fullscreenShown: !!fs && !fs.hidden,
		pipHidden: !!pip && pip.hidden,
		nativeControls: !!media && media.hasAttribute('controls'),
		// The stage must hold its shape before any video data arrives.
		stageHeight: stage ? Math.round(stage.getBoundingClientRect().height) : 0,
		stageWidth: stage ? Math.round(stage.getBoundingClientRect().width) : 0,
		chromeVisible: chrome ? getComputedStyle(chrome).opacity : '',
		// Nothing may stick out sideways.
		overflow: root ? Math.round(root.scrollWidth - root.clientWidth) : -1,
		posterOnTop: 0,
		keyboard: null
	};

	var poster = document.querySelector('.imgp__poster');
	if (poster && stage) {
		result.posterOnTop = Math.round(poster.getBoundingClientRect().height);
	}

	// The keyboard. Headless Chromium cannot decode a synthetic MP4, so
	// media.play() rejects and paused never flips — that is the browser's
	// decoder, not our code. What is ours is whether the shortcut reaches the
	// media element at all, so play() is watched rather than its outcome.
	var played = 0;
	var realPlay = media.play.bind(media);
	media.play = function () { played++; return realPlay().catch(function () {}); };

	root.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));

	// 'm' needs no decoding at all, so this half is end to end.
	var mutedBefore = media.muted;
	root.dispatchEvent(new KeyboardEvent('keydown', { key: 'm', bubbles: true }));
	var mutedAfter = media.muted;

	// A keystroke inside a text field belongs to the text field.
	var field = document.createElement('input');
	field.type = 'text';
	document.querySelector('.imgp__chrome').appendChild(field);
	field.focus();
	var playedBeforeTyping = played;
	field.dispatchEvent(new KeyboardEvent('keydown', { key: ' ', bubbles: true }));

	setTimeout(function () {
		result.keyboard = {
			played: played,
			mutedFlipped: mutedBefore !== mutedAfter,
			ignoredInField: played === playedBeforeTyping
		};

		// The poster is dismissed by the play event, whether or not the file
		// decodes: that binding is ours to get right.
		media.dispatchEvent(new Event('play'));
		result.startedClass = root.classList.contains('is-started');

		var out = document.createElement('pre');
		out.id = 'result';
		out.textContent = 'RESULT:' + JSON.stringify(result);
		document.body.appendChild(out);
	}, 350);
}, 1200);
</script>
</body></html>
HTML;

file_put_contents( $docroot . '/index.html', str_replace( 'ASSET_URL', "'/build/'", $page ) );

// The same page pointed at a location with no chunk in it. Loading the video
// chrome is allowed to fail — a CDN rewrite, an optimisation plugin moving
// files — and when it does the visitor must still get a usable player rather
// than a dead rectangle.
file_put_contents( $docroot . '/broken.html', str_replace( 'ASSET_URL', "'/nowhere/'", $page ) );

$probe = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
$port  = (int) explode( ':', (string) stream_socket_get_name( $probe, false ) )[1];
fclose( $probe );

$server = proc_open(
	array( PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot ),
	array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
	$pipes,
	$docroot
);

for ( $attempt = 0; $attempt < 50; $attempt++ ) {
	$socket = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.2 );
	if ( $socket ) { fclose( $socket ); break; }
	usleep( 100000 );
}

$visit = static function ( string $path ) use ( $browser, $port ): string {
	return (string) shell_exec(
		sprintf(
			'%s --headless --no-sandbox --disable-gpu --autoplay-policy=no-user-gesture-required --virtual-time-budget=9000 --dump-dom %s 2>/dev/null',
			escapeshellarg( $browser ),
			escapeshellarg( "http://127.0.0.1:{$port}{$path}" )
		)
	);
};

$dom    = $visit( '/' );
$broken = $visit( '/broken.html' );

foreach ( $pipes as $pipe ) {
	if ( is_resource( $pipe ) ) { fclose( $pipe ); }
}
proc_terminate( $server );
proc_close( $server );

if ( ! preg_match( '/RESULT:(\{.*?\})</s', $dom, $matches ) ) {
	echo 'FAIL  the page did not report a result' . PHP_EOL;
	$failures++;
} else {
	$r = json_decode( $matches[1], true );

	check( 'the player enhances', ! empty( $r['enhanced'] ) );
	check(
		'the browser found the video chunk on its own',
		! empty( $r['chunkRequested'] ),
		'a wrong public path here 404s silently on every install'
	);
	check(
		'and looked for it beside the bundle, not beside the page',
		! empty( $r['chunkRequested'] ) && empty( $r['chunkFailed'] )
	);
	check(
		'the module ran and revealed full screen',
		! empty( $r['fullscreenShown'] ),
		'the button stays hidden until the module confirms support'
	);
	check(
		'native controls are off once enhanced',
		! empty( $r['enhanced'] ) && empty( $r['nativeControls'] )
	);
	check(
		'the stage holds 16:9 before any video data arrives',
		$r['stageWidth'] > 0 && abs( ( $r['stageWidth'] / max( 1, $r['stageHeight'] ) ) - ( 16 / 9 ) ) < 0.05,
		$r['stageWidth'] . 'x' . $r['stageHeight']
	);
	check(
		'the poster covers the stage',
		(int) $r['stageHeight'] > 0 && (int) $r['posterOnTop'] === (int) $r['stageHeight'],
		$r['posterOnTop'] . ' vs ' . $r['stageHeight']
	);
	check( 'the controls are visible while paused', '1' === (string) ( $r['chromeVisible'] ?? '' ), (string) ( $r['chromeVisible'] ?? '' ) );
	check(
		'nothing overflows sideways',
		(int) $r['stageWidth'] > 0 && 0 === (int) $r['overflow'],
		(string) $r['overflow'] . 'px'
	);
	check(
		'the space bar reaches the media element',
		1 === (int) ( $r['keyboard']['played'] ?? 0 ),
		wp_json_encode( $r['keyboard'] )
	);
	check( 'm mutes', ! empty( $r['keyboard']['mutedFlipped'] ) );
	check(
		'and a space typed into a field stays in the field',
		! empty( $r['keyboard']['ignoredInField'] ),
		'a player that eats the space bar breaks every comment form on the page'
	);
	check( 'the poster is dismissed once it plays', ! empty( $r['startedClass'] ) );
}

echo PHP_EOL . '# When the video chrome cannot be loaded' . PHP_EOL;

if ( ! preg_match( '/RESULT:(\{.*?\})</s', $broken, $matches ) ) {
	check( 'the page still runs with an unreachable chunk', false );
} else {
	$b = json_decode( $matches[1], true );

	check( 'the core player still enhances', ! empty( $b['enhanced'] ) );
	check(
		'native controls are handed back rather than leaving a dead rectangle',
		! empty( $b['nativeControls'] ),
		'the visitor must be able to press play even when our chrome is missing'
	);
	check(
		'and the video-only buttons stay hidden, since nothing would answer them',
		empty( $b['fullscreenShown'] )
	);
}

// Clean up the throwaway docroot.
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $docroot, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $entry ) {
	$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
}
rmdir( $docroot );

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "{$failures} check(s) failed." . PHP_EOL;
	exit( 1 );
}
echo 'All checks passed.' . PHP_EOL;
