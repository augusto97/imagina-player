<?php
/**
 * Settings screen: presets, waveform generation and advanced options.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Admin;

use ImaginaPlayer\Peaks\PeaksGenerator;
use ImaginaPlayer\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SettingsPage {

	public const MENU_SLUG = 'imagina-player';

	public const ACTION = 'imagina_player_save_settings';

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( string $hook ): void {
		if ( 'settings_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'imagina-player-settings',
			\ImaginaPlayer\URL . 'assets/admin/settings.js',
			array(),
			\ImaginaPlayer\VERSION,
			true
		);
	}

	public function register_menu(): void {
		add_options_page(
			__( 'Imagina Player', 'imagina-player' ),
			__( 'Imagina Player', 'imagina-player' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Settings::all();
		$presets  = Settings::presets();
		$shape    = Settings::preset_defaults();
		?>
		<div class="wrap imgp-settings">
			<h1><?php esc_html_e( 'Imagina Player', 'imagina-player' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved.', 'imagina-player' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>

				<h2><?php esc_html_e( 'Presets', 'imagina-player' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'A preset is a reusable look for your players. Blocks pick a preset and may override individual settings.', 'imagina-player' ); ?>
				</p>

				<?php foreach ( $presets as $key => $preset ) : ?>
					<div class="imgp-preset card" style="max-width:none;padding:1rem 1.5rem;margin-bottom:1rem;">
						<h3>
							<?php echo esc_html( (string) $preset['label'] ); ?>
							<code><?php echo esc_html( $key ); ?></code>
						</h3>

						<table class="form-table" role="presentation">
							<?php foreach ( $shape as $field => $default ) : ?>
								<tr>
									<th scope="row">
										<label for="<?php echo esc_attr( "imgp-{$key}-{$field}" ); ?>">
											<?php echo esc_html( self::field_label( $field ) ); ?>
										</label>
									</th>
									<td><?php $this->render_field( $key, $field, $preset[ $field ] ?? $default, $default ); ?></td>
								</tr>
							<?php endforeach; ?>
						</table>

						<?php if ( Settings::DEFAULT_PRESET !== $key ) : ?>
							<p>
								<label>
									<input type="checkbox" name="delete_presets[]" value="<?php echo esc_attr( $key ); ?>" />
									<?php esc_html_e( 'Delete this preset when saving', 'imagina-player' ); ?>
								</label>
							</p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>

				<h3><?php esc_html_e( 'Add a preset', 'imagina-player' ); ?></h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="imgp-new-preset"><?php esc_html_e( 'Name', 'imagina-player' ); ?></label></th>
						<td>
							<input type="text" id="imgp-new-preset" name="new_preset_label" class="regular-text" placeholder="<?php esc_attr_e( 'Podcast', 'imagina-player' ); ?>" />
							<p class="description"><?php esc_html_e( 'The new preset starts as a copy of the default preset.', 'imagina-player' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Waveforms', 'imagina-player' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="imgp-resolution"><?php esc_html_e( 'Bars per waveform', 'imagina-player' ); ?></label></th>
						<td>
							<input type="number" id="imgp-resolution" name="peaks[resolution]" min="32" max="2000" step="1" value="<?php echo esc_attr( (string) $settings['peaks']['resolution'] ); ?>" />
							<p class="description"><?php esc_html_e( 'How many amplitude samples are stored per track. 400 suits players up to about 1200px wide.', 'imagina-player' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Server-side generation', 'imagina-player' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="peaks[server_generation]" value="1" <?php checked( ! empty( $settings['peaks']['server_generation'] ) ); ?> />
								<?php esc_html_e( 'Generate waveforms with ffmpeg when it is available', 'imagina-player' ); ?>
							</label>
							<p class="description">
								<?php
								echo PeaksGenerator::is_available()
									? esc_html__( 'ffmpeg was detected on this server.', 'imagina-player' )
									: esc_html__( 'ffmpeg was not detected. Waveforms will be computed in the visitor’s browser instead.', 'imagina-player' );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="imgp-ffmpeg"><?php esc_html_e( 'ffmpeg path', 'imagina-player' ); ?></label></th>
						<td>
							<input type="text" id="imgp-ffmpeg" name="peaks[ffmpeg_path]" class="regular-text code" value="<?php echo esc_attr( (string) $settings['peaks']['ffmpeg_path'] ); ?>" placeholder="/usr/bin/ffmpeg" />
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Browser fallback', 'imagina-player' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="peaks[client_fallback]" value="1" <?php checked( ! empty( $settings['peaks']['client_fallback'] ) ); ?> />
								<?php esc_html_e( 'Let the first visitor’s browser compute a missing waveform and store it', 'imagina-player' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Advanced', 'imagina-player' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Front-end stylesheet', 'imagina-player' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="advanced[load_frontend_css]" value="1" <?php checked( ! empty( $settings['advanced']['load_frontend_css'] ) ); ?> />
								<?php esc_html_e( 'Load the bundled stylesheet (turn off if your theme styles the player)', 'imagina-player' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Lazy initialisation', 'imagina-player' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="advanced[lazy_init]" value="1" <?php checked( ! empty( $settings['advanced']['lazy_init'] ) ); ?> />
								<?php esc_html_e( 'Only initialise a player when it scrolls near the viewport', 'imagina-player' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'imagina-player' ) );
		}

		check_admin_referer( self::ACTION );

		$settings = Settings::all();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$raw_presets = isset( $_POST['presets'] ) && is_array( $_POST['presets'] ) ? wp_unslash( $_POST['presets'] ) : array();
		$to_delete   = isset( $_POST['delete_presets'] ) && is_array( $_POST['delete_presets'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['delete_presets'] ) ) : array();
		$new_label   = isset( $_POST['new_preset_label'] ) ? sanitize_text_field( wp_unslash( $_POST['new_preset_label'] ) ) : '';
		$raw_peaks   = isset( $_POST['peaks'] ) && is_array( $_POST['peaks'] ) ? wp_unslash( $_POST['peaks'] ) : array();
		$raw_adv     = isset( $_POST['advanced'] ) && is_array( $_POST['advanced'] ) ? wp_unslash( $_POST['advanced'] ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$presets = array();

		foreach ( $raw_presets as $key => $values ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || in_array( $key, $to_delete, true ) ) {
				continue;
			}

			$presets[ $key ] = Settings::sanitize_preset( is_array( $values ) ? $values : array() );
		}

		if ( ! isset( $presets[ Settings::DEFAULT_PRESET ] ) ) {
			$presets[ Settings::DEFAULT_PRESET ] = Settings::preset_defaults();
		}

		if ( '' !== $new_label ) {
			$new_key = self::unique_preset_key( sanitize_key( $new_label ), $presets );

			$presets[ $new_key ]          = $presets[ Settings::DEFAULT_PRESET ];
			$presets[ $new_key ]['label'] = $new_label;
		}

		$settings['presets'] = $presets;

		$settings['peaks']['resolution']        = max( 32, min( 2000, (int) ( $raw_peaks['resolution'] ?? 400 ) ) );
		$settings['peaks']['server_generation'] = ! empty( $raw_peaks['server_generation'] );
		$settings['peaks']['client_fallback']   = ! empty( $raw_peaks['client_fallback'] );
		$settings['peaks']['ffmpeg_path']       = sanitize_text_field( (string) ( $raw_peaks['ffmpeg_path'] ?? '' ) );

		$settings['advanced']['load_frontend_css'] = ! empty( $raw_adv['load_frontend_css'] );
		$settings['advanced']['lazy_init']         = ! empty( $raw_adv['lazy_init'] );

		Settings::update( $settings );

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'options-general.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	/**
	 * @param array<string, array<string, mixed>> $presets Existing presets.
	 */
	private static function unique_preset_key( string $base, array $presets ): string {
		$base = '' !== $base ? $base : 'preset';
		$key  = $base;
		$i    = 2;

		while ( isset( $presets[ $key ] ) ) {
			$key = $base . '-' . $i;
			++$i;
		}

		return $key;
	}

	private function render_field( string $preset_key, string $field, mixed $value, mixed $default ): void {
		$name = sprintf( 'presets[%s][%s]', $preset_key, $field );
		$id   = "imgp-{$preset_key}-{$field}";

		if ( 'skin' === $field ) {
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';

			foreach ( Settings::skins() as $skin_key => $label ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $skin_key ),
					selected( $skin_key, (string) $value, false ),
					esc_html( $label )
				);
			}

			echo '</select>';

			return;
		}

		if ( 'preload' === $field ) {
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';

			foreach ( array( 'none', 'metadata', 'auto' ) as $option ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $option ),
					selected( $option, (string) $value, false ),
					esc_html( $option )
				);
			}

			echo '</select>';

			return;
		}

		if ( is_bool( $default ) ) {
			printf(
				'<input type="hidden" name="%1$s" value="0" /><label><input type="checkbox" id="%2$s" name="%1$s" value="1" %3$s /> %4$s</label>',
				esc_attr( $name ),
				esc_attr( $id ),
				checked( (bool) $value, true, false ),
				esc_html__( 'Enabled', 'imagina-player' )
			);

			return;
		}

		if ( in_array( $field, array( 'accent', 'wave_color', 'wave_progress', 'text_color', 'meta_color' ), true ) ) {
			printf(
				'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text code" /> <input type="color" class="imgp-swatch" data-target="%1$s" value="%4$s" aria-label="%5$s" />',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( (string) $value ),
				esc_attr( self::color_for_input( (string) $value ) ),
				esc_attr__( 'Pick a colour', 'imagina-player' )
			);

			return;
		}

		if ( is_int( $default ) || is_float( $default ) ) {
			printf(
				'<input type="number" id="%s" name="%s" value="%s" step="%s" class="small-text" />',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( (string) $value ),
				is_float( $default ) ? '0.01' : '1'
			);

			return;
		}

		printf(
			'<input type="text" id="%s" name="%s" value="%s" class="regular-text" />',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( (string) $value )
		);
	}

	private static function color_for_input( string $value ): string {
		return preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ? $value : '#000000';
	}

	private static function field_label( string $field ): string {
		$labels = array(
			'label'             => __( 'Preset name', 'imagina-player' ),
			'skin'              => __( 'Skin', 'imagina-player' ),
			'accent'            => __( 'Accent colour', 'imagina-player' ),
			'wave_color'        => __( 'Waveform colour', 'imagina-player' ),
			'wave_progress'     => __( 'Played colour', 'imagina-player' ),
			'wave_bars'         => __( 'Bar width (px)', 'imagina-player' ),
			'wave_gap'          => __( 'Bar gap (px)', 'imagina-player' ),
			'wave_reflection'   => __( 'Reflection height', 'imagina-player' ),
			'text_color'        => __( 'Title colour', 'imagina-player' ),
			'meta_color'        => __( 'Artist colour', 'imagina-player' ),
			'background'        => __( 'Background', 'imagina-player' ),
			'height'            => __( 'Waveform height (px)', 'imagina-player' ),
			'rounded_bars'      => __( 'Rounded bars', 'imagina-player' ),
			'show_artist'       => __( 'Show artist', 'imagina-player' ),
			'show_title'        => __( 'Show title', 'imagina-player' ),
			'show_thumbnail'    => __( 'Show thumbnail', 'imagina-player' ),
			'show_volume'       => __( 'Show volume', 'imagina-player' ),
			'show_time'         => __( 'Show times', 'imagina-player' ),
			'show_download'     => __( 'Show download', 'imagina-player' ),
			'show_speed'        => __( 'Show speed', 'imagina-player' ),
			'show_skip'         => __( 'Show skip buttons', 'imagina-player' ),
			'skip_seconds'      => __( 'Skip amount (s)', 'imagina-player' ),
			'sticky'            => __( 'Sticky footer player', 'imagina-player' ),
			'preload'           => __( 'Preload', 'imagina-player' ),
			'remember_position' => __( 'Remember position', 'imagina-player' ),
		);

		return $labels[ $field ] ?? ucwords( str_replace( '_', ' ', $field ) );
	}
}
