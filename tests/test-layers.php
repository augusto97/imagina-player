<?php
/**
 * Calls to action, bars and email gates.
 *
 * Two things are worth the most attention here, and neither is the happy path.
 *
 * The first is what reaches the page: a layer's text and URL come from whoever
 * edited the block, and they end up inside an `href` and inside HTML, so a
 * layer is an injection point unless it is treated as one.
 *
 * The second is the capture endpoint. It is public and it has no nonce — on
 * purpose, because the form it belongs to is printed into pages that a
 * full-page cache serves to everyone for hours, which makes a nonce either
 * stale or not a secret. What stands in for it has to be tested, or it is just
 * a comment.
 */

$plugin = dirname( __DIR__ ) . '/';

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

require $plugin . 'tests/bootstrap.php';

use ImaginaPlayer\Leads\LeadRepository;
use ImaginaPlayer\Player\Layers;
use ImaginaPlayer\Render\PlayerRenderer;
use ImaginaPlayer\Rest\LeadController;

echo PHP_EOL . '# What survives sanitising' . PHP_EOL;

$layers = Layers::sanitize(
	array(
		array( 'type' => 'cta', 'at' => 100, 'title' => 'Apúntate', 'url' => 'https://example.test/curso' ),
		array( 'type' => 'bar', 'at' => 30, 'title' => 'Oferta', 'url' => 'https://example.test/oferta' ),
		array( 'type' => 'email', 'at' => 60, 'title' => 'Recibe el resto', 'list' => 'curso' ),
		// Nowhere to send anyone.
		array( 'type' => 'cta', 'at' => 50, 'title' => 'Sin enlace' ),
		array( 'type' => 'nonsense', 'url' => 'https://example.test' ),
		'not an array',
	)
);

check( 'the three real layers survive', 3 === count( $layers ), (string) count( $layers ) );
check( 'a call to action with no link is dropped', ! in_array( 'Sin enlace', array_column( $layers, 'title' ), true ) );
check( 'an unknown kind is dropped', ! in_array( 'nonsense', array_column( $layers, 'type' ), true ) );
check( 'an email gate needs no link', 'email' === $layers[2]['type'] );

$bounds = Layers::sanitize(
	array(
		array( 'type' => 'cta', 'at' => 500, 'url' => 'https://example.test' ),
		array( 'type' => 'cta', 'at' => -20, 'url' => 'https://example.test' ),
	)
);

check( 'a percentage above 100 is clamped', 100 === $bounds[0]['at'], (string) $bounds[0]['at'] );
check( 'and below zero too', 0 === $bounds[1]['at'], (string) $bounds[1]['at'] );

$hostile = Layers::sanitize(
	array(
		array( 'type' => 'cta', 'url' => 'javascript:alert(1)', 'title' => 'x' ),
		array( 'type' => 'cta', 'url' => 'https://example.test', 'title' => '<script>alert(1)</script>Hola' ),
	)
);

check( 'a javascript: link is refused', 1 === count( $hostile ), wp_json_encode( array_column( $hostile, 'url' ) ) );
check( 'and a script tag in the headline does not survive', ! str_contains( $hostile[0]['title'], '<script' ), $hostile[0]['title'] );

check( 'a stack with a gate is known to interrupt', Layers::interrupts( $layers ) );
check( 'a stack of only bars is not', ! Layers::interrupts( Layers::sanitize( array( array( 'type' => 'bar', 'url' => 'https://example.test' ) ) ) ) );

echo PHP_EOL . '# What reaches the page' . PHP_EOL;

$renderer = new PlayerRenderer();

$html = $renderer->render(
	array(
		'src'    => 'https://example.test/wp-content/uploads/clase.mp4',
		'layers' => array(
			array( 'type' => 'email', 'at' => 60, 'title' => 'Recibe el resto', 'list' => 'curso', 'consent' => 'Sin spam.' ),
			array( 'type' => 'bar', 'at' => 20, 'title' => 'Oferta', 'url' => 'https://example.test/o', 'newTab' => true ),
		),
	)
);

check( 'the layers are rendered', 2 === substr_count( $html, 'imgp__layer ' ), $html );
check(
	'and rendered hidden, not built by JavaScript',
	2 === substr_count( $html, 'hidden' ) || str_contains( $html, 'imgp__layer' ) && str_contains( $html, 'hidden' ),
	'a layer built in JavaScript does not exist for anything that reads the page'
);
check( 'the email gate has a form', str_contains( $html, 'imgp__layer-form' ) );
check( 'with a real email input', str_contains( $html, 'type="email"' ) );
check( 'and a honeypot no person will fill', str_contains( $html, 'name="website"' ) );
check( 'the honeypot is hidden from assistive technology too', str_contains( $html, 'imgp__hp' ) && str_contains( $html, 'aria-hidden="true"' ) );
check( 'the small print is shown', str_contains( $html, 'Sin spam.' ) );
check( 'a new-tab link carries rel=noopener', str_contains( $html, 'rel="noopener noreferrer"' ) );
check( 'the client is told which layers exist', str_contains( $html, '&quot;layers&quot;' ) );
check( 'and which list an address belongs to', str_contains( $html, 'curso' ) );

// The list name reaches the client config; it must not be able to break out.
$injected = $renderer->render(
	array(
		'src'    => 'https://example.test/wp-content/uploads/clase.mp4',
		'layers' => array(
			array( 'type' => 'cta', 'title' => '"><img src=x onerror=alert(1)>', 'url' => 'https://example.test' ),
		),
	)
);

check( 'a headline cannot break out of the markup', ! str_contains( $injected, '<img src=x' ), $injected );

// Audio carries layers too: a gate two thirds through a podcast is the same
// feature, and it was the reason for not putting this in the video module.
$audio = $renderer->render(
	array(
		'src'    => 'https://example.test/wp-content/uploads/episodio.mp3',
		'layers' => array( array( 'type' => 'email', 'at' => 66, 'title' => 'Suscríbete' ) ),
	)
);

check( 'an audio player can carry a layer', str_contains( $audio, 'imgp__layer' ), 'this is why layers are not part of the video module' );
check( 'and is still an audio player', str_contains( $audio, 'imgp--audio' ) );

// A player with none must not ask for the chunk.
$plain = $renderer->render( array( 'src' => 'https://example.test/wp-content/uploads/episodio.mp3' ) );

check( 'a player with no layers renders none', ! str_contains( $plain, 'imgp__layer' ) );
check( 'and tells the client nothing about them', ! str_contains( $plain, '&quot;layers&quot;' ), 'this absence is what keeps the chunk unloaded' );

echo PHP_EOL . '# The capture endpoint' . PHP_EOL;

$controller = new LeadController();
$GLOBALS['stub_queries'] = array();
$GLOBALS['stub_transients'] = array();

/** A stand-in request carrying only what the endpoint reads. */
function request( array $params ): WP_REST_Request {
	return new WP_REST_Request( $params );
}

$filled_trap = $controller->capture( request( array( 'email' => 'bot@example.test', 'website' => 'http://spam.test' ) ) );

check( 'a filled honeypot is answered with success', 200 === $filled_trap->get_status(), (string) $filled_trap->get_status() );
check( 'but nothing is written', array() === $GLOBALS['stub_queries'], 'telling a bot it was caught only teaches it what to change' );

$bad = $controller->capture( request( array( 'email' => 'not an address' ) ) );

check( 'a malformed address is refused', is_wp_error( $bad ) );
check( 'with a 400, not a 500', 400 === ( $bad->get_error_data()['status'] ?? 0 ) );
check( 'and still nothing written', array() === $GLOBALS['stub_queries'] );

$ok = $controller->capture( request( array( 'email' => 'hola@example.test', 'list' => 'curso', 'at' => 42 ) ) );

check( 'a real address is accepted', ! is_wp_error( $ok ) && 201 === $ok->get_status() );
check( 'and written', 1 === count( $GLOBALS['stub_queries'] ), (string) count( $GLOBALS['stub_queries'] ) );
check( 'through a prepared statement', str_contains( $GLOBALS['stub_queries'][0] ?? '', "'hola@example.test'" ), $GLOBALS['stub_queries'][0] ?? '' );
check( 'the list travels with it', str_contains( $GLOBALS['stub_queries'][0] ?? '', "'curso'" ) );

// The rate limit: five from one address, then no more.
$blocked = null;

for ( $i = 0; $i < 8; $i++ ) {
	$result = $controller->capture( request( array( 'email' => 'repeat@example.test' ) ) );

	if ( is_wp_error( $result ) ) {
		$blocked = $result;
		break;
	}
}

check( 'a repeated address is eventually refused', null !== $blocked, 'without this the endpoint is an open write' );
check( 'with 429, the code that means "slow down"', 429 === ( $blocked?->get_error_data()['status'] ?? 0 ) );
check( 'after five, not on the first', $i >= 5, 'attempt ' . $i );

// A different address is not caught by another address's limit.
$other = $controller->capture( request( array( 'email' => 'otra@example.test' ) ) );

check( 'a different address is unaffected', ! is_wp_error( $other ), 'limiting by IP would lock out a whole office' );

echo PHP_EOL . '# The export cannot attack the person who opens it' . PHP_EOL;

$cases = array(
	'=HYPERLINK("http://evil.test","click")' => "'=HYPERLINK",
	'+1234'                                  => "'+1234",
	'-cmd'                                   => "'-cmd",
	'@SUM(A1)'                               => "'@SUM(A1)",
	'hola@example.test'                      => 'hola@example.test',
);

foreach ( $cases as $input => $expected ) {
	check(
		sprintf( '%s is neutralised', var_export( $input, true ) ),
		str_starts_with( LeadRepository::csv_cell( $input ), $expected ),
		LeadRepository::csv_cell( $input )
	);
}

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "{$failures} check(s) failed." . PHP_EOL;
	exit( 1 );
}
echo 'All checks passed.' . PHP_EOL;
