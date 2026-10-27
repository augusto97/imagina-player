<?php
/**
 * Who may play a player, and the viewer's own name over the picture.
 *
 * The rule on a locked player matters only if nothing of the file reaches
 * the page: an address in the markup, a subtitle track, a download link or
 * the script's configuration would each hand the file over. And the viewer
 * mark matters only if it names the person watching, and nobody else.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Player\Access;
use ImaginaPlayer\Render\PlayerRenderer;
use ImaginaPlayer\Render\PlaylistRenderer;
use ImaginaPlayer\Settings;

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

// The integrations, as the plugins they stand for would answer.
function wc_customer_bought_product( $email, $user_id, $product_id ) {
	return in_array( (int) $product_id, (array) ( $GLOBALS['bought'][ (int) $user_id ] ?? array() ), true );
}
function wc_memberships_is_user_active_member( $user_id, $plan_id ) {
	return in_array( (int) $plan_id, (array) ( $GLOBALS['plans'][ (int) $user_id ] ?? array() ), true );
}
function sfwd_lms_has_access( $course_id, $user_id ) {
	return in_array( (int) $course_id, (array) ( $GLOBALS['courses'][ (int) $user_id ] ?? array() ), true );
}
define( 'MEPR_VERSION', '1.0' );

$GLOBALS['stub_users'] = array(
	5 => array( 'display_name' => 'Ana <b>Pérez</b>', 'user_email' => 'ana@example.com', 'roles' => array( 'subscriber' ) ),
	6 => array( 'display_name' => 'Luis', 'user_email' => 'luis@example.com', 'roles' => array( 'customer' ) ),
);
$GLOBALS['bought']                = array( 6 => array( 42 ) );
$GLOBALS['plans']                 = array( 6 => array( 7 ) );
$GLOBALS['courses']               = array( 6 => array( 99 ) );
$GLOBALS['stub_user_caps'][6]     = array( 'mepr-active:memberships:12' );

/** As a visitor: nobody who can edit anything. */
function as_visitor( int $user = 0 ): void {
	$GLOBALS['stub_current_user'] = $user;
	$GLOBALS['stub_caps']         = array();
}

echo '# The rules' . PHP_EOL;

as_visitor();
check( 'with no rule, everyone may play', Access::allows( array() ) );
check( '"signed in" refuses a visitor who is not', ! Access::allows( array( 'accessRule' => 'logged_in' ) ) );
as_visitor( 5 );
check( 'and lets one in who is', Access::allows( array( 'accessRule' => 'logged_in' ) ) );
check( 'a role in the list lets them in', Access::allows( array( 'accessRule' => 'roles', 'accessIds' => 'customer, subscriber' ) ) );
check( 'a role not in it does not', ! Access::allows( array( 'accessRule' => 'roles', 'accessIds' => 'customer' ) ) );
check( 'someone who did not buy the product is refused', ! Access::allows( array( 'accessRule' => 'woocommerce', 'accessIds' => '42' ) ) );
as_visitor( 6 );
check( 'someone who bought any one of the products is let in', Access::allows( array( 'accessRule' => 'woocommerce', 'accessIds' => '41,42' ) ) );
check( 'a member of the plan is let in', Access::allows( array( 'accessRule' => 'wc_memberships', 'accessIds' => '7' ) ) );
check( 'a MemberPress member, through its own capability', Access::allows( array( 'accessRule' => 'memberpress', 'accessIds' => '12' ) ) );
check( 'and not for a membership they do not have', ! Access::allows( array( 'accessRule' => 'memberpress', 'accessIds' => '13' ) ) );
check( 'a student of the course is let in', Access::allows( array( 'accessRule' => 'learndash', 'accessIds' => '99' ) ) );
check( 'a rule nobody knows is no rule', Access::allows( array( 'accessRule' => 'witchcraft' ) ) );

as_visitor( 5 );
$GLOBALS['stub_caps'] = array( 'edit_post' );
check( 'whoever can edit the post always may', Access::allows( array( 'accessRule' => 'woocommerce', 'accessIds' => '42' ), 10 ) );
as_visitor( 5 );

add_filter( 'imagina_player_can_view', static fn( $allowed, $atts, $user ) => 5 === $user ? true : $allowed, 10, 3 );
check( 'and a site can decide for itself through a filter', Access::allows( array( 'accessRule' => 'woocommerce', 'accessIds' => '42' ) ) );
remove_all_filters( 'imagina_player_can_view' );

echo PHP_EOL . '# A locked player has nothing of the file in it' . PHP_EOL;

as_visitor();
$renderer = new PlayerRenderer();
$secret   = 'https://cdn.example.com/lesson-secret.mp4';
$locked   = $renderer->render(
	array(
		'src'          => $secret,
		'title'        => 'Lección 4',
		'poster'       => 'https://cdn.example.com/poster.jpg',
		'downloadUrl'  => 'https://cdn.example.com/lesson-secret.zip',
		'tracks'       => array( array( 'src' => 'https://cdn.example.com/secret.vtt', 'srclang' => 'es', 'label' => 'Español' ) ),
		'showDownload' => 'yes',
		'accessRule'   => 'woocommerce',
		'accessIds'    => '42',
	)
);

check( 'no address of the file', ! str_contains( $locked, 'lesson-secret' ) );
check( 'no subtitle file', ! str_contains( $locked, 'secret.vtt' ) && ! str_contains( $locked, '<track' ) );
check( 'no media element and nothing for the script', ! str_contains( $locked, '<video' ) && ! str_contains( $locked, 'data-imagina-player' ) );
check( 'but the poster and the title, which are what the offer is for', str_contains( $locked, 'poster.jpg' ) && str_contains( $locked, 'Lección 4' ) );
check( 'with a message that suits the rule', str_contains( $locked, 'This video comes with a purchase.' ) );
check( 'a button to the product the rule names', (bool) preg_match( '/class="imgp__locked-button" href="https:\/\/example\.test\/\?p=42"/', $locked ), $locked );
check( 'and a way to sign in, for a visitor who is not', str_contains( $locked, 'imgp__locked-login' ) );

as_visitor( 5 );
$member = $renderer->render(
	array(
		'src'           => 'https://cdn.example.com/a.mp3',
		'accessRule'    => 'roles',
		'accessIds'     => 'customer',
		'accessMessage' => 'Sólo para clientes',
		'accessButton'  => 'Hazte cliente',
		'accessUrl'     => 'https://example.com/join',
	)
);
check( 'the author’s own message, button and address', str_contains( $member, 'Sólo para clientes' ) && str_contains( $member, 'Hazte cliente' ) && str_contains( $member, 'https://example.com/join' ) );
check( 'and no sign-in link for someone already signed in', ! str_contains( $member, 'imgp__locked-login' ) );

check( 'a page is cacheable until a player opens for somebody in particular', ! defined( 'DONOTCACHEPAGE' ) );
as_visitor( 6 );
$open = $renderer->render( array( 'src' => $secret, 'accessRule' => 'woocommerce', 'accessIds' => '42' ) );
check( 'it opens for someone who bought it', str_contains( $open, 'lesson-secret' ) && str_contains( $open, 'data-imagina-player' ) );
check( 'and then the page is kept out of the cache', defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );

echo PHP_EOL . '# A locked playlist' . PHP_EOL;

as_visitor();
$list = ( new PlaylistRenderer() )->render(
	array(
		'items'      => array(
			array( 'src' => 'https://cdn.example.com/one-secret.mp3', 'title' => 'Uno' ),
			array( 'src' => 'https://cdn.example.com/two-secret.mp3', 'title' => 'Dos' ),
		),
		'accessRule' => 'logged_in',
	)
);
check( 'keeps its titles', str_contains( $list, 'Uno' ) && str_contains( $list, 'Dos' ) );
check( 'and loses every address', ! str_contains( $list, 'secret.mp3' ) && ! str_contains( $list, 'data-imagina-playlist=' ) );
check( 'its player is locked too', str_contains( $list, 'imgp--locked' ) );

echo PHP_EOL . '# The viewer’s name over the picture' . PHP_EOL;

$GLOBALS['stub_options']['imagina_player_settings'] = array(
	'video' => array_merge( Settings::defaults()['video'], array( 'viewer_mark' => 'name', 'viewer_mark_opacity' => 40, 'show_pip' => true ) ),
);
Settings::flush_cache();

as_visitor( 5 );
$named = $renderer->render( array( 'src' => 'https://cdn.example.com/clase.mp4' ) );
check( 'names the person signed in', (bool) preg_match( '/class="imgp__viewer-mark"[^>]*>Ana &lt;b&gt;Pérez&lt;\/b&gt;</', $named ), substr( (string) strstr( $named, 'imgp__viewer-mark' ), 0, 120 ) );
check( 'escaped, so a name cannot carry markup', ! str_contains( $named, '<b>Pérez' ) );
check( 'at the site’s opacity', str_contains( $named, '--imgp-vm-opacity:0.4' ) );
check( 'and turns picture-in-picture off', str_contains( $named, 'disablepictureinpicture' ) && ! str_contains( $named, 'imgp__vbtn--pip' ) );

$email = $renderer->render( array( 'src' => 'https://cdn.example.com/clase.mp4', 'videoViewerMark' => 'email' ) );
check( 'a block can ask for the email instead', str_contains( $email, '>ana@example.com<' ) );

$off = $renderer->render( array( 'src' => 'https://cdn.example.com/clase.mp4', 'videoViewerMark' => 'off' ) );
check( 'or for nothing', ! str_contains( $off, 'imgp__viewer-mark' ) && str_contains( $off, 'imgp__vbtn--pip' ) );

as_visitor();
$anonymous = $renderer->render( array( 'src' => 'https://cdn.example.com/clase.mp4' ) );
check( 'nobody signed in, no mark', ! str_contains( $anonymous, 'imgp__viewer-mark' ) );

as_visitor( 5 );
$audio = $renderer->render( array( 'src' => 'https://cdn.example.com/a.mp3' ) );
check( 'and none on audio, which has no picture', ! str_contains( $audio, 'imgp__viewer-mark' ) );

unset( $GLOBALS['stub_options']['imagina_player_settings'] );
Settings::flush_cache();

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
