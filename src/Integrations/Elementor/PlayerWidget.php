<?php
/**
 * What the three Elementor widgets share.
 *
 * Elementor keeps a widget's settings in its own shape — a media control is
 * an array with an id and a url, a switch is "yes" or nothing, a repeater a
 * list of rows — and the renderer takes the block's attributes. The mapping
 * between the two lives here, in one place and in the open, so it can be
 * read and tested without Elementor on hand: `attributes_from()` is pure.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Integrations\Elementor;

use Elementor\Controls_Manager;
use Elementor\Repeater;
use Elementor\Widget_Base;
use ImaginaPlayer\Assets;
use ImaginaPlayer\Player\Attributes;
use ImaginaPlayer\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class PlayerWidget extends Widget_Base {

	/** The three ways a widget can be told which file to play. */
	public const SOURCE_LIBRARY = 'library';
	public const SOURCE_URL     = 'url';
	public const SOURCE_FIELD   = 'field';

	public function get_categories(): array {
		return array( Integration::CATEGORY );
	}

	/**
	 * The front-end bundle, so the editor's preview frame loads it and a
	 * widget dropped into the page becomes a player there and then.
	 *
	 * @return string[]
	 */
	public function get_script_depends(): array {
		return array( Assets::FRONTEND_HANDLE );
	}

	/**
	 * @return string[]
	 */
	public function get_style_depends(): array {
		return array( Assets::FRONTEND_HANDLE );
	}

	/**
	 * The block attributes a set of widget settings amounts to.
	 *
	 * @param array<string, mixed> $settings What Elementor stored.
	 * @return array<string, mixed>
	 */
	abstract public function attributes_from( array $settings ): array;

	/**
	 * The markup for a set of settings. Its own method so a test can render
	 * without Elementor's output buffering around it.
	 *
	 * @param array<string, mixed> $settings What Elementor stored.
	 */
	abstract public function markup( array $settings ): string;

	protected function render(): void {
		$settings = $this->get_settings_for_display();

		echo $this->markup( is_array( $settings ) ? $settings : array() ); // phpcs:ignore WordPress.Security.EscapeOutput -- the renderer escapes everything it prints.
	}

	/* ---- Controls shared by the audio and video widgets ------------------ */

	/**
	 * Where the file comes from: the library, an address, or a custom field
	 * of the post the page shows.
	 *
	 * @param string   $medium      'audio' or 'video'.
	 * @param string[] $media_types What the library picker offers.
	 */
	protected function source_controls( string $medium, array $media_types ): void {
		$this->add_control(
			'source',
			array(
				'label'   => __( 'Source', 'imagina-player' ),
				'type'    => Controls_Manager::SELECT,
				'default' => self::SOURCE_LIBRARY,
				'options' => array(
					self::SOURCE_LIBRARY => __( 'Media library', 'imagina-player' ),
					self::SOURCE_URL     => __( 'Address', 'imagina-player' ),
					self::SOURCE_FIELD   => __( 'Custom field of the post', 'imagina-player' ),
				),
			)
		);

		$this->add_control(
			'media',
			array(
				'label'       => 'audio' === $medium ? __( 'Audio file', 'imagina-player' ) : __( 'Video file', 'imagina-player' ),
				'type'        => Controls_Manager::MEDIA,
				'media_types' => $media_types,
				'condition'   => array( 'source' => self::SOURCE_LIBRARY ),
			)
		);

		$this->add_control(
			'url',
			array(
				'label'       => __( 'Address', 'imagina-player' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => 'audio' === $medium
					? 'https://example.com/track.mp3'
					: 'https://www.youtube.com/watch?v=…',
				'description' => 'audio' === $medium
					? __( 'A file on another site or a streaming provider.', 'imagina-player' )
					: __( 'A YouTube or Vimeo address, an MP4, or an HLS stream (.m3u8).', 'imagina-player' ),
				'options'     => false,
				'dynamic'     => array( 'active' => true ),
				'condition'   => array( 'source' => self::SOURCE_URL ),
			)
		);

		$this->add_control(
			'source_field',
			array(
				'label'       => __( 'Custom field key', 'imagina-player' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => 'video_url',
				'description' => __( 'The name of a custom field on the post this widget is shown on — an ACF or JetEngine field, or any post meta — whose value is the file’s address or its media library ID. In a product template, each product supplies its own file.', 'imagina-player' ),
				'condition'   => array( 'source' => self::SOURCE_FIELD ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'imagina-player' ),
				'type'        => Controls_Manager::TEXT,
				'description' => __( 'Leave empty to use the file’s own title.', 'imagina-player' ),
				'dynamic'     => array( 'active' => true ),
			)
		);
	}

	/** The preset and the skin, and the one colour most people change. */
	protected function appearance_controls( string $medium ): void {
		$this->start_controls_section(
			'appearance',
			array( 'label' => __( 'Appearance', 'imagina-player' ) )
		);

		$presets = array();

		foreach ( Settings::presets() as $key => $preset ) {
			$presets[ (string) $key ] = (string) ( $preset['label'] ?? $key );
		}

		$this->add_control(
			'preset',
			array(
				'label'   => __( 'Preset', 'imagina-player' ),
				'type'    => Controls_Manager::SELECT,
				'default' => Settings::DEFAULT_PRESET,
				'options' => $presets,
			)
		);

		$skins = array( '' => __( 'Use preset', 'imagina-player' ) );

		foreach ( 'audio' === $medium ? \ImaginaPlayer\Player\Skins::all() : \ImaginaPlayer\Player\Skins::video() as $key => $label ) {
			$skins[ (string) $key ] = (string) $label;
		}

		$this->add_control(
			'skin',
			array(
				'label'   => __( 'Skin', 'imagina-player' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => $skins,
			)
		);

		$this->add_control(
			'accent',
			array(
				'label'       => __( 'Accent colour', 'imagina-player' ),
				'type'        => Controls_Manager::COLOR,
				'description' => __( 'Leave empty to use the preset’s.', 'imagina-player' ),
			)
		);

		$this->add_control(
			'border_radius',
			array(
				'label'   => __( 'Corner radius (px)', 'imagina-player' ),
				'type'    => Controls_Manager::NUMBER,
				'min'     => 0,
				'max'     => 64,
				'default' => '',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * One three-way choice per control: the preset's answer, on, or off.
	 *
	 * @param array<string, string> $switches Attribute name => label.
	 */
	protected function tristate_controls( string $section, string $label, array $switches ): void {
		$this->start_controls_section( $section, array( 'label' => $label ) );

		foreach ( $switches as $attribute => $text ) {
			$this->add_control(
				$attribute,
				array(
					'label'   => $text,
					'type'    => Controls_Manager::SELECT,
					'default' => '',
					'options' => self::tristate_options(),
				)
			);
		}

		$this->end_controls_section();
	}

	/**
	 * @return array<string, string>
	 */
	public static function tristate_options(): array {
		return array(
			''    => __( 'Use preset', 'imagina-player' ),
			'yes' => __( 'Show', 'imagina-player' ),
			'no'  => __( 'Hide', 'imagina-player' ),
		);
	}

	/** Calls to action: a card, a bar, or an email gate at a point in the playback. */
	protected function layer_controls(): void {
		$this->start_controls_section(
			'layers_section',
			array( 'label' => __( 'Calls to action', 'imagina-player' ) )
		);

		$repeater = new Repeater();

		$repeater->add_control(
			'type',
			array(
				'label'   => __( 'Kind', 'imagina-player' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'cta',
				'options' => array(
					'cta'   => __( 'Card with a button', 'imagina-player' ),
					'bar'   => __( 'Bar with a button', 'imagina-player' ),
					'email' => __( 'Email gate', 'imagina-player' ),
				),
			)
		);
		$repeater->add_control( 'at', array( 'label' => __( 'Show at (percent of playback)', 'imagina-player' ), 'type' => Controls_Manager::NUMBER, 'min' => 0, 'max' => 100, 'default' => 100 ) );
		$repeater->add_control( 'until', array( 'label' => __( 'Hide again at (percent of playback, 0 for never)', 'imagina-player' ), 'type' => Controls_Manager::NUMBER, 'min' => 0, 'max' => 100, 'default' => 0 ) );
		$repeater->add_control( 'title', array( 'label' => __( 'Title', 'imagina-player' ), 'type' => Controls_Manager::TEXT, 'dynamic' => array( 'active' => true ) ) );
		$repeater->add_control( 'text', array( 'label' => __( 'Text', 'imagina-player' ), 'type' => Controls_Manager::TEXTAREA, 'dynamic' => array( 'active' => true ) ) );
		$repeater->add_control( 'button', array( 'label' => __( 'Button label', 'imagina-player' ), 'type' => Controls_Manager::TEXT ) );
		$repeater->add_control( 'url', array( 'label' => __( 'Link', 'imagina-player' ), 'type' => Controls_Manager::URL, 'options' => false, 'dynamic' => array( 'active' => true ), 'condition' => array( 'type!' => 'email' ) ) );
		$repeater->add_control( 'new_tab', array( 'label' => __( 'Open in a new tab', 'imagina-player' ), 'type' => Controls_Manager::SWITCHER, 'condition' => array( 'type!' => 'email' ) ) );
		$repeater->add_control( 'skip', array( 'label' => __( 'Can be dismissed', 'imagina-player' ), 'type' => Controls_Manager::SWITCHER, 'default' => 'yes' ) );
		$repeater->add_control( 'list', array( 'label' => __( 'List name', 'imagina-player' ), 'type' => Controls_Manager::TEXT, 'description' => __( 'Where the address is filed in Leads.', 'imagina-player' ), 'condition' => array( 'type' => 'email' ) ) );
		$repeater->add_control( 'consent', array( 'label' => __( 'Consent text', 'imagina-player' ), 'type' => Controls_Manager::TEXT, 'condition' => array( 'type' => 'email' ) ) );
		$repeater->add_control( 'thanks', array( 'label' => __( 'Thank-you text', 'imagina-player' ), 'type' => Controls_Manager::TEXT, 'condition' => array( 'type' => 'email' ) ) );

		$this->add_control(
			'layers',
			array(
				'label'       => __( 'Calls to action', 'imagina-player' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => array(),
				'title_field' => '{{{ title || type }}}',
			)
		);

		$this->end_controls_section();
	}

	/* ---- Reading Elementor's settings ------------------------------------ */

	/**
	 * The file, from whichever of the three sources the widget was told.
	 *
	 * @param array<string, mixed> $settings
	 * @return array{src: string, attachmentId: int, sourceField: string}
	 */
	protected static function source_from( array $settings ): array {
		$source = (string) ( $settings['source'] ?? self::SOURCE_LIBRARY );
		$out    = array(
			'src'          => '',
			'attachmentId' => 0,
			'sourceField'  => '',
		);

		if ( self::SOURCE_FIELD === $source ) {
			$out['sourceField'] = (string) ( $settings['source_field'] ?? '' );

			return $out;
		}

		if ( self::SOURCE_URL === $source ) {
			$out['src'] = self::url_from( $settings['url'] ?? '' );

			return $out;
		}

		$media = $settings['media'] ?? array();

		if ( is_array( $media ) ) {
			$out['attachmentId'] = (int) ( $media['id'] ?? 0 );
			$out['src']          = (string) ( $media['url'] ?? '' );
		}

		return $out;
	}

	/**
	 * An address from a URL control, which stores an array, or from a plain
	 * string, which a dynamic tag may hand over.
	 *
	 * @param mixed $value
	 */
	protected static function url_from( $value ): string {
		if ( is_array( $value ) ) {
			return (string) ( $value['url'] ?? '' );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * An image from a MEDIA control: its id and its address.
	 *
	 * @param mixed $value
	 * @return array{id: int, url: string}
	 */
	protected static function image_from( $value ): array {
		if ( ! is_array( $value ) ) {
			return array(
				'id'  => 0,
				'url' => '',
			);
		}

		return array(
			'id'  => (int) ( $value['id'] ?? 0 ),
			'url' => (string) ( $value['url'] ?? '' ),
		);
	}

	/** A switcher: "yes" or nothing. */
	protected static function switch_from( array $settings, string $key ): bool {
		return 'yes' === ( $settings[ $key ] ?? '' );
	}

	/**
	 * Every three-way choice the widget offers, copied across by name.
	 *
	 * @param array<string, mixed> $settings
	 * @param string[]             $names
	 * @return array<string, string>
	 */
	protected static function tristates_from( array $settings, array $names ): array {
		$out = array();

		foreach ( $names as $name ) {
			$out[ $name ] = Attributes::to_tristate( $settings[ $name ] ?? '' );
		}

		return $out;
	}

	/**
	 * The calls to action, in the shape the renderer's own sanitiser takes.
	 *
	 * @param mixed $rows What the repeater stored.
	 * @return array<int, array<string, mixed>>
	 */
	protected static function layers_from( $rows ): array {
		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$out[] = array(
				'type'    => (string) ( $row['type'] ?? 'cta' ),
				'at'      => (int) ( $row['at'] ?? 100 ),
				'until'   => (int) ( $row['until'] ?? 0 ),
				'title'   => (string) ( $row['title'] ?? '' ),
				'text'    => (string) ( $row['text'] ?? '' ),
				'button'  => (string) ( $row['button'] ?? '' ),
				'url'     => self::url_from( $row['url'] ?? '' ),
				'newTab'  => 'yes' === ( $row['new_tab'] ?? '' ),
				'skip'    => 'yes' === ( $row['skip'] ?? '' ),
				'list'    => (string) ( $row['list'] ?? '' ),
				'consent' => (string) ( $row['consent'] ?? '' ),
				'thanks'  => (string) ( $row['thanks'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * A number control that may be empty, as the text the attribute takes.
	 *
	 * @param mixed  $value
	 * @param string $unit  Appended when there is a number.
	 */
	protected static function number_from( $value, string $unit = '' ): string {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return '';
		}

		return (string) ( 0 + $value ) . $unit;
	}
}
