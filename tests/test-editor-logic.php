<?php
/**
 * What the block editor and the settings screen were found to get wrong.
 *
 * "Site: on" read from the factory instead of the site; subtitles that could
 * never appear in the preview; a settings screen that said "Saved." over a
 * value it had just changed; a playlist whose "Edit tracks" threw away what
 * the author had typed; overlays the server drops with nothing to say why;
 * block names and descriptions that were never translatable.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Blocks\BlockRegistrar;
use ImaginaPlayer\Player\Layers;
use ImaginaPlayer\Rest\SettingsController;
use ImaginaPlayer\Settings;

$root = dirname( __DIR__ );

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

echo '# What "Site" means in the inspector' . PHP_EOL;

$GLOBALS['stub_options']['imagina_player_settings'] = array(
	'presets' => array(
		'default' => array_merge( Settings::preset_defaults(), array( 'show_download' => true, 'sticky' => true ) ),
		'podcast' => array_merge( Settings::preset_defaults(), array( 'label' => 'Podcast', 'show_speed' => true, 'accent' => '#d6336c' ) ),
	),
	'video'   => array_merge( Settings::defaults()['video'], array( 'show_pip' => false, 'ratio' => '4:3', 'hide_after' => 4000 ) ),
);
Settings::flush_cache();

$GLOBALS['stub_inline_scripts'] = array();
( new BlockRegistrar() )->enqueue_editor_data();

$script = (string) ( $GLOBALS['stub_inline_scripts'][0][1] ?? '' );
$data   = json_decode( (string) preg_replace( '/^window\.imaginaPlayerEditor = |;$/', '', $script ), true );

check( 'the editor is told what each preset says', true === ( $data['presetValues']['default']['show_download'] ?? null ) && true === ( $data['presetValues']['default']['sticky'] ?? null ), wp_json_encode( $data['presetValues']['default'] ?? null ) );
check( 'per preset, so a block on another preset is not told the default’s', true === ( $data['presetValues']['podcast']['show_speed'] ?? null ) && '#d6336c' === ( $data['presetValues']['podcast']['accent'] ?? null ) );
check( 'and what the site’s video settings are', false === ( $data['video']['show_pip'] ?? null ) && '4:3' === ( $data['video']['ratio'] ?? null ) && 4000 === ( $data['video']['hide_after'] ?? null ) );
check( 'with the attribute each one is overridden by', 'videoPip' === ( $data['videoOverrides']['show_pip'] ?? null ) );
check( 'but nothing it does not need: no policy for the whole site', ! isset( $data['video']['provider_privacy'] ) );

unset( $GLOBALS['stub_options']['imagina_player_settings'] );
Settings::flush_cache();

echo PHP_EOL . '# Subtitles in the preview' . PHP_EOL;

$uploads = sys_get_temp_dir() . '/imgp-editor-uploads-' . getmypid();
@mkdir( $uploads . '/2026/10', 0777, true );
file_put_contents( $uploads . '/2026/10/subs.vtt', "WEBVTT\n\n00:00.000 --> 00:02.000\nHola\n" );
file_put_contents( $uploads . '/2026/10/subs.srt', "1\n00:00:00,000 --> 00:00:02,000\nAdiós\n" );
$GLOBALS['stub_uploads_dir'] = $uploads;
$GLOBALS['stub_uploads_url'] = 'https://example.test/wp-content/uploads';

$block = array(
	'src'    => 'https://cdn.example.com/clase.mp4',
	'tracks' => array(
		array( 'src' => 'https://example.test/wp-content/uploads/2026/10/subs.vtt', 'srclang' => 'es', 'label' => 'Español' ),
		array( 'src' => 'https://example.test/wp-content/uploads/2026/10/subs.srt', 'srclang' => 'pt', 'label' => 'Português' ),
		array( 'src' => 'https://elsewhere.example/en.vtt', 'srclang' => 'en', 'label' => 'English' ),
	),
);

$preview = ( new SettingsController() )->preview( new WP_REST_Request( array( 'attributes' => $block ) ) )->get_data();
$html    = (string) ( $preview['html'] ?? '' );

preg_match_all( '/<track kind="subtitles" src="([^"]+)"/', $html, $sources );
$first  = (string) ( $sources[1][0] ?? '' );
$second = (string) ( $sources[1][1] ?? '' );

check( 'a subtitle file of this site is written into the preview', str_starts_with( $first, 'data:text/vtt' ) && str_contains( (string) base64_decode( substr( $first, strpos( $first, ',' ) + 1 ) ), 'Hola' ), substr( $first, 0, 40 ) );
check( 'a SubRip one too, converted on the way', str_starts_with( $second, 'data:text/vtt' ) && str_contains( (string) base64_decode( substr( $second, strpos( $second, ',' ) + 1 ) ), '00:00:00.000 --> 00:00:02.000' ) );
check( 'one on another site stays a link, and the editor is told', str_contains( (string) ( $sources[1][2] ?? '' ), 'elsewhere.example' ) && 1 === ( $preview['linkedCaptions'] ?? null ), wp_json_encode( $preview['linkedCaptions'] ?? null ) );

$page = ( new ImaginaPlayer\Render\PlayerRenderer() )->render( $block );
check( 'and a published page links every file as before', ! str_contains( $page, 'data:text/vtt;charset=utf-8;base64,V0VCVlRU' ) );

array_map( 'unlink', (array) glob( $uploads . '/2026/10/*' ) );
@rmdir( $uploads . '/2026/10' );
@rmdir( $uploads . '/2026' );
@rmdir( $uploads );
unset( $GLOBALS['stub_uploads_dir'], $GLOBALS['stub_uploads_url'] );

echo PHP_EOL . '# Saving says what it changed' . PHP_EOL;

$controller = new SettingsController();
$saved      = $controller->update_settings(
	new WP_REST_Request(
		array(
			'branding' => array( 'accent' => 'nonsense', 'wave_color' => 'c9ced6' ),
			'peaks'    => array( 'resolution' => 5, 'ffmpeg_path' => 'ffmpeg' ),
			'video'    => array( 'hide_after' => 90000 ),
		)
	)
)->get_data();
$notes = implode( ' | ', (array) ( $saved['adjusted'] ?? array() ) );

check( 'a colour that is not one is reported', str_contains( $notes, '“nonsense” is not a colour' ), $notes );
check( 'a bare hex is a colour, and is not', ! str_contains( $notes, 'c9ced6' ) );
check( 'a number out of range is reported', str_contains( $notes, 'Bars per waveform' ) && str_contains( $notes, 'Hide the controls after' ) );
check( 'an ffmpeg path that is not a path is reported', str_contains( $notes, 'ffmpeg path was cleared' ) );

$clean = $controller->update_settings( new WP_REST_Request( array( 'peaks' => array( 'resolution' => 400 ) ) ) )->get_data();
check( 'and a save with nothing changed has nothing to report', array() === ( $clean['adjusted'] ?? null ) );

$GLOBALS['stub_options']['imagina_player_settings'] = array(
	'branding' => array_merge( Settings::defaults()['branding'], array( 'accent' => '#0a7c66' ) ),
);
Settings::flush_cache();
$controller->update_settings( new WP_REST_Request( array( 'presets' => array( 'other' => Settings::preset_defaults() ) ) ) );
Settings::flush_cache();
check( 'a default preset that went missing comes back in the brand colours', '#0a7c66' === ( Settings::presets()['default']['accent'] ?? null ), (string) ( Settings::presets()['default']['accent'] ?? '' ) );
unset( $GLOBALS['stub_options']['imagina_player_settings'] );
Settings::flush_cache();

echo PHP_EOL . '# Block names, translatable' . PHP_EOL;

$pot = $root . '/build/.editor-logic.pot';
shell_exec( 'php ' . escapeshellarg( $root . '/bin/make-pot.php' ) . ' ' . escapeshellarg( $pot ) );
$template = (string) file_get_contents( $pot );
@unlink( $pot );

check( 'a block’s title is in the template, under the context WordPress asks for', (bool) preg_match( '/msgctxt "block title"\nmsgid "Imagina Video Player"/', $template ) );
check( 'and its description', (bool) preg_match( '/msgctxt "block description"\nmsgid "Several tracks or videos in one player/', $template ) );
check( 'and its keywords', (bool) preg_match( '/msgctxt "block keyword"\nmsgid "waveform"/', $template ) );

$mo = $root . '/languages/imagina-player-es_ES.mo';
check( 'and the Spanish file carries them, with their context', str_contains( (string) file_get_contents( $mo ), "block title\x04Imagina Video Player" ) );

echo PHP_EOL . '# The editor’s own logic, run' . PHP_EOL;

$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );

if ( '' === $node ) {
	echo 'SKIP  no node' . PHP_EOL;
} else {
	$out = $root . '/build/.editor-logic';
	shell_exec(
		sprintf(
			'cd %s && npx tsc assets/src/editor/layer-rules.ts assets/src/editor/playlist-items.ts --outDir %s --module commonjs --target es2020 --lib es2020 2>&1',
			escapeshellarg( $root ),
			escapeshellarg( $out )
		)
	);

	$layers = array(
		array( 'type' => 'cta', 'title' => 'Sin enlace', 'url' => '' ),
		array( 'type' => 'cta', 'title' => 'Con enlace', 'url' => 'https://example.com' ),
		array( 'type' => 'bar', 'title' => 'Barra', 'url' => '' ),
		array( 'type' => 'email', 'title' => 'Correo' ),
		array( 'type' => 'text', 'title' => '', 'text' => '' ),
		array( 'type' => 'text', 'title' => 'Hola' ),
		array( 'type' => 'image', 'image' => '' ),
		array( 'type' => 'image', 'image' => 'https://example.com/a.png' ),
		array( 'type' => 'hotspot', 'x' => 10, 'y' => 10 ),
		array( 'type' => 'hotspot', 'x' => 10, 'y' => 10, 'title' => 'Aquí' ),
		array( 'type' => 'shortcode', 'shortcode' => 'contact-form' ),
		array( 'type' => 'shortcode', 'shortcode' => '[contact-form]' ),
	);

	$script = $out . '/run.js';
	file_put_contents(
		$script,
		'const { layerGap } = require( "./layer-rules.js" );'
		. 'const { merge, seconds, clock } = require( "./playlist-items.js" );'
		. 'const layers = ' . wp_json_encode( $layers ) . ';'
		. 'const items = [ { id: 7, src: "a.mp3", title: "Typed by hand" }, { id: 0, src: "https://www.youtube.com/watch?v=x", title: "By address" }, { id: 8, src: "b.mp3", title: "Dropped" } ];'
		. 'const picked = [ { id: 9, url: "c.mp3", title: "New", fileLength: "3:14" }, { id: 7, url: "a.mp3", title: "Library title" } ];'
		. 'console.log( JSON.stringify( { gaps: layers.map( layerGap ), merged: merge( items, picked ), seconds: [ seconds( "3:14" ), seconds( "1:02:03" ), seconds( "75" ), seconds( "3:" ) ], clock: [ clock( 194 ), clock( 3723 ), clock( 0 ) ] } ) );'
	);

	$result = json_decode( (string) shell_exec( 'node ' . escapeshellarg( $script ) . ' 2>&1' ), true );
	shell_exec( 'rm -rf ' . escapeshellarg( $out ) );

	$agree = true;

	foreach ( $layers as $index => $layer ) {
		$dropped  = '' !== ( $result['gaps'][ $index ] ?? '?' );
		$survived = array() !== Layers::sanitize( array( $layer ) );

		if ( $dropped === $survived ) {
			$agree = false;
			echo '      disagreement on ' . wp_json_encode( $layer ) . PHP_EOL;
		}
	}

	check( 'the editor warns about exactly the overlays the server drops', is_array( $result ) && $agree, wp_json_encode( $result['gaps'] ?? null ) );

	$merged = (array) ( $result['merged'] ?? array() );
	check( 'editing the tracks keeps what was typed for a file still picked', 'Typed by hand' === ( $merged[0]['title'] ?? null ), wp_json_encode( $merged ) );
	check( 'keeps an item added by address, in its place', 'By address' === ( $merged[1]['title'] ?? null ) );
	check( 'drops a file left out of the selection, and adds a new one at the end', 3 === count( $merged ) && 'New' === ( $merged[2]['title'] ?? null ) );
	check( 'with the length the library reports', 194 === ( $merged[2]['duration'] ?? null ) );
	check( 'lengths are read as a person writes them', array( 194, 3723, 75, 0 ) === ( $result['seconds'] ?? null ), wp_json_encode( $result['seconds'] ?? null ) );
	check( 'and written back the same way', array( '3:14', '1:02:03', '' ) === ( $result['clock'] ?? null ), wp_json_encode( $result['clock'] ?? null ) );
}

echo PHP_EOL . '# The export says its time zone' . PHP_EOL;

$repository = new ImaginaPlayer\Leads\LeadRepository();
$rows       = $repository->export();
check( 'the CSV’s date column says it is UTC', 'captured (UTC)' === ( $rows[0][3] ?? null ), wp_json_encode( $rows[0] ?? null ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
