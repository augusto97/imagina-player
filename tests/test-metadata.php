<?php
/**
 * Where a track's title and artist come from.
 *
 * Most of this already happened before it was written down — the file's own
 * tags, then the library title — and none of it was configurable or visible.
 * An author saw two empty fields and no reason to believe anything would fill
 * them, which from the outside is the same as the feature not existing.
 *
 * The filename parsing gets the most attention here because it is the part
 * that will be silently wrong: a rule that looks right on `my-track.mp3` and
 * mangles `La historia de un quiste` is worse than no rule.
 */

$plugin = dirname( __DIR__ ) . '/';

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

require $plugin . 'tests/bootstrap.php';

use ImaginaPlayer\Media\Track;
use ImaginaPlayer\Settings;

echo PHP_EOL . '# A readable title out of a file name' . PHP_EOL;

$names = array(
	'https://example.test/audio/mi-conferencia.mp3'            => 'Mi conferencia',
	'https://example.test/audio/mi_conferencia_01.mp3'         => 'Mi conferencia 01',
	// A leading date is filing, not a title.
	'https://example.test/2024-03-11_mi-conferencia.mp3'       => 'Mi conferencia',
	'https://example.test/20240311-mi-conferencia.mp3'         => 'Mi conferencia',
	'https://example.test/2024.03.11 mi conferencia.mp3'       => 'Mi conferencia',
	// Existing capitals are somebody's decision; leave them alone.
	'https://example.test/La-Historia-de-un-Quiste.mp3'        => 'La Historia de un Quiste',
	'https://example.test/BBC-entrevista.mp3'                  => 'BBC entrevista',
	// Percent-encoding is how accents survive a URL.
	'https://example.test/audio/conferencia-espa%C3%B1ola.mp3' => 'Conferencia española',
	// A query string is not part of the name.
	'https://example.test/audio/ep-12.mp3?token=abc&expires=1' => 'Ep 12',
	'https://example.test/audio/multiple---dashes.mp3'         => 'Multiple dashes',
	'https://example.test/audio/.mp3'                          => '',
);

foreach ( $names as $url => $expected ) {
	check(
		sprintf( '%s → %s', basename( (string) strtok( $url, '?' ) ), '' === $expected ? '(nothing)' : $expected ),
		$expected === Track::title_from_filename( $url ),
		Track::title_from_filename( $url )
	);
}

check(
	'a Spanish title is not word-capitalised',
	'La historia de un quiste' === Track::title_from_filename( 'https://x.test/la-historia-de-un-quiste.mp3' ),
	'title case turns Spanish into something that looks deliberate and is wrong: '
		. Track::title_from_filename( 'https://x.test/la-historia-de-un-quiste.mp3' )
);

echo PHP_EOL . '# What the block says always wins' . PHP_EOL;

$named = Track::from_attributes(
	array(
		'src'   => 'https://example.test/audio/mi-conferencia.mp3',
		'title' => 'Un título escrito a mano',
	)
);

check( 'a typed title is kept', 'Un título escrito a mano' === $named->title, $named->title );

$unnamed = Track::from_attributes(
	array( 'src' => 'https://example.test/audio/mi-conferencia.mp3' )
);

check(
	'and an empty one falls back to the file name',
	'Mi conferencia' === $unnamed->title,
	$unnamed->title
);

echo PHP_EOL . '# An external track is the case that most needs this' . PHP_EOL;

check(
	'a pasted address gets a name',
	'' !== Track::from_attributes(
		array( 'src' => 'https://cdn.example.com/podcast/episodio-12.mp3' )
	)->title,
	'it has no attachment to ask and no tags to read, so the file name is all there is'
);

echo PHP_EOL . '# The setting is obeyed' . PHP_EOL;

/** Render a track with one metadata setting changed. */
function with_metadata( array $patch, array $atts = array() ): Track {
	$settings             = Settings::defaults();
	$settings['metadata'] = array_replace( $settings['metadata'], $patch );

	update_option( Settings::OPTION_KEY, $settings );
	Settings::flush_cache();

	return Track::from_attributes(
		array_merge( array( 'src' => 'https://example.test/audio/mi-conferencia.mp3' ), $atts )
	);
}

check(
	'turning the file name off leaves the title empty',
	'' === with_metadata( array( 'from_filename' => false ) )->title,
	with_metadata( array( 'from_filename' => false ) )->title
);
check(
	'and turning titles off entirely does too',
	'' === with_metadata( array( 'title_from' => 'none' ) )->title
);
check(
	'pinning titles to tags alone skips the file name',
	'' === with_metadata( array( 'title_from' => 'tags' ) )->title,
	'a file with no tags should stay nameless when that is what was asked for'
);
check(
	'while the default finds one',
	'' !== with_metadata( array() )->title
);

// Back to the defaults for anything that runs after this.
update_option( Settings::OPTION_KEY, Settings::defaults() );
Settings::flush_cache();

echo PHP_EOL . '# Tags and cover art from a library file' . PHP_EOL;

$GLOBALS['stub_posts'] = array(
	7 => array(
		'type' => 'attachment',
		'mime' => 'audio/mpeg',
		'url'  => 'https://example.test/wp-content/uploads/2024/03/grabacion.mp3',
	),
);
$GLOBALS['stub_meta']   = array( 7 => array() );
$GLOBALS['stub_covers'] = array( 7 => 'https://example.test/wp-content/uploads/2024/03/portada.jpg' );

// What wp_read_audio_metadata() leaves behind after an upload.
$GLOBALS['stub_attachment_meta'] = array(
	7 => array(
		'length'       => 4623.0,
		'title'        => 'La historia de un quiste',
		'album_artist' => 'Imagina',
	),
);

$library = Track::from_attributes( array( 'attachmentId' => 7 ) );

check( 'the tag title is used', 'La historia de un quiste' === $library->title, $library->title );
check(
	'album_artist counts as an artist',
	'Imagina' === $library->artist,
	'for a lecture series that is the field people actually fill in: ' . $library->artist
);
check( 'the duration comes across', 4623.0 === $library->duration, (string) $library->duration );
check(
	'the cover art inside the file becomes the thumbnail',
	str_contains( $library->thumbnail, 'portada.jpg' ),
	$library->thumbnail
);

$settings             = Settings::defaults();
$settings['metadata'] = array_replace( $settings['metadata'], array( 'use_cover' => false ) );
update_option( Settings::OPTION_KEY, $settings );
Settings::flush_cache();

check(
	'and turning that off leaves it alone',
	'' === Track::from_attributes( array( 'attachmentId' => 7 ) )->thumbnail
);

$settings['metadata'] = array_replace( $settings['metadata'], array( 'use_cover' => true, 'artist_from' => 'none' ) );
update_option( Settings::OPTION_KEY, $settings );
Settings::flush_cache();

check(
	'turning the artist off leaves it empty',
	'' === Track::from_attributes( array( 'attachmentId' => 7 ) )->artist
);

update_option( Settings::OPTION_KEY, Settings::defaults() );
Settings::flush_cache();
$GLOBALS['stub_posts'] = array();

echo PHP_EOL . '# The editor stops showing a blank box' . PHP_EOL;

$editor = (string) file_get_contents( $plugin . 'assets/src/editor/edit.tsx' );

check(
	'the title field offers what the server resolved',
	str_contains( $editor, 'placeholder={ resolved.title }' ),
	'an empty box beside a filled-in player gives no reason to think anything is happening'
);
check( 'and so does the artist field', str_contains( $editor, 'placeholder={ resolved.artist }' ) );
check(
	'the help text names it rather than describing it',
	str_contains( $editor, 'Empty shows' )
);

$preview = (string) file_get_contents( $plugin . 'assets/src/editor/preview.tsx' );

check( 'the preview passes it up', str_contains( $preview, 'onResolved' ) );

$controller = (string) file_get_contents( $plugin . 'src/Rest/SettingsController.php' );

check( 'and the server sends it', str_contains( $controller, "'resolved'" ) );

echo PHP_EOL . '# There is somewhere to change it' . PHP_EOL;

$admin = (string) file_get_contents( $plugin . 'build/admin.js' );

check( 'a Track details section exists', str_contains( $admin, 'Track details' ) );

foreach ( array( 'title_from', 'artist_from', 'from_filename', 'use_cover' ) as $key ) {
	check( "the {$key} control is in the built bundle", str_contains( $admin, $key ) );
}

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "{$failures} check(s) failed." . PHP_EOL;
	exit( 1 );
}
echo 'All checks passed.' . PHP_EOL;
