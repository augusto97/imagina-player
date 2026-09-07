<?php
/**
 * A provider's own subtitles, switched from this player's bar.
 *
 * YouTube and Vimeo draw their subtitles inside their frame and will not hand
 * the text over, so this player cannot draw them its own way. It used to hide
 * its subtitles button for them — and with the provider's interface hidden as
 * well, a viewer had no way to turn subtitles on at all. Now the button lists
 * the provider's languages and switches them, through each provider's API.
 *
 * Driven in a real Chromium against stand-ins for both APIs that record what
 * they are told, because the only honest question is "what did the provider
 * get asked to do".
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Render\PlayerRenderer;

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
			glob( '/opt/pw-browsers/chromium-*/chrome-linux/chrome' ) ?: array(),
			glob( '/opt/pw-browsers/chromium_headless_shell-*/chrome-linux/headless_shell' ) ?: array()
		)
	);

	foreach ( $candidates as $candidate ) {
		if ( '' !== $candidate && is_executable( $candidate ) ) {
			return $candidate;
		}
	}

	return '';
}

$renderer = new PlayerRenderer();

echo '# The button is there to be shown' . PHP_EOL;

$youtube = $renderer->render( array( 'src' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ) );
$vimeo   = $renderer->render( array( 'src' => 'https://vimeo.com/76979871' ) );
$file    = $renderer->render( array( 'src' => 'https://example.test/clip.mp4' ) );

check( 'a YouTube video gets a subtitles button, hidden until the provider lists languages', str_contains( $youtube, 'imgp__vbtn--captions' ) );
check( 'so does a Vimeo video', str_contains( $vimeo, 'imgp__vbtn--captions' ) );
check( 'a file with no subtitle tracks still gets none', ! str_contains( $file, 'imgp__vbtn--captions' ) );

$browser = find_browser();

if ( '' === $browser ) {
	echo 'SKIP  no Chromium found; the switching not driven' . PHP_EOL;
	echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All provider-caption checks passed.' ) . PHP_EOL;
	exit( $failures ? 1 : 0 );
}

$workdir = $root . '/build/.provider-captions-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

foreach ( (array) glob( $root . '/build/*.{js,css}', GLOB_BRACE ) as $asset ) {
	copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
}

$plain = $renderer->render( array( 'src' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'title' => 'Plain' ) );
$on    = $renderer->render( array( 'src' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'title' => 'On', 'videoCaptionsOn' => true ) );
$vim   = $renderer->render( array( 'src' => 'https://vimeo.com/76979871', 'title' => 'Vimeo' ) );

/**
 * One page, three players, and a stand-in for each provider's API.
 *
 * @param string $remembered What the viewer chose last time, or '' for nothing.
 */
function page( string $plain, string $on, string $vim, string $remembered ): string {
	$remember = '' === $remembered ? '' : "window.localStorage.setItem('imagina-player-captions', " . json_encode( $remembered ) . ");";

	return <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>html,body{margin:0}</style>
</head><body>
<div id="plain">{$plain}</div>
<div id="on">{$on}</div>
<div id="vim">{$vim}</div>
<script>
{$remember}
window.__yt = [];
window.__vimeo = [];

// YouTube: the captions module loads on request and announces itself with
// onApiChange, after which the track list can be read. Exactly the order the
// real one uses.
window.YT = {
	Player: function (element, options) {
		var self = this;
		var loaded = false;
		var frame = document.createElement('iframe');
		element.appendChild(frame);
		window.__yt.push('vars:cc=' + options.playerVars.cc_load_policy);
		this.playVideo = function () { window.__yt.push('play'); options.events.onStateChange({ data: 1 }); };
		this.pauseVideo = function () { window.__yt.push('pause'); options.events.onStateChange({ data: 2 }); };
		this.seekTo = function () {};
		this.setVolume = function () {};
		this.mute = function () {};
		this.unMute = function () {};
		this.setPlaybackRate = function () {};
		this.getCurrentTime = function () { return 1; };
		this.getDuration = function () { return 200; };
		this.destroy = function () {};
		this.loadModule = function (name) {
			window.__yt.push('loadModule:' + name);
			if (!loaded) { loaded = true; window.setTimeout(function () { options.events.onApiChange && options.events.onApiChange(); }, 50); }
		};
		this.getOption = function (module, option) {
			if ('captions' === module && 'tracklist' === option && loaded) {
				return [ { languageCode: 'en', languageName: 'English', displayName: 'English' }, { languageCode: 'es', languageName: 'Spanish', displayName: 'Español' } ];
			}
			return null;
		};
		this.setOption = function (module, option, value) {
			window.__yt.push('setOption:' + module + ':' + option + ':' + JSON.stringify(value));
		};
		window.setTimeout(function () { options.events.onReady({ target: self }); }, 0);
	}
};

// Vimeo: the documented text-track calls.
window.Vimeo = {
	Player: function (element, options) {
		var handlers = {};
		var frame = document.createElement('iframe');
		element.appendChild(frame);
		this.ready = function () { return Promise.resolve(); };
		this.play = function () { window.__vimeo.push('play'); (handlers.play || function () {})(); return Promise.resolve(); };
		this.pause = function () { return Promise.resolve(); };
		this.setCurrentTime = function (s) { return Promise.resolve(s); };
		this.getDuration = function () { return Promise.resolve(100); };
		this.setVolume = function (v) { return Promise.resolve(v); };
		this.setMuted = function (m) { return Promise.resolve(m); };
		this.setPlaybackRate = function (r) { return Promise.resolve(r); };
		this.on = function (event, handler) { handlers[event] = handler; };
		this.destroy = function () { return Promise.resolve(); };
		this.getTextTracks = function () {
			window.__vimeo.push('getTextTracks');
			return Promise.resolve([ { language: 'en', kind: 'subtitles', label: 'English', mode: 'disabled' }, { language: 'es', kind: 'captions', label: 'Español', mode: 'disabled' }, { language: 'x-chapters', kind: 'chapters', label: 'Chapters', mode: 'disabled' } ]);
		};
		this.enableTextTrack = function (language) { window.__vimeo.push('enable:' + language); return Promise.resolve({}); };
		this.disableTextTrack = function () { window.__vimeo.push('disable'); return Promise.resolve(); };
	}
};

window.imaginaPlayer = { restUrl: '', lazyInit: false, maxComputeBytes: 0, assetUrl: './', i18n: {} };
</script>
<script src="./frontend.js"></script>
<script>
function report(data) {
	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify(data);
	document.body.appendChild(pre);
}

setTimeout(function () {
	var out = {};
	var plainButton = document.querySelector('#plain .imgp__vbtn--captions');
	var onButton = document.querySelector('#on .imgp__vbtn--captions');
	var vimButton = document.querySelector('#vim .imgp__vbtn--captions');

	out.hiddenBeforePlay = !!plainButton && plainButton.hidden;

	document.querySelector('#plain .imgp__play').click();
	document.querySelector('#on .imgp__play').click();
	document.querySelector('#vim .imgp__play').click();

	setTimeout(function () {
		out.shownAfterPlay = !!plainButton && !plainButton.hidden;
		out.vimShownAfterPlay = !!vimButton && !vimButton.hidden;
		out.onPressed = onButton ? onButton.getAttribute('aria-pressed') : null;
		out.plainPressed = plainButton ? plainButton.getAttribute('aria-pressed') : null;
		out.ytAfterPlay = window.__yt.slice();
		out.vimeoAfterPlay = window.__vimeo.slice();

		// Open the menu on the plain player and read it.
		plainButton.click();
		var menu = document.querySelector('#plain .imgp__menu');
		out.menu = Array.prototype.map.call(menu.querySelectorAll('.imgp__menuitem'), function (el) { return el.textContent.trim() + (el.classList.contains('is-active') || el.getAttribute('aria-checked') === 'true' ? '*' : ''); });

		// Pick Spanish.
		var items = menu.querySelectorAll('.imgp__menuitem');
		items[items.length - 1].click();

		setTimeout(function () {
			out.ytAfterPick = window.__yt.slice();
			out.plainPressedAfterPick = plainButton.getAttribute('aria-pressed');
			out.remembered = window.localStorage.getItem('imagina-player-captions');

			// And off again.
			plainButton.click();
			document.querySelector('#plain .imgp__menu .imgp__menuitem').click();

			// Vimeo: pick Spanish there too.
			vimButton.click();
			var vitems = document.querySelectorAll('#vim .imgp__menu .imgp__menuitem');
			out.vimMenu = Array.prototype.map.call(vitems, function (el) { return el.textContent.trim(); });
			vitems[vitems.length - 1].click();

			setTimeout(function () {
				out.ytAtEnd = window.__yt.slice();
				out.vimeoAtEnd = window.__vimeo.slice();
				report(out);
			}, 200);
		}, 200);
	}, 900);
}, 900);
</script>
</body></html>
HTML;
}

/**
 * Run a page in Chromium and read what it reported.
 *
 * @return array<string, mixed>|null
 */
function run( string $browser, string $file ): ?array {
	$dom = (string) shell_exec(
		sprintf(
			'%s --headless=new --no-sandbox --disable-gpu --window-size=900,700 --virtual-time-budget=9000 --dump-dom %s 2>/dev/null',
			escapeshellarg( $browser ),
			escapeshellarg( 'file://' . $file )
		)
	);

	return preg_match( '/RESULT:(\{.*?\})</s', $dom, $m ) ? (array) json_decode( $m[1], true ) : null;
}

file_put_contents( $workdir . '/captions.html', page( $plain, $on, $vim, '' ) );
$r = run( $browser, $workdir . '/captions.html' );

file_put_contents( $workdir . '/remembered.html', page( $plain, $on, $vim, 'es' ) );
$r2 = run( $browser, $workdir . '/remembered.html' );

exec( 'rm -rf ' . escapeshellarg( $workdir ) );

echo PHP_EOL . '# YouTube, from this player’s bar' . PHP_EOL;

check( 'the page reported', is_array( $r ), 'no result; the bundle may have thrown' );

if ( is_array( $r ) ) {
	$after = (array) ( $r['ytAfterPlay'] ?? array() );
	$pick  = (array) ( $r['ytAfterPick'] ?? array() );
	$end   = (array) ( $r['ytAtEnd'] ?? array() );

	check( 'before play the button is hidden — the frame, and its languages, do not exist yet', true === ( $r['hiddenBeforePlay'] ?? false ) );
	check( 'after play the button appears', true === ( $r['shownAfterPlay'] ?? false ) );
	check( 'because the captions module was asked for', in_array( 'loadModule:captions', $after, true ), implode( ' ', $after ) );
	check( 'and, the viewer not having asked, subtitles are put off again after the list was read', in_array( 'setOption:captions:track:{}', $after, true ), implode( ' ', $after ) );
	check( 'so the button reads off', 'false' === ( $r['plainPressed'] ?? '' ) );
	check( 'the menu lists off and every language YouTube has', array( 'Off*', 'English', 'Español' ) === ( $r['menu'] ?? array() ), json_encode( $r['menu'] ?? null ) );
	check( 'picking a language tells YouTube which one', in_array( 'setOption:captions:track:{"languageCode":"es"}', $pick, true ), implode( ' ', $pick ) );
	check( 'and the button reads on', 'true' === ( $r['plainPressedAfterPick'] ?? '' ) );
	check( 'and the choice is remembered for the next video', 'es' === ( $r['remembered'] ?? '' ), (string) ( $r['remembered'] ?? '' ) );
	check( 'picking off tells YouTube none', count( array_keys( $end, 'setOption:captions:track:{}', true ) ) >= 2, implode( ' ', $end ) );

	echo PHP_EOL . '# On from the start, when the author asked' . PHP_EOL;

	check( 'the frame is built with YouTube’s own switch for it', in_array( 'vars:cc=1', $after, true ) && in_array( 'vars:cc=0', $after, true ), implode( ' ', array_filter( $after, static fn( $c ) => str_starts_with( $c, 'vars:' ) ) ) );
	check( 'and the first language is chosen once the list is known', in_array( 'setOption:captions:track:{"languageCode":"en"}', $after, true ), implode( ' ', $after ) );
	check( 'so that button reads on', 'true' === ( $r['onPressed'] ?? '' ) );

	echo PHP_EOL . '# Vimeo, the same way' . PHP_EOL;

	$vafter = (array) ( $r['vimeoAfterPlay'] ?? array() );
	$vend   = (array) ( $r['vimeoAtEnd'] ?? array() );

	check( 'after play the button appears', true === ( $r['vimShownAfterPlay'] ?? false ) );
	check( 'because Vimeo was asked for its tracks', in_array( 'getTextTracks', $vafter, true ), implode( ' ', $vafter ) );
	check( 'the menu lists off and the subtitle languages, chapters excluded', array( 'Off', 'English', 'Español' ) === ( $r['vimMenu'] ?? array() ), json_encode( $r['vimMenu'] ?? null ) );
	check( 'picking a language enables that track on Vimeo', in_array( 'enable:es', $vend, true ), implode( ' ', $vend ) );
}

echo PHP_EOL . '# A viewer who chose a language last time' . PHP_EOL;

check( 'the page reported', is_array( $r2 ), 'no result' );

if ( is_array( $r2 ) ) {
	$after2 = (array) ( $r2['ytAfterPlay'] ?? array() );
	check( 'gets it again without asking', in_array( 'setOption:captions:track:{"languageCode":"es"}', $after2, true ), implode( ' ', $after2 ) );
	check( 'and the button reads on from the start', 'true' === ( $r2['plainPressed'] ?? '' ) );
}

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All provider-caption checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
