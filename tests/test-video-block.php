<?php
/**
 * The video half of the block, and whether any of it does anything.
 *
 * The report was blunt and correct: the controls in the editor were mostly
 * audio controls, the Video panel had two fields in it, and autoplay and start
 * muted did nothing. Three separate faults with one shape — a switch that
 * exists and reaches nothing — so what is checked here is reach, from the
 * block's attributes through to the markup and the config the browser gets.
 *
 * The structural reason for the second one is worth stating, because it is not
 * an oversight that could be fixed by adding fields: per-block overrides were
 * driven by a map from *preset* keys to attributes, and the video settings are
 * not in a preset. There was no path from a block to them at all.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Player\Attributes;
use ImaginaPlayer\Player\Video;
use ImaginaPlayer\Render\PlayerRenderer;
use ImaginaPlayer\Settings;

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

/** The client config the browser is handed, decoded. */
function client_config( string $html ): array {
	if ( ! preg_match( '/data-imagina-player="([^"]*)"/', $html, $m ) ) {
		return array();
	}

	return (array) json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
}

$renderer = new PlayerRenderer();
$file     = 'https://cdn.example.com/clip.mp4';
$youtube  = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

echo PHP_EOL . '# A block can answer for its own video' . PHP_EOL;

$defaults = $renderer->render( array( 'src' => $file ) );

check( 'by default the play button over the picture is there', str_contains( $defaults, 'imgp__bigplay' ) );
check( 'and the fullscreen button', str_contains( $defaults, 'vbtn--fullscreen' ) );
check( 'and picture-in-picture', str_contains( $defaults, 'vbtn--pip' ) );
check( 'and the browser download is blocked', str_contains( $defaults, 'controlslist' ) );

/*
 * Each one turned off on the block alone. Before this the only place these
 * existed was the site-wide settings screen, so two videos in one post could
 * not behave differently from each other.
 */
$off = $renderer->render(
	array(
		'src'                => $file,
		'videoBigPlay'       => 'no',
		'videoFullscreen'    => 'no',
		'videoPip'           => 'no',
		'videoSpeed'         => 'no',
		'videoBlockDownload' => 'no',
	)
);

check( 'the block can drop the play button over the picture', ! str_contains( $off, 'imgp__bigplay' ) );
check( 'and the fullscreen button', ! str_contains( $off, 'vbtn--fullscreen' ) );
check( 'and picture-in-picture', ! str_contains( $off, 'vbtn--pip' ) );
check( 'and can allow the browser download again', ! str_contains( $off, 'controlslist' ) );

$fit = $renderer->render(
	array(
		'src'            => $file,
		'poster'         => 'https://cdn.example.com/still.jpg',
		'videoPosterFit' => 'contain',
	)
);

check( 'and how the poster fills its box', str_contains( $fit, 'imgp__poster--contain' ), 'poster fit not applied' );

$hide = client_config( $renderer->render( array( 'src' => $file, 'videoHideAfter' => '0' ) ) );

check(
	'and how long before the controls fade',
	0 === ( $hide['video']['hideAfter'] ?? -1 ),
	wp_json_encode( $hide['video']['hideAfter'] ?? null )
);

echo PHP_EOL . '# And saying nothing still means the site setting' . PHP_EOL;

/*
 * The reason these are three-way rather than switches: a switch would freeze
 * whatever the site says today into every block, so changing the site later
 * would leave every existing post behind.
 */
$inherited = Video::resolve( Attributes::sanitize( array( 'src' => $file ) ) );
$site      = Settings::video();

$drift = array();

foreach ( Video::override_map() as $key => $attribute ) {
	if ( ( $inherited[ $key ] ?? null ) !== ( $site[ $key ] ?? null ) ) {
		$drift[] = $key;
	}
}

check( 'an unset block matches the site settings exactly', array() === $drift, implode( ', ', $drift ) );

echo PHP_EOL . '# A skin belongs to a medium' . PHP_EOL;

/*
 * All seven of the original skins arrange a waveform, a row of transport
 * buttons and a title beside them. A video block offered them anyway, so
 * choosing one either did nothing visible or did something meaningless — a
 * "card with cover" on a video that already has a poster, a "waveform,
 * mirrored" on a picture with no waveform.
 */
$audio_only = array_diff( array_keys( \ImaginaPlayer\Player\Skins::all() ), array_keys( \ImaginaPlayer\Player\Skins::video() ) );

check( 'there are video skins at all', count( \ImaginaPlayer\Player\Skins::video() ) >= 3 );
check( 'and audio skins a video does not share', count( $audio_only ) >= 5, implode( ',', $audio_only ) );

foreach ( array( 'theater', 'minimal', 'stacked' ) as $skin ) {
	$html = $renderer->render( array( 'src' => $file, 'skin' => $skin ) );

	check(
		"a video keeps its own skin: {$skin}",
		str_contains( $html, 'imgp--skin-' . $skin ),
		$skin
	);
}

/*
 * And an audio skin on a video falls back rather than rendering something
 * meaningless — which is what happens when an author replaces an audio file
 * with a video and the block keeps the skin it was saved with.
 */
foreach ( $audio_only as $skin ) {
	$html = $renderer->render( array( 'src' => $file, 'skin' => $skin ) );

	check(
		"an audio skin on a video falls back: {$skin}",
		str_contains( $html, 'imgp--skin-theater' ),
		$skin
	);
}

foreach ( array( 'theater', 'stacked' ) as $skin ) {
	$html = $renderer->render( array( 'src' => 'https://cdn.example.com/track.mp3', 'skin' => $skin ) );

	check(
		"and a video skin on audio falls back: {$skin}",
		str_contains( $html, 'imgp--skin-wave' ),
		$skin
	);
}

/*
 * The stacked skin is the one that is a difference in markup rather than in
 * paint: the stage crops to the video's shape, so a bar inside it can only ever
 * be over the picture.
 */
$stacked = $renderer->render( array( 'src' => $file, 'skin' => 'stacked' ) );
$theater = $renderer->render( array( 'src' => $file, 'skin' => 'theater' ) );

check(
	'the stacked skin puts its bar outside the picture',
	strpos( $stacked, 'imgp__chrome' ) > strpos( $stacked, '</div>', strpos( $stacked, 'imgp__stage' ) )
);

check(
	'and the theater skin keeps it on the picture',
	strpos( $theater, 'imgp__chrome' ) < strpos( $theater, '</div>', strrpos( $theater, 'imgp__stage' ) ) + 400
);

echo PHP_EOL . '# Autoplay, muted and loop on a video nobody here is serving' . PHP_EOL;

/*
 * The fault as reported. These are printed by the renderer as attributes on an
 * `<audio>` or `<video>` element, and a provider video has neither — so on a
 * YouTube video all three were switches wired to nothing, with no sign of it.
 */
$provider = client_config(
	$renderer->render(
		array(
			'src'      => $youtube,
			'autoplay' => true,
			'muted'    => true,
			'loop'     => true,
		)
	)
);

check( 'autoplay reaches a YouTube video', true === ( $provider['video']['autoplay'] ?? null ) );
check( 'and start muted', true === ( $provider['video']['muted'] ?? null ) );
check( 'and loop', true === ( $provider['video']['loop'] ?? null ) );

$quiet = client_config( $renderer->render( array( 'src' => $youtube ) ) );

check( 'and a block that asked for none of them says so', false === ( $quiet['video']['autoplay'] ?? null ) );

// Still on the element for a file, which is where they belong when there is one.
$element = $renderer->render( array( 'src' => $file, 'autoplay' => true, 'muted' => true, 'loop' => true ) );

check( 'a self-hosted video still carries them as attributes', str_contains( $element, 'autoplay' ) && str_contains( $element, 'muted' ) && str_contains( $element, 'loop' ) );

echo PHP_EOL . '# Audio settings stay out of the video block' . PHP_EOL;

$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
$root = dirname( __DIR__ );

if ( '' === $node ) {
	echo 'SKIP  no node; the editor\'s own filtering was not checked' . PHP_EOL;
} else {
	shell_exec(
		sprintf(
			'cd %s && npx tsc assets/src/shared/source.ts --outDir build/.video-check --module es2020 --target es2020 --moduleResolution bundler 2>&1',
			escapeshellarg( $root )
		)
	);

	$compiled = $root . '/build/.video-check/source.js';

	if ( ! file_exists( $compiled ) ) {
		echo 'SKIP  the editor copy would not compile' . PHP_EOL;
	} else {
		$script = $root . '/build/.video-check.mjs';

		file_put_contents(
			$script,
			"import { controlApplies, colourApplies } from '" . $compiled . "';\n"
			. "const controls = ['show_title','show_artist','show_thumbnail','show_volume','show_time','show_download','show_speed','show_skip','sticky','remember_position'];\n"
			. "const colours = ['accent','waveColor','waveProgress','textColor','metaColor'];\n"
			. "const out = { video: { controls: [], colours: [] }, audio: { controls: [], colours: [] } };\n"
			. "for (const c of controls) { if (controlApplies(c, true)) out.video.controls.push(c); if (controlApplies(c, false)) out.audio.controls.push(c); }\n"
			. "for (const c of colours) { if (colourApplies(c, true)) out.video.colours.push(c); if (colourApplies(c, false)) out.audio.colours.push(c); }\n"
			. "console.log(JSON.stringify(out));\n"
		);

		$raw    = (string) shell_exec( 'node ' . escapeshellarg( $script ) . ' 2>/dev/null' );
		$parsed = json_decode( trim( $raw ), true );

		exec( 'rm -rf ' . escapeshellarg( $root . '/build/.video-check' ) . ' ' . escapeshellarg( $script ) );

		if ( ! is_array( $parsed ) ) {
			check( 'the editor filtering ran', false, trim( $raw ) );
		} else {
			$video = (array) $parsed['video'];
			$audio = (array) $parsed['audio'];

			/*
			 * A video's still is the poster, which has its own field; the
			 * thumbnail is never rendered in the video layout. Offering the
			 * switch was a promise the player did not keep.
			 */
			check( 'a video block is not offered a thumbnail switch', ! in_array( 'show_thumbnail', $video['controls'], true ) );
			check( 'nor a waveform colour', ! in_array( 'waveColor', $video['colours'], true ) );
			check( 'nor a played-portion colour', ! in_array( 'waveProgress', $video['colours'], true ) );

			// And nothing was taken away that a video does use.
			check( 'a video block keeps the volume switch', in_array( 'show_volume', $video['controls'], true ) );
			check( 'and the times', in_array( 'show_time', $video['controls'], true ) );
			check( 'and the accent colour', in_array( 'accent', $video['colours'], true ) );

			// The audio block is untouched by any of this.
			check( 'an audio block still gets every control', count( $audio['controls'] ) === 10, (string) count( $audio['controls'] ) );
			check( 'and every colour', count( $audio['colours'] ) === 5, (string) count( $audio['colours'] ) );
		}
	}
}

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
