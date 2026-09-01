<?php
/**
 * Measuring a recording that is an hour long.
 *
 * On a host with no ffmpeg the waveform is measured once, in the editor's own
 * browser, and stored for everybody. That worked for a podcast episode and did
 * not work for a lecture, and the report was exactly that: some files never got
 * a waveform, and the one named was 48 MB and fifty-three minutes.
 *
 * The cause is not the download. `decodeAudioData` was handed the whole file
 * with an 8 kHz context, on the reasoning that the context's rate is what comes
 * back — which is true of the result and not of the work. The decoder expands
 * the file at its own rate and resamples afterwards, so fifty-three minutes of
 * 44.1 kHz stereo is about a gigabyte of float samples in flight before
 * anything is handed back.
 *
 * Whether that gigabyte is fatal depends on the machine, which is why it was
 * "some files" and not all of them, and why it cannot be reproduced here: this
 * container has memory to spare and decodes the fixture below whole without
 * complaining. So what is checked is not that the old way fails — it does not
 * fail *here* — but that the new way never asks for that gigabyte in the first
 * place. The largest single decode is bounded, whatever the length of the
 * recording, and that is a property this can measure.
 *
 * The fixture is built here rather than committed, because half a gigabyte is
 * not a thing to keep in a repository.
 */

require __DIR__ . '/bootstrap.php';

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
	echo 'SKIP  no Chromium with Playwright; the long file was not measured' . PHP_EOL;
	exit( 0 );
}

$workdir = $root . '/build/.long-audio';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

/**
 * A WAV of a given length, written a second at a time.
 *
 * Uncompressed on purpose: what makes this hard is the number of samples the
 * decoder has to hold, and that follows the running time rather than the file
 * size. Fifty-three minutes is fifty-three minutes whether it arrived as 48 MB
 * of MP3 or half a gigabyte of PCM.
 *
 * @param string $path    Where to write it.
 * @param int    $seconds How long.
 */
function write_wav( string $path, int $seconds ): void {
	// Mono at 22.05 kHz. What makes a recording hard to decode is how many
	// seconds of it there are, not how many bytes — so the fixture keeps the
	// running time and spends a quarter of the disk on it.
	$rate  = 22050;
	$bytes = $rate * $seconds * 2;

	$handle = fopen( $path, 'wb' );

	fwrite( $handle, 'RIFF' . pack( 'V', 36 + $bytes ) . 'WAVE' );
	fwrite( $handle, 'fmt ' . pack( 'VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16 ) );
	fwrite( $handle, 'data' . pack( 'V', $bytes ) );

	/*
	 * A second of tone at each of forty-eight loudnesses, written once and then
	 * chosen from. Rescaling every sample of an hour in PHP is a minute of
	 * arithmetic to produce a test fixture; this is the same envelope for a
	 * fraction of it, and enough distinct levels that "not a flat line" stays a
	 * real thing to check rather than a threshold tuned down to fit.
	 */
	$levels = array();

	for ( $level = 0; $level < 48; $level++ ) {
		$amp   = 0.15 + 0.85 * ( $level / 47 );
		$block = '';

		for ( $i = 0; $i < $rate; $i++ ) {
			$v      = (int) round( 32767 * $amp * sin( 2 * M_PI * 220 * $i / $rate ) );
			$block .= pack( 'v', $v & 0xffff );
		}

		$levels[] = $block;
	}

	for ( $s = 0; $s < $seconds; $s++ ) {
		// A slow rise and fall, so the finished waveform has an envelope and a
		// flat row of bars would be obviously wrong.
		$level = (int) round( 47 * abs( sin( $s / 180.0 * M_PI ) ) );

		fwrite( $handle, $levels[ $level ] );
	}

	fclose( $handle );
}

$long = $workdir . '/long.wav';
$short = $workdir . '/short.wav';

echo PHP_EOL . '# Building the fixtures' . PHP_EOL;

$started = microtime( true );

// Fifty-three minutes, the length that was reported.
write_wav( $long, 3180 );

/*
 * And a short one, to compare the two paths on. Under the twenty megabyte
 * line, so it takes the whole-file route; the same file forced through the
 * windowed route has to agree with it, or the windows are lying.
 */
write_wav( $short, 60 );

check( 'a fifty-three minute file was written', is_readable( $long ) && filesize( $long ) > 100 * 1024 * 1024, (string) ( (int) ( filesize( $long ) / 1048576 ) ) . ' MB' );
check( 'and a minute-long one to compare against', is_readable( $short ) );

printf( '      (%d seconds to build)%s', (int) ( microtime( true ) - $started ), PHP_EOL );

// The module the editor really uses, compiled as it is.
exec(
	sprintf(
		'cd %s && npx tsc assets/src/shared/measure.ts --outDir %s --module es2020 --target es2020 --moduleResolution bundler --lib es2020,dom --skipLibCheck 2>&1',
		escapeshellarg( $root ),
		escapeshellarg( $workdir . '/mod' )
	),
	$compile_output
);

if ( ! is_readable( $workdir . '/mod/measure.js' ) ) {
	check( 'the measuring module compiles', false, implode( ' / ', array_slice( $compile_output, 0, 2 ) ) );
	exec( 'rm -rf ' . escapeshellarg( $workdir ) );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

file_put_contents( $workdir . '/index.html', '<!doctype html><meta charset="utf-8"><title>measure</title>' );

/*
 * A router, because PHP's built-in server answers every request with the whole
 * file however politely it was asked. Slicing is now how a large file comes
 * through the doorway on a real site — one request per slice, so no single one
 * can outlast a host's execution limit — and a test served by something that
 * cannot slice would never exercise it.
 */
file_put_contents(
	$workdir . '/router.php',
	<<<'PHP'
<?php
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$file = __DIR__ . $path;

if ( ! is_file( $file ) || false !== strpos( $path, '..' ) ) {
	return false;
}

$size  = filesize( $file );
$range = $_SERVER['HTTP_RANGE'] ?? '';

$types = array( 'wav' => 'audio/wav', 'js' => 'text/javascript', 'html' => 'text/html' );
$ext   = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

header( 'Accept-Ranges: bytes' );
// A module served as text/plain is a module the browser refuses to import.
header( 'Content-Type: ' . ( $types[ $ext ] ?? 'application/octet-stream' ) );

if ( preg_match( '/^bytes=(\d+)-(\d*)$/', $range, $m ) ) {
	$start = (int) $m[1];
	$end   = '' === $m[2] ? $size - 1 : min( (int) $m[2], $size - 1 );
	$length = $end - $start + 1;

	http_response_code( 206 );
	header( "Content-Range: bytes {$start}-{$end}/{$size}" );
	header( 'Content-Length: ' . $length );

	$handle = fopen( $file, 'rb' );
	fseek( $handle, $start );
	echo fread( $handle, $length );
	fclose( $handle );

	return true;
}

header( 'Content-Length: ' . $size );
readfile( $file );

return true;
PHP
);

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

out.long = await page.evaluate(async () => {
	const { measure } = await import('./mod/measure.js');
	const started = performance.now();

	/*
	 * How much audio the biggest single decode covered. This is the number the
	 * whole change is about: with the file handed over whole it is the length
	 * of the recording, and with it handed over in pieces it is the length of
	 * a piece.
	 */
	let biggest = 0;

	for (const Ctor of [ window.OfflineAudioContext, window.AudioContext ]) {
		const original = Ctor.prototype.decodeAudioData;

		Ctor.prototype.decodeAudioData = function (...args) {
			return original.apply(this, args).then((audio) => {
				biggest = Math.max(biggest, audio.duration);
				return audio;
			});
		};
	}

	try {
		const r = await measure('/long.wav', 400);

		return {
			ok: true,
			duration: r.duration,
			bars: r.peaks.length,
			max: Math.max(...r.peaks),
			min: Math.min(...r.peaks),
			distinct: new Set(r.peaks.map((v) => v.toFixed(2))).size,
			seconds: Math.round((performance.now() - started) / 1000),
			biggestDecode: biggest,
		};
	} catch (e) {
		return { ok: false, message: (e && e.message) || String(e) };
	}
});

// The same short file both ways, to see whether the windows agree with the
// whole.
out.compare = await page.evaluate(async () => {
	const mod = await import('./mod/measure.js');
	const whole = await mod.measure('/short.wav', 200);

	/*
	 * The same audio down the windowed path, by telling it no file is small
	 * enough to take in one go. Small windows too, so there are several of
	 * them and the seams are actually exercised.
	 */
	const windowed = await mod.measure('/short.wav', 200, undefined, undefined, {
		wholeFileLimit: 0,
		windowBytes: 512 * 1024,
	});

	let worst = 0;

	for (let i = 0; i < whole.peaks.length; i++) {
		worst = Math.max(worst, Math.abs(whole.peaks[i] - windowed.peaks[i]));
	}

	return {
		worst,
		wholeDuration: whole.duration,
		windowedDuration: windowed.duration,
	};
});

/*
 * And the slices themselves. The file comes down in pieces and is put back
 * together by hand, which is exactly the kind of code that is off by one and
 * still looks like it worked — so the proof is that the picture matches the
 * one taken from a single download of the same file.
 */
out.slices = await page.evaluate(async () => {
	const mod = await import('./mod/measure.js');

	const requests = [];
	const original = window.fetch;

	window.fetch = function (input, init) {
		const range = init && init.headers && init.headers.Range;
		if (range) { requests.push(range); }
		return original.apply(this, arguments);
	};

	try {
		// Small slices on purpose, so there are a dozen of them and the
		// stitching is actually exercised.
		const sliced = await mod.measure('/short.wav', 200, undefined, undefined, {
			sliceBytes: 256 * 1024,
		});

		// The same audio again with nothing asked in slices, to compare with.
		window.fetch = original;

		const plain = await mod.measure('/short.wav', 200);
		let worst = 0;

		for (let i = 0; i < plain.peaks.length; i++) {
			worst = Math.max(worst, Math.abs(plain.peaks[i] - sliced.peaks[i]));
		}

		return {
			requests: requests.length,
			first: requests[0] || '',
			worst,
			duration: sliced.duration,
			plainDuration: plain.duration,
		};
	} finally {
		window.fetch = original;
	}
});

/*
 * The failure that was reported, reproduced exactly: twelve slices of thirteen
 * arrive and the last one does not.
 *
 * Two answers are wanted from this. A piece that fails once and works when
 * asked again must not fail the measurement at all — there was no retry
 * anywhere in the chain, so one dropped connection out of thirteen threw away
 * a fifty megabyte download that was 99% done. And a piece that never comes
 * must still leave a waveform, because everything but the last scrap of a file
 * is a picture of that file, and the alternative on offer was nothing.
 */
out.flaky = await page.evaluate(async () => {
	const mod = await import('./mod/measure.js');
	const original = window.fetch;

	const clean = await mod.measure('/short.wav', 200, undefined, undefined, {
		sliceBytes: 256 * 1024,
	});

	/*
	 * The third piece, refused twice and then served. A far end that fumbles
	 * one request and answers the next is the ordinary case, not an exotic one.
	 *
	 * Named by the bytes it asks for rather than counted. Counting calls does
	 * not work here for the reason this whole test exists: a retry is another
	 * call, so "the third request" stops being the third piece the moment the
	 * retry it is testing starts happening — which is how the first version of
	 * this test refused once and reported that it had refused twice.
	 */
	const third = 'bytes=' + 2 * 256 * 1024 + '-';
	let refusals = 0;

	window.fetch = function (input, init) {
		const range = init && init.headers && init.headers.Range;

		if (range && range.startsWith(third) && refusals < 2) {
			refusals++;
			return Promise.reject(new TypeError('Failed to fetch'));
		}

		return original.apply(this, arguments);
	};

	try {
		const survived = await mod.measure('/short.wav', 200, undefined, undefined, {
			sliceBytes: 256 * 1024,
		});

		let worst = 0;

		for (let i = 0; i < clean.peaks.length; i++) {
			worst = Math.max(worst, Math.abs(clean.peaks[i] - survived.peaks[i]));
		}

		return {
			ok: true,
			refusals,
			worst,
			duration: survived.duration,
			cleanDuration: clean.duration,
		};
	} catch (e) {
		return { ok: false, refusals, message: (e && e.message) || String(e) };
	} finally {
		window.fetch = original;
	}
});

out.tail = await page.evaluate(async () => {
	const mod = await import('./mod/measure.js');
	const original = window.fetch;

	const clean = await mod.measure('/short.wav', 200, undefined, undefined, {
		sliceBytes: 256 * 1024,
	});

	// Every request for the last piece refused, however many times it is asked.
	let total = 0;

	window.fetch = function (input, init) {
		const range = init && init.headers && init.headers.Range;
		const m = range && /^bytes=(\d+)-(\d+)$/.exec(range);

		if (m) {
			const end = Number(m[2]);

			if (total && end >= total - 1) {
				return Promise.reject(new TypeError('Failed to fetch'));
			}
		}

		return original.apply(this, arguments).then((r) => {
			const cr = r.headers.get('content-range');
			if (cr) { total = Number(cr.split('/')[1]) || total; }
			return r;
		});
	};

	try {
		const short = await mod.measure('/short.wav', 200, undefined, undefined, {
			sliceBytes: 256 * 1024,
		});

		return {
			ok: true,
			bars: short.peaks.length,
			duration: short.duration,
			cleanDuration: clean.duration,
			distinct: new Set(short.peaks.map((v) => v.toFixed(2))).size,
		};
	} catch (e) {
		return { ok: false, message: (e && e.message) || String(e) };
	} finally {
		window.fetch = original;
	}
});

/*
 * And the other side of that. Tolerating a missing tail is only defensible
 * because the tail is tiny; losing a third of a recording and drawing a
 * confident picture of the rest would be worse than saying so. So a piece
 * missing from the middle still has to fail.
 */
out.gap = await page.evaluate(async () => {
	const mod = await import('./mod/measure.js');
	const original = window.fetch;

	// The second piece, so nearly all of the file is still to come. By name
	// rather than by count, for the same reason as above.
	const second = 'bytes=' + 256 * 1024 + '-';

	window.fetch = function (input, init) {
		const range = init && init.headers && init.headers.Range;

		if (range && range.startsWith(second)) {
			return Promise.reject(new TypeError('Failed to fetch'));
		}

		return original.apply(this, arguments);
	};

	try {
		await mod.measure('/short.wav', 200, undefined, undefined, {
			sliceBytes: 256 * 1024,
		});

		return { threw: false };
	} catch (e) {
		return { threw: true, message: (e && e.message) || String(e) };
	} finally {
		window.fetch = original;
	}
});

console.log(JSON.stringify(out));
await browser.close();
JS;

$port = 8000 + ( getmypid() % 900 );

$script = str_replace(
	array( 'PLAYWRIGHT', 'BROWSER', 'PORT' ),
	array( $root . '/node_modules/playwright/index.js', $browser, (string) $port ),
	$script
);

file_put_contents( $workdir . '/run.mjs', $script );

$server = proc_open(
	array( PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $workdir, $workdir . '/router.php' ),
	array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
	$pipes,
	$workdir
);

for ( $attempt = 0; $attempt < 50; $attempt++ ) {
	$socket = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.2 );
	if ( $socket ) { fclose( $socket ); break; }
	usleep( 100000 );
}

$raw = (string) shell_exec( 'timeout 900 node ' . escapeshellarg( $workdir . '/run.mjs' ) . ' 2>/dev/null' );

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

echo PHP_EOL . '# Fifty-three minutes' . PHP_EOL;

$long_result = (array) ( $report['long'] ?? array() );

check(
	'the file is measured rather than hanging for ever',
	true === ( $long_result['ok'] ?? false ),
	(string) ( $long_result['message'] ?? 'no result' )
);

if ( true === ( $long_result['ok'] ?? false ) ) {
	printf( '      (%d seconds in the browser)%s', (int) ( $long_result['seconds'] ?? 0 ), PHP_EOL );

	check(
		'and its length comes back right',
		abs( (float) $long_result['duration'] - 3180 ) < 5,
		( $long_result['duration'] ?? '?' ) . 's, expected 3180'
	);

	check(
		'with a full row of bars',
		400 === (int) ( $long_result['bars'] ?? 0 ),
		(string) ( $long_result['bars'] ?? 0 )
	);

	/*
	 * The shape, not just the count. Windows that decoded to silence — or a
	 * resample that lost the envelope — would give a row of identical bars,
	 * which is the same picture as no waveform at all and would pass every
	 * check above.
	 */
	check(
		'and a waveform with a shape, not a flat line',
		(int) ( $long_result['distinct'] ?? 0 ) >= 20,
		( $long_result['distinct'] ?? 0 ) . ' distinct levels'
	);

	check(
		'no window came back silent',
		(float) ( $long_result['min'] ?? 0 ) > 0.05,
		'quietest bar ' . ( $long_result['min'] ?? '?' )
	);

	/*
	 * And the point of the exercise. Handed the file whole, the decoder holds
	 * fifty-three minutes of samples at once — about a gigabyte at the rate it
	 * decodes at before resampling. Handed it in pieces, it never holds more
	 * than a piece, and how long the recording is stops mattering.
	 */
	check(
		'and no single decode covered more than a couple of minutes',
		(float) ( $long_result['biggestDecode'] ?? 9999 ) < 150,
		'the largest was ' . round( (float) ( $long_result['biggestDecode'] ?? 0 ) ) . 's of audio at once'
	);
}

echo PHP_EOL . '# The windows agree with the whole' . PHP_EOL;

/*
 * The windowed path is an approximation — it cuts the file on byte boundaries
 * and lays the pieces back on a timeline — so it will not be identical. It has
 * to be close, or a long file gets a picture of something else.
 */
$compare = (array) ( $report['compare'] ?? array() );

check(
	'the same file measured both ways gives the same picture',
	isset( $compare['worst'] ) && (float) $compare['worst'] < 0.12,
	'worst bar differs by ' . ( $compare['worst'] ?? '?' )
);

check(
	'and the same length',
	isset( $compare['wholeDuration'] )
		&& abs( (float) $compare['wholeDuration'] - (float) $compare['windowedDuration'] ) < 0.5,
	( $compare['wholeDuration'] ?? '?' ) . ' vs ' . ( $compare['windowedDuration'] ?? '?' )
);

echo PHP_EOL . '# Put back together from slices' . PHP_EOL;

$slices = (array) ( $report['slices'] ?? array() );

check(
	'a same-origin file is asked for in slices',
	(int) ( $slices['requests'] ?? 0 ) > 1,
	( $slices['requests'] ?? 0 ) . ' ranged requests'
);

check(
	'starting at the beginning',
	str_starts_with( (string) ( $slices['first'] ?? '' ), 'bytes=0-' ),
	(string) ( $slices['first'] ?? '' )
);

/*
 * Byte-for-byte, in the only way that matters here: a file reassembled with an
 * offset wrong by one would still decode, and would draw a different picture.
 */
check(
	'and what comes out is the same audio as a single download',
	isset( $slices['worst'] ) && (float) $slices['worst'] < 0.0001,
	'worst bar differs by ' . ( $slices['worst'] ?? '?' )
);

check(
	'of the same length',
	isset( $slices['duration'] )
		&& abs( (float) $slices['duration'] - (float) $slices['plainDuration'] ) < 0.05,
	( $slices['duration'] ?? '?' ) . ' vs ' . ( $slices['plainDuration'] ?? '?' )
);

echo PHP_EOL . '# A piece that fumbles once' . PHP_EOL;

$flaky = (array) ( $report['flaky'] ?? array() );

check(
	'a slice refused twice and then served does not fail the measurement',
	true === ( $flaky['ok'] ?? false ),
	(string) ( $flaky['message'] ?? 'no result' )
);

check(
	'and it really was refused, rather than the test asking nicely',
	2 === (int) ( $flaky['refusals'] ?? 0 ),
	( $flaky['refusals'] ?? 0 ) . ' refusals'
);

check(
	'and what comes out is the same audio as when nothing was refused',
	isset( $flaky['worst'] ) && (float) $flaky['worst'] < 0.0001,
	'worst bar differs by ' . ( $flaky['worst'] ?? '?' )
);

echo PHP_EOL . '# A piece that never comes' . PHP_EOL;

/*
 * The reported failure, exactly: every slice but the last, and the last one
 * refused however many times it is asked for. This used to throw away the
 * entire download.
 */
$tail = (array) ( $report['tail'] ?? array() );

check(
	'the last slice going missing still leaves a waveform',
	true === ( $tail['ok'] ?? false ),
	(string) ( $tail['message'] ?? 'no result' )
);

check(
	'with a full row of bars',
	200 === (int) ( $tail['bars'] ?? 0 ),
	(string) ( $tail['bars'] ?? 0 )
);

/*
 * And the length is the file's, not the length of what arrived. Handing back
 * the short one would put every chapter mark and every stored position out by
 * the size of the missing piece.
 */
check(
	'and the length is the whole file’s, not the part that arrived',
	isset( $tail['duration'], $tail['cleanDuration'] )
		&& abs( (float) $tail['duration'] - (float) $tail['cleanDuration'] ) < 1.0,
	( $tail['duration'] ?? '?' ) . ' vs ' . ( $tail['cleanDuration'] ?? '?' ) . ' whole'
);

check(
	'and it is a shape, not a flat line',
	(int) ( $tail['distinct'] ?? 0 ) >= 20,
	( $tail['distinct'] ?? 0 ) . ' distinct values'
);

echo PHP_EOL . '# A piece missing from the middle is still a failure' . PHP_EOL;

/*
 * The other half of the above, and the reason it is defensible. Forgiving a
 * missing tail is only right because the tail is a rounding error; drawing a
 * confident picture with a third of the recording absent would be worse than
 * refusing.
 */
$gap = (array) ( $report['gap'] ?? array() );

check(
	'losing a slice near the start refuses rather than inventing the rest',
	true === ( $gap['threw'] ?? false ),
	'it returned a waveform anyway'
);

echo PHP_EOL;
echo 0 === $failures ? 'All long-audio checks passed.' . PHP_EOL : "{$failures} FAILURE(S)" . PHP_EOL;

exit( 0 === $failures ? 0 : 1 );
