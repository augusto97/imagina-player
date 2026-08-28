<?php
/**
 * Can a person actually press the controls?
 *
 * This exists because of a bug that every other test in the suite was happy
 * with. The seek bar on a video was drawn correctly — the track paints at the
 * baseline and looks exactly like a seek bar — while the element a pointer has
 * to land on was zero pixels tall, because everything inside the scrubber is
 * positioned absolutely and the video rule set the box to `height: auto`.
 *
 * Nothing that checks markup, or settings, or even geometry of the visible
 * parts would catch that: the bar was there, the code behind it was correct,
 * and dispatching an event straight at the element worked. What nobody could
 * do was hit it.
 *
 * So this asks the only question that matters for a control: at the point where
 * it appears, is it the thing the browser would deliver the click to.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Peaks\PeaksRepository;
use ImaginaPlayer\Player\Skins;
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

	return '';
}

$browser = find_browser();

if ( '' === $browser ) {
	echo 'SKIP  no Chromium found; the controls were not pressed' . PHP_EOL;
	exit( 0 );
}

$workdir = $root . '/build/.hit-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

foreach ( (array) glob( $root . '/build/*.{js,css}', GLOB_BRACE ) as $asset ) {
	copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
}

$peaks = array();

for ( $i = 0; $i < 400; $i++ ) {
	$peaks[] = min( 1.0, max( 0.12, 0.55 + sin( $i * 0.35 ) * 0.25 ) );
}

$encoded  = PeaksRepository::encode( $peaks );
$renderer = new PlayerRenderer();
$sections = '';

foreach ( array_keys( Skins::all() ) as $skin ) {
	$html = $renderer->render(
		array(
			'src'        => 'https://cdn.example.com/track.mp3',
			'title'      => 'Un episodio',
			'artist'     => 'Alguien',
			'skin'       => $skin,
			'showSkip'   => 'yes',
			'showSpeed'  => 'yes',
			'showVolume' => 'yes',
		)
	);

	$html      = str_replace( 'data-imagina-player=', 'data-peaks="' . $encoded . '" data-imagina-player=', $html );
	$sections .= sprintf( '<section data-case="audio-%s">%s</section>', esc_attr( $skin ), $html );
}

$sections .= sprintf(
	'<section data-case="video">%s</section>',
	$renderer->render( array( 'src' => 'https://cdn.example.com/clip.mp4', 'title' => 'Un vídeo', 'showVolume' => 'yes' ) )
);

$sections .= sprintf(
	'<section data-case="video-youtube">%s</section>',
	$renderer->render( array( 'src' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'title' => 'En YouTube' ) )
);

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>html,body{margin:0}section{margin:0 0 28px;max-width:760px}</style>
</head><body>
{$sections}
<script>
// A length, so the seek bar has something to represent.
Object.defineProperty(HTMLMediaElement.prototype, 'duration', {
	get: function () { return 200; },
	configurable: true
});

window.imaginaPlayer = { restUrl: '', lazyInit: false, maxComputeBytes: 0, assetUrl: './', i18n: {} };
</script>
<script src="./frontend.js"></script>
</body></html>
HTML;

$probe = <<<'JS'
<script>
/** Is `el` what a click at its own middle would actually reach? */
function reachable(el) {
	var box = el.getBoundingClientRect();

	if (box.width < 1 || box.height < 1) {
		return { ok: false, why: Math.round(box.width) + 'x' + Math.round(box.height) };
	}

	var hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);

	if (!hit) {
		return { ok: false, why: 'nothing at that point' };
	}

	// The element itself, something inside it, or a label wrapping it.
	if (el === hit || el.contains(hit) || hit.contains(el)) {
		return { ok: true };
	}

	return { ok: false, why: 'covered by ' + (String(hit.className) || hit.tagName) };
}

window.__measure = function () {
	var report = {};

	document.querySelectorAll('section').forEach(function (section) {
		var found = {};

		/*
		 * Into view first. `elementFromPoint` takes viewport coordinates and
		 * answers null for anything outside it, so measuring a player below the
		 * fold reports every control as unreachable — which is a fact about the
		 * window, not about the player.
		 */
		section.scrollIntoView({ block: 'center' });

		/*
		 * Every control the renderer put in this player, by name. Anything
		 * hidden is skipped — a player without a download button is not a
		 * player with an unreachable one.
		 */
		[
			['seek', '.imgp__seek'],
			['play', '.imgp__play'],
			['bigplay', '.imgp__bigplay'],
			['mute', '.imgp__mute'],
			['speed', '.imgp__speed'],
			['skip-back', '.imgp__skip--back'],
			['skip-forward', '.imgp__skip--forward'],
			['volume', '.imgp__volume-slider'],
			['fullscreen', '.imgp__vbtn--fullscreen'],
			['menu', '.imgp__vbtn--menu']
		].forEach(function (pair) {
			var el = section.querySelector(pair[1]);

			if (!el || el.hidden) { return; }

			var style = getComputedStyle(el);

			if ('none' === style.display || 'hidden' === style.visibility) { return; }

			found[pair[0]] = reachable(el);
		});

		report[section.getAttribute('data-case')] = found;
	});

	return report;
};

setTimeout(function () {
	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify(window.__measure());
	document.body.appendChild(pre);
}, 1100);
</script>
JS;

$file = $workdir . '/hits.html';
file_put_contents( $file, str_replace( '</body></html>', $probe . '</body></html>', $page ) );

$dom = (string) shell_exec(
	sprintf(
		'%s --headless=new --no-sandbox --disable-gpu --window-size=900,900 --virtual-time-budget=9000 --dump-dom %s 2>/dev/null',
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

echo PHP_EOL . '# Every control can be pressed where it appears' . PHP_EOL;

$total = 0;

foreach ( (array) $report as $case => $controls ) {
	$broken = array();

	foreach ( (array) $controls as $name => $result ) {
		++$total;

		if ( empty( $result['ok'] ) ) {
			$broken[] = $name . ' (' . ( $result['why'] ?? '?' ) . ')';
		}
	}

	check(
		"{$case}: all " . count( (array) $controls ) . ' controls',
		array() === $broken,
		implode( ', ', $broken )
	);
}

/*
 * A guard on the guard. If the selectors stop matching — a class renamed, the
 * markup restructured — every case above would pass by finding nothing at all.
 */
check( 'and the probe actually found controls to press', $total > 40, (string) $total );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
