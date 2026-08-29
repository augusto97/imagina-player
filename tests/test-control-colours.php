<?php
/**
 * Can you see the controls you just coloured?
 *
 * Two of these colours were fixed in the stylesheet with no way to reach them:
 * the icons and the clock on the bar were `#fff`, and the played part of the
 * seek bar took the *waveform's* progress colour — an audio setting a video
 * block does not show. So the bar could be set to a pale grey and every icon on
 * it would vanish, and the one moving coloured thing on the picture could not
 * be changed at all.
 *
 * Making them settable is half of it. The other half is that the automatic
 * answer has to be right, because it is what every site that never touches
 * these gets — and "right" here means a real contrast ratio between what is
 * painted and what it is painted on, measured after the browser has resolved
 * every `color-mix()` and custom property.
 *
 * Contrast is read through a canvas rather than by parsing the computed value:
 * a `color-mix()` resolves to `color(srgb 0 0.7 0.78)`, whose numbers run 0–1,
 * and a regular expression reading those as 0–255 calls every mixed colour
 * black. That mistake passed a contrast check of mine once already.
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
	echo 'SKIP  no Chromium found; the controls were not measured' . PHP_EOL;
	exit( 0 );
}

$workdir = $root . '/build/.colour-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

foreach ( (array) glob( $root . '/build/*.{js,css}', GLOB_BRACE ) as $asset ) {
	copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
}

$renderer = new PlayerRenderer();

$cases = array(
	// What a site gets without touching anything.
	'dark-auto'   => array(),
	// The case that was broken: a pale bar kept its white icons.
	'light-auto'  => array( 'videoChromeColor' => '#f2f2f4' ),
	// And the case the settings exist for.
	'chosen'      => array(
		'videoChromeColor'   => '#101828',
		'videoControlColor'  => '#ffe08a',
		'videoProgressColor' => '#00c2d8',
	),
);

$sections = '';

foreach ( $cases as $name => $atts ) {
	$sections .= sprintf(
		'<section data-case="%s">%s</section>',
		esc_attr( $name ),
		$renderer->render(
			$atts + array(
				'src'        => 'https://cdn.example.com/clip.mp4',
				'title'      => 'Una clase',
				'showVolume' => 'yes',
				'showTime'   => 'yes',
			)
		)
	);
}

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>html,body{margin:0;background:#fff}section{margin:0 0 24px;max-width:640px}</style>
</head><body>
{$sections}
<script>
Object.defineProperty(HTMLMediaElement.prototype, 'duration', {
	get: function () { return 200; },
	configurable: true
});

window.imaginaPlayer = { restUrl: '', lazyInit: false, maxComputeBytes: 0, assetUrl: './', i18n: {} };
</script>
<script src="./frontend.js"></script>
<script>
setTimeout(function () {
	var probe = document.createElement('canvas').getContext('2d', { willReadFrequently: true });

	/*
	 * Every colour here is composited over the thing behind it before it is
	 * measured.
	 *
	 * `getImageData` hands back straight, un-premultiplied channels, so a rail
	 * drawn as 30% of near-black comes out of the canvas as near-black — and
	 * comparing that with a near-black played line said 1.29:1 for a pair that
	 * a viewer sees as a dark line on a light grey rail. Painting the backdrop
	 * first and the sample on top of it is what the browser does, so it is what
	 * the measurement has to do.
	 */
	function luminance(color, backdrop) {
		probe.clearRect(0, 0, 1, 1);
		probe.fillStyle = backdrop || '#ffffff';
		probe.fillRect(0, 0, 1, 1);
		probe.fillStyle = color;
		probe.fillRect(0, 0, 1, 1);

		var data = probe.getImageData(0, 0, 1, 1).data;
		var parts = [ data[0], data[1], data[2] ].map(function (n) {
			var c = n / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
		});
		return 0.2126 * parts[0] + 0.7152 * parts[1] + 0.0722 * parts[2];
	}

	/** Contrast of `a` on `b`, with both resolved over `backdrop` first. */
	function ratio(a, b, backdrop) {
		var x = luminance(a, backdrop);
		var y = luminance(b, backdrop);
		return Math.round(((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)) * 100) / 100;
	}

	/*
	 * What is behind the control bar. The bar itself is a gradient to
	 * transparent, so its own computed background is not a colour anything can
	 * be compared against — the chrome colour is, and it is what the gradient
	 * is made of at the bottom where the controls sit.
	 */
	function chrome(root) {
		return getComputedStyle(root).getPropertyValue('--imgp-chrome-bg').trim() || '#000000';
	}

	var report = {};

	document.querySelectorAll('section').forEach(function (section) {
		var root = section.querySelector('.imgp');
		var bar = chrome(root);
		var icon = section.querySelector('.imgp__vbtn--fullscreen') || section.querySelector('.imgp__skip');
		var title = section.querySelector('.imgp__title');
		var time = section.querySelector('.imgp__time--current');
		var progress = section.querySelector('.imgp__progress');
		var track = section.querySelector('.imgp--video .imgp__track') || section.querySelector('.imgp__track');

		report[section.getAttribute('data-case')] = {
			icon: icon ? ratio(getComputedStyle(icon).color, bar, bar) : null,
			title: title ? ratio(getComputedStyle(title).color, bar, bar) : null,
			// The chip carries its own backing, itself translucent over the bar.
			time: time
				? ratio(getComputedStyle(time).color, getComputedStyle(time).backgroundColor, bar)
				: null,
			// The played line has to be visible against the rail behind it, or
			// there is no way to see how far through the video you are. The
			// rail is translucent, so the bar is the backdrop for both.
			progressOnTrack: progress && track
				? ratio(getComputedStyle(progress).backgroundColor, getComputedStyle(track).backgroundColor, bar)
				: null,
			// And the actual colours, so a setting that silently did nothing
			// cannot pass on contrast alone.
			iconColor: icon ? getComputedStyle(icon).color : '',
			progressColor: progress ? getComputedStyle(progress).backgroundColor : ''
		};
	});

	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify(report);
	document.body.appendChild(pre);
}, 1200);
</script>
</body></html>
HTML;

$file = $workdir . '/colours.html';
file_put_contents( $file, $page );

$dom = (string) shell_exec(
	sprintf(
		'%s --headless=new --no-sandbox --disable-gpu --window-size=900,1400 --virtual-time-budget=9000 --dump-dom %s 2>/dev/null',
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

/*
 * 4.5:1 is the WCAG figure for body text. The icons and the clock are small and
 * sit over a moving picture, so there is no argument for a lower bar here.
 */
const READABLE = 4.5;

foreach ( array(
	'dark-auto'  => 'a control bar left alone',
	'light-auto' => 'a control bar set to a pale colour',
	'chosen'     => 'a control bar with colours chosen by hand',
) as $case => $label ) {
	echo PHP_EOL . '# ' . $label . PHP_EOL;

	$measured = $report[ $case ] ?? array();

	check( 'was measured', array() !== $measured );

	if ( array() === $measured ) {
		continue;
	}

	foreach ( array( 'icon' => 'the buttons', 'title' => 'the title', 'time' => 'the clock' ) as $key => $what ) {
		$ratio = $measured[ $key ] ?? null;

		check(
			$what . ' can be read against what is behind them',
			null !== $ratio && (float) $ratio >= READABLE,
			null === $ratio ? 'not found' : $ratio . ':1'
		);
	}

	/*
	 * The played line against its rail. A lower bar than text — it is a solid
	 * band several pixels tall, not a letterform — but it has to be a visible
	 * difference, and the rail is drawn from the control colour, so a change to
	 * one moves the other.
	 */
	$separation = $measured['progressOnTrack'] ?? null;

	check(
		'the played part of the seek bar stands out from the rest of it',
		null !== $separation && (float) $separation >= 1.6,
		null === $separation ? 'not found' : $separation . ':1'
	);
}

echo PHP_EOL . '# The colours are the ones that were asked for' . PHP_EOL;

/*
 * Contrast alone would be satisfied by a setting that does nothing, as long as
 * the default happened to read. These are the values themselves.
 */
$chosen = $report['chosen'] ?? array();

check(
	'a chosen button colour reaches the buttons',
	str_contains( (string) ( $chosen['iconColor'] ?? '' ), '255, 224, 138' ),
	(string) ( $chosen['iconColor'] ?? '' )
);

check(
	'and a chosen played colour reaches the seek bar',
	str_contains( (string) ( $chosen['progressColor'] ?? '' ), '0, 194, 216' ),
	(string) ( $chosen['progressColor'] ?? '' )
);

$dark  = $report['dark-auto'] ?? array();
$light = $report['light-auto'] ?? array();

check(
	'and the automatic answer changes with the bar rather than staying white',
	( $dark['iconColor'] ?? '' ) !== ( $light['iconColor'] ?? '' ),
	'both bars got ' . (string) ( $dark['iconColor'] ?? '?' )
);

echo PHP_EOL;
echo 0 === $failures ? 'All control colour checks passed.' . PHP_EOL : "{$failures} FAILURE(S)" . PHP_EOL;

exit( 0 === $failures ? 0 : 1 );
