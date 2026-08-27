<?php
/**
 * Block registration.
 *
 * Attribute definitions are generated from the PHP schema and injected at
 * registration time rather than duplicated into `block.json`. The editor picks
 * them up through WordPress's server-side block definitions, so the schema stays
 * in one place.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Blocks;

use ImaginaPlayer\Assets;
use ImaginaPlayer\Player\Attributes;
use ImaginaPlayer\Render\PlayerRenderer;
use ImaginaPlayer\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BlockRegistrar {

	public const BLOCK_NAME = 'imagina/audio-player';

	public function hooks(): void {
		add_action( 'init', array( $this, 'register' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_data' ) );
	}

	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			\ImaginaPlayer\PATH . 'blocks/audio',
			array(
				'attributes'      => self::block_attributes(),
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/**
	 * Translate the PHP attribute schema into block attribute definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function block_attributes(): array {
		$attributes = array();

		foreach ( Attributes::schema() as $name => $definition ) {
			$attributes[ $name ] = match ( $definition['type'] ) {
				'int', 'float' => array(
					'type'    => 'number',
					'default' => $definition['default'],
				),
				'bool'         => array(
					'type'    => 'boolean',
					'default' => (bool) $definition['default'],
				),
				default        => array(
					'type'    => 'string',
					'default' => (string) $definition['default'],
				),
			};
		}

		return $attributes;
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( array $attributes, string $content = '', mixed $block = null ): string {
		$renderer = new PlayerRenderer();
		$html     = $renderer->render( $attributes );

		if ( '' === $html ) {
			return '';
		}

		$wrapper = get_block_wrapper_attributes( array( 'class' => 'imgp-block' ) );

		return sprintf( '<div %s>%s</div>', $wrapper, $html );
	}

	/**
	 * Hand the editor the preset list and the override schema so its inspector
	 * can be generated instead of hand-maintained.
	 */
	public function enqueue_editor_data(): void {
		$presets = array();

		foreach ( Settings::presets() as $key => $preset ) {
			$presets[] = array(
				'value' => $key,
				'label' => (string) $preset['label'],
			);
		}

		wp_add_inline_script(
			Assets::EDITOR_HANDLE,
			'window.imaginaPlayerEditor = ' . wp_json_encode(
				array(
					'presets'     => $presets,
					'skins'       => Settings::skins(),
					'overrides'   => Attributes::override_map(),
					'presetShape' => Settings::preset_defaults(),
					'settingsUrl' => esc_url_raw( admin_url( 'admin.php?page=imagina-player' ) ),
					// The preview runs the real player inside an iframe, so it needs
					// the real assets rather than a copy of them.
					'frontendCss' => \ImaginaPlayer\URL . 'build/style-frontend.css',
					'frontendJs'  => \ImaginaPlayer\URL . 'build/frontend.js',
					'frameCss'    => \ImaginaPlayer\URL . 'assets/preview-frame.css',
				)
			) . ';',
			'before'
		);
	}
}
