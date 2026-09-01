<?php
/**
 * Hiding the interface of a video somebody else hosts.
 *
 * A video on YouTube is played through a frame this page cannot reach into —
 * that is what the same-origin policy is for — so its interface cannot be
 * styled away. `controls=0` takes off the control bar and nothing else: the
 * title, the channel avatar, the "Watch on YouTube" button and the grid of
 * suggested videos at the end answer to no parameter, and every one of them is
 * a way off the page the visitor is on.
 *
 * Three mechanisms, and each is checked here on its own.
 *
 * ## What this test is measuring, and what it is not
 *
 * It cannot reach youtube.com — this machine has no route to it — so it does
 * not measure YouTube. It measures the geometry the technique depends on,
 * against a stand-in built to the same shape: chrome pinned to the edges of
 * the player, the picture fitted inside it and centred, which is how the
 * YouTube embed is laid out.
 *
 * That distinction is worth being plain about. If YouTube moves its title bar
 * to sit against the picture instead of against the frame, this test will
 * still pass and the feature will still be wrong. What this proves is that the
 * crop does what it is meant to do, exactly, to the pixel — not that YouTube
 * has not changed.
 *
 * @package ImaginaPlayer
 */

$root     = dirname( __DIR__ );
$failures = 0;

function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

$browser = '';

foreach ( array_merge(
	array( (string) getenv( 'CHROMIUM_BIN' ) ),
	glob( '/opt/pw-browsers/chromium-*/chrome-linux/chrome' ) ?: array()
) as $candidate ) {
	if ( '' !== $candidate && is_executable( $candidate ) ) {
		$browser = $candidate;
		break;
	}
}

if ( '' === $browser ) {
	echo 'SKIP  no Chromium; the crop was not measured' . PHP_EOL;
	exit( 0 );
}

$css = $root . '/build/style-frontend.css';

if ( ! is_readable( $css ) ) {
	check( 'the stylesheet is built', false, 'run npm run build first' );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

$workdir = $root . '/build/.provider-chrome';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

copy( $css, $workdir . '/style-frontend.css' );

/*
 * The stand-in, laid out the way an embedded player is: bars pinned to the top
 * and bottom of the player, which fills the frame, and the picture fitted
 * inside and centred. Nothing here is YouTube's markup — what matters is where
 * things sit, which is what the crop is aimed at.
 */
file_put_contents(
	$workdir . '/embed.html',
	<<<'HTML'
<!doctype html><meta charset="utf-8"><title>stand-in</title>
<style>
	html, body { margin: 0; height: 100%; background: #000; }
	.player { position: absolute; inset: 0; }
	/* Fitted to the player and centred, which is what a video does. */
	.picture {
		position: absolute; inset: 0; margin: auto;
		aspect-ratio: 16 / 9; max-width: 100%; max-height: 100%;
		width: auto; height: auto; background: #345;
	}
	/* Pinned to the player's edges, which is where the chrome lives. */
	.chrome-top { position: absolute; top: 0; left: 0; right: 0; height: 48px; background: #f0f; }
	.chrome-bottom { position: absolute; bottom: 0; left: 0; right: 0; height: 48px; background: #0ff; }
</style>
<div class="player">
	<div class="picture" id="picture"></div>
	<div class="chrome-top" id="top"></div>
	<div class="chrome-bottom" id="bottom"></div>
</div>
HTML
);

/**
 * A page holding one player, with the crop on or off.
 *
 * @param bool $bare Whether the interface is being hidden.
 */
function page( bool $bare ): string {
	$class = $bare ? 'imgp imgp--video imgp--bare-provider' : 'imgp imgp--video';

	return <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>
	html, body { margin: 0; padding: 0; background: #fff; }
	/* A stage of a known width, so every number below is exact rather than
	   whatever the window happened to be. The height comes from the plugin's
	   own aspect ratio, because the layout under test is the plugin's. */
	#stage { width: 640px; }
	/* Something of ours above the frame, to hit-test against — standing in for
	   the control bar, which is what really sits there. */
	#chrome { position: absolute; inset: 0; z-index: 5; }
</style>
</head><body>
<div id="stage">
	<div class="{$class}">
		<div class="imgp__stage">
			<div class="imgp__embed" id="embed">
				<iframe id="frame" src="./embed.html" title="stand-in"></iframe>
			</div>
			<div id="chrome"></div>
		</div>
	</div>
</div>
<script>
window.addEventListener('load', function () {
	setTimeout(function () {
		var frame = document.getElementById('frame');
		var box = document.getElementById('embed').getBoundingClientRect();
		var inner = frame.contentDocument;
		var offset = frame.getBoundingClientRect();

		function at(id) {
			var r = inner.getElementById(id).getBoundingClientRect();
			return {
				top: Math.round(r.top + offset.top),
				bottom: Math.round(r.bottom + offset.top),
				left: Math.round(r.left + offset.left),
				right: Math.round(r.right + offset.left)
			};
		}

		var mid = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);

		var out = document.createElement('pre');
		out.id = 'result';
		out.textContent = 'RESULT:' + JSON.stringify({
			box: {
				top: Math.round(box.top), bottom: Math.round(box.bottom),
				left: Math.round(box.left), right: Math.round(box.right),
				width: Math.round(box.width), height: Math.round(box.height)
			},
			topBar: at('top'),
			bottomBar: at('bottom'),
			picture: at('picture'),
			// What the mouse would land on over the middle of the video.
			hit: mid ? (mid.id || mid.tagName) : 'nothing',
			pointerEvents: getComputedStyle(frame).pointerEvents
		});
		document.body.appendChild(out);
	}, 400);
});
</script>
</body></html>
HTML;
}

/*
 * Served over HTTP rather than opened from disk.
 *
 * Chrome gives every `file://` document its own opaque origin, so a frame
 * loaded that way is cross-origin to the page holding it and reading inside it
 * throws — which is exactly the restriction this whole feature exists because
 * of, arriving in the test rather than in the product. One origin, one port,
 * and the measurements can be taken.
 */
$port = 8700 + ( getmypid() % 80 );

$server = proc_open(
	array( PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $workdir ),
	array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
	$pipes,
	$workdir
);

for ( $attempt = 0; $attempt < 50; $attempt++ ) {
	$socket = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.2 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- polling until it answers.
	if ( $socket ) { fclose( $socket ); break; }
	usleep( 100000 );
}

/**
 * Load a page and read back what it measured.
 *
 * @param string $file   Where to write it.
 * @param string $html   The page.
 * @param string $chrome The browser binary.
 * @param int    $port   The port it is served on.
 */
function measure_page( string $file, string $html, string $chrome, int $port ): ?array {
	file_put_contents( $file, $html );

	$dom = (string) shell_exec(
		sprintf(
			'%s --headless --no-sandbox --disable-gpu --window-size=900,700 --virtual-time-budget=5000 --dump-dom %s 2>/dev/null',
			escapeshellarg( $chrome ),
			escapeshellarg( 'http://127.0.0.1:' . $port . '/' . basename( $file ) )
		)
	);

	return preg_match( '/RESULT:(\{.*?\})</s', $dom, $found )
		? json_decode( $found[1], true )
		: null;
}

echo PHP_EOL . '# With the provider’s interface left alone' . PHP_EOL;

$plain = measure_page( $workdir . '/plain.html', page( false ), $browser, $port );

check( 'the page reported', is_array( $plain ), 'nothing came back' );

if ( ! is_array( $plain ) ) {
	proc_terminate( $server );
	exec( 'rm -rf ' . escapeshellarg( $workdir ) );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

/*
 * The negative control, and the reason the numbers below mean anything. If the
 * bars were outside the box without the crop, the crop would be measuring its
 * own shadow.
 */
check(
	'the provider’s top bar is over the picture, as it is today',
	$plain['topBar']['top'] >= $plain['box']['top']
		&& $plain['topBar']['bottom'] <= $plain['box']['bottom'],
	'top bar at ' . $plain['topBar']['top'] . '–' . $plain['topBar']['bottom']
		. ', box ' . $plain['box']['top'] . '–' . $plain['box']['bottom']
);

check(
	'and so is the bottom one',
	$plain['bottomBar']['bottom'] <= $plain['box']['bottom']
		&& $plain['bottomBar']['top'] >= $plain['box']['top']
);

check(
	'and the frame takes the mouse, so hovering it shows them',
	'none' !== $plain['pointerEvents'],
	'pointer-events: ' . $plain['pointerEvents']
);

echo PHP_EOL . '# With it hidden' . PHP_EOL;

$bare = measure_page( $workdir . '/bare.html', page( true ), $browser, $port );

check( 'the page reported', is_array( $bare ) );

if ( is_array( $bare ) ) {
	check(
		'the box is the same size as before — the player did not move or resize',
		$bare['box'] === $plain['box'],
		wp_json_encode_local( $bare['box'] )
	);

	/*
	 * Clear of the box by a margin, not merely outside it.
	 *
	 * "Outside" on its own is far too easy to satisfy: with the picture kept
	 * centred, almost any overscan puts a bar of this stand-in's height just
	 * past the edge, so a crop with barely any room to spare passed — and a
	 * provider whose bar is taller than the one drawn here, or that grows it on
	 * a larger player, would land back inside it.
	 *
	 * A whole box-height of clearance is what the setting is for, so that is
	 * what is checked. At this size that is 360 pixels; a hundred is asked for,
	 * which no bar approaches and a mistuned crop cannot reach.
	 */
	$clearance = 100;

	check(
		'the top bar is clear above the visible box, with room to spare',
		$bare['box']['top'] - $bare['topBar']['bottom'] >= $clearance,
		( $bare['box']['top'] - $bare['topBar']['bottom'] ) . 'px of clearance, wanted ' . $clearance
	);

	check(
		'and the bottom bar clear below it',
		$bare['bottomBar']['top'] - $bare['box']['bottom'] >= $clearance,
		( $bare['bottomBar']['top'] - $bare['box']['bottom'] ) . 'px of clearance, wanted ' . $clearance
	);

	/*
	 * The half that makes it worth doing. Hiding the chrome by cropping into
	 * the picture would be a trade rather than a fix — and it is easy to do by
	 * accident, since any overscan that is slightly wrong eats the frame.
	 */
	check(
		'and the picture still covers the box exactly, to the pixel',
		abs( $bare['picture']['top'] - $bare['box']['top'] ) <= 1
			&& abs( $bare['picture']['bottom'] - $bare['box']['bottom'] ) <= 1
			&& abs( $bare['picture']['left'] - $bare['box']['left'] ) <= 1
			&& abs( $bare['picture']['right'] - $bare['box']['right'] ) <= 1,
		'picture ' . $bare['picture']['top'] . '–' . $bare['picture']['bottom']
			. ' × ' . $bare['picture']['left'] . '–' . $bare['picture']['right']
			. ', box ' . $bare['box']['top'] . '–' . $bare['box']['bottom']
			. ' × ' . $bare['box']['left'] . '–' . $bare['box']['right']
	);

	check(
		'the frame never receives the mouse, so nothing of theirs appears on hover',
		'none' === $bare['pointerEvents'],
		'pointer-events: ' . $bare['pointerEvents']
	);

	check(
		'and a click over the middle of the video lands on this player, not the frame',
		'chrome' === $bare['hit'],
		'it landed on ' . $bare['hit']
	);
}

foreach ( $pipes as $pipe ) {
	if ( is_resource( $pipe ) ) { fclose( $pipe ); }
}

proc_terminate( $server );
exec( 'rm -rf ' . escapeshellarg( $workdir ) );

echo PHP_EOL;
echo 0 === $failures ? 'All provider-chrome checks passed.' . PHP_EOL : "{$failures} FAILURE(S)" . PHP_EOL;

exit( 0 === $failures ? 0 : 1 );

/**
 * JSON, without WordPress loaded.
 *
 * @param mixed $data What to encode.
 */
function wp_json_encode_local( $data ): string {
	return (string) json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- no WordPress here.
}
