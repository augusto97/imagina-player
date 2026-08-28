<?php
/**
 * Script and style registration.
 *
 * Front-end assets are registered but never enqueued globally: a page only
 * loads the player bundle if it actually renders a player.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Assets {

	public const FRONTEND_HANDLE = 'imagina-player';

	public const EDITOR_HANDLE = 'imagina-player-editor';

	private static bool $frontend_enqueued = false;

	public function hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
	}

	public function register(): void {
		$frontend = self::asset_meta( 'frontend' );

		wp_register_script(
			self::FRONTEND_HANDLE,
			URL . 'build/frontend.js',
			$frontend['dependencies'],
			$frontend['version'],
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);

		wp_register_style(
			self::FRONTEND_HANDLE,
			URL . 'build/style-frontend.css',
			array(),
			$frontend['version']
		);

		$editor = self::asset_meta( 'editor' );

		wp_register_script(
			self::EDITOR_HANDLE,
			URL . 'build/editor.js',
			$editor['dependencies'],
			$editor['version'],
			true
		);

		wp_register_style(
			self::EDITOR_HANDLE,
			URL . 'build/editor.css',
			array( 'wp-edit-blocks' ),
			$editor['version']
		);

		wp_set_script_translations( self::FRONTEND_HANDLE, 'imagina-player', PATH . 'languages' );
		wp_set_script_translations( self::EDITOR_HANDLE, 'imagina-player', PATH . 'languages' );
	}

	/**
	 * The addresses the preview iframe loads, with a cache-busting version.
	 *
	 * The block preview and the settings screen both build an iframe by hand and
	 * point it at the real front-end files, so they miss everything WordPress
	 * does for an enqueued asset — including `?ver=`. Without it the browser is
	 * entitled to keep serving whatever it cached the first time, and it does:
	 * after an update the editor went on drawing a video with a stylesheet that
	 * predated video, so the picture had no shape and the controls fell out from
	 * under it. Nothing was wrong with the page; it was months old.
	 *
	 * @return array<string, string>
	 */
	public static function preview_assets(): array {
		$version = self::asset_meta( 'frontend' )['version'];

		return array(
			'frontendCss' => add_query_arg( array( 'ver' => $version ), URL . 'build/style-frontend.css' ),
			'frontendJs'  => add_query_arg( array( 'ver' => $version ), URL . 'build/frontend.js' ),
			// Not built by webpack, so it has no hash of its own; the plugin
			// version is enough, since it only changes when the plugin does.
			'frameCss'    => add_query_arg( array( 'ver' => VERSION ), URL . 'assets/preview-frame.css' ),
		);
	}

	/**
	 * Enqueue the front-end bundle, once, for a page that renders a player.
	 */
	public static function enqueue_frontend(): void {
		if ( self::$frontend_enqueued ) {
			return;
		}

		self::$frontend_enqueued = true;

		wp_enqueue_script( self::FRONTEND_HANDLE );

		$advanced = Settings::advanced();

		if ( ! empty( $advanced['load_frontend_css'] ) ) {
			wp_enqueue_style( self::FRONTEND_HANDLE );
		}

		wp_add_inline_script(
			self::FRONTEND_HANDLE,
			'window.imaginaPlayer = ' . wp_json_encode( self::runtime_data() ) . ';',
			'before'
		);

		$custom = trim( (string) ( $advanced['custom_css'] ?? '' ) );

		if ( '' !== $custom ) {
			wp_add_inline_style( self::FRONTEND_HANDLE, wp_strip_all_tags( $custom ) );
		}
	}

	/**
	 * Data shared by every player on the page.
	 *
	 * @return array<string, mixed>
	 */
	public static function runtime_data(): array {
		$advanced = Settings::advanced();
		$peaks    = Settings::peaks_settings();

		return array(
			'restUrl'         => esc_url_raw( rest_url( Rest\PeaksController::REST_NAMESPACE ) ),
			// The player loads its video chrome on demand. Webpack would work
			// the location out from the script's own URL, but that throws
			// outright when an optimisation plugin inlines the bundle — so the
			// side that actually knows the answer gives it.
			'assetUrl'        => esc_url_raw( URL . 'build/' ),
			'lazyInit'        => ! empty( $advanced['lazy_init'] ),
			'maxComputeBytes' => (int) ( $peaks['max_client_bytes'] ?? 25 * 1024 * 1024 ),
			'i18n'            => array(
				'play'   => __( 'Play', 'imagina-player' ),
				'pause'  => __( 'Pause', 'imagina-player' ),
				'mute'   => __( 'Mute', 'imagina-player' ),
				'unmute' => __( 'Unmute', 'imagina-player' ),
				'captionsOff' => __( 'Off', 'imagina-player' ),
				'qualityAuto' => __( 'Auto', 'imagina-player' ),
				'layerFailed' => __( 'That could not be sent. Please try again.', 'imagina-player' ),
			),
		);
	}

	/**
	 * Read the dependency manifest webpack writes next to each bundle.
	 *
	 * @return array{dependencies: array<int, string>, version: string}
	 */
	private static function asset_meta( string $entry ): array {
		$file = PATH . 'build/' . $entry . '.asset.php';

		if ( is_readable( $file ) ) {
			$meta = require $file;

			if ( is_array( $meta ) ) {
				return array(
					'dependencies' => isset( $meta['dependencies'] ) && is_array( $meta['dependencies'] ) ? $meta['dependencies'] : array(),
					'version'      => isset( $meta['version'] ) ? (string) $meta['version'] : VERSION,
				);
			}
		}

		return array(
			'dependencies' => array(),
			'version'      => VERSION,
		);
	}
}
