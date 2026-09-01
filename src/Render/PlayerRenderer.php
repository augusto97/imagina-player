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
use ImaginaPlayer\Player\Video;
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

		// Resolved once: the site's video settings with this block's own
		// answers over them. Every reader below takes it rather than going back
		// to the options table, or two of them could disagree.
		$video_config = Video::resolve( $atts );

		/*
		 * For a video, the video settings are the authority on which controls
		 * appear. The preset's own `show_*` flags describe an audio player —
		 * they are what the Controls panel has always edited — and letting both
		 * lists apply is exactly what made a video block show a mixture of the
		 * two with neither of them complete.
		 */
		if ( $track->is_video() ) {
			foreach ( array( 'show_skip', 'show_time', 'show_volume', 'show_title' ) as $key ) {
				$config[ $key ] = (bool) $video_config[ $key ];
			}
		}

		/*
		 * A skin belongs to a medium. The saved one is used when it is one of
		 * this medium's own and replaced by that medium's default when it is
		 * not — an author who swaps an audio file for a video is otherwise left
		 * with "card with cover" on a picture that has no cover.
		 */
		$skin = Skins::resolve( (string) $config['skin'], $track->is_video() );

		$classes = array(
			'imgp',
			'imgp--skin-' . $skin,
			$track->thumbnail && $config['show_thumbnail'] ? 'imgp--has-thumb' : '',
			$config['sticky'] ? 'imgp--sticky' : '',
			$config['sticky'] ? 'imgp--stick-' . $config['sticky_position'] : '',
			$track->is_video() ? 'imgp--video' : 'imgp--audio',
			$track->is_video() ? 'imgp--cc-' . sanitize_html_class( (string) $video_config['caption_size'] ) : '',
			$track->is_video() ? 'imgp--ccbg-' . sanitize_html_class( (string) $video_config['caption_bg'] ) : '',
			/*
			 * Printed by the server rather than added by the script, so the
			 * crop is in place before the frame is: a frame that appears at
			 * full height and is cropped a moment later shows the provider's
			 * title bar for exactly that moment, which is the thing being
			 * hidden.
			 */
			$track->is_provider() && ! empty( $video_config['provider_bare'] ) ? 'imgp--bare-provider' : '',
			(int) $config['border_radius'] > 0 ? 'imgp--rounded-box' : '',
			$config['rounded_bars'] ? 'imgp--rounded' : '',
			$atts['className'],
		);

		$client_config = array(
			'id'          => $id,
			'skin'        => $skin,
			'centered'    => Skins::is_centered( $skin ),
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
			/*
			 * A name for this player that survives a page load, so a call to
			 * action somebody has dismissed stays dismissed. The DOM id was
			 * being used for that and it is minted fresh on every render, so
			 * nothing was ever recognised — the promise not to show the same
			 * gate twice was never kept once, and the browser's storage filled
			 * with keys that could not match anything again.
			 */
			'layerKey'    => '' === $track->src ? '' : substr( md5( $track->src ), 0, 12 ),
			'peaksToken'  => $peaks_payload['token'],
			'canCompute'  => $peaks_payload['can_compute'],
			// Non-zero when the file is served through a signed link, which can
			// expire while a cached page is still being served.
			'protectedId' => Vault::is_protected( $track->attachment_id ) ? $track->attachment_id : 0,
		);

		// Only present when there is something to run, because its absence is
		// what keeps the layer chunk from being downloaded at all.
		if ( array() !== (array) $atts['layers'] ) {
			/*
			 * Rebuilt key by key rather than passed through, so a field that is
			 * only for the server never reaches the page. The cost of that is
			 * this list has to be kept in step with what the script reads: an
			 * end time added to the schema and sanitised and rendered still did
			 * nothing, because it stopped here.
			 */
			$client_config['layers'] = array_map(
				static fn( array $layer ): array => array(
					'type'  => (string) $layer['type'],
					'at'    => (int) $layer['at'],
					'until' => (int) ( $layer['until'] ?? 0 ),
					'skip'  => (bool) $layer['skip'],
					'list'  => (string) ( $layer['list'] ?? '' ),
				),
				(array) $atts['layers']
			);
		}

		// Namespaced rather than flattened: the video module reads `video` and
		// nothing else, so growing it later cannot collide with an audio key.
		if ( $track->is_video() ) {
			$video = $video_config;

			$client_config['video'] = array(
				'ratio'     => $track->aspect_ratio,
				'poster'    => $track->poster,
				'hideAfter' => max( 0, (int) $video['hide_after'] ),
				// The browser cannot tell from the element that this is adaptive
				// streaming, and loading 400 KB of hls.js to find out would be
				// the whole cost of the feature paid on every video.
				'hls'       => $track->is_hls(),
				// Stop when the tab is hidden or the picture scrolls away.
				'focus'     => (bool) $video_config['focus_mode'],
				// Subtitles on from the first frame.
				'captionsOn' => (bool) $video_config['captions_on'],
				// Its absence is what keeps the storyboard chunk unloaded.
				'storyboard' => (string) $atts['storyboard'],
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

			/*
			 * Its presence is what makes the browser load the provider chunk at
			 * all, so it is added only when there is a provider — and the frame
			 * address is built here rather than in the browser, so the privacy
			 * setting is applied in one place.
			 */
			if ( $track->is_provider() && null !== $track->provider ) {
				$client_config['video']['provider']     = $track->provider->name;
				$client_config['video']['providerId']   = $track->provider->id;
				$client_config['video']['providerHash'] = $track->provider->hash;
				$client_config['video']['embedUrl']     = $track->provider->embed_url(
					(bool) ( $video_config['provider_privacy'] ?? true )
				);

				/*
				 * A provider video has no element, so `autoplay`, `muted` and
				 * `loop` — which the renderer prints as attributes on an
				 * `<audio>` or `<video>` — reached nothing at all. They were
				 * switches in the block that did nothing on a YouTube video,
				 * with no sign of it anywhere. The provider takes them as
				 * parameters instead.
				 */
				$client_config['video']['autoplay'] = (bool) $atts['autoplay'];
				$client_config['video']['muted']    = (bool) $atts['muted'];
				$client_config['video']['loop']     = (bool) $atts['loop'];

				/*
				 * Whether to hide the provider's own interface. Only ever sent
				 * for a provider video, because on a file served from here
				 * there is no other interface to hide.
				 */
				$client_config['video']['providerBare'] = ! empty( $video_config['provider_bare'] );
			}
		}

		/**
		 * Filter the JSON config handed to the front-end for one player.
		 *
		 * @param array<string, mixed> $client_config Client config.
		 * @param array<string, mixed> $atts          Sanitised attributes.
		 * @param Track                $track         Resolved track.
		 */
		$client_config = apply_filters( 'imagina_player_client_config', $client_config, $atts, $track );

		$vars = Config::css_variables( $config );

		/*
		 * The video half of the paint. These were hard-coded in the stylesheet
		 * — a bar of near-black and white subtitles — so a player could carry a
		 * site's colours everywhere except the two places a viewer looks at
		 * most while a video is actually playing.
		 */
		if ( $track->is_video() ) {
			$vars['--imgp-chrome']    = self::translucent( (string) $video_config['chrome_color'], 0.78 );
			$vars['--imgp-chrome-bg'] = (string) $video_config['chrome_color'];
			$vars['--imgp-cc']        = (string) $video_config['caption_color'];

			/*
			 * The controls on the bar. `--imgp-on-chrome` was `#fff` in the
			 * stylesheet and nowhere else, so the icons stayed white however
			 * pale the bar behind them was set — pick a light control bar and
			 * every icon disappeared into it. On `auto` the colour is worked
			 * out from the bar, the same way the accent's foreground is.
			 */
			$control = (string) $video_config['control_color'];

			$vars['--imgp-on-chrome'] = 'auto' === $control
				? Config::readable_on( (string) $video_config['chrome_color'] )
				: $control;

			/*
			 * The volume rail is drawn from `--imgp-control`, which on audio is
			 * the icon colour. A video's icons are the ones above, so the rail
			 * follows them rather than the audio preset's slate grey — which
			 * was a dark line on a dark bar.
			 */
			$vars['--imgp-control'] = $vars['--imgp-on-chrome'];

			/*
			 * The played part of the seek bar, and the volume knob that goes
			 * with it. It took `--imgp-wave-progress`, which is the waveform's
			 * colour: an audio-only setting the video block does not show, so
			 * the one coloured thing a viewer watches move could not be reached
			 * from the block at all. On `auto` it is the accent.
			 */
			$progress = (string) $video_config['progress_color'];

			$vars['--imgp-progress'] = 'auto' === $progress
				? (string) $config['accent']
				: $progress;

			$vars['--imgp-on-progress'] = Config::readable_on( $vars['--imgp-progress'] );
		}

		$style = Config::style_attribute( $vars );

		// Layout follows the *medium*, not the skin. Every audio skin arranges a
		// row of controls beside a scrubber; a video needs them over the picture,
		// and no choice of skin changes that.
		$layout = $track->is_video() ? 'theater' : Skins::layout( $skin );

		$parts = array(
			'media'    => $this->part_media( $track, $atts, $config, $video_config ),
			// A video always gets a scrubber. A skin that hides it was designed for
			// a bar of audio controls, and a video without a seek bar is broken.
			'scrubber' => $track->is_video() || Skins::has_scrubber( $skin )
				? $this->part_scrubber( $track, $config )
				: '',
			'thumb'    => $config['show_thumbnail'] && '' !== $track->thumbnail ? $this->part_thumb( $track ) : '',
			'play'     => $this->part_play(),
			'meta'     => $this->part_meta( $track, $config ),
			'controls' => $this->part_controls( $track, $config ),
			'logo'     => $this->part_logo(),
			// Only when the player may detach. A floating player a reader
			// cannot dismiss is the thing everybody hates about floating
			// players; without this the only way out is to scroll back.
			'unstick'  => $config['sticky'] ? $this->part_unstick() : '',
		);

		/*
		 * Two groups, because they belong in two places.
		 *
		 * A call to action and an email gate cover the picture — they are
		 * asking a question and they stop playback, so covering the controls is
		 * the point. An action bar is a standing offer that does not interrupt,
		 * and pinning it to the bottom of the picture put it exactly on top of
		 * the control row: the headline landed behind the play button and the
		 * button landed on the volume slider. Presto puts its action bar
		 * underneath the video and Fluent's "sits below the player", for this
		 * reason.
		 */
		$parts['layers'] = $this->part_layers( $track, $config, $atts, $id, array( 'cta', 'email' ) );
		$parts['bars']   = $this->part_layers( $track, $config, $atts, $id, array( 'bar' ) );

		if ( 'theater' === $layout ) {
			$video_settings = $video_config;

			$parts['poster']  = $this->part_poster( $track, $video_settings );
			$parts['bigplay'] = empty( $video_settings['big_play'] ) ? '' : $this->part_big_play();
			$parts['video']   = $this->part_video_controls( $config, $atts, $video_config );
			$parts['mark']    = $this->part_watermark( $atts );
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
				$chrome = sprintf(
					'<div class="imgp__chrome">%s<div class="imgp__bar">%s%s%s%s</div></div>',
					$parts['scrubber'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['play'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['meta'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['controls'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['video'] . $parts['logo'] // phpcs:ignore WordPress.Security.EscapeOutput
				);

				/*
				 * The stacked skin puts the bar under the picture instead of on
				 * it, and that is a difference in where the markup goes rather
				 * than in how it is painted: the stage crops to the video's
				 * shape and hides anything past it, so a bar inside it can only
				 * ever be over the picture.
				 */
				$outside = 'stacked' === $skin;

				printf(
					'<div class="imgp__stage" style="--imgp-ratio:%s">%s%s%s%s%s</div>%s',
					esc_attr( str_replace( ':', ' / ', $track->aspect_ratio ) ),
					$parts['media'], // phpcs:ignore WordPress.Security.EscapeOutput -- assembled from escaped parts.
					$parts['poster'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['bigplay'], // phpcs:ignore WordPress.Security.EscapeOutput
					$parts['mark'] . $parts['layers'], // phpcs:ignore WordPress.Security.EscapeOutput
					$outside ? '' : $chrome, // phpcs:ignore WordPress.Security.EscapeOutput
					$outside ? $chrome : '' // phpcs:ignore WordPress.Security.EscapeOutput
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

			/*
			 * Video puts the interrupting layers inside the stage, over the
			 * picture; audio has no picture, so they go on the player itself.
			 * The bar goes below the player either way.
			 */
			if ( 'theater' !== $layout ) {
				echo $parts['layers']; // phpcs:ignore WordPress.Security.EscapeOutput -- assembled from escaped parts.
			}

			echo $parts['bars']; // phpcs:ignore WordPress.Security.EscapeOutput -- assembled from escaped parts.

			echo $parts['unstick']; // phpcs:ignore WordPress.Security.EscapeOutput -- assembled from escaped parts.
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
	private function part_media( Track $track, array $atts, array $config, array $video_config ): string {
		if ( $track->is_provider() ) {
			return $this->part_embed( $track );
		}

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
				<?php echo $this->download_guards( $track, $config, $video_config ); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed attribute names. ?>
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
			/*
			 * `data` has to be asked for. `esc_url()` allows the protocols in
			 * `wp_allowed_protocols()`, which does not include it, so escaping
			 * this the ordinary way emptied the attribute and the chapters
			 * simply were not there — on every real site, silently, because the
			 * test stub for `esc_url()` was `htmlspecialchars` and passed it.
			 *
			 * Safe to allow here because the content is not a URL somebody
			 * supplied: `Captions::data_uri()` base64-encodes a VTT file this
			 * code just built out of already-sanitised chapter titles.
			 */
			$html .= sprintf(
				'<track kind="chapters" src="%s" label="%s" default />',
				esc_url( Captions::data_uri( $chapters ), array( 'data' ) ),
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
	 * @param array<string, mixed> $config       Effective settings.
	 * @param array<string, mixed> $video_config Effective video settings.
	 */
	private function download_guards( Track $track, array $config, array $video_config ): string {
		if ( ! empty( $config['show_download'] ) || empty( $video_config['block_download'] ) ) {
			// Two reasons not to: the setting is off, or a download was offered
			// deliberately — and hiding the browser's own next to our own
			// download button would be theatre.
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
	private function part_poster( Track $track, array $video ): string {
		if ( '' === $track->poster ) {
			return '';
		}

		$fit = 'contain' === ( $video['poster_fit'] ?? 'cover' ) ? 'contain' : 'cover';

		return sprintf(
			'<div class="imgp__poster imgp__poster--%s" aria-hidden="true"><img src="%s" alt="" decoding="async" fetchpriority="high" /></div>',
			esc_attr( $fit ),
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
	/**
	 * @param array<string, mixed> $config       Effective player settings.
	 * @param array<string, mixed> $atts         Sanitised attributes.
	 * @param array<string, mixed> $video_config Effective video settings.
	 */
	private function part_video_controls( array $config, array $atts, array $video_config ): string {
		$buttons = array();

		// Two conditions each, and they say different things: whether there is
		// anything to show, and whether the author wants the button for it.
		if ( array() !== (array) ( $atts['tracks'] ?? array() ) && ! empty( $video_config['show_captions'] ) ) {
			$buttons['captions'] = array(
				'icons' => array( 'cc' ),
				'label' => __( 'Subtitles', 'imagina-player' ),
			);
		}

		if ( array() !== (array) ( $atts['chapters'] ?? array() ) && ! empty( $video_config['show_chapters'] ) ) {
			$buttons['chapters'] = array(
				'icons' => array( 'chapters' ),
				'label' => __( 'Chapters', 'imagina-player' ),
			);
		}

		/*
		 * Finding the moment a word is said. Only offered when the video
		 * carries subtitles, because that is the text it searches — there is
		 * nothing to index on the server and nothing extra to fetch.
		 */
		if ( array() !== (array) ( $atts['tracks'] ?? array() ) && ! empty( $video_config['show_search'] ) ) {
			$buttons['search'] = array(
				'icons' => array( 'search' ),
				'label' => __( 'Search what is said', 'imagina-player' ),
			);
		}

		// Hidden until the manifest reports more than one rendition; a stream with
		// a single quality is not a choice.
		$buttons['quality'] = array(
			'icons' => array( 'quality' ),
			'label' => __( 'Quality', 'imagina-player' ),
		);

		$video = $video_config;

		if ( ! empty( $video['show_pip'] ) ) {
			$buttons['pip'] = array(
				'icons' => array( 'pip' ),
				'label' => __( 'Picture in picture', 'imagina-player' ),
			);
		}

		if ( ! empty( $video['show_fullscreen'] ) ) {
			$buttons['fullscreen'] = array(
				'icons' => array( 'expand', 'collapse' ),
				'label' => __( 'Full screen', 'imagina-player' ),
			);
		}

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
	private function part_layer( array $layer, string $id, int $index = 0 ): string {
		$type = (string) $layer['type'];

		ob_start();
		?>
		<div
			class="imgp__layer imgp__layer--<?php echo esc_attr( $type ); ?>"
			<?php
			/*
			 * Its own place in the list, carried in the markup.
			 *
			 * The script used to line the elements up with the settings by
			 * walking the document in order, which only holds while every layer
			 * is rendered in one container. Bars now sit below the picture and
			 * the rest over it, so document order and list order are no longer
			 * the same thing — and a mismatch there does not fail, it shows the
			 * wrong layer at the wrong moment.
			 */
			?>
			data-layer-index="<?php echo esc_attr( (string) $index ); ?>"
			data-layer="<?php echo esc_attr( (string) wp_json_encode( array( 'type' => $type, 'at' => $layer['at'], 'until' => $layer['until'] ?? 0 ) ) ); ?>"
			role="<?php echo 'bar' === $type ? 'complementary' : 'dialog'; ?>"
			<?php echo 'bar' === $type ? '' : 'aria-modal="false"'; ?>
			aria-labelledby="<?php echo esc_attr( $id ); ?>-t"
			hidden
		>
			<div class="imgp__layer-body">
				<?php
				/*
				 * Copy and action are separate boxes rather than one stack,
				 * because beside an audio player there is no picture to cover
				 * and the offer has to read as a strip: words on one side, the
				 * thing to press on the other. Over a video the same two boxes
				 * sit one above the other.
				 */
				?>
				<div class="imgp__layer-copy">
					<?php if ( '' !== $layer['title'] ) : ?>
						<p class="imgp__layer-title" id="<?php echo esc_attr( $id ); ?>-t">
							<?php echo esc_html( (string) $layer['title'] ); ?>
						</p>
					<?php endif; ?>

					<?php if ( '' !== $layer['text'] ) : ?>
						<p class="imgp__layer-text"><?php echo esc_html( (string) $layer['text'] ); ?></p>
					<?php endif; ?>
				</div>

				<div class="imgp__layer-action">
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
	/**
	 * A colour with an alpha, for painting over a picture.
	 *
	 * The bar has always been translucent so the video is not lost behind it;
	 * making the colour settable must not quietly make it opaque.
	 *
	 * @param string $hex   A colour from the settings, already sanitised.
	 * @param float  $alpha How much of it to keep.
	 */
	private static function translucent( string $hex, float $alpha ): string {
		$clean = ltrim( trim( $hex ), '#' );

		if ( 3 === strlen( $clean ) ) {
			$clean = $clean[0] . $clean[0] . $clean[1] . $clean[1] . $clean[2] . $clean[2];
		}

		if ( 6 !== strlen( $clean ) || ! ctype_xdigit( $clean ) ) {
			return 'rgb(0 0 0 / 78%)';
		}

		return sprintf(
			'rgb(%d %d %d / %d%%)',
			hexdec( substr( $clean, 0, 2 ) ),
			hexdec( substr( $clean, 2, 2 ) ),
			hexdec( substr( $clean, 4, 2 ) ),
			(int) round( $alpha * 100 )
		);
	}

	/**
	 * The stand-in for a video somebody else serves.
	 *
	 * No iframe. An iframe in the markup is a request to Google on every page
	 * view whether or not anyone watches — a third-party cookie and a few
	 * hundred kilobytes charged to a page that may never use them. What is here
	 * instead is the still image the visitor would see anyway, and a link to
	 * the video, which is what somebody with no JavaScript gets. The frame is
	 * built by the browser when play is pressed.
	 */
	private function part_embed( Track $track ): string {
		$provider = $track->provider;

		if ( null === $provider ) {
			return '';
		}

		ob_start();
		?>
		<div class="imgp__embed" data-provider="<?php echo esc_attr( $provider->name ); ?>">
			<noscript>
				<a class="imgp__embed-link" href="<?php echo esc_url( $track->src ); ?>" rel="noopener noreferrer" target="_blank">
					<?php
					printf(
						/* translators: %s: YouTube or Vimeo. */
						esc_html__( 'Watch this video on %s', 'imagina-player' ),
						esc_html( $provider->label() )
					);
					?>
				</a>
			</noscript>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * A mark over the picture.
	 *
	 * Not protection, and it should not be sold as any: anybody recording the
	 * screen records the mark too, and anybody who wants it gone crops it. What
	 * it does is make a copy traceable and an embed on somebody else's site
	 * obviously somebody else's, which is the honest reason to want one.
	 *
	 * @param array<string, mixed> $atts Sanitised attributes.
	 */
	private function part_watermark( array $atts ): string {
		// Checked before the element is built, not while it is printed: an
		// address that does not survive escaping should leave no element at
		// all, rather than an `<img src="">` that every browser reports as a
		// broken image.
		$src = esc_url_raw( (string) ( $atts['watermark'] ?? '' ) );

		if ( '' === $src ) {
			return '';
		}

		$positions = array( 'top-left', 'top-right', 'bottom-left', 'bottom-right' );
		$position  = (string) ( $atts['watermarkPosition'] ?? 'top-right' );
		$position  = in_array( $position, $positions, true ) ? $position : 'top-right';
		$opacity   = max( 5, min( 100, (int) ( $atts['watermarkOpacity'] ?? 55 ) ) );

		return sprintf(
			'<img class="imgp__watermark imgp__watermark--%s" src="%s" alt="" aria-hidden="true" decoding="async" style="--imgp-mark-opacity:%s" />',
			esc_attr( $position ),
			esc_url( $src ),
			esc_attr( (string) round( $opacity / 100, 2 ) )
		);
	}

	/**
	 * The way out of a player that has detached and started following.
	 *
	 * Hidden until it does. A floating player with no way to dismiss it is the
	 * single most disliked thing about floating players, and scrolling back to
	 * where it came from is not an answer on a long page.
	 */
	private function part_unstick(): string {
		return sprintf(
			'<button type="button" class="imgp__unstick" aria-label="%s">%s</button>',
			esc_attr__( 'Stop following', 'imagina-player' ),
			Icons::get( 'close' )
		);
	}

	/**
	 * @param array<int, string> $only Layer types to render, or empty for all.
	 */
	private function part_layers( Track $track, array $config, array $atts, string $id, array $only = array() ): string {
		$layers = array();

		foreach ( (array) ( $atts['layers'] ?? array() ) as $index => $layer ) {
			if ( array() !== $only && ! in_array( (string) ( ( (array) $layer )['type'] ?? '' ), $only, true ) ) {
				continue;
			}

			$layers[ 'layer-' . $index ] = $this->part_layer( (array) $layer, $id . '-l' . $index, (int) $index );
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

		$class = array( 'imgp__layers' );

		if ( array( 'bar' ) === $only ) {
			$class[] = 'imgp__layers--under';
		}

		return '<div class="' . esc_attr( implode( ' ', $class ) ) . '">' . $html . '</div>';
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
