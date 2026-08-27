<?php
$imgp_base_url = './';
$plugin        = dirname( __DIR__, 2 ) . '/';

require dirname( __DIR__ ) . '/bootstrap.php';

use ImaginaPlayer\Render\PlayerRenderer;

$renderer = new PlayerRenderer();
$peaks    = trim( file_get_contents( __DIR__ . '/peaks.txt' ) );

$make = static function ( array $atts ) use ( $renderer, $peaks ): string {
	$html = $renderer->render( array_merge( array(
		'src'    => './demo.wav',
		'title'  => '1.1 "El camino del amor"',
		'artist' => 'Elízabeth Guerra Gómez',
	), $atts ) );

	// Inject the pre-computed waveform the way a cached render would.
	return str_replace( 'data-imagina-player=', 'data-peaks="' . $peaks . '" data-imagina-player=', $html );
};

// One panel per skin, so a layout regression is visible at a glance.
$cover = './cover.png';

$panels = '';

foreach ( \ImaginaPlayer\Player\Skins::all() as $skin => $label ) {
	$atts = array( 'skin' => $skin );

	if ( in_array( $skin, array( 'card', 'pill', 'compact' ), true ) ) {
		$atts['thumbnail']     = $cover;
		$atts['showThumbnail'] = 'yes';
	}

	$panels .= '<div class="variant"><h2>' . esc_html( $label ) . ' <code>' . esc_html( $skin ) . '</code></h2>'
		. $make( $atts ) . '</div>';
}

$panels .= '<div class="variant"><h2>Overrides por bloque: colores, altura y controles extra</h2>'
	. $make( array(
		'accent'       => '#0b7285',
		'waveColor'    => '#ced4da',
		'waveProgress' => '#0b7285',
		'metaColor'    => '#0b7285',
		'height'       => '84',
		'showSkip'     => 'yes',
		'showSpeed'    => 'yes',
		'showDownload' => 'yes',
	) )
	. '</div>';

$css = file_get_contents( $plugin . 'build/style-frontend.css' );
$js  = file_get_contents( $plugin . 'build/frontend.js' );

$page = <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
	body { margin: 0; padding: 40px; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #fff; }
	.stage { max-width: 1200px; margin: 0 auto; }
	h2 { font: 600 13px/1 system-ui; letter-spacing: .08em; text-transform: uppercase; color: #888; margin: 0 0 14px; }
	.variant + .variant { margin-top: 56px; }
	$css
</style>
</head>
<body>
<div class="stage">
	$panels
</div>
<script>window.imaginaPlayer = {"restUrl":"","lazyInit":false,"i18n":{"play":"Reproducir","pause":"Pausa","mute":"Silenciar","unmute":"Activar sonido"}};</script>
<script>$js</script>
</body>
</html>
HTML;

file_put_contents( __DIR__ . '/index.html', $page );
echo "written\n";
