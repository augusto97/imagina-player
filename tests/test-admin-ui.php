<?php
/**
 * The admin application, rendered in a browser.
 *
 * The screen is a React app talking to REST, so nothing about it can be checked
 * from PHP. This drives the real bundle with React and a stubbed `wp` global,
 * and asserts the app mounts, paints its sections, and renders a live preview.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Player\Skins;
use ImaginaPlayer\Render\PlayerRenderer;
use ImaginaPlayer\Rest\SettingsController;
use ImaginaPlayer\Settings;

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

	foreach ( array( 'chromium', 'chromium-browser', 'google-chrome' ) as $name ) {
		$found = trim( (string) shell_exec( 'command -v ' . escapeshellarg( $name ) . ' 2>/dev/null' ) );

		if ( '' !== $found ) {
			return $found;
		}
	}

	return '';
}

$browser = find_browser();
$react   = $root . '/node_modules/react/umd/react.production.min.js';
$dom     = $root . '/node_modules/react-dom/umd/react-dom.production.min.js';

if ( '' === $browser || ! is_readable( $react ) || ! is_readable( $dom ) ) {
	echo 'SKIP  no browser or React build available; admin UI not checked' . PHP_EOL;
	exit( 0 );
}

// The settings payload the REST endpoint would return.
$defaults = Settings::defaults();
$payload  = array(
	'presets'    => array(
		'default' => Settings::preset_defaults(),
		'podcast' => array_replace( Settings::preset_defaults(), array( 'label' => 'Podcast', 'skin' => 'compact', 'accent' => '#0b7285' ) ),
	),
	'peaks'      => array(
		'resolution'        => 400,
		'server_generation' => true,
		'client_fallback'   => true,
		'ffmpeg_path'       => '',
		'max_client_mb'     => 25,
	),
	'protection' => $defaults['protection'],
	'advanced'   => $defaults['advanced'],
	'branding'   => $defaults['branding'],
	'schema'     => array(
		'presetDefaults' => Settings::preset_defaults(),
		'skins'          => Skins::all(),
		'skinNotes'      => Skins::descriptions(),
		'defaultPreset'  => 'default',
	),
	'system'     => array(
		'ffmpeg'       => false,
		'ffmpegBinary' => '',
		'vaultDir'     => '/var/www/uploads/imagina-protected-abc123',
		'vaultName'    => 'imagina-protected-abc123',
		'htaccess'     => false,
		'version'      => \ImaginaPlayer\VERSION,
	),
);

$renderer     = new PlayerRenderer();
$preview_html = $renderer->render(
	array(
		'src'    => 'https://example.test/track.mp3',
		'title'  => 'Your track title',
		'artist' => 'Artist name',
	)
);

$boot = wp_json_encode(
	array(
		'restUrl'     => '',
		'nonce'       => '',
		'frontendCss' => './style-frontend.css',
		'frontendJs'  => './frontend.js',
		'docsUrl'     => '',
	)
);

$settings_json = wp_json_encode( $payload );
$preview_json  = wp_json_encode(
	array(
		'html'  => $preview_html,
		'peaks' => SettingsController::demo_peaks(),
	)
);

$admin_js  = (string) file_get_contents( $root . '/build/admin.js' );
$admin_css = (string) file_get_contents( $root . '/build/admin.css' );
$react_js  = (string) file_get_contents( $react );
$dom_js    = (string) file_get_contents( $dom );

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<style>body{margin:0;background:#f0f0f1;font-family:-apple-system,system-ui,sans-serif}#wpcontent{padding-left:20px}{$admin_css}</style>
</head><body>
<div id="wpcontent"><div id="imagina-player-admin"></div></div>
<script>{$react_js}</script>
<script>{$dom_js}</script>
<script>
// Shim the WordPress globals the bundle expects, from plain React.
window.ReactJSXRuntime = {
	Fragment: React.Fragment,
	jsx: function (type, props, key) { return React.createElement(type, key === undefined ? props : Object.assign({}, props, { key: key })); },
	jsxs: function (type, props, key) { return React.createElement(type, key === undefined ? props : Object.assign({}, props, { key: key })); }
};
window.wp = {
	element: Object.assign({}, React, { createRoot: ReactDOM.createRoot, render: ReactDOM.render }),
	i18n: {
		__: function (text) { return text; },
		_x: function (text) { return text; },
		_n: function (a) { return a; },
		sprintf: function (format) {
			var args = Array.prototype.slice.call(arguments, 1);
			var i = 0;
			return String(format).replace(/%\\d\\\$[sd]|%[sd]/g, function () { return args[i++]; });
		},
		setLocaleData: function () {}
	},
	apiFetch: function (options) {
		var path = options.path || '';
		if (path.indexOf('/settings') !== -1) { return Promise.resolve({$settings_json}); }
		if (path.indexOf('/preview') !== -1) { return Promise.resolve({$preview_json}); }
		return Promise.resolve({});
	}
};
window.imaginaPlayerAdmin = {$boot};
</script>
<script>{$admin_js}</script>
<script>
setTimeout(function () {
	function text(selector) {
		return Array.prototype.map.call(document.querySelectorAll(selector), function (el) { return el.textContent.trim(); });
	}

	var root = document.getElementById('imagina-player-admin');
	var styles = root ? getComputedStyle(root) : null;

	// Phase one: whatever the Controls tab shows.
	var result = {
		mounted: !!document.querySelector('.imgpa-header'),
		prefersDark: window.matchMedia('(prefers-color-scheme: dark)').matches,
		ground: styles ? styles.backgroundColor : '',
		ink: styles ? styles.color : '',
		nav: text('.imgpa-nav__item'),
		presets: text('.imgpa-presets__name'),
		tabs: text('.imgpa-tabs__tab'),
		toggles: document.querySelectorAll('.imgpa-toggle').length,
		preview: !!document.querySelector('.imgpa-preview__frame'),
		saveDisabled: document.querySelector('.imgpa-btn--primary') ? document.querySelector('.imgpa-btn--primary').disabled : null
	};

	// Phase two: switch to Style and let React repaint before reading it.
	var tabs = document.querySelectorAll('.imgpa-tabs__tab');

	if (tabs.length >= 3) {
		tabs[2].click();
	}

	setTimeout(function () {
		result.styleTab = {
			colorPickers: document.querySelectorAll('.imgpa-color input[type="color"]').length,
			backgroundChoices: text('.imgpa-segment__option')
		};

		var out = document.createElement('pre');
		out.id = 'result';
		out.textContent = 'RESULT:' + JSON.stringify(result);
		document.body.appendChild(out);
	}, 400);
}, 1800);
</script>
</body></html>
HTML;

$page_file = getenv( 'IMGP_KEEP_PAGE' ) ?: $root . '/build/.admin-test.html';
file_put_contents( $page_file, $page );

// The preview iframe pulls the real front-end assets from alongside the page.
$dom_output = (string) shell_exec(
	sprintf(
		'%s --headless --no-sandbox --disable-gpu --virtual-time-budget=8000 --dump-dom %s 2>/dev/null',
		escapeshellarg( $browser ),
		escapeshellarg( 'file://' . $page_file )
	)
);

if ( ! getenv( 'IMGP_KEEP_PAGE' ) ) { @unlink( $page_file ); }

if ( ! preg_match( '/RESULT:(\{.*?\})</s', $dom_output, $matches ) ) {
	echo 'FAIL  the admin app did not report a result' . PHP_EOL;
	exit( 1 );
}

$result = json_decode( $matches[1], true );

check( 'the application mounts', ! empty( $result['mounted'] ) );
check( 'every section is listed', 5 === count( $result['nav'] ?? array() ), implode( ' / ', $result['nav'] ?? array() ) );
check( 'both presets are listed', 2 === count( $result['presets'] ?? array() ), implode( ' / ', $result['presets'] ?? array() ) );
check( 'the preset editor has its three tabs', 3 === count( $result['tabs'] ?? array() ), implode( ' / ', $result['tabs'] ?? array() ) );
check( 'the control toggles render', (int) ( $result['toggles'] ?? 0 ) >= 8, (string) ( $result['toggles'] ?? 0 ) );
check( 'the live preview frame is present', ! empty( $result['preview'] ) );
check( 'saving is disabled until something changes', true === ( $result['saveDisabled'] ?? null ) );

// The screen once followed the OS preference and turned dark on its own, while
// the rest of wp-admin stayed light and kept painting dark headings onto it.
// WordPress has no dark mode; this screen does not invent one.
$to_rgb = static function ( string $value ): array {
	preg_match_all( '/\d+/', $value, $numbers );

	return array_map( 'intval', array_slice( $numbers[0], 0, 3 ) );
};

$ground = $to_rgb( (string) ( $result['ground'] ?? '' ) );
$ink    = $to_rgb( (string) ( $result['ink'] ?? '' ) );

// Headless Chromium will not report a dark colour scheme on demand — the flags
// that look like they should are about auto-darkening, not the media query — so
// the decision is pinned at the stylesheet instead: no colour-scheme rule may
// reach this screen at all.
$admin_css = (string) file_get_contents( $root . '/build/admin.css' );

check(
	'the stylesheet has no colour-scheme rule to follow',
	! str_contains( $admin_css, 'prefers-color-scheme' )
);
check(
	'the panel stays light anyway',
	3 === count( $ground ) && array_sum( $ground ) / 3 > 200,
	(string) ( $result['ground'] ?? '' )
);
check(
	'and its text stays dark',
	3 === count( $ink ) && array_sum( $ink ) / 3 < 110,
	(string) ( $result['ink'] ?? '' )
);

$style = $result['styleTab'] ?? array();

// Every colour in the Style tab must be pickable, background included: it was
// the one field left as a bare text box because it also accepts "transparent".
check(
	'the style tab offers colour pickers',
	(int) ( $style['colorPickers'] ?? 0 ) >= 5,
	(string) ( $style['colorPickers'] ?? 0 )
);
check(
	'the background offers transparent or a colour',
	2 === count( $style['backgroundChoices'] ?? array() ),
	implode( ' / ', $style['backgroundChoices'] ?? array() )
);

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
