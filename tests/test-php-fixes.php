<?php
/**
 * What the PHP audit found, each kept fixed by a check of its own.
 *
 * Small things, most of them, but each one a promise the plugin made and did
 * not keep: a shortcode attribute that was ignored, a time that was read as
 * the wrong number, a duration printed as minutes past the hour, a class a
 * theme saw twice, a right-to-left site served the left-to-right sheet.
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/elementor-stubs.php';

use ImaginaPlayer\Assets;
use ImaginaPlayer\Blocks\BlockRegistrar;
use ImaginaPlayer\Integrations\Elementor\Integration;
use ImaginaPlayer\Player\Attributes;
use ImaginaPlayer\Player\Layers;
use ImaginaPlayer\Render\PlayerRenderer;
use ImaginaPlayer\Render\PlaylistRenderer;
use ImaginaPlayer\Shortcodes\PlayerShortcode;

$root = dirname( __DIR__ );

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

echo '# The version of PHP the plugin says it needs' . PHP_EOL;

/*
 * The header says 8.0. `readonly` properties are 8.1, and on 8.0 they are a
 * parse error the moment the class loads — the plugin activated and then the
 * first player on any page took the site down. Checked across the source so a
 * newer construct cannot creep back in.
 */
$newer = array();

foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src' ) ) as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}

	// Code only: a comment may say `HYPERLINK(...)` without being a callable.
	$code = '';

	foreach ( token_get_all( (string) file_get_contents( (string) $file ) ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		$code .= is_array( $token ) ? $token[1] : $token;
	}

	foreach ( array(
		'readonly property'     => '/\breadonly\s+(?:public|protected|private|[?\\\\A-Za-z])/',
		'enum'                  => '/^\s*enum\s+[A-Z]/m',
		'never return type'     => '/\)\s*:\s*never\b/',
		'first-class callable'  => '/\w\(\s*\.\.\.\s*\)/',
		'new in an initialiser' => '/function\s+\w+\s*\([^)]*=\s*new\s+/',
	) as $what => $pattern ) {
		if ( preg_match( $pattern, $code ) ) {
			$newer[] = $what . ' in ' . basename( (string) $file );
		}
	}
}

check( 'no PHP 8.1 syntax anywhere in the plugin', array() === $newer, implode( ', ', $newer ) );
check( 'and the header still says 8.0', str_contains( (string) file_get_contents( $root . '/imagina-player.php' ), "Requires PHP:      8.0" ) );

echo PHP_EOL . '# Shortcodes and times' . PHP_EOL;

$shortcode = new PlayerShortcode();
$classed   = $shortcode->render( array( 'src' => 'https://cdn.example.com/a.mp3', 'class' => 'my-player' ) );
check( '[imagina_player class="…"] reaches the player', str_contains( $classed, 'my-player' ) );

check( 'start_time="1:30" is a minute and a half', 90.0 === Attributes::sanitize( array( 'startTime' => '1:30' ) )['startTime'] );
check( 'and a plain number is still seconds', 42.0 === Attributes::sanitize( array( 'startTime' => '42' ) )['startTime'] );
check( 'and nonsense is zero', 0.0 === Attributes::sanitize( array( 'startTime' => 'soon' ) )['startTime'] );

echo PHP_EOL . '# What the page prints' . PHP_EOL;

$long = ( new PlaylistRenderer() )->render(
	array(
		'items' => array(
			array( 'src' => 'https://cdn.example.com/a.mp3', 'title' => 'Long', 'duration' => 4500 ),
			array( 'src' => 'https://cdn.example.com/b.mp3', 'title' => 'Short', 'duration' => 61 ),
		),
	)
);
check( 'an hour and a quarter reads 1:15:00 in a list', str_contains( $long, '>1:15:00<' ) && ! str_contains( $long, '75:00' ) );

$renderer = new PlayerRenderer();
$video    = $renderer->render( array( 'src' => 'https://cdn.example.com/v.mp4', 'thumbnail' => 'https://cdn.example.com/cover.jpg' ) );
$audio    = $renderer->render( array( 'src' => 'https://cdn.example.com/a.mp3', 'thumbnail' => 'https://cdn.example.com/cover.jpg' ) );
check( 'a video does not claim a thumbnail it never shows', ! str_contains( $video, 'imgp--has-thumb' ) );
check( 'audio still does', str_contains( $audio, 'imgp--has-thumb' ) );

$chapters    = array( array( 'start' => '0:00', 'title' => 'Uno' ), array( 'start' => '1:00', 'title' => 'Dos' ) );
$audio_chaps = $renderer->render( array( 'src' => 'https://cdn.example.com/a.mp3', 'chapters' => $chapters ) );
$video_chaps = $renderer->render( array( 'src' => 'https://cdn.example.com/v.mp4', 'chapters' => $chapters ) );
check( 'audio is not given a chapter track nothing reads', ! str_contains( $audio_chaps, 'kind="chapters"' ) );
check( 'video still is', str_contains( $video_chaps, 'kind="chapters"' ) );

$stream = $renderer->render( array( 'src' => 'https://cdn.example.com/live/index.m3u8', 'thumbnail' => 'https://cdn.example.com/cover.jpg' ) );
check( 'a stream borrows the cover as its poster, as a file does', str_contains( $stream, 'imgp__poster' ) && str_contains( $stream, 'cover.jpg' ) );

// WordPress puts the block's class on the wrapper; the player must not repeat it.
$block = ( new BlockRegistrar() )->render( array( 'src' => 'https://cdn.example.com/a.mp3', 'className' => 'is-style-x' ) );
check( 'a block’s own class is not repeated on the player inside it', 1 !== preg_match( '/class="imgp [^"]*is-style-x/', $block ) );
check( 'while the shortcode, which has no wrapper, still puts it on the player', 1 === preg_match( '/class="imgp [^"]*is-style-x/', $renderer->render( array( 'src' => 'https://cdn.example.com/a.mp3', 'className' => 'is-style-x' ) ) ) );

$uploads = sys_get_temp_dir() . '/imgp-srt-' . getmypid();
@mkdir( $uploads . '/2026', 0777, true );
file_put_contents( $uploads . '/2026/subs.srt', "1\n00:00:01,000 --> 00:00:02,000\nHola\n" );
$GLOBALS['stub_uploads_dir'] = $uploads;
$local = new ReflectionMethod( ImaginaPlayer\Media\Captions::class, 'local_path' );
check( 'a subtitle file given as /wp-content/uploads/… is found', is_string( $local->invoke( null, '/wp-content/uploads/2026/subs.srt' ) ) );
check( 'and as //host/wp-content/uploads/…', is_string( $local->invoke( null, '//example.test/wp-content/uploads/2026/subs.srt' ) ) );
check( 'but not a relative path that climbs out', null === $local->invoke( null, '/wp-content/uploads/../../etc/passwd.srt' ) );
unset( $GLOBALS['stub_uploads_dir'] );
@unlink( $uploads . '/2026/subs.srt' );
@rmdir( $uploads . '/2026' );
@rmdir( $uploads );

echo PHP_EOL . '# Stylesheets and the runtime object' . PHP_EOL;

$GLOBALS['stub_style_data'] = array();
( new Assets() )->register();
check( 'the front-end sheet has its right-to-left twin registered', 'replace' === ( $GLOBALS['stub_style_data'][ Assets::FRONTEND_HANDLE ]['rtl'] ?? '' ) );
check( 'and the editor’s', 'replace' === ( $GLOBALS['stub_style_data'][ Assets::EDITOR_HANDLE ]['rtl'] ?? '' ) );
check( 'and the files are there to be found', is_readable( $root . '/build/style-frontend-rtl.css' ) && is_readable( $root . '/build/editor-rtl.css' ) && is_readable( $root . '/build/admin-rtl.css' ) );

$data = Assets::runtime_data();
check( 'the runtime object carries the nonce once', 1 === substr_count( (string) wp_json_encode( array_keys( $data ) ), '"nonce"' ) );

$GLOBALS['stub_inline_scripts'] = array();
$GLOBALS['stub_script_status']  = array();
// A fresh request: the renders above already attached it once in this one.
( new ReflectionProperty( Assets::class, 'runtime_attached' ) )->setValue( null, false );
Assets::attach_runtime();
check( 'nothing is attached to a script that is not on the page', array() === $GLOBALS['stub_inline_scripts'] );
$GLOBALS['stub_script_status'][ Assets::FRONTEND_HANDLE ]['enqueued'] = true;
Assets::attach_runtime();
check( 'enqueued some other way — a block’s view script, a cached builder element — it gets the runtime object', 1 === count( $GLOBALS['stub_inline_scripts'] ) && str_contains( (string) ( $GLOBALS['stub_inline_scripts'][0][1] ?? '' ), 'window.imaginaPlayer' ) );
Assets::attach_runtime();
check( 'once', 1 === count( $GLOBALS['stub_inline_scripts'] ) );

echo PHP_EOL . '# Elementor' . PHP_EOL;

$integration = new Integration();
$widgets     = new Elementor\Widgets_Manager();
if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
	define( 'ELEMENTOR_VERSION', '3.5.0' );
}
$integration->register_widgets( $widgets );
$video_widget = $widgets->registered['imagina-video-player'];
$controls     = $video_widget->get_controls();

check( 'the accent picker offers solid colours only, which is what the player takes', false === ( $controls['accent']['alpha'] ?? null ) );

$layers = $video_widget->attributes_from(
	array(
		'layers' => array(
			array( 'type' => 'image', 'image' => array( 'url' => 'https://cdn.example.com/logo.png' ), 'at' => '' ),
			array( 'type' => 'cta', 'url' => array( 'url' => 'https://example.test/' ), 'at' => '' ),
			array( 'type' => 'text', 'title' => 'Hola', 'at' => '30' ),
		),
	)
)['layers'];
$clean = Layers::sanitize( $layers );
check( 'an image overlay left on the default appears from the start', 0 === ( $clean[0]['at'] ?? -1 ), (string) ( $clean[0]['at'] ?? '?' ) );
check( 'a call to action left on the default waits for the end', 100 === ( $clean[1]['at'] ?? -1 ), (string) ( $clean[1]['at'] ?? '?' ) );
check( 'and a moment that was given is kept', 30 === ( $clean[2]['at'] ?? -1 ), (string) ( $clean[2]['at'] ?? '?' ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
