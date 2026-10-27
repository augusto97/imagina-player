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
use ImaginaPlayer\Render\PlaylistRenderer;
use ImaginaPlayer\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BlockRegistrar {

	public const BLOCK_NAME = 'imagina/audio-player';

	public const VIDEO_BLOCK = 'imagina/video-player';

	public const PLAYLIST_BLOCK = 'imagina/playlist';

	/**
	 * What every block name above begins with, for code that looks for any of
	 * them in post content. Named here so it cannot be typed differently
	 * somewhere else — which it was.
	 */
	public const NAMESPACE_PREFIX = 'imagina/';

	/** The attribute a playlist keeps its tracks in. */
	public const PLAYLIST_ITEMS = 'items';

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
				'attributes'         => self::block_attributes(),
				'render_callback'    => array( $this, 'render' ),
				// Asked for only when the editor wants them; see `Variations`.
				'variation_callback' => array( Variations::class, 'audio' ),
			)
		);

		/*
		 * A block of its own rather than a mode of the audio one. The audio
		 * block always handled a video file, but it is called "Imagina Audio
		 * Player", carries an audio icon and says "upload an audio file" — so
		 * somebody looking for video in the inserter found nothing, and
		 * reasonably concluded there was nothing to find.
		 */
		register_block_type(
			\ImaginaPlayer\PATH . 'blocks/video',
			array(
				'attributes'         => self::block_attributes(),
				'render_callback'    => array( $this, 'render' ),
				'variation_callback' => array( Variations::class, 'video' ),
			)
		);

		register_block_type(
			\ImaginaPlayer\PATH . 'blocks/playlist',
			array(
				'attributes'      => array(
					'items'     => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array( 'type' => 'object' ),
					),
					'layout'    => array(
						'type'    => 'string',
						'default' => 'list',
					),
					'heading'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'preset'    => array(
						'type'    => 'string',
						'default' => Settings::DEFAULT_PRESET,
					),
					// A course: what has been watched, how far, where to
					// carry on, and optionally one lesson at a time.
					'course'           => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'courseSequential' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					// The course's own name, set once by the editor, so its
					// progress survives lessons being added or moved.
					'courseKey'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'className' => array(
						'type'    => 'string',
						'default' => '',
					),
				) + self::anchor_attribute() + self::access_attributes(),
				'render_callback'    => array( $this, 'render_playlist' ),
				'variation_callback' => array( Variations::class, 'playlist' ),
			)
		);
	}

	/**
	 * Who may play a playlist: the same five as a single player.
	 *
	 * @return array<string, array{type: string, default: string}>
	 */
	private static function access_attributes(): array {
		$out = array();

		foreach ( array( 'accessRule', 'accessIds', 'accessMessage', 'accessButton', 'accessUrl' ) as $name ) {
			$out[ $name ] = array(
				'type'    => 'string',
				'default' => '',
			);
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_playlist( array $attributes, string $content = '', mixed $block = null ): string {
		$renderer = new PlaylistRenderer();
		$html     = $renderer->render( $attributes );

		if ( '' === $html ) {
			return '';
		}

		$wrapper = get_block_wrapper_attributes( self::wrapper_attributes( $attributes ) );

		return sprintf( '<div %s>%s</div>', $wrapper, $html );
	}

	/**
	 * The wrapper's class, and its id when the author gave the block one.
	 *
	 * The editor's "HTML anchor" field writes an `anchor` attribute, which a
	 * static block prints itself and a dynamic block has to print by hand:
	 * core adds no id on the server. It is what a timestamp button or a
	 * `?player=` link names when a page carries more than one player.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array<string, string>
	 */
	public static function wrapper_attributes( array $attributes ): array {
		$wrapper = array( 'class' => 'imgp-block' );
		$anchor  = self::anchor( (string) ( $attributes['anchor'] ?? '' ) );

		if ( '' !== $anchor ) {
			$wrapper['id'] = $anchor;
		}

		return $wrapper;
	}

	/**
	 * An anchor as the editor's own field allows it.
	 *
	 * `sanitize_html_class()` was used, and it is a class filter: it drops an
	 * accented letter, a colon or a dot, all valid in an id, so the printed
	 * id stopped matching the one the author typed and their own links to it
	 * broke. The editor takes out spaces and `#`; this does the same, plus
	 * the characters that have no business in an attribute at all.
	 */
	public static function anchor( string $anchor ): string {
		$anchor = (string) preg_replace( '/[\s#"\'<>`]+/u', '', $anchor );

		return function_exists( 'mb_substr' ) ? mb_substr( $anchor, 0, 128 ) : substr( $anchor, 0, 128 );
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
				'array'        => array(
					'type'    => 'array',
					'default' => array(),
					'items'   => array( 'type' => 'object' ),
				),
				default        => array(
					'type'    => 'string',
					'default' => (string) $definition['default'],
				),
			};
		}

		return $attributes + self::anchor_attribute();
	}

	/**
	 * The anchor, declared rather than left to `supports.anchor`.
	 *
	 * Left to the support, the editor defines `anchor` as read from the
	 * saved HTML's `id` — and a dynamic block saves no HTML, so whatever the
	 * author typed in "HTML anchor" was gone after saving. Declared with a
	 * type, the editor keeps this definition and writes the value into the
	 * block comment like any other attribute.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function anchor_attribute(): array {
		return array(
			'anchor' => array(
				'type'    => 'string',
				'default' => '',
			),
		);
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content    Inner content; these blocks have none.
	 * @param mixed                $block      The block instance, for its context.
	 */
	public function render( array $attributes, string $content = '', mixed $block = null ): string {
		$renderer = new PlayerRenderer();
		/*
		 * The block's own class goes on the block's wrapper, where
		 * `get_block_wrapper_attributes()` puts it. Passed on as well, the
		 * player carried it a second time, and a theme rule written for the
		 * class — a margin, a border — applied twice, one box inside another.
		 */
		$html = $renderer->render( array_diff_key( $attributes, array( 'className' => true ) ), self::context_post_id( $block ) );

		if ( '' === $html ) {
			return '';
		}

		$wrapper = get_block_wrapper_attributes( self::wrapper_attributes( $attributes ) );

		return sprintf( '<div %s>%s</div>', $wrapper, $html );
	}

	/**
	 * The post a block is rendered for, from its context.
	 *
	 * Inside a Query Loop or a template the global post is not always the
	 * one the block belongs to; the context is. A source read from a custom
	 * field has to come from the right post.
	 *
	 * @param mixed $block The block instance, when WordPress passed one.
	 */
	private static function context_post_id( mixed $block ): ?int {
		if ( is_object( $block ) && isset( $block->context ) && is_array( $block->context ) && ! empty( $block->context['postId'] ) ) {
			return (int) $block->context['postId'];
		}

		return null;
	}

	/**
	 * Hand the editor the preset list and the override schema so its inspector
	 * can be generated instead of hand-maintained.
	 */
	public function enqueue_editor_data(): void {
		$presets = array();
		$values  = array();

		/*
		 * What each preset actually says, not the factory's defaults: the
		 * inspector marks every row "Site: on" or "Site: off", and reading the
		 * factory told a site that had turned the download button on that it
		 * was off — and ignored the preset the block had picked.
		 */
		$shown = array_flip(
			array_merge(
				array_keys( Attributes::override_map() ),
				array( 'label' )
			)
		);

		foreach ( Settings::presets() as $key => $preset ) {
			$presets[] = array(
				'value' => $key,
				'label' => (string) $preset['label'],
			);

			$values[ $key ] = array_intersect_key( (array) $preset, $shown );
		}

		// The video half, which is the site's settings rather than a preset's.
		$video = array_intersect_key( Settings::video(), \ImaginaPlayer\Player\Video::override_map() + array( 'ratio' => '' ) );

		wp_add_inline_script(
			Assets::EDITOR_HANDLE,
			'window.imaginaPlayerEditor = ' . wp_json_encode(
				array(
					'presets'     => $presets,
					'skins'       => Settings::skins(),
					// Separately, because a skin belongs to a medium: the seven
					// above all arrange a waveform and a row of transport
					// buttons, which is not what a video needs.
					'videoSkins'  => \ImaginaPlayer\Player\Skins::video(),
					'overrides'      => Attributes::override_map(),
					'presetShape'    => Settings::preset_defaults(),
					'presetValues'   => $values,
					'video'          => $video,
					'videoOverrides' => \ImaginaPlayer\Player\Video::override_map(),
					// Who may play a player: the rules, and which of the
					// plugins they need are active here.
					'access'         => \ImaginaPlayer\Player\Access::rules(),
					'settingsUrl' => esc_url_raw( admin_url( 'admin.php?page=imagina-player' ) ),
				) + Assets::preview_assets()
			) . ';',
			'before'
		);
	}
}
