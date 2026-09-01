<?php
/**
 * Settings, attribute sanitisation, peaks encoding and rendered markup.
 */

require __DIR__ . '/bootstrap.php';

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
/*
 * Combined by energy, not by the loudest of the bucket.
 *
 * It used to keep the maximum, and on a long recording that saturates: every
 * few seconds of somebody talking contains a syllable at full volume, so every
 * bar came out the same height and an hour of teaching was drawn as a comb of
 * identical teeth. Loudness across the bucket is what the ear calls loud, and
 * it has to survive being resampled or the reduction is undone here.
 */
$resampled = PeaksRepository::resample( array( 0.1, 0.9, 0.2, 0.3 ), 2 );
check(
	'resample combines a bucket by loudness, not by its loudest value',
	abs( $resampled[0] - sqrt( ( 0.01 + 0.81 ) / 2 ) ) < 0.0001
		&& abs( $resampled[1] - sqrt( ( 0.04 + 0.09 ) / 2 ) ) < 0.0001,
	implode( ', ', array_map( static fn( $v ) => round( $v, 4 ), $resampled ) )
);
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
check( 'carries CSS custom properties', str_contains( $html, '--imgp-accent:#1f2937' ) );
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

// Every skin must actually produce its own layout. The block preview renders
// through this same path now, and the lookalike it replaced drew card, compact
// and pill as the plain stacked one.
$layouts = array(
	'wave'          => array( 'imgp__wave', 'imgp__bar' ),
	'wave-centered' => array( 'imgp__wave', 'imgp__bar' ),
	'card'          => array( 'imgp__wave', 'imgp__body' ),
	'compact'       => array( 'imgp__row', 'imgp__track' ),
	'pill'          => array( 'imgp__row', 'imgp__track' ),
	'bar'           => array( 'imgp__track', 'imgp__bar' ),
	'minimal'       => array( 'imgp__bar' ),
);

foreach ( $layouts as $skin => $expected ) {
	$markup = $renderer->render( array(
		'src'   => 'https://cdn.example.com/a.mp3',
		'title' => 'T',
		'skin'  => $skin,
	) );

	$missing = array();

	foreach ( $expected as $marker ) {
		if ( ! str_contains( $markup, $marker ) ) {
			$missing[] = $marker;
		}
	}

	check( "skin {$skin} renders its own layout", array() === $missing, implode( ', ', $missing ) );
}

// Only the skins that draw a waveform get a canvas.
$minimal = $renderer->render( array( 'src' => 'https://cdn.example.com/a.mp3', 'skin' => 'minimal' ) );
check( 'the minimal skin has no scrubber at all', ! str_contains( $minimal, 'imgp__scrubber' ) );

$bar = $renderer->render( array( 'src' => 'https://cdn.example.com/a.mp3', 'skin' => 'bar' ) );
check( 'the bar skin has no waveform canvas', ! str_contains( $bar, '<canvas' ) );

// The Plugins screen is where people look for a plugin's settings, so the row
// has to carry a link to them.
ImaginaPlayer\Plugin::instance()->boot();

$basename    = plugin_basename( ImaginaPlayer\FILE );
$row_links   = apply_filters( 'plugin_action_links_' . $basename, array( '<a href="#">Deactivate</a>' ) );
$settings_ok = false;

foreach ( $row_links as $link ) {
	if ( str_contains( $link, 'page=imagina-player' ) && str_contains( $link, 'Settings' ) ) {
		$settings_ok = true;
	}
}

check( 'the plugins list gets a Settings link', $settings_ok, implode( ' | ', $row_links ) );
check( 'and it comes first in the row', str_contains( (string) ( $row_links[0] ?? '' ), 'page=imagina-player' ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
