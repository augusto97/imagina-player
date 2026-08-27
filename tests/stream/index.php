<?php
/**
 * Front controller for the streaming integration test.
 *
 * Boots the plugin's stream server against PHP's built-in web server with one
 * fake protected attachment, so range requests can be exercised over real HTTP.
 * Started by tests/test-stream-http.php; not part of the plugin.
 */

$imgp_base_url = 'http://127.0.0.1/';

require dirname( __DIR__ ) . '/bootstrap.php';

use ImaginaPlayer\Protection\ProtectedMedia;
use ImaginaPlayer\Protection\StreamServer;
use ImaginaPlayer\Protection\Vault;
use ImaginaPlayer\Settings;

$media = getenv( 'IMGP_TEST_MEDIA' );

$GLOBALS['stub_posts'] = array(
	7 => array(
		'type' => 'attachment',
		'mime' => 'audio/mpeg',
		'file' => $media,
	),
);

$GLOBALS['stub_meta'] = array(
	7 => array( Vault::META_PROTECTED => '1' ),
);

$settings               = Settings::defaults();
$settings['protection'] = array_replace(
	$settings['protection'],
	array( 'enabled' => true )
);

update_option( Settings::OPTION_KEY, $settings );
Settings::flush_cache();

// A helper route so the test can ask for a freshly signed URL.
if ( isset( $_GET['mint'] ) ) {
	header( 'Content-Type: text/plain' );
	echo ProtectedMedia::signed_url( 7 );
	exit;
}

( new StreamServer() )->maybe_serve();

http_response_code( 404 );
echo 'no route';
