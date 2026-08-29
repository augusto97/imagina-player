<?php
/**
 * What "rounded corners" does to a video.
 *
 * The setting is one number and it did two things. On an audio player, asking
 * for a radius means asking for the card the controls sit in, so the shell
 * gained padding and a faint tint along with the curve — which is right, and is
 * what it was written for. The same class landed on a video, and there the
 * padding became a pale ring around the picture and the tint became a border
 * nobody asked for. Turning on rounded corners drew a frame.
 *
 * It is not a thing a string search in the stylesheet can settle: the rule that
 * causes it is correct where it was written. So this measures the rendered
 * boxes in a real browser — is the picture flush with the shell, is anything
 * painted behind it, and is the curve actually there — and it measures the
 * audio card at the same time, because the fix must not flatten the thing the
 * rule exists for.
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
	echo 'SKIP  no Chromium found; the corners were not measured' . PHP_EOL;
	exit( 0 );
}

$workdir = $root . '/build/.radius-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

foreach ( (array) glob( $root . '/build/*.{js,css}', GLOB_BRACE ) as $asset ) {
	copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
}

$renderer = new PlayerRenderer();

$cases = array(
	'video-square'  => array( 'src' => 'https://cdn.example.com/clip.mp4', 'title' => 'Sin radio' ),
	'video-rounded' => array( 'src' => 'https://cdn.example.com/clip.mp4', 'title' => 'Con radio', 'borderRadius' => 18 ),
	// The stacked skin puts the controls below the picture rather than over it,
	// so there the shell is the thing that has to round, not the stage.
	'video-stacked' => array( 'src' => 'https://cdn.example.com/clip.mp4', 'title' => 'Apilado', 'borderRadius' => 18, 'skin' => 'stacked' ),
	'audio-rounded' => array( 'src' => 'https://cdn.example.com/track.mp3', 'title' => 'Audio', 'borderRadius' => 18 ),
);

$sections = '';

foreach ( $cases as $name => $atts ) {
	$sections .= sprintf( '<section data-case="%s">%s</section>', esc_attr( $name ), $renderer->render( $atts ) );
}

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>html,body{margin:0;background:#fff}section{margin:0 0 28px;max-width:640px}</style>
</head><body>
{$sections}
<script>
window.imaginaPlayer = { restUrl: '', lazyInit: false, maxComputeBytes: 0, assetUrl: './', i18n: {} };
</script>
<script src="./frontend.js"></script>
<script>
setTimeout(function () {
	var report = {};

	document.querySelectorAll('section').forEach(function (section) {
		var root = section.querySelector('.imgp');
		var stage = section.querySelector('.imgp__stage');
		var inner = stage || section.querySelector('.imgp__bar');
		var rootBox = root.getBoundingClientRect();
		var innerBox = inner.getBoundingClientRect();
		var style = getComputedStyle(root);

		report[section.getAttribute('data-case')] = {
			/*
			 * The ring. Anything between the outside of the player and the
			 * picture inside it — padding, a border, or both — shows up here as
			 * a gap on the left and the right.
			 */
			inset: Math.round(innerBox.left - rootBox.left),
			// A background painted on the shell is the tint that reads as a
			// border once there is a gap for it to show through.
			background: style.backgroundColor,
			borderWidth: style.borderTopWidth,
			padding: style.paddingLeft,
			// The curve has to be somewhere, or the setting did nothing.
			rootRadius: parseFloat(style.borderTopLeftRadius) || 0,
			innerRadius: stage ? parseFloat(getComputedStyle(stage).borderTopLeftRadius) || 0 : 0,
			// And the picture must still be clipped by whatever carries it.
			clipped: stage ? getComputedStyle(stage).overflow : getComputedStyle(root).overflow
		};
	});

	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify(report);
	document.body.appendChild(pre);
}, 1100);
</script>
</body></html>
HTML;

$file = $workdir . '/radius.html';
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

/** Is a computed colour actually painted, or is it see-through? */
function painted( string $colour ): bool {
	if ( '' === $colour || 'transparent' === $colour ) {
		return false;
	}

	// `rgba(r, g, b, 0)` is the computed form of a transparent background.
	return ! (bool) preg_match( '/,\s*0\s*\)$/', $colour );
}

echo PHP_EOL . '# A rounded video is not a framed video' . PHP_EOL;

$square  = $report['video-square'] ?? array();
$rounded = $report['video-rounded'] ?? array();

check( 'both videos were measured', array() !== $square && array() !== $rounded );

check(
	'a video with no radius sits flush in its shell',
	0 === (int) ( $square['inset'] ?? -1 ),
	'inset ' . ( $square['inset'] ?? '?' ) . 'px'
);

/*
 * The report itself. Rounding the corners used to move the picture inwards by
 * the player's gap and paint the shell behind it, which is the pale ring in the
 * screenshot.
 */
check(
	'and asking for rounded corners does not push it inwards',
	0 === (int) ( $rounded['inset'] ?? -1 ),
	'inset ' . ( $rounded['inset'] ?? '?' ) . 'px — the ring is back'
);

check(
	'nothing is painted behind the picture for a gap to show',
	! painted( (string) ( $rounded['background'] ?? '' ) ),
	(string) ( $rounded['background'] ?? '' )
);

check(
	'and no border is drawn either',
	0.0 === (float) ( $rounded['borderWidth'] ?? 1 ),
	(string) ( $rounded['borderWidth'] ?? '?' )
);

/*
 * Having established there is no frame, the corners still have to be round —
 * otherwise "no ring" would be satisfied by the setting doing nothing at all.
 */
check(
	'the corners are actually rounded',
	18.0 === (float) ( $rounded['innerRadius'] ?? 0 ),
	'stage radius ' . ( $rounded['innerRadius'] ?? '?' )
);

check(
	'and the picture is clipped to them',
	'hidden' === ( $rounded['clipped'] ?? '' ),
	(string) ( $rounded['clipped'] ?? '?' )
);

check(
	'a video with no radius has square corners',
	0.0 === (float) ( $square['innerRadius'] ?? 1 ),
	'stage radius ' . ( $square['innerRadius'] ?? '?' )
);

/*
 * The stacked skin is the one case where the shell has to carry the curve: its
 * controls sit under the picture, so rounding only the stage would leave two
 * square corners at the bottom.
 */
$stacked = $report['video-stacked'] ?? array();

echo PHP_EOL . '# The skin whose controls are not on the picture' . PHP_EOL;

check(
	'stacked rounds the whole player, since its bar is below the picture',
	18.0 === (float) ( $stacked['rootRadius'] ?? 0 ),
	'shell radius ' . ( $stacked['rootRadius'] ?? '?' )
);

check(
	'and still does not inset the picture',
	0 === (int) ( $stacked['inset'] ?? -1 ),
	'inset ' . ( $stacked['inset'] ?? '?' ) . 'px'
);

/*
 * The other half. The padding and the tint are the audio player's card, and
 * removing them from a video must not remove them from the thing they were
 * written for.
 */
echo PHP_EOL . '# The audio card still is one' . PHP_EOL;

$audio = $report['audio-rounded'] ?? array();

check(
	'an audio player keeps its padding',
	(float) ( $audio['inset'] ?? 0 ) > 0,
	'inset ' . ( $audio['inset'] ?? '?' ) . 'px'
);

check(
	'and the tint that makes it a card',
	painted( (string) ( $audio['background'] ?? '' ) ),
	(string) ( $audio['background'] ?? '' )
);

check(
	'and its corners are round',
	18.0 === (float) ( $audio['rootRadius'] ?? 0 ),
	'shell radius ' . ( $audio['rootRadius'] ?? '?' )
);

echo PHP_EOL;
echo 0 === $failures ? 'All corner checks passed.' . PHP_EOL : "{$failures} FAILURE(S)" . PHP_EOL;

exit( 0 === $failures ? 0 : 1 );
