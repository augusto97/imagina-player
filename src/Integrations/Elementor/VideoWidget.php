<?php
/**
 * The video player as an Elementor widget.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Integrations\Elementor;

use Elementor\Controls_Manager;
use Elementor\Repeater;
use ImaginaPlayer\Player\Attributes;
use ImaginaPlayer\Render\PlayerRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VideoWidget extends PlayerWidget {

	/** The three-way choices the video player offers, attribute => label. */
	public const SWITCHES = array(
		'videoBigPlay'       => 'Play button over the picture',
		'videoTitle'         => 'Title',
		'videoTime'          => 'Time',
		'videoVolume'        => 'Volume',
		'videoSkip'          => 'Skip back and forward',
		'videoSpeed'         => 'Speed',
		'videoCaptions'      => 'Subtitles',
		'videoChapters'      => 'Chapters',
		'videoSearch'        => 'Search what is said',
		'videoPip'           => 'Picture in picture',
		'videoFullscreen'    => 'Full screen',
		'videoBlockDownload' => 'Block the browser download',
		'videoFocusMode'     => 'Focus mode',
		'videoCaptionsOn'    => 'Subtitles on from the start',
		'videoProviderBare'  => 'Hide YouTube’s and Vimeo’s own interface',
	);

	public const RATIOS = array( '16:9', '4:3', '1:1', '9:16', '21:9' );

	public function get_name(): string {
		return 'imagina-video-player';
	}

	public function get_title(): string {
		return __( 'Imagina Video Player', 'imagina-player' );
	}

	public function get_icon(): string {
		return 'eicon-video-camera';
	}

	public function get_keywords(): array {
		return array( 'video', 'player', 'youtube', 'vimeo', 'hls', 'imagina' );
	}

	protected function register_controls(): void {
		$this->start_controls_section(
			'media_section',
			array( 'label' => __( 'Video', 'imagina-player' ) )
		);

		$this->source_controls( 'video', array( 'video' ) );

		$this->add_control(
			'poster',
			array(
				'label'       => __( 'Poster', 'imagina-player' ),
				'type'        => Controls_Manager::MEDIA,
				'media_types' => array( 'image' ),
				'description' => __( 'Shown before play. Leave empty to use YouTube’s or Vimeo’s own picture.', 'imagina-player' ),
			)
		);

		$ratios = array( '' => __( 'Use preset', 'imagina-player' ) );

		foreach ( self::RATIOS as $ratio ) {
			$ratios[ $ratio ] = $ratio;
		}

		$this->add_control(
			'aspect_ratio',
			array(
				'label'   => __( 'Aspect ratio', 'imagina-player' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => $ratios,
			)
		);

		$this->add_control( 'autoplay', array( 'label' => __( 'Autoplay', 'imagina-player' ), 'type' => Controls_Manager::SWITCHER, 'description' => __( 'Browsers allow it only when muted.', 'imagina-player' ) ) );
		$this->add_control( 'muted', array( 'label' => __( 'Start muted', 'imagina-player' ), 'type' => Controls_Manager::SWITCHER ) );
		$this->add_control( 'loop', array( 'label' => __( 'Loop', 'imagina-player' ), 'type' => Controls_Manager::SWITCHER ) );
		$this->add_control( 'start_time', array( 'label' => __( 'Start at (seconds)', 'imagina-player' ), 'type' => Controls_Manager::NUMBER, 'min' => 0, 'default' => '' ) );

		$this->end_controls_section();

		$this->appearance_controls( 'video' );

		$switches = array();

		foreach ( self::SWITCHES as $attribute => $label ) {
			$switches[ $attribute ] = self::label( $attribute, $label );
		}

		$this->tristate_controls( 'controls_section', __( 'Controls', 'imagina-player' ), $switches );

		$this->start_controls_section(
			'behaviour_section',
			array( 'label' => __( 'Behaviour', 'imagina-player' ) )
		);
		$this->add_control( 'hide_after', array( 'label' => __( 'Hide the controls after (ms of stillness)', 'imagina-player' ), 'type' => Controls_Manager::NUMBER, 'min' => 0, 'max' => 20000, 'default' => '' ) );
		$this->add_control( 'skip_seconds', array( 'label' => __( 'Skip by (seconds)', 'imagina-player' ), 'type' => Controls_Manager::NUMBER, 'min' => 1, 'max' => 120, 'default' => '' ) );
		$this->add_control(
			'poster_fit',
			array(
				'label'   => __( 'Poster fit', 'imagina-player' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''        => __( 'Use preset', 'imagina-player' ),
					'cover'   => __( 'Fill the frame', 'imagina-player' ),
					'contain' => __( 'Show the whole picture', 'imagina-player' ),
				),
			)
		);
		$this->add_control(
			'caption_size',
			array(
				'label'   => __( 'Subtitle size', 'imagina-player' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''       => __( 'Use preset', 'imagina-player' ),
					'small'  => __( 'Small', 'imagina-player' ),
					'medium' => __( 'Medium', 'imagina-player' ),
					'large'  => __( 'Large', 'imagina-player' ),
					'xlarge' => __( 'Extra large', 'imagina-player' ),
				),
			)
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'subtitles_section',
			array( 'label' => __( 'Subtitles', 'imagina-player' ) )
		);

		$tracks = new Repeater();
		$tracks->add_control( 'label', array( 'label' => __( 'Language name', 'imagina-player' ), 'type' => Controls_Manager::TEXT, 'placeholder' => 'Español' ) );
		$tracks->add_control( 'srclang', array( 'label' => __( 'Language code', 'imagina-player' ), 'type' => Controls_Manager::TEXT, 'placeholder' => 'es' ) );
		$tracks->add_control( 'src', array( 'label' => __( 'Subtitle file (.vtt or .srt)', 'imagina-player' ), 'type' => Controls_Manager::URL, 'options' => false, 'description' => __( 'Upload it to the media library and paste its address.', 'imagina-player' ) ) );
		$tracks->add_control( 'default', array( 'label' => __( 'On by default', 'imagina-player' ), 'type' => Controls_Manager::SWITCHER ) );

		$this->add_control(
			'tracks',
			array(
				'label'       => __( 'Subtitle tracks', 'imagina-player' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $tracks->get_controls(),
				'default'     => array(),
				'title_field' => '{{{ label || srclang }}}',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'chapters_section',
			array( 'label' => __( 'Chapters', 'imagina-player' ) )
		);

		$chapters = new Repeater();
		$chapters->add_control( 'start', array( 'label' => __( 'Starts at', 'imagina-player' ), 'type' => Controls_Manager::TEXT, 'placeholder' => '1:30', 'description' => __( 'Minutes and seconds, or seconds.', 'imagina-player' ) ) );
		$chapters->add_control( 'title', array( 'label' => __( 'Title', 'imagina-player' ), 'type' => Controls_Manager::TEXT ) );

		$this->add_control(
			'chapters',
			array(
				'label'       => __( 'Chapters', 'imagina-player' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $chapters->get_controls(),
				'default'     => array(),
				'title_field' => '{{{ start }}} {{{ title }}}',
			)
		);

		$this->end_controls_section();

		$this->layer_controls();
	}

	private static function label( string $attribute, string $fallback ): string {
		switch ( $attribute ) {
			case 'videoBigPlay':
				return __( 'Play button over the picture', 'imagina-player' );
			case 'videoTitle':
				return __( 'Title', 'imagina-player' );
			case 'videoTime':
				return __( 'Time', 'imagina-player' );
			case 'videoVolume':
				return __( 'Volume', 'imagina-player' );
			case 'videoSkip':
				return __( 'Skip back and forward', 'imagina-player' );
			case 'videoSpeed':
				return __( 'Speed', 'imagina-player' );
			case 'videoCaptions':
				return __( 'Subtitles', 'imagina-player' );
			case 'videoChapters':
				return __( 'Chapters', 'imagina-player' );
			case 'videoSearch':
				return __( 'Search what is said', 'imagina-player' );
			case 'videoPip':
				return __( 'Picture in picture', 'imagina-player' );
			case 'videoFullscreen':
				return __( 'Full screen', 'imagina-player' );
			case 'videoBlockDownload':
				return __( 'Block the browser download', 'imagina-player' );
			case 'videoFocusMode':
				return __( 'Focus mode', 'imagina-player' );
			case 'videoCaptionsOn':
				return __( 'Subtitles on from the start', 'imagina-player' );
			case 'videoProviderBare':
				return __( 'Hide YouTube’s and Vimeo’s own interface', 'imagina-player' );
		}

		return $fallback;
	}

	public function attributes_from( array $settings ): array {
		$poster = self::image_from( $settings['poster'] ?? null );

		return self::source_from( $settings ) + self::tristates_from( $settings, array_keys( self::SWITCHES ) ) + array(
			'title'            => (string) ( $settings['title'] ?? '' ),
			'poster'           => $poster['url'],
			'posterId'         => $poster['id'],
			'aspectRatio'      => (string) ( $settings['aspect_ratio'] ?? '' ),
			'autoplay'         => self::switch_from( $settings, 'autoplay' ),
			'muted'            => self::switch_from( $settings, 'muted' ),
			'loop'             => self::switch_from( $settings, 'loop' ),
			'startTime'        => (float) ( $settings['start_time'] ?? 0 ),
			'preset'           => (string) ( $settings['preset'] ?? 'default' ),
			'skin'             => (string) ( $settings['skin'] ?? '' ),
			'accent'           => (string) ( $settings['accent'] ?? '' ),
			'borderRadius'     => self::number_from( $settings['border_radius'] ?? '', 'px' ),
			'videoHideAfter'   => self::number_from( $settings['hide_after'] ?? '' ),
			'skipSeconds'      => self::number_from( $settings['skip_seconds'] ?? '' ),
			'videoPosterFit'   => (string) ( $settings['poster_fit'] ?? '' ),
			'videoCaptionSize' => (string) ( $settings['caption_size'] ?? '' ),
			'tracks'           => self::tracks_from( $settings['tracks'] ?? array() ),
			'chapters'         => self::chapters_from( $settings['chapters'] ?? array() ),
			'layers'           => self::layers_from( $settings['layers'] ?? array() ),
		);
	}

	/**
	 * @param mixed $rows
	 * @return array<int, array<string, mixed>>
	 */
	private static function tracks_from( $rows ): array {
		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$out[] = array(
				'src'     => self::url_from( $row['src'] ?? '' ),
				'srclang' => (string) ( $row['srclang'] ?? '' ),
				'label'   => (string) ( $row['label'] ?? '' ),
				'kind'    => 'subtitles',
				'default' => 'yes' === ( $row['default'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * @param mixed $rows
	 * @return array<int, array<string, mixed>>
	 */
	private static function chapters_from( $rows ): array {
		$out = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$out[] = array(
				'start' => (string) ( $row['start'] ?? '0' ),
				'title' => (string) ( $row['title'] ?? '' ),
			);
		}

		return $out;
	}

	public function markup( array $settings ): string {
		$html = ( new PlayerRenderer() )->render( $this->attributes_from( $settings ) );

		return '' === $html ? '' : '<div class="imgp-block imgp-block--elementor">' . $html . '</div>';
	}
}
