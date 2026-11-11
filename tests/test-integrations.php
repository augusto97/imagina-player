<?php
/**
 * Sending captured addresses on: Mailchimp, Brevo, MailerLite, FluentCRM and a
 * webhook.
 *
 * Each service is only worth connecting if what is sent is what that service
 * expects — its address, its method, its way of saying who is asking — and
 * if a key, once saved, never comes back to the browser. And none of it is
 * worth a lost address: what fails is tried again, and what is captured is
 * kept here before anything is sent anywhere.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Leads\Delivery;
use ImaginaPlayer\Leads\Integrations;
use ImaginaPlayer\Rest\IntegrationsController;
use ImaginaPlayer\Rest\LeadController;

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

/** The requests made since the last call, and forget them. */
function sent(): array {
	$sent                            = $GLOBALS['stub_remote_requests'] ?? array();
	$GLOBALS['stub_remote_requests'] = array();
	return $sent;
}

function answer( int $code, array $body = array() ): array {
	return array( 'code' => $code, 'headers' => array(), 'body' => (string) json_encode( $body ) );
}

$lead = array(
	'email'       => 'Ana@Example.com',
	'list'        => 'curso-gratis',
	'source_id'   => 5,
	'source_url'  => 'https://example.test/clase/',
	'position'    => 12.5,
	'captured_at' => '2026-11-11T10:00:00+00:00',
);

echo '# The settings' . PHP_EOL;

check( 'everything off to begin with', ! Integrations::any_enabled() );

Integrations::save(
	array(
		'mailchimp'  => array( 'enabled' => true, 'api_key' => ' abcdef0123456789-us21 ', 'list_id' => 'a1b2<c3>' ),
		'brevo'      => array( 'list_ids' => '3, x, 7,7 ,12' ),
		'mailerlite' => array( 'group_ids' => '117458921364260810, 5' ),
		'webhook'    => array( 'url' => 'javascript:alert(1)', 'secret' => 'shh' ),
		'nonsense'   => array( 'enabled' => true ),
	)
);
$stored = Integrations::get();

check( 'a key is kept without the spaces around it', 'abcdef0123456789-us21' === $stored['mailchimp']['api_key'] );
check( 'an audience ID keeps only letters and digits', 'a1b2c3' === $stored['mailchimp']['list_id'] );
check( 'list numbers are numbers, once each', '3,7,12' === $stored['brevo']['list_ids'] );
check( 'MailerLite’s long group numbers are kept whole', '117458921364260810,5' === $stored['mailerlite']['group_ids'] );
check( 'a webhook address that is not http or https is no address', '' === $stored['webhook']['url'] );
check( 'a service nobody knows is not stored', ! isset( $stored['nonsense'] ) && ! isset( $GLOBALS['stub_options'][ Integrations::OPTION ]['nonsense'] ) );
check( 'switched on, it counts', Integrations::any_enabled() );

$admin = Integrations::for_admin();
check( 'the screen sees a key as its last four characters', '••••us21' === $admin['mailchimp']['api_key'] && true === $admin['mailchimp']['api_key_saved'] );
check( 'and a short secret as nothing of it at all', '••••' === $admin['webhook']['secret'] );
check( 'no key anywhere in what the screen is sent', ! str_contains( (string) json_encode( $admin ), 'abcdef0123456789' ) );

Integrations::save( array( 'mailchimp' => array( 'api_key' => '••••us21', 'list_id' => 'zz9' ) ) );
check( 'a key sent back as its mask is kept as it was', 'abcdef0123456789-us21' === Integrations::get()['mailchimp']['api_key'] && 'zz9' === Integrations::get()['mailchimp']['list_id'] );
Integrations::save( array( 'webhook' => array( 'secret' => '' ) ) );
check( 'an emptied one is removed', '' === Integrations::get()['webhook']['secret'] );
Integrations::save( array( 'mailchimp' => array( 'api_key' => "new-key\x01-us9" ) ) );
check( 'a new one replaces it, unprintable characters dropped', 'new-key-us9' === Integrations::get()['mailchimp']['api_key'] );

echo PHP_EOL . '# Mailchimp' . PHP_EOL;

check( 'the data centre is read off the key', 'https://us21.api.mailchimp.com/3.0' === Delivery::mailchimp_base( 'abc-us21' ) && '' === Delivery::mailchimp_base( 'nokey' ) );

$GLOBALS['stub_remote_steps'] = array( answer( 200 ), answer( 204 ) );
$result = Delivery::send( 'mailchimp', array( 'api_key' => 'abc-us21', 'list_id' => 'L1', 'double_optin' => false, 'tag_with_list' => true ), $lead );
[ $put, $tag ] = sent() + array( array(), array() );
$hash = md5( 'ana@example.com' );
check( 'added or updated in one call, by the address’s hash', 'PUT' === ( $put['args']['method'] ?? '' ) && "https://us21.api.mailchimp.com/3.0/lists/L1/members/{$hash}" === ( $put['url'] ?? '' ), (string) ( $put['url'] ?? '' ) );
check( 'subscribed straight away', 'subscribed' === ( json_decode( (string) ( $put['args']['body'] ?? '' ), true )['status_if_new'] ?? '' ) );
check( 'with the key as basic authentication', 'Basic ' . base64_encode( 'imagina-player:abc-us21' ) === ( $put['args']['headers']['Authorization'] ?? '' ) );
check( 'then tagged with the gate’s list name', "https://us21.api.mailchimp.com/3.0/lists/L1/members/{$hash}/tags" === ( $tag['url'] ?? '' ) && 'curso-gratis' === ( json_decode( (string) ( $tag['args']['body'] ?? '' ), true )['tags'][0]['name'] ?? '' ) );
check( 'and it says so', true === $result['ok'] );

$GLOBALS['stub_remote_steps'] = array( answer( 200 ) );
Delivery::send( 'mailchimp', array( 'api_key' => 'abc-us21', 'list_id' => 'L1', 'double_optin' => true, 'tag_with_list' => false ), $lead );
$calls = sent();
check( 'double opt-in leaves them pending until they confirm', 'pending' === ( json_decode( (string) ( $calls[0]['args']['body'] ?? '' ), true )['status_if_new'] ?? '' ) );
check( 'no tag when asked for none', 1 === count( $calls ) );

$GLOBALS['stub_remote_steps'] = array( answer( 400, array( 'title' => 'Invalid Resource', 'detail' => 'Ana@Example.com looks fake or invalid' ) ) );
$refused = Delivery::send( 'mailchimp', array( 'api_key' => 'abc-us21', 'list_id' => 'L1', 'double_optin' => false, 'tag_with_list' => true ), $lead );
sent();
check( 'a refusal comes back in the service’s own words', false === $refused['ok'] && str_contains( $refused['message'], 'looks fake or invalid' ) );

echo PHP_EOL . '# Brevo' . PHP_EOL;

$GLOBALS['stub_remote_steps'] = array( answer( 201 ) );
$brevo = Delivery::send( 'brevo', array( 'api_key' => 'xkeysib-1', 'list_ids' => '3,7' ), $lead );
$call  = sent()[0] ?? array();
$body  = json_decode( (string) ( $call['args']['body'] ?? '' ), true );
check( 'a contact, created or updated', 'https://api.brevo.com/v3/contacts' === ( $call['url'] ?? '' ) && 'POST' === ( $call['args']['method'] ?? '' ) && true === ( $body['updateEnabled'] ?? null ) );
check( 'in the lists given, as numbers', array( 3, 7 ) === ( $body['listIds'] ?? null ) );
check( 'with the key in Brevo’s own header', 'xkeysib-1' === ( $call['args']['headers']['api-key'] ?? '' ) && true === $brevo['ok'] );

echo PHP_EOL . '# MailerLite' . PHP_EOL;

$GLOBALS['stub_remote_steps'] = array( answer( 200 ) );
Delivery::send( 'mailerlite', array( 'api_key' => 'ml-token', 'group_ids' => '117458921364260810' ), $lead );
$call = sent()[0] ?? array();
$body = json_decode( (string) ( $call['args']['body'] ?? '' ), true );
check( 'a subscriber, in the groups given', 'https://connect.mailerlite.com/api/subscribers' === ( $call['url'] ?? '' ) && array( '117458921364260810' ) === ( $body['groups'] ?? null ) );
check( 'with the token as a bearer', 'Bearer ml-token' === ( $call['args']['headers']['Authorization'] ?? '' ) );

echo PHP_EOL . '# FluentCRM' . PHP_EOL;

check( 'not there, it says so', false === Delivery::send( 'fluentcrm', array( 'list_ids' => '', 'tag_ids' => '', 'double_optin' => false ), $lead )['ok'] );

final class FakeContact {
	public string $status;
	public bool $mailed = false;
	public function __construct( string $status ) { $this->status = $status; }
	public function sendDoubleOptinEmail(): void { $this->mailed = true; }
}
final class FakeContacts {
	public function createOrUpdate( array $data ) { $GLOBALS['fluent_calls'][] = $data; return $GLOBALS['fluent_contact'] = new FakeContact( $data['status'] ); }
}
// Declared here, not hoisted to the top of the file: until now it was not there.
if ( ! function_exists( 'FluentCrmApi' ) ) {
	function FluentCrmApi( $what ) { return new FakeContacts(); }
}

$fluent = Delivery::send( 'fluentcrm', array( 'list_ids' => '1,4', 'tag_ids' => '2', 'double_optin' => true ), $lead );
$data   = $GLOBALS['fluent_calls'][0] ?? array();
check( 'a contact on this site, with its lists and tags', 'ana@example.com' === strtolower( (string) ( $data['email'] ?? '' ) ) && array( 1, 4 ) === ( $data['lists'] ?? null ) && array( 2 ) === ( $data['tags'] ?? null ) );
check( 'pending, and sent FluentCRM’s own confirmation', 'pending' === ( $data['status'] ?? '' ) && true === $GLOBALS['fluent_contact']->mailed && true === $fluent['ok'] );
check( 'no request leaves the site for it', array() === sent() );

echo PHP_EOL . '# Webhook' . PHP_EOL;

$GLOBALS['stub_remote_steps'] = array( answer( 200 ) );
Delivery::send( 'webhook', array( 'url' => 'https://hooks.example.com/catch', 'secret' => 'shh' ), $lead );
$call = sent()[0] ?? array();
$raw  = (string) ( $call['args']['body'] ?? '' );
$body = json_decode( $raw, true );
check( 'a POST of JSON to the address given', 'https://hooks.example.com/catch' === ( $call['url'] ?? '' ) && 'POST' === ( $call['args']['method'] ?? '' ) && 'application/json' === ( $call['args']['headers']['Content-Type'] ?? '' ) );
check( 'with the address, the list, the page and the second', 'lead.captured' === ( $body['event'] ?? '' ) && 'curso-gratis' === ( $body['list'] ?? '' ) && 'https://example.test/clase/' === ( $body['source_url'] ?? '' ) && 12.5 === (float) ( $body['position'] ?? 0 ) && '2026-11-11T10:00:00+00:00' === ( $body['captured_at'] ?? '' ) );
check( 'signed with the secret, so the receiver can tell it is genuine', 'sha256=' . hash_hmac( 'sha256', $raw, 'shh' ) === ( $call['args']['headers']['X-Imagina-Signature'] ?? '' ) );

$GLOBALS['stub_remote_steps'] = array( answer( 200 ) );
Delivery::send( 'webhook', array( 'url' => 'https://hooks.example.com/catch', 'secret' => '' ), $lead );
check( 'and not signed when there is no secret', ! isset( ( sent()[0] ?? array() )['args']['headers']['X-Imagina-Signature'] ) );

$GLOBALS['stub_remote_steps'] = array( new WP_Error( 'http_request_failed', 'A valid URL was not provided.' ) );
$blocked = Delivery::send( 'webhook', array( 'url' => 'http://10.0.0.5/admin', 'secret' => '' ), $lead );
sent();
check( 'a request the client refuses is a failure with its reason', false === $blocked['ok'] && 'A valid URL was not provided.' === $blocked['message'] );
check( 'made through the client that refuses private addresses', str_contains( (string) file_get_contents( dirname( __DIR__ ) . '/src/Leads/Delivery.php' ), 'wp_safe_remote_request( $url, $args )' ) );

echo PHP_EOL . '# In the background, and again when it fails' . PHP_EOL;

$GLOBALS['stub_options'][ Integrations::OPTION ] = array();
$GLOBALS['stub_scheduled']                      = array();
Delivery::queue( $lead );
check( 'nothing switched on, nothing queued', array() === $GLOBALS['stub_scheduled'] );

Integrations::save(
	array(
		'brevo'   => array( 'enabled' => true, 'api_key' => 'xkeysib-1', 'list_ids' => '3' ),
		'webhook' => array( 'enabled' => true, 'url' => 'https://hooks.example.com/catch', 'secret' => '' ),
	)
);
Delivery::queue( array( 'email' => 'luis@example.com', 'list' => '' ) );
$queued = $GLOBALS['stub_scheduled'][0] ?? array();
check( 'queued for the next cron run, not sent while the visitor waits', Delivery::HOOK === ( $queued[0] ?? '' ) && array() === sent() );
check( 'for the services switched on, as the first attempt, with the moment it was captured', array( 'brevo', 'webhook' ) === ( $queued[1][1] ?? null ) && 1 === ( $queued[1][2] ?? null ) && '' !== (string) ( $queued[1][0]['captured_at'] ?? '' ) );

$GLOBALS['stub_scheduled']    = array();
$GLOBALS['stub_scheduled_at'] = array();
$GLOBALS['stub_remote_steps'] = array( answer( 503 ), answer( 200 ) );
$now     = time();
$results = Delivery::deliver( $queued[1][0], $queued[1][1], 1 );
sent();
$retry = $GLOBALS['stub_scheduled'][0] ?? array();
check( 'what failed is tried again', false === $results['brevo']['ok'] && true === $results['webhook']['ok'] );
check( 'only that, in five minutes, as the second attempt', array( 'brevo' ) === ( $retry[1][1] ?? null ) && 2 === ( $retry[1][2] ?? null ) && abs( (int) ( $GLOBALS['stub_scheduled_at'][0] ?? 0 ) - ( $now + 300 ) ) <= 2 );

$GLOBALS['stub_scheduled']    = array();
$GLOBALS['stub_remote_steps'] = array( answer( 503 ) );
Delivery::deliver( $queued[1][0], array( 'brevo' ), 4 );
sent();
check( 'three times, and then left', array() === $GLOBALS['stub_scheduled'] );

$log = $GLOBALS['stub_options'][ Delivery::LOG ] ?? array();
check( 'the last result of each service is kept for the screen', false === ( $log['brevo']['ok'] ?? null ) && 4 === ( $log['brevo']['attempt'] ?? 0 ) && true === ( $log['webhook']['ok'] ?? null ) );
check( 'without a second copy of the address', 'l…@example.com' === ( $log['webhook']['email'] ?? '' ) );

$GLOBALS['stub_options'][ Integrations::OPTION ]['brevo']['enabled'] = false;
$GLOBALS['stub_remote_steps'] = array( answer( 200 ) );
check( 'a service switched off since is not sent to', array() === Delivery::deliver( $queued[1][0], array( 'brevo' ), 2 ) && array() === sent() );

echo PHP_EOL . '# Captured, then queued' . PHP_EOL;

$GLOBALS['stub_scheduled'] = array();
$response = ( new LeadController() )->capture( new WP_REST_Request( array( 'email' => 'eva@example.com', 'list' => 'webinar', 'source' => 9, 'at' => 30 ), array( 'referer' => 'https://example.test/webinar/' ) ) );
$queued   = array_values( array_filter( (array) $GLOBALS['stub_scheduled'], static fn( $event ) => Delivery::HOOK === $event[0] ) );
check( 'an address captured is queued for the services', ! is_wp_error( $response ) && 1 === count( $queued ) && 'eva@example.com' === ( $queued[0][1][0]['email'] ?? '' ) && 'webinar' === ( $queued[0][1][0]['list'] ?? '' ) );

$GLOBALS['stub_scheduled'] = array();
( new LeadController() )->capture( new WP_REST_Request( array( 'email' => 'bot@example.com', 'website' => 'http://spam' ) ) );
check( 'what the honeypot catches goes nowhere', array() === array_filter( (array) $GLOBALS['stub_scheduled'], static fn( $event ) => Delivery::HOOK === $event[0] ) );

echo PHP_EOL . '# The screen' . PHP_EOL;

$GLOBALS['stub_routes'] = array();
( new IntegrationsController() )->register_routes();
$routes = array();
foreach ( $GLOBALS['stub_routes'] as $route ) {
	$routes[ $route[1] ] = $route[2];
}
$GLOBALS['stub_caps'] = array();
check( 'nobody but an administrator reads it', false === ( $routes['/integrations'][0]['permission_callback'] )() && false === ( $routes['/integrations/test']['permission_callback'] )() );
$GLOBALS['stub_caps'] = array( 'manage_options' );
check( 'an administrator does', true === ( $routes['/integrations'][0]['permission_callback'] )() );
check( 'only the five services can be tried', Delivery::SERVICES === ( $routes['/integrations/test']['args']['service']['enum'] ?? null ) );
unset( $GLOBALS['stub_caps'] );

$read = ( new IntegrationsController() )->read()->get_data();
check( 'what it reads has no key in it, and the log beside it', ! str_contains( (string) json_encode( $read ), 'xkeysib-1' ) && isset( $read['log']['webhook'] ) && array_key_exists( 'fluentcrm', $read ) );

$GLOBALS['stub_remote_steps'] = array( answer( 200, array( 'email' => 'owner@example.com' ) ) );
Integrations::save( array( 'brevo' => array( 'api_key' => 'xkeysib-1' ) ) );
$tried = Delivery::test( 'brevo' );
$call  = sent()[0] ?? array();
check( 'trying a service asks for the account, sending nobody’s address', 'https://api.brevo.com/v3/account' === ( $call['url'] ?? '' ) && 'GET' === ( $call['args']['method'] ?? '' ) && str_contains( $tried['message'], 'owner@example.com' ) );
check( 'and a service with nothing filled in is told what is missing', false === Delivery::test( 'mailerlite' )['ok'] && array() === sent() );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
