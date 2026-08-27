<?php
/**
 * The admin screen.
 *
 * A top-level menu that mounts a React application, rather than a WordPress
 * options form. Everything it reads and writes goes through the REST API, so
 * this class only has to put the mount point on the page and hand the app its
 * bootstrap data.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Admin;

use ImaginaPlayer\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dashboard {

	public const MENU_SLUG = 'imagina-player';

	public const HANDLE = 'imagina-player-admin';

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( \ImaginaPlayer\FILE ), array( $this, 'action_links' ) );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'Imagina Player', 'imagina-player' ),
			__( 'Imagina Player', 'imagina-player' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' ),
			self::menu_icon(),
			57
		);
	}

	/**
	 * Add a Settings link next to Deactivate on the plugins screen.
	 *
	 * @param array<int, string> $links Existing action links.
	 * @return array<int, string>
	 */
	public function action_links( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
			esc_html__( 'Settings', 'imagina-player' )
		);

		array_unshift( $links, $settings );

		return $links;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// The application draws everything, including its own heading.
		echo '<div id="imagina-player-admin" class="imagina-player-admin"></div>';
	}

	public function enqueue( string $hook ): void {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		$asset = self::asset_meta();

		// The logo and cover fields open the same media frame the rest of
		// wp-admin uses; it is not on a settings screen unless asked for.
		wp_enqueue_media();

		wp_enqueue_script(
			self::HANDLE,
			\ImaginaPlayer\URL . 'build/admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			self::HANDLE,
			\ImaginaPlayer\URL . 'build/admin.css',
			array(),
			$asset['version']
		);

		wp_set_script_translations( self::HANDLE, 'imagina-player', \ImaginaPlayer\PATH . 'languages' );

		// The preview iframe loads the real front-end assets, so it needs their
		// URLs rather than a copy of their contents.
		wp_add_inline_script(
			self::HANDLE,
			'window.imaginaPlayerAdmin = ' . wp_json_encode(
				array(
					'restUrl'     => esc_url_raw( rest_url( 'imagina-player/v1' ) ),
					'nonce'       => wp_create_nonce( 'wp_rest' ),
					'frontendCss' => \ImaginaPlayer\URL . 'build/style-frontend.css',
					'frontendJs'  => \ImaginaPlayer\URL . 'build/frontend.js',
					'frameCss'    => \ImaginaPlayer\URL . 'assets/preview-frame.css',
					'docsUrl'     => 'https://github.com/augusto97/imagina-player',
				)
			) . ';',
			'before'
		);

		// The screen provides its own chrome; the default admin padding fights it.
		wp_add_inline_style( self::HANDLE, '#wpcontent{padding-left:0}#wpbody-content{padding-bottom:0}' );
	}

	/**
	 * @return array{dependencies: array<int, string>, version: string}
	 */
	private static function asset_meta(): array {
		$file = \ImaginaPlayer\PATH . 'build/admin.asset.php';

		if ( is_readable( $file ) ) {
			$meta = require $file;

			if ( is_array( $meta ) ) {
				return array(
					'dependencies' => isset( $meta['dependencies'] ) && is_array( $meta['dependencies'] ) ? $meta['dependencies'] : array(),
					'version'      => isset( $meta['version'] ) ? (string) $meta['version'] : \ImaginaPlayer\VERSION,
				);
			}
		}

		return array(
			'dependencies' => array( 'wp-element', 'wp-api-fetch', 'wp-i18n' ),
			'version'      => \ImaginaPlayer\VERSION,
		);
	}

	private static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="black" stroke-width="2" stroke-linecap="round"><path d="M3 12h2M8 6v12M13 9v6M18 4v16M21 11v2"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- data URI for the menu icon.
	}
}
