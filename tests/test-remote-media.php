<?php
/**
 * A file on somebody else's domain, and why it could not be measured.
 *
 * A waveform is measured on the server where there is ffmpeg, and in the
 * editor's own browser where there is not. A browser cannot read a file from
 * another domain unless that domain says it may, and media hosts mostly do
 * not — so there is a doorway on this site that fetches the file and hands it
 * over same-origin.
 *
 * A report showed that doorway had never worked, and that the failure said the
 * wrong thing three times over:
 *
 * 1. The doorway's URL carries a REST nonce, taken from `window.wpApiSettings`.
 *    The editor script never declared `wp-api-request`, which is what puts that
 *    object on the page — so where nothing else happened to enqueue it the
 *    nonce was empty and WordPress refused the request. Silently: the file just
 *    never got a waveform.
 * 2. When the doorway did answer, it answered with a bare status. The file's
 *    own server refusing *us* looked exactly like this site refusing us, and
 *    those have completely different answers.
 * 3. And the player on the front end called every failure "too large", so a
 *    file a browser is not allowed to read sent people to the size settings.
 *
 * None of this throws. Every one of them ends in a flat progress bar, which is
 * also what a file with no waveform looks like when everything is working.
 */

require __DIR__ . '/bootstrap.php';

$root     = dirname( __DIR__ );
$failures = 0;

function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

echo PHP_EOL . '# The doorway can be opened at all' . PHP_EOL;

/*
 * The nonce. This is the one that made the whole path dead rather than wrong:
 * the URL is built by hand, because what it produces has to be something an
 * audio decoder can be pointed at, and a hand-built REST URL needs the nonce
 * that `apiFetch` would otherwise add for you.
 */
$assets = (string) file_get_contents( $root . '/src/Assets.php' );

check(
	'the editor asks for the script that carries the REST nonce',
	str_contains( $assets, "'wp-api-request'" ),
	'without it window.wpApiSettings may not exist, and the request is refused'
);

check(
	'and it is added to what the build worked out rather than replacing it',
	(bool) preg_match( "/\\\$editor\\['dependencies'\\]\\[\\] = 'wp-api-request';/", $assets )
);

$notice = (string) file_get_contents( $root . '/assets/src/editor/waveform-notice.tsx' );

check(
	'the editor still reads the nonce from there',
	str_contains( $notice, 'wpApiSettings?.nonce' )
);

echo PHP_EOL . '# The doorway says which step gave up' . PHP_EOL;

$controller = (string) file_get_contents( $root . '/src/Rest/PeaksController.php' );

check(
	'a refusal carries a reason',
	str_contains( $controller, 'X-Imagina-Reason' )
);

/*
 * And the reason distinguishes the two cases that matter. "The file's own
 * server said no to this site" is a setting on that service — hotlink
 * protection, a signed-URL rule — and nothing in this plugin can change it.
 * Reporting it as a generic failure sends somebody looking in the wrong place.
 */
foreach ( array(
	'upstream-unreachable' => 'this site could not reach the file at all',
	'not-media'            => 'the address is not a media file',
	'too-large'            => 'the file is bigger than this site will fetch',
	'bad-url'              => 'the address was refused as unsafe',
) as $tag => $what ) {
	check(
		"it names the case where {$what}",
		str_contains( $controller, "'" . $tag . "'" ),
		$tag
	);
}

check(
	'and passes the status the remote server gave, rather than hiding it',
	str_contains( $controller, "'upstream-' . " ),
	'a 403 from a CDN is the whole answer, and it was being thrown away'
);

$measure = (string) file_get_contents( $root . '/assets/src/shared/measure.ts' );

check(
	'the browser reads that reason back',
	str_contains( $measure, 'x-imagina-reason' )
);

echo PHP_EOL . '# Every reason has words of its own' . PHP_EOL;

/*
 * The point of all of the above. Each tag the server can send has to turn into
 * a sentence, or it falls through to the catch-all and the work is wasted.
 */
preg_match_all( "/'X-Imagina-Reason: ' \\. \\\$reason/", $controller, $sent );

foreach ( array( 'proxy-upstream-', 'proxy-not-media', 'proxy-too-large', 'proxy-bad-url' ) as $tag ) {
	check(
		"the editor has something to say about {$tag}",
		str_contains( $notice, "'" . $tag . "'" ),
		$tag
	);
}

check(
	'and it points at the domain hosting the file, which is the only place that can fix it',
	str_contains( $notice, 'hotlink' )
);

/*
 * The failure that started this. Reporting the direct attempt is reporting
 * that a cross-origin file is cross-origin — true, and no use: the doorway
 * exists precisely for that, so its failure is the one worth hearing.
 */
check(
	'a failure through the doorway is reported over the direct attempt',
	str_contains( $notice, 'throw viaProxy;' ),
	'reporting the direct one names the thing that was meant to be worked around'
);

echo PHP_EOL . '# And on the front end' . PHP_EOL;

$peaks  = (string) file_get_contents( $root . '/assets/src/frontend/peaks.ts' );
$player = (string) file_get_contents( $root . '/assets/src/frontend/player.ts' );

/*
 * `probeSize` returns -1 when nothing could be learned about the file, and that
 * was folded into the size check — so a file the browser is not allowed to read
 * was reported as too large to read, which is a different problem with a
 * different answer and a settings page that cannot help.
 */
check(
	'a file that cannot be reached is not called too large',
	str_contains( $peaks, "'unreachable'" )
);

check(
	'and a forbidden answer is recognised as one',
	str_contains( $peaks, '403 === response.status' )
);

check(
	'the console says which of them happened',
	str_contains( $player, 'unreachable:' ) && str_contains( $player, "'too-large':" )
);

check(
	'and sends somebody to the block editor, where the doorway is',
	str_contains( $player, 'block editor' ),
	'the front end has no way through, so the advice has to point somewhere that does'
);

/*
 * The block preview asks for no download at all. That used to come out as
 * "too large" in every editor's console, for every file, whatever its size.
 */
check(
	'a preview that asks for no download is not told the file is too large',
	str_contains( $peaks, "'not-attempted'" )
);

echo PHP_EOL . '# The doorway, actually run' . PHP_EOL;

/*
 * Everything above reads the source. This runs the endpoint, because a header
 * that is written and never sent is the same as no header.
 *
 * In its own process each time: the handler ends in `exit`, which is right for
 * something that streams a file and wrong for something a test wants to call
 * twice.
 *
 * What is read here is the body, because a command line cannot see a header.
 * The tag in the body and the tag in the header are the same variable, so this
 * proves the refusal was reached with the right reason — which is where the
 * mistakes are — rather than that the header left the building.
 */
function run_proxy( string $url, array $remote ): array {
	$script = <<<'PHP'
<?php
require %s;
$GLOBALS['stub_remote'] = %s;
$controller = new ImaginaPlayer\Rest\PeaksController();
$controller->proxy( new WP_REST_Request( array( 'src' => %s ) ) );
PHP;

	$file = sys_get_temp_dir() . '/imgp-proxy-' . getmypid() . '.php';

	file_put_contents(
		$file,
		sprintf(
			$script,
			var_export( dirname( __DIR__ ) . '/tests/bootstrap.php', true ),
			var_export( $remote, true ),
			var_export( $url, true )
		)
	);

	$output = array();
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $output, $status );

	@unlink( $file );

	return array( 'status' => $status, 'output' => implode( "\n", $output ) );
}

/*
 * The case that was reported: the file is on a bucket or a CDN, and that
 * service says no to this site as well. Its status is the whole answer, and it
 * was being replaced by a bare 502.
 */
$refused = run_proxy(
	'https://media.example.com/lesson.mp3',
	array( 'code' => 403, 'headers' => array( 'content-type' => 'audio/mpeg' ) )
);

check(
	'a remote refusal comes back naming the remote status',
	str_contains( $refused['output'], 'upstream-403' ),
	substr( $refused['output'], 0, 160 )
);

$not_media = run_proxy(
	'https://media.example.com/page.html',
	array( 'code' => 200, 'headers' => array( 'content-type' => 'text/html' ) )
);

check(
	'an address that is not media says so',
	str_contains( $not_media['output'], 'not-media' ),
	substr( $not_media['output'], 0, 160 )
);

$bad = run_proxy( 'http://127.0.0.1/secret.mp3', array( 'code' => 200 ) );

check(
	'and an address on this machine is refused before anything is fetched',
	str_contains( $bad['output'], 'bad-url' ),
	substr( $bad['output'], 0, 160 )
);

echo PHP_EOL . '# The preview stops asking the wrong address' . PHP_EOL;

/*
 * Both previews built their runtime by hand with an empty REST root, so the
 * player inside them asked for `/peaks` against the site root and collected a
 * 404 on every editor load.
 */
check(
	'the previews are given the real REST root',
	str_contains( $assets, "'restUrl'     => esc_url_raw( rest_url(" )
);

foreach ( array( 'assets/src/editor/preview.tsx', 'assets/src/admin/PreviewFrame.tsx' ) as $file ) {
	$source = (string) file_get_contents( $root . '/' . $file );

	check(
		basename( $file ) . ' no longer hard-codes an empty one',
		! preg_match( '/restUrl\s*:\s*""/', $source ),
		'an empty root makes every peaks request a 404 against the site root'
	);
}

echo PHP_EOL;
echo 0 === $failures ? 'All remote-media checks passed.' . PHP_EOL : "{$failures} FAILURE(S)" . PHP_EOL;

exit( 0 === $failures ? 0 : 1 );
