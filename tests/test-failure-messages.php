<?php
/**
 * Every way a measurement can fail says something true about it.
 *
 * This test exists because one bug has now happened three times, in three
 * different disguises. The measuring code throws a short tag; the editor turns
 * that tag into a sentence; and when a tag arrives that the sentence-writer has
 * no case for, it falls through to the last line — "the browser could not read
 * it, which is usually a cross-origin refusal".
 *
 * That line is a lie for every tag but the one it was written for, and it is a
 * convincing one: it names a real thing that really does happen, so nobody
 * doubts it. It sent somebody looking at CORS headers when the doorway had been
 * refused by the file's host; it sent them looking again when a slice came back
 * 424; and it sent them, once, into enabling a PHP function their host had
 * warned them not to enable.
 *
 * So the tags are not listed here by hand — a list by hand is a list that goes
 * out of date the next time somebody adds a `throw`. They are read out of the
 * two files that produce them, and every one of them has to come back as
 * something other than the catch-all.
 *
 * @package ImaginaPlayer
 */

$root = dirname( __DIR__ );

$failures = 0;

/**
 * One assertion.
 *
 * @param string $what  What was checked.
 * @param bool   $ok    Whether it held.
 * @param string $extra What was seen instead.
 */
function check( string $what, bool $ok, string $extra = '' ): void {
	global $failures;

	if ( ! $ok ) {
		$failures++;
	}

	printf(
		'  %s %s%s%s',
		$ok ? 'ok  ' : 'FAIL',
		$what,
		'' === $extra ? '' : ' — ' . $extra,
		PHP_EOL
	);
}

echo PHP_EOL . '# Reading the tags out of the code that throws them' . PHP_EOL;

$measure = (string) file_get_contents( $root . '/assets/src/shared/measure.ts' );
$proxy   = (string) file_get_contents( $root . '/src/Rest/PeaksController.php' );

/*
 * What the browser side throws. The pipe and anything after it is the location
 * — "slice 9 of 13" — and is deliberately not captured: it is appended by the
 * sentence-writer, and whether it appends it exactly once is its own check
 * below.
 */
preg_match_all( "/new Error\(\s*'([a-z0-9][a-z0-9-]*)/", $measure, $found );

$tags = array_values( array_unique( $found[1] ) );

check( 'the measuring module throws tags this test can read', count( $tags ) >= 4, implode( ', ', $tags ) );

/*
 * And what the doorway refuses with. These reach the browser as an
 * `X-Imagina-Reason` header, which the measuring code turns into `proxy-<tag>`,
 * so they are just as much part of the vocabulary as the ones above.
 */
preg_match_all( '/refuse\(\s*(.*?)\);/s', $proxy, $calls );

$refusals = array();

foreach ( $calls[1] as $arguments ) {
	preg_match_all( "/'([a-z0-9][a-z0-9-]*)'/", $arguments, $words );

	foreach ( $words[1] as $word ) {
		$refusals[] = $word;
	}
}

/*
 * `'upstream-' . $upstream` — a prefix with a status glued on. Read as-is it
 * would be a tag nobody ever sends, so it is completed with a status that
 * really does come back from a host with hotlink protection on.
 */
$refusals = array_map(
	static fn( string $tag ): string => str_ends_with( $tag, '-' ) ? $tag . '403' : $tag,
	$refusals
);

$refusals = array_values( array_unique( $refusals ) );

check( 'the doorway refuses with tags this test can read', count( $refusals ) >= 4, implode( ', ', $refusals ) );

foreach ( $refusals as $tag ) {
	$tags[] = 'proxy-' . $tag;
}

/*
 * A response with no reason on it at all — the doorway was never reached, or
 * something in front of it answered instead. The status is all there is.
 */
foreach ( array( '403', '404', '502', '424' ) as $status ) {
	$tags[] = 'fetch-failed-' . $status;
}

echo PHP_EOL . '# Compiling the sentence-writer on its own' . PHP_EOL;

$workdir = sys_get_temp_dir() . '/imgp-failure-' . getmypid();

@mkdir( $workdir, 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- may exist.

$build = $root . '/build/.failure-check';

exec(
	sprintf(
		'cd %s && npx tsc assets/src/shared/failure.ts --outDir %s --module commonjs --target es2020 --moduleResolution node --esModuleInterop --skipLibCheck 2>&1',
		escapeshellarg( $root ),
		escapeshellarg( $build )
	),
	$compiled
);

if ( ! is_readable( $build . '/failure.js' ) ) {
	check( 'it compiles', false, implode( ' / ', array_slice( $compiled, 0, 3 ) ) );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

check( 'it compiles on its own, outside React', true );

/*
 * Run for real. Not a reimplementation of the mapping in PHP — the whole point
 * is that the shipped code is asked, in the shipped translation layer, what it
 * would tell somebody.
 */
$asked = array_merge(
	$tags,
	array( 'imgp-a-tag-nobody-has-written-yet' ),
	array( 'slice-empty|slice 9 of 13', 'proxy-upstream-403|slice 2 of 13' ),
	array( 'proxy-upstream-unreachable|slice 13 of 13|cURL error 56: Recv failure: Connection reset by peer' )
);

file_put_contents( $workdir . '/ask.json', (string) wp_json_encode_local( $asked ) );

file_put_contents(
	$workdir . '/run.cjs',
	<<<'JS'
const { reason } = require( process.argv[ 2 ] );
const asked = JSON.parse( require( 'fs' ).readFileSync( process.argv[ 3 ], 'utf8' ) );

const said = {};

for ( const tag of asked ) {
	said[ tag ] = reason( new Error( tag ) );
}

// Not an Error at all: a string, and nothing, which is what a rejected fetch
// can hand over.
said[ '#string' ] = reason( 'decode-failed' );
said[ '#nothing' ] = reason( undefined );

process.stdout.write( JSON.stringify( said ) );
JS
);

exec(
	sprintf(
		'cd %s && node %s %s %s 2>&1',
		escapeshellarg( $root ),
		escapeshellarg( $workdir . '/run.cjs' ),
		escapeshellarg( $build . '/failure.js' ),
		escapeshellarg( $workdir . '/ask.json' )
	),
	$output,
	$code
);

$said = json_decode( implode( '', $output ), true );

if ( 0 !== $code || ! is_array( $said ) ) {
	check( 'it runs', false, implode( ' / ', array_slice( $output, 0, 3 ) ) );
	exec( 'rm -rf ' . escapeshellarg( $workdir ) );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

check( 'and runs', true );

echo PHP_EOL . '# No tag falls through to the catch-all' . PHP_EOL;

/*
 * Read out of the code rather than written down here, so that rewording the
 * sentence does not quietly turn this test into one that passes no matter what.
 */
$catchall = $said['imgp-a-tag-nobody-has-written-yet'];

check(
	'an unknown tag still gets the cross-origin sentence',
	str_contains( $catchall, 'cross-origin' ),
	$catchall
);

foreach ( $tags as $tag ) {
	check(
		'"' . $tag . '" says something of its own',
		isset( $said[ $tag ] ) && $said[ $tag ] !== $catchall,
		$said[ $tag ] ?? 'nothing'
	);
}

echo PHP_EOL . '# A status is repeated back, not swallowed' . PHP_EOL;

foreach ( array( 'proxy-upstream-403' => '403', 'fetch-failed-404' => '404', 'fetch-failed-502' => '502' ) as $tag => $status ) {
	check(
		'"' . $tag . '" names ' . $status,
		str_contains( $said[ $tag ] ?? '', $status ),
		$said[ $tag ] ?? 'nothing'
	);
}

/*
 * 424 is the status this plugin's own refusals are sent as, because a 5xx from
 * PHP gets replaced by the web server's own page on LiteSpeed — reason header,
 * body and all. So a bare 424 with no reason attached is not "somebody in front
 * of WordPress answered": it is our own refusal with its reason stripped, and
 * it must not be reported as a gateway problem.
 */
check(
	'a bare 424 is not blamed on a gateway',
	! str_contains( $said['fetch-failed-424'] ?? '', 'between the browser and WordPress' ),
	$said['fetch-failed-424'] ?? 'nothing'
);

echo PHP_EOL . '# The location is appended exactly once' . PHP_EOL;

foreach ( array( 'slice-empty' => 'slice 9 of 13', 'proxy-upstream-403' => 'slice 2 of 13' ) as $tag => $where ) {
	$with = $said[ $tag . '|' . $where ] ?? '';

	check(
		'"' . $tag . '" carries its slice',
		1 === substr_count( $with, $where ),
		$with
	);

	check(
		'and says the same thing it says without one',
		'' !== $with && str_starts_with( $with, (string) ( $said[ $tag ] ?? "\0" ) ),
		$with
	);
}

echo PHP_EOL . '# And what the far end actually said' . PHP_EOL;

/*
 * The tag says which kind of failure it was; this says which one it actually
 * was. "Could not reach the server" covers a name that would not resolve, a
 * certificate that would not verify, a connection reset at byte forty million
 * and a timeout — and the HTTP client names which every time. That sentence
 * was being dropped, and the guesses that filled the gap cost somebody a
 * server setting their host had warned them not to change.
 */
$full = $said['proxy-upstream-unreachable|slice 13 of 13|cURL error 56: Recv failure: Connection reset by peer'] ?? '';

check( 'the words the client used survive to the screen', str_contains( $full, 'cURL error 56' ), $full );
check( 'beside which piece it was', str_contains( $full, 'slice 13 of 13' ), $full );
check(
	'and the sentence it belongs to is still in front of them',
	str_starts_with( $full, (string) ( $said['proxy-upstream-unreachable'] ?? "\0" ) ),
	$full
);

echo PHP_EOL . '# Whatever is thrown, a sentence comes back' . PHP_EOL;

check( 'a plain string is handled', ( $said['#string'] ?? '' ) === ( $said['decode-failed'] ?? "\0" ), $said['#string'] ?? 'nothing' );
check( 'and nothing at all is handled', '' !== ( $said['#nothing'] ?? '' ), $said['#nothing'] ?? 'nothing' );

exec( 'rm -rf ' . escapeshellarg( $workdir ) );
exec( 'rm -rf ' . escapeshellarg( $build ) );

echo PHP_EOL;

if ( $failures > 0 ) {
	echo "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

echo 'All checks passed.' . PHP_EOL;

/**
 * JSON, without WordPress loaded.
 *
 * @param mixed $data What to encode.
 */
function wp_json_encode_local( $data ): string {
	return (string) json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- no WordPress here.
}
