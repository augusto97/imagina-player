<?php
/**
 * The three players as Elementor widgets.
 *
 * Elementor keeps a widget's settings in its own shape, and the renderer takes
 * the block's attributes; every choice a widget offers has to arrive at the
 * renderer as the attribute it stands for. That mapping is the whole of what
 * can go wrong here, and it is checked choice by choice, with Elementor's
 * base classes stood in for so the widgets can be built without it.
 *
 * On a real Elementor 4.4 the same widgets registered, rendered on a page and
 * enqueued the front end; that run is in the changelog, and this is what
 * keeps it true.
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/elementor-stubs.php';

use ImaginaPlayer\Integrations\Elementor\AudioWidget;
use ImaginaPlayer\Integrations\Elementor\Integration;
use ImaginaPlayer\Integrations\Elementor\PlaylistWidget;
use ImaginaPlayer\Integrations\Elementor\VideoWidget;
use ImaginaPlayer\Player\Attributes;

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

$GLOBALS['stub_posts'] = array(
	77 => array( 'type' => 'attachment', 'mime' => 'audio/mpeg', 'url' => 'https://example.test/uploads/track.mp3', 'title' => 'Track' ),
	78 => array( 'type' => 'attachment', 'mime' => 'image/jpeg', 'url' => 'https://example.test/uploads/cover.jpg', 'title' => 'Cover' ),
	79 => array( 'type' => 'attachment', 'mime' => 'video/mp4', 'url' => 'https://example.test/uploads/clip.mp4', 'title' => 'Clip' ),
);

echo '# Registration' . PHP_EOL;

$integration = new Integration();
$widgets     = new Elementor\Widgets_Manager();
$elements    = new Elementor\Elements_Manager();

define( 'ELEMENTOR_VERSION', '3.5.0' );

$integration->register_category( $elements );
$integration->register_widgets( $widgets );

check( 'a category of its own', isset( $elements->categories['imagina-player'] ) && '' !== ( $elements->categories['imagina-player']['title'] ?? '' ) );
check( 'three widgets', array( 'imagina-audio-player', 'imagina-video-player', 'imagina-playlist' ) === array_keys( $widgets->registered ), implode( ',', array_keys( $widgets->registered ) ) );

foreach ( $widgets->registered as $name => $widget ) {
	check( "{$name} sits in that category and needs the front-end bundle", array( 'imagina-player' ) === $widget->get_categories() && array( 'imagina-player' ) === $widget->get_script_depends() && array( 'imagina-player' ) === $widget->get_style_depends() );
}

echo PHP_EOL . '# The panel each one shows' . PHP_EOL;

$audio_controls = $widgets->registered['imagina-audio-player']->get_controls();
$video_controls = $widgets->registered['imagina-video-player']->get_controls();
$list_controls  = $widgets->registered['imagina-playlist']->get_controls();

check( 'the audio widget offers the library, an address, or a custom field', array( 'library', 'url', 'field' ) === array_keys( $audio_controls['source']['options'] ?? array() ) );
check( 'its library picker offers audio and video files', array( 'audio', 'video' ) === ( $audio_controls['media']['media_types'] ?? array() ) );
check( 'the address takes a dynamic tag', true === ( $audio_controls['url']['dynamic']['active'] ?? false ) );
check( 'and so does the title', true === ( $audio_controls['title']['dynamic']['active'] ?? false ) );
check( 'every audio switch is a three-way choice', array() === array_diff( array_keys( AudioWidget::SWITCHES ), array_keys( $audio_controls ) ) && array( '', 'yes', 'no' ) === array_keys( $audio_controls['showSpeed']['options'] ?? array() ) );
check( 'the video widget offers subtitles, chapters and calls to action as lists', 'repeater' === ( $video_controls['tracks']['type'] ?? '' ) && 'repeater' === ( $video_controls['chapters']['type'] ?? '' ) && 'repeater' === ( $video_controls['layers']['type'] ?? '' ) );
check( 'every video switch is offered', array() === array_diff( array_keys( VideoWidget::SWITCHES ), array_keys( $video_controls ) ) );
check( 'the playlist widget is a list of tracks with a layout and a preset', 'repeater' === ( $list_controls['items']['type'] ?? '' ) && array( 'list', 'grid' ) === array_keys( $list_controls['layout']['options'] ?? array() ) && isset( $list_controls['preset'] ) );
check( 'the preset choices are the site’s presets', array_key_exists( 'default', $audio_controls['preset']['options'] ?? array() ) );
check( 'the skins offered to a video are the video skins', array( '', 'theater', 'minimal', 'stacked' ) === array_keys( $video_controls['skin']['options'] ?? array() ), implode( ',', array_keys( $video_controls['skin']['options'] ?? array() ) ) );

echo PHP_EOL . '# What the audio widget’s choices become' . PHP_EOL;

$audio = new AudioWidget();

$from_library = $audio->attributes_from( array(
	'source'        => 'library',
	'media'         => array( 'id' => 77, 'url' => 'https://example.test/uploads/track.mp3' ),
	'title'         => 'Chosen title',
	'artist'        => 'Somebody',
	'thumbnail'     => array( 'id' => 78, 'url' => 'https://example.test/uploads/cover.jpg' ),
	'download_url'  => array( 'url' => 'https://example.test/get.mp3' ),
	'autoplay'      => 'yes',
	'loop'          => '',
	'start_time'    => '12',
	'preset'        => 'default',
	'skin'          => 'card',
	'accent'        => '#ff0000',
	'border_radius' => '12',
	'showSpeed'     => 'yes',
	'showDownload'  => 'no',
	'showTitle'     => '',
	'skip_seconds'  => '30',
	'layers'        => array(
		array( '_id' => 'a', 'type' => 'cta', 'at' => '50', 'until' => '0', 'title' => 'Halfway', 'button' => 'Go', 'url' => array( 'url' => 'https://example.test/go' ), 'new_tab' => 'yes', 'skip' => 'yes' ),
		array( '_id' => 'b', 'type' => 'email', 'at' => '10', 'title' => 'Sign up', 'list' => 'news', 'consent' => 'I agree', 'thanks' => 'Thanks' ),
	),
) );

check( 'a library file is the attachment, by id and address', 77 === $from_library['attachmentId'] && 'https://example.test/uploads/track.mp3' === $from_library['src'] );
check( 'the title and artist as typed', 'Chosen title' === $from_library['title'] && 'Somebody' === $from_library['artist'] );
check( 'the cover as an image', 78 === $from_library['thumbnailId'] && 'https://example.test/uploads/cover.jpg' === $from_library['thumbnail'] );
check( 'the download address', 'https://example.test/get.mp3' === $from_library['downloadUrl'] );
check( 'a switch on is true, a switch off is false', true === $from_library['autoplay'] && false === $from_library['loop'] );
check( 'a start time as seconds', 12.0 === $from_library['startTime'] );
check( 'the preset, skin and accent', 'default' === $from_library['preset'] && 'card' === $from_library['skin'] && '#ff0000' === $from_library['accent'] );
check( 'a radius in pixels', '12px' === $from_library['borderRadius'], (string) $from_library['borderRadius'] );
check( 'three-way choices: show, hide, and the preset’s', 'yes' === $from_library['showSpeed'] && 'no' === $from_library['showDownload'] && '' === $from_library['showTitle'] );
check( 'skip seconds as text, as the block stores it', '30' === $from_library['skipSeconds'] );
check( 'a call to action in the renderer’s shape', 'cta' === ( $from_library['layers'][0]['type'] ?? '' ) && 50 === ( $from_library['layers'][0]['at'] ?? 0 ) && 'https://example.test/go' === ( $from_library['layers'][0]['url'] ?? '' ) && true === ( $from_library['layers'][0]['newTab'] ?? false ) );
check( 'and an email gate with its list', 'email' === ( $from_library['layers'][1]['type'] ?? '' ) && 'news' === ( $from_library['layers'][1]['list'] ?? '' ) );

$sane = Attributes::sanitize( $from_library );
check( 'and the renderer’s own sanitiser accepts all of it', 77 === $sane['attachmentId'] && 'card' === $sane['skin'] && 'yes' === $sane['showSpeed'] && 2 === count( $sane['layers'] ) && '12px' === $sane['borderRadius'] );

$from_url = $audio->attributes_from( array( 'source' => 'url', 'url' => array( 'url' => 'https://cdn.example.test/a.mp3' ), 'media' => array( 'id' => 77, 'url' => 'x' ) ) );
check( 'an address is used when chosen, and the library file left alone', 'https://cdn.example.test/a.mp3' === $from_url['src'] && 0 === $from_url['attachmentId'] );

$from_tag = $audio->attributes_from( array( 'source' => 'url', 'url' => 'https://cdn.example.test/from-a-dynamic-tag.mp3' ) );
check( 'a dynamic tag that hands over a plain string is an address too', 'https://cdn.example.test/from-a-dynamic-tag.mp3' === $from_tag['src'] );

$from_field = $audio->attributes_from( array( 'source' => 'field', 'source_field' => 'audio_url', 'media' => array( 'id' => 77, 'url' => 'x' ) ) );
check( 'a custom field is named and nothing else is used', 'audio_url' === $from_field['sourceField'] && '' === $from_field['src'] && 0 === $from_field['attachmentId'] );

$empty = $audio->attributes_from( array() );
check( 'nothing chosen is nothing, not an error', '' === $empty['src'] && 0 === $empty['attachmentId'] && '' === $empty['borderRadius'] && array() === $empty['layers'] );

echo PHP_EOL . '# What the video widget’s choices become' . PHP_EOL;

$video = new VideoWidget();

$vid = $video->attributes_from( array(
	'source'             => 'url',
	'url'                => array( 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ),
	'poster'             => array( 'id' => 78, 'url' => 'https://example.test/uploads/cover.jpg' ),
	'aspect_ratio'       => '4:3',
	'muted'              => 'yes',
	'hide_after'         => '1500',
	'poster_fit'         => 'contain',
	'caption_size'       => 'large',
	'videoProviderBare'  => 'no',
	'videoCaptionsOn'    => 'yes',
	'tracks'             => array( array( '_id' => 't', 'label' => 'Español', 'srclang' => 'es', 'src' => array( 'url' => 'https://example.test/es.vtt' ), 'default' => 'yes' ) ),
	'chapters'           => array( array( '_id' => 'c', 'start' => '1:30', 'title' => 'Second part' ) ),
) );

check( 'the address and the poster', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' === $vid['src'] && 78 === $vid['posterId'] );
check( 'the ratio, the muting and the delay', '4:3' === $vid['aspectRatio'] && true === $vid['muted'] && '1500' === $vid['videoHideAfter'] );
check( 'the poster fit and subtitle size', 'contain' === $vid['videoPosterFit'] && 'large' === $vid['videoCaptionSize'] );
check( 'the provider interface switch and subtitles-on', 'no' === $vid['videoProviderBare'] && 'yes' === $vid['videoCaptionsOn'] );
check( 'a subtitle track in the renderer’s shape', 'es' === ( $vid['tracks'][0]['srclang'] ?? '' ) && 'https://example.test/es.vtt' === ( $vid['tracks'][0]['src'] ?? '' ) && true === ( $vid['tracks'][0]['default'] ?? false ) );
check( 'a chapter with its start as typed', '1:30' === ( $vid['chapters'][0]['start'] ?? '' ) && 'Second part' === ( $vid['chapters'][0]['title'] ?? '' ) );

$vsane = Attributes::sanitize( $vid );
check( 'and the sanitiser keeps them', 1 === count( $vsane['tracks'] ) && 90.0 === ( $vsane['chapters'][0]['start'] ?? 0 ) && 'no' === $vsane['videoProviderBare'], json_encode( $vsane['chapters'] ) );

echo PHP_EOL . '# What the playlist widget’s choices become' . PHP_EOL;

$list = new PlaylistWidget();

$items = $list->attributes_from( array(
	'heading' => 'Album',
	'layout'  => 'grid',
	'preset'  => 'default',
	'items'   => array(
		array( '_id' => '1', 'media' => array( 'id' => 77, 'url' => 'https://example.test/uploads/track.mp3' ), 'title' => 'One' ),
		array( '_id' => '2', 'media' => array( 'id' => 0, 'url' => '' ), 'url' => array( 'url' => 'https://cdn.example.test/two.mp3' ), 'title' => 'Two', 'artist' => 'B' ),
		array( '_id' => '3', 'title' => 'Nothing chosen' ),
	),
) );

check( 'a library track by id', 77 === ( $items['items'][0]['id'] ?? 0 ) );
check( 'a track by address when no file is chosen', 'https://cdn.example.test/two.mp3' === ( $items['items'][1]['src'] ?? '' ) && 'B' === ( $items['items'][1]['artist'] ?? '' ) );
check( 'a row with neither is dropped', 2 === count( $items['items'] ) );
check( 'the heading and the layout', 'Album' === $items['heading'] && 'grid' === $items['layout'] );
check( 'an unknown layout falls back to the list', 'list' === $list->attributes_from( array( 'layout' => 'carousel' ) )['layout'] );

echo PHP_EOL . '# And they render' . PHP_EOL;

$audio_html = $audio->render_to_string( array( 'source' => 'library', 'media' => array( 'id' => 77, 'url' => 'https://example.test/uploads/track.mp3' ), 'title' => 'Rendered' ) );
check( 'the audio widget renders the real player', str_contains( $audio_html, 'data-imagina-player=' ) && str_contains( $audio_html, 'imgp--audio' ) && str_contains( $audio_html, 'Rendered' ) );
check( 'inside the same wrapper the block uses, marked as Elementor’s', str_contains( $audio_html, 'class="imgp-block imgp-block--elementor"' ) );

$video_html = $video->render_to_string( array( 'source' => 'url', 'url' => array( 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ) ) );
check( 'the video widget renders a video player for a YouTube address', str_contains( $video_html, 'imgp--video' ) && str_contains( $video_html, 'dQw4w9WgXcQ' ) );

$list_html = $list->render_to_string( array( 'items' => array( array( 'media' => array( 'id' => 77, 'url' => 'https://example.test/uploads/track.mp3' ), 'title' => 'One' ) ) ) );
check( 'the playlist widget renders a playlist', str_contains( $list_html, 'imgp-playlist' ) );

$GLOBALS['stub_caps'] = array();
check( 'an empty widget prints nothing for visitors', '' === $audio->render_to_string( array() ) );
unset( $GLOBALS['stub_caps'] );

echo PHP_EOL . '# Nothing runs without Elementor' . PHP_EOL;

check( 'the widgets are registered only through Elementor’s own hook', str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/src/Integrations/Elementor/Integration.php' ), "'elementor/widgets/register'" ) );
check( 'and the preview frame is given the front-end bundle', str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/src/Integrations/Elementor/Integration.php' ), "'elementor/preview/enqueue_scripts'" ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All Elementor checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
