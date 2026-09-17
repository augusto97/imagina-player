<?php
/**
 * Moments: timestamp buttons, links to a second, the speed list, the gear,
 * the segmented chapter bar and picking up where the viewer stopped.
 *
 * The server side is checked directly. The rest is driven in a real Chromium
 * against the built bundle, because a menu that opens, a bar that names the
 * chapter under the pointer and a chip that says "resume from" are things a
 * browser does or does not do.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Blocks\BlockRegistrar;
use ImaginaPlayer\Render\PlayerRenderer;
use ImaginaPlayer\Shortcodes\TimeShortcode;

$root = dirname( __DIR__ );

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

echo '# A time, however it is written' . PHP_EOL;

check( 'seconds', 95.0 === TimeShortcode::seconds( '95' ) );
check( 'minutes and seconds', 95.0 === TimeShortcode::seconds( '1:35' ) );
check( 'with hours', 3723.0 === TimeShortcode::seconds( '1:02:03' ) );
check( 'YouTube style', 95.0 === TimeShortcode::seconds( '1m35s' ) );
check( 'YouTube style with hours', 3723.0 === TimeShortcode::seconds( '1h2m3s' ) );
check( 'with a trailing s', 95.0 === TimeShortcode::seconds( '95s' ) );
check( 'a fraction', 95.5 === TimeShortcode::seconds( '95.5' ) );
check( 'the very start', 0.0 === TimeShortcode::seconds( '0:00' ) );
check( 'a word is not a time', null === TimeShortcode::seconds( 'soon' ) );
check( 'nor is nothing', null === TimeShortcode::seconds( '' ) );
check( 'nor is a script', null === TimeShortcode::seconds( '<script>' ) );
check( 'stamped back as a viewer reads it', '1:35' === TimeShortcode::stamp( 95 ) && '1:02:03' === TimeShortcode::stamp( 3723 ) );

echo PHP_EOL . '# The button the shortcode writes' . PHP_EOL;

$shortcode = new TimeShortcode();

$plain = $shortcode->render( array( 'at' => '1:35' ) );

check( 'is a button', str_starts_with( $plain, '<button type="button"' ), $plain );
check( 'carrying the second', str_contains( $plain, 'data-imgp-time="95"' ), $plain );
check( 'and the time as its text when none was given', str_contains( $plain, '>1:35</button>' ), $plain );
check( 'with the class the script and the stylesheet know', str_contains( $plain, 'class="imgp-time"' ), $plain );
check( 'naming no player when none was named', ! str_contains( $plain, 'data-imgp-player' ), $plain );

$named = $shortcode->render( array( 'T' => '2m', 'player' => 'intro', 'class' => 'is-big "onclick' ), 'The <b>second</b> question' );

check( 'the alias t= is accepted, in any case', str_contains( $named, 'data-imgp-time="120"' ), $named );
check( 'the player it names is carried', str_contains( $named, 'data-imgp-player="intro"' ), $named );
check( 'the words the author wrote are the label', str_contains( $named, 'The <b>second</b> question</button>' ), $named );
check( 'an extra class is kept, and a hostile one is not', str_contains( $named, 'class="imgp-time is-big onclick"' ), $named );

$hostile = $shortcode->render( array( 'at' => '1:35', 'player' => 'x" onmouseover="alert(1)' ), '<script>alert(1)</script>Go' );

check( 'a hostile player name is reduced to a class name', str_contains( $hostile, 'data-imgp-player="xonmouseoveralert1"' ), $hostile );
check( 'and a script in the label is stripped', ! str_contains( $hostile, '<script' ), $hostile );
check( 'no time, no button', '' === $shortcode->render( array( 'at' => 'later' ), 'x' ) );
$shortcode->hooks();
check( 'the shortcode is registered under its tag', shortcode_exists( 'imagina_time' ) );

echo PHP_EOL . '# The block wrapper names itself' . PHP_EOL;

check( 'no anchor, no id', array( 'class' => 'imgp-block' ) === BlockRegistrar::wrapper_attributes( array() ) );
check( 'the HTML anchor becomes the id', 'intro' === ( BlockRegistrar::wrapper_attributes( array( 'anchor' => 'intro' ) )['id'] ?? '' ) );
check( 'and cannot break out of the attribute', 'xonclickalert1' === ( BlockRegistrar::wrapper_attributes( array( 'anchor' => 'x" onclick="alert(1)' ) )['id'] ?? '' ) );

echo PHP_EOL . '# What the server puts in the bar' . PHP_EOL;

$renderer = new PlayerRenderer();

$video = $renderer->render( array( 'src' => 'https://cdn.example.com/clip.mp4' ) );
$audio = $renderer->render( array( 'src' => 'https://cdn.example.com/talk.mp3' ) );
$chaptered = $renderer->render(
	array(
		'src'      => 'https://cdn.example.com/clip.mp4',
		'chapters' => array( array( 'start' => '0:00', 'title' => 'Intro' ), array( 'start' => '1:00', 'title' => 'The middle' ) ),
	)
);

check( 'a video gets a gear, hidden until the script fills it', preg_match( '/imgp__vbtn--settings"[^>]*hidden/', $video ) === 1 );
check( 'an audio player does not', ! str_contains( $audio, 'imgp__vbtn--settings' ) );
check( 'chapters mark the player as segmented', str_contains( $chaptered, 'imgp--chaptered' ) );
check( 'no chapters, no segments', ! str_contains( $video, 'imgp--chaptered' ) );

echo PHP_EOL . '# In a browser' . PHP_EOL;

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

$browser = find_browser();

if ( '' === $browser ) {
	echo 'SKIP  no Chromium found; nothing was pressed' . PHP_EOL;
	echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
	exit( $failures ? 1 : 0 );
}

$workdir = $root . '/build/.moments-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

foreach ( (array) glob( $root . '/build/*.{js,css}', GLOB_BRACE ) as $asset ) {
	copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
}

/*
 * Two videos. The first has chapters, remembers its position and was left
 * at 0:42 last time; the second is plain. A timestamp button names the
 * first; the address names the second.
 */
$first = $renderer->render(
	array(
		'src'              => 'https://cdn.example.com/clip.mp4',
		'title'            => 'First',
		'rememberPosition' => true,
		'videoSpeed'       => true,
		'chapters'         => array( array( 'start' => '0:00', 'title' => 'Intro' ), array( 'start' => '1:00', 'title' => 'The middle' ), array( 'start' => '2:30', 'title' => 'The end' ) ),
	)
);
$second = $renderer->render( array( 'src' => 'https://cdn.example.com/other.mp4', 'title' => 'Second' ) );

// The key the first player will look under: md5 of its source, twelve characters.
$layer_key = substr( md5( 'https://cdn.example.com/clip.mp4' ), 0, 12 );

$button = ( new TimeShortcode() )->render( array( 'at' => '1:35', 'player' => 'intro' ), 'Jump' );

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>html,body{margin:0} .spacer{height:1400px}</style>
</head><body>
<p>{$button}</p>
<div class="imgp-block" id="intro">{$first}</div>
<div class="spacer"></div>
<div class="imgp-block" id="other">{$second}</div>
<script>
// A probe that throws reports the throw, rather than nothing.
window.addEventListener('error', function (e) {
	var pre = document.createElement('pre');
	pre.textContent = 'RESULT:' + JSON.stringify({ error: e.message + ' @' + e.lineno + ':' + e.colno });
	document.body.appendChild(pre);
});
Object.defineProperty(HTMLMediaElement.prototype, 'duration', {
	get: function () { return 200; },
	configurable: true
});
window.localStorage.setItem('imagina-player:position:{$layer_key}', '42');
window.__copied = null;
Object.defineProperty(navigator, 'clipboard', {
	value: { writeText: function (text) { window.__copied = text; return Promise.resolve(); } },
	configurable: true
});
window.imaginaPlayer = { restUrl: '', lazyInit: false, maxComputeBytes: 0, assetUrl: './', i18n: { resumeFrom: 'Seguir desde %s', startOver: 'Empezar de nuevo' } };
</script>
<script src="./frontend.js"></script>
</body></html>
HTML;

$probe = <<<'JS'
<script>
setTimeout(function () {
	var out = {};
	var first = document.querySelector('#intro .imgp');
	var second = document.querySelector('#other .imgp');
	var media1 = first.querySelector('.imgp__media');
	var media2 = second.querySelector('.imgp__media');

	// The address said #t=95&player=other.
	out.linkSeeked = Math.round(media2.currentTime);
	out.linkLeftFirstAlone = Math.round(media1.currentTime);
	out.linkDidNotPlay = media2.paused;

	// Resume: the first player opened at 0:42 and said so.
	var chip = first.querySelector('.imgp__resume');
	out.chipShown = !!chip;
	out.chipText = chip ? chip.querySelector('.imgp__resume-text').textContent : '';
	out.chipOnSecond = !!second.querySelector('.imgp__resume');

	// Segments: a notch per chapter that is not at the start, in a segmented player.
	out.segmented = first.classList.contains('imgp--chaptered');
	out.notches = first.querySelectorAll('.imgp__mark').length;

	// Hovering the bar names the chapter under the pointer.
	var scrubber = first.querySelector('.imgp__scrubber');
	var box = scrubber.getBoundingClientRect();
	scrubber.dispatchEvent(new PointerEvent('pointermove', { clientX: box.left + box.width * 0.5, clientY: box.top + 2, bubbles: true }));
	var tip = first.querySelector('.imgp__chapter-tip');
	out.tipShown = !!tip && !tip.hidden;
	out.tipTitle = tip ? tip.querySelector('.imgp__chapter-tip-title').textContent : '';
	out.tipTime = tip ? tip.querySelector('.imgp__chapter-tip-time').textContent : '';
	scrubber.dispatchEvent(new PointerEvent('pointerleave', { bubbles: true }));
	out.tipHiddenAfter = !!tip && tip.hidden;

	// The speed button opens a list instead of cycling.
	var speed = first.querySelector('.imgp__speed');
	speed.click();
	var menu = first.querySelector('.imgp__menu');
	out.speedMenuOpen = !menu.hidden;
	var items = Array.prototype.slice.call(menu.querySelectorAll('.imgp__menuitem'));
	out.speedChoices = items.length;
	out.normalChecked = items.some(function (b) { return b.getAttribute('aria-checked') === 'true' && b.textContent === 'Normal'; });
	var pick = items.filter(function (b) { return b.textContent === '1.5×'; })[0];
	if (pick) { pick.click(); }
	out.rateAfterPick = media1.playbackRate;
	out.buttonAfterPick = speed.textContent;
	out.menuClosedAfterPick = menu.hidden;

	// The gear.
	var gear = first.querySelector('.imgp__vbtn--settings');
	out.gearShown = !!gear && !gear.hidden;
	gear.click();
	var gearItems = Array.prototype.slice.call(menu.querySelectorAll('.imgp__menuitem')).map(function (b) { return b.textContent; });
	out.gearItems = gearItems;
	var speedRow = menu.querySelector('.imgp__menuitem');
	speedRow.click();
	out.gearSpeedList = menu.querySelectorAll('.imgp__menuitem').length;
	out.gearSpeedStillOpen = !menu.hidden;
	// Close and open the gear again to copy the link.
	gear.click();
	gear.click();
	var copy = Array.prototype.slice.call(menu.querySelectorAll('.imgp__menuitem')).filter(function (b) { return b.textContent.indexOf('Copy link') === 0; })[0];
	media1.currentTime = 77;
	if (copy) { copy.click(); }

	setTimeout(function () {
		out.copied = window.__copied;
		out.toast = first.querySelector('.imgp__toast') ? first.querySelector('.imgp__toast').textContent : '';

		// The timestamp button: seeks the player it names and starts it.
		var jumpButton = document.querySelector('.imgp-time');
		jumpButton.click();

		setTimeout(function () {
			out.jumped = Math.round(media1.currentTime);
			out.jumpLeftSecondAlone = Math.round(media2.currentTime);

			// Start over: back to the beginning and forgotten.
			var restart = first.querySelector('.imgp__resume-restart');
			if (restart) { restart.click(); }
			out.restarted = Math.round(media1.currentTime);
			out.forgotten = window.localStorage.getItem('imagina-player:position:' + 'KEY') === null;
			out.chipGone = !first.querySelector('.imgp__resume');

			var pre = document.createElement('pre');
			pre.textContent = 'RESULT:' + JSON.stringify(out);
			document.body.appendChild(pre);
		}, 300);
	}, 200);
}, 1500);
</script>
JS;

$probe = str_replace( "'KEY'", json_encode( $layer_key ), $probe );

$file = $workdir . '/moments.html';
file_put_contents( $file, str_replace( '</body></html>', $probe . '</body></html>', $page ) );

$dom = (string) shell_exec(
	sprintf(
		'%s --headless=new --no-sandbox --disable-gpu --allow-file-access-from-files --window-size=900,700 --virtual-time-budget=12000 --dump-dom %s 2>/dev/null',
		escapeshellarg( $browser ),
		escapeshellarg( 'file://' . $file . '#t=1m35s&player=other' )
	)
);

if ( getenv( 'MOMENTS_DEBUG' ) ) {
	copy( $file, (string) getenv( 'MOMENTS_DEBUG' ) );
	file_put_contents( (string) getenv( 'MOMENTS_DEBUG' ) . '.dom', $dom );
}

exec( 'rm -rf ' . escapeshellarg( $workdir ) );

if ( ! preg_match( '/RESULT:(\{.*?\})</s', $dom, $matches ) ) {
	check( 'the page reported', false, 'no result' );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

$r = json_decode( html_entity_decode( $matches[1] ), true );

if ( isset( $r['error'] ) ) {
	check( 'the probe ran without throwing', false, (string) $r['error'] );
	echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
	exit( 1 );
}

echo '## A link to a moment' . PHP_EOL;
check( '#t=1m35s&player=other takes the named player to 1:35', 95 === (int) ( $r['linkSeeked'] ?? -1 ), (string) ( $r['linkSeeked'] ?? '?' ) );
check( 'and leaves the other where it was', 42 === (int) ( $r['linkLeftFirstAlone'] ?? -1 ), (string) ( $r['linkLeftFirstAlone'] ?? '?' ) );
check( 'without starting it', true === ( $r['linkDidNotPlay'] ?? false ) );

echo '## Picking up where the viewer stopped' . PHP_EOL;
check( 'the player that remembers says where it resumed from', true === ( $r['chipShown'] ?? false ) );
check( 'in the site’s words, with the time', 'Seguir desde 0:42' === ( $r['chipText'] ?? '' ), (string) ( $r['chipText'] ?? '' ) );
check( 'a player with nothing to resume says nothing', false === ( $r['chipOnSecond'] ?? true ) );

echo '## The segmented bar' . PHP_EOL;
check( 'the player is marked as segmented', true === ( $r['segmented'] ?? false ) );
check( 'two notches for three chapters — the first starts at zero', 2 === (int) ( $r['notches'] ?? 0 ), (string) ( $r['notches'] ?? '?' ) );
check( 'hovering the middle of the bar names the middle chapter', true === ( $r['tipShown'] ?? false ) && 'The middle' === ( $r['tipTitle'] ?? '' ), (string) ( $r['tipTitle'] ?? '' ) );
check( 'with the time under the pointer', '1:40' === ( $r['tipTime'] ?? '' ), (string) ( $r['tipTime'] ?? '' ) );
check( 'and the tip leaves with the pointer', true === ( $r['tipHiddenAfter'] ?? false ) );

echo '## Speed' . PHP_EOL;
check( 'the speed button opens a list', true === ( $r['speedMenuOpen'] ?? false ) );
check( 'of seven speeds', 7 === (int) ( $r['speedChoices'] ?? 0 ), (string) ( $r['speedChoices'] ?? '?' ) );
check( 'with the current one marked', true === ( $r['normalChecked'] ?? false ) );
check( 'picking 1.5× sets it', 1.5 === (float) ( $r['rateAfterPick'] ?? 0 ), (string) ( $r['rateAfterPick'] ?? '?' ) );
check( 'and the button shows it', '1.5×' === ( $r['buttonAfterPick'] ?? '' ), (string) ( $r['buttonAfterPick'] ?? '' ) );
check( 'and the list closes', true === ( $r['menuClosedAfterPick'] ?? false ) );

echo '## The gear' . PHP_EOL;
check( 'the gear is shown', true === ( $r['gearShown'] ?? false ) );
check( 'it offers speed and a link', array( 'Speed · 1.5×', 'Copy link to this moment' ) === ( $r['gearItems'] ?? array() ), wp_json_encode( $r['gearItems'] ?? null ) );
check( 'speed opens the list in the same panel', 7 === (int) ( $r['gearSpeedList'] ?? 0 ) && true === ( $r['gearSpeedStillOpen'] ?? false ), wp_json_encode( array( $r['gearSpeedList'] ?? null, $r['gearSpeedStillOpen'] ?? null ) ) );
check( 'copying writes the address with the second and the player', is_string( $r['copied'] ?? null ) && str_contains( (string) $r['copied'], 't=77' ) && str_contains( (string) $r['copied'], 'player=intro' ) && ! str_contains( (string) $r['copied'], '#' ), (string) ( $r['copied'] ?? 'null' ) );
check( 'and says so', 'Link copied' === ( $r['toast'] ?? '' ), (string) ( $r['toast'] ?? '' ) );

echo '## A timestamp button' . PHP_EOL;
check( 'takes the player it names to 1:35', 95 === (int) ( $r['jumped'] ?? -1 ), (string) ( $r['jumped'] ?? '?' ) );
check( 'and leaves the other alone', 95 === (int) ( $r['jumpLeftSecondAlone'] ?? -1 ), (string) ( $r['jumpLeftSecondAlone'] ?? '?' ) );

echo '## Start over' . PHP_EOL;
check( 'goes back to the beginning', 0 === (int) ( $r['restarted'] ?? -1 ), (string) ( $r['restarted'] ?? '?' ) );
check( 'forgets the position', true === ( $r['forgotten'] ?? false ) );
check( 'and takes the chip away', true === ( $r['chipGone'] ?? false ) );

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
