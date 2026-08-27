<?php
require __DIR__ . '/wp-stubs.php';

$plugin = dirname( __DIR__ ) . '/';
require_once $plugin . 'src/Support/Autoloader.php';
ImaginaPlayer\Support\Autoloader::register( 'ImaginaPlayer', $plugin . 'src' );
define( 'ImaginaPlayer\VERSION', '0.1.0' );
define( 'ImaginaPlayer\PATH', $plugin );
define( 'ImaginaPlayer\URL', 'https://example.test/' );

use ImaginaPlayer\Peaks\PeaksGenerator;
use ImaginaPlayer\Settings;

// Point the generator at the stub ffmpeg.
update_option( Settings::OPTION_KEY, array(
	'peaks' => array( 'server_generation' => true, 'ffmpeg_path' => __DIR__ . '/fixtures/fakebin/ffmpeg' ),
) );
Settings::flush_cache();

$failures = 0;
function check( string $label, bool $ok ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? "PASS  " : "FAIL  " ) . $label . PHP_EOL;
}

check( 'detects the ffmpeg binary', PeaksGenerator::is_available() );

$file = __DIR__ . '/fixtures/fakebin/ffmpeg'; // Any readable file: the stub ignores the input.
$peaks = PeaksGenerator::generate( $file, 20 );

check( 'returns peaks', is_array( $peaks ) && count( $peaks ) === 20 );

if ( is_array( $peaks ) && 20 === count( $peaks ) ) {
	$first_half  = array_sum( array_slice( $peaks, 0, 10 ) ) / 10;
	$second_half = array_sum( array_slice( $peaks, 10 ) ) / 10;

	check( 'quiet first half measured around 25%', abs( $first_half - 0.25 ) < 0.05 );
	check( 'loud second half normalised to 1', abs( $second_half - 1.0 ) < 0.02 );
	check( 'every value within 0..1', count( array_filter( $peaks, static fn( $p ) => $p < 0 || $p > 1 ) ) === 0 );
}

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
