<?php
/**
 * Are the bars the audio, or are they decoration?
 *
 * A fifty-three minute lesson came back with a waveform that looks like a comb
 * — four hundred bars all very nearly the same height — and the reasonable
 * question was whether anything had been measured at all. "Trust me, they are
 * real" is not an answer, and a test that only checks the bars are not all
 * identical is barely better: a picture can vary a little and still be
 * unrelated to the recording.
 *
 * So this writes audio whose loudness is known second by second, measures it
 * with the shipped module in a real browser, and checks each bar against what
 * was written at that moment. If the numbers are invented, or stitched in the
 * wrong order, or scaled, the comparison fails.
 *
 * And then it asks the second question, which is the one the picture was
 * really about: given that the bars are real, why is a lecture flat? That is
 * the last section, and the answer is not a bug.
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

if ( '' === $browser || ! is_dir( $root . '/node_modules/playwright' ) ) {
	echo 'SKIP  no Chromium with Playwright; nothing was measured' . PHP_EOL;
	exit( 0 );
}

$workdir = $root . '/build/.waveform-truth';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

const RATE = 22050;

/** A WAV header for mono 16-bit at RATE. */
function wav_header( $handle, int $samples ): void {
	$bytes = $samples * 2;

	fwrite( $handle, 'RIFF' . pack( 'V', 36 + $bytes ) . 'WAVE' );
	fwrite( $handle, 'fmt ' . pack( 'VvvVVvv', 16, 1, 1, RATE, RATE * 2, 2, 16 ) );
	fwrite( $handle, 'data' . pack( 'V', $bytes ) );
}

/** One second of a 220 Hz tone at the given amplitude. */
function tone_second( float $amp ): string {
	static $cache = array();

	$key = (string) round( $amp, 4 );

	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}

	$block = '';

	for ( $i = 0; $i < RATE; $i++ ) {
		$v      = (int) round( 32767 * $amp * sin( 2 * M_PI * 220 * $i / RATE ) );
		$block .= pack( 'v', $v & 0xffff );
	}

	$cache[ $key ] = $block;

	return $block;
}

echo PHP_EOL . '# Writing audio whose shape is known in advance' . PHP_EOL;

/*
 * Blunt on purpose. A gentle envelope can be matched by something that is
 * merely smooth; blocks of silence next to blocks at full volume cannot be
 * matched by anything but the recording itself.
 */
$blocks  = array( 1.0, 0.0, 0.5, 0.0, 0.25, 1.0, 0.125, 0.75, 0.0, 0.375, 1.0, 0.0625 );
$per     = 60;
$seconds = count( $blocks ) * $per;

$file   = $workdir . '/blocks.wav';
$handle = fopen( $file, 'wb' );

wav_header( $handle, RATE * $seconds );

foreach ( $blocks as $amp ) {
	$second = tone_second( $amp );

	for ( $s = 0; $s < $per; $s++ ) {
		fwrite( $handle, $second );
	}
}

fclose( $handle );

check(
	sprintf( '%d minutes in %d blocks of known loudness', $seconds / 60, count( $blocks ) ),
	is_readable( $file ) && filesize( $file ) > 20 * 1024 * 1024,
	(int) ( filesize( $file ) / 1048576 ) . ' MB, so it takes the long path'
);

/*
 * And a second recording shaped like speech, which is what the reported file
 * is. Bursts with gaps: every block is just as loud at its loudest, and they
 * differ enormously in how much of the time anything is happening at all.
 *
 * This is the fixture that answers the question the picture asked. A person
 * talking is loud for a syllable and silent between words, and how much of a
 * stretch is silence is exactly what the ear calls "quieter".
 */
$duty   = array( 0.10, 0.90, 0.30, 0.70, 0.05, 1.00, 0.20, 0.50 );
$speech = $workdir . '/speech.wav';
$handle = fopen( $speech, 'wb' );

wav_header( $handle, RATE * count( $duty ) * $per );

$loud   = tone_second( 1.0 );
$silent = tone_second( 0.0 );

foreach ( $duty as $share ) {
	for ( $s = 0; $s < $per; $s++ ) {
		/*
		 * Bursts inside the second rather than whole loud seconds, so that
		 * every bar — each covering several seconds — contains both loud and
		 * silent audio. Whole loud seconds would let a bar land entirely in a
		 * gap, which is a different picture and a much easier one.
		 */
		$cut = (int) ( RATE * $share ) * 2;

		fwrite( $handle, substr( $loud, 0, $cut ) . substr( $silent, 0, RATE * 2 - $cut ) );
	}
}

fclose( $handle );

check(
	'and the same length again shaped like speech, loud in bursts',
	is_readable( $speech ) && filesize( $speech ) > 20 * 1024 * 1024
);

// The module the editor really uses, compiled as it is.
exec(
	sprintf(
		'cd %s && npx tsc assets/src/shared/measure.ts --outDir %s --module es2020 --target es2020 --moduleResolution bundler --lib es2020,dom --skipLibCheck 2>&1',
		escapeshellarg( $root ),
		escapeshellarg( $workdir . '/mod' )
	),
	$compiled
);

if ( ! is_readable( $workdir . '/mod/measure.js' ) ) {
	check( 'the measuring module compiles', false, implode( ' / ', array_slice( $compiled, 0, 2 ) ) );
	exec( 'rm -rf ' . escapeshellarg( $workdir ) );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

file_put_contents( $workdir . '/index.html', '<!doctype html><meta charset="utf-8"><title>truth</title>' );

$bars = count( $blocks ) * 20;

$script = <<<'JS'
import pkg from 'PLAYWRIGHT';
const { chromium } = pkg;

const browser = await chromium.launch({
	executablePath: 'BROWSER',
	args: [ '--no-sandbox', '--disable-gpu' ],
});

const page = await browser.newPage();
const out = {};

await page.goto('http://127.0.0.1:PORT/', { waitUntil: 'load' });

out.blocks = await page.evaluate(async (bars) => {
	const { measure } = await import('./mod/measure.js');

	try {
		const r = await measure('/blocks.wav', bars);

		return { ok: true, peaks: r.peaks, duration: r.duration };
	} catch (e) {
		return { ok: false, message: (e && e.message) || String(e) };
	}
}, BARS);

out.speech = await page.evaluate(async (bars) => {
	const { measure } = await import('./mod/measure.js');

	try {
		const r = await measure('/speech.wav', bars);

		return { ok: true, peaks: r.peaks, duration: r.duration };
	} catch (e) {
		return { ok: false, message: (e && e.message) || String(e) };
	}
}, BARS);

console.log(JSON.stringify(out));
await browser.close();
JS;

$port = 8900 + ( getmypid() % 90 );

$script = str_replace(
	array( 'PLAYWRIGHT', 'BROWSER', 'PORT', 'BARS' ),
	array( $root . '/node_modules/playwright/index.js', $browser, (string) $port, (string) $bars ),
	$script
);

file_put_contents( $workdir . '/run.mjs', $script );

$server = proc_open(
	array( PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $workdir ),
	array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
	$pipes,
	$workdir
);

for ( $attempt = 0; $attempt < 50; $attempt++ ) {
	$socket = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.2 );
	if ( $socket ) { fclose( $socket ); break; }
	usleep( 100000 );
}

$raw = (string) shell_exec( 'timeout 600 node ' . escapeshellarg( $workdir . '/run.mjs' ) . ' 2>/dev/null' );

foreach ( $pipes as $pipe ) {
	if ( is_resource( $pipe ) ) { fclose( $pipe ); }
}

proc_terminate( $server );
exec( 'rm -rf ' . escapeshellarg( $workdir ) );

$report = json_decode( trim( $raw ), true );

if ( ! is_array( $report ) ) {
	check( 'the browser reported', false, substr( trim( $raw ), 0, 200 ) );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

echo PHP_EOL . '# Each bar against the audio that was under it' . PHP_EOL;

$measured = (array) ( $report['blocks'] ?? array() );

check(
	'the file was measured',
	true === ( $measured['ok'] ?? false ),
	(string) ( $measured['message'] ?? 'no result' )
);

if ( true !== ( $measured['ok'] ?? false ) ) {
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

$peaks = array_map( 'floatval', (array) $measured['peaks'] );

check(
	'with the length the file really has',
	abs( (float) $measured['duration'] - $seconds ) < 3,
	round( (float) $measured['duration'] ) . 's, expected ' . $seconds
);

check( 'and a full row of bars', count( $peaks ) === $bars, count( $peaks ) . ' of ' . $bars );

/*
 * The comparison the whole file exists for. Twenty bars per block, and the
 * middle eighteen of them checked: the two at each seam straddle the change in
 * loudness and are legitimately somewhere in between.
 */
/*
 * Against the amplitude each block was written at, as a share of the loudest —
 * the row is scaled so the loudest bar reaches the top, which is what every
 * other part of the plugin has always done and what a waveform has to do to
 * fill the space it is given.
 */
$loudest = max( $blocks );
$worst   = 0.0;
$lines   = array();

foreach ( $blocks as $index => $amp ) {
	$slice = array_slice( $peaks, $index * 20 + 1, 18 );
	$seen  = array_sum( $slice ) / max( 1, count( $slice ) );
	$want  = $amp / $loudest;
	$off   = abs( $seen - $want );
	$worst = max( $worst, $off );

	$lines[] = sprintf( '%.3f→%.3f', $want, $seen );
}

check(
	'every block comes back at the loudness it was written at',
	$worst < 0.02,
	'worst block off by ' . round( $worst, 4 )
);

printf( '      written→measured: %s%s', implode( '  ', $lines ), PHP_EOL );

/*
 * Silence in particular, because it is the one value that cannot be arrived at
 * by accident. A generated picture, a placeholder, a synthetic envelope — none
 * of them produce a flat zero in exactly the three places the recording is
 * silent.
 */
$silences = array();

foreach ( $blocks as $index => $amp ) {
	if ( 0.0 === $amp ) {
		$silences = array_merge( $silences, array_slice( $peaks, $index * 20 + 1, 18 ) );
	}
}

check(
	'and the silent minutes read as silence, not as a low hum',
	array() !== $silences && max( $silences ) < 0.01,
	'loudest bar in a silent block: ' . round( max( $silences ?: array( 1 ) ), 4 )
);

/*
 * Order. Every check above would still pass if the blocks came back shuffled,
 * or reversed, which is exactly the failure a file stitched together from
 * slices in the wrong order would produce.
 */
$matches = 0;

foreach ( $blocks as $index => $amp ) {
	$slice = array_slice( $peaks, $index * 20 + 1, 18 );
	$seen  = array_sum( $slice ) / max( 1, count( $slice ) );

	if ( abs( $seen - $amp / $loudest ) < 0.02 ) {
		$matches++;
	}
}

check(
	'in the order they were recorded in, not shuffled or reversed',
	$matches === count( $blocks ),
	$matches . ' of ' . count( $blocks ) . ' blocks in place'
);

echo PHP_EOL . '# A lecture has to look like a lecture' . PHP_EOL;

$talk = (array) ( $report['speech'] ?? array() );

check(
	'the speech-shaped file was measured too',
	true === ( $talk['ok'] ?? false ),
	(string) ( $talk['message'] ?? 'no result' )
);

$spoken = array_map( 'floatval', (array) ( $talk['peaks'] ?? array() ) );

if ( array() !== $spoken ) {
	/*
	 * Bars per block, worked out rather than assumed. The two fixtures have a
	 * different number of blocks in the same number of bars, and reading the
	 * speech one twenty bars at a time — the figure that is right for the other
	 * fixture — read across the seams and reported every block as a smear of
	 * itself and its neighbour. Which looked exactly like the measuring being
	 * wrong, and was the test being wrong.
	 */
	$span   = intdiv( count( $spoken ), count( $duty ) );
	$edge   = max( 1, (int) round( $span * 0.1 ) );
	$levels = array();
	$shown  = array();

	foreach ( $duty as $index => $share ) {
		$slice = array_slice( $spoken, $index * $span + $edge, $span - 2 * $edge );

		$levels[ $index ] = array_sum( $slice ) / max( 1, count( $slice ) );
		$shown[]          = sprintf( '%d%%→%.2f', (int) ( $share * 100 ), $levels[ $index ] );
	}

	printf( '      talking→measured: %s%s', implode( '  ', $shown ), PHP_EOL );

	/*
	 * This is the check the reported picture was really about.
	 *
	 * Eight blocks, every one of them just as loud at its loudest, differing
	 * only in how much of the time anything is happening — which is the only
	 * thing that varies in a recording of somebody talking, and the thing the
	 * ear hears as louder and quieter.
	 *
	 * Measured by the loudest instant in each bar, all eight came out within
	 * 0.02 of each other: a comb, and indistinguishable from a picture that had
	 * been made up. Loudness per bar has to tell them apart.
	 */
	check(
		'talking a twentieth of the time draws a different bar from talking all of it',
		max( $levels ) - min( $levels ) > 0.5,
		'from ' . round( min( $levels ), 2 ) . ' to ' . round( max( $levels ), 2 )
	);

	/*
	 * And in the right direction, and in proportion. "Not flat" on its own
	 * would be satisfied by noise, which is exactly the thing that was being
	 * suspected.
	 */
	$by_duty = $duty;
	asort( $by_duty );

	$rising   = true;
	$previous = -1.0;
	$sequence = array();

	foreach ( array_keys( $by_duty ) as $index ) {
		if ( $levels[ $index ] < $previous - 0.02 ) {
			$rising = false;
		}

		$previous   = $levels[ $index ];
		$sequence[] = round( $levels[ $index ], 2 );
	}

	check(
		'and the busier a stretch is, the taller its bar — every time',
		$rising,
		'sorted by how much talking is in them the bars go ' . implode( ' ', $sequence )
	);

	/*
	 * In proportion, not merely in order. Loudness rises as the square root of
	 * how much of the time there is sound, which is arithmetic rather than
	 * taste — and checking it is what separates a measurement from a picture
	 * that happens to slope the right way.
	 */
	$worst_share = 0.0;

	foreach ( $duty as $index => $share ) {
		$worst_share = max( $worst_share, abs( $levels[ $index ] - sqrt( $share ) ) );
	}

	check(
		'and by the amount the arithmetic says, not just in the right order',
		$worst_share < 0.05,
		'worst block off by ' . round( $worst_share, 3 )
	);
}

echo PHP_EOL . '# A waveform measured the old way offers to be measured again' . PHP_EOL;

/*
 * Changing how a bar is worked out does nothing for the recordings that were
 * already measured — and the person with the comb-shaped lecture is precisely
 * the person the change is for. The stored record carries the version it was
 * measured under, so the editor can count an old one as worth doing again and
 * offer it, rather than needing somebody to know it has to be deleted first.
 *
 * Drawn in the meantime, though. An old picture beats no picture.
 */
require_once __DIR__ . '/bootstrap.php';

check(
	'a waveform measured the old way is not counted as current',
	! ImaginaPlayer\Peaks\PeaksRepository::is_current( array( 'version' => 1, 'peaks' => 'x' ) )
);

check(
	'one measured this way is',
	ImaginaPlayer\Peaks\PeaksRepository::is_current(
		array( 'version' => ImaginaPlayer\Peaks\PeaksRepository::FORMAT_VERSION, 'peaks' => 'x' )
	)
);

check(
	'a record with no version at all counts as the oldest',
	! ImaginaPlayer\Peaks\PeaksRepository::is_current( array( 'peaks' => 'x' ) )
);

check(
	'and no waveform is still no waveform',
	! ImaginaPlayer\Peaks\PeaksRepository::is_current( null )
);

check(
	'the editor asks the version, rather than only whether a row exists',
	str_contains(
		(string) file_get_contents( dirname( __DIR__ ) . '/src/Rest/PeaksController.php' ),
		'PeaksRepository::is_current( $this->repository->get('
	),
	'otherwise an old picture never gets the offer to be redone'
);

echo PHP_EOL;
echo 0 === $failures ? 'All waveform-truth checks passed.' . PHP_EOL : "{$failures} FAILURE(S)" . PHP_EOL;

exit( 0 === $failures ? 0 : 1 );
