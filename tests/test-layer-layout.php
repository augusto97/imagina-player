<?php
/**
 * How a call to action looks once it is on the page.
 *
 * This exists because of a bug nothing else here could have caught. The layer
 * markup was correct, the sanitising was correct, the runtime showed it at the
 * right moment — and on a live site it rendered as a full-width sheet of brand
 * colour lying across the player, with a button in almost exactly the colour of
 * the sheet behind it. Every check passed. It looked terrible.
 *
 * So the checks below are about geometry and contrast rather than markup: does
 * the panel sit under the player instead of over it, does it stay inside the
 * player's width, is it a strip rather than a slab, and can you actually see
 * the button. Those are the things that were wrong, and they are only knowable
 * after a real layout.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Peaks\PeaksRepository;
use ImaginaPlayer\Player\Config;
use ImaginaPlayer\Render\PlayerRenderer;

$root = dirname( __DIR__ );

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

echo PHP_EOL . '# A foreground that survives the accent' . PHP_EOL;

/*
 * `--imgp-on-accent` had no value anywhere and fell back to white everywhere,
 * which is right on a deep magenta and unreadable on the bright cyan this
 * plugin's own brand uses.
 */
check( 'a bright accent gets a dark label', '#111111' === Config::readable_on( '#00c2d8' ), Config::readable_on( '#00c2d8' ) );
check( 'a deep accent gets a light one', '#ffffff' === Config::readable_on( '#c2185b' ), Config::readable_on( '#c2185b' ) );
check( 'shorthand hex is understood', Config::readable_on( '#fff' ) === Config::readable_on( '#ffffff' ) );
check( 'case does not matter', Config::readable_on( '#00C2D8' ) === Config::readable_on( '#00c2d8' ) );
check( 'something unparseable falls back rather than breaking', '#ffffff' === Config::readable_on( 'rebeccapurple' ) );

$vars = Config::css_variables( Config::resolve( array( 'accent' => '#00c2d8' ) ) );
check( 'and it reaches the player as a custom property', '#111111' === ( $vars['--imgp-on-accent'] ?? '' ), (string) ( $vars['--imgp-on-accent'] ?? 'missing' ) );

echo PHP_EOL . '# What the layout does' . PHP_EOL;

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
	echo 'SKIP  no Chromium found; layer layout not measured' . PHP_EOL;
	echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
	exit( $failures ? 1 : 0 );
}

$workdir = $root . '/build/.layer-test';

if ( ! is_dir( $workdir ) ) {
	mkdir( $workdir, 0777, true );
}

copy( $root . '/build/style-frontend.css', $workdir . '/style-frontend.css' );

$peaks = array();

for ( $i = 0; $i < 400; $i++ ) {
	$peaks[] = min( 1.0, max( 0.12, 0.55 + sin( $i * 0.35 ) * 0.25 ) );
}

$encoded  = PeaksRepository::encode( $peaks );
$renderer = new PlayerRenderer();

$stacks = array(
	'cta'   => array( array( 'type' => 'cta', 'at' => 50, 'title' => 'Llévate el curso completo', 'text' => 'Diez sesiones más, con ejercicios y plantillas.', 'button' => 'Quiero verlo', 'url' => 'https://example.test/curso', 'skip' => true ) ),
	'email' => array( array( 'type' => 'email', 'at' => 50, 'title' => 'Escucha el resto', 'text' => 'Te mandamos el enlace al correo.', 'button' => 'Enviármelo', 'consent' => 'Sin spam. Puedes darte de baja cuando quieras.', 'thanks' => 'Hecho.', 'list' => 'curso', 'skip' => true ) ),
	'bar'   => array( array( 'type' => 'bar', 'at' => 10, 'title' => 'Oferta de lanzamiento', 'text' => '30% menos esta semana.', 'button' => 'Ver oferta', 'url' => 'https://example.test/oferta' ) ),
);

$sections = '';

// The brand's own accent on purpose: bright, and the case white labels fail on.
foreach ( $stacks as $name => $stack ) {
	$html = $renderer->render(
		array(
			'src'    => 'https://cdn.example.com/track.mp3',
			'title'  => 'La Tiendita del Amor — episodio 12',
			'artist' => 'Elízabeth Guerra',
			'accent' => '#00c2d8',
			'layers' => $stack,
		)
	);

	$html      = str_replace( 'data-imagina-player=', 'data-peaks="' . $encoded . '" data-imagina-player=', $html );
	$sections .= sprintf( '<section data-case="audio-%s">%s</section>', esc_attr( $name ), $html );
}

foreach ( $stacks as $name => $stack ) {
	$sections .= sprintf(
		'<section data-case="video-%s">%s</section>',
		esc_attr( $name ),
		$renderer->render(
			array(
				'src'    => 'https://cdn.example.com/clip.mp4',
				'title'  => 'La Tiendita del Amor — episodio 12',
				'accent' => '#00c2d8',
				'layers' => $stack,
			)
		)
	);
}

$page = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="./style-frontend.css">
<style>html,body{margin:0;padding:0;background:#fff}section{margin:0 0 20px}</style>
</head><body>
{$sections}
<script>
/* The layers are rendered hidden and unhidden by the runtime at the right
   moment. Nothing here is testing the timing, so they are simply shown. */
document.querySelectorAll('.imgp__layer').forEach(function (el) { el.hidden = false; });
document.querySelectorAll('.imgp--video').forEach(function (el) { el.classList.add('has-modal-layer'); });

function channel(v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }

/*
 * Every colour goes through a canvas rather than a regular expression, because
 * a computed background is not always `rgb()`. A `color-mix()` resolves to
 * `color(srgb 0 0.7 0.78)`, whose numbers are 0–1 — read as 0–255 that is
 * indistinguishable from black, and a contrast check that believes every mixed
 * colour is black passes everything. Painting it and reading the pixel back
 * gives one answer in one format whatever the source notation was.
 */
var probeCtx = document.createElement('canvas').getContext('2d', { willReadFrequently: true });

function pixel(color) {
	probeCtx.clearRect(0, 0, 1, 1);
	probeCtx.fillStyle = '#000';
	probeCtx.fillStyle = color;
	// An unparseable value leaves fillStyle at the previous one; black is not a
	// colour any of this uses, so it is a usable "did not parse" marker.
	if (probeCtx.fillStyle === '#000000' && !/^(#000000|black|rgb\(0, 0, 0\))$/.test(String(color).trim())) {
		return null;
	}
	probeCtx.fillRect(0, 0, 1, 1);
	var d = probeCtx.getImageData(0, 0, 1, 1).data;
	return [ d[0], d[1], d[2] ];
}

function luminance(color) {
	var rgb = pixel(color);
	if (!rgb) { return null; }
	return 0.2126 * channel(rgb[0]) + 0.7152 * channel(rgb[1]) + 0.0722 * channel(rgb[2]);
}

function contrast(a, b) {
	var x = luminance(a), y = luminance(b);
	if (x === null || y === null) { return null; }
	return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
}

/** The nearest ancestor that actually paints something. */
function painted(el) {
	for (var node = el.parentElement; node; node = node.parentElement) {
		var bg = getComputedStyle(node).backgroundColor;
		if (bg && !/rgba\(0, 0, 0, 0\)|transparent/.test(bg)) { return bg; }
	}
	return 'rgb(255, 255, 255)';
}

window.__measure = function () {
	var report = {};

	document.querySelectorAll('section').forEach(function (section) {
		var player = section.querySelector('.imgp');
		var layer = section.querySelector('.imgp__layer');
		var button = layer.querySelector('.imgp__layer-button');
		var body = layer.querySelector('.imgp__layer-body');
		var playerBox = player.getBoundingClientRect();
		var layerBox = layer.getBoundingClientRect();
		var buttonStyle = getComputedStyle(button);

		/* Everything in the player that is not part of a layer. If a layer
		   overlaps any of it, the layer is lying across the player. */
		var covered = 0;

		player.querySelectorAll('.imgp__bar, .imgp__scrubber, .imgp__meta, .imgp__play').forEach(function (el) {
			if (el.closest('.imgp__layer')) { return; }
			var box = el.getBoundingClientRect();
			if (box.height === 0) { return; }
			var overlap = Math.min(box.bottom, layerBox.bottom) - Math.max(box.top, layerBox.top);
			if (overlap > covered) { covered = overlap; }
		});

		report[section.getAttribute('data-case')] = {
			height: Math.round(layerBox.height),
			overhang: Math.round(Math.max(layerBox.right - playerBox.right, playerBox.left - layerBox.left)),
			covers: Math.round(covered),
			bodyWidth: Math.round(body.getBoundingClientRect().width),
			buttonVsPanel: contrast(buttonStyle.backgroundColor, painted(button)),
			labelVsButton: contrast(buttonStyle.color, buttonStyle.backgroundColor)
		};
	});

	return report;
};
</script>
</body></html>
HTML;

$measured = array();

foreach ( array( 760, 360 ) as $width ) {
	$file = $workdir . '/layers.html';

	file_put_contents(
		$file,
		str_replace(
			'</body></html>',
			'<script>setTimeout(function(){var o=document.createElement("pre");'
			. 'o.textContent="RESULT:"+JSON.stringify(window.__measure());document.body.appendChild(o);},900);</script></body></html>',
			$page
		)
	);

	$dom = (string) shell_exec(
		sprintf(
			'%s --headless --no-sandbox --disable-gpu --window-size=%d,2400 --virtual-time-budget=6000 --dump-dom %s 2>/dev/null',
			escapeshellarg( $browser ),
			$width,
			escapeshellarg( 'file://' . $file )
		)
	);

	if ( ! preg_match( '/RESULT:(\{.*?\})</s', $dom, $matches ) ) {
		check( "the page reported at {$width}px", false );
		continue;
	}

	$measured[ $width ] = json_decode( $matches[1], true );
}

exec( 'rm -rf ' . escapeshellarg( $workdir ) );

// A strip beside audio, a sheet over video: the two are allowed very different
// heights, and neither is allowed the other's.
$ceilings = array(
	760 => array( 'audio-cta' => 110, 'audio-email' => 130, 'audio-bar' => 90 ),
	360 => array( 'audio-cta' => 200, 'audio-email' => 260, 'audio-bar' => 190 ),
);

foreach ( $measured as $width => $report ) {
	foreach ( (array) $report as $case => $data ) {
		$audio = str_starts_with( (string) $case, 'audio-' );

		/*
		 * The original fault, and the only one of these that is not a matter of
		 * taste: the panel was inside an absolutely positioned wrapper covering
		 * the whole player, so it lay across the waveform and the title.
		 */
		if ( $audio ) {
			check(
				"{$case} sits beside the player rather than on it at {$width}px",
				0 === (int) $data['covers'],
				$data['covers'] . 'px of the player is underneath it'
			);

			check(
				"{$case} stays inside the player at {$width}px",
				(int) $data['overhang'] <= 1,
				$data['overhang'] . 'px past the edge'
			);

			check(
				"{$case} is a strip, not a slab, at {$width}px",
				(int) $data['height'] <= $ceilings[ $width ][ $case ],
				$data['height'] . 'px tall, limit ' . $ceilings[ $width ][ $case ]
			);
		} elseif ( 'video-bar' === $case ) {
			/*
			 * The bar is the one layer that does not interrupt, so over a video
			 * it is a strip along the bottom edge and covering the picture would
			 * be the bug rather than the point.
			 */
			check(
				"{$case} is a strip along the bottom at {$width}px",
				(int) $data['height'] <= 110,
				$data['height'] . 'px tall'
			);

			check(
				"{$case} stays inside the picture at {$width}px",
				(int) $data['overhang'] <= 1,
				$data['overhang'] . 'px past the edge'
			);
		} else {
			// Over a picture a gate is meant to cover it.
			check(
				"{$case} covers the picture at {$width}px",
				(int) $data['height'] > 100,
				$data['height'] . 'px tall'
			);

			// Presto caps its column at 600px, Fluent at 500. A headline running
			// the full width of a wide video is unreadable.
			check(
				"{$case} keeps its column readable at {$width}px",
				(int) $data['bodyWidth'] <= 490,
				$data['bodyWidth'] . 'px wide'
			);
		}

		/*
		 * The button was `--imgp-accent` on a background that was 92% the same
		 * accent, so it was very nearly invisible. 3:1 is the WCAG threshold for
		 * a control being distinguishable from what is behind it.
		 */
		check(
			"{$case} has a button you can see at {$width}px",
			null !== $data['buttonVsPanel'] && (float) $data['buttonVsPanel'] >= 3.0,
			'contrast ' . round( (float) $data['buttonVsPanel'], 2 ) . ':1'
		);

		// And a label you can read on it, which is what the computed
		// `--imgp-on-accent` is for.
		check(
			"{$case} has a label you can read at {$width}px",
			null !== $data['labelVsButton'] && (float) $data['labelVsButton'] >= 4.5,
			'contrast ' . round( (float) $data['labelVsButton'], 2 ) . ':1'
		);
	}
}

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
