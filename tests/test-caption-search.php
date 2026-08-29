<?php
/**
 * Finding the moment a word is said.
 *
 * Presto keeps this for its paid tier and it is the one feature of theirs that
 * changes what a long video is for: on a forty-minute talk, getting to the part
 * about pricing without dragging the bar and guessing is the difference between
 * a video somebody watches and a video somebody uses.
 *
 * The text is the subtitles the player already has, so there is nothing to
 * index on the server and nothing extra to fetch. What is checked here is that
 * the matching is worth using — accents folded, markup out of the cue text —
 * and that picking a result actually moves the video.
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

echo PHP_EOL . '# The button is offered only when there is text to search' . PHP_EOL;

$renderer = new PlayerRenderer();
$tracks   = array( array( 'src' => 'https://cdn.example.com/es.vtt', 'label' => 'Español', 'srclang' => 'es' ) );

check(
	'a video with subtitles gets the button',
	str_contains( $renderer->render( array( 'src' => 'https://cdn.example.com/clip.mp4', 'tracks' => $tracks ) ), 'vbtn--search' )
);

check(
	'a video without them does not',
	! str_contains( $renderer->render( array( 'src' => 'https://cdn.example.com/clip.mp4' ) ), 'vbtn--search' )
);

check(
	'and the block can turn it off',
	! str_contains( $renderer->render( array( 'src' => 'https://cdn.example.com/clip.mp4', 'tracks' => $tracks, 'videoSearch' => 'no' ) ), 'vbtn--search' )
);

echo PHP_EOL . '# The matching' . PHP_EOL;

$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );

if ( '' === $node ) {
	echo 'SKIP  no node; the matcher was not exercised' . PHP_EOL;
} else {
	shell_exec(
		sprintf(
			'cd %s && npx tsc assets/src/frontend/search.ts --outDir build/.search-check --module es2020 --target es2020 --moduleResolution bundler --lib es2020,dom 2>&1',
			escapeshellarg( $root )
		)
	);

	$compiled = $root . '/build/.search-check/search.js';

	if ( ! file_exists( $compiled ) ) {
		echo 'SKIP  the matcher would not compile' . PHP_EOL;
	} else {
		$script = $root . '/build/.search-check.mjs';

		file_put_contents(
			$script,
			"import { search, fold } from '" . $compiled . "';\n"
			. "const hits = [\n"
			. "  { at: 0, text: 'Bienvenidos a la página de precios' },\n"
			. "  { at: 12.5, text: 'Aquí hablamos del plan básico' },\n"
			. "  { at: 40, text: 'Y ahora la PÁGINA de contacto' },\n"
			. "  { at: 90, text: 'Gracias por acompañarnos' }\n"
			. "];\n"
			. "console.log(JSON.stringify({\n"
			. "  plain: search(hits, 'precios').map(h => h.at),\n"
			. "  unaccented: search(hits, 'pagina').map(h => h.at),\n"
			. "  accented: search(hits, 'página').map(h => h.at),\n"
			. "  cased: search(hits, 'PÁGINA').map(h => h.at),\n"
			. "  tooShort: search(hits, 'a').length,\n"
			. "  nothing: search(hits, 'zzz').length,\n"
			. "  limited: search(Array.from({length: 40}, (_, i) => ({ at: i, text: 'hola' })), 'hola').length,\n"
			. "  folded: fold('ÁÉÍÓÚñÑ')\n"
			. "}));\n"
		);

		$raw    = (string) shell_exec( 'node ' . escapeshellarg( $script ) . ' 2>/dev/null' );
		$parsed = json_decode( trim( $raw ), true );

		exec( 'rm -rf ' . escapeshellarg( $root . '/build/.search-check' ) . ' ' . escapeshellarg( $script ) );

		if ( ! is_array( $parsed ) ) {
			check( 'the matcher ran', false, trim( $raw ) );
		} else {
			check( 'a word is found where it is said', array( 0 ) === $parsed['plain'], wp_json_encode( $parsed['plain'] ) );

			/*
			 * Spanish is the first language this is used in. A search that only
			 * matches when the accent is typed is a search nobody uses twice.
			 */
			check( 'typing it without the accent still finds it', array( 0, 40 ) === $parsed['unaccented'], wp_json_encode( $parsed['unaccented'] ) );
			check( 'and with it', array( 0, 40 ) === $parsed['accented'], wp_json_encode( $parsed['accented'] ) );
			check( 'and in capitals', array( 0, 40 ) === $parsed['cased'], wp_json_encode( $parsed['cased'] ) );
			check( 'ñ survives the folding', str_contains( (string) $parsed['folded'], 'ñ' ), (string) $parsed['folded'] );

			// A single letter matches most of a transcript, which is not a result.
			check( 'one letter is not a search', 0 === $parsed['tooShort'] );
			check( 'and a word nobody said finds nothing', 0 === $parsed['nothing'] );
			check( 'a common word does not return the whole transcript', 12 === $parsed['limited'], (string) $parsed['limited'] );
		}
	}
}

echo PHP_EOL . '# Using it' . PHP_EOL;

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
	echo 'SKIP  no Chromium found; the box was not opened' . PHP_EOL;
	echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
	exit( $failures ? 1 : 0 );
}

$workdir = $root . '/build/.search-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

foreach ( (array) glob( $root . '/build/*.{js,css}', GLOB_BRACE ) as $asset ) {
	copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
}

// A real subtitle file, with the markup a real one carries.
file_put_contents(
	$workdir . '/es.vtt',
	"WEBVTT\n\n"
	. "00:00:00.000 --> 00:00:10.000\n<v Elízabeth>Bienvenidos a la <i>página</i> de precios\n\n"
	. "00:00:40.000 --> 00:00:50.000\nY ahora la página de contacto\n\n"
	. "00:01:30.000 --> 00:01:40.000\nGracias por acompañarnos\n"
);

$html = $renderer->render(
	array(
		'src'    => 'https://cdn.example.com/clip.mp4',
		'tracks' => array( array( 'src' => './es.vtt', 'label' => 'Español', 'srclang' => 'es' ) ),
	)
);

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>html,body{margin:0}</style>
</head><body>
{$html}
<script>
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
setTimeout(function () {
	var out = {};
	var button = document.querySelector('.imgp__vbtn--search');

	out.buttonShown = !!button && !button.hidden;
	button.click();

	// The chunk loads and the cues have to arrive before anything can match.
	setTimeout(function () {
		var input = document.querySelector('.imgp__search-input');

		out.boxOpened = !!input;

		if (!input) {
			var early = document.createElement('pre');
			early.textContent = 'RESULT:' + JSON.stringify(out);
			document.body.appendChild(early);

			return;
		}

		input.value = 'pagina';
		input.dispatchEvent(new Event('input', { bubbles: true }));

		setTimeout(function () {
			var rows = document.querySelectorAll('.imgp__search-hit');

			out.results = rows.length;
			out.first = rows[0] ? rows[0].textContent : null;

			// The cue carries <v Elízabeth> and <i>; neither is what was said.
			out.hasMarkup = !!out.first && (out.first.indexOf('<') > -1 || out.first.indexOf('Elízabeth>') > -1);

			if (rows[1]) {
				rows[1].click();
			}

			setTimeout(function () {
				out.movedTo = Math.round(document.querySelector('.imgp__media').currentTime);
				out.closedAfterPick = !!document.querySelector('.imgp__menu').hidden;

				var pre = document.createElement('pre');
				pre.textContent = 'RESULT:' + JSON.stringify(out);
				document.body.appendChild(pre);
			}, 300);
		}, 500);
	}, 900);
}, 1100);
</script>
JS;

$file = $workdir . '/search.html';
file_put_contents( $file, str_replace( '</body></html>', $probe . '</body></html>', $page ) );

$dom = (string) shell_exec(
	sprintf(
		'%s --headless=new --no-sandbox --disable-gpu --allow-file-access-from-files --window-size=900,700 --virtual-time-budget=12000 --dump-dom %s 2>/dev/null',
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

check( 'the button is there to press', true === ( $r['buttonShown'] ?? false ) );
check( 'pressing it opens a box', true === ( $r['boxOpened'] ?? false ) );
check( 'typing finds the two lines that say it', 2 === (int) ( $r['results'] ?? 0 ), (string) ( $r['results'] ?? '?' ) );

/*
 * A cue carries speaker names and italics. Showing `<v Elízabeth>Bienvenidos`
 * as a search result is showing the file rather than what was said.
 */
check( 'and shows what was said, not the file', false === ( $r['hasMarkup'] ?? true ), (string) ( $r['first'] ?? '' ) );

check( 'picking one moves the video to that moment', 40 === (int) ( $r['movedTo'] ?? -1 ), (string) ( $r['movedTo'] ?? '?' ) );
check( 'and closes the box', true === ( $r['closedAfterPick'] ?? false ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
