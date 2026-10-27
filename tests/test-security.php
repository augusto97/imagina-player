<?php
/**
 * What the security audit found, closed and kept closed.
 *
 * None of it was a door anyone could walk through unaided, which is why each
 * one is worth a test: these are the kinds of gaps that widen quietly when the
 * code around them changes.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Protection\ProtectedMedia;
use ImaginaPlayer\Protection\SelfCheck;
use ImaginaPlayer\Render\PlayerRenderer;
use ImaginaPlayer\Rest\LeadController;
use ImaginaPlayer\Rest\SettingsController;
use ImaginaPlayer\Rest\StreamController;
use ImaginaPlayer\Support\Signature;

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

$GLOBALS['stub_posts'] = array(
	10 => array( 'type' => 'attachment', 'post_parent' => 20 ),
	11 => array( 'type' => 'attachment', 'post_parent' => 21 ),
	12 => array( 'type' => 'attachment', 'post_parent' => 0 ),
	13 => array( 'type' => 'attachment', 'post_parent' => 0 ),
	20 => array( 'type' => 'post', 'status' => 'publish' ),
	21 => array( 'type' => 'post', 'status' => 'private' ),
);
$GLOBALS['stub_meta'] = array(
	10 => array( '_imagina_protected' => '1' ),
	11 => array( '_imagina_protected' => '1' ),
	12 => array( '_imagina_protected' => '1' ),
	// 13 is in the library and not protected.
);

/**
 * A token as a page served `$age` seconds ago would carry.
 */
function served_token( int $id, int $age = 0 ): string {
	return Signature::create( array( 'id' => $id ), 600, ProtectedMedia::CONTEXT, time() - $age );
}

echo '# Fresh links are only for people who were shown the player' . PHP_EOL;

$controller = new StreamController();
$ask = static function ( array $params, array $headers = array() ) use ( $controller ) {
	$answer = $controller->get_url( new WP_REST_Request( $params, $headers ) );

	return $answer instanceof WP_Error
		? (string) $answer->get_error_code()
		: (string) ( $answer->get_data()['url'] ?? '' );
};

$GLOBALS['stub_caps'] = array();

check( 'a number alone gets nothing', 'imagina_player_unavailable' === $ask( array( 'id' => 10 ) ) );
check( 'nor a made-up token', 'imagina_player_unavailable' === $ask( array( 'id' => 10, 'token' => 'abc.def' ) ) );
check( 'nor a real token for another file', 'imagina_player_unavailable' === $ask( array( 'id' => 12, 'token' => served_token( 10 ) ) ) );
check( 'a token from a page served an hour ago is traded for a fresh link', str_contains( $ask( array( 'id' => 10, 'token' => served_token( 10, HOUR_IN_SECONDS ) ) ), 'imagina_media=10' ) );
check( 'and so is one from a page a cache held for a week', str_contains( $ask( array( 'id' => 10, 'token' => served_token( 10, WEEK_IN_SECONDS ) ) ), 'imagina_media=10' ) );
check( 'but not one from a month ago', 'imagina_player_unavailable' === $ask( array( 'id' => 10, 'token' => served_token( 10, 30 * DAY_IN_SECONDS ) ) ) );
check( 'a file of a private post is not renewed for someone who cannot read it', 'imagina_player_unavailable' === $ask( array( 'id' => 11, 'token' => served_token( 11 ) ) ) );
$GLOBALS['stub_caps'] = array( 'read_post' );
check( 'and is for someone who can', str_contains( $ask( array( 'id' => 11, 'token' => served_token( 11 ) ) ), 'imagina_media=11' ) );
$GLOBALS['stub_caps'] = array();
check( 'a file in the vault that belongs to no post is renewed', str_contains( $ask( array( 'id' => 12, 'token' => served_token( 12 ) ) ), 'imagina_media=12' ) );
check( 'a file that is not protected gets the same answer as one that does not exist', $ask( array( 'id' => 13, 'token' => served_token( 13 ) ) ) === $ask( array( 'id' => 999, 'token' => served_token( 999 ) ) ) );
check( 'asked from another site’s page, nothing', 'imagina_player_unavailable' === $ask( array( 'id' => 10, 'token' => served_token( 10 ) ), array( 'origin' => 'https://evil.example' ) ) );
check( 'asked from this site’s own page, a link', str_contains( $ask( array( 'id' => 10, 'token' => served_token( 10 ) ), array( 'origin' => 'https://example.test' ) ), 'imagina_media=10' ) );

$GLOBALS['stub_current_user'] = 5;
$bound = Signature::create( array( 'id' => 10, 'u' => 5 ), 600, ProtectedMedia::CONTEXT );
$GLOBALS['stub_current_user'] = 6;
check( 'a link minted for one person is not renewed for another', 'imagina_player_unavailable' === $ask( array( 'id' => 10, 'token' => $bound ) ) );
unset( $GLOBALS['stub_current_user'] );

echo PHP_EOL . '# The file itself' . PHP_EOL;

check( 'a link to a file of a private post stops working with the post', 'not_visible' === ProtectedMedia::authorize( 11, served_token( 11 ) ) );
check( 'while one of a public post works', true === ProtectedMedia::authorize( 10, served_token( 10 ) ) );

$server = (string) file_get_contents( dirname( __DIR__ ) . '/src/Protection/StreamServer.php' );
check( 'the media door no longer sends anyone to an unprotected file’s address', ! str_contains( $server, 'wp_safe_redirect' ) );

echo PHP_EOL . '# A network, counted fairly' . PHP_EOL;

$_SERVER['REMOTE_ADDR'] = '2001:db8:1234:1::5';
$a = ProtectedMedia::client_fingerprint();
$_SERVER['REMOTE_ADDR'] = '2001:0db8:1234:ff00::9';
$b = ProtectedMedia::client_fingerprint();
$_SERVER['REMOTE_ADDR'] = '2001:db8:9999::1';
$c = ProtectedMedia::client_fingerprint();
check( 'two addresses in one /48 are one network', $a === $b && '' !== $a );
check( 'one in another /48 is not', $a !== $c );
add_filter( 'imagina_player_client_ip', static fn() => '203.0.113.7' );
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
check( 'behind a proxy, a site can say who the visitor really is', ProtectedMedia::client_ip() === '203.0.113.7' );
unset( $GLOBALS['stub_filters']['imagina_player_client_ip'] );
add_filter( 'imagina_player_client_ip', static fn() => 'not an address' );
check( 'and what it says is checked', '' === ProtectedMedia::client_ip() );
unset( $GLOBALS['stub_filters']['imagina_player_client_ip'] );

echo PHP_EOL . '# The editor’s preview' . PHP_EOL;

$GLOBALS['stub_caps'] = array( 'edit_posts' );
$held = SettingsController::readable_files(
	array(
		'attachmentId' => 11,
		'posterId'     => 12,
		'items'        => array( array( 'id' => 11, 'src' => 'x' ) ),
		'layers'       => array( array( 'type' => 'image', 'imageId' => 11 ) ),
	)
);
check( 'a file the author may not read is not read for them', 0 === $held['attachmentId'] && 0 === $held['posterId'] );
check( 'nor inside a playlist or an overlay', 0 === $held['items'][0]['id'] && 0 === $held['layers'][0]['imageId'] );
$GLOBALS['stub_caps'] = array( 'edit_posts', 'read_post' );
check( 'one they may read is kept', 11 === SettingsController::readable_files( array( 'attachmentId' => 11 ) )['attachmentId'] );

add_shortcode( 'probe_sc', static fn() => '<b>RAN</b>' );
$layered = array( 'src' => 'https://cdn.example.com/v.mp4', 'layers' => array( array( 'type' => 'shortcode', 'shortcode' => '[probe_sc]' ) ) );
PlayerRenderer::allow_shortcodes( false );
$preview = ( new PlayerRenderer() )->render( $layered );
check( 'a shortcode is held, not run, in the preview of an author who may not publish markup', ! str_contains( $preview, 'RAN' ) && str_contains( $preview, '[probe_sc]' ) );
PlayerRenderer::allow_shortcodes( true );
check( 'and runs on the page', str_contains( ( new PlayerRenderer() )->render( $layered ), '<b>RAN</b>' ) );
unset( $GLOBALS['stub_caps'] );

echo PHP_EOL . '# The doorway to other sites’ files' . PHP_EOL;

$peaks = (string) file_get_contents( dirname( __DIR__ ) . '/src/Rest/PeaksController.php' );
check( 'refuses this site’s own address', str_contains( $peaks, "'own-site'" ) );
check( 'connects to the address it checked, not one looked up again', str_contains( $peaks, 'CURLOPT_RESOLVE' ) );
$pin = new ReflectionMethod( ImaginaPlayer\Rest\PeaksController::class, 'pin_resolution' );
check( 'a name that resolves to this machine is refused', false === $pin->invoke( new ImaginaPlayer\Rest\PeaksController(), 'http://localhost/a.mp3' ) );
$media = new ReflectionMethod( ImaginaPlayer\Rest\PeaksController::class, 'looks_like_media' );
$doorway = new ImaginaPlayer\Rest\PeaksController();
check( 'a file with no type is accepted when its address names a media file', true === $media->invoke( $doorway, '', 'https://bucket.example/talk.mp3' ) );
check( 'and refused when it does not', false === $media->invoke( $doorway, '', 'https://internal.example/admin' ) );
check( 'an audio type is accepted whatever the address', true === $media->invoke( $doorway, 'audio/mpeg', 'https://cdn.example/stream' ) );

echo PHP_EOL . '# Captured addresses' . PHP_EOL;

$leads = new LeadController();
add_filter( 'imagina_player_lead_allowed', static fn() => new WP_Error( 'captcha', 'Prove it', array( 'status' => 403 ) ) );
$answer = $leads->capture( new WP_REST_Request( array( 'email' => 'a@example.test' ) ) );
check( 'a site’s own check can turn an address away', $answer instanceof WP_Error && 'captcha' === $answer->get_error_code() );
unset( $GLOBALS['stub_filters']['imagina_player_lead_allowed'] );

$site_limit = new ReflectionMethod( LeadController::class, 'site_within_limit' );
set_transient( 'imgp_lead_site', 300, 600 );
check( 'past the site’s ceiling, nothing more is taken', false === $site_limit->invoke( $leads ) );
delete_transient( 'imgp_lead_site' );
check( 'below it, it is', true === $site_limit->invoke( $leads ) );

echo PHP_EOL . '# Saying who may play' . PHP_EOL;

$flush = static fn() => ImaginaPlayer\Settings::flush_cache();
$GLOBALS['stub_options']['imagina_player_settings']['protection'] = array( 'enabled' => true, 'require_login' => false );
$flush();
$who = new ReflectionMethod( SelfCheck::class, 'check_who_may_play' );
$said = $who->invoke( null );
check( 'protection with nothing limiting playback is pointed out', 'warn' === ( $said['status'] ?? '' ), wp_json_encode( $said ) );
$GLOBALS['stub_options']['imagina_player_settings']['protection']['require_login'] = true;
$flush();
check( 'and not once members are required', 'pass' === ( $who->invoke( null )['status'] ?? '' ) );

$selfcheck = (string) file_get_contents( dirname( __DIR__ ) . '/src/Protection/SelfCheck.php' );
check( 'the self-check verifies the certificate first', str_contains( $selfcheck, "'sslverify'   => true" ) );

check( 'the third-party plugin archive is no longer in the repository', ! file_exists( dirname( __DIR__ ) . '/dzs-zoomsounds.zip' ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
