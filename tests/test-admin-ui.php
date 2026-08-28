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
	'video'      => $defaults['video'],
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
		'ffmpegState'  => 'processes-disabled',
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
// wp_enqueue_media() puts this on the page. The field has to work either way,
// so the test records whether the button appears when it is present.
window.wp.media = function (args) {
	window.__mediaFrameArgs = args;
	return {
		on: function (event, handler) { window.__mediaSelect = handler; },
		open: function () { window.__mediaOpened = true; },
		state: function () {
			return { get: function () { return { first: function () { return { toJSON: function () {
				return { url: 'https://example.test/wp-content/uploads/picked-logo.png' };
			} }; } }; } };
		}
	};
};
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
		// Contrast of the things that carry the brand colour. White on Imagina's
		// cyan reads at 2.2:1, so these have to be checked rather than assumed.
		contrast: (function () {
			function luminance(rgb) {
				var parts = rgb.match(/\d+/g).slice(0, 3).map(function (n) {
					var c = n / 255;
					return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
				});
				return 0.2126 * parts[0] + 0.7152 * parts[1] + 0.0722 * parts[2];
			}

			function ratio(el) {
				if (!el) { return null; }
				var s = getComputedStyle(el);
				var a = luminance(s.color);
				var b = luminance(s.backgroundColor);
				return Math.round(((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)) * 100) / 100;
			}

			return {
				primaryButton: ratio(document.querySelector('.imgpa-btn--primary')),
				activeTab: ratio(document.querySelector('.imgpa-tabs__tab.is-active')),
				activeNav: ratio(document.querySelector('.imgpa-nav__item.is-active'))
			};
		})(),
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

		// Phase three: Branding, where the logo field lives.
		var nav = document.querySelectorAll('.imgpa-nav__item');

		Array.prototype.forEach.call(nav, function (item) {
			if (item.textContent.trim() === 'Branding') { item.click(); }
		});

		setTimeout(function () {
			var media = document.querySelector('.imgpa-media');
			var button = media
				? Array.prototype.filter.call(media.querySelectorAll('button'), function (el) {
					return el.textContent.indexOf('Media library') !== -1;
				})[0]
				: null;

			result.branding = {
				hasMediaField: !!media,
				hasLibraryButton: !!button,
				// A plain URL box is still there: a logo often lives outside
				// the library, and the picker must not take that away.
				hasUrlBox: !!(media && media.querySelector('input[type="text"]'))
			};

			if (button) {
				button.click();
				result.branding.opened = !!window.__mediaOpened;
				result.branding.restrictedToImages = window.__mediaFrameArgs
					&& window.__mediaFrameArgs.library
					&& window.__mediaFrameArgs.library.type === 'image';

				// What the frame hands back has to reach the field.
				if (window.__mediaSelect) { window.__mediaSelect(); }
			}

			setTimeout(function () {
				var box = document.querySelector('.imgpa-media input[type="text"]');
				result.branding.valueAfterPick = box ? box.value : '';

				// Phase four: Protection, and the self-check. By name, not by
				// position — an index breaks the moment a section is inserted
				// before it, which is exactly what happened when Video arrived.
				Array.prototype.forEach.call(
					document.querySelectorAll('.imgpa-nav__item'),
					function (item) {
						if (item.textContent.trim() === 'Protection') { item.click(); }
					}
				);

				setTimeout(function () {
					var cards = Array.prototype.map.call(
						document.querySelectorAll('.imgpa-card__head h2'),
						function (el) { return el.textContent.trim(); }
					);
					var runner = Array.prototype.filter.call(
						document.querySelectorAll('.imgpa-card button'),
						function (el) { return el.textContent.indexOf('Run the check') !== -1; }
					)[0];

					result.protection = { cards: cards, hasRunButton: !!runner };

					// Phase five: the Video section, which has to have real
					// controls in it and not just a heading.
					Array.prototype.forEach.call(
						document.querySelectorAll('.imgpa-nav__item'),
						function (item) {
							if (item.textContent.trim() === 'Video') { item.click(); }
						}
					);

					setTimeout(function () {
						result.video = {
							cards: Array.prototype.map.call(
								document.querySelectorAll('.imgpa-card__head h2'),
								function (el) { return el.textContent.trim(); }
							),
							selects: document.querySelectorAll('.imgpa-main select').length,
							toggles: document.querySelectorAll('.imgpa-main .imgpa-toggle').length,
							numbers: document.querySelectorAll('.imgpa-main .imgpa-number input').length
						};

						var out = document.createElement('pre');
						out.id = 'result';
						out.textContent = 'RESULT:' + JSON.stringify(result);
						document.body.appendChild(out);
					}, 400);
				}, 400);
			}, 300);
		}, 400);
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
// By name rather than by count: a count tells you a section was added or
// removed but not which, and it fails on every deliberate change too.
$expected_sections = array( 'Presets', 'Branding', 'Waveforms', 'Protection', 'Emails', 'Advanced' );

foreach ( $expected_sections as $name ) {
	check( "the {$name} section is listed", in_array( $name, $result['nav'] ?? array(), true ), implode( ' / ', $result['nav'] ?? array() ) );
}
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

// WCAG AA for normal text is 4.5:1. These three carry the brand colour, and
// the brand colour is bright enough that the obvious choice of white text on it
// fails badly.
foreach ( (array) ( $result['contrast'] ?? array() ) as $part => $ratio ) {
	check(
		"{$part} text is readable on its background",
		is_numeric( $ratio ) && (float) $ratio >= 4.5,
		$ratio . ':1'
	);
}

// The logo was the last field still asking for a pasted URL. A settings screen
// has no MediaUpload — that belongs to the block editor — so it opens wp.media
// directly, and that only exists once the screen enqueues it.
$branding = $result['branding'] ?? array();

check( 'the branding logo is a media field', ! empty( $branding['hasMediaField'] ) );
check( 'it offers the media library', ! empty( $branding['hasLibraryButton'] ) );
check( 'and still accepts a pasted URL', ! empty( $branding['hasUrlBox'] ) );
check( 'the button opens the library', ! empty( $branding['opened'] ) );
check( 'which is restricted to images', ! empty( $branding['restrictedToImages'] ) );
check(
	'and what is chosen lands in the field',
	'https://example.test/wp-content/uploads/picked-logo.png' === ( $branding['valueAfterPick'] ?? '' ),
	(string) ( $branding['valueAfterPick'] ?? '' )
);

// The screen has to enqueue the frame, or the button never appears on a real
// install however well it behaves here.
check(
	'the settings screen enqueues the media frame',
	str_contains( (string) file_get_contents( $root . '/src/Admin/Dashboard.php' ), 'wp_enqueue_media()' )
);

$protection = $result['protection'] ?? array();

check(
	'the protection section offers a self-check',
	in_array( 'Check that it works', $protection['cards'] ?? array(), true ),
	implode( ' / ', $protection['cards'] ?? array() )
);
check( 'with a button to run it', ! empty( $protection['hasRunButton'] ) );

// The Video section is the one this release exists for. A heading with nothing
// under it would be the same failure in a new place.
$video_panel = $result['video'] ?? array();

check(
	'the Video section renders its cards',
	count( array_intersect( array( 'The picture', 'Controls', 'Subtitles' ), $video_panel['cards'] ?? array() ) ) === 3,
	implode( ' / ', $video_panel['cards'] ?? array() )
);
check(
	'with real dropdowns in it',
	(int) ( $video_panel['selects'] ?? 0 ) >= 4,
	(string) ( $video_panel['selects'] ?? 0 )
);
check(
	'real toggles',
	(int) ( $video_panel['toggles'] ?? 0 ) >= 4,
	(string) ( $video_panel['toggles'] ?? 0 )
);
check(
	'and the hide delay as a number field',
	(int) ( $video_panel['numbers'] ?? 0 ) >= 1,
	(string) ( $video_panel['numbers'] ?? 0 )
);

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
