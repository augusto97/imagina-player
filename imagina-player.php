<?php
/**
 * Plugin Name:       Imagina Player
 * Plugin URI:        https://github.com/augusto97/imagina-player
 * Description:       Modern, fast and accessible audio &amp; video player for WordPress. Waveform audio player, Gutenberg blocks, reusable presets and a shortcode compatibility layer for legacy players.
 * Version:           1.0.1
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Imagina
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       imagina-player
 * Domain Path:       /languages
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION  = '1.0.1';
const MIN_PHP  = '8.0';
const SLUG     = 'imagina-player';
const PREFIX   = 'imgp';

define( 'ImaginaPlayer\FILE', __FILE__ );
define( 'ImaginaPlayer\PATH', plugin_dir_path( __FILE__ ) );
define( 'ImaginaPlayer\URL', plugin_dir_url( __FILE__ ) );

/**
 * Bail out early — with an admin notice instead of a fatal — on unsupported PHP.
 */
if ( version_compare( PHP_VERSION, MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'Imagina Player requires PHP %1$s or newer. This site runs PHP %2$s.', 'imagina-player' ),
						MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);

	return;
}

require_once PATH . 'src/Support/Autoloader.php';

Support\Autoloader::register( __NAMESPACE__, PATH . 'src' );

Plugin::instance()->boot();

register_activation_hook( __FILE__, array( Plugin::class, 'on_activation' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'on_deactivation' ) );
