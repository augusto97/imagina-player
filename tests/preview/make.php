<?php
$plugin = dirname( __DIR__, 2 ) . '/';
require $plugin . 'tests/wp-stubs.php';
require_once $plugin . 'src/Support/Autoloader.php';
ImaginaPlayer\Support\Autoloader::register( 'ImaginaPlayer', $plugin . 'src' );
define( 'ImaginaPlayer\VERSION', '0.1.0' );
define( 'ImaginaPlayer\PATH', $plugin );
define( 'ImaginaPlayer\URL', './' );

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

$html = $make( array() );

$html_teal = $make( array(
	'accent'       => '#0b7285',
	'waveColor'    => '#ced4da',
	'waveProgress' => '#0b7285',
	'metaColor'    => '#0b7285',
	'height'       => '84',
	'showSkip'     => 'yes',
	'showSpeed'    => 'yes',
	'showDownload' => 'yes',
) );

$html_bar = $make( array(
	'skin'      => 'bar',
	'accent'    => '#e8590c',
	'metaColor' => '#e8590c',
) );

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
	<div class="variant">
		<h2>Preset por defecto</h2>
		$html
	</div>
	<div class="variant">
		<h2>Overrides por bloque: colores, altura y controles extra</h2>
		$html_teal
	</div>
	<div class="variant">
		<h2>Skin «bar» — mismo markup, sin canvas</h2>
		$html_bar
	</div>
</div>
<script>window.imaginaPlayer = {"restUrl":"","lazyInit":false,"i18n":{"play":"Reproducir","pause":"Pausa","mute":"Silenciar","unmute":"Activar sonido"}};</script>
<script>$js</script>
</body>
</html>
HTML;

file_put_contents( __DIR__ . '/index.html', $page );
echo "written\n";
