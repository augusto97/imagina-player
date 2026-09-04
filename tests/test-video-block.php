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

echo PHP_EOL . '# Every setting is reachable from the block' . PHP_EOL;

/*
 * The guard against the mistake this file has now watched happen twice: a
 * setting added to the schema, resolved by the renderer, tested end to end —
 * and never put in the panel, so the only way to use it was to write the
 * attribute by hand. Both times everything passed.
 *
 * The built editor bundle is the honest place to look. Reading the source would
 * pass on a control that is written but unreachable because its file is not
 * imported.
 */
$bundle = dirname( __DIR__ ) . '/build/editor.js';

if ( ! is_readable( $bundle ) ) {
	check( 'the editor bundle is built', false, $bundle );
} else {
	$editor  = (string) file_get_contents( $bundle );
	$missing = array();

	foreach ( \ImaginaPlayer\Player\Video::override_map() as $setting => $attribute ) {
		if ( ! str_contains( $editor, $attribute ) ) {
			$missing[] = $attribute . ' (' . $setting . ')';
		}
	}

	check(
		'every video setting has a control in the block',
		array() === $missing,
		implode( ', ', $missing )
	);

	// And the other direction: a setting the site can change but a block cannot
	// is a setting two videos on one page cannot differ on.
	$site_only = array();

	foreach ( array_keys( Settings::video() ) as $setting ) {
		if ( in_array( $setting, array( 'ratio', 'provider_privacy' ), true ) ) {
			// The shape is its own field; the privacy domain is a site policy.
			continue;
		}

		if ( ! array_key_exists( $setting, \ImaginaPlayer\Player\Video::override_map() ) ) {
			$site_only[] = $setting;
		}
	}

	check(
		'and every video setting a site has, a block can answer for itself',
		array() === $site_only,
		implode( ', ', $site_only )
	);
}

echo PHP_EOL . '# Every control has its own answer' . PHP_EOL;

/*
 * Presto toggles thirteen controls individually and Fluent the same shape. Here
 * half of them lived on the audio preset, which is why a video block showed a
 * mixture of the two lists with neither complete.
 */
$all_on = $renderer->render( array( 'src' => $file, 'tracks' => array( array( 'src' => 'https://cdn.example.com/es.vtt', 'label' => 'es' ) ), 'chapters' => array( array( 'start' => 0, 'title' => 'Intro' ) ) ) );

foreach ( array( 'imgp__skip' => 'skip', 'imgp__volume' => 'volume', 'imgp__time' => 'times', 'imgp__title' => 'title', 'vbtn--captions' => 'the subtitles button', 'vbtn--chapters' => 'the chapters button' ) as $needle => $what ) {
	check( "by default a video has {$what}", str_contains( $all_on, $needle ), $needle );
}

$all_off = $renderer->render(
	array(
		'src'           => $file,
		'tracks'        => array( array( 'src' => 'https://cdn.example.com/es.vtt', 'label' => 'es' ) ),
		'chapters'      => array( array( 'start' => 0, 'title' => 'Intro' ) ),
		'videoSkip'     => 'no',
		'videoVolume'   => 'no',
		'videoTime'     => 'no',
		'videoTitle'    => 'no',
		'videoCaptions' => 'no',
		'videoChapters' => 'no',
	)
);

foreach ( array( 'imgp__skip' => 'skip', 'imgp__volume' => 'volume', 'imgp__time' => 'times', 'imgp__title' => 'title', 'vbtn--captions' => 'the subtitles button', 'vbtn--chapters' => 'the chapters button' ) as $needle => $what ) {
	check( "and the block can drop {$what}", ! str_contains( $all_off, $needle ), $needle );
}

/*
 * Two conditions, and they say different things: whether there is anything to
 * show, and whether the author wants the button for it.
 */
$no_tracks = $renderer->render( array( 'src' => $file ) );

check( 'a video with no subtitles has no subtitles button', ! str_contains( $no_tracks, 'vbtn--captions' ) );

echo PHP_EOL . '# Stopping when nobody is watching, and subtitles from the start' . PHP_EOL;

$behaviour = client_config( $renderer->render( array( 'src' => $file, 'videoFocusMode' => 'yes', 'videoCaptionsOn' => 'yes' ) ) );

check( 'focus mode reaches the browser', true === ( $behaviour['video']['focus'] ?? null ) );
check( 'and subtitles on from the start', true === ( $behaviour['video']['captionsOn'] ?? null ) );

$quiet_behaviour = client_config( $renderer->render( array( 'src' => $file ) ) );

check( 'both are off unless asked for', false === ( $quiet_behaviour['video']['focus'] ?? null ) && false === ( $quiet_behaviour['video']['captionsOn'] ?? null ) );

echo PHP_EOL . '# A mark over the picture' . PHP_EOL;

$marked = $renderer->render(
	array(
		'src'               => $file,
		'watermark'         => 'https://cdn.example.com/logo.png',
		'watermarkPosition' => 'bottom-left',
		'watermarkOpacity'  => 30,
	)
);

check( 'the mark reaches the picture', str_contains( $marked, 'imgp__watermark' ) );
check( 'in the corner it was given', str_contains( $marked, 'imgp__watermark--bottom-left' ) );
check( 'at the opacity it was given', str_contains( $marked, '--imgp-mark-opacity:0.3' ) );

/*
 * The class it nearly shipped with was already the chapter marker on the scrub
 * bar, which would have made every chapter tick a full-size logo.
 */
check( 'and does not collide with the chapter markers', ! str_contains( $marked, 'class="imgp__mark ' ) );

$hostile_mark = $renderer->render( array( 'src' => $file, 'watermark' => 'javascript:alert(1)', 'watermarkPosition' => 'nowhere', 'watermarkOpacity' => 9999 ) );

check( 'an address that is not one leaves no element at all', ! str_contains( $hostile_mark, 'imgp__watermark' ) );

$odd_mark = $renderer->render( array( 'src' => $file, 'watermark' => 'https://cdn.example.com/logo.png', 'watermarkPosition' => 'nowhere', 'watermarkOpacity' => 9999 ) );

check( 'a corner that does not exist falls back', str_contains( $odd_mark, 'imgp__watermark--top-right' ) );
check( 'and an opacity out of range is clamped', str_contains( $odd_mark, '--imgp-mark-opacity:1' ) );

echo PHP_EOL . '# How the picture and its bar are painted' . PHP_EOL;

/*
 * The bar over the picture and the subtitle text were hard-coded — near-black
 * and white — so a player could carry a site's colours everywhere except the
 * two places somebody actually looks at while a video plays.
 */
$painted = $renderer->render(
	array(
		'src'               => $file,
		'videoChromeColor'  => '#1b2a4a',
		'videoCaptionColor' => '#ffe08a',
		'videoCaptionSize'  => 'xlarge',
		'videoCaptionBg'    => 'shadow',
	)
);

check( 'the control bar takes its colour from the block', str_contains( $painted, '--imgp-chrome:rgb(27 42 74' ), 'chrome colour missing' );
check( 'and keeps the alpha that lets the video through it', str_contains( $painted, '/ 78%)' ) );
check( 'the subtitles take theirs', str_contains( $painted, '--imgp-cc:#ffe08a' ) );
check( 'their size reaches the player', str_contains( $painted, 'imgp--cc-xlarge' ) );
check( 'and what sits behind them', str_contains( $painted, 'imgp--ccbg-shadow' ) );

/*
 * These reach a `style` attribute and a class name, and a block's attributes
 * are not sanitised on save the way the site's settings are.
 */
$hostile = $renderer->render(
	array(
		'src'               => $file,
		'videoChromeColor'  => 'javascript:alert(1)',
		'videoCaptionColor' => '#zzz',
		'videoCaptionSize'  => 'huge; }*/ body{display:none}',
		'videoCaptionBg'    => '"><script>',
	)
);

check( 'a colour that is not a colour falls back', str_contains( $hostile, '--imgp-chrome:rgb(0 0 0 / 78%)' ) );
check( 'and does not reach the style attribute', ! str_contains( $hostile, 'javascript:' ) );
check( 'a size that is not a size falls back', str_contains( $hostile, 'imgp--cc-medium' ) );
check( 'and nothing of it reaches the class list', ! str_contains( $hostile, 'display:none' ) && ! str_contains( $hostile, '<script>' ) );

$inherits = $renderer->render( array( 'src' => $file ) );

check( 'and a block that says nothing uses the site colour', str_contains( $inherits, '--imgp-chrome:rgb(0 0 0 / 78%)' ) );

/*
 * The controls themselves. Two colours that were fixed in the stylesheet: the
 * icons and the clock on the bar were `#fff` with no way to change them, and
 * the played part of the seek bar took `--imgp-wave-progress` — the waveform's
 * colour, an audio setting a video block does not even show. So the one thing
 * a viewer watches move could not be coloured from the block at all.
 */
echo PHP_EOL . '# Where a setting lives' . PHP_EOL;

/*
 * The inspector had grown by accretion. A panel called "Video" held a corner
 * radius, a poster, thirteen tristate dropdowns, a second group called
 * "Colours" and the subtitle sizes — while a separate "Colours" panel and a
 * separate "Subtitles" panel sat above it. Nothing was missing. It was simply
 * impossible to guess where anything was, which for a person using it is the
 * same problem.
 *
 * So the shape is checked, not just the presence of each control: panels named
 * for the question they answer, each setting in exactly one of them, and
 * nothing left loose outside a panel where it would sit above the lot.
 */
$inspector = (string) file_get_contents( dirname( __DIR__ ) . '/assets/src/editor/edit.tsx' );

preg_match_all( "/<PanelBody\s+title=\{ __\(\s*'([^']+)'/s", $inspector, $panel_matches );
$panels = $panel_matches[1];

check( 'the inspector has panels', array() !== $panels );

$expected = array(
	'Media',
	'Appearance',
	'Controls',
	'Playback',
	'Subtitles',
	'Chapters and previews',
	'Calls to action',
	'Advanced',
);

check(
	'each one is named for the question it answers',
	$expected === $panels,
	implode( ' | ', $panels )
);

/*
 * The one panel that is its own component, because it is also offered before
 * a file is chosen: it sits right after Media, where the file itself is chosen,
 * and it is named for its question too.
 */
$media_at      = strpos( $inspector, "title={ __( 'Media', 'imagina-player' ) }" );
$dynamic_at    = strpos( $inspector, '<DynamicSourcePanel', $media_at ?: 0 );
$appearance_at = strpos( $inspector, "title={ __( 'Appearance', 'imagina-player' ) }" );
$dynamic       = (string) file_get_contents( dirname( __DIR__ ) . '/assets/src/editor/dynamic-source.tsx' );

check( 'the dynamic source sits between Media and Appearance', false !== $dynamic_at && $media_at < $dynamic_at && $dynamic_at < $appearance_at );
check( 'and is named for its question', str_contains( $dynamic, "title={ __( 'Dynamic source', 'imagina-player' ) }" ) );
check( 'and is offered before a file is chosen, since a template has none to choose', 2 === substr_count( $inspector, '<DynamicSourcePanel' ) );

/*
 * Only the first is open. Eight expanded panels is the wall of settings that
 * was reported; seven closed ones is a list you can read.
 */
$open = substr_count( $inspector, 'initialOpen={ false }' );

check(
	'and all but the first are closed when the block is selected',
	count( $expected ) - 1 === $open,
	$open . ' of ' . ( count( $expected ) - 1 ) . ' closed'
);

/*
 * A setting in two panels is worse than a setting in the wrong one: the two
 * copies disagree the moment one is edited.
 */
$listed = array();

foreach ( array( 'VIDEO_CONTROLS', 'VIDEO_PLAYBACK', 'VIDEO_SUBTITLES' ) as $list ) {
	if ( ! preg_match( '/const ' . $list . ' = \[(.*?)\] as const;/s', $inspector, $m ) ) {
		check( $list . ' is defined', false );
		continue;
	}

	preg_match_all( "/'(video[A-Za-z]+)'/", $m[1], $found );

	foreach ( $found[1] as $attribute ) {
		$listed[] = $attribute;
	}
}

check(
	'no video setting is offered in two places at once',
	count( $listed ) === count( array_unique( $listed ) ),
	implode( ', ', array_diff_assoc( $listed, array_unique( $listed ) ) )
);

/*
 * And the split itself. Whether a control appears is one question; how the
 * player behaves is another, and they were in the same list of thirteen.
 */
check(
	'what the player shows is separated from how it behaves',
	in_array( 'videoBigPlay', $listed, true )
		&& in_array( 'videoFocusMode', $listed, true )
		&& ! str_contains(
			(string) ( preg_match( '/const VIDEO_CONTROLS = \[(.*?)\] as const;/s', $inspector, $m ) ? $m[1] : '' ),
			'videoFocusMode'
		)
);

/*
 * Thirteen three-way dropdowns in a column is what made the panel unreadable:
 * each row costs a click to find out what it says. The segmented control shows
 * all three answers at once, and marks the rows this block has changed.
 */
check(
	'settings that can inherit use the compact control, not a stack of dropdowns',
	str_contains( $inspector, '<TristateList' )
);

$bundle_js = (string) @file_get_contents( dirname( __DIR__ ) . '/build/editor.js' );

check(
	'and it reaches the built bundle',
	str_contains( $bundle_js, 'imgp-editor__tri' )
);

echo PHP_EOL . '# The controls on the bar' . PHP_EOL;

$controls = $renderer->render(
	array(
		'src'                => $file,
		'videoControlColor'  => '#ffe08a',
		'videoProgressColor' => '#00c2d8',
	)
);

check( 'the buttons and times take their colour from the block', str_contains( $controls, '--imgp-on-chrome:#ffe08a' ) );
check( 'the played portion takes its own', str_contains( $controls, '--imgp-progress:#00c2d8' ) );

/*
 * The rail the volume slider runs along is drawn from `--imgp-control`, which
 * on an audio player is the icon colour and defaults to a slate grey. On a
 * video that grey was a dark line on a dark bar, so it follows the control
 * colour instead.
 */
check( 'and the volume rail follows the buttons rather than the audio grey', str_contains( $controls, '--imgp-control:#ffe08a' ) );

/*
 * Left alone, both are worked out rather than assumed. This is the case that
 * matters most, because it is what every existing site gets without touching
 * anything: a control bar somebody set to a pale colour used to keep its white
 * icons and lose them.
 */
$auto_dark = $renderer->render( array( 'src' => $file ) );

check( 'left alone, a near-black bar gets white buttons', str_contains( $auto_dark, '--imgp-on-chrome:#ffffff' ) );

$auto_light = $renderer->render(
	array(
		'src'              => $file,
		'videoChromeColor' => '#f5f5f5',
	)
);

check( 'and a pale bar gets dark ones instead of invisible white', str_contains( $auto_light, '--imgp-on-chrome:#111111' ), 'white icons on a white bar' );

check(
	'the played portion falls back to the accent, not to the waveform colour',
	str_contains( $renderer->render( array( 'src' => $file, 'accent' => '#7c3aed' ) ), '--imgp-progress:#7c3aed' )
);

$hostile_controls = $renderer->render(
	array(
		'src'                => $file,
		'videoControlColor'  => 'url(javascript:alert(1))',
		'videoProgressColor' => '}*/body{display:none}',
	)
);

check( 'a control colour that is not a colour falls back to automatic', str_contains( $hostile_controls, '--imgp-on-chrome:#ffffff' ) );
check( 'and neither reaches the style attribute', ! str_contains( $hostile_controls, 'javascript:' ) && ! str_contains( $hostile_controls, 'display:none' ) );

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
