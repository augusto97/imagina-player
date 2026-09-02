<?php
/**
 * Every video setting, proved to reach the player.
 *
 * This file exists because of a specific failure, and it is worth naming: the
 * video player was built and tested, and then shipped with no block in the
 * inserter and no settings anywhere. It worked and could not be found or
 * changed, which from the outside is indistinguishable from not being finished.
 *
 * So each check here changes one setting and asserts the rendered output
 * changes with it. A control that does nothing fails.
 */

$plugin = dirname( __DIR__ ) . '/';

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

require $plugin . 'tests/bootstrap.php';

use ImaginaPlayer\Render\PlayerRenderer;
use ImaginaPlayer\Settings;

const VIDEO_SRC = 'https://example.test/wp-content/uploads/clase.mp4';

/**
 * Render a video with one video setting changed.
 *
 * @param array<string, mixed> $video Video settings to override.
 * @param array<string, mixed> $atts  Extra block attributes.
 */
function render_with( array $video = array(), array $atts = array() ): string {
	$settings          = Settings::defaults();
	$settings['video'] = array_replace( $settings['video'], $video );

	update_option( Settings::OPTION_KEY, $settings );
	Settings::flush_cache();

	$renderer = new PlayerRenderer();

	return $renderer->render( array_merge( array( 'src' => VIDEO_SRC ), $atts ) );
}

echo PHP_EOL . '# The shape of the picture' . PHP_EOL;

check(
	'the site default ratio is used when the block sets none',
	str_contains( render_with( array( 'ratio' => '4:3' ) ), '--imgp-ratio:4 / 3' ),
	'a site that works in one shape should not have to set it on every block'
);
check(
	'a vertical default reaches the page',
	str_contains( render_with( array( 'ratio' => '9:16' ) ), '--imgp-ratio:9 / 16' )
);
check(
	'and the block still wins when it says so',
	str_contains( render_with( array( 'ratio' => '4:3' ), array( 'aspectRatio' => '1:1' ) ), '--imgp-ratio:1 / 1' )
);

echo PHP_EOL . '# The poster' . PHP_EOL;

$with_poster = array( 'poster' => 'https://example.test/p.jpg' );

check(
	'crop is the default',
	str_contains( render_with( array(), $with_poster ), 'imgp__poster--cover' )
);
check(
	'and fit can be chosen',
	str_contains( render_with( array( 'poster_fit' => 'contain' ), $with_poster ), 'imgp__poster--contain' )
);

echo PHP_EOL . '# The controls' . PHP_EOL;

check( 'full screen is on by default', str_contains( render_with(), 'imgp__vbtn--fullscreen' ) );
check(
	'and can be turned off',
	! str_contains( render_with( array( 'show_fullscreen' => false ) ), 'imgp__vbtn--fullscreen' ),
	'a toggle that leaves the button there is a decoration'
);

check( 'picture in picture is on by default', str_contains( render_with(), 'imgp__vbtn--pip' ) );
check( 'and can be turned off', ! str_contains( render_with( array( 'show_pip' => false ) ), 'imgp__vbtn--pip' ) );

check( 'the big play button is on by default', str_contains( render_with(), 'imgp__bigplay' ) );
check( 'and can be turned off', ! str_contains( render_with( array( 'big_play' => false ) ), 'imgp__bigplay' ) );

$slow = render_with( array( 'hide_after' => 9000 ) );

check( 'the hide delay reaches the client', str_contains( $slow, '9000' ), $slow );
check(
	'and zero survives as zero rather than being treated as unset',
	str_contains( render_with( array( 'hide_after' => 0 ) ), '&quot;hideAfter&quot;:0' ),
	'zero is a real answer here: never hide them'
);

echo PHP_EOL . '# Subtitles' . PHP_EOL;

check( 'medium is the default', str_contains( render_with(), 'imgp--cc-medium' ) );
check( 'large reaches the markup', str_contains( render_with( array( 'caption_size' => 'large' ) ), 'imgp--cc-large' ) );
check( 'so does a shadow backing', str_contains( render_with( array( 'caption_bg' => 'shadow' ) ), 'imgp--ccbg-shadow' ) );
check( 'and no backing at all', str_contains( render_with( array( 'caption_bg' => 'none' ) ), 'imgp--ccbg-none' ) );

// The stylesheet has to answer those classes, or they are markup that does
// nothing — which is the failure this whole file is about.
$css = (string) file_get_contents( $plugin . 'build/style-frontend.css' );

foreach ( array( 'imgp--cc-small', 'imgp--cc-large', 'imgp--ccbg-shadow', 'imgp--ccbg-none', 'imgp__poster--contain' ) as $class ) {
	check( "the stylesheet answers .{$class}", str_contains( $css, $class ), 'a class nothing styles is a setting that does nothing' );
}

echo PHP_EOL . '# Keeping the file' . PHP_EOL;

check( 'the download guards are on by default', str_contains( render_with(), 'controlslist="nodownload' ) );
check(
	'and can be turned off',
	! str_contains( render_with( array( 'block_download' => false ) ), 'controlslist' )
);
check(
	'offering a download still overrides them',
	! str_contains( render_with( array(), array( 'showDownload' => 'yes' ) ), 'controlslist' ),
	'hiding the browser download beside our own would be theatre'
);

echo PHP_EOL . '# A settings round trip does not lose anything' . PHP_EOL;

$controller = new ImaginaPlayer\Rest\SettingsController();

update_option( Settings::OPTION_KEY, Settings::defaults() );
Settings::flush_cache();

$controller->update_settings(
	new WP_REST_Request(
		array(
			'video' => array(
				'ratio'           => '9:16',
				'hide_after'      => 0,
				'show_pip'        => false,
				'show_fullscreen' => true,
				'show_speed'      => false,
				'big_play'        => false,
				'block_download'  => false,
				'poster_fit'      => 'contain',
				'caption_size'    => 'large',
				'caption_bg'      => 'none',
			),
		)
	)
);

$saved = Settings::video();

check( 'the ratio is stored', '9:16' === $saved['ratio'], $saved['ratio'] );
check( 'a zero delay is stored as zero', 0 === $saved['hide_after'], var_export( $saved['hide_after'], true ) );
check( 'the toggles that were turned off stay off', false === $saved['show_pip'] && false === $saved['big_play'] );
check( 'the one left on stays on', true === $saved['show_fullscreen'] );
check( 'the poster fit is stored', 'contain' === $saved['poster_fit'] );
check( 'the caption choices are stored', 'large' === $saved['caption_size'] && 'none' === $saved['caption_bg'] );

// And the values that are not in the allowed set do not get through.
$controller->update_settings(
	new WP_REST_Request(
		array(
			'video' => array(
				'ratio'        => '1:900',
				'caption_size' => 'enormous',
				'caption_bg'   => '<script>',
				'poster_fit'   => 'stretch',
				'hide_after'   => 999999,
			),
		)
	)
);

$guarded = Settings::video();

check( 'an absurd ratio falls back', '16:9' === $guarded['ratio'], $guarded['ratio'] );
check( 'an unknown caption size falls back', 'medium' === $guarded['caption_size'], $guarded['caption_size'] );
check( 'so does an unknown backing', 'solid' === $guarded['caption_bg'], $guarded['caption_bg'] );
check( 'and an unknown poster fit', 'cover' === $guarded['poster_fit'], $guarded['poster_fit'] );
check( 'a huge delay is clamped', 20000 === $guarded['hide_after'], (string) $guarded['hide_after'] );

echo PHP_EOL . '# The block exists where somebody would look for it' . PHP_EOL;

$block = json_decode( (string) file_get_contents( $plugin . 'blocks/video/block.json' ), true );

check( 'there is a video block', is_array( $block ) );
check( 'named as one', 'imagina/video-player' === ( $block['name'] ?? '' ), (string) ( $block['name'] ?? '' ) );
check( 'titled as one', str_contains( (string) ( $block['title'] ?? '' ), 'Video' ), (string) ( $block['title'] ?? '' ) );
check( 'with a video icon', 'format-video' === ( $block['icon'] ?? '' ) );
check(
	'and findable by searching for what it is',
	in_array( 'video', $block['keywords'] ?? array(), true ) && in_array( 'hls', $block['keywords'] ?? array(), true ),
	implode( ' / ', $block['keywords'] ?? array() )
);

$registrar = (string) file_get_contents( $plugin . 'src/Blocks/BlockRegistrar.php' );

check( 'and it is actually registered', str_contains( $registrar, "blocks/video" ) );

$editor = (string) file_get_contents( $plugin . 'build/editor.js' );

check( 'the editor registers it too', str_contains( $editor, 'imagina/video-player' ), 'registered on one side only means an invalid block in the editor' );
check(
	'and the video panels key off the block, not a guess at the file name',
	str_contains( $editor, 'imagina/video-player' ) && str_contains( (string) file_get_contents( $plugin . 'assets/src/editor/edit.tsx' ), "'imagina/video-player' === name" )
);

echo PHP_EOL . '# The settings screen has somewhere to put all this' . PHP_EOL;

$admin = (string) file_get_contents( $plugin . 'build/admin.js' );

check( 'there is a Video section', str_contains( $admin, "id: 'video'" ) || str_contains( $admin, 'id:"video"' ), 'settings nobody can reach are not settings' );

foreach ( array( 'poster_fit', 'caption_size', 'block_download', 'hide_after' ) as $key ) {
	check( "the {$key} control is in the built bundle", str_contains( $admin, $key ) );
}

echo PHP_EOL . '# And a way to look at a video before publishing one' . PHP_EOL;

/*
 * The preset editor has had a live preview since the first version. The video
 * settings had none, and the preset preview only ever drew audio — so a
 * preset's accent on a play button over a picture, its corner radius on the
 * picture, its button colour on the bar, and every setting in the Video section
 * could only be seen by publishing a post and looking at the front end.
 */
$controller = new ImaginaPlayer\Rest\SettingsController();

$video_preview = $controller->preview(
	new WP_REST_Request(
		array(
			'medium' => 'video',
			'preset' => array( 'accent' => '#00c2d8' ),
		)
	)
);

$video_data = $video_preview->get_data();
$video_html = (string) ( $video_data['html'] ?? '' );

check( 'the preview renders a video', str_contains( $video_html, 'imgp--video' ), substr( $video_html, 0, 120 ) );
check( 'with a stage to hold the picture', str_contains( $video_html, 'imgp__stage' ) );
check( 'and a poster, so it is not a black rectangle', str_contains( $video_html, 'preview-poster.svg' ) );
check( 'the preset reaches it', str_contains( $video_html, '#00c2d8' ) );

check(
	'the poster it points at is actually in the plugin',
	is_readable( $plugin . 'assets/preview-poster.svg' )
);

/*
 * Unsaved settings, which is the whole point of a preview: seeing a change
 * before committing to it.
 */
$candidate = $controller->preview(
	new WP_REST_Request(
		array(
			'medium' => 'video',
			'preset' => array(),
			'video'  => array( 'chrome_color' => '#123456' ),
		)
	)
);

check(
	'a setting that has not been saved yet shows in the preview',
	str_contains( (string) ( $candidate->get_data()['html'] ?? '' ), 'rgb(18 52 86' ),
	'otherwise every change is a guess followed by a save'
);

/*
 * And the filter has to come back off. It is added at priority 99 for one
 * render; left on, every player on the request would take the preview's
 * settings.
 */
$after = $controller->preview(
	new WP_REST_Request( array( 'medium' => 'video', 'preset' => array() ) )
);

check(
	'and stops applying once that render is done',
	! str_contains( (string) ( $after->get_data()['html'] ?? '' ), 'rgb(18 52 86' ),
	'the override outlived the render it was for'
);

check(
	'the audio preview is unchanged',
	str_contains(
		(string) ( $controller->preview( new WP_REST_Request( array( 'preset' => array() ) ) )->get_data()['html'] ?? '' ),
		'imgp--audio'
	)
);

$admin_bundle = (string) file_get_contents( $plugin . 'build/admin.js' );

check(
	'the settings screen can switch the preview between the two',
	str_contains( $admin_bundle, 'imgpa-preview__medium' )
);

echo PHP_EOL . '# A request that names one setting in a group leaves the rest of the group alone' . PHP_EOL;

/*
 * Found on a real WordPress, not in a stub: a request carrying only
 * `peaks.ffmpeg_path` switched off server generation and the browser fallback,
 * and one carrying only `video.provider_bare` switched off privacy mode and
 * every control. The settings screen sends whole groups, so it never saw it —
 * anything else talking to the endpoint did.
 */
update_option( Settings::OPTION_KEY, Settings::defaults() );
Settings::flush_cache();

$controller->update_settings(
	new WP_REST_Request(
		array(
			'video'      => array( 'provider_bare' => false ),
			'peaks'      => array( 'ffmpeg_path' => '/usr/local/bin/ffmpeg' ),
			'metadata'   => array( 'title_from' => 'file' ),
			'advanced'   => array( 'custom_css' => '.x{}' ),
			'branding'   => array( 'logo_height' => 30 ),
			'protection' => array( 'ttl' => 2 * HOUR_IN_SECONDS ),
		)
	)
);

$all = Settings::all();

check( 'the named video switch changed', false === $all['video']['provider_bare'] );
check( 'the unnamed video switches kept their value', true === $all['video']['provider_privacy'] && true === $all['video']['show_pip'] && true === $all['video']['show_captions'] );
check( 'the unnamed video numbers kept their value', 2600 === $all['video']['hide_after'] );
check( 'the named waveform path changed', '/usr/local/bin/ffmpeg' === $all['peaks']['ffmpeg_path'], $all['peaks']['ffmpeg_path'] );
check( 'the unnamed waveform switches kept their value', true === $all['peaks']['server_generation'] && true === $all['peaks']['client_fallback'] );
check( 'the unnamed size limit kept its value', 25 * MB_IN_BYTES === $all['peaks']['max_client_bytes'], (string) $all['peaks']['max_client_bytes'] );
check( 'the named metadata choice changed', 'file' === $all['metadata']['title_from'] );
check( 'the unnamed metadata switches kept their value', true === $all['metadata']['use_cover'] && true === $all['metadata']['from_filename'] );
check( 'the named stylesheet changed', '.x{}' === $all['advanced']['custom_css'] );
check( 'the unnamed advanced switches kept their value', true === $all['advanced']['load_frontend_css'] && true === $all['advanced']['lazy_init'] );
check( 'the named branding number changed', 30 === $all['branding']['logo_height'] );
check( 'the unnamed branding colours kept their value', Settings::defaults()['branding']['accent'] === $all['branding']['accent'] );
check( 'the named protection number changed', 2 * HOUR_IN_SECONDS === $all['protection']['ttl'] );
check( 'the unnamed protection delivery kept its value', 'php' === $all['protection']['delivery'] && '/imagina-protected/' === $all['protection']['xaccel_prefix'] );

// And a whole group, sent the way the settings screen sends it, still turns
// things off: "not mentioned" is not the same as "sent as false".
$controller->update_settings(
	new WP_REST_Request(
		array(
			'peaks' => array(
				'resolution'        => 400,
				'server_generation' => false,
				'client_fallback'   => false,
				'ffmpeg_path'       => '',
				'max_client_mb'     => 40,
			),
		)
	)
);

$all = Settings::all();

check( 'a switch sent as false is off', false === $all['peaks']['server_generation'] && false === $all['peaks']['client_fallback'] );
check( 'a size sent in megabytes is stored in bytes', 40 * MB_IN_BYTES === $all['peaks']['max_client_bytes'], (string) $all['peaks']['max_client_bytes'] );

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "{$failures} check(s) failed." . PHP_EOL;
	exit( 1 );
}
echo 'All checks passed.' . PHP_EOL;
