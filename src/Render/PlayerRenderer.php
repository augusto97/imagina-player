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
use ImaginaPlayer\Media\Track;
use ImaginaPlayer\Peaks\PeaksRepository;
use ImaginaPlayer\Peaks\PeaksToken;
use ImaginaPlayer\Player\Attributes;
use ImaginaPlayer\Player\Config;
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
			$atts['className'],
		);

		$client_config = array(
			'id'          => $id,
			'skin'        => $config['skin'],
			'bars'        => (int) $config['wave_bars'],
			'gap'         => (int) $config['wave_gap'],
			'reflection'  => (float) $config['wave_reflection'],
			'resolution'  => PeaksRepository::resolution(),
			'startTime'   => (float) $atts['startTime'],
			'skipSeconds' => (int) $config['skip_seconds'],
			'remember'    => (bool) $config['remember_position'],
			'sticky'      => (bool) $config['sticky'],
			'duration'    => $track->duration,
			'peaksKey'    => $track->peaks_key(),
			'peaksToken'  => $peaks_payload['token'],
			'canCompute'  => $peaks_payload['can_compute'],
		);

		/**
		 * Filter the JSON config handed to the front-end for one player.
		 *
		 * @param array<string, mixed> $client_config Client config.
		 * @param array<string, mixed> $atts          Sanitised attributes.
		 * @param Track                $track         Resolved track.
		 */
		$client_config = apply_filters( 'imagina_player_client_config', $client_config, $atts, $track );

		$style = Config::style_attribute( Config::css_variables( $config ) );

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
			<?php $this->render_media( $track, $atts, $config ); ?>

			<?php if ( 'minimal' !== $config['skin'] ) : ?>
				<div class="imgp__scrubber">
					<?php if ( 'wave' === $config['skin'] ) : ?>
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
			<?php endif; ?>

			<div class="imgp__bar">
				<?php if ( $config['show_thumbnail'] && '' !== $track->thumbnail ) : ?>
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
				<?php endif; ?>

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

				<?php $this->render_meta( $track, $config ); ?>

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
			</div>
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
	private function render_media( Track $track, array $atts, array $config ): void {
		$tag = $track->is_video() ? 'video' : 'audio';
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
		></<?php echo esc_attr( $tag ); ?>>
		<?php
	}

	/**
	 * @param array<string, mixed> $config Effective settings.
	 */
	private function render_meta( Track $track, array $config ): void {
		$show_artist = $config['show_artist'] && '' !== $track->artist;
		$show_title  = $config['show_title'] && '' !== $track->title;

		if ( ! $show_artist && ! $show_title ) {
			return;
		}
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
	}

	/**
	 * Look up cached peaks and mint a write grant when they are missing.
	 *
	 * @param array<string, mixed> $config Effective settings.
	 * @return array{peaks: string, token: string, can_compute: bool}
	 */
	private function peaks_payload( Track $track, array $config ): array {
		if ( 'wave' !== $config['skin'] ) {
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
