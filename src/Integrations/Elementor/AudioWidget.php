<?php
/**
 * The audio player as an Elementor widget.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Integrations\Elementor;

use Elementor\Controls_Manager;
use ImaginaPlayer\Render\PlayerRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AudioWidget extends PlayerWidget {

	/** The three-way choices the audio player offers, attribute => label. */
	public const SWITCHES = array(
		'showTitle'        => 'Title',
		'showArtist'       => 'Artist',
		'showThumbnail'    => 'Cover art',
		'showTime'         => 'Time',
		'showVolume'       => 'Volume',
		'showSpeed'        => 'Speed',
		'showSkip'         => 'Skip back and forward',
		'showDownload'     => 'Download',
		'sticky'           => 'Stick to the edge while scrolling',
		'rememberPosition' => 'Remember where the listener left off',
	);

	public function get_name(): string {
		return 'imagina-audio-player';
	}

	public function get_title(): string {
		return __( 'Imagina Audio Player', 'imagina-player' );
	}

	public function get_icon(): string {
		return 'eicon-headphones';
	}

	public function get_keywords(): array {
		return array( 'audio', 'player', 'podcast', 'music', 'waveform', 'imagina' );
	}

	protected function register_controls(): void {
		$this->start_controls_section(
			'media_section',
			array( 'label' => __( 'Audio', 'imagina-player' ) )
		);

		$this->source_controls( 'audio', array( 'audio', 'video' ) );

		$this->add_control(
			'artist',
			array(
				'label'   => __( 'Artist', 'imagina-player' ),
				'type'    => Controls_Manager::TEXT,
				'dynamic' => array( 'active' => true ),
			)
		);

		$this->add_control(
			'thumbnail',
			array(
				'label'       => __( 'Cover art', 'imagina-player' ),
				'type'        => Controls_Manager::MEDIA,
				'media_types' => array( 'image' ),
			)
		);

		$this->add_control(
			'download_url',
			array(
				'label'       => __( 'Download file', 'imagina-player' ),
				'type'        => Controls_Manager::URL,
				'options'     => false,
				'description' => __( 'Offered when the Download control is on. Leave empty to offer the file itself.', 'imagina-player' ),
			)
		);

		$this->add_control( 'autoplay', array( 'label' => __( 'Autoplay', 'imagina-player' ), 'type' => Controls_Manager::SWITCHER ) );
		$this->add_control( 'loop', array( 'label' => __( 'Loop', 'imagina-player' ), 'type' => Controls_Manager::SWITCHER ) );
		$this->add_control( 'start_time', array( 'label' => __( 'Start at (seconds)', 'imagina-player' ), 'type' => Controls_Manager::NUMBER, 'min' => 0, 'default' => '' ) );

		$this->end_controls_section();

		$this->appearance_controls( 'audio' );

		$switches = array();

		foreach ( self::SWITCHES as $attribute => $label ) {
			$switches[ $attribute ] = self::label( $attribute, $label );
		}

		$this->tristate_controls( 'controls_section', __( 'Controls', 'imagina-player' ), $switches );

		$this->start_controls_section(
			'skip_section',
			array( 'label' => __( 'Skipping', 'imagina-player' ) )
		);
		$this->add_control( 'skip_seconds', array( 'label' => __( 'Skip by (seconds)', 'imagina-player' ), 'type' => Controls_Manager::NUMBER, 'min' => 1, 'max' => 120, 'default' => '' ) );
		$this->end_controls_section();

		$this->layer_controls();
	}

	/**
	 * The label for a switch, translated.
	 *
	 * Listed once as plain text in SWITCHES so a test can read the set; the
	 * translation happens here, where the strings are visible to the tools.
	 */
	private static function label( string $attribute, string $fallback ): string {
		switch ( $attribute ) {
			case 'showTitle':
				return __( 'Title', 'imagina-player' );
			case 'showArtist':
				return __( 'Artist', 'imagina-player' );
			case 'showThumbnail':
				return __( 'Cover art', 'imagina-player' );
			case 'showTime':
				return __( 'Time', 'imagina-player' );
			case 'showVolume':
				return __( 'Volume', 'imagina-player' );
			case 'showSpeed':
				return __( 'Speed', 'imagina-player' );
			case 'showSkip':
				return __( 'Skip back and forward', 'imagina-player' );
			case 'showDownload':
				return __( 'Download', 'imagina-player' );
			case 'sticky':
				return __( 'Stick to the edge while scrolling', 'imagina-player' );
			case 'rememberPosition':
				return __( 'Remember where the listener left off', 'imagina-player' );
		}

		return $fallback;
	}

	public function attributes_from( array $settings ): array {
		$thumbnail = self::image_from( $settings['thumbnail'] ?? null );

		return self::source_from( $settings ) + self::tristates_from( $settings, array_keys( self::SWITCHES ) ) + array(
			'title'        => (string) ( $settings['title'] ?? '' ),
			'artist'       => (string) ( $settings['artist'] ?? '' ),
			'thumbnail'    => $thumbnail['url'],
			'thumbnailId'  => $thumbnail['id'],
			'downloadUrl'  => self::url_from( $settings['download_url'] ?? '' ),
			'autoplay'     => self::switch_from( $settings, 'autoplay' ),
			'loop'         => self::switch_from( $settings, 'loop' ),
			'startTime'    => (float) ( $settings['start_time'] ?? 0 ),
			'preset'       => (string) ( $settings['preset'] ?? 'default' ),
			'skin'         => (string) ( $settings['skin'] ?? '' ),
			'accent'       => (string) ( $settings['accent'] ?? '' ),
			'borderRadius' => self::number_from( $settings['border_radius'] ?? '', 'px' ),
			'skipSeconds'  => self::number_from( $settings['skip_seconds'] ?? '' ),
			'layers'       => self::layers_from( $settings['layers'] ?? array() ),
		);
	}

	public function markup( array $settings ): string {
		$html = ( new PlayerRenderer() )->render( $this->attributes_from( $settings ) );

		return '' === $html ? '' : '<div class="imgp-block imgp-block--elementor">' . $html . '</div>';
	}
}
