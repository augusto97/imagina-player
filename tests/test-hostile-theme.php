<?php
/**
 * The player inside a theme that fights it.
 *
 * Every other browser test in this suite renders the player into an empty page.
 * No real WordPress site is an empty page, and this is the gap a report went
 * straight through: on a live site the video was covered by a flat sheet of the
 * theme's pink, and every check here was green.
 *
 * The mechanism is ordinary CSS. The play button over the picture is a `button`
 * that covers the whole stage, and it declares `background: transparent` at one
 * class of specificity. A theme that says `.entry-content button { background:
 * #ff87ac }` — which is a completely normal thing for a theme to say — wins, and
 * paints the video. During playback only the button's circle and icon are faded
 * out, so what is left is a coloured rectangle with no clue what it is.
 *
 * So this renders the player under a stylesheet built out of the rules themes
 * actually ship, and asks the questions a person would ask looking at it: is
 * anything painted over the picture, are the controls still the size of
 * controls, is anything hanging outside the box.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Peaks\PeaksRepository;
use ImaginaPlayer\Player\Skins;
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

if ( '' === $browser ) {
	echo 'SKIP  no Chromium found; the player was not tried under a theme' . PHP_EOL;
	exit( 0 );
}

$workdir = $root . '/build/.hostile-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

foreach ( (array) glob( $root . '/build/*.{js,css}', GLOB_BRACE ) as $asset ) {
	copy( (string) $asset, $workdir . '/' . basename( (string) $asset ) );
}

/*
 * Not invented to be difficult. Every rule below is one that shipped themes
 * really do apply — brand buttons, embed backgrounds, fluid images, generous
 * form controls — written the way a theme writes them, inside a content
 * wrapper, at a specificity a bare component class does not beat.
 */
file_put_contents(
	$workdir . '/theme.css',
	<<<'CSS'
body { margin: 0; font-family: Georgia, serif; background: #fff; }

.entry-content button,
.entry-content input[type="submit"] {
	background: #ff87ac;
	color: #fff;
	border: 2px solid #d6336c;
	border-radius: 8px;
	padding: 12px 24px;
	min-height: 48px;
	text-transform: uppercase;
	letter-spacing: 0.08em;
	font-family: inherit;
	box-shadow: 0 2px 0 #d6336c;
}

.entry-content iframe {
	background: #ff87ac;
	border: 3px solid #d6336c;
	display: block;
	max-width: 100%;
}

.entry-content img {
	max-width: 100%;
	height: auto;
	border-radius: 12px;
}

.entry-content video,
.entry-content audio { background: #ff87ac; border-radius: 12px; }

.entry-content canvas { background: #ff87ac; }

.entry-content a { color: #d6336c; text-decoration: underline; }

.entry-content p { margin: 1.5em 0; line-height: 2; font-size: 18px; }

.entry-content input[type="email"],
.entry-content input[type="range"] {
	padding: 14px;
	border: 2px solid #d6336c;
	border-radius: 8px;
	min-height: 48px;
	background: #fff0f5;
}

.entry-content ol, .entry-content ul { padding-left: 2.5em; margin: 1.5em 0; }
.entry-content svg { max-width: 100%; }
CSS
);

$peaks = array();

for ( $i = 0; $i < 400; $i++ ) {
	$peaks[] = min( 1.0, max( 0.12, 0.55 + sin( $i * 0.35 ) * 0.25 ) );
}

$encoded  = PeaksRepository::encode( $peaks );
$renderer = new PlayerRenderer();

$sections = '';

// Audio, every skin, with everything switched on.
foreach ( array_keys( Skins::all() ) as $skin ) {
	$html = $renderer->render(
		array(
			'src'          => 'https://cdn.example.com/track.mp3',
			'title'        => 'Un episodio de prueba',
			'artist'       => 'Elízabeth Guerra',
			'skin'         => $skin,
			'accent'       => '#8e44ad',
			'showSkip'     => 'yes',
			'showSpeed'    => 'yes',
			'showVolume'   => 'yes',
			'showDownload' => 'yes',
		)
	);

	$html      = str_replace( 'data-imagina-player=', 'data-peaks="' . $encoded . '" data-imagina-player=', $html );
	$sections .= sprintf( '<section data-case="audio-%s">%s</section>', esc_attr( $skin ), $html );
}

// A self-hosted video, and a video on YouTube, which is the one that was
// reported: the frame is somebody else's and the button over it is ours.
$sections .= sprintf(
	'<section data-case="video-file">%s</section>',
	$renderer->render(
		array(
			'src'    => 'https://cdn.example.com/clip.mp4',
			'title'  => 'Vídeo propio',
			'accent' => '#8e44ad',
		)
	)
);

$sections .= sprintf(
	'<section data-case="video-youtube">%s</section>',
	$renderer->render(
		array(
			'src'    => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
			'title'  => 'Vídeo de YouTube',
			'accent' => '#8e44ad',
		)
	)
);

/*
 * And the things that sit on top of the picture.
 *
 * This whole file walks every element inside the player and compares it with
 * and without the theme, which sounds exhaustive and was not: no case rendered
 * here had a call to action on it, so the panel, its button, its form and its
 * close button were never in the tree being walked. The blanket reset above
 * strips every button's background, and the close button's was restated for
 * `:hover` and not for the state it spends its life in — a white glyph at
 * three-quarter opacity with nothing behind it, over whatever the video happens
 * to be showing.
 */
$layers = array(
	array(
		'type'   => 'cta',
		'at'     => 50,
		'title'  => 'Sigue la clase completa',
		'text'   => 'Cuarenta lecciones, con ejercicios.',
		'button' => 'Ver el curso',
		'url'    => 'https://example.test/curso',
		'skip'   => true,
	),
	array(
		'type'   => 'bar',
		'at'     => 10,
		'title'  => 'Descarga los apuntes',
		'button' => 'Descargar',
		'url'    => 'https://example.test/apuntes',
		'skip'   => true,
	),
	array(
		'type'    => 'email',
		'at'      => 80,
		'title'   => 'Recibe la siguiente',
		'text'    => 'Una al mes, nada más.',
		'button'  => 'Enviar',
		'consent' => 'Puedes darte de baja cuando quieras.',
		'skip'    => true,
	),
);

$sections .= sprintf(
	'<section data-case="video-layers">%s</section>',
	$renderer->render(
		array(
			'src'    => 'https://cdn.example.com/clip.mp4',
			'title'  => 'Vídeo con CTA',
			'accent' => '#8e44ad',
			'layers' => $layers,
		)
	)
);

$sections .= sprintf(
	'<section data-case="audio-layers">%s</section>',
	$renderer->render(
		array(
			'src'    => 'https://cdn.example.com/track.mp3',
			'title'  => 'Audio con CTA',
			'accent' => '#8e44ad',
			'layers' => $layers,
		)
	)
);

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./theme.css">
<link rel="stylesheet" href="./style-frontend.css">
<style>*{transition:none!important;animation:none!important}section{margin:0 0 24px}</style>
</head><body><div class="entry-content">
{$sections}
</div>
<script>
/* Stands in for YouTube, and behaves the way it does: it replaces the element
   it is handed with an iframe of its own making. */
window.YT = { Player: function (element, options) {
	var frame = document.createElement('iframe');
	frame.src = 'data:text/html,<body style="margin:0;background:%23204060"></body>';
	frame.width = 640;
	frame.height = 360;
	element.parentNode.replaceChild(frame, element);
	this.playVideo = function () { options.events.onStateChange({ data: 1 }); };
	this.pauseVideo = function () {};
	this.seekTo = function () {};
	this.setVolume = function () {};
	this.mute = function () {};
	this.unMute = function () {};
	this.setPlaybackRate = function () {};
	this.getCurrentTime = function () { return 19; };
	this.getDuration = function () { return 400; };
	var self = this;
	window.setTimeout(function () { options.events.onReady({ target: self }); }, 0);
} };

window.imaginaPlayer = { restUrl: '', lazyInit: false, maxComputeBytes: 0, assetUrl: './', i18n: {} };
</script>
<script src="./frontend.js"></script>
</body></html>
HTML;

$probe = <<<'JS'
<script>
function opaque(el) {
	var bg = getComputedStyle(el).backgroundColor;
	var m = String(bg).match(/rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?/);

	return !!m && (undefined === m[4] || parseFloat(m[4]) > 0.5);
}

/** Anything painted over the picture that is not the picture. */
function coversPicture(player) {
	var stage = player.querySelector('.imgp__stage');

	if (!stage) { return null; }

	var box = stage.getBoundingClientRect();
	var worst = null;

	player.querySelectorAll('.imgp__stage *').forEach(function (el) {
		// The frame, the box it sits in and the poster are the picture; the
		// chrome is meant to be over it and is a strip, not a sheet.
		if (el.closest('.imgp__poster') || 'IFRAME' === el.tagName) { return; }
		if (el.classList.contains('imgp__embed')) { return; }
		if (el.closest('.imgp__chrome') || el.classList.contains('imgp__chrome')) { return; }
		if (el.classList.contains('imgp__media')) { return; }

		var s = getComputedStyle(el);

		if ('0' === s.opacity || 'hidden' === s.visibility || 'none' === s.display) { return; }
		if (!opaque(el)) { return; }

		var r = el.getBoundingClientRect();
		var share = (r.width * r.height) / (box.width * box.height);

		if (share > 0.25 && (!worst || share > worst.share)) {
			worst = {
				cls: String(el.className) || el.tagName,
				bg: s.backgroundColor,
				share: Math.round(share * 100)
			};
		}
	});

	return worst;
}

window.__measure = function () {
	var report = {};

	document.querySelectorAll('section').forEach(function (section) {
		var player = section.querySelector('.imgp');
		var rect = player.getBoundingClientRect();
		var overflow = 0;
		// Which element, not just how far: "43px over" sends you reading the
		// whole stylesheet, and the name sends you to one rule.
		var offender = '';

		player.querySelectorAll('*').forEach(function (el) {
			var r = el.getBoundingClientRect();
			var over = Math.round(Math.max(r.right - rect.right, rect.left - r.left));

			if (over > overflow) {
				overflow = over;
				offender = ('string' === typeof el.className ? el.className : el.tagName) || el.tagName;
			}
		});

		// The transport buttons. A theme that gives every button 48px and
		// 24px of padding turns a row of controls into a stack of slabs.
		/*
		 * Every element, not just the buttons, and the properties a theme
		 * actually reaches: an earlier version of this compared only each
		 * button's size and colour, and so was perfectly happy while the
		 * theme's `border-radius: 8px` turned every round play button into a
		 * rounded square.
		 */
		var controls = {};

		player.querySelectorAll('*').forEach(function (el, i) {
			/*
			 * These two are sized and positioned by where playback is, so the
			 * two runs disagree about them for reasons that have nothing to do
			 * with the theme.
			 */
			if (el.classList.contains('imgp__progress') ||
				el.classList.contains('imgp__time--current')) { return; }

			var r = el.getBoundingClientRect();
			var s = getComputedStyle(el);

			// An SVG's className is an object, not a string.
			var name = 'string' === typeof el.className ? el.className : '';

			controls[(name || el.tagName) + '#' + i] = [
				Math.round(r.width),
				Math.round(r.height),
				s.backgroundColor,
				s.borderTopLeftRadius,
				s.borderTopWidth,
				s.color,
				/* Not the font: the site's typeface is meant to flow into the
				   player, and comparing it would only ever report that it did. */
				s.textTransform,
				s.letterSpacing
			].join('/');
		});

		var frame = player.querySelector('.imgp__embed iframe');
		var frameStyle = frame ? getComputedStyle(frame) : null;

		report[section.getAttribute('data-case')] = {
			frameBorder: frameStyle ? frameStyle.borderTopWidth : null,
			frameFills: frame && player.querySelector('.imgp__stage')
				? Math.round(
						(frame.getBoundingClientRect().width * frame.getBoundingClientRect().height) /
						(player.querySelector('.imgp__stage').getBoundingClientRect().width *
							player.querySelector('.imgp__stage').getBoundingClientRect().height) * 100
				  )
				: null,
			covers: coversPicture(player),
			overflow: overflow,
			offender: offender,
			controls: controls,
			height: Math.round(rect.height),
			width: Math.round(rect.width)
		};
	});

	return report;
};

setTimeout(function () {
	// Start the videos, because the reported fault only showed once playing.
	document.querySelectorAll('section[data-case^="video"] .imgp__play').forEach(function (b) { b.click(); });

	setTimeout(function () {
		var pre = document.createElement('pre');
		pre.textContent = 'RESULT:' + JSON.stringify(window.__measure());
		document.body.appendChild(pre);
	}, 800);
}, 900);
</script>
JS;

/*
 * Twice: the same page with the theme and without it. Comparing the two is
 * worth more than any threshold — the player is allowed to look however it
 * looks, and what is being asserted is that a theme makes no difference to it.
 * A fixed number like "a button is at most 44px tall" only tests whether I
 * remembered the design, which the first version of this file got wrong.
 */
$runs = array();

foreach ( array( 'themed' => true, 'bare' => false ) as $name => $themed ) {
	$html = $themed
		? $page
		: str_replace( '<link rel="stylesheet" href="./theme.css">', '<style>body{margin:0;background:#fff}</style>', $page );

	$file = $workdir . '/' . $name . '.html';
	file_put_contents( $file, str_replace( '</body></html>', $probe . '</body></html>', $html ) );

	$dom = (string) shell_exec(
		sprintf(
			'%s --headless --no-sandbox --disable-gpu --window-size=900,800 --virtual-time-budget=9000 --dump-dom %s 2>/dev/null',
			escapeshellarg( $browser ),
			escapeshellarg( 'file://' . $file )
		)
	);

	if ( ! preg_match( '/RESULT:(\{.*?\})</s', $dom, $matches ) ) {
		check( "the {$name} page reported", false, 'no result' );
		exec( 'rm -rf ' . escapeshellarg( $workdir ) );
		echo PHP_EOL . "{$failures} FAILURE(S)" . PHP_EOL;
		exit( 1 );
	}

	$runs[ $name ] = json_decode( $matches[1], true );
}

exec( 'rm -rf ' . escapeshellarg( $workdir ) );

$report = $runs['themed'];

echo PHP_EOL . '# Nothing of the theme\'s is painted over the picture' . PHP_EOL;

foreach ( (array) $report as $case => $data ) {
	if ( ! str_starts_with( (string) $case, 'video' ) ) {
		continue;
	}

	$covers = $data['covers'] ?? null;

	check(
		"{$case} shows the video, not a sheet of the theme's colour",
		null === $covers,
		null === $covers ? '' : $covers['cls'] . ' painted ' . $covers['bg'] . ' over ' . $covers['share'] . '% of it'
	);
}

$yt = (array) ( $report['video-youtube'] ?? array() );

check(
	'the provider frame fills the picture rather than being sized by the theme',
	100 === (int) ( $yt['frameFills'] ?? 0 ),
	( $yt['frameFills'] ?? '?' ) . '% of the stage'
);

check(
	'and the theme cannot draw a border around it',
	'0px' === ( $yt['frameBorder'] ?? '' ),
	(string) ( $yt['frameBorder'] ?? '?' )
);

echo PHP_EOL . '# The theme makes no difference' . PHP_EOL;

/*
 * A theme's `button { min-height: 48px; padding: 12px 24px }` is aimed at "Add
 * to cart", not at a mute icon. Left alone it turns a row of controls into a
 * stack of slabs, which is most of what "it looks horrible" means.
 */
foreach ( (array) $report as $case => $themed ) {
	$bare     = (array) ( $runs['bare'][ $case ] ?? array() );
	$mismatch = array();

	foreach ( (array) ( $themed['controls'] ?? array() ) as $name => $shape ) {
		$was = (string) ( $bare['controls'][ $name ] ?? '' );

		if ( $was !== $shape ) {
			$mismatch[] = $name . ': ' . $was . ' became ' . $shape;
		}
	}

	check(
		"{$case} looks the same with the theme as without it",
		array() === $mismatch,
		implode( '; ', array_slice( $mismatch, 0, 3 ) )
	);

	check(
		"{$case} is the same size with the theme as without it",
		(int) ( $themed['height'] ?? 0 ) === (int) ( $bare['height'] ?? -1 )
			&& (int) ( $themed['width'] ?? 0 ) === (int) ( $bare['width'] ?? -1 ),
		$bare['height'] . 'x' . $bare['width'] . ' became ' . $themed['height'] . 'x' . $themed['width']
	);
}

echo PHP_EOL . '# And nothing hangs outside the player' . PHP_EOL;

foreach ( (array) $report as $case => $data ) {
	check(
		"{$case} stays inside its own box",
		(int) ( $data['overflow'] ?? 0 ) <= 1,
		$data['overflow'] . 'px over — ' . (string) ( $data['offender'] ?? '?' )
	);
}

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
