<?php
/**
 * Subtitles and chapters.
 *
 * The conversion is the part worth testing hardest. A malformed timing line in
 * a WebVTT file does not fail loudly — the browser drops that cue and, in most
 * engines, every cue after it. So a converter that is nearly right produces a
 * video whose subtitles stop halfway through, which is worse than none.
 */

$plugin = dirname( __DIR__ ) . '/';

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

require $plugin . 'tests/bootstrap.php';

use ImaginaPlayer\Media\Captions;
use ImaginaPlayer\Player\Attributes;
use ImaginaPlayer\Render\PlayerRenderer;

echo PHP_EOL . '# SubRip to WebVTT' . PHP_EOL;

$srt = "1\r\n00:00:01,500 --> 00:00:04,000\r\nHola, bienvenido.\r\n\r\n"
	. "2\r\n00:01:02,250 --> 00:01:05,750\r\nEsta es la segunda línea.\r\nY sigue aquí.\r\n";

$vtt = Captions::srt_to_vtt( $srt );

check( 'the file starts with the magic line', str_starts_with( $vtt, 'WEBVTT' ), substr( $vtt, 0, 20 ) );
check( 'commas become dots in the timings', str_contains( $vtt, '00:00:01.500 --> 00:00:04.000' ), $vtt );
check( 'the second cue converts too', str_contains( $vtt, '00:01:02.250 --> 00:01:05.750' ) );
check( 'cue numbers are dropped', ! (bool) preg_match( '/^\s*\d+\s*$/m', $vtt ), $vtt );
check( 'the text survives', str_contains( $vtt, 'Hola, bienvenido.' ) );
check( 'and so do multi-line cues', str_contains( $vtt, "Esta es la segunda línea.\nY sigue aquí." ) );
check( 'Windows line endings are normalised', ! str_contains( $vtt, "\r" ) );

// A byte-order mark before WEBVTT invalidates the whole file.
$bom = Captions::srt_to_vtt( "\xEF\xBB\xBF1\n00:00:00,000 --> 00:00:01,000\nHola\n" );
check( 'a byte-order mark is stripped', str_starts_with( $bom, 'WEBVTT' ), bin2hex( substr( $bom, 0, 6 ) ) );

// A cue whose first line of text is a bare number must keep it.
$numeric = Captions::srt_to_vtt( "1\n00:00:00,000 --> 00:00:02,000\n42\n" );
check( 'a cue whose text is just a number keeps it', str_contains( $numeric, '42' ), $numeric );

// SRT sometimes carries positioning; VTT understands the same settings.
$positioned = Captions::srt_to_vtt( "1\n00:00:00,000 --> 00:00:02,000 line:90% align:center\nAbajo\n" );
check( 'cue settings are carried across', str_contains( $positioned, 'line:90% align:center' ), $positioned );

// Single-digit hours and short milliseconds are both legal in the wild.
$loose = Captions::srt_to_vtt( "1\n0:00:01,5 --> 0:00:02,25\nCorto\n" );
check( 'loose timings are normalised to two digits and three decimals', str_contains( $loose, '00:00:01.500 --> 00:00:02.250' ), $loose );

// Already-VTT input passing through the converter should not be mangled.
check( 'a .vtt file is recognised as needing no conversion', ! Captions::is_srt( 'https://example.test/subs.vtt' ) );
check( 'and a .srt one as needing it', Captions::is_srt( 'https://example.test/subs.srt' ) );
check( 'a query string does not confuse the extension', Captions::is_srt( 'https://example.test/subs.srt?v=2' ) );

echo PHP_EOL . '# The caption endpoint only reads this site\'s own subtitles' . PHP_EOL;

$uploads = sys_get_temp_dir() . '/imgp-caps-' . bin2hex( random_bytes( 4 ) );
mkdir( $uploads . '/2026/08', 0777, true );

$GLOBALS['stub_uploads_dir'] = $uploads;
$GLOBALS['stub_uploads_url'] = 'https://example.test/wp-content/uploads';

file_put_contents( $uploads . '/2026/08/clase.srt', $srt );
file_put_contents( $uploads . '/2026/08/clase.vtt', "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nYa es vtt\n" );

// Something that is not a subtitle, to be refused.
file_put_contents( $uploads . '/secret.php', '<?php echo "nope";' );

// A real, readable subtitle file *outside* the uploads tree, so the traversal
// case fails on containment rather than on the file simply not existing.
file_put_contents( '/tmp/outside.vtt', "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nfuera\n" );

$read = Captions::read( 'https://example.test/wp-content/uploads/2026/08/clase.srt' );
check( 'an SRT under uploads is read and converted', is_string( $read ) && str_starts_with( (string) $read, 'WEBVTT' ) );

$read_vtt = Captions::read( 'https://example.test/wp-content/uploads/2026/08/clase.vtt' );
check( 'a VTT under uploads is read as it is', is_string( $read_vtt ) && str_contains( (string) $read_vtt, 'Ya es vtt' ) );

check(
	'the same file over http rather than https still resolves',
	is_string( Captions::read( 'http://example.test/wp-content/uploads/2026/08/clase.srt' ) ),
	'a site reachable on both would otherwise fail for half its visitors'
);

$refusals = array(
	'a PHP file'                    => 'https://example.test/wp-content/uploads/secret.php',
	'a path climbing out with ..'   => 'https://example.test/wp-content/uploads/../../wp-config.php',
	// Same climb, but wearing a subtitle extension: only the resolved-path check
	// can stop this one, and the first version of this test could not tell the
	// difference because the extension check was catching everything.
	'a climb wearing a .vtt name'   => 'https://example.test/wp-content/uploads/../../../tmp/outside.vtt',
	'an absolute path elsewhere'    => 'https://example.test/etc/passwd.vtt',
	'another site entirely'         => 'https://evil.test/wp-content/uploads/2026/08/clase.srt',
	'a file that is not there'      => 'https://example.test/wp-content/uploads/2026/08/missing.vtt',
);

foreach ( $refusals as $label => $url ) {
	check( 'refuses ' . $label, null === Captions::read( $url ), $url );
}

// Climbing out with an encoded traversal, in case the plain one was the only
// shape considered.
check(
	'refuses an encoded traversal too',
	null === Captions::read( 'https://example.test/wp-content/uploads/%2e%2e/%2e%2e/wp-config.php' )
);

echo PHP_EOL . '# Chapters' . PHP_EOL;

$chapters = Attributes::sanitize_chapters(
	array(
		array( 'start' => '2:00', 'title' => 'Segunda parte' ),
		array( 'start' => 0, 'title' => 'Introducción' ),
		array( 'start' => '1:00', 'title' => 'Primera parte' ),
		array( 'start' => '1:00', 'title' => 'Duplicada' ),
		array( 'start' => 30, 'title' => '' ),
	)
);

check( 'chapters are sorted by time', array_column( $chapters, 'title' ) === array( 'Introducción', 'Primera parte', 'Segunda parte' ), implode( ' / ', array_column( $chapters, 'title' ) ) );
check( 'mm:ss is understood', 120.0 === $chapters[2]['start'], (string) $chapters[2]['start'] );
check( 'a chapter with no title is dropped', 3 === count( $chapters ) );
check( 'and a duplicate start too', 3 === count( $chapters ), 'two cues at the same second is a zero-length cue' );

check( '1:30 is ninety seconds', 90.0 === Attributes::to_seconds( '1:30' ) );
check( '0:01:30 is too', 90.0 === Attributes::to_seconds( '0:01:30' ) );
check( 'a bare number is seconds', 90.0 === Attributes::to_seconds( 90 ) );
check( 'nonsense is zero, not a crash', 0.0 === Attributes::to_seconds( 'later' ) );

$vtt = Captions::chapters_vtt( $chapters, 180.0 );

check( 'the chapter track is WebVTT', str_starts_with( $vtt, 'WEBVTT' ) );
check( 'each cue runs to the next chapter', str_contains( $vtt, '00:00:00.000 --> 00:01:00.000' ), $vtt );
check( 'and the last one to the end of the video', str_contains( $vtt, '00:02:00.000 --> 00:03:00.000' ), $vtt );
check( 'the titles are the cue text', str_contains( $vtt, 'Introducción' ) );
check( 'no chapters means no track at all', '' === Captions::chapters_vtt( array() ) );

// Without a duration the last cue still has to end somewhere sane.
$open = Captions::chapters_vtt( $chapters, 0.0 );
check( 'an unknown duration still closes the last cue', str_contains( $open, '00:02:00.000 --> 24:02:00.000' ), $open );

echo PHP_EOL . '# In the rendered player' . PHP_EOL;

$renderer = new PlayerRenderer();

$html = $renderer->render(
	array(
		'src'      => 'https://example.test/wp-content/uploads/lesson.mp4',
		'tracks'   => array(
			array( 'src' => 'https://example.test/wp-content/uploads/2026/08/clase.vtt', 'srclang' => 'es', 'label' => 'Español', 'default' => true ),
			array( 'src' => 'https://example.test/wp-content/uploads/2026/08/clase.srt', 'srclang' => 'en', 'label' => 'English' ),
		),
		'chapters' => array(
			array( 'start' => 0, 'title' => 'Intro' ),
			array( 'start' => 60, 'title' => 'Demo' ),
		),
	)
);

check( 'a VTT track is linked straight to the file', str_contains( $html, 'clase.vtt' ), $html );
check( 'an SRT track goes through the converter', str_contains( $html, '/caption?src=' ), $html );
check( 'the default track is marked', (bool) preg_match( '#<track[^>]*srclang="es"[^>]*default#', $html ), $html );
check( 'the other one is not', ! (bool) preg_match( '#<track[^>]*srclang="en"[^>]*default#', $html ) );
check( 'chapters are inlined rather than fetched', str_contains( $html, 'kind="chapters" src="data:text/vtt' ), $html );
check( 'the subtitles button appears', str_contains( $html, 'imgp__vbtn--captions' ) );
check( 'the chapters button appears', str_contains( $html, 'imgp__vbtn--chapters' ) );
check( 'and the menu they share', str_contains( $html, 'imgp__menu' ) );
check( 'chapter starts reach the client for the markers', str_contains( $html, '&quot;chapters&quot;' ) );

// A video with neither must not grow buttons that do nothing.
$plain = $renderer->render( array( 'src' => 'https://example.test/wp-content/uploads/lesson.mp4' ) );

check( 'no subtitles means no subtitles button', ! str_contains( $plain, 'imgp__vbtn--captions' ) );
check( 'no chapters means no chapters button', ! str_contains( $plain, 'imgp__vbtn--chapters' ) );

// Two default tracks is not a state a browser has an answer for.
$two = Attributes::sanitize_tracks(
	array(
		array( 'src' => 'https://example.test/a.vtt', 'default' => true ),
		array( 'src' => 'https://example.test/b.vtt', 'default' => true ),
	)
);

check( 'only the first default track stays the default', true === $two[0]['default'] && false === $two[1]['default'] );

$hostile = Attributes::sanitize_tracks(
	array(
		array( 'src' => 'javascript:alert(1)', 'srclang' => 'es' ),
		array( 'src' => 'https://example.test/c.vtt', 'srclang' => 'es" onerror="x' ),
		array( 'src' => '' ),
		'not an array',
	)
);

check( 'a javascript: source is dropped', 1 === count( $hostile ), wp_json_encode( $hostile ) );
check( 'a language code is cut down to what a browser parses', 'esonerrorx' === $hostile[0]['srclang'], $hostile[0]['srclang'] );

// Clean up.
@unlink( '/tmp/outside.vtt' );

$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $uploads, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $entry ) {
	$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
}
rmdir( $uploads );

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "{$failures} check(s) failed." . PHP_EOL;
	exit( 1 );
}
echo 'All checks passed.' . PHP_EOL;
