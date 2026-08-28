<?php
/**
 * Turns player attributes into markup.
 *
 * The player is rendered on the server as a real `<audio controls>` element
 * wrapped in the visual shell. JavaScript then takes the controls off and
 * enhances the shell, so a page whose script fails to load still plays audio
 * with native controls instead of showing a dead widget.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Render;

use ImaginaPlayer\Assets;
use ImaginaPlayer\Media\Captions;
use ImaginaPlayer\Media\Track;
use ImaginaPlayer\Rest\CaptionController;
use ImaginaPlayer\Peaks\PeaksRepository;
use ImaginaPlayer\Peaks\PeaksToken;
use ImaginaPlayer\Player\Attributes;
use ImaginaPlayer\Protection\Vault;
use ImaginaPlayer\Player\Config;
use ImaginaPlayer\Player\Skins;
use ImaginaPlayer\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PlayerRenderer {

	private static int $instance_count = 0;

	private PeaksRepository $peaks;

	public function __construct( ?PeaksRepository $peaks = null ) {
		$this->peaks = $peaks ?? new PeaksRepository();
	}

	/**
	 * @param array<string, mixed> $raw_atts Unsanitised attributes.
	 */
	public function render( array $raw_atts ): string {
		$atts   = Attributes::sanitize( $raw_atts );
		$config = Config::resolve( $atts );
		$track  = Track::from_attributes( $atts );

		if ( ! $track->is_playable() ) {
			return $this->render_placeholder();
		}

		Assets::enqueue_frontend();

		++self::$instance_count;
		$id = 'imgp-' . self::$instance_count . '-' . wp_rand( 1000, 9999 );

		$peaks_payload = $this->peaks_payload( $track, $config );

		$classes = array(
			'imgp',
			'imgp--skin-' . $config['skin'],
			$track->thumbnail && $config['show_thumbnail'] ? 'imgp--has-thumb' : '',
			$config['sticky'] ? 'imgp--sticky' : '',
			$config['sticky'] ? 'imgp--stick-' . $config['sticky_position'] : '',
			$track->is_video() ? 'imgp--video' : 'imgp--audio',
			(int) $config['border_radius'] > 0 ? 'imgp--rounded-box' : '',
			$config['rounded_bars'] ? 'imgp--rounded' : '',
			$atts['className'],
		);

		$client_config = array(
			'id'          => $id,
			'skin'        => $config['skin'],
			'centered'    => Skins::is_centered( (string) $config['skin'] ),
			'bars'        => (int) $config['wave_bars'],
			'gap'         => (int) $config['wave_gap'],
			'reflection'  => (float) $config['wave_reflection'],
			'resolution'  => PeaksRepository::resolution(),
			'startTime'   => (float) $atts['startTime'],
			'skipSeconds' => (int) $config['skip_seconds'],
			'remember'    => (bool) $config['remember_position'],
			'onEnd'       => (string) $config['on_end'],
			'sticky'      => (bool) $config['sticky'],
			'duration'    => $track->duration,
			'peaksKey'    => $track->peaks_key(),
			'peaksToken'  => $peaks_payload['token'],
			'canCompute'  => $peaks_payload['can_compute'],
			// Non-zero when the file is served through a signed link, which can
			// expire while a cached page is still being served.
			'protectedId' => Vault::is_protected( $track->attachment_id ) ? $track->attachment_id : 0,
		);

		// Only present when there is something to run, because its absence is
		// what keeps the layer chunk from being downloaded at all.
		if ( array() !== (array) $atts['layers'] ) {
			$client_config['layers'] = array_map(
				static fn( array $layer ): array => array(
					'type' => (string) $layer['type'],
					'at'   => (int) $layer['at'],
					'skip' => (bool) $layer['skip'],
					'list' => (string) ( $layer['list'] ?? '' ),
				),
				(array) $atts['layers']
			);
		}

		// Namespaced rather than flattened: the video module reads `video` and
		// nothing else, so growing it later cannot collide with an audio key.
		if ( $track->is_video() ) {
			$client_config['video'] = array(
				'ratio'     => $track->aspect_ratio,
				'poster'    => $track->poster,
				'hideAfter' => 2600,
				// The browser cannot tell from the element that this is adaptive
				// streaming, and loading 400 KB of hls.js to find out would be
				// the whole cost of the feature paid on every video.
				'hls'       => $track->is_hls(),
				// Chapter starts, so the module can draw markers on the scrub bar
				// without parsing the VTT it just handed the browser.
				'chapters'  => array_map(
					static fn( array $chapter ): array => array(
						'start' => round( (float) $chapter['start'], 3 ),
						'title' => (string) $chapter['title'],
					),
					(array) $atts['chapters']
				),
			);
		}

		/**
		 * Filter the JSON config handed to the front-end for one player.
		 *
		 * @param array<string, mixed> $client_config Client config.
		 * @param array<string, mixed> $atts          Sanitised attributes.
		 * @param Track                $track         Resolved track.
		 */
		$client_config = apply_filters( 'imagina_player_client_config', $client_config, $atts, $track );

		$style = Config::style_attribute( Config::css_variables( $config ) );

		// Layout follows the *medium*, not the skin. Every audio skin arranges a
		// row of controls beside a scrubber; a video needs them over the picture,
		// and no choice of skin changes that.
		$layout = $track->is_video() ? 'theater' : Skins::layout( (string) $config['skin'] );

		$parts = array(
			'media'    => $this->part_media( $track, $atts, $config ),
			// A video always gets a scrubber. A skin that hides it was designed for
			// a bar of audio controls, and a video without a seek bar is broken.
			'scrubber' => $track->is_video() || Skins::has_scrubber( (string) $config['skin'] )
				? $this->part_scrubber( $track, $config )
				: '',
			'thumb'    => $config['show_thumbnail'] && '' !== $track->thumbnail ? $this->part_thumb( $track ) : '',
			'play'     => $this->part_play(),
			'meta'     => $this->part_meta( $track, $config ),
			'controls' => $this->part_controls( $track, $config ),
			'logo'     => $this->part_logo(),
		);

		$parts['layers'] = $this->part_layers( $track, $config, $atts, $id );

		if ( 'theater' === $layout ) {
			$parts['poster']  = $this->part_poster( $track );
			$parts['bigplay'] = $this->part_big_play();
			$parts['video']   = $this->part_video_controls( $config, $atts );
		}

		ob_start();
		?>
		<div
			id="<?php echo esc_attr( $id ); ?>"
			class="<?php echo esc_attr( trim( implode( ' ', array_filter( $classes ) ) ) ); ?>"
			style="<?php echo esc_attr( $style ); ?>"
			data-imagina-player="<?php echo esc_attr( (string) wp_json_encode( $client_config ) ); ?>"
			<?php if ( '' !== $peaks_payload['peaks'] ) : ?>
				data-peaks="<?php echo esc_attr( $peaks_payload['peaks'] ); ?>"
			<?php endif; ?>
		>
			<?php
			// Audio hangs its shell beside the media element; video wraps around
			// it, because the controls sit on the picture.
			if ( 'theater' !== $layout ) {
				echo $parts['media']; // phpcs:ignore WordPress.Security.EscapeOutput -- assembled from escaped parts.
			}

			if ( 'theater' === $layout ) {
				printf(
					'<div class="imgp__stage" style="--imgp-ratio:%s">%s%s%s%s<div class="imgp__chrome">%s<div class="imgp__bar">%s%s%s%s</div></div></div>',
					esc_attr( str_replace( ':', ' / ', $track->aspect_ratio ) ),
					$parts['media'], // phpcs:ignore WordPress.Security.EscapeOutput -- assembled from escaped parts.
					$parts['poster'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['bigplay'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['layers'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['scrubber'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['play'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['meta'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['controls'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['video'] . $parts['logo'] // phpcs:ignore WordPress.Security.EscapeOutput
				);
			} elseif ( 'card' === $layout ) {
				printf(
					'%s<div class="imgp__body">%s<div class="imgp__bar">%s%s%s</div></div>',
					$parts['thumb'], // phpcs:ignore WordPress.Security.EscapeOutput -- assembled from escaped parts.
					$parts['scrubber'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['play'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['meta'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['controls'] . $parts['logo'] // phpcs:ignore WordPress.Security.EscapeOutput
				);
			} elseif ( 'inline' === $layout ) {
				printf(
					'<div class="imgp__row">%s%s%s%s%s</div>',
					$parts['play'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['thumb'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['meta'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['scrubber'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['controls'] . $parts['logo'] // phpcs:ignore WordPress.Security.EscapeOutput
				);
			} else {
				printf(
					'%s<div class="imgp__bar">%s%s%s%s</div>',
					$parts['scrubber'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['thumb'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['play'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['meta'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['controls'] . $parts['logo'] // phpcs:ignore WordPress.Security.EscapeOutput
				);
			}

			// Video puts its layers inside the stage, over the picture. Audio has
			// no picture, so they go on the player itself.
			if ( 'theater' !== $layout ) {
				echo $parts['layers']; // phpcs:ignore WordPress.Security.EscapeOutput -- assembled from escaped parts.
			}
			?>
		</div>
		<?php

		$html = (string) ob_get_clean();

		/**
		 * Filter the rendered player markup.
		 *
		 * @param string               $html   Rendered markup.
		 * @param array<string, mixed> $atts   Sanitised attributes.
		 * @param array<string, mixed> $config Effective settings.
		 */
		return apply_filters( 'imagina_player_render', $html, $atts, $config );
	}

	/**
	 * @param array<string, mixed> $atts   Sanitised attributes.
	 * @param array<string, mixed> $config Effective settings.
	 */
	/**
	 * The media element itself, with native controls for the no-JavaScript case.
	 *
	 * @param array<string, mixed> $atts   Sanitised attributes.
	 * @param array<string, mixed> $config Effective settings.
	 */
	private function part_media( Track $track, array $atts, array $config ): string {
		$is_video = $track->is_video();
		$tag      = $is_video ? 'video' : 'audio';

		// Everything here is what the visitor gets if our JavaScript never
		// arrives: a real media element with native controls. The enhancement
		// takes the controls off, it does not put the player there.
		ob_start();
		?>
		<<?php echo esc_attr( $tag ); ?>
			class="imgp__media"
			src="<?php echo esc_url( $track->src ); ?>"
			preload="<?php echo esc_attr( (string) $config['preload'] ); ?>"
			controls
			<?php echo $atts['loop'] ? 'loop' : ''; ?>
			<?php echo $atts['muted'] ? 'muted' : ''; ?>
			<?php echo $atts['autoplay'] ? 'autoplay playsinline' : ''; ?>
			<?php echo '' !== $track->title ? 'title="' . esc_attr( $track->title ) . '"' : ''; ?>
			<?php if ( $is_video ) : ?>
				playsinline
				<?php echo '' !== $track->poster ? 'poster="' . esc_url( $track->poster ) . '"' : ''; ?>
				<?php echo $this->download_guards( $track, $config ); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed attribute names. ?>
			<?php endif; ?>
		><?php echo $this->text_tracks( $track, $atts ); // phpcs:ignore WordPress.Security.EscapeOutput -- assembled from escaped parts. ?></<?php echo esc_attr( $tag ); ?>>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Subtitle and chapter tracks.
	 *
	 * Subtitles that are already WebVTT are linked straight to the file. SubRip
	 * goes through an endpoint that converts it, because `<track>` reads WebVTT
	 * and nothing else, and telling people to go and convert their subtitles is
	 * not a feature.
	 *
	 * Chapters are inlined as a data URI: they are a few hundred bytes, so a
	 * request would cost more than the content, and inlining means they still
	 * work on a site whose REST API is behind a security plugin.
	 *
	 * @param array<string, mixed> $atts Sanitised attributes.
	 */
	private function text_tracks( Track $track, array $atts ): string {
		$html = '';

		foreach ( (array) ( $atts['tracks'] ?? array() ) as $subtitle ) {
			if ( ! is_array( $subtitle ) || empty( $subtitle['src'] ) ) {
				continue;
			}

			$html .= sprintf(
				'<track kind="%s" src="%s" srclang="%s" label="%s"%s />',
				esc_attr( (string) $subtitle['kind'] ),
				esc_url( CaptionController::track_url( (string) $subtitle['src'] ) ),
				esc_attr( (string) $subtitle['srclang'] ),
				esc_attr( (string) $subtitle['label'] ),
				empty( $subtitle['default'] ) ? '' : ' default'
			);
		}

		$chapters = Captions::chapters_vtt( (array) ( $atts['chapters'] ?? array() ), $track->duration );

		if ( '' !== $chapters ) {
			$html .= sprintf(
				'<track kind="chapters" src="%s" label="%s" default />',
				esc_url( Captions::data_uri( $chapters ) ),
				esc_attr__( 'Chapters', 'imagina-player' )
			);
		}

		return $html;
	}

	/**
	 * Attributes that make the file harder to walk away with.
	 *
	 * None of these stop a determined person — a screen recorder always works,
	 * and nothing short of DRM changes that. What they stop is the easy path:
	 * the browser's own download button, "Save video as", and casting the raw
	 * URL to a device. The link expiring is what does the real work; this is
	 * the layer above it.
	 *
	 * @param array<string, mixed> $config Effective settings.
	 */
	private function download_guards( Track $track, array $config ): string {
		if ( ! empty( $config['show_download'] ) ) {
			// Offering a download and then hiding the browser's own is theatre.
			return '';
		}

		$guards = array( 'controlslist="nodownload noplaybackrate"', 'disableremoteplayback' );

		/**
		 * Filter the hardening attributes placed on a protected video element.
		 *
		 * @param array<int, string> $guards Attribute strings.
		 * @param Track              $track  Resolved track.
		 */
		$guards = (array) apply_filters( 'imagina_player_video_guards', $guards, $track );

		return implode( ' ', array_map( 'strval', $guards ) );
	}

	/**
	 * The waveform or progress bar, plus the accessible seek slider over it.
	 *
	 * @param array<string, mixed> $config Effective settings.
	 */
	private function part_scrubber( Track $track, array $config ): string {
		// No waveform over a video: it would mean downloading and decoding the
		// audio of a file the visitor may never play, to draw a picture nobody
		// looks at while watching one.
		$waveform = ! $track->is_video() && Skins::uses_waveform( (string) $config['skin'] );

		ob_start();
		?>
		<div class="imgp__scrubber">
			<?php if ( $waveform ) : ?>
				<canvas class="imgp__wave" aria-hidden="true"></canvas>
			<?php else : ?>
				<div class="imgp__track" aria-hidden="true"><div class="imgp__progress"></div></div>
			<?php endif; ?>

			<div
				class="imgp__seek"
				role="slider"
				tabindex="0"
				aria-label="<?php esc_attr_e( 'Seek', 'imagina-player' ); ?>"
				aria-valuemin="0"
				aria-valuemax="100"
				aria-valuenow="0"
				aria-valuetext="<?php esc_attr_e( 'Not started', 'imagina-player' ); ?>"
			>
				<?php if ( $config['show_time'] ) : ?>
					<span class="imgp__time imgp__time--current">0:00</span>
					<span class="imgp__time imgp__time--total"><?php echo esc_html( $this->format_time( $track->duration ) ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	private function part_thumb( Track $track ): string {
		ob_start();
		?>
		<div class="imgp__thumb">
			<img
				src="<?php echo esc_url( $track->thumbnail ); ?>"
				alt=""
				loading="lazy"
				decoding="async"
				width="72"
				height="72"
			/>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * The site's brand mark, when one is configured.
	 */
	/**
	 * The still shown before playback starts.
	 *
	 * A real `<img>` rather than the `poster` attribute alone, because the
	 * attribute gives no control over loading priority or fit — and a poster is
	 * very often the page's largest contentful paint. `decoding=async` and a
	 * plain `loading=eager` say what this is: the one image on the page that
	 * should not be deferred.
	 */
	private function part_poster( Track $track ): string {
		if ( '' === $track->poster ) {
			return '';
		}

		return sprintf(
			'<div class="imgp__poster" aria-hidden="true"><img src="%s" alt="" decoding="async" fetchpriority="high" /></div>',
			esc_url( $track->poster )
		);
	}

	/**
	 * The play button in the middle of the picture.
	 *
	 * A real button, not a click handler on the stage: it is the first thing a
	 * keyboard reaches, and it is what a screen reader announces.
	 */
	private function part_big_play(): string {
		return sprintf(
			'<button type="button" class="imgp__bigplay" aria-label="%s">%s%s</button>',
			esc_attr__( 'Play', 'imagina-player' ),
			Icons::get( 'play', 'imgp__icon--play' ),
			Icons::get( 'pause', 'imgp__icon--pause' )
		);
	}

	/**
	 * Controls that only make sense over a picture.
	 *
	 * Built from a list rather than written out, so a later control — captions,
	 * quality, chapters — is an entry here and a case in the module, not a
	 * rewrite of this markup.
	 *
	 * @param array<string, mixed> $config Effective settings.
	 */
	private function part_video_controls( array $config, array $atts = array() ): string {
		$buttons = array();

		if ( array() !== (array) ( $atts['tracks'] ?? array() ) ) {
			$buttons['captions'] = array(
				'icons' => array( 'cc' ),
				'label' => __( 'Subtitles', 'imagina-player' ),
			);
		}

		if ( array() !== (array) ( $atts['chapters'] ?? array() ) ) {
			$buttons['chapters'] = array(
				'icons' => array( 'chapters' ),
				'label' => __( 'Chapters', 'imagina-player' ),
			);
		}

		// Hidden until the manifest reports more than one rendition; a stream with
		// a single quality is not a choice.
		$buttons['quality'] = array(
			'icons' => array( 'quality' ),
			'label' => __( 'Quality', 'imagina-player' ),
		);

		$buttons += array(
			'pip'        => array(
				'icons' => array( 'pip' ),
				'label' => __( 'Picture in picture', 'imagina-player' ),
			),
			'fullscreen' => array(
				'icons' => array( 'expand', 'collapse' ),
				'label' => __( 'Full screen', 'imagina-player' ),
			),
		);

		/**
		 * Filter the buttons on the video control bar.
		 *
		 * Each entry is `key => [ icons: string[], label: string ]`. The module
		 * binds `.imgp__vbtn--{key}`; a button with no handler does nothing, so
		 * add both or neither.
		 *
		 * @param array<string, array{icons: array<int, string>, label: string}> $buttons Buttons.
		 * @param array<string, mixed>                                           $config  Effective settings.
		 */
		$buttons = (array) apply_filters( 'imagina_player_video_controls', $buttons, $config );

		$html = '';

		foreach ( $buttons as $key => $button ) {
			$icons = '';

			foreach ( (array) ( $button['icons'] ?? array() ) as $icon ) {
				$icons .= Icons::get( (string) $icon, 'imgp__icon--' . sanitize_html_class( (string) $icon ) );
			}

			$html .= sprintf(
				'<button type="button" class="imgp__vbtn imgp__vbtn--%s" aria-label="%s" hidden>%s</button>',
				esc_attr( sanitize_html_class( (string) $key ) ),
				esc_attr( (string) ( $button['label'] ?? '' ) ),
				$icons
			);
		}

		if ( '' === $html ) {
			return '';
		}

		// One panel, shared. The captions and chapters menus never open at the
		// same time, so a second container would only be a second thing to
		// position, style and keep in step.
		return '<div class="imgp__vcontrols">' . $html
			. '<div class="imgp__menu" role="menu" hidden></div></div>';
	}

	/**
	 * One layer, hidden until its moment.
	 *
	 * Rendered by the server rather than built in JavaScript so that it is in
	 * the page for anything that reads the page — a search engine, a reader
	 * mode, someone with scripting off. `hidden` is the only thing standing
	 * between it and being visible, and the runtime removes that.
	 *
	 * @param array<string, mixed> $layer Sanitised layer.
	 */
	private function part_layer( array $layer, string $id ): string {
		$type = (string) $layer['type'];

		ob_start();
		?>
		<div
			class="imgp__layer imgp__layer--<?php echo esc_attr( $type ); ?>"
			data-layer="<?php echo esc_attr( (string) wp_json_encode( array( 'type' => $type, 'at' => $layer['at'] ) ) ); ?>"
			role="<?php echo 'bar' === $type ? 'complementary' : 'dialog'; ?>"
			<?php echo 'bar' === $type ? '' : 'aria-modal="false"'; ?>
			aria-labelledby="<?php echo esc_attr( $id ); ?>-t"
			hidden
		>
			<div class="imgp__layer-body">
				<?php if ( '' !== $layer['title'] ) : ?>
					<p class="imgp__layer-title" id="<?php echo esc_attr( $id ); ?>-t">
						<?php echo esc_html( (string) $layer['title'] ); ?>
					</p>
				<?php endif; ?>

				<?php if ( '' !== $layer['text'] ) : ?>
					<p class="imgp__layer-text"><?php echo esc_html( (string) $layer['text'] ); ?></p>
				<?php endif; ?>

				<?php if ( 'email' === $type ) : ?>
					<form class="imgp__layer-form" novalidate>
						<label class="imgp__sr" for="<?php echo esc_attr( $id ); ?>-e">
							<?php esc_html_e( 'Email address', 'imagina-player' ); ?>
						</label>
						<input
							class="imgp__layer-input"
							id="<?php echo esc_attr( $id ); ?>-e"
							type="email"
							name="email"
							autocomplete="email"
							required
							placeholder="<?php esc_attr_e( 'you@example.com', 'imagina-player' ); ?>"
						/>
						<?php
						/*
						 * A field no person can see and no person will fill in.
						 * Anything that arrives with it filled came from a script,
						 * and is dropped without a word — which is cheaper and
						 * kinder than a captcha.
						 */
						?>
						<div class="imgp__hp" aria-hidden="true">
							<label for="<?php echo esc_attr( $id ); ?>-w">
								<?php esc_html_e( 'Leave this field empty', 'imagina-player' ); ?>
							</label>
							<input id="<?php echo esc_attr( $id ); ?>-w" type="text" name="website" tabindex="-1" autocomplete="off" />
						</div>
						<button type="submit" class="imgp__layer-button">
							<?php echo esc_html( (string) $layer['button'] ); ?>
						</button>
					</form>

					<?php if ( '' !== $layer['consent'] ) : ?>
						<p class="imgp__layer-fine"><?php echo esc_html( (string) $layer['consent'] ); ?></p>
					<?php endif; ?>

					<p class="imgp__layer-thanks" hidden><?php echo esc_html( (string) $layer['thanks'] ); ?></p>
				<?php else : ?>
					<a
						class="imgp__layer-button"
						href="<?php echo esc_url( (string) $layer['url'] ); ?>"
						<?php echo $layer['newTab'] ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>
					><?php echo esc_html( (string) $layer['button'] ); ?></a>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $layer['skip'] ) || 'bar' === $type ) : ?>
				<button type="button" class="imgp__layer-close" aria-label="<?php esc_attr_e( 'Close', 'imagina-player' ); ?>">
					<?php echo Icons::get( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG. ?>
				</button>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Where things that sit on top of the picture mount.
	 *
	 * Empty today. It exists now because chapters, captions, calls to action and
	 * an email gate all need the same thing — a stacking context above the video
	 * and below the controls — and retrofitting one later means moving every
	 * z-index in the stylesheet.
	 *
	 * @param array<string, mixed> $config Effective settings.
	 */
	private function part_layers( Track $track, array $config, array $atts, string $id ): string {
		$layers = array();

		foreach ( (array) ( $atts['layers'] ?? array() ) as $index => $layer ) {
			$layers[ 'layer-' . $index ] = $this->part_layer( (array) $layer, $id . '-l' . $index );
		}

		/**
		 * Filter the overlay layers rendered above a video.
		 *
		 * Each entry is a block of HTML, already escaped by whoever added it.
		 * Keys are for ordering and de-duplication, not output.
		 *
		 * @param array<string, string> $layers Layer markup, keyed by name.
		 * @param Track                 $track  Resolved track.
		 * @param array<string, mixed>  $config Effective settings.
		 * @param string                $id     Player DOM id.
		 */
		$layers = (array) apply_filters( 'imagina_player_video_layers', $layers, $track, $config, $id );

		if ( array() === $layers ) {
			return '';
		}

		$html = implode( '', array_map( 'strval', $layers ) );

		return '<div class="imgp__layers">' . $html . '</div>';
	}

	private function part_logo(): string {
		$branding = Settings::branding();
		$logo     = (string) ( $branding['logo'] ?? '' );

		if ( '' === $logo ) {
			return '';
		}

		$height = max( 8, min( 80, (int) ( $branding['logo_height'] ?? 20 ) ) );
		$link   = (string) ( $branding['logo_link'] ?? '' );

		ob_start();
		?>
		<div class="imgp__logo" style="--imgp-logo-height:<?php echo esc_attr( (string) $height ); ?>px">
			<?php if ( '' !== $link ) : ?>
				<a href="<?php echo esc_url( $link ); ?>" rel="noopener">
			<?php endif; ?>
			<img src="<?php echo esc_url( $logo ); ?>" alt="" loading="lazy" decoding="async" />
			<?php if ( '' !== $link ) : ?>
				</a>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	private function part_play(): string {
		ob_start();
		?>
		<button
			type="button"
			class="imgp__play"
			aria-label="<?php esc_attr_e( 'Play', 'imagina-player' ); ?>"
			aria-pressed="false"
		>
			<?php
			echo Icons::get( 'play', 'imgp__icon--play' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG.
			echo Icons::get( 'pause', 'imgp__icon--pause' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG.
			?>
		</button>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $config Effective settings.
	 */
	private function part_meta( Track $track, array $config ): string {
		$show_artist = $config['show_artist'] && '' !== $track->artist;
		$show_title  = $config['show_title'] && '' !== $track->title;

		if ( ! $show_artist && ! $show_title ) {
			return '';
		}

		ob_start();
		?>
		<div class="imgp__meta">
			<?php if ( $show_artist ) : ?>
				<span class="imgp__artist"><?php echo esc_html( $track->artist ); ?></span>
			<?php endif; ?>
			<?php if ( $show_title ) : ?>
				<span class="imgp__title"><?php echo esc_html( $track->title ); ?></span>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Skip, speed, download and volume — everything that sits to the trailing side.
	 *
	 * @param array<string, mixed> $config Effective settings.
	 */
	private function part_controls( Track $track, array $config ): string {
		ob_start();
		?>
		<div class="imgp__controls">
			<?php if ( $config['show_skip'] ) : ?>
				<button
					type="button"
					class="imgp__skip imgp__skip--back"
					aria-label="<?php echo esc_attr( sprintf( /* translators: %d: number of seconds */ __( 'Rewind %d seconds', 'imagina-player' ), (int) $config['skip_seconds'] ) ); ?>"
				><?php echo Icons::get( 'back' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG. ?></button>
				<button
					type="button"
					class="imgp__skip imgp__skip--forward"
					aria-label="<?php echo esc_attr( sprintf( /* translators: %d: number of seconds */ __( 'Forward %d seconds', 'imagina-player' ), (int) $config['skip_seconds'] ) ); ?>"
				><?php echo Icons::get( 'forward' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG. ?></button>
			<?php endif; ?>

			<?php if ( $config['show_speed'] ) : ?>
				<button
					type="button"
					class="imgp__speed"
					aria-label="<?php esc_attr_e( 'Playback speed', 'imagina-player' ); ?>"
				>1&times;</button>
			<?php endif; ?>

			<?php if ( $config['show_download'] ) : ?>
				<a
					class="imgp__download"
					href="<?php echo esc_url( '' !== $track->download_url ? $track->download_url : $track->src ); ?>"
					download
					aria-label="<?php esc_attr_e( 'Download', 'imagina-player' ); ?>"
				><?php echo Icons::get( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG. ?></a>
			<?php endif; ?>

			<?php if ( $config['show_volume'] ) : ?>
				<div class="imgp__volume">
					<button
						type="button"
						class="imgp__mute"
						aria-label="<?php esc_attr_e( 'Mute', 'imagina-player' ); ?>"
						aria-pressed="false"
					>
						<?php
						echo Icons::get( 'volume', 'imgp__icon--volume' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG.
						echo Icons::get( 'muted', 'imgp__icon--muted' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG.
						?>
					</button>
					<input
						class="imgp__volume-slider"
						type="range"
						min="0"
						max="1"
						step="0.01"
						value="1"
						aria-label="<?php esc_attr_e( 'Volume', 'imagina-player' ); ?>"
					/>
				</div>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	private function peaks_payload( Track $track, array $config ): array {
		// A video never gets peaks — no waveform is drawn over it, so measuring
		// one would be a download and a decode for nothing.
		if ( $track->is_video() || ! Skins::uses_waveform( (string) $config['skin'] ) ) {
			return array(
				'peaks'       => '',
				'token'       => '',
				'can_compute' => false,
			);
		}

		$key = $track->peaks_key();

		if ( '' === $key ) {
			return array(
				'peaks'       => '',
				'token'       => '',
				'can_compute' => false,
			);
		}

		$record = $this->peaks->get( $key );

		if ( is_array( $record ) ) {
			return array(
				'peaks'       => (string) $record['peaks'],
				'token'       => '',
				'can_compute' => false,
			);
		}

		// Nothing cached: try to get the server to do the work out of band, and
		// let the browser fill in meanwhile if the site allows it.
		if ( $track->attachment_id > 0 ) {
			PeaksRepository::schedule_generation( $track->attachment_id );
		}

		$peaks_settings  = Settings::peaks_settings();
		$client_fallback = ! empty( $peaks_settings['client_fallback'] );

		return array(
			'peaks'       => '',
			'token'       => $client_fallback ? PeaksToken::create( $key, PeaksRepository::resolution() ) : '',
			'can_compute' => $client_fallback,
		);
	}

	/**
	 * Editors need to see why nothing rendered; visitors should see nothing.
	 */
	private function render_placeholder(): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		return sprintf(
			'<div class="imgp imgp--empty"><p>%s</p></div>',
			esc_html__( 'Imagina Player: no audio file selected.', 'imagina-player' )
		);
	}

	public function format_time( float $seconds ): string {
		if ( $seconds <= 0 ) {
			return '--:--';
		}

		$seconds = (int) round( $seconds );
		$hours   = intdiv( $seconds, 3600 );
		$minutes = intdiv( $seconds % 3600, 60 );
		$rest    = $seconds % 60;

		if ( $hours > 0 ) {
			return sprintf( '%d:%02d:%02d', $hours, $minutes, $rest );
		}

		return sprintf( '%d:%02d', $minutes, $rest );
	}
}
