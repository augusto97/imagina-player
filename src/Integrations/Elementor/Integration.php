<?php
/**
 * Elementor: the three players as widgets.
 *
 * Elementor builds a page from widgets, each with a settings panel; a block
 * is not one of them, and a shortcode inside a text widget has no panel at
 * all. So the audio player, the video player and the playlist are offered
 * as widgets of their own, in their own category, with the same choices the
 * blocks offer — and rendered by the same renderer, so a player on an
 * Elementor page is the player on any other page.
 *
 * Nothing here runs unless Elementor is active: the hooks fire only from
 * Elementor, and the widget classes extend Elementor's own and are loaded
 * only when it asks for them.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Integrations\Elementor;

use ImaginaPlayer\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Integration {

	/** The category the three widgets sit in. */
	public const CATEGORY = 'imagina-player';

	/** The oldest Elementor whose widget registration this uses. */
	public const MINIMUM_ELEMENTOR = '3.5.0';

	public function hooks(): void {
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );

		/*
		 * The editor draws widgets inside a preview frame, and a player there
		 * needs the front-end script to become one — the same script the
		 * page will load, with the same runtime object. Its markup arrives
		 * later, by request, and the script watches for that.
		 */
		add_action( 'elementor/preview/enqueue_scripts', array( Assets::class, 'enqueue_frontend' ) );
	}

	/**
	 * @param object $elements_manager Elementor's elements manager.
	 */
	public function register_category( $elements_manager ): void {
		if ( ! is_object( $elements_manager ) || ! method_exists( $elements_manager, 'add_category' ) ) {
			return;
		}

		$elements_manager->add_category(
			self::CATEGORY,
			array(
				'title' => __( 'Imagina Player', 'imagina-player' ),
				'icon'  => 'eicon-play',
			)
		);
	}

	/**
	 * @param object $widgets_manager Elementor's widgets manager.
	 */
	public function register_widgets( $widgets_manager ): void {
		if ( ! self::supported() || ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'register' ) ) {
			return;
		}

		foreach ( self::widget_classes() as $class ) {
			$widgets_manager->register( new $class() );
		}
	}

	/**
	 * The widgets, as class names.
	 *
	 * @return array<int, class-string>
	 */
	public static function widget_classes(): array {
		return array(
			AudioWidget::class,
			VideoWidget::class,
			PlaylistWidget::class,
		);
	}

	/**
	 * Whether the Elementor on this site can take these widgets.
	 *
	 * `register()` on the widgets manager and the `elementor/widgets/register`
	 * hook arrived together in 3.5; an older Elementor fires neither, and an
	 * Elementor without `Widget_Base` is not one at all.
	 */
	public static function supported(): bool {
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
			return false;
		}

		return ! defined( 'ELEMENTOR_VERSION' ) || version_compare( (string) ELEMENTOR_VERSION, self::MINIMUM_ELEMENTOR, '>=' );
	}
}
