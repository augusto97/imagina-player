<?php
/**
 * Playback statistics: where browsers report, and where the settings screen
 * reads.
 *
 * A report is public and carries no nonce, for the same reason a captured
 * address does: the page it comes from may have been served from a cache for
 * hours. What it carries instead is a token the page was drawn with, for one
 * player on one page, so a script cannot invent players or pages to count —
 * only add to the ones a site really shows, and no faster than a network's
 * worth of people would.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Stats;

use ImaginaPlayer\Media\Track;
use ImaginaPlayer\Protection\ProtectedMedia;
use ImaginaPlayer\Render\PlayerRenderer;
use ImaginaPlayer\Rest\PeaksController;
use ImaginaPlayer\Settings;
use ImaginaPlayer\Share\Clip;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Stats {

	public const PURGE_HOOK = 'imagina_player_purge_stats';

	/** Reports one network may send in ten minutes. */
	private const NETWORK_LIMIT = 300;

	/** Layers a report may name. */
	private const MAX_LAYERS = 64;

	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::PURGE_HOOK, array( self::class, 'purge' ) );

		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	public static function enabled(): bool {
		return ! empty( Settings::all()['stats']['enabled'] );
	}

	/**
	 * What a page tells the player so it may report: the media's name, the
	 * page's number, and the token that proves the two were drawn together.
	 *
	 * @return array{key: string, post: int, token: string}|null
	 */
	public static function client( string $key, int $post ): ?array {
		if ( ! self::enabled() || ! preg_match( '/^[a-f0-9]{12}$/', $key ) ) {
			return null;
		}

		// A player drawn for an editor — the block editor, the settings
		// screen, Elementor's or the Customizer's preview — is somebody
		// checking their work, not an audience.
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || self::previewing() ) {
			return null;
		}

		return array(
			'key'   => $key,
			'post'  => $post,
			'token' => self::token( $key, $post ),
		);
	}

	/** A page being edited, shown in a builder's or the Customizer's frame. */
	private static function previewing(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read, never acted on.
		if ( isset( $_GET['elementor-preview'] ) ) {
			return true;
		}

		return function_exists( 'is_customize_preview' ) && is_customize_preview();
	}

	public static function token( string $key, int $post ): string {
		return substr( hash_hmac( 'sha256', $key . '|' . $post, wp_salt( 'nonce' ) ), 0, 16 );
	}

	/** The page a player is drawn on, when there is one. */
	public static function current_post(): int {
		$id = (int) get_the_ID();

		return $id > 0 ? $id : (int) get_queried_object_id();
	}

	public function register_routes(): void {
		$can_manage = static fn(): bool => current_user_can( 'manage_options' );

		register_rest_route(
			PeaksController::REST_NAMESPACE,
			'/stats',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'report' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'summary' ),
					'permission_callback' => $can_manage,
					'args'                => array(
						'days' => array( 'type' => 'integer', 'default' => 30, 'minimum' => 1, 'maximum' => 400 ),
					),
				),
			)
		);

		register_rest_route(
			PeaksController::REST_NAMESPACE,
			'/stats/detail',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'detail' ),
				'permission_callback' => $can_manage,
				'args'                => array(
					'key'  => array( 'type' => 'string', 'required' => true, 'pattern' => '^[a-f0-9]{12}$' ),
					'post' => array( 'type' => 'integer', 'required' => true ),
					'days' => array( 'type' => 'integer', 'default' => 30, 'minimum' => 1, 'maximum' => 400 ),
				),
			)
		);

		register_rest_route(
			PeaksController::REST_NAMESPACE,
			'/stats/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => $can_manage,
				'args'                => array(
					'days' => array( 'type' => 'integer', 'default' => 30, 'minimum' => 1, 'maximum' => 400 ),
				),
			)
		);
	}

	/**
	 * A browser's report, checked down to what it can mean.
	 *
	 * Sent with `sendBeacon` as plain text, which is not a request a browser
	 * asks permission for first, so the body is read as it is rather than as
	 * WordPress reads JSON.
	 */
	public function report( WP_REST_Request $request ): WP_REST_Response {
		$data = json_decode( (string) $request->get_body(), true );
		$data = is_array( $data ) ? $data : (array) $request->get_json_params();
		$ok   = new WP_REST_Response( null, 204 );

		if ( ! self::enabled() ) {
			return $ok;
		}

		$report = self::clean( $data );

		if ( null === $report || ! hash_equals( self::token( $report['key'], $report['post'] ), (string) ( $data['token'] ?? '' ) ) ) {
			return new WP_REST_Response( null, 400 );
		}

		if ( ! self::within_limit() ) {
			return new WP_REST_Response( null, 429 );
		}

		StatsRepository::add( $report );

		return $ok;
	}

	/**
	 * What a report may say, and nothing else.
	 *
	 * @param array<string, mixed> $data
	 * @return array{key: string, post: int, play: int, seconds: float, complete: int, from: int, to: int, clicks: array<int, int>, title: string, duration: float}|null
	 */
	public static function clean( array $data ): ?array {
		$key = (string) ( $data['key'] ?? '' );

		if ( ! preg_match( '/^[a-f0-9]{12}$/', $key ) ) {
			return null;
		}

		$duration = max( 0.0, min( 86400.0, (float) ( $data['duration'] ?? 0 ) ) );
		$to       = (int) ( $data['to'] ?? -1 );
		$from     = (int) ( $data['from'] ?? -1 );
		$clicks   = array();

		foreach ( (array) ( $data['clicks'] ?? array() ) as $layer => $count ) {
			if ( is_numeric( $layer ) && (int) $layer >= 0 && (int) $layer < self::MAX_LAYERS ) {
				$clicks[ (int) $layer ] = max( 0, min( 20, (int) $count ) );
			}
		}

		return array(
			'key'      => $key,
			'post'     => max( 0, (int) ( $data['post'] ?? 0 ) ),
			'play'     => empty( $data['play'] ) ? 0 : 1,
			// Never more than the file could have played since the last
			// report, however the clock was wound.
			'seconds'  => max( 0.0, min( $duration > 0 ? $duration * 2 + 60 : 4 * HOUR_IN_SECONDS, (float) ( $data['seconds'] ?? 0 ) ) ),
			'complete' => empty( $data['complete'] ) ? 0 : 1,
			'from'     => max( -1, min( StatsRepository::BUCKETS - 1, $from ) ),
			'to'       => max( -1, min( StatsRepository::BUCKETS - 1, $to ) ),
			'clicks'   => array_filter( $clicks ),
			'title'    => mb_substr( sanitize_text_field( (string) ( $data['title'] ?? '' ) ), 0, 200 ),
			'duration' => $duration,
		);
	}

	/**
	 * Counted by network, as captured addresses are: an office or a campus
	 * shares one, so the limit is generous, and a script has to work for it.
	 */
	private static function within_limit(): bool {
		$network = ProtectedMedia::client_fingerprint();

		if ( '' === $network ) {
			return true;
		}

		$key   = 'imgp_stats_net_' . md5( $network );
		$count = (int) get_transient( $key );

		if ( $count >= (int) apply_filters( 'imagina_player_stats_network_limit', self::NETWORK_LIMIT ) ) {
			return false;
		}

		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );

		return true;
	}

	public function summary( WP_REST_Request $request ): WP_REST_Response {
		$days = max( 1, min( 400, (int) $request->get_param( 'days' ) ) );
		$rows = StatsRepository::summary( $days );

		foreach ( $rows as &$row ) {
			$row['page']    = $row['post'] > 0 ? (string) get_the_title( $row['post'] ) : '';
			$row['pageUrl'] = $row['post'] > 0 ? (string) get_permalink( $row['post'] ) : '';
		}

		unset( $row );

		return new WP_REST_Response(
			array(
				'enabled' => self::enabled(),
				'rows'    => $rows,
				'daily'   => StatsRepository::daily( $days ),
			),
			200
		);
	}

	public function detail( WP_REST_Request $request ): WP_REST_Response {
		$days = max( 1, min( 400, (int) $request->get_param( 'days' ) ) );
		$key  = (string) $request->get_param( 'key' );
		$post = (int) $request->get_param( 'post' );

		return new WP_REST_Response(
			StatsRepository::detail( $key, $post, $days ) + array( 'layers' => self::layers( $key, $post ) ),
			200
		);
	}

	/**
	 * What the layers a click was counted against are called, read from the
	 * page rather than from the report, so a name on the screen is one the
	 * author wrote.
	 *
	 * Blocks and the shortcode; a page built some other way gets numbers.
	 *
	 * @return array<int, array{type: string, label: string}>
	 */
	public static function layers( string $key, int $post ): array {
		$page = $post > 0 ? get_post( $post ) : null;

		if ( ! $page instanceof \WP_Post ) {
			return array();
		}

		foreach ( Clip::all( parse_blocks( (string) $page->post_content ) ) as $attributes ) {
			$atts = PlayerRenderer::resolve( $attributes, $post );

			if ( substr( md5( Track::from_attributes( $atts )->stable_key() ), 0, 12 ) !== $key ) {
				continue;
			}

			return array_map(
				static fn( array $layer ): array => array(
					'type'  => (string) $layer['type'],
					'label' => (string) ( '' !== (string) ( $layer['title'] ?? '' ) ? $layer['title'] : ( $layer['button'] ?? $layer['text'] ?? '' ) ),
				),
				array_values( (array) ( $atts['layers'] ?? array() ) )
			);
		}

		return array();
	}

	/**
	 * A CSV of the period, streamed.
	 */
	public function export( WP_REST_Request $request ): void {
		$days = max( 1, min( 400, (int) $request->get_param( 'days' ) ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="imagina-player-stats.csv"' );

		$out = fopen( 'php://output', 'w' );

		if ( false === $out ) {
			exit;
		}

		fputcsv( $out, array( 'title', 'page', 'url', 'plays', 'minutes watched', 'completed', 'average share watched', 'layer clicks' ), ',', '"', '\\' );

		foreach ( StatsRepository::summary( $days ) as $row ) {
			$average = $row['plays'] > 0 && $row['duration'] > 0 ? min( 1, $row['seconds'] / $row['plays'] / $row['duration'] ) : 0;

			fputcsv(
				$out,
				array(
					self::cell( $row['title'] ),
					self::cell( $row['post'] > 0 ? (string) get_the_title( $row['post'] ) : '' ),
					$row['post'] > 0 ? (string) get_permalink( $row['post'] ) : '',
					$row['plays'],
					round( $row['seconds'] / 60, 1 ),
					$row['complete'],
					round( $average * 100 ) . '%',
					$row['click'],
				),
				',',
				'"',
				'\\'
			);
		}

		fclose( $out );
		exit;
	}

	/**
	 * A title a spreadsheet will not run: `=`, `+`, `-` or `@` first is a
	 * formula to Excel.
	 */
	private static function cell( string $value ): string {
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	public static function purge(): void {
		StatsRepository::purge( max( 30, (int) ( Settings::all()['stats']['keep_days'] ?? 400 ) ) );
	}
}
