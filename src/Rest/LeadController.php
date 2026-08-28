<?php
/**
 * Where an address captured by a player is posted.
 *
 * Public, and deliberately without a nonce. A nonce would be the obvious
 * choice and it would be wrong here: this form sits inside a page that a
 * full-page cache serves to everyone for hours, so the nonce printed into it is
 * stale for all but the first visitor, and for a logged-out visitor it is the
 * same value for everybody anyway — which is to say, not a secret.
 *
 * What actually stands between this and abuse is cheaper and more honest: a
 * field no person can see, a limit on how often one address can post, and the
 * fact that the worst outcome is a row in a table the site owner can delete.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Rest;

use ImaginaPlayer\Leads\LeadRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LeadController {

	/** Submissions allowed from one address in the window below. */
	private const LIMIT = 5;

	private const WINDOW = 10 * MINUTE_IN_SECONDS;

	private LeadRepository $leads;

	public function __construct( ?LeadRepository $leads = null ) {
		$this->leads = $leads ?? new LeadRepository();
	}

	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Activation creates the table, but a site that *updates* never runs
		// activation again — so the version check runs on every load and does
		// nothing at all once the numbers match.
		add_action( 'plugins_loaded', array( LeadRepository::class, 'maybe_install' ) );
	}

	public function register_routes(): void {
		$can_manage = static fn(): bool => current_user_can( 'manage_options' );

		register_rest_route(
			PeaksController::REST_NAMESPACE,
			'/lead',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'capture' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'list'    => array( 'type' => 'string' ),
					'website' => array( 'type' => 'string' ),
					'source'  => array( 'type' => 'integer' ),
					'at'      => array( 'type' => 'number' ),
				),
			)
		);

		register_rest_route(
			PeaksController::REST_NAMESPACE,
			'/leads',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'listing' ),
					'permission_callback' => $can_manage,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove' ),
					'permission_callback' => $can_manage,
					'args'                => array(
						'id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			PeaksController::REST_NAMESPACE,
			'/leads/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => $can_manage,
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function capture( WP_REST_Request $request ) {
		// The honeypot. A person never sees this field, so anything that fills
		// it is a script. Answered with success rather than an error, because
		// telling a bot it was caught only teaches it what to change.
		if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$email = sanitize_email( (string) $request->get_param( 'email' ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error(
				'imagina_player_bad_email',
				__( 'That does not look like an email address.', 'imagina-player' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->within_limit( $email ) ) {
			return new WP_Error(
				'imagina_player_too_many',
				__( 'Too many attempts. Try again in a few minutes.', 'imagina-player' ),
				array( 'status' => 429 )
			);
		}

		$saved = $this->leads->save(
			array(
				'email'      => $email,
				'list'       => sanitize_text_field( (string) $request->get_param( 'list' ) ),
				'source_id'  => (int) $request->get_param( 'source' ),
				'source_url' => esc_url_raw( (string) $request->get_header( 'referer' ) ),
				'position'   => (float) $request->get_param( 'at' ),
			)
		);

		if ( ! $saved ) {
			return new WP_Error(
				'imagina_player_lead_failed',
				__( 'That could not be saved. Please try again.', 'imagina-player' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response( array( 'ok' => true ), 201 );
	}

	/**
	 * Keyed on the address rather than the IP.
	 *
	 * An IP is shared by everyone behind one office router or one mobile
	 * network, so limiting by IP would lock out a whole building because of one
	 * person. What is being limited here is repeat submissions of the same
	 * address, which is the shape the abuse actually takes.
	 */
	private function within_limit( string $email ): bool {
		$key   = 'imgp_lead_' . md5( strtolower( $email ) );
		$count = (int) get_transient( $key );

		if ( $count >= self::LIMIT ) {
			return false;
		}

		set_transient( $key, $count + 1, self::WINDOW );

		return true;
	}

	public function listing( WP_REST_Request $request ): WP_REST_Response {
		$page = max( 1, (int) $request->get_param( 'page' ) );
		$per  = max( 1, min( 200, (int) ( $request->get_param( 'perPage' ) ?: 50 ) ) );
		$list = sanitize_text_field( (string) $request->get_param( 'list' ) );

		$result = $this->leads->page( $per, ( $page - 1 ) * $per, $list );

		return new WP_REST_Response(
			array(
				'rows'  => $result['rows'],
				'total' => $result['total'],
				'lists' => $this->leads->lists(),
			),
			200
		);
	}

	public function remove( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			array( 'deleted' => $this->leads->delete( (int) $request->get_param( 'id' ) ) ),
			200
		);
	}

	/**
	 * A CSV, streamed rather than assembled: an export of a hundred thousand
	 * rows should not be a hundred thousand rows held in memory first.
	 */
	public function export( WP_REST_Request $request ): void {
		$list = sanitize_text_field( (string) $request->get_param( 'list' ) );
		$rows = $this->leads->export( $list );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="imagina-player-leads.csv"' );

		$out = fopen( 'php://output', 'w' );

		if ( false === $out ) {
			exit;
		}

		foreach ( $rows as $row ) {
			fputcsv( $out, $row, ',', '"', '\\' );
		}

		fclose( $out );
		exit;
	}
}
