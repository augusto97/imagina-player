<?php
/**
 * Numbers a visitor can send that are numbers and still break things.
 *
 * `is_numeric( '1e999' )` is true. `(float) '1e999'` is INF. `max( 0.0, INF )`
 * is INF. And `json_encode( INF )` is `false`, with the error "Inf and NaN
 * cannot be JSON encoded" — which, inside a REST response, is a 500.
 *
 * So an anonymous visitor holding a valid waveform grant — every visitor to a
 * page with a player on it holds one — could store a duration of INF against a
 * track, and from then on the endpoint that hands that track's waveform to
 * every other visitor answers 500. Write-once made it permanent: no later
 * submission could replace it.
 *
 * The same shape applies to any float taken from a request. Each is checked
 * here by sending the value and asking the endpoint what it says.
 *
 * @package ImaginaPlayer
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Peaks\PeaksRepository;
use ImaginaPlayer\Peaks\PeaksToken;
use ImaginaPlayer\Rest\LeadController;
use ImaginaPlayer\Rest\PeaksController;

$failures = 0;

function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

/**
 * Can this response be sent at all?
 *
 * What WordPress does with a REST response is `wp_json_encode()` it, and if
 * that fails the visitor gets a 500 instead — so "does it encode" is the
 * question, not "does the array look right".
 */
function encodes( $data ): bool {
	return false !== json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the same call core makes.
}

$GLOBALS['stub_table_exists'] = 'wp_imagina_player_peaks';
$GLOBALS['stub_table_rows']   = array();
$GLOBALS['stub_meta']         = array();
$GLOBALS['stub_transients']   = array();

$controller = new PeaksController();

echo PHP_EOL . '# A duration of infinity, from a visitor' . PHP_EOL;

// The grant every visitor to a page with this track on it is given.
$token = PeaksToken::create( 'att_41', 400 );

$stored = $controller->store_peaks(
	new WP_REST_Request(
		array(
			'token'    => $token,
			'peaks'    => array( 0.2, 0.9, 0.4 ),
			'duration' => '1e999',
		)
	)
);

check(
	'the submission is accepted rather than erroring',
	$stored instanceof WP_REST_Response && $stored->get_status() < 300,
	$stored instanceof WP_Error ? $stored->get_error_message() : 'status ' . ( $stored instanceof WP_REST_Response ? $stored->get_status() : '?' )
);

$record = ( new PeaksRepository() )->get( 'att_41' );

check(
	'and what was stored is a finite number',
	null !== $record && is_finite( (float) $record['duration'] ),
	'stored duration: ' . var_export( $record['duration'] ?? null, true )
);

check(
	'within a length a recording could actually have',
	null !== $record && (float) $record['duration'] <= 24 * HOUR_IN_SECONDS,
	'stored duration: ' . var_export( $record['duration'] ?? null, true )
);

/*
 * The consequence that mattered. This is the endpoint every other visitor's
 * browser asks for the waveform, and it was answering 500 for as long as the
 * record existed.
 */
$served = $controller->get_peaks( new WP_REST_Request( array( 'key' => 'att_41' ) ) );

check(
	'and the waveform can still be handed to everybody else',
	200 === $served->get_status() && encodes( $served->get_data() ),
	encodes( $served->get_data() ) ? 'status ' . $served->get_status() : 'the response cannot be encoded: ' . json_last_error_msg()
);

echo PHP_EOL . '# And the same from an editor' . PHP_EOL;

$GLOBALS['stub_posts'][42] = array( 'type' => 'attachment' );

$editor = $controller->store_for_attachment(
	new WP_REST_Request(
		array(
			'attachmentId' => 42,
			'peaks'        => array( 0.5, 0.5 ),
			'duration'     => '-1e999',
		)
	)
);

$record = ( new PeaksRepository() )->get( 'att_42' );

check(
	'a negative infinity is stored as nothing, not as a negative',
	null !== $record && 0.0 === (float) $record['duration'],
	'stored duration: ' . var_export( $record['duration'] ?? null, true )
);

echo PHP_EOL . '# Amplitudes past the top' . PHP_EOL;

$GLOBALS['stub_meta'] = array();

$controller->store_peaks(
	new WP_REST_Request(
		array(
			'token'    => PeaksToken::create( 'att_43', 4 ),
			'peaks'    => array( '1e999', '-1e999', 0.5, 'nan' ),
			'duration' => 10,
		)
	)
);

$record = ( new PeaksRepository() )->get( 'att_43' );

if ( null !== $record ) {
	$values = PeaksRepository::decode( $record['peaks'] );

	check(
		'are clamped to the range a bar can be, and nothing is infinite',
		array() !== $values && max( $values ) <= 1.0 && min( $values ) >= 0.0
			&& array() === array_filter( $values, static fn( $v ) => ! is_finite( $v ) ),
		implode( ', ', $values )
	);
} else {
	// 'nan' is not numeric, so the whole submission is refused — which is
	// also fine, as long as nothing half-written is left behind.
	check( 'or the submission is refused whole, leaving nothing behind', true );
}

echo PHP_EOL . '# A position of infinity, on a captured address' . PHP_EOL;

$GLOBALS['stub_queries'] = array();

$leads = new LeadController();

$captured = $leads->capture(
	new WP_REST_Request(
		array(
			'email' => 'someone@example.com',
			'list'  => 'course',
			'at'    => '1e999',
		)
	)
);

check(
	'is not a server error',
	$captured instanceof WP_REST_Response && 201 === $captured->get_status(),
	$captured instanceof WP_Error ? $captured->get_error_message() : 'status ' . $captured->get_status()
);

$insert = (string) ( end( $GLOBALS['stub_queries'] ) ?: '' );

check(
	'and the number written to the database is finite',
	'' !== $insert && ! preg_match( '/\binf\b|\bnan\b/i', $insert ),
	substr( $insert, 0, 160 )
);

echo PHP_EOL;
echo 0 === $failures ? 'All hostile-input checks passed.' . PHP_EOL : "{$failures} FAILURE(S)" . PHP_EOL;

exit( 0 === $failures ? 0 : 1 );
