<?php
/**
 * The player that follows the reader.
 *
 * The switch for this existed and was offered on video blocks, but what it did
 * there was the audio version: pin the player to the foot of the window as a
 * full-width bar, which for a video means laying a whole sixteen-by-nine
 * picture across the bottom of the screen. It was removed from the video block
 * for one release rather than left doing that.
 *
 * What is checked here is the behaviour rather than the look, because the look
 * is the easy half. A floating player has three ways to be wrong that no
 * screenshot shows: it detaches when nothing is playing, it leaves the page
 * jumping where it used to be, and it cannot be got rid of.
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
	echo 'SKIP  no Chromium found; the floating player was not driven' . PHP_EOL;
	exit( 0 );
}

echo PHP_EOL . '# The way out is in the page' . PHP_EOL;

$renderer = new PlayerRenderer();

$with    = $renderer->render( array( 'src' => 'https://cdn.example.com/clip.mp4', 'sticky' => 'yes' ) );
$without = $renderer->render( array( 'src' => 'https://cdn.example.com/clip.mp4', 'sticky' => 'no' ) );

check( 'a player that may follow carries a button to send it away', str_contains( $with, 'imgp__unstick' ) );
check( 'and one that may not carries nothing', ! str_contains( $without, 'imgp__unstick' ) );

$workdir = $root . '/build/.sticky-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

foreach ( (array) glob( $root . '/build/*.{js,css}', GLOB_BRACE ) as $asset ) {
	copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
}

$filler = str_repeat( '<p>Un párrafo de relleno, para que la página sea más alta que la ventana.</p>', 14 );

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>html,body{margin:0}main{max-width:640px;margin:0 auto;padding:20px}p{line-height:2}</style>
</head><body>
<main>
<p>Antes.</p>
{$with}
{$filler}
</main>
<script>
/*
 * A media element that can be told it is playing. There is no video file to
 * fetch here — and fetching one would make this a test of the network — but
 * whether the player has detached depends entirely on whether it believes
 * something is playing, so that is the one thing worth faking.
 */
window.__playing = false;

Object.defineProperty(HTMLMediaElement.prototype, 'paused', {
	get: function () { return ! window.__playing; },
	configurable: true
});

window.imaginaPlayer = { restUrl: '', lazyInit: false, maxComputeBytes: 0, assetUrl: './', i18n: {} };
</script>
<script src="./frontend.js"></script>
</body></html>
HTML;

$probe = <<<'JS'
<script>
function stuck() {
	return document.querySelector('.imgp').classList.contains('is-stuck');
}

function gap() {
	var placeholder = document.querySelector('.imgp__sticky-placeholder');

	return placeholder ? Math.round(placeholder.getBoundingClientRect().height) : 0;
}

setTimeout(function () {
	var player = document.querySelector('.imgp');
	var tall = Math.round(player.getBoundingClientRect().height);
	var out = {};

	// Scrolled past while nothing is playing: it must stay where it is.
	window.scrollTo(0, 1200);

	setTimeout(function () {
		out.stuckWhilePaused = stuck();

		// Now it is playing, and still out of view.
		window.__playing = true;
		document.querySelector('.imgp__media').dispatchEvent(new Event('play'));
		window.scrollTo(0, 1220);

		setTimeout(function () {
			out.stuckWhilePlaying = stuck();
			out.gapHeld = gap();
			out.playerHeight = tall;

			var card = document.querySelector('.imgp').getBoundingClientRect();

			out.card = { w: Math.round(card.width), h: Math.round(card.height) };
			out.viewport = { w: window.innerWidth, h: window.innerHeight };

			// And the reader sends it away.
			document.querySelector('.imgp__unstick').click();

			setTimeout(function () {
				out.stuckAfterDismiss = stuck();

				/*
				 * Something has to make the player reconsider, or this proves
				 * nothing: the page is already scrolled as far as it goes, so
				 * no observer fires and staying put would look like success
				 * whatever the dismissal did. A `play` is the reconsidering
				 * event a reader would actually produce.
				 */
				document.querySelector('.imgp__media').dispatchEvent(new Event('play'));
				window.scrollTo(0, 1600);

				setTimeout(function () {
					out.returnedAfterDismiss = stuck();

					var pre = document.createElement('pre');
					pre.textContent = 'RESULT:' + JSON.stringify(out);
					document.body.appendChild(pre);
				}, 400);
			}, 300);
		}, 500);
	}, 500);
}, 700);
</script>
JS;

$file = $workdir . '/sticky.html';
file_put_contents( $file, str_replace( '</body></html>', $probe . '</body></html>', $page ) );

$dom = (string) shell_exec(
	sprintf(
		'%s --headless=new --no-sandbox --disable-gpu --window-size=900,600 --virtual-time-budget=12000 --dump-dom %s 2>/dev/null',
		escapeshellarg( $browser ),
		escapeshellarg( 'file://' . $file )
	)
);

exec( 'rm -rf ' . escapeshellarg( $workdir ) );

if ( ! preg_match( '/RESULT:(\{.*?\})</s', $dom, $matches ) ) {
	check( 'the page reported', false, 'no result' );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

$r = json_decode( $matches[1], true );

echo PHP_EOL . '# When it follows, and when it does not' . PHP_EOL;

/*
 * A player that pins itself the moment it scrolls past, whether or not anybody
 * asked for anything, is an advert. It follows only what a reader is listening
 * to or watching.
 */
check( 'scrolling past a paused player leaves it alone', false === ( $r['stuckWhilePaused'] ?? null ) );
check( 'scrolling away from a playing one makes it follow', true === ( $r['stuckWhilePlaying'] ?? null ) );

/*
 * The player leaves the flow, so without something holding its place the whole
 * page jumps up by its height at the moment it detaches — under the reader's
 * finger, mid-scroll.
 */
check(
	'and the space it came from is held open',
	abs( (int) ( $r['gapHeld'] ?? 0 ) - (int) ( $r['playerHeight'] ?? -1 ) ) <= 1,
	'gap ' . ( $r['gapHeld'] ?? '?' ) . 'px for a player ' . ( $r['playerHeight'] ?? '?' ) . 'px tall'
);

echo PHP_EOL . '# It is a card, not a bar' . PHP_EOL;

$card     = (array) ( $r['card'] ?? array() );
$viewport = (array) ( $r['viewport'] ?? array() );

// The audio version spans the window. A picture doing that is the thing that
// made this switch unusable on a video block.
check(
	'the floating video is a corner of the window rather than the width of it',
	(int) ( $card['w'] ?? 0 ) > 0 && (int) $card['w'] < (int) ( $viewport['w'] ?? 0 ) * 0.7,
	( $card['w'] ?? '?' ) . 'px of ' . ( $viewport['w'] ?? '?' )
);

check(
	'and it keeps the shape of a video',
	(int) ( $card['h'] ?? 0 ) > 0
		&& abs( ( (int) $card['w'] / max( 1, (int) $card['h'] ) ) - ( 16 / 9 ) ) < 0.25,
	( $card['w'] ?? '?' ) . 'x' . ( $card['h'] ?? '?' )
);

echo PHP_EOL . '# And the reader can be rid of it' . PHP_EOL;

check( 'the button sends it back', false === ( $r['stuckAfterDismiss'] ?? null ) );

/*
 * Sending it back only for the next scroll to return it is not dismissing it.
 */
check( 'and it does not come back on the next scroll', false === ( $r['returnedAfterDismiss'] ?? null ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
