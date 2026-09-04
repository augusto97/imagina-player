<?php
/**
 * The preview frame reports its own height.
 *
 * The editor's block preview and the settings screen's live preview both hold
 * the player in an iframe with `sandbox="allow-scripts"`, and both measured it
 * by reading `contentDocument.body.scrollHeight`. Under that sandbox the
 * document is unreachable from outside, so the measuring measured nothing and
 * every preview kept its starting height — 150 pixels, into which an audio
 * player happens to fit and a 16:9 video does not. Reported as "in the editor
 * the videos do not take the right height".
 *
 * Checked in a real Chromium, with a real sandboxed frame: that the document
 * really is unreadable, that the frame's own report arrives, that it follows a
 * change in size, and that a report from any other frame is ignored.
 */

require __DIR__ . '/bootstrap.php';

$root = dirname( __DIR__ );

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

function find_browser(): string {
	$candidates = array_filter(
		array_merge(
			array( (string) getenv( 'CHROMIUM_BIN' ) ),
			glob( '/opt/pw-browsers/chromium_headless_shell-*/chrome-linux/headless_shell' ) ?: array(),
			glob( '/opt/pw-browsers/chromium-*/chrome-linux/chrome' ) ?: array()
		)
	);

	foreach ( $candidates as $candidate ) {
		if ( '' !== $candidate && is_executable( $candidate ) ) {
			return $candidate;
		}
	}

	return '';
}

$workdir = $root . '/build/.preview-height-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

exec(
	sprintf(
		'cd %s && npx tsc assets/src/shared/frame-height.ts --outDir %s --module commonjs --target es2020 --moduleResolution node --esModuleInterop --skipLibCheck 2>&1',
		escapeshellarg( $root ),
		escapeshellarg( $workdir )
	),
	$tsc_output,
	$tsc_status
);

check( 'the module compiles on its own', 0 === $tsc_status && is_readable( $workdir . '/frame-height.js' ), implode( ' ', $tsc_output ) );

if ( ! is_readable( $workdir . '/frame-height.js' ) ) {
	exit( 1 );
}

echo PHP_EOL . '# What counts as a report' . PHP_EOL;

$shape_script = $workdir . '/shape.js';
file_put_contents(
	$shape_script,
	<<<'JS'
const m = require( process.argv[ 2 ] );
const out = {};
out.valid    = m.reportedHeight( { type: m.FRAME_HEIGHT_TYPE, height: 371 } );
out.rounded  = m.reportedHeight( { type: m.FRAME_HEIGHT_TYPE, height: 362.4 } );
out.tiny     = m.reportedHeight( { type: m.FRAME_HEIGHT_TYPE, height: 3 } );
out.huge     = m.reportedHeight( { type: m.FRAME_HEIGHT_TYPE, height: 1e9 } );
out.string   = m.reportedHeight( { type: m.FRAME_HEIGHT_TYPE, height: '500' } );
out.nan      = m.reportedHeight( { type: m.FRAME_HEIGHT_TYPE, height: NaN } );
out.other    = m.reportedHeight( { type: 'something-else', height: 500 } );
out.nothing  = m.reportedHeight( null );
out.text     = m.reportedHeight( 'imgp-preview-height' );
out.script   = m.FRAME_HEIGHT_SCRIPT;
process.stdout.write( JSON.stringify( out ) );
JS
);

$shape = json_decode( (string) shell_exec( sprintf( 'node %s %s 2>&1', escapeshellarg( $shape_script ), escapeshellarg( $workdir . '/frame-height.js' ) ) ), true );

check( 'a report carries its height', 371 === ( $shape['valid'] ?? null ) );
check( 'rounded up, so a fraction never grows a scrollbar', 363 === ( $shape['rounded'] ?? null ) );
check( 'never shorter than a player', 40 === ( $shape['tiny'] ?? null ) );
check( 'never a mile tall, whatever the frame says', 4000 === ( $shape['huge'] ?? null ) );
check( 'a height that is not a number is not a report', array_key_exists( 'string', $shape ) && null === $shape['string'] && array_key_exists( 'nan', $shape ) && null === $shape['nan'] );
check( 'nor is any other message', array_key_exists( 'other', $shape ) && null === $shape['other'] && null === $shape['nothing'] && null === $shape['text'] );
check( 'the frame’s script posts to its parent', str_contains( (string) ( $shape['script'] ?? '' ), 'window.parent.postMessage' ) && str_contains( (string) ( $shape['script'] ?? '' ), 'imgp-preview-height' ) );

echo PHP_EOL . '# In a real sandboxed frame' . PHP_EOL;

$browser = find_browser();

if ( '' === $browser ) {
	echo 'SKIP  no Chromium found; the frame itself not checked' . PHP_EOL;
} else {
	$page_script = $workdir . '/frame.js';
	file_put_contents(
		$page_script,
		<<<'JS'
const { chromium } = require( 'playwright' );
const fs = require( 'fs' );
const m = require( process.argv[ 2 ] );
const modulePath = process.argv[ 2 ];

( async () => {
	const browser = await chromium.launch( { executablePath: process.env.CHROMIUM_BIN } );
	const page = await browser.newPage( { viewport: { width: 900, height: 700 } } );

	// The compiled module, made available on the page as `fh`.
	const moduleSource = fs.readFileSync( modulePath, 'utf8' );
	const inner = `<!doctype html><html><body style="margin:0"><div id="box" style="height:700px"></div>` +
		`<script>setTimeout(function(){document.getElementById("box").style.height="900px";},400);</script>` +
		`<script>${ m.FRAME_HEIGHT_SCRIPT }</script></body></html>`;
	const other = `<!doctype html><html><body><script>setInterval(function(){window.parent.postMessage({type:"${ m.FRAME_HEIGHT_TYPE }",height:3000},"*");},50);</script></body></html>`;
	const silent = `<!doctype html><html><body><div style="height:700px"></div></body></html>`;

	await page.setContent( `<!doctype html><html><body>
		<script>var exports = {}; ${ moduleSource }; window.fh = exports;</script>
		<iframe id="a" sandbox="allow-scripts" style="height:150px;width:600px" srcdoc="${ inner.replace( /"/g, '&quot;' ) }"></iframe>
		<iframe id="b" sandbox="allow-scripts" style="height:150px;width:600px" srcdoc="${ other.replace( /"/g, '&quot;' ) }"></iframe>
		<iframe id="c" sandbox="allow-scripts" style="height:150px;width:600px" srcdoc="${ silent.replace( /"/g, '&quot;' ) }"></iframe>
		<iframe id="canvas" style="height:300px;width:700px" srcdoc="${ ( '<!doctype html><html><body><iframe id=&quot;d&quot; sandbox=&quot;allow-scripts&quot; style=&quot;height:150px;width:600px&quot; srcdoc=&quot;' + inner.replace( /&/g, '&amp;' ).replace( /"/g, '&amp;quot;' ) + '&quot;></iframe></body></html>' ) }"></iframe>
		<script>
			window.reports = { a: [], c: [] };
			window.fh.listenForFrameHeight( () => document.getElementById( 'a' ), ( h ) => { window.reports.a.push( h ); document.getElementById( 'a' ).style.height = h + 'px'; } );
			window.fh.listenForFrameHeight( () => document.getElementById( 'c' ), ( h ) => window.reports.c.push( h ) );
			// The editor's arrangement: the block, and its preview frame, live in the
			// editor's canvas iframe, while the editor's code runs up here.
			window.reports.d = [];
			document.getElementById( 'canvas' ).addEventListener( 'load', () => {
				const d = document.getElementById( 'canvas' ).contentDocument.getElementById( 'd' );
				window.fh.listenForFrameHeight( () => d, ( h ) => window.reports.d.push( h ) );
			} );
		</script>
	</body></html>` );

	await page.waitForTimeout( 1200 );

	const result = await page.evaluate( () => ( {
		readable: document.getElementById( 'a' ).contentDocument !== null,
		reports: window.reports,
		frameHeight: document.getElementById( 'a' ).getBoundingClientRect().height,
	} ) );

	process.stdout.write( JSON.stringify( result ) );
	await browser.close();
} )().catch( ( e ) => { process.stderr.write( String( e ) ); process.exit( 1 ); } );
JS
	);

	$raw = (string) shell_exec(
		sprintf(
			'cd %s && CHROMIUM_BIN=%s NODE_PATH=%s node %s %s 2>&1',
			escapeshellarg( $root ),
			escapeshellarg( $browser ),
			escapeshellarg( $root . '/node_modules' ),
			escapeshellarg( $page_script ),
			escapeshellarg( $workdir . '/frame-height.js' )
		)
	);
	$result = json_decode( $raw, true );

	check( 'the page ran', is_array( $result ), substr( $raw, 0, 300 ) );

	if ( is_array( $result ) ) {
		$a = $result['reports']['a'] ?? array();

		check( 'the sandboxed document cannot be read from outside — which is why measuring it never worked', false === ( $result['readable'] ?? true ) );
		check( 'the frame reports its height', count( $a ) >= 1 && $a[0] >= 700 && $a[0] <= 720, json_encode( $a ) );
		check( 'and again when its content grows', end( $a ) >= 900 && end( $a ) <= 920, json_encode( $a ) );
		check( 'so the frame is as tall as its content', ( $result['frameHeight'] ?? 0 ) >= 900, (string) ( $result['frameHeight'] ?? '' ) );
		check( 'a report from another frame is ignored, however often it insists', ! in_array( 3000, $a, true ) && ! in_array( 3000, $result['reports']['c'] ?? array(), true ), json_encode( $result['reports'] ) );
		check( 'a frame without the script reports nothing', array() === ( $result['reports']['c'] ?? array( 1 ) ) );

		$d = $result['reports']['d'] ?? array();
		check( 'a frame inside the editor’s canvas frame is heard from the editor above it', count( $d ) >= 1 && end( $d ) >= 900, json_encode( $d ) );
	}
}

echo PHP_EOL . '# And both previews use it' . PHP_EOL;

foreach ( array( 'editor', 'admin' ) as $bundle ) {
	$js = (string) file_get_contents( $root . '/build/' . $bundle . '.js' );

	check( "the {$bundle} bundle carries the frame’s script", str_contains( $js, 'imgp-preview-height' ) && str_contains( $js, 'window.parent.postMessage' ) );
	check( "and no longer tries to read the frame’s document", ! str_contains( $js, 'contentDocument' ) );
}

exec( 'rm -rf ' . escapeshellarg( $workdir ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All preview-height checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
