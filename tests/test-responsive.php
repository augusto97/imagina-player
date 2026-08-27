<?php
/**
 * The player at phone widths.
 *
 * Overflow is a layout fact, not an opinion: each skin is laid out at a set of
 * real viewport widths, with every control turned on and a long title, and asked
 * whether it fits.
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

$browser = find_browser();

if ( '' === $browser ) {
	echo 'SKIP  no Chromium found; responsive layout not checked' . PHP_EOL;
	exit( 0 );
}

$workdir = $root . '/build/.responsive-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

copy( $root . '/build/style-frontend.css', $workdir . '/style-frontend.css' );
copy( $root . '/build/frontend.js', $workdir . '/frontend.js' );

$peaks = array();

for ( $i = 0; $i < 400; $i++ ) {
	$peaks[] = min( 1.0, max( 0.12, 0.55 + sin( $i * 0.35 ) * 0.25 + sin( $i * 1.7 ) * 0.2 ) );
}

$encoded  = PeaksRepository::encode( $peaks );
$renderer = new PlayerRenderer();

// Worst case on purpose: every control on, a long title, a long duration.
$cases = '';

foreach ( array_keys( Skins::all() ) as $skin ) {
	$html = $renderer->render(
		array(
			'src'          => 'https://cdn.example.com/track.mp3',
			'title'        => 'Audio de prueba 1 — conferencia completa',
			'artist'       => 'Elízabeth Guerra Gómez',
			'skin'         => $skin,
			'thumbnail'    => 'https://cdn.example.com/cover.png',
			'showSkip'     => 'yes',
			'showSpeed'    => 'yes',
			'showDownload' => 'yes',
			'showVolume'   => 'yes',
		)
	);

	$html = str_replace( 'data-imagina-player=', 'data-peaks="' . $encoded . '" data-imagina-player=', $html );

	$cases .= sprintf( '<section data-skin="%s">%s</section>', esc_attr( $skin ), $html );
}

$widths = array( 320, 360, 414, 768 );
$page   = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>
	html,body{margin:0;padding:0}
	body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
	/* No page padding: any overflow is the player's own, not the harness's. */
	section{padding:0;margin:0 0 24px}
</style>
</head><body>
{$cases}
<script>window.imaginaPlayer={restUrl:"",lazyInit:false,maxComputeBytes:0,i18n:{}};</script>
<script src="./frontend.js"></script>
<script>
window.__measure = function () {
	var report = {};

	document.querySelectorAll('section').forEach(function (section) {
		var player = section.querySelector('.imgp');
		var worst = 0;
		var culprit = '';

		// Walk the player and find anything sticking out past the section.
		section.querySelectorAll('*').forEach(function (el) {
			var over = Math.round(el.getBoundingClientRect().right - section.getBoundingClientRect().right);
			if (over > worst) {
				worst = over;
				culprit = el.className && el.className.baseVal === undefined ? String(el.className) : el.tagName;
			}
		});

		report[section.getAttribute('data-skin')] = {
			documentOverflow: Math.round(document.documentElement.scrollWidth - document.documentElement.clientWidth),
			overflow: worst,
			culprit: culprit,
			enhanced: !!(player && player.classList.contains('is-enhanced'))
		};
	});

	return report;
};
</script>
</body></html>
HTML;

$page_file = $workdir . '/player.html';
file_put_contents( $page_file, $page );

foreach ( $widths as $width ) {
	// One launch per width: the layout has to be measured after a real reflow.
	$probe = str_replace(
		'</body></html>',
		'<script>setTimeout(function(){var o=document.createElement("pre");o.id="result";'
		. 'o.textContent="RESULT:"+JSON.stringify(window.__measure());document.body.appendChild(o);},1600);</script></body></html>',
		$page
	);

	file_put_contents( $page_file, $probe );

	$dom = (string) shell_exec(
		sprintf(
			'%s --headless --no-sandbox --disable-gpu --window-size=%d,2000 --virtual-time-budget=7000 --dump-dom %s 2>/dev/null',
			escapeshellarg( $browser ),
			$width,
			escapeshellarg( 'file://' . $page_file )
		)
	);

	if ( ! preg_match( '/RESULT:(\{.*?\})</s', $dom, $matches ) ) {
		check( "the page reported at {$width}px", false );
		continue;
	}

	$report = json_decode( $matches[1], true );

	foreach ( (array) $report as $skin => $data ) {
		check(
			"{$skin} fits at {$width}px",
			(int) $data['overflow'] <= 1 && (int) $data['documentOverflow'] <= 0,
			$data['overflow'] . 'px over via ' . $data['culprit']
				. ', page ' . $data['documentOverflow'] . 'px'
		);
	}
}

exec( 'rm -rf ' . escapeshellarg( $workdir ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
