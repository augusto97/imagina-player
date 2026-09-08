<?php
/**
 * The playlist as an Elementor widget.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Integrations\Elementor;

use Elementor\Controls_Manager;
use Elementor\Repeater;
use ImaginaPlayer\Render\PlaylistRenderer;
use ImaginaPlayer\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PlaylistWidget extends PlayerWidget {

	public function get_name(): string {
		return 'imagina-playlist';
	}

	public function get_title(): string {
		return __( 'Imagina Playlist', 'imagina-player' );
	}

	public function get_icon(): string {
		return 'eicon-post-list';
	}

	public function get_keywords(): array {
		return array( 'playlist', 'audio', 'tracks', 'album', 'podcast', 'imagina' );
	}

	protected function register_controls(): void {
		$this->start_controls_section(
			'tracks_section',
			array( 'label' => __( 'Tracks', 'imagina-player' ) )
		);

		$items = new Repeater();
		$items->add_control( 'media', array( 'label' => __( 'Audio file', 'imagina-player' ), 'type' => Controls_Manager::MEDIA, 'media_types' => array( 'audio', 'video' ) ) );
		$items->add_control( 'url', array( 'label' => __( 'Or an address', 'imagina-player' ), 'type' => Controls_Manager::URL, 'options' => false, 'dynamic' => array( 'active' => true ), 'description' => __( 'Used when no file is chosen above.', 'imagina-player' ) ) );
		$items->add_control( 'title', array( 'label' => __( 'Title', 'imagina-player' ), 'type' => Controls_Manager::TEXT, 'dynamic' => array( 'active' => true ) ) );
		$items->add_control( 'artist', array( 'label' => __( 'Artist', 'imagina-player' ), 'type' => Controls_Manager::TEXT, 'dynamic' => array( 'active' => true ) ) );

		$this->add_control(
			'items',
			array(
				'label'       => __( 'Tracks', 'imagina-player' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $items->get_controls(),
				'default'     => array(),
				'title_field' => '{{{ title || url.url || "Track" }}}',
			)
		);

		$this->add_control( 'heading', array( 'label' => __( 'Heading', 'imagina-player' ), 'type' => Controls_Manager::TEXT, 'dynamic' => array( 'active' => true ) ) );

		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'imagina-player' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'list',
				'options' => array(
					'list' => __( 'List', 'imagina-player' ),
					'grid' => __( 'Grid', 'imagina-player' ),
				),
			)
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

		$this->end_controls_section();
	}

	public function attributes_from( array $settings ): array {
		$items = array();

		foreach ( is_array( $settings['items'] ?? null ) ? $settings['items'] : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$media = is_array( $row['media'] ?? null ) ? $row['media'] : array();
			$id    = (int) ( $media['id'] ?? 0 );
			$src   = (string) ( $media['url'] ?? '' );

			if ( 0 === $id && '' === $src ) {
				$src = self::url_from( $row['url'] ?? '' );
			}

			if ( 0 === $id && '' === $src ) {
				continue;
			}

			$items[] = array(
				'id'     => $id,
				'src'    => $src,
				'title'  => (string) ( $row['title'] ?? '' ),
				'artist' => (string) ( $row['artist'] ?? '' ),
			);
		}

		return array(
			'items'   => $items,
			'heading' => (string) ( $settings['heading'] ?? '' ),
			'layout'  => 'grid' === ( $settings['layout'] ?? 'list' ) ? 'grid' : 'list',
			'preset'  => (string) ( $settings['preset'] ?? 'default' ),
		);
	}

	public function markup( array $settings ): string {
		$html = ( new PlaylistRenderer() )->render( $this->attributes_from( $settings ) );

		return '' === $html ? '' : '<div class="imgp-block imgp-block--elementor">' . $html . '</div>';
	}
}
