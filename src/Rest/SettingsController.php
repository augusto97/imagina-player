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

use ImaginaPlayer\Peaks\PeaksGenerator;
use ImaginaPlayer\Peaks\PeaksRepository;
use ImaginaPlayer\Player\Skins;
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

		register_rest_route(
			PeaksController::REST_NAMESPACE,
			'/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => $can_manage,
				'args'                => array(
					'preset' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);
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
			$settings['peaks']['ffmpeg_path']       = sanitize_text_field( (string) ( $peaks['ffmpeg_path'] ?? '' ) );
			$settings['peaks']['max_client_bytes']  = max( 1, min( 200, (int) ( $peaks['max_client_mb'] ?? 25 ) ) ) * MB_IN_BYTES;
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

			foreach ( array( 'accent', 'wave_color', 'text_color', 'meta_color' ) as $key ) {
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
		$preset = Settings::sanitize_preset( (array) $request->get_param( 'preset' ) );

		// Feed the renderer the candidate preset by filtering the resolution step,
		// so the preview goes through exactly the same code path as the front end.
		$override = static fn(): array => $preset;

		// A duration the player can lay a scrubber over. Without it the preview
		// shows `--:--` and the elapsed badge has nowhere to sit.
		$fake_duration = static function ( array $config ): array {
			$config['duration'] = 214.0;

			return $config;
		};

		add_filter( 'imagina_player_resolved_config', $override, 99 );
		add_filter( 'imagina_player_client_config', $fake_duration, 99 );

		$renderer = new PlayerRenderer();

		// The preview needs a source or the renderer, correctly, refuses to draw a
		// player at all. Nothing ever loads from it: the waveform is supplied and
		// nobody presses play on a settings screen.
		$html = $renderer->render(
			array(
				'src'       => home_url( '/imagina-player-preview.mp3' ),
				'title'     => (string) ( $request->get_param( 'title' ) ?: __( 'Your track title', 'imagina-player' ) ),
				'artist'    => (string) ( $request->get_param( 'artist' ) ?: __( 'Artist name', 'imagina-player' ) ),
				'thumbnail' => (string) ( $request->get_param( 'thumbnail' ) ?: \ImaginaPlayer\URL . 'assets/preview-cover.svg' ),
			)
		);

		remove_filter( 'imagina_player_resolved_config', $override, 99 );
		remove_filter( 'imagina_player_client_config', $fake_duration, 99 );

		return new WP_REST_Response(
			array(
				'html'  => $html,
				'peaks' => self::demo_peaks(),
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
			'system'     => array(
				'ffmpeg'        => PeaksGenerator::is_available(),
				'ffmpegBinary'  => PeaksGenerator::binary(),
				'vaultDir'      => Vault::base_dir(),
				'vaultName'     => Vault::directory_name(),
				'htaccess'      => Vault::server_honours_htaccess(),
				'version'       => \ImaginaPlayer\VERSION,
			),
		);
	}
}
