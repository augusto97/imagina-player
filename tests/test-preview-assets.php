<?php
/**
 * The preview frame gets its stylesheet and script as text, not as links.
 *
 * The frame is sandboxed, so to the server its requests come from nowhere:
 * `Origin: null`, a cross-site fetch with no referrer. A host that refuses
 * that for static files — hotlink protection, a Cross-Origin-Resource-Policy
 * header, a firewall rule — left the preview with the browser's bare controls
 * and no styling, and `ERR_BLOCKED_BY_RESPONSE.NotSameOrigin 403` in the
 * console for each of the plugin's files. Seen on a real site.
 *
 * Checked against a server that does exactly that, in a real Chromium: the
 * old way is shown to fail on it, and the new way to work.
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

$workdir = $root . '/build/.preview-assets-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

exec(
	sprintf(
		'cd %s && npx tsc assets/src/shared/frame-assets.ts --outDir %s --module commonjs --target es2020 --moduleResolution node --esModuleInterop --skipLibCheck --lib es2020,dom 2>&1',
		escapeshellarg( $root ),
		escapeshellarg( $workdir )
	),
	$tsc_output,
	$tsc_status
);

check( 'the module compiles on its own', 0 === $tsc_status && is_readable( $workdir . '/frame-assets.js' ), implode( ' ', $tsc_output ) );

if ( ! is_readable( $workdir . '/frame-assets.js' ) ) {
	exit( 1 );
}

echo PHP_EOL . '# Text that goes inside a script or style element' . PHP_EOL;

$shape = json_decode( (string) shell_exec( sprintf( 'node -e %s %s 2>&1', escapeshellarg( 'const m=require(process.argv[1]);process.stdout.write(JSON.stringify({a:m.escapeInline("x</script><script>alert(1)</script>"),b:m.escapeInline("p{}</style><style>"),c:m.escapeInline("<!-- x"),d:m.escapeInline("plain")}))' ), escapeshellarg( $workdir . '/frame-assets.js' ) ) ), true );

check( 'a closing script tag inside the script cannot end it', is_array( $shape ) && ! str_contains( $shape['a'], '</script' ) && str_contains( $shape['a'], '<\\/script' ), json_encode( $shape ) );
check( 'nor a closing style tag inside the stylesheet', is_array( $shape ) && ! str_contains( $shape['b'], '</style' ) );
check( 'nor a comment opener', is_array( $shape ) && ! str_contains( $shape['c'], '<!--' ) );
check( 'and ordinary text is untouched', is_array( $shape ) && 'plain' === $shape['d'] );

echo PHP_EOL . '# Against a host that refuses the frame’s requests' . PHP_EOL;

$browser = find_browser();

if ( '' === $browser ) {
	echo 'SKIP  no Chromium found; the frame itself not checked' . PHP_EOL;
} else {
	$page_script = $workdir . '/frame.js';
	file_put_contents(
		$page_script,
		<<<'JS'
const { chromium } = require( 'playwright' );
const http = require( 'http' );
const fs = require( 'fs' );
const modulePath = process.argv[ 2 ];
const moduleSource = fs.readFileSync( modulePath, 'utf8' );

// The host: a static file is refused when the request comes from nowhere —
// which is what a sandboxed frame sends — exactly as hotlink protection or a
// Cross-Origin-Resource-Policy header does. The page itself is always served.
const server = http.createServer( ( req, res ) => {
	const url = new URL( req.url, 'http://x' );
	const fromNowhere = 'cross-site' === req.headers[ 'sec-fetch-site' ];

	if ( '/chunk.js' === url.pathname ) {
		if ( fromNowhere ) { res.writeHead( 403 ); return res.end( 'Forbidden' ); }
		res.writeHead( 200, { 'Content-Type': 'text/javascript' } );
		return res.end( 'document.body.setAttribute("data-chunk", document.body.getAttribute("data-ran") ? "after-main" : "before-main");' );
	}

	if ( '/style.css' === url.pathname || '/frontend.js' === url.pathname ) {
		if ( fromNowhere ) {
			res.writeHead( 403, { 'Cross-Origin-Resource-Policy': 'same-origin' } );
			return res.end( 'Forbidden' );
		}
		if ( '/style.css' === url.pathname ) {
			res.writeHead( 200, { 'Content-Type': 'text/css' } );
			return res.end( '.imgp { background: rgb(1, 2, 3); height: 40px; }' );
		}
		res.writeHead( 200, { 'Content-Type': 'text/javascript' } );
		return res.end( 'document.body.setAttribute("data-ran","1");' );
	}

	if ( '/missing.css' === url.pathname ) {
		res.writeHead( 404 );
		return res.end();
	}

	res.writeHead( 200, { 'Content-Type': 'text/html' } );
	res.end( `<!doctype html><html><body>
		<script>var exports = {}; ${ moduleSource }; window.fa = exports;</script>
		<iframe id="old" sandbox="allow-scripts" srcdoc="&lt;!doctype html&gt;&lt;html&gt;&lt;head&gt;&lt;link rel=&quot;stylesheet&quot; href=&quot;/style.css&quot;&gt;&lt;/head&gt;&lt;body&gt;&lt;div class=&quot;imgp&quot;&gt;&lt;/div&gt;&lt;script src=&quot;/frontend.js&quot;&gt;&lt;/script&gt;&lt;/body&gt;&lt;/html&gt;"></iframe>
		<iframe id="new" sandbox="allow-scripts"></iframe>
		<script>
			window.fa.inlineAssets( [ '/style.css' ], '/frontend.js', [ '/chunk.js' ] ).then( ( inlined ) => {
				window.inlined = inlined;
				document.getElementById( 'new' ).srcdoc = '<!doctype html><html><head>' + inlined.head + '</head><body><div class="imgp"></div>' + inlined.tail + '</body></html>';
			} );
			window.fa.inlineAssets( [ '/missing.css' ], '/frontend.js' ).then( ( inlined ) => { window.partial = inlined; } );
		</script>
	</body></html>` );
} );

( async () => {
	await new Promise( ( r ) => server.listen( 0, '127.0.0.1', r ) );
	const base = 'http://127.0.0.1:' + server.address().port;
	const browser = await chromium.launch( { executablePath: process.env.CHROMIUM_BIN } );
	const page = await browser.newPage();
	await page.goto( base + '/' );
	await page.waitForFunction( () => window.inlined && window.partial && document.getElementById( 'new' ).srcdoc );
	await page.waitForTimeout( 800 );

	const read = async ( id ) => {
		const handle = await page.$( '#' + id );
		const f = await handle.contentFrame();
		return f.evaluate( () => ( {
			background: getComputedStyle( document.querySelector( '.imgp' ) ).backgroundColor,
			ran: document.body.getAttribute( 'data-ran' ),
			chunk: document.body.getAttribute( 'data-chunk' ),
		} ) );
	};

	const result = {
		old: await read( 'old' ),
		fresh: await read( 'new' ),
		inlined: await page.evaluate( () => ( { complete: window.inlined.complete, headHasStyle: window.inlined.head.startsWith( '<style>' ), tailHasScript: window.inlined.tail.startsWith( '<script>' ) && ! window.inlined.tail.includes( 'src=' ) } ) ),
		partial: await page.evaluate( () => ( { complete: window.partial.complete, head: window.partial.head } ) ),
	};

	process.stdout.write( JSON.stringify( result ) );
	await browser.close();
	server.close();
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
			escapeshellarg( $workdir . '/frame-assets.js' )
		)
	);
	$result = json_decode( $raw, true );

	check( 'the page ran', is_array( $result ), substr( $raw, 0, 400 ) );

	if ( is_array( $result ) ) {
		check( 'linked from inside the frame, the stylesheet never arrives — the fault as reported', 'rgb(1, 2, 3)' !== ( $result['old']['background'] ?? '' ), json_encode( $result['old'] ) );
		check( 'nor does the script', '1' !== ( $result['old']['ran'] ?? '' ) );
		check( 'fetched by the page and written into the frame, the stylesheet applies', 'rgb(1, 2, 3)' === ( $result['fresh']['background'] ?? '' ), json_encode( $result['fresh'] ) );
		check( 'and the script runs', '1' === ( $result['fresh']['ran'] ?? '' ) );
		check( 'and so does a piece it would have loaded on demand, after it', 'after-main' === ( $result['fresh']['chunk'] ?? '' ), json_encode( $result['fresh'] ) );
		check( 'with both written as text, not as addresses', ! empty( $result['inlined']['complete'] ) && ! empty( $result['inlined']['headHasStyle'] ) && ! empty( $result['inlined']['tailHasScript'] ) );
		check( 'a file the page cannot fetch either is left as a link, and said to be', empty( $result['partial']['complete'] ) && str_contains( (string) ( $result['partial']['head'] ?? '' ), '<link rel="stylesheet" href="/missing.css">' ), json_encode( $result['partial'] ) );
	}
}

echo PHP_EOL . '# The plugin says where the pieces are, and which they are' . PHP_EOL;

$handed = ImaginaPlayer\Assets::preview_assets();
$on_disk = array_map( 'basename', glob( $root . '/build/imagina-*.js' ) ?: array() );
$named   = array_map( static fn( string $url ): string => basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ), (array) ( $handed['frontendChunks'] ?? array() ) );

check( 'the previews are told where the on-demand pieces live', str_ends_with( (string) ( $handed['assetUrl'] ?? '' ), '/build/' ), (string) ( $handed['assetUrl'] ?? '' ) );
check( 'and are handed every piece there is', array() === array_diff( array_diff( $on_disk, array( 'imagina-hls.js' ) ), $named ), implode( ' ', array_diff( $on_disk, $named ) ) );
check( 'except the HLS library, which a preview never plays', ! in_array( 'imagina-hls.js', $named, true ) );
check( 'each with its version, so a browser does not keep an old one', array() !== $named && ! in_array( false, array_map( static fn( string $url ): bool => str_contains( $url, '?ver=' ), (array) $handed['frontendChunks'] ), true ) );

echo PHP_EOL . '# And both previews do it' . PHP_EOL;

foreach ( array( 'the block preview' => '/assets/src/editor/preview.tsx', 'the settings preview' => '/assets/src/admin/PreviewFrame.tsx' ) as $what => $file ) {
	$source = (string) file_get_contents( $root . $file );

	check( "{$what} inlines the files", str_contains( $source, 'inlineAssets(' ) && str_contains( $source, '${ inlined.head }' ) && str_contains( $source, '${ inlined.tail }' ) );
	check( 'with the on-demand pieces', str_contains( $source, 'frontendChunks' ) );
	check( 'and tells the player where they live, for anything not written in', str_contains( $source, 'assetUrl:' ) );
	check( 'and no longer links them from inside the frame', ! str_contains( $source, '<link rel="stylesheet" href="${' ) && ! str_contains( $source, '<script src="${' ) );
}

exec( 'rm -rf ' . escapeshellarg( $workdir ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All preview-asset checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
