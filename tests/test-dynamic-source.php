<?php
/**
 * A file named by a custom field of the post the player is shown on.
 *
 * A product template holds one block; every product needs its own video. The
 * block names a custom field — ACF, JetEngine, plain meta — and the file is
 * read from the post in front of the visitor. Checked here in every shape
 * those plugins store a file in, in the shapes a hostile author might store,
 * and at every door: the block, the shortcode, the editor's preview.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Media\DynamicSource;
use ImaginaPlayer\Player\Attributes;
use ImaginaPlayer\Render\PlayerRenderer;
use ImaginaPlayer\Rest\SettingsController;
use ImaginaPlayer\Shortcodes\PlayerShortcode;

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

// Two products and a video in the library.
$GLOBALS['stub_posts'] = array(
	77 => array( 'type' => 'attachment', 'mime' => 'video/mp4', 'url' => 'https://example.test/uploads/clip.mp4', 'title' => 'Clip' ),
	10 => array( 'type' => 'product' ),
	11 => array( 'type' => 'product' ),
	12 => array( 'type' => 'product' ),
	13 => array( 'type' => 'product' ),
	14 => array( 'type' => 'product' ),
	15 => array( 'type' => 'product' ),
	16 => array( 'type' => 'product' ),
);
$GLOBALS['stub_meta'] = array(
	10 => array( 'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ),
	11 => array( 'video_url' => '77' ),
	12 => array( 'video_url' => array( 'ID' => 77, 'url' => 'https://example.test/uploads/clip.mp4' ) ),
	13 => array( 'video_url' => array( 'url' => 'https://cdn.example.test/clip.mp4' ) ),
	14 => array(),
	15 => array( 'video_url' => 'javascript:alert(1)', '_secret' => 'https://example.test/private.mp4', 'count' => '5' ),
	16 => array( 'video_url' => 'https://vimeo.com/76979871/abc123def4' ),
);

$block = array( 'sourceField' => 'video_url' );

echo '# Every shape a field plugin stores a file in' . PHP_EOL;

$out = DynamicSource::apply( Attributes::sanitize( $block ), 10 );
check( 'a URL field gives the address', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' === $out['src'] && 0 === $out['attachmentId'] );

$out = DynamicSource::apply( Attributes::sanitize( $block ), 11 );
check( 'an attachment ID stored as text — ACF’s File, JetEngine’s Media — gives the attachment', 77 === $out['attachmentId'] && '' === $out['src'] );

$out = DynamicSource::apply( Attributes::sanitize( $block ), 12 );
check( 'an array with an ID — ACF’s array format — gives the attachment', 77 === $out['attachmentId'] );

$out = DynamicSource::apply( Attributes::sanitize( $block ), 13 );
check( 'an array with only an address gives the address', 'https://cdn.example.test/clip.mp4' === $out['src'] );

$out = DynamicSource::apply( Attributes::sanitize( $block ), 16 );
check( 'a Vimeo address with its unlisted hash survives whole', 'https://vimeo.com/76979871/abc123def4' === $out['src'] );

echo PHP_EOL . '# And the shapes that are not a file' . PHP_EOL;

$with_own = Attributes::sanitize( $block + array( 'src' => 'https://example.test/default.mp4' ) );
$out      = DynamicSource::apply( $with_own, 14 );
check( 'a post whose field is empty keeps the block’s own file', 'https://example.test/default.mp4' === $out['src'] );
$out = DynamicSource::apply( Attributes::sanitize( $block ), 14 );
check( 'and with no own file there is none', '' === $out['src'] && 0 === $out['attachmentId'] );

$out = DynamicSource::apply( Attributes::sanitize( $block ), 15 );
check( 'a field holding a javascript: address is not a file', '' === $out['src'] );

$out = DynamicSource::apply( Attributes::sanitize( array( 'sourceField' => '_secret' ) ), 15 );
check( 'hidden meta is not read, whatever it holds', '' === $out['src'], $out['src'] );

$out = DynamicSource::apply( Attributes::sanitize( array( 'sourceField' => 'count' ) ), 15 );
check( 'a number that is not an attachment is not a file', 0 === $out['attachmentId'] && '' === $out['src'] );

$out = DynamicSource::apply( Attributes::sanitize( array( 'src' => 'https://example.test/own.mp4' ) ), 10 );
check( 'a block naming no field is left alone', 'https://example.test/own.mp4' === $out['src'] );

check( 'a key with anything but letters, digits, dash and underscore is refused', '' === Attributes::sanitize( array( 'sourceField' => 'video url; drop' ) )['sourceField'] );
check( 'and one that is too long', '' === Attributes::sanitize( array( 'sourceField' => str_repeat( 'a', 101 ) ) )['sourceField'] );
check( 'an ordinary key is kept as typed', 'Video-URL_2' === Attributes::sanitize( array( 'sourceField' => ' Video-URL_2 ' ) )['sourceField'] );

echo PHP_EOL . '# At the block, in a loop' . PHP_EOL;

$renderer = new PlayerRenderer();

$GLOBALS['stub_current_post'] = 10;
$html = $renderer->render( $block );
check( 'the block on product 10 shows product 10’s video', str_contains( $html, 'imgp--video' ) && str_contains( $html, 'dQw4w9WgXcQ' ) );

$GLOBALS['stub_current_post'] = 11;
$html = $renderer->render( $block );
check( 'the same block on product 11 shows product 11’s', str_contains( $html, 'clip.mp4' ) && ! str_contains( $html, 'dQw4w9WgXcQ' ) );

$GLOBALS['stub_current_post'] = 14;
$GLOBALS['stub_caps']         = array();
check( 'and on a product with no video, visitors see nothing at all', '' === $renderer->render( $block ) );
$GLOBALS['stub_caps'] = array( 'edit_posts' );
$html                 = $renderer->render( $block );
check( 'while an editor is told which field it would have read', str_contains( $html, 'imgp--dynamic' ) && str_contains( $html, 'video_url' ), $html );
unset( $GLOBALS['stub_caps'] );

$GLOBALS['stub_current_post'] = 0;
$GLOBALS['stub_queried_post'] = 10;
$html = $renderer->render( $block );
check( 'outside a loop, the page’s own post is read', str_contains( $html, 'dQw4w9WgXcQ' ) );
$GLOBALS['stub_queried_post'] = 0;

echo PHP_EOL . '# At the shortcode' . PHP_EOL;

$GLOBALS['stub_current_post'] = 10;
$shortcode = new PlayerShortcode();
check( '[imagina_player field="video_url"] reads the field', str_contains( $shortcode->render( array( 'field' => 'video_url' ) ), 'dQw4w9WgXcQ' ) );
check( 'as does source_field="video_url"', str_contains( $shortcode->render( array( 'source_field' => 'video_url' ) ), 'dQw4w9WgXcQ' ) );

echo PHP_EOL . '# And in the editor' . PHP_EOL;

$GLOBALS['stub_current_post'] = 0;
$controller = new SettingsController();

$data = $controller->preview( new WP_REST_Request( array( 'attributes' => $block, 'postId' => 10 ) ) )->get_data();
check( 'the preview of a post shows that post’s video', str_contains( (string) $data['html'], 'dQw4w9WgXcQ' ) );

$GLOBALS['stub_caps'] = array( 'edit_posts' );
$data = $controller->preview( new WP_REST_Request( array( 'attributes' => $block, 'postId' => 10 ) ) )->get_data();
check( 'but not of a post the author may not edit', ! str_contains( (string) $data['html'], 'dQw4w9WgXcQ' ) && str_contains( (string) $data['html'], 'imgp--dynamic' ) );
unset( $GLOBALS['stub_caps'] );

$data = $controller->preview( new WP_REST_Request( array( 'attributes' => $block ) ) )->get_data();
check( 'a template — no post — shows which field the block will read', str_contains( (string) $data['html'], 'imgp--dynamic' ) && str_contains( (string) $data['html'], 'video_url' ) );

$editor_bundle = (string) file_get_contents( dirname( __DIR__ ) . '/build/editor.js' );
check( 'the editor offers the field', str_contains( $editor_bundle, 'Dynamic source' ) && str_contains( $editor_bundle, 'sourceField' ) && str_contains( $editor_bundle, 'getCurrentPostId' ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All dynamic-source checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
