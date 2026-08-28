<?php
/**
 * Playlists.
 *
 * The property worth protecting here is that the list works before any
 * JavaScript does. Every item is a link to its own file, so a visitor with a
 * blocked bundle, a reader mode, or a search engine crawler still finds a page
 * of playable tracks rather than a list of dead text. The runtime's whole job
 * is to catch the click and hand it to the player already on the page.
 */

$plugin = dirname( __DIR__ ) . '/';

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

require $plugin . 'tests/bootstrap.php';

use ImaginaPlayer\Render\PlaylistRenderer;

echo PHP_EOL . '# What a playlist renders' . PHP_EOL;

$renderer = new PlaylistRenderer();

$items = array(
	array( 'src' => 'https://example.test/wp-content/uploads/1.mp3', 'title' => 'Uno', 'artist' => 'Imagina', 'duration' => 185 ),
	array( 'src' => 'https://example.test/wp-content/uploads/2.mp3', 'title' => 'Dos', 'duration' => 61 ),
	array( 'src' => 'https://example.test/wp-content/uploads/3.mp3', 'title' => 'Tres' ),
);

$html = $renderer->render( array( 'items' => $items, 'heading' => 'El curso' ) );

check( 'the playlist renders', str_contains( $html, 'imgp-playlist' ), substr( $html, 0, 120 ) );
check( 'the heading is shown', str_contains( $html, 'El curso' ) );
check( 'every track is listed', 3 === substr_count( $html, 'imgp-playlist__link' ), (string) substr_count( $html, 'imgp-playlist__link' ) );
check( 'the first track is the one loaded', str_contains( $html, 'imgp-playlist__item is-current' ) );

check(
	'a real player is rendered, not a placeholder',
	str_contains( $html, 'data-imagina-player' ),
	'the playlist drives the ordinary player rather than reimplementing one'
);

// The property this whole design exists for.
check(
	'each item is a link to its own file',
	3 === substr_count( $html, 'href="https://example.test/wp-content/uploads/' ),
	'without JavaScript, clicking a track must still play it'
);

check( 'durations are shown as minutes and seconds', str_contains( $html, '3:05' ) && str_contains( $html, '1:01' ), $html );
check( 'a track with no known duration shows none', 2 === substr_count( $html, 'imgp-playlist__time' ), (string) substr_count( $html, 'imgp-playlist__time' ) );
check( 'the tracks reach the client for swapping', str_contains( $html, 'data-imagina-playlist' ) );

$grid = $renderer->render( array( 'items' => $items, 'layout' => 'grid' ) );

check( 'a grid asks for the grid', str_contains( $grid, 'imgp-playlist--grid' ) );
check( 'and a list for the list', str_contains( $html, 'imgp-playlist--list' ) );
check( 'an unknown layout falls back to a list', str_contains( $renderer->render( array( 'items' => $items, 'layout' => 'carousel' ) ), 'imgp-playlist--list' ) );

$empty = $renderer->render( array( 'items' => array() ) );

check( 'an empty playlist says so rather than rendering nothing', str_contains( $empty, 'imgp--empty' ), $empty );

echo PHP_EOL . '# What does not survive' . PHP_EOL;

$hostile = PlaylistRenderer::sanitize_items(
	array(
		array( 'src' => 'javascript:alert(1)', 'title' => 'x' ),
		array( 'src' => '', 'title' => 'sin archivo' ),
		array( 'src' => 'https://example.test/ok.mp3', 'title' => '<img src=x onerror=alert(1)>' ),
		'not an array',
		array( 'src' => 'https://example.test/neg.mp3', 'title' => 'Negativa', 'duration' => -50 ),
	)
);

// Five went in; three of them are not tracks: a javascript: URL, an item with
// no file, and something that is not an array at all.
check( 'only the two real tracks survive', 2 === count( $hostile ), wp_json_encode( array_column( $hostile, 'src' ) ) );
check( 'and a javascript: source is not one of them', ! in_array( 'javascript:alert(1)', array_column( $hostile, 'src' ), true ) );
check( 'so is an item with no file', ! in_array( 'sin archivo', array_column( $hostile, 'title' ), true ) );
check( 'a tag in a title does not survive', ! str_contains( $hostile[0]['title'], '<img' ), $hostile[0]['title'] );
check( 'a negative duration becomes zero', 0.0 === $hostile[1]['duration'], (string) $hostile[1]['duration'] );

$injected = $renderer->render(
	array(
		'items' => array( array( 'src' => 'https://example.test/a.mp3', 'title' => '"><script>alert(1)</script>' ) ),
	)
);

check( 'a title cannot break out of the markup', ! str_contains( $injected, '<script>alert' ), $injected );

echo PHP_EOL . '# The chunk stays out of everything else' . PHP_EOL;

$core = (string) file_get_contents( $plugin . 'build/frontend.js' );

check( 'the playlist chunk exists', is_readable( $plugin . 'build/imagina-playlist.js' ) );
check(
	'and its code is not in the core bundle',
	! str_contains( $core, 'imagina-player-playlist' ),
	'a page with no playlist should not carry playlist code'
);
check( 'but the core knows how to fetch it', str_contains( $core, 'imagina-playlist' ) );

$chunk = (string) @file_get_contents( $plugin . 'build/imagina-playlist.js' );

check( 'the chunk is where the resume key lives', str_contains( $chunk, 'imagina-player-playlist' ), 'otherwise the check above passes for the wrong reason' );

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "{$failures} check(s) failed." . PHP_EOL;
	exit( 1 );
}
echo 'All checks passed.' . PHP_EOL;
