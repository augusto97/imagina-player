<?php
/**
 * Protected media: token signing, access decisions and range parsing.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Protection\ProtectedMedia;
use ImaginaPlayer\Protection\StreamServer;
use ImaginaPlayer\Protection\Vault;
use ImaginaPlayer\Settings;
use ImaginaPlayer\Support\Signature;

$failures = 0;
function check( string $label, bool $ok ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? "PASS  " : "FAIL  " ) . $label . PHP_EOL;
}

// --- Signature --------------------------------------------------------------

$token  = Signature::create( array( 'id' => 42 ), 3600, 'stream' );
$claims = Signature::verify( $token, 'stream' );

check( 'signature round-trips', is_array( $claims ) && 42 === $claims['id'] );
check( 'signature carries an expiry', is_array( $claims ) && $claims['exp'] > time() );
check( 'tampered payload rejected', null === Signature::verify( 'x' . $token, 'stream' ) );
check( 'tampered signature rejected', null === Signature::verify( substr( $token, 0, -4 ) . 'aaaa', 'stream' ) );
check( 'garbage rejected', null === Signature::verify( 'nonsense', 'stream' ) );

// A token minted for one purpose must not validate for another.
check( 'context isolation holds', null === Signature::verify( $token, 'peaks' ) );

// Expired tokens: issue one in the past.
$expired = Signature::create( array( 'id' => 42 ), 60, 'stream', time() - 7200 );
check( 'expired token rejected', null === Signature::verify( $expired, 'stream' ) );

// Window alignment keeps the URL stable for everyone in the same window.
$window = 3600;
check(
	'window start is stable inside a window',
	Signature::window_start( $window, 1000000 ) === Signature::window_start( $window, 1000000 + 59 )
);
check(
	'window start advances between windows',
	Signature::window_start( $window, 1000000 ) !== Signature::window_start( $window, 1000000 + $window * 2 )
);

// --- Range parsing ----------------------------------------------------------

$size = 1000;

check( 'no header means the whole file', null === StreamServer::parse_range( '', $size ) );
check( 'open-ended range', StreamServer::parse_range( 'bytes=0-', $size ) === array( 0, 999 ) );
check( 'closed range', StreamServer::parse_range( 'bytes=100-199', $size ) === array( 100, 199 ) );
check( 'suffix range', StreamServer::parse_range( 'bytes=-500', $size ) === array( 500, 999 ) );
check( 'range past the end is clamped', StreamServer::parse_range( 'bytes=900-5000', $size ) === array( 900, 999 ) );
check( 'start past the end is unsatisfiable', false === StreamServer::parse_range( 'bytes=1000-', $size ) );
check( 'reversed range is unsatisfiable', false === StreamServer::parse_range( 'bytes=500-100', $size ) );
check( 'multipart ranges fall back to the whole file', null === StreamServer::parse_range( 'bytes=0-99,200-299', $size ) );
check( 'nonsense header falls back to the whole file', null === StreamServer::parse_range( 'chickens=0-99', $size ) );
check( 'empty spec falls back to the whole file', null === StreamServer::parse_range( 'bytes=-', $size ) );

// --- Access decisions -------------------------------------------------------

$settings = Settings::defaults();

$configure = static function ( array $protection ) use ( $settings ): void {
	$next               = $settings;
	$next['protection'] = array_replace( $settings['protection'], $protection );
	update_option( Settings::OPTION_KEY, $next );
	Settings::flush_cache();
};

$configure( array( 'enabled' => true ) );

$url = ProtectedMedia::signed_url( 7 );

check( 'signed URL points at the site', str_starts_with( $url, 'https://example.test/?' ) );
check( 'signed URL names the attachment', str_contains( $url, 'imagina_media=7' ) );
check( 'signed URL carries a token', str_contains( $url, 'imgpt=' ) );

parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
$issued = (string) $query[ ProtectedMedia::TOKEN_VAR ];

check( 'a valid token authorises', true === ProtectedMedia::authorize( 7, $issued ) );
check( 'a token for another file is refused', 'token_mismatch' === ProtectedMedia::authorize( 8, $issued ) );
check( 'a missing token is refused', 'invalid_token' === ProtectedMedia::authorize( 7, '' ) );

// Login requirement.
$configure( array( 'enabled' => true, 'require_login' => true ) );
$GLOBALS['stub_current_user'] = 0;
check( 'logged-out visitor is refused when login is required', 'login_required' === ProtectedMedia::authorize( 7, $issued ) );

$GLOBALS['stub_current_user'] = 5;
check( 'logged-in visitor passes', true === ProtectedMedia::authorize( 7, $issued ) );

// User binding: a link issued to one user must not work for another.
$configure( array( 'enabled' => true, 'bind_to_user' => true ) );
$GLOBALS['stub_current_user'] = 5;
parse_str( (string) wp_parse_url( ProtectedMedia::signed_url( 7 ), PHP_URL_QUERY ), $bound_query );
$bound = (string) $bound_query[ ProtectedMedia::TOKEN_VAR ];

check( 'user-bound link works for its owner', true === ProtectedMedia::authorize( 7, $bound ) );

$GLOBALS['stub_current_user'] = 9;
check( 'user-bound link fails for someone else', 'wrong_user' === ProtectedMedia::authorize( 7, $bound ) );

// The membership hook has the final say. This is the integration point a
// course or membership plugin uses, so it is checked in both directions.
$configure( array( 'enabled' => true ) );
$GLOBALS['stub_current_user'] = 5;

check( 'access is allowed with no membership rule', true === ProtectedMedia::authorize( 7, $issued ) );

add_filter( 'imagina_player_can_stream', static fn( $allowed, $id ) => 7 === $id ? false : $allowed, 10, 2 );

check( 'the can_stream filter can deny', 'denied_by_filter' === ProtectedMedia::authorize( 7, $issued ) );

remove_all_filters( 'imagina_player_can_stream' );

check( 'removing the rule restores access', true === ProtectedMedia::authorize( 7, $issued ) );

// --- Vault ------------------------------------------------------------------

check( 'vault directory name is stable', Vault::directory_name() === Vault::directory_name() );
check( 'vault directory name is not guessable', 1 === preg_match( '/^imagina-protected-[a-f0-9]{12}$/', Vault::directory_name() ) );
check( 'vault path sits inside uploads', str_contains( Vault::base_dir(), Vault::directory_name() ) );

// --- Moving files in and out of the vault ------------------------------------
//
// This code relocates a user's media, so it is checked against real files.

$uploads = sys_get_temp_dir() . '/imgp-vault-test-' . getmypid();
$GLOBALS['stub_uploads_dir'] = $uploads;

mkdir( $uploads . '/2026/08', 0777, true );
file_put_contents( $uploads . '/2026/08/pista.mp3', 'audio-bytes' );

$GLOBALS['stub_posts'] = array(
	11 => array( 'type' => 'attachment', 'mime' => 'audio/mpeg', 'file' => $uploads . '/2026/08/pista.mp3' ),
);
$GLOBALS['stub_meta']  = array( 11 => array( '_wp_attached_file' => '2026/08/pista.mp3' ) );

// `get_attached_file()` follows `_wp_attached_file`, as WordPress does.
$resolve = static function ( int $id ) use ( $uploads ): void {
	$GLOBALS['stub_posts'][ $id ]['file'] = $uploads . '/' . $GLOBALS['stub_meta'][ $id ]['_wp_attached_file'];
};

check( 'file starts out unprotected', ! Vault::is_protected( 11 ) );

$result = Vault::protect( 11 );
$resolve( 11 );

check( 'protect() succeeds', true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
check( 'the file is now flagged protected', Vault::is_protected( 11 ) );
check( 'the original path is empty', ! file_exists( $uploads . '/2026/08/pista.mp3' ) );
check( 'the file now lives in the vault', file_exists( Vault::base_dir() . '/2026/08/pista.mp3' ) );
check( 'its contents survived the move', 'audio-bytes' === file_get_contents( Vault::base_dir() . '/2026/08/pista.mp3' ) );
check( 'WordPress points at the new path', str_starts_with( $GLOBALS['stub_meta'][11]['_wp_attached_file'], Vault::directory_name() . '/' ) );
check( 'deny rules were written', file_exists( Vault::base_dir() . '/.htaccess' ) );
check( 'the deny rules actually deny', str_contains( (string) file_get_contents( Vault::base_dir() . '/.htaccess' ), 'Require all denied' ) );

$result = Vault::unprotect( 11 );
$resolve( 11 );

check( 'unprotect() succeeds', true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
check( 'the file came back', file_exists( $uploads . '/2026/08/pista.mp3' ) );
check( 'the flag is gone', ! Vault::is_protected( 11 ) );
check( 'the path was restored', '2026/08/pista.mp3' === $GLOBALS['stub_meta'][11]['_wp_attached_file'] );

// Images keep their generated sizes next to them; moving one would strand them.
$GLOBALS['stub_posts'][12] = array( 'type' => 'attachment', 'mime' => 'image/jpeg', 'file' => '' );
$image_result              = Vault::protect( 12 );
check( 'images cannot be protected', is_wp_error( $image_result ) );

// Clean up.
exec( 'rm -rf ' . escapeshellarg( $uploads ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
