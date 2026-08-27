<?php
require __DIR__ . '/wp-stubs.php';

$plugin = dirname( __DIR__ ) . '/';

require_once $plugin . 'src/Support/Autoloader.php';
ImaginaPlayer\Support\Autoloader::register( 'ImaginaPlayer', $plugin . 'src' );

define( 'ImaginaPlayer\FILE', $plugin . 'imagina-player.php' );
define( 'ImaginaPlayer\PATH', $plugin );
define( 'ImaginaPlayer\URL', 'https://example.test/wp-content/plugins/imagina-player/' );

// The bootstrap file defines VERSION etc. inside the namespace; replicate them.
if ( ! defined( 'ImaginaPlayer\VERSION' ) ) {
	define( 'ImaginaPlayer\VERSION', '0.1.0' );
	define( 'ImaginaPlayer\SLUG', 'imagina-player' );
}

use ImaginaPlayer\Peaks\PeaksRepository;
use ImaginaPlayer\Peaks\PeaksToken;
use ImaginaPlayer\Player\Attributes;
use ImaginaPlayer\Render\PlayerRenderer;
use ImaginaPlayer\Settings;

$failures = 0;
function check( string $label, bool $ok ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? "PASS  " : "FAIL  " ) . $label . PHP_EOL;
}

// --- Settings ---------------------------------------------------------------
$settings = Settings::all();
check( 'default preset exists', isset( $settings['presets']['default'] ) );
check( 'default skin is wave', 'wave' === $settings['presets']['default']['skin'] );
check( 'colour sanitiser keeps hex', '#ff0000' === Settings::sanitize_color( '#ff0000', '#000000' ) );
check( 'colour sanitiser repairs bare hex', '#ff0000' === Settings::sanitize_color( 'ff0000', '#000000' ) );
check( 'colour sanitiser rejects junk', '#000000' === Settings::sanitize_color( 'javascript:alert(1)', '#000000' ) );
check( 'colour sanitiser allows custom properties', 'var(--brand)' === Settings::sanitize_color( 'var(--brand)', '#000' ) );

// --- Attributes -------------------------------------------------------------
$atts = Attributes::sanitize( array(
	'src'        => 'https://cdn.example.com/track.mp3',
	'title'      => '1.1 "El camino del amor"',
	'artist'     => 'Elízabeth Guerra Gómez',
	'showVolume' => 'no',
	'startTime'  => '-4',
) );
check( 'tri-state parses "no"', 'no' === $atts['showVolume'] );
check( 'tri-state defaults to inherit', '' === $atts['showTime'] );
check( 'negative start time clamped', 0.0 === $atts['startTime'] );
check( 'javascript: URLs dropped', '' === Attributes::sanitize( array( 'src' => 'javascript:alert(1)' ) )['src'] );

// --- Peaks encoding ---------------------------------------------------------
$source = array( 0.0, 0.5, 1.0, 0.25 );
$roundtrip = PeaksRepository::decode( PeaksRepository::encode( $source ) );
check( 'peaks round-trip length', count( $roundtrip ) === 4 );
check( 'peaks round-trip precision', abs( $roundtrip[1] - 0.5 ) < 0.01 && 1.0 === $roundtrip[2] );
check( 'resample keeps bucket maxima', PeaksRepository::resample( array( 0.1, 0.9, 0.2, 0.3 ), 2 ) === array( 0.9, 0.3 ) );
$normalized = PeaksRepository::normalize( array( 0.2, 0.4 ) );
check( 'normalize scales to 1', 1.0 === $normalized[1] && abs( $normalized[0] - 0.5 ) < 0.0001 );

// --- Token ------------------------------------------------------------------
$token = PeaksToken::create( 'url_' . md5( 'x' ), 400 );
$claim = PeaksToken::verify( $token );
check( 'token round-trips', is_array( $claim ) && 400 === $claim['resolution'] );
check( 'tampered token rejected', null === PeaksToken::verify( substr( $token, 0, -3 ) . 'aaa' ) );
check( 'garbage token rejected', null === PeaksToken::verify( 'nonsense' ) );

// --- Render -----------------------------------------------------------------
$renderer = new PlayerRenderer();
$html = $renderer->render( array(
	'src'    => 'https://cdn.example.com/track.mp3',
	'title'  => '1.1 "El camino del amor"',
	'artist' => 'Elízabeth Guerra Gómez',
) );

check( 'renders a player root', str_contains( $html, 'data-imagina-player=' ) );
check( 'renders native controls for the no-JS case', str_contains( $html, '<audio' ) && str_contains( $html, 'controls' ) );
check( 'renders the waveform canvas', str_contains( $html, 'class="imgp__wave"' ) );
check( 'renders an accessible seek slider', str_contains( $html, 'role="slider"' ) );
check( 'escapes the title', str_contains( $html, '&quot;El camino del amor&quot;' ) );
check( 'carries CSS custom properties', str_contains( $html, '--imgp-accent:#c04ec4' ) );
check( 'mints a peaks token when nothing is cached', str_contains( $html, '&quot;peaksToken&quot;' ) && ! str_contains( $html, '"peaksToken":""' ) );

$empty = $renderer->render( array() );
check( 'empty source renders an editor hint', str_contains( $empty, 'imgp--empty' ) );

check( 'formats hours', '1:01:01' === $renderer->format_time( 3661 ) );
check( 'formats minutes', '52:54' === $renderer->format_time( 3174 ) );

// XSS probe through every string that reaches the markup.
$xss = $renderer->render( array(
	'src'    => 'https://cdn.example.com/a.mp3"><script>alert(1)</script>',
	'title'  => '<script>alert(1)</script>',
	'artist' => '"><img src=x onerror=alert(1)>',
) );
check( 'no raw script tag survives', ! str_contains( $xss, '<script>' ) );
check( 'no raw img injection survives', ! str_contains( $xss, '<img src=x' ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
