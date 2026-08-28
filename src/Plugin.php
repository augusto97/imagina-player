<?php
/**
 * Plugin bootstrapper: owns the service list and the activation lifecycle.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer;

use ImaginaPlayer\Admin\Dashboard;
use ImaginaPlayer\Blocks\BlockRegistrar;
use ImaginaPlayer\Peaks\PeaksRepository;
use ImaginaPlayer\Protection\Integration as ProtectionIntegration;
use ImaginaPlayer\Protection\StreamServer;
use ImaginaPlayer\Rest\CaptionController;
use ImaginaPlayer\Rest\PeaksController;
use ImaginaPlayer\Rest\SettingsController;
use ImaginaPlayer\Rest\StreamController;
use ImaginaPlayer\Shortcodes\PlayerShortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static ?self $instance = null;

	/**
	 * Booted services, keyed by short name so integrations can reach them
	 * through the `imagina_player_service` filter.
	 *
	 * @var array<string, object>
	 */
	private array $services = array();

	private bool $booted = false;

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->services = array(
			'assets'      => new Assets(),
			'peaks'       => new PeaksRepository(),
			'rest'        => new PeaksController(),
			'captions'    => new CaptionController(),
			'stream'      => new StreamServer(),
			'streamRest'  => new StreamController(),
			'settingsApi' => new SettingsController(),
			'protection'  => new ProtectionIntegration(),
			'blocks'      => new BlockRegistrar(),
			'shortcodes'  => new PlayerShortcode(),
			'dashboard'   => new Dashboard(),
		);

		foreach ( $this->services as $service ) {
			if ( method_exists( $service, 'hooks' ) ) {
				$service->hooks();
			}
		}

		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ), 5 );

		/**
		 * Fires once every Imagina Player service has registered its hooks.
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'imagina_player_booted', $this );
	}

	/**
	 * Fetch a booted service.
	 */
	public function service( string $name ): ?object {
		return $this->services[ $name ] ?? null;
	}

	/**
	 * Catch up a site whose stored version is behind the code.
	 *
	 * The activation hook does not fire for a plugin copied into place, updated
	 * over FTP or deployed by git, which is exactly how this one reaches most of
	 * the sites that will run it.
	 */
	public function maybe_upgrade(): void {
		if ( get_option( 'imagina_player_version' ) === VERSION ) {
			return;
		}

		if ( false === get_option( Settings::OPTION_KEY ) ) {
			add_option( Settings::OPTION_KEY, Settings::defaults() );
		}

		update_option( 'imagina_player_version', VERSION, false );
	}

	public static function on_activation(): void {
		if ( false === get_option( Settings::OPTION_KEY ) ) {
			add_option( Settings::OPTION_KEY, Settings::defaults() );
		}

		update_option( 'imagina_player_version', VERSION, false );

		PeaksRepository::install_table();

		// Front-end assets are versioned by plugin version; flush page caches that key on rewrite rules.
		flush_rewrite_rules();
	}

	public static function on_deactivation(): void {
		flush_rewrite_rules();
	}
}
