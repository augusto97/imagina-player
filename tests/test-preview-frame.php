<?php
/**
 * The preview iframe.
 *
 * Both previews put the real player inside a frame sized to its content. If that
 * document can scroll, the editor loses the drag it needs and the block sprouts
 * scrollbars — which is exactly what it did.
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
	echo 'SKIP  no Chromium found; the preview frame was not checked' . PHP_EOL;
	exit( 0 );
}

$workdir = $root . '/build/.preview-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

// The frame loads these by URL, so they have to sit next to the page.
copy( $root . '/assets/preview-frame.css', $workdir . '/preview-frame.css' );
copy( $root . '/build/style-frontend.css', $workdir . '/style-frontend.css' );
copy( $root . '/build/frontend.js', $workdir . '/frontend.js' );

$renderer = new PlayerRenderer();
$peaks    = array();

for ( $i = 0; $i < 400; $i++ ) {
	$peaks[] = min( 1.0, max( 0.12, 0.55 + sin( $i * 0.35 ) * 0.25 + sin( $i * 1.7 ) * 0.2 ) );
}

$encoded = PeaksRepository::encode( $peaks );
$frames  = array();

// Every skin, because a layout that overflows does so on its own terms.
foreach ( array_keys( Skins::all() ) as $skin ) {
	$html = $renderer->render(
		array(
			'src'       => 'https://cdn.example.com/track.mp3',
			'title'     => 'Audio de prueba 1',
			'artist'    => 'Artista',
			'skin'      => $skin,
			'thumbnail' => 'https://cdn.example.com/cover.png',
			'showSkip'  => 'yes',
			'showSpeed' => 'yes',
		)
	);

	$frames[ $skin ] = str_replace(
		'data-imagina-player=',
		'data-peaks="' . $encoded . '" data-imagina-player=',
		$html
	);
}

// One page per skin would mean one browser launch per skin; a page of frames
// measures them all at once, at the width the editor gives them.
$frame_markup = '';

foreach ( $frames as $skin => $html ) {
	$doc = '<!doctype html><html><head><meta charset="utf-8">'
		. '<link rel="stylesheet" href="./preview-frame.css">'
		. '<link rel="stylesheet" href="./style-frontend.css">'
		. '</head><body>' . $html
		. '<script>window.imaginaPlayer={restUrl:"",lazyInit:false,maxComputeBytes:0,i18n:{}};</script>'
		. '<script src="./frontend.js"></script></body></html>';

	$frame_markup .= sprintf(
		'<iframe data-skin="%s" scrolling="no" srcdoc="%s"></iframe>',
		esc_attr( $skin ),
		esc_attr( $doc )
	);
}

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<style>
	body { margin: 0; width: 760px; }
	/* Deliberately shorter than the player. This is the state every frame is in
	   for the moment between loading and being measured, and it is where the
	   scrollbars came from: a vertical one appears, steals width, and the
	   content it squeezes grows a horizontal one underneath. */
	iframe { display: block; width: 100%; height: 70px; border: 0; overflow: hidden; pointer-events: none; }
</style>
</head><body>
{$frame_markup}
<script>
setTimeout(function () {
	var report = {};

	document.querySelectorAll('iframe').forEach(function (frame) {
		var doc = frame.contentDocument;
		var el = doc.documentElement;

		report[frame.getAttribute('data-skin')] = {
			overflowX: el.scrollWidth - el.clientWidth,
			// A scrollbar inside the frame eats into the width the content gets.
			widthLost: frame.clientWidth - el.clientWidth,
			height: Math.ceil(doc.body.scrollHeight),
			player: !!doc.querySelector('.imgp.is-enhanced')
		};
	});

	var out = document.createElement('pre');
	out.id = 'result';
	out.textContent = 'RESULT:' + JSON.stringify(report);
	document.body.appendChild(out);
}, 2200);
</script>
</body></html>
HTML;

$page_file = $workdir . '/frames.html';
file_put_contents( $page_file, $page );

$dom = (string) shell_exec(
	sprintf(
		'%s --headless --no-sandbox --disable-gpu --window-size=760,2400 --virtual-time-budget=9000 --dump-dom %s 2>/dev/null',
		escapeshellarg( $browser ),
		escapeshellarg( 'file://' . $page_file )
	)
);

if ( ! preg_match( '/RESULT:(\{.*?\})</s', $dom, $matches ) ) {
	echo 'FAIL  the frames did not report' . PHP_EOL;
	exit( 1 );
}

$report = json_decode( $matches[1], true );

check( 'every skin reported', is_array( $report ) && count( $report ) === count( Skins::all() ) );

foreach ( (array) $report as $skin => $data ) {
	check( "skin {$skin} enhances inside the frame", ! empty( $data['player'] ) );
	check(
		"skin {$skin} does not scroll sideways",
		0 >= (int) $data['overflowX'],
		(string) $data['overflowX'] . 'px'
	);
	check(
		"skin {$skin} grows no scrollbar when the frame is too short",
		0 === (int) $data['widthLost'],
		(string) $data['widthLost'] . 'px lost'
	);
	check(
		"skin {$skin} reports a usable height",
		(int) $data['height'] > 20 && (int) $data['height'] < 400,
		(string) $data['height'] . 'px'
	);
}

exec( 'rm -rf ' . escapeshellarg( $workdir ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
