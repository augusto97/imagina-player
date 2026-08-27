<?php
/**
 * Why ffmpeg is unavailable — which is the part the notice has to get right.
 *
 * "Not found" covered three situations with three different fixes: a host that
 * forbids starting processes, a path typed in wrong, and nothing installed.
 * Only the last is answered by "ask your host to install ffmpeg", so a single
 * message sent two out of three people down the wrong road.
 */

$plugin = dirname( __DIR__ ) . '/';

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

require $plugin . 'tests/bootstrap.php';

use ImaginaPlayer\Peaks\PeaksGenerator;
use ImaginaPlayer\Settings;

function with_path( string $path ): array {
	$settings                        = Settings::defaults();
	$settings['peaks']['ffmpeg_path'] = $path;
	update_option( Settings::OPTION_KEY, $settings );
	Settings::flush_cache();

	return PeaksGenerator::diagnosis();
}

$states = array();

// A configured path that is not there at all.
$missing            = with_path( '/definitely/not/here/ffmpeg' );
$states['missing']  = $missing['state'];

check(
	'a path that does not exist says so',
	'path-missing' === $missing['state'],
	$missing['state']
);
check(
	'and it reports back the path it was given',
	'/definitely/not/here/ffmpeg' === $missing['configured'],
	$missing['configured']
);

// A path that exists but is not ffmpeg. `true` answers nothing on stdout.
$decoy = '/bin/true';

if ( is_file( $decoy ) ) {
	$wrong = with_path( $decoy );

	check(
		'a real file that is not ffmpeg is told apart from a missing one',
		'path-not-ffmpeg' === $wrong['state'],
		$wrong['state']
	);
} else {
	check( 'a real file that is not ffmpeg is told apart from a missing one', true, 'skipped: no /bin/true' );
}

// Nothing configured: whatever the host has, or has not.
$blank = with_path( '' );

check(
	'with no path set, the answer depends on the host rather than erroring',
	in_array( $blank['state'], array( 'ok', 'not-installed', 'processes-disabled' ), true ),
	$blank['state']
);
check(
	'and it agrees with is_available()',
	( 'ok' === $blank['state'] ) === ( '' !== PeaksGenerator::binary() ),
	$blank['state'] . ' / ' . PeaksGenerator::binary()
);

// The cache bug this shook out: the resolved binary was held in a single slot
// for the whole request, so saving a new path on the settings screen and
// re-reading the status — which happen in one request — reported the value
// from before the save. A stand-in that answers like ffmpeg makes that
// visible: resolve it, then point somewhere else and see whether the old
// answer comes back.
$fake = sys_get_temp_dir() . '/imgp-fake-ffmpeg-' . bin2hex( random_bytes( 4 ) );
file_put_contents( $fake, "#!/bin/sh\necho 'ffmpeg version 6.0 (test stand-in)'\n" );
chmod( $fake, 0755 );

$resolved = with_path( $fake );

check(
	'a working binary at a configured path is found',
	'ok' === $resolved['state'],
	$resolved['state']
);

$moved = with_path( '/also/not/here/ffmpeg' );

check(
	'changing the path in the same request is resolved fresh, not from cache',
	'path-missing' === $moved['state'],
	$moved['state'] . ' (would be "ok" if the earlier answer were cached)'
);

$second = $moved;

unlink( $fake );

// Every state the interface knows how to explain.
$known = array( 'ok', 'processes-disabled', 'path-missing', 'path-not-ffmpeg', 'not-installed' );

foreach ( array( $missing, $blank, $second ) as $result ) {
	check(
		'the state is one the interface has wording for: ' . $result['state'],
		in_array( $result['state'], $known, true )
	);
}

// The admin bundle has to carry a message for each of them.
$panels = (string) file_get_contents( $plugin . 'assets/src/admin/panels.tsx' );

foreach ( array( 'processes-disabled', 'path-missing', 'path-not-ffmpeg' ) as $state ) {
	check(
		"the settings screen has its own wording for {$state}",
		str_contains( $panels, "case '{$state}':" ),
		$state
	);
}

check(
	'the built admin bundle carries the disable_functions explanation',
	str_contains( (string) file_get_contents( $plugin . 'build/admin.js' ), 'disable_functions' )
);

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "{$failures} check(s) failed." . PHP_EOL;
	exit( 1 );
}
echo 'All checks passed.' . PHP_EOL;
