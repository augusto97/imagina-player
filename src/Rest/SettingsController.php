<?php
/**
 * Settings API behind the admin interface.
 *
 * The screen is a React application rather than a WordPress options form, so it
 * reads and writes through here. The preview endpoint returns markup produced by
 * the real renderer: what the settings screen shows is literally what the front
 * end will output, not a hand-maintained imitation of it.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Rest;

use ImaginaPlayer\Media\Track;
use ImaginaPlayer\Peaks\PeaksGenerator;
use ImaginaPlayer\Peaks\PeaksRepository;
use ImaginaPlayer\Player\Attributes;
use ImaginaPlayer\Player\Skins;
use ImaginaPlayer\Protection\SelfCheck;
use ImaginaPlayer\Protection\Vault;
use ImaginaPlayer\Render\PlayerRenderer;
use ImaginaPlayer\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SettingsController {

	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$can_manage = static fn(): bool => current_user_can( 'manage_options' );

		register_rest_route(
			PeaksController::REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $can_manage,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => $can_manage,
				),
			)
		);

		// A write method on purpose: it puts a file on disk and makes outbound
		// requests, so it should not be reachable by a link somebody follows.
		register_rest_route(
			PeaksController::REST_NAMESPACE,
			'/protection/self-check',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'self_check' ),
				'permission_callback' => $can_manage,
			)
		);

		register_rest_route(
			PeaksController::REST_NAMESPACE,
			'/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => $can_manage,
				'args'                => array(
					'preset'     => array(
						'type' => 'object',
					),
					'attributes' => array(
						'type' => 'object',
					),
					/*
					 * Which medium the settings screen wants to look at. A
					 * preset carries the accent, the corner radius, the button
					 * colour and a skin, and every one of those is visible on a
					 * video — but the preview only ever rendered audio, so the
					 * half of the plugin most people came for could not be seen
					 * before publishing it.
					 */
					'medium'     => array(
						'type' => 'string',
						'enum' => array( 'audio', 'video' ),
					),
					/*
					 * Unsaved video settings, so the Video section can show
					 * what a change looks like before it is saved — the same
					 * courtesy the preset editor has always had.
					 */
					'video'      => array(
						'type' => 'object',
					),
					'title'      => array( 'type' => 'string', 'maxLength' => 200 ),
					'artist'     => array( 'type' => 'string', 'maxLength' => 200 ),
				),
			)
		);
	}

	public function self_check(): WP_REST_Response {
		return new WP_REST_Response( SelfCheck::run(), 200 );
	}

	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->payload(), 200 );
	}

	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$incoming = $request->get_json_params();
		$settings = Settings::all();

		if ( isset( $incoming['presets'] ) && is_array( $incoming['presets'] ) ) {
			$presets = array();

			foreach ( $incoming['presets'] as $key => $preset ) {
				$key = sanitize_key( (string) $key );

				if ( '' === $key || ! is_array( $preset ) ) {
					continue;
				}

				$presets[ $key ] = Settings::sanitize_preset( $preset );
			}

			// The default preset is what every unconfigured block falls back to;
			// losing it would leave those blocks with nothing to resolve.
			if ( ! isset( $presets[ Settings::DEFAULT_PRESET ] ) ) {
				$presets[ Settings::DEFAULT_PRESET ] = Settings::preset_defaults();
			}

			$settings['presets'] = $presets;
		}

		if ( isset( $incoming['peaks'] ) && is_array( $incoming['peaks'] ) ) {
			$peaks = $incoming['peaks'];

			$settings['peaks']['resolution']        = max( 32, min( 2000, (int) ( $peaks['resolution'] ?? 400 ) ) );
			$settings['peaks']['server_generation'] = ! empty( $peaks['server_generation'] );
			$settings['peaks']['client_fallback']   = ! empty( $peaks['client_fallback'] );
			$settings['peaks']['ffmpeg_path']       = Settings::sanitize_binary_path( (string) ( $peaks['ffmpeg_path'] ?? '' ) );
			$settings['peaks']['max_client_bytes']  = max( 1, min( 200, (int) ( $peaks['max_client_mb'] ?? 25 ) ) ) * MB_IN_BYTES;
		}

		if ( isset( $incoming['metadata'] ) && is_array( $incoming['metadata'] ) ) {
			$metadata = $incoming['metadata'];

			$settings['metadata']['title_from']    = in_array( $metadata['title_from'] ?? '', array( 'auto', 'tags', 'post', 'file', 'none' ), true )
				? (string) $metadata['title_from']
				: 'auto';
			$settings['metadata']['artist_from']   = in_array( $metadata['artist_from'] ?? '', array( 'auto', 'tags', 'none' ), true )
				? (string) $metadata['artist_from']
				: 'auto';
			$settings['metadata']['from_filename'] = ! empty( $metadata['from_filename'] );
			$settings['metadata']['use_cover']     = ! empty( $metadata['use_cover'] );
		}

		if ( isset( $incoming['video'] ) && is_array( $incoming['video'] ) ) {
			$video = $incoming['video'];

			$settings['video']['ratio']           = Attributes::sanitize_ratio( (string) ( $video['ratio'] ?? '16:9' ) );
			// Zero is a real answer here — "never hide them" — so it is clamped
			// rather than treated as unset.
			$settings['video']['hide_after']      = max( 0, min( 20000, (int) ( $video['hide_after'] ?? 2600 ) ) );
			$settings['video']['show_pip']        = ! empty( $video['show_pip'] );
			$settings['video']['show_fullscreen'] = ! empty( $video['show_fullscreen'] );
			$settings['video']['show_speed']      = ! empty( $video['show_speed'] );
			$settings['video']['big_play']        = ! empty( $video['big_play'] );
			$settings['video']['block_download']  = ! empty( $video['block_download'] );
			$settings['video']['poster_fit']      = 'contain' === ( $video['poster_fit'] ?? '' ) ? 'contain' : 'cover';
			$settings['video']['caption_size']    = in_array( $video['caption_size'] ?? '', array( 'small', 'medium', 'large', 'xlarge' ), true )
				? (string) $video['caption_size']
				: 'medium';
			$settings['video']['caption_bg']      = in_array( $video['caption_bg'] ?? '', array( 'solid', 'shadow', 'none' ), true )
				? (string) $video['caption_bg']
				: 'solid';
			$settings['video']['provider_privacy'] = ! empty( $video['provider_privacy'] );

			foreach ( array( 'show_captions', 'show_chapters', 'show_search', 'show_skip', 'show_time', 'show_volume', 'show_title', 'focus_mode', 'captions_on', 'provider_bare' ) as $flag ) {
				$settings['video'][ $flag ] = ! empty( $video[ $flag ] );
			}

			$settings['video']['chrome_color']    = Settings::sanitize_color( (string) ( $video['chrome_color'] ?? '' ), '#000000' );
			$settings['video']['caption_color']   = Settings::sanitize_color( (string) ( $video['caption_color'] ?? '' ), '#ffffff' );

			/*
			 * `auto` is a value in its own right for these two — the icons
			 * follow the bar, the played line follows the accent — so it has to
			 * survive the sanitiser rather than be turned into a colour here.
			 */
			foreach ( array( 'control_color', 'progress_color' ) as $key ) {
				$value = (string) ( $video[ $key ] ?? 'auto' );

				$settings['video'][ $key ] = 'auto' === $value
					? 'auto'
					: Settings::sanitize_color( $value, 'auto' );
			}
		}

		if ( isset( $incoming['protection'] ) && is_array( $incoming['protection'] ) ) {
			$protection = $incoming['protection'];

			$settings['protection']['enabled']       = ! empty( $protection['enabled'] );
			$settings['protection']['require_login'] = ! empty( $protection['require_login'] );
			$settings['protection']['bind_to_user']  = ! empty( $protection['bind_to_user'] );
			$settings['protection']['bind_to_ip']    = ! empty( $protection['bind_to_ip'] );
			$settings['protection']['ttl']           = max( HOUR_IN_SECONDS, min( WEEK_IN_SECONDS, (int) ( $protection['ttl'] ?? DAY_IN_SECONDS ) ) );
			$settings['protection']['delivery']      = in_array( $protection['delivery'] ?? 'php', array( 'php', 'xaccel', 'xsendfile' ), true )
				? (string) $protection['delivery']
				: 'php';
			$settings['protection']['xaccel_prefix'] = '/' . trim( sanitize_text_field( (string) ( $protection['xaccel_prefix'] ?? '' ) ), '/' ) . '/';

			if ( $settings['protection']['enabled'] ) {
				Vault::ensure();
			}
		}

		if ( isset( $incoming['advanced'] ) && is_array( $incoming['advanced'] ) ) {
			$settings['advanced']['load_frontend_css'] = ! empty( $incoming['advanced']['load_frontend_css'] );
			$settings['advanced']['lazy_init']         = ! empty( $incoming['advanced']['lazy_init'] );
			// Stripped of tags, not of CSS: this is a stylesheet, and an admin who
			// can reach this screen can already edit theme files.
			$settings['advanced']['custom_css']        = wp_strip_all_tags( (string) ( $incoming['advanced']['custom_css'] ?? '' ) );
		}

		if ( isset( $incoming['branding'] ) && is_array( $incoming['branding'] ) ) {
			$branding = $incoming['branding'];
			$current  = $settings['branding'];

			foreach ( array( 'accent', 'wave_color', 'text_color', 'meta_color', 'control_color' ) as $key ) {
				$settings['branding'][ $key ] = Settings::sanitize_color(
					(string) ( $branding[ $key ] ?? '' ),
					(string) $current[ $key ]
				);
			}

			$settings['branding']['logo']        = esc_url_raw( (string) ( $branding['logo'] ?? '' ), array( 'http', 'https' ) );
			$settings['branding']['logo_link']   = esc_url_raw( (string) ( $branding['logo_link'] ?? '' ), array( 'http', 'https' ) );
			$settings['branding']['logo_height'] = max( 8, min( 80, (int) ( $branding['logo_height'] ?? 20 ) ) );
		}

		Settings::update( $settings );

		return new WP_REST_Response( $this->payload(), 200 );
	}

	/**
	 * Render a player with a candidate preset, without saving anything.
	 */
	public function preview( WP_REST_Request $request ): WP_REST_Response {
		$attributes = $request->get_param( 'attributes' );

		// Two callers, two shapes. The block sends its own attributes and wants to
		// see exactly what it will publish; the settings screen sends a candidate
		// preset that is not saved anywhere yet.
		if ( is_array( $attributes ) ) {
			// A block previewing a real file gets that file's real waveform. It
			// used to get the demo one, which told the author their waveform was
			// working when it was not — they only found out on the front end.
			return $this->preview_response( $attributes, null, false );
		}

		if ( 'video' === $request->get_param( 'medium' ) ) {
			/*
			 * A file that does not exist, exactly like the audio demo: the
			 * player is built from the attributes and the poster, and nothing
			 * here ever plays. What matters is that it is the real renderer, so
			 * the preview cannot drift away from the thing it previews.
			 */
			$video = $request->get_param( 'video' );

			$override = null;

			if ( is_array( $video ) ) {
				$candidate = Settings::video();

				foreach ( $video as $key => $value ) {
					if ( array_key_exists( $key, $candidate ) ) {
						$candidate[ $key ] = $value;
					}
				}

				$override = static fn(): array => $candidate;
				add_filter( 'imagina_player_video_settings', $override, 99 );
			}

			$response = $this->preview_response(
				array(
					'src'         => home_url( '/imagina-player-preview.mp4' ),
					'title'       => (string) ( $request->get_param( 'title' ) ?: __( 'Your video title', 'imagina-player' ) ),
					'poster'      => \ImaginaPlayer\URL . 'assets/preview-poster.svg',
					'aspectRatio' => '16:9',
				),
				Settings::sanitize_preset( (array) $request->get_param( 'preset' ) )
			);

			if ( null !== $override ) {
				remove_filter( 'imagina_player_video_settings', $override, 99 );
			}

			return $response;
		}

		return $this->preview_response(
			array(
				'src'       => home_url( '/imagina-player-preview.mp3' ),
				'title'     => (string) ( $request->get_param( 'title' ) ?: __( 'Your track title', 'imagina-player' ) ),
				'artist'    => (string) ( $request->get_param( 'artist' ) ?: __( 'Artist name', 'imagina-player' ) ),
				'thumbnail' => \ImaginaPlayer\URL . 'assets/preview-cover.svg',
			),
			Settings::sanitize_preset( (array) $request->get_param( 'preset' ) )
		);
	}

	/**
	 * Render through the real renderer, optionally forcing a candidate preset.
	 *
	 * @param array<string, mixed>      $attributes Player attributes.
	 * @param array<string, mixed>|null $preset     Preset to force, or null to resolve normally.
	 */
	private function preview_response( array $attributes, ?array $preset, bool $demo = true ): WP_REST_Response {
		// A duration the player can lay a scrubber over. Without it the preview
		// shows `--:--` and the elapsed badge has nowhere to sit. Only for the
		// settings screen, whose "track" is a file that does not exist.
		$fake_duration = static function ( array $config ): array {
			if ( empty( $config['duration'] ) ) {
				$config['duration'] = 214.0;
			}

			return $config;
		};

		$override = null;

		if ( null !== $preset ) {
			$override = static fn(): array => $preset;
			add_filter( 'imagina_player_resolved_config', $override, 99 );
		}

		if ( $demo ) {
			add_filter( 'imagina_player_client_config', $fake_duration, 99 );
		}

		$renderer = new PlayerRenderer();
		$html     = $renderer->render( $attributes );

		if ( null !== $override ) {
			remove_filter( 'imagina_player_resolved_config', $override, 99 );
		}

		if ( $demo ) {
			remove_filter( 'imagina_player_client_config', $fake_duration, 99 );

			return new WP_REST_Response(
				array(
					'html'  => $html,
					'peaks' => self::demo_peaks(),
					'real'  => false,
				),
				200
			);
		}

		// The real thing, or nothing. An empty string is what the editor needs
		// in order to say "this track has no waveform yet" rather than draw one
		// that does not exist.
		$track  = Track::from_attributes( Attributes::sanitize( $attributes ) );
		$key    = $track->peaks_key();
		$record = '' === $key ? null : ( new PeaksRepository() )->get( $key );

		return new WP_REST_Response(
			array(
				'html'         => $html,
				'peaks'        => is_array( $record ) ? (string) $record['peaks'] : '',
				'real'         => true,
				'hasPeaks'     => is_array( $record ),
				'attachmentId' => $track->attachment_id,
				// What the block will show if its own fields are left empty, so
				// the editor can put it in the field as a placeholder rather
				// than showing a blank box beside a filled-in player.
				'resolved'     => array(
					'title'     => $track->title,
					'artist'    => $track->artist,
					'thumbnail' => $track->thumbnail,
				),
			),
			200
		);
	}

	/**
	 * A synthetic waveform for the preview, so it looks like audio without
	 * needing a real file to analyse.
	 */
	public static function demo_peaks(): string {
		$peaks = array();

		for ( $i = 0; $i < 400; $i++ ) {
			$peaks[] = min(
				1.0,
				max(
					0.12,
					0.55
					+ sin( $i * 0.35 ) * 0.25
					+ sin( $i * 1.7 ) * 0.2
					+ sin( $i * 0.11 ) * 0.3
				)
			);
		}

		return PeaksRepository::encode( $peaks );
	}

	/**
	 * Everything the interface needs in one response.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		$settings = Settings::all();
		$peaks    = $settings['peaks'];

		return array(
			'presets'    => $settings['presets'],
			'peaks'      => array(
				'resolution'        => (int) $peaks['resolution'],
				'server_generation' => (bool) $peaks['server_generation'],
				'client_fallback'   => (bool) $peaks['client_fallback'],
				'ffmpeg_path'       => (string) $peaks['ffmpeg_path'],
				'max_client_mb'     => (int) round( (int) $peaks['max_client_bytes'] / MB_IN_BYTES ),
			),
			'protection' => $settings['protection'],
			'advanced'   => $settings['advanced'],
			'branding'   => $settings['branding'],
			'schema'     => array(
				'presetDefaults' => Settings::preset_from_branding(),
				'skins'          => Skins::all(),
				'skinNotes'      => Skins::descriptions(),
				'defaultPreset'  => Settings::DEFAULT_PRESET,
			),
			'video'      => Settings::video(),
			'metadata'   => Settings::metadata(),
			'system'     => array(
				'ffmpeg'        => PeaksGenerator::is_available(),
				'ffmpegBinary'  => PeaksGenerator::binary(),
				'ffmpegState'   => PeaksGenerator::diagnosis()['state'],
				'vaultDir'      => Vault::base_dir(),
				'vaultName'     => Vault::directory_name(),
				'htaccess'      => Vault::server_honours_htaccess(),
				'version'       => \ImaginaPlayer\VERSION,
			),
		);
	}
}
