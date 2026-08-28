<?php
/**
 * Does a YouTube video actually play in this player?
 *
 * The recognition tests say the address is understood and the right markup
 * reaches the page. That is not the same as the thing working, and "the right
 * markup reached the page" is exactly what was true of the broken version.
 *
 * So this drives the real built bundle in a real browser, with YouTube's API
 * replaced by a stand-in that records what it was asked to do. Nothing here
 * talks to Google — that would make the suite depend on a third party being up
 * and on this machine being allowed to reach it — but everything between the
 * play button and `playVideo()` is the code that ships.
 *
 * What it is really checking is the seam: our chrome talks to an element, and a
 * video on YouTube is not an element. If the adapter in between is wrong, the
 * player looks fine and does nothing, which is the failure that was reported.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Render\PlayerRenderer;

$root = dirname( __DIR__ );

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

/**
 * The full browser first, and the headless shell only as a fallback.
 *
 * Everything the player paints on a clock tick is scheduled through
 * `requestAnimationFrame`, and the shell composites so rarely that those
 * callbacks can go unrun for the whole session — the clock then reads 0:00 not
 * because the player is broken but because nothing ever painted. The full
 * browser in headless mode produces frames normally.
 */
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

	foreach ( array( 'chromium', 'chromium-browser', 'google-chrome' ) as $name ) {
		$found = trim( (string) shell_exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null' ) );

		if ( '' !== $found ) {
			return $found;
		}
	}

	return '';
}

$browser = find_browser();

if ( '' === $browser ) {
	echo 'SKIP  no Chromium found; provider playback not driven' . PHP_EOL;
	exit( 0 );
}

$workdir = $root . '/build/.provider-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

// The whole build directory, because the provider and video chunks are fetched
// at runtime and have to be next to the bundle that asks for them.
foreach ( (array) glob( $root . '/build/*.{js,css}', GLOB_BRACE ) as $asset ) {
	copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
}

$renderer = new PlayerRenderer();

// A call to action half way through, because that is the part of the player
// that has to know where playback is — and where playback is, for a video on
// YouTube, is something only the adapter can say.
$html = $renderer->render(
	array(
		'src'    => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
		'title'  => 'Un vídeo de prueba',
		'layers' => array(
			array(
				'type'   => 'cta',
				'at'     => 50,
				'title'  => 'A mitad',
				'button' => 'Ver más',
				'url'    => 'https://example.test/mas',
			),
		),
	)
);

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>html,body{margin:0}</style>
</head><body>
{$html}
<script>
/*
 * Stand-in for YouTube's iframe API. It records what it was told to do and
 * reports a clock the test drives by hand, which is the only way to ask "what
 * happens at 50% of a video" without waiting half a video.
 */
window.__calls = [];
window.__ytState = { time: 0, duration: 200 };

window.YT = {
	Player: function (element, options) {
		var self = this;

		window.__calls.push('construct');
		window.__ytOptions = options;

		// A real frame appears here; a div is enough to prove the box was used.
		var frame = document.createElement('iframe');
		frame.className = 'imgp__fake-frame';
		element.appendChild(frame);

		this.playVideo = function () {
			window.__calls.push('play');
			options.events.onStateChange({ data: 1 });
		};
		this.pauseVideo = function () {
			window.__calls.push('pause');
			options.events.onStateChange({ data: 2 });
		};
		this.seekTo = function (s) { window.__calls.push('seek:' + Math.round(s)); window.__ytState.time = s; };
		this.setVolume = function (v) { window.__calls.push('volume:' + Math.round(v)); };
		this.mute = function () { window.__calls.push('mute'); };
		this.unMute = function () { window.__calls.push('unmute'); };
		this.setPlaybackRate = function (r) { window.__calls.push('rate:' + r); };
		this.getCurrentTime = function () { return window.__ytState.time; };
		this.getDuration = function () { return window.__ytState.duration; };
		this.destroy = function () { window.__calls.push('destroy'); };

		window.setTimeout(function () { options.events.onReady({ target: self }); }, 0);
	}
};

window.imaginaPlayer = {
	restUrl: '',
	lazyInit: false,
	maxComputeBytes: 0,
	assetUrl: './',
	i18n: {}
};
</script>
<script src="./frontend.js"></script>
</body></html>
HTML;

$file = $workdir . '/provider.html';

$probe = <<<'JS'
<script>
function report(data) {
	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify(data);
	document.body.appendChild(pre);
}

setTimeout(function () {
	var root = document.querySelector('.imgp');
	var box = document.querySelector('.imgp__embed');
	var out = {
		enhanced: root.classList.contains('is-enhanced'),
		framesBeforePlay: document.querySelectorAll('iframe').length,
		chrome: !!document.querySelector('.imgp__chrome'),
		bigPlay: !!document.querySelector('.imgp__bigplay')
	};

	// Press our own play button, which is the only thing a visitor does.
	document.querySelector('.imgp__play').click();

	setTimeout(function () {
		out.framesAfterPlay = document.querySelectorAll('iframe').length;
		out.callsAfterPlay = window.__calls.slice();
		out.playingClass = root.classList.contains('is-playing');

		// Move YouTube's clock past the half-way mark and let the poll notice.
		window.__ytState.time = 120;

		window.setTimeout(function () {
			out.total = (document.querySelector('.imgp__time--total') || {}).textContent;

			/*
			 * Whether the layer appeared is the honest read of "does the player
			 * know where playback is": the code that decides divides the player's
			 * own currentTime by its own duration, so it showing means both came
			 * from YouTube and arrived intact. The painted clock is not read here
			 * — see the note beside the assertions.
			 */
			var layer = document.querySelector('.imgp__layer');
			out.layerShown = layer ? !layer.hidden : null;

			// Seeking with the keyboard, which goes through the core's own seekTo
			// and out to the provider.
			root.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));

			window.setTimeout(function () {
				document.querySelector('.imgp__play').click();
				out.callsAtEnd = window.__calls.slice();
				out.boxKept = !!box && box.querySelector('iframe') !== null;

				report(out);
			}, 300);
		}, 600);
	}, 700);
}, 900);
</script>
JS;

file_put_contents( $file, str_replace( '</body></html>', $probe . '</body></html>', $page ) );

$dom = (string) shell_exec(
	sprintf(
		'%s --headless=new --no-sandbox --disable-gpu --window-size=900,700 --virtual-time-budget=14000 --dump-dom %s 2>/dev/null',
		escapeshellarg( $browser ),
		escapeshellarg( 'file://' . $file )
	)
);

exec( 'rm -rf ' . escapeshellarg( $workdir ) );

if ( ! preg_match( '/RESULT:(\{.*?\})</s', $dom, $matches ) ) {
	check( 'the page reported', false, 'no result; the bundle may have thrown' );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

$r = json_decode( $matches[1], true );

echo PHP_EOL . '# Before anybody presses play' . PHP_EOL;

check( 'the player takes over the markup', true === ( $r['enhanced'] ?? false ) );
check( 'our own chrome is on the picture', true === ( $r['chrome'] ?? false ) );
check( 'and a play button in the middle of it', true === ( $r['bigPlay'] ?? false ) );

/*
 * The reason the frame is not in the markup: an iframe is a request to Google
 * from every visitor who loads the page, watched or not.
 */
check( 'nothing has been requested from the provider yet', 0 === (int) ( $r['framesBeforePlay'] ?? -1 ), (string) ( $r['framesBeforePlay'] ?? '?' ) );

echo PHP_EOL . '# Pressing our play button' . PHP_EOL;

$calls = (array) ( $r['callsAfterPlay'] ?? array() );

check( 'builds the frame', 1 === (int) ( $r['framesAfterPlay'] ?? 0 ), (string) ( $r['framesAfterPlay'] ?? '?' ) );
check( 'inside the box the renderer left for it', true === ( $r['boxKept'] ?? false ) );
check( 'and starts the video', in_array( 'play', $calls, true ), implode( ',', $calls ) );
check( 'the player knows it is playing', true === ( $r['playingClass'] ?? false ) );

echo PHP_EOL . '# While it runs' . PHP_EOL;

check( 'the player learns the length from the provider', '3:20' === ( $r['total'] ?? '' ), (string) ( $r['total'] ?? '' ) );

/*
 * The seam, and the check that would have caught the reported fault. YouTube
 * never volunteers where playback is, so the adapter asks and republishes it as
 * the events the rest of the player already listens for. If any of that is
 * wrong, a layer set to appear half way through never appears — while the
 * player goes on looking perfectly healthy, which is the failure mode that
 * makes this worth a test rather than a glance.
 *
 * The painted clock is deliberately not asserted. It repaints inside
 * `requestAnimationFrame`, and headless Chromium under a virtual clock hands
 * those out so unreliably that the check failed roughly two runs in three with
 * nothing wrong. What is asserted here is the same fact — that the player's own
 * `currentTime` and `duration` follow the provider — read through the one
 * consumer that acts on them without waiting for a frame.
 */
check( 'and knows where playback is: a call to action at 50% appears', true === ( $r['layerShown'] ?? false ) );

$end = (array) ( $r['callsAtEnd'] ?? array() );

check( 'seeking from the keyboard reaches the provider', (bool) preg_grep( '/^seek:/', $end ), implode( ',', $end ) );
check( 'and our pause button reaches it too', in_array( 'pause', $end, true ), implode( ',', $end ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
