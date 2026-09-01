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

echo PHP_EOL . '# A large file does not become one long request' . PHP_EOL;

/*
 * The report that closed this out: some files worked and others did not, on
 * the same site, and the failing one said only "the server answered 502".
 *
 * That 502 is not from here — every refusal this endpoint makes carries a
 * reason, and that one carried none. It is the web server's, because the whole
 * file used to come through in a single call: fetched to a temporary file and
 * then read back and echoed, two full transfers of the recording inside one
 * PHP request with no time limit raised. Where `max_execution_time` is thirty
 * seconds, a big enough file does not finish, PHP is killed, and the web
 * server answers for it. A small file finished in time. That is the whole of
 * why it looked arbitrary.
 */
check(
	'the doorway serves a slice when one is asked for',
	str_contains( $controller, "'Range' => 'bytes='" )
		&& str_contains( $controller, 'Content-Range' ),
	'without this a fifty megabyte file is one request that a host can kill'
);

check(
	'and says so on every answer, so the browser knows it may ask',
	str_contains( $controller, "header( 'Accept-Ranges: bytes' )" )
);

check(
	'it only claims a partial answer when the far end really gave one',
	str_contains( $controller, '206 === (int) wp_remote_retrieve_response_code( $body )' ),
	'a server that ignores Range sends the whole file, and stitching those would repeat the beginning'
);

/*
 * And the size limit has to be about what was asked for. A three hundred
 * megabyte recording is refused as a whole and perfectly fine four megabytes
 * at a time; checking the whole-file size first would refuse it either way.
 */
check(
	'a file too big to fetch whole can still be fetched in slices',
	str_contains( $controller, 'null === $range && $length > self::PROXY_MAX_BYTES' )
);

check(
	'and it asks for more time as well, since that costs nothing where it is allowed',
	str_contains( $controller, 'set_time_limit( 120 )' )
);

$measure = (string) file_get_contents( $root . '/assets/src/shared/measure.ts' );

check(
	'the browser asks for slices',
	str_contains( $measure, 'readInSlices' )
);

/*
 * But not across origins. A `Range` header makes a request non-simple and the
 * browser asks permission first; a media host that happily serves a plain GET
 * to another domain will often refuse that — so asking would break the files
 * that work in order to help the ones that do not.
 */
check(
	'but only from this site, where there is no permission to ask for',
	str_contains( $measure, 'sameOrigin( url )' ),
	'a Range header on a cross-origin request triggers a preflight'
);

$sliced = run_proxy(
	'https://media.example.com/lesson.mp3',
	array( 'code' => 200, 'headers' => array( 'content-type' => 'audio/mpeg', 'content-length' => (string) ( 400 * 1024 * 1024 ) ) )
);

check(
	'a file larger than the whole-file limit is refused when asked for whole',
	str_contains( $sliced['output'], 'too-large' ),
	substr( $sliced['output'], 0, 120 )
);

echo PHP_EOL . '# Asking this server what it sees' . PHP_EOL;

/*
 * Every diagnosis in this file so far was made from outside: a status code
 * read in a browser, and a story told about it. Twice that story was wrong,
 * and the second time it was wrong in a way that sent somebody to change a
 * server setting that had nothing to do with anything.
 *
 * Whether the file's own host refuses this site, whether something in front of
 * WordPress refuses the request before PHP sees it, what PHP is actually
 * permitted to do — none of it is visible from a browser. So the server is
 * asked, and it answers with what it found rather than with a conclusion.
 */
$GLOBALS['stub_remote_steps'] = array(
	array( 'code' => 403, 'headers' => array( 'content-type' => 'audio/mpeg' ), 'body' => '' ),
	array( 'code' => 403, 'headers' => array(), 'body' => '' ),
);

$controller_instance = new ImaginaPlayer\Rest\PeaksController();

$report = $controller_instance->diagnose(
	new WP_REST_Request( array( 'src' => 'https://media.example.com/lesson.mp3' ) )
)->get_data();

check( 'the check answers', is_array( $report ) && isset( $report['steps'] ) );

$steps = array_column( (array) ( $report['steps'] ?? array() ), null, 'step' );

check(
	'it says whether the address is one this site will fetch at all',
	isset( $steps['url'] ) && true === $steps['url']['ok']
);

check(
	'it reports the status the file’s own host gave this server',
	isset( $steps['head'] ) && 403 === ( $steps['head']['status'] ?? 0 ),
	'this is the fact that separates "the host refuses us" from every other cause'
);

check(
	'and asks whether that host will serve part of a file',
	isset( $steps['range'] ),
	'fetching a large file in pieces depends entirely on the answer'
);

/*
 * The environment, because a notice about `popen` that will not go away is
 * almost always a php.ini edited for one SAPI while WordPress runs under
 * another — and that is settled by evidence, not by argument.
 */
$environment = (array) ( $report['environment'] ?? array() );

foreach ( array( 'sapi', 'maxExecutionTime', 'memoryLimit', 'popenDisabled', 'disableFunctions' ) as $key ) {
	check( "it reports {$key}", array_key_exists( $key, $environment ), $key );
}

check(
	'and what it thinks of ffmpeg, beside the reason',
	isset( $environment['ffmpeg']['state'] )
);

/*
 * A success even when everything it describes failed. An endpoint that goes
 * down with the thing it is diagnosing is one more mystery rather than an
 * answer — and reaching it at all is itself the test of whether requests of
 * this shape get through to PHP.
 */
$GLOBALS['stub_remote_steps'] = array();
$GLOBALS['stub_remote'] = new WP_Error( 'http_request_failed', 'Could not resolve host' );

$broken = $controller_instance->diagnose(
	new WP_REST_Request( array( 'src' => 'https://nowhere.example/lesson.mp3' ) )
);

check(
	'it still answers when the file cannot be reached at all',
	200 === $broken->get_status()
);

$broken_steps = array_column( (array) ( $broken->get_data()['steps'] ?? array() ), null, 'step' );

check(
	'and passes on what went wrong in words',
	isset( $broken_steps['head']['error'] )
		&& str_contains( (string) $broken_steps['head']['error'], 'resolve' ),
	(string) ( $broken_steps['head']['error'] ?? 'nothing' )
);

$GLOBALS['stub_remote'] = null;

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
