<?php
/**
 * The transcript under the picture.
 *
 * Every subtitle line with its time, following playback and searchable. The
 * server prints the panel only where it can be filled — a real element with
 * subtitle tracks, and the author's say-so — and the browser fills it the
 * first time it is opened.
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

$renderer = new PlayerRenderer();
$tracks   = array( array( 'src' => 'https://cdn.example.com/es.vtt', 'label' => 'Español', 'srclang' => 'es' ) );

echo '# Where the panel is printed' . PHP_EOL;

$off  = $renderer->render( array( 'src' => 'https://cdn.example.com/clip.mp4', 'tracks' => $tracks ) );
$on   = $renderer->render( array( 'src' => 'https://cdn.example.com/clip.mp4', 'tracks' => $tracks, 'videoTranscript' => 'yes' ) );
$bare = $renderer->render( array( 'src' => 'https://cdn.example.com/clip.mp4', 'videoTranscript' => 'yes' ) );
$yt   = $renderer->render( array( 'src' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'tracks' => $tracks, 'videoTranscript' => 'yes' ) );
$mp3  = $renderer->render( array( 'src' => 'https://cdn.example.com/talk.mp3', 'tracks' => $tracks, 'videoTranscript' => 'yes' ) );

check( 'off by default', ! str_contains( $off, 'imgp__transcript' ) );
check( 'printed when the block asks', str_contains( $on, '<details class="imgp__transcript">' ) );
check( 'closed, with a summary to open it', str_contains( $on, '<summary class="imgp__transcript-summary">Transcript</summary>' ) && ! str_contains( $on, '<details class="imgp__transcript" open' ) );
check( 'with a search box', str_contains( $on, 'class="imgp__transcript-search"' ) );
check( 'under the picture, not in it', strpos( $on, 'imgp__transcript' ) > strpos( $on, '</div>', strpos( $on, 'imgp__stage' ) ) );
check( 'not without subtitles to read', ! str_contains( $bare, 'imgp__transcript' ) );
check( 'not for a provider video, whose subtitles cannot be read', ! str_contains( $yt, 'imgp__transcript' ) );
check( 'not for audio', ! str_contains( $mp3, 'imgp__transcript' ) );

echo PHP_EOL . '# In a browser' . PHP_EOL;

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
	echo 'SKIP  no Chromium found; the panel was not opened' . PHP_EOL;
	echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
	exit( $failures ? 1 : 0 );
}

$workdir = $root . '/build/.transcript-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

foreach ( (array) glob( $root . '/build/*.{js,css}', GLOB_BRACE ) as $asset ) {
	copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
}

file_put_contents(
	$workdir . '/es.vtt',
	"WEBVTT\n\n"
	. "00:00:00.000 --> 00:00:10.000\n<v Elízabeth>Bienvenidos a la <i>página</i> de precios\n\n"
	. "00:00:40.000 --> 00:00:50.000\nY ahora la página de contacto\n\n"
	. "00:01:30.000 --> 00:01:40.000\nGracias por acompañarnos\n"
);

$markup = $renderer->render(
	array(
		'src'             => 'https://cdn.example.com/clip.mp4',
		'tracks'          => array( array( 'src' => './es.vtt', 'label' => 'Español', 'srclang' => 'es' ) ),
		'videoTranscript' => 'yes',
	)
);

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>html,body{margin:0}#host{width:640px}</style>
</head><body><div id="host">{$markup}</div>
<script>
window.addEventListener('error', function (e) {
	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify({ error: e.message + ' @' + e.lineno });
	document.body.appendChild(pre);
});
Object.defineProperty(HTMLMediaElement.prototype, 'duration', { get: function () { return 200; }, configurable: true });
window.imaginaPlayer = { restUrl: '', lazyInit: false, maxComputeBytes: 0, assetUrl: './', i18n: { searchNone: 'Nada' } };
</script>
<script src="./frontend.js"></script>
<script>
setTimeout(function () {
	var out = {};
	var media = document.querySelector('.imgp__media');
	var clock = 0;
	Object.defineProperty(media, 'currentTime', { get: function () { return clock; }, set: function (v) { clock = v; }, configurable: true });
	var panel = document.querySelector('.imgp__transcript');
	out.emptyBeforeOpen = document.querySelectorAll('.imgp__cue').length === 0;
	panel.open = true;
	setTimeout(function () {
		var rows = document.querySelectorAll('.imgp__cue');
		out.rows = rows.length;
		out.first = rows[0] ? rows[0].textContent : '';
		out.hasMarkup = !!out.first && out.first.indexOf('<') > -1;
		clock = 45;
		media.dispatchEvent(new Event('timeupdate'));
		var current = document.querySelector('.imgp__cue.is-current');
		out.currentAt45 = current ? current.querySelector('.imgp__cue-at').textContent : null;
		if (rows[2]) { rows[2].click(); }
		out.clickedTo = clock;
		var input = document.querySelector('.imgp__transcript-search');
		input.value = 'pagina';
		input.dispatchEvent(new Event('input', { bubbles: true }));
		out.shownAfterSearch = Array.prototype.filter.call(document.querySelectorAll('.imgp__cue'), function (r) { return !r.hidden; }).length;
		input.value = 'zzz';
		input.dispatchEvent(new Event('input', { bubbles: true }));
		out.noneNote = document.querySelector('.imgp__transcript-note').textContent;
		var pre = document.createElement('pre');
		pre.textContent = 'RESULT:' + JSON.stringify(out);
		document.body.appendChild(pre);
	}, 1500);
}, 900);
</script>
</body></html>
HTML;

$file_path = $workdir . '/transcript.html';
file_put_contents( $file_path, $page );

$dom = (string) shell_exec(
	sprintf(
		'%s --headless=new --no-sandbox --disable-gpu --allow-file-access-from-files --window-size=900,900 --virtual-time-budget=12000 --dump-dom %s 2>/dev/null',
		escapeshellarg( $browser ),
		escapeshellarg( 'file://' . $file_path )
	)
);

exec( 'rm -rf ' . escapeshellarg( $workdir ) );

if ( ! preg_match( '/RESULT:(\{.*?\})</s', $dom, $matches ) ) {
	check( 'the page reported', false, 'no result' );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

$r = json_decode( html_entity_decode( $matches[1] ), true );

if ( isset( $r['error'] ) ) {
	check( 'the probe ran without throwing', false, (string) $r['error'] );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

check( 'nothing is built before the panel is opened', true === ( $r['emptyBeforeOpen'] ?? false ) );
check( 'opening it lists the three lines', 3 === (int) ( $r['rows'] ?? 0 ), (string) ( $r['rows'] ?? '?' ) );
check( 'each with its time and what was said, not the file', str_starts_with( (string) ( $r['first'] ?? '' ), '0:00' ) && false === ( $r['hasMarkup'] ?? true ), (string) ( $r['first'] ?? '' ) );
check( 'at 0:45 the second line is the current one', '0:40' === ( $r['currentAt45'] ?? null ), (string) ( $r['currentAt45'] ?? 'null' ) );
check( 'pressing a line moves the video there', 90 === (int) ( $r['clickedTo'] ?? -1 ), (string) ( $r['clickedTo'] ?? '?' ) );
check( 'typing narrows the list, accents or not', 2 === (int) ( $r['shownAfterSearch'] ?? 0 ), (string) ( $r['shownAfterSearch'] ?? '?' ) );
check( 'and a word nobody said says so', 'Nada' === ( $r['noneNote'] ?? '' ), (string) ( $r['noneNote'] ?? '' ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
