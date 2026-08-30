<?php
/**
 * When a call to action, a bar and an email gate are on the screen.
 *
 * The report was that they barely worked: the bar never showed, and neither did
 * the other one. Three separate causes, none of which a PHP test could have
 * found, because all three are about the moment something becomes visible.
 *
 * 1. The whole overlay slot sat at `z-index: 6` while the control bar sat at 8,
 *    and the slot is its own stacking context — so nothing inside it could
 *    climb past the controls. A bar pinned to the bottom of the picture, which
 *    is the same edge the controls occupy, was drawn *underneath* them: the
 *    headline came out behind the play button and the button on top of the
 *    volume slider.
 * 2. The script only listened to `timeupdate`, which fires while something is
 *    playing. Nothing could be on screen before the visitor pressed play, so
 *    "a bar that is simply there" — which is what an action bar is in both
 *    Presto and Fluent — could not be expressed at all.
 * 3. A new layer defaulted to appearing at 100%. Somebody adding an action bar
 *    and leaving the slider alone got a bar that appeared when the video
 *    finished.
 *
 * So this drives a real player in a browser, with a length and a clock it
 * controls, and asks what is on the screen at each moment.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Render\PlayerRenderer;

$root     = dirname( __DIR__ );
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
			return (string) $candidate;
		}
	}

	return '';
}

$browser = find_browser();

if ( '' === $browser ) {
	echo 'SKIP  no Chromium found; the layers were not driven' . PHP_EOL;
	exit( 0 );
}

$workdir = $root . '/build/.layer-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

foreach ( (array) glob( $root . '/build/*.{js,css}', GLOB_BRACE ) as $asset ) {
	copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
}

/*
 * The order here is the order the settings are stored in, deliberately not the
 * order they end up in the document: bars are rendered below the picture and
 * the rest over it, so the two differ, and lining them up by document order is
 * exactly the mistake that would show the wrong layer at the wrong moment.
 */
$layers = array(
	array( 'type' => 'bar', 'at' => 0, 'title' => 'Siempre', 'button' => 'Ir', 'url' => 'https://e.test/', 'skip' => true ),
	array( 'type' => 'cta', 'at' => 50, 'title' => 'Mitad', 'text' => 'x', 'button' => 'Ver', 'url' => 'https://e.test/', 'skip' => true ),
	array( 'type' => 'email', 'at' => 80, 'title' => 'Correo', 'button' => 'Enviar', 'skip' => true ),
	array( 'type' => 'cta', 'at' => 100, 'title' => 'Final', 'button' => 'Otra vez', 'url' => 'https://e.test/', 'skip' => true ),
	// A window: up for the middle third and gone again afterwards, which is
	// what both of the players this is measured against can do and this could
	// not.
	array( 'type' => 'bar', 'at' => 30, 'until' => 60, 'title' => 'Sólo un rato', 'button' => 'Ir', 'url' => 'https://e.test/', 'skip' => true ),
);

$renderer = new PlayerRenderer();

$markup = $renderer->render(
	array(
		'src'         => 'https://cdn.example.com/clip.mp4',
		'title'       => 'Clase',
		'aspectRatio' => '16:9',
		'layers'      => $layers,
	)
);

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>html,body{margin:0}#host{width:640px}</style>
</head><body><div id="host">{$markup}</div>
<script>
window.imaginaPlayer = { restUrl: '', lazyInit: false, maxComputeBytes: 0, assetUrl: './', i18n: {} };
</script>
<script src="./frontend.js"></script>
<script>
var media = document.querySelector('.imgp__media');
var clock = 0;

// A length and a clock this test owns. Headless Chromium cannot decode a real
// file, and what is under test is the arithmetic, not the decoder.
Object.defineProperty(media, 'duration', { get: function () { return 200; }, configurable: true });
Object.defineProperty(media, 'currentTime', {
	get: function () { return clock; },
	set: function (v) { clock = v; },
	configurable: true
});
Object.defineProperty(media, 'ended', { get: function () { return clock >= 200; }, configurable: true });
media.play = function () { return Promise.resolve(); };
media.pause = function () {};

/** Which layers are on screen, by the index the server gave each one. */
function visible() {
	var out = [];

	document.querySelectorAll('.imgp__layer').forEach(function (el) {
		if (!el.hidden) { out.push(Number(el.dataset.layerIndex)); }
	});

	return out.sort(function (a, b) { return a - b; });
}

function at(seconds) {
	clock = seconds;
	media.dispatchEvent(new Event('timeupdate'));
	if (seconds >= 200) { media.dispatchEvent(new Event('ended')); }
	return visible();
}

setTimeout(function () {
	var report = {};

	// Before anything at all is played.
	report.idle = visible();

	report.start = at(0);
	report.third = at(70);      // 35%
	report.half = at(100);      // 50%
	report.late = at(130);      // 65%
	report.gate = at(160);      // 80%
	report.finish = at(200);    // 100%

	/*
	 * Where each one is in the document, and whether anything is painted over
	 * the controls. The bar used to be drawn under them.
	 */
	var chrome = document.querySelector('.imgp__chrome');
	var bar = document.querySelector('.imgp__layer--bar');
	var cta = document.querySelector('.imgp__layer--cta');
	var stage = document.querySelector('.imgp__stage');

	report.barInsideStage = !!(stage && bar && stage.contains(bar));
	report.ctaInsideStage = !!(stage && cta && stage.contains(cta));

	/*
	 * With the controls actually up.
	 *
	 * They fade out after a few seconds of stillness and take their
	 * pointer-events with them, so hit-testing an idle control bar finds
	 * whatever is behind it no matter what the stacking says — which made an
	 * earlier version of this check pass with the overlay slot back under the
	 * chrome, the exact bug it exists to catch.
	 */
	var player = document.querySelector('.imgp');
	player.classList.remove('is-chrome-idle');

	// The question a person actually asks: at the middle of the control row,
	// what would a click land on?
	var box = chrome.getBoundingClientRect();
	var point = [ box.left + box.width / 2, box.top + box.height - 12 ];
	var mid = document.elementFromPoint(point[0], point[1]);

	report.controlRow = mid ? (mid.className || mid.tagName) : '';
	report.overControls = !!(mid && mid.closest('.imgp__layer--bar'));

	/*
	 * And the stacking itself, stated rather than inferred.
	 *
	 * Hit-testing alone was not enough: the slot is its own stacking context,
	 * so what is painted on top depends on the two containers' z-index and not
	 * on the children's — and with a call to action already covering the whole
	 * picture, a click at the control row lands on it either way. The numbers
	 * are the invariant.
	 */
	var slot = document.querySelector('.imgp__stage .imgp__layers');
	report.slotZ = slot ? Number(getComputedStyle(slot).zIndex) : null;
	report.chromeZ = Number(getComputedStyle(chrome).zIndex);

	// And with a call to action up, the controls must be the ones covered.
	cta.hidden = false;
	var overCta = document.elementFromPoint(point[0], point[1]);
	report.ctaCoversControls = !!(overCta && overCta.closest('.imgp__layer'));
	report.overCta = overCta ? (overCta.className || overCta.tagName) : '';
	cta.hidden = true;

	/*
	 * Can you see the thing you are meant to press?
	 *
	 * Measured through a canvas, over the panel behind it, because the panel is
	 * a gradient and the button's colour is the site's accent. Both defaults
	 * are near-black, and the pair came out at 1.43:1 — the label read
	 * perfectly and the button was not visible as a button at all.
	 */
	var probe = document.createElement('canvas').getContext('2d', { willReadFrequently: true });

	function luminance(colour, backdrop) {
		probe.clearRect(0, 0, 1, 1);
		probe.fillStyle = backdrop;
		probe.fillRect(0, 0, 1, 1);
		probe.fillStyle = colour;
		probe.fillRect(0, 0, 1, 1);

		var d = probe.getImageData(0, 0, 1, 1).data;
		var parts = [ d[0], d[1], d[2] ].map(function (n) {
			var c = n / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
		});

		return 0.2126 * parts[0] + 0.7152 * parts[1] + 0.0722 * parts[2];
	}

	function ratio(a, b, backdrop) {
		var x = luminance(a, backdrop);
		var y = luminance(b, backdrop);
		return Math.round(((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)) * 100) / 100;
	}

	cta.hidden = false;

	var action = cta.querySelector('.imgp__layer-button');
	var style = getComputedStyle(action);
	// The dark end of the call to action's own gradient.
	var panel = 'rgb(0, 0, 0)';

	report.buttonLabel = ratio(style.color, style.backgroundColor, panel);

	/*
	 * The fill, or an edge that is actually drawn. A border with no width still
	 * reports a colour — the element's own `color` — so taking the better of
	 * the two without checking the width said the button was perfectly visible
	 * with `border: 0`, which is the state it was in when this was reported.
	 */
	var edge = ratio(style.backgroundColor, panel, panel);

	if (parseFloat(style.borderTopWidth) > 0) {
		edge = Math.max(edge, ratio(style.borderTopColor, panel, panel));
	}

	report.buttonEdge = edge;
	report.buttonBorderWidth = style.borderTopWidth;

	cta.hidden = true;

	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify(report);
	document.body.appendChild(pre);
}, 1300);
</script>
</body></html>
HTML;

$file = $workdir . '/layers.html';
file_put_contents( $file, $page );

$dom = (string) shell_exec(
	sprintf(
		'%s --headless=new --no-sandbox --disable-gpu --window-size=900,1000 --virtual-time-budget=9000 --dump-dom %s 2>/dev/null',
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

$report = json_decode( $matches[1], true );

/** @param array<int,int> $expected */
function shows( string $label, array $actual, array $expected ): void {
	check(
		$label,
		$expected === $actual,
		'showing ' . ( array() === $actual ? 'nothing' : implode( ',', $actual ) )
			. ' — expected ' . ( array() === $expected ? 'nothing' : implode( ',', $expected ) )
	);
}

echo PHP_EOL . '# A bar that is simply there' . PHP_EOL;

/*
 * The heart of the report. An action bar in Presto and in Fluent is a standing
 * offer — it is on the page, and pressing play is not a condition of seeing it.
 * Here it needed a `timeupdate` to appear, and `timeupdate` needs playback.
 */
shows( 'a bar set to the start is up before anything is played', (array) ( $report['idle'] ?? array() ), array( 0 ) );
shows( 'and stays once the clock starts', (array) ( $report['start'] ?? array() ), array( 0 ) );

echo PHP_EOL . '# The rest arrive when they are due' . PHP_EOL;

shows( 'a windowed bar comes up inside its window', (array) ( $report['third'] ?? array() ), array( 0, 4 ) );
shows( 'a call to action at the halfway mark', (array) ( $report['half'] ?? array() ), array( 0, 1, 4 ) );
shows( 'and the window closes on its own afterwards', (array) ( $report['late'] ?? array() ), array( 0, 1 ) );
shows( 'the gate at four fifths', (array) ( $report['gate'] ?? array() ), array( 0, 1, 2 ) );

/*
 * The end is where this went wrong in an interesting way. "Rewind when it
 * ends" is the default, so the player seeks back to zero the moment a call to
 * action at 100% becomes due — and an earlier version of the window logic read
 * that as "no longer due" and hid it again in the same frame.
 */
shows( 'and everything is still up after the video rewinds itself', (array) ( $report['finish'] ?? array() ), array( 0, 1, 2, 3 ) );

echo PHP_EOL . '# Nothing lands on the controls' . PHP_EOL;

check(
	'the action bar is below the picture, not pinned over it',
	false === ( $report['barInsideStage'] ?? true ),
	'it is inside the stage, which is the same edge the controls are on'
);

check(
	'a call to action still covers the picture',
	true === ( $report['ctaInsideStage'] ?? false )
);

check(
	'the control row belongs to the controls',
	false === ( $report['overControls'] ?? true ),
	'the bar is on top of them'
);

/*
 * The number that was wrong. The slot sat at 6 and the control bar at 8, and
 * because the slot is its own stacking context nothing inside it could climb
 * past — so the bar pinned to the same edge as the controls was drawn
 * underneath them, and a call to action had the control bar across its foot.
 */
check(
	'the overlay slot stacks above the control bar',
	is_numeric( $report['slotZ'] ?? null )
		&& is_numeric( $report['chromeZ'] ?? null )
		&& (int) $report['slotZ'] > (int) $report['chromeZ'],
	'slot ' . (string) ( $report['slotZ'] ?? '?' ) . ' vs controls ' . (string) ( $report['chromeZ'] ?? '?' )
);

check(
	'and a call to action covers the controls, which is the point of one',
	true === ( $report['ctaCoversControls'] ?? false ),
	'the control bar is drawn across it — a click at the control row reached ' . (string) ( $report['overCta'] ?? '?' )
);

echo PHP_EOL . '# The thing you are meant to press' . PHP_EOL;

check(
	'the button label reads against the button',
	(float) ( $report['buttonLabel'] ?? 0 ) >= 4.5,
	( $report['buttonLabel'] ?? '?' ) . ':1'
);

/*
 * And the button itself against the panel. Not a text ratio — this is a shape,
 * not a letterform — but it has to be visible as one, and 1.43:1 is not. The
 * accent stays whatever the site chose; the edge is what draws it.
 */
check(
	'and the button is visible as a button, not floating words',
	(float) ( $report['buttonEdge'] ?? 0 ) >= 3.0,
	( $report['buttonEdge'] ?? '?' ) . ':1 against the panel'
);

echo PHP_EOL . '# What the server sends' . PHP_EOL;

check(
	'every layer carries its own index, so the two orders cannot drift',
	count( $layers ) === substr_count( $markup, 'data-layer-index=' ),
	substr_count( $markup, 'data-layer-index=' ) . ' of ' . count( $layers )
);

check(
	'the player has a name that survives a page load',
	(bool) preg_match( '/&quot;layerKey&quot;:&quot;[0-9a-f]{12}&quot;/', $markup ),
	'without one, a dismissed gate comes back on every visit'
);

check(
	'and it is not the DOM id, which is minted fresh every render',
	! preg_match( '/&quot;layerKey&quot;:&quot;imgp-/', $markup )
);

/*
 * The guard for the mistake that made half of this file fail on its first run.
 *
 * The payload sent to the page is rebuilt key by key, on purpose — a field
 * meant for the server should not travel to every visitor. The cost is that a
 * new field has to be added in two places, and the second one is easy to
 * forget: the end time was in the schema, sanitised, rendered into the markup
 * and read by the script, and did nothing at all, because it stopped at this
 * list. Nothing errored. The layer simply never went away.
 */
$sent = array();

if ( preg_match( '/&quot;layers&quot;:\[(.*?)\]/', $markup, $m ) ) {
	preg_match_all( '/&quot;([a-zA-Z]+)&quot;:/', $m[1], $keys );
	$sent = array_values( array_unique( $keys[1] ) );
}

check( 'the layers reach the page at all', array() !== $sent );

/*
 * Every timing field the script decides with. Not every field of the schema —
 * the headline and the button label are rendered by the server and have no
 * business being sent twice.
 */
$needed = array( 'type', 'at', 'until', 'skip' );

check(
	'and carry every field the script decides with',
	array() === array_diff( $needed, $sent ),
	'missing ' . implode( ', ', array_diff( $needed, $sent ) )
);

$script = (string) file_get_contents( dirname( __DIR__ ) . '/assets/src/frontend/layers.ts' );

preg_match_all( '/spec\.([a-zA-Z]+)/', $script, $read );

$unused = array_diff( array_values( array_unique( $read[1] ) ), $sent );

check(
	'and the script reads nothing the server does not send',
	array() === $unused,
	implode( ', ', $unused ) . ' — read from the settings but never sent'
);

echo PHP_EOL;
echo 0 === $failures ? 'All layer timing checks passed.' . PHP_EOL : "{$failures} FAILURE(S)" . PHP_EOL;

exit( 0 === $failures ? 0 : 1 );
