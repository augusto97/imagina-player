<?php
/**
 * Sending a captured address on: Mailchimp, Brevo, MailerLite, FluentCRM, or
 * any address that takes a POST — Zapier, Make, n8n.
 *
 * Never while the visitor waits. The address is in this site's own table
 * before anything here runs, and the sending happens on the next cron run, so
 * a service that is slow or down costs the visitor nothing and loses nobody's
 * address: what fails is tried again later, three times, and the last result
 * of each service is kept for the settings screen to show.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Leads;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delivery {

	public const HOOK = 'imagina_player_deliver_lead';

	public const LOG = 'imagina_player_integration_log';

	public const SERVICES = array( 'mailchimp', 'brevo', 'mailerlite', 'fluentcrm', 'webhook' );

	/** Seconds before each new try of what failed. */
	private const RETRY_AFTER = array( 5 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS );

	private const TIMEOUT = 10;

	public function hooks(): void {
		add_action( self::HOOK, array( self::class, 'deliver' ), 10, 3 );
	}

	/**
	 * Put an address in line for every service that is switched on.
	 *
	 * @param array<string, mixed> $lead email, list, source_id, source_url, position.
	 */
	public static function queue( array $lead ): void {
		$services = self::enabled();

		if ( array() === $services ) {
			return;
		}

		$lead['captured_at'] = gmdate( 'c' );

		wp_schedule_single_event( time(), self::HOOK, array( $lead, $services, 1 ) );
	}

	/** @return array<int, string> */
	private static function enabled(): array {
		$all = Integrations::get();

		return array_values( array_filter( self::SERVICES, static fn( string $service ): bool => ! empty( $all[ $service ]['enabled'] ) ) );
	}

	/**
	 * Send one address to the services named, and line up another try for
	 * those that failed.
	 *
	 * @param array<string, mixed> $lead
	 * @param array<int, string>   $services
	 * @return array<string, array{ok: bool, message: string}>
	 */
	public static function deliver( array $lead, array $services, int $attempt = 1 ): array {
		$all     = Integrations::get();
		$results = array();
		$failed  = array();

		foreach ( array_intersect( $services, self::SERVICES ) as $service ) {
			if ( empty( $all[ $service ]['enabled'] ) ) {
				continue;
			}

			$result              = self::send( $service, $all[ $service ], $lead );
			$results[ $service ] = $result;

			self::log( $service, $result, (string) ( $lead['email'] ?? '' ), $attempt );

			if ( ! $result['ok'] ) {
				$failed[] = $service;
			}
		}

		if ( array() !== $failed && $attempt <= count( self::RETRY_AFTER ) ) {
			wp_schedule_single_event( time() + self::RETRY_AFTER[ $attempt - 1 ], self::HOOK, array( $lead, $failed, $attempt + 1 ) );
		}

		return $results;
	}

	/**
	 * @param array<string, mixed> $config
	 * @param array<string, mixed> $lead
	 * @return array{ok: bool, message: string}
	 */
	public static function send( string $service, array $config, array $lead ): array {
		$email = sanitize_email( (string) ( $lead['email'] ?? '' ) );

		if ( '' === $email ) {
			return self::result( false, 'No address to send.' );
		}

		return match ( $service ) {
			'mailchimp'  => self::mailchimp( $config, $email, (string) ( $lead['list'] ?? '' ) ),
			'brevo'      => self::brevo( $config, $email ),
			'mailerlite' => self::mailerlite( $config, $email ),
			'fluentcrm'  => self::fluentcrm( $config, $email ),
			'webhook'    => self::webhook( $config, $lead + array( 'email' => $email ) ),
			default      => self::result( false, 'Unknown service.' ),
		};
	}

	/**
	 * Whether a service's settings work, without sending anybody's address
	 * — except the webhook, which is sent a test event it can tell apart.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public static function test( string $service ): array {
		$config = Integrations::get()[ $service ] ?? null;

		if ( null === $config ) {
			return self::result( false, __( 'There is no such service.', 'imagina-player' ) );
		}

		switch ( $service ) {
			case 'mailchimp':
				$base = self::mailchimp_base( (string) $config['api_key'] );

				if ( '' === $base || '' === (string) $config['list_id'] ) {
					return self::result( false, __( 'Enter an API key and an audience ID first.', 'imagina-player' ) );
				}

				$response = self::request( 'mailchimp', 'GET', $base . '/lists/' . rawurlencode( (string) $config['list_id'] ) . '?fields=name', self::mailchimp_auth( (string) $config['api_key'] ) );

				if ( $response['ok'] ) {
					/* translators: %s: the name of a Mailchimp audience. */
					return self::result( true, sprintf( __( 'Connected to the audience “%s”.', 'imagina-player' ), (string) ( $response['data']['name'] ?? '' ) ) );
				}

				return $response;

			case 'brevo':
				if ( '' === (string) $config['api_key'] ) {
					return self::result( false, __( 'Enter an API key first.', 'imagina-player' ) );
				}

				$response = self::request( 'brevo', 'GET', 'https://api.brevo.com/v3/account', array( 'api-key' => (string) $config['api_key'] ) );

				/* translators: %s: the account's email address or company name. */
				return $response['ok'] ? self::result( true, sprintf( __( 'Connected to the account %s.', 'imagina-player' ), (string) ( $response['data']['email'] ?? $response['data']['companyName'] ?? '' ) ) ) : $response;

			case 'mailerlite':
				if ( '' === (string) $config['api_key'] ) {
					return self::result( false, __( 'Enter an API key first.', 'imagina-player' ) );
				}

				$response = self::request( 'mailerlite', 'GET', 'https://connect.mailerlite.com/api/groups?limit=1', array( 'Authorization' => 'Bearer ' . $config['api_key'] ) );

				return $response['ok'] ? self::result( true, __( 'Connected.', 'imagina-player' ) ) : $response;

			case 'fluentcrm':
				return self::fluentcrm_available()
					? self::result( true, __( 'FluentCRM is active on this site.', 'imagina-player' ) )
					: self::result( false, __( 'FluentCRM is not active on this site.', 'imagina-player' ) );

			case 'webhook':
				return self::webhook(
					$config,
					array(
						'event'       => 'test',
						'email'       => 'test@example.com',
						'list'        => '',
						'captured_at' => gmdate( 'c' ),
					)
				);
		}

		return self::result( false, __( 'There is no such service.', 'imagina-player' ) );
	}

	/**
	 * Added or updated in one call, by the address's own hash: someone who
	 * signs up twice is one member, not an error.
	 *
	 * @param array<string, mixed> $config
	 * @return array{ok: bool, message: string}
	 */
	private static function mailchimp( array $config, string $email, string $list ): array {
		$base = self::mailchimp_base( (string) $config['api_key'] );

		if ( '' === $base || '' === (string) $config['list_id'] ) {
			return self::result( false, 'The API key or the audience ID is missing.' );
		}

		$member = $base . '/lists/' . rawurlencode( (string) $config['list_id'] ) . '/members/' . md5( strtolower( $email ) );
		$auth   = self::mailchimp_auth( (string) $config['api_key'] );
		$put    = self::request(
			'mailchimp',
			'PUT',
			$member,
			$auth,
			array(
				'email_address' => $email,
				'status_if_new' => ! empty( $config['double_optin'] ) ? 'pending' : 'subscribed',
			)
		);

		if ( ! $put['ok'] || empty( $config['tag_with_list'] ) || '' === $list ) {
			return self::result( $put['ok'], $put['message'] );
		}

		$tag = self::request( 'mailchimp', 'POST', $member . '/tags', $auth, array( 'tags' => array( array( 'name' => $list, 'status' => 'active' ) ) ) );

		return self::result( $tag['ok'], $tag['message'] );
	}

	/** `abc123-us21` is at `https://us21.api.mailchimp.com/3.0`. */
	public static function mailchimp_base( string $key ): string {
		if ( ! preg_match( '/-([a-z]{2,4}\d{1,3})$/', $key, $m ) ) {
			return '';
		}

		return 'https://' . $m[1] . '.api.mailchimp.com/3.0';
	}

	/** @return array<string, string> */
	private static function mailchimp_auth( string $key ): array {
		return array( 'Authorization' => 'Basic ' . base64_encode( 'imagina-player:' . $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- HTTP basic authentication.
	}

	/**
	 * @param array<string, mixed> $config
	 * @return array{ok: bool, message: string}
	 */
	private static function brevo( array $config, string $email ): array {
		if ( '' === (string) $config['api_key'] ) {
			return self::result( false, 'The API key is missing.' );
		}

		$lists = array_map( 'intval', array_filter( explode( ',', (string) $config['list_ids'] ) ) );
		$body  = array(
			'email'         => $email,
			'updateEnabled' => true,
		);

		if ( array() !== $lists ) {
			$body['listIds'] = $lists;
		}

		$response = self::request( 'brevo', 'POST', 'https://api.brevo.com/v3/contacts', array( 'api-key' => (string) $config['api_key'] ), $body );

		return self::result( $response['ok'], $response['message'] );
	}

	/**
	 * @param array<string, mixed> $config
	 * @return array{ok: bool, message: string}
	 */
	private static function mailerlite( array $config, string $email ): array {
		if ( '' === (string) $config['api_key'] ) {
			return self::result( false, 'The API key is missing.' );
		}

		$groups = array_values( array_filter( explode( ',', (string) $config['group_ids'] ) ) );
		$body   = array( 'email' => $email );

		if ( array() !== $groups ) {
			$body['groups'] = $groups;
		}

		$response = self::request( 'mailerlite', 'POST', 'https://connect.mailerlite.com/api/subscribers', array( 'Authorization' => 'Bearer ' . $config['api_key'] ), $body );

		return self::result( $response['ok'], $response['message'] );
	}

	public static function fluentcrm_available(): bool {
		return function_exists( 'FluentCrmApi' );
	}

	/**
	 * FluentCRM lives on this site, so this is a call rather than a request.
	 *
	 * @param array<string, mixed> $config
	 * @return array{ok: bool, message: string}
	 */
	private static function fluentcrm( array $config, string $email ): array {
		if ( ! self::fluentcrm_available() ) {
			return self::result( false, 'FluentCRM is not active.' );
		}

		$double = ! empty( $config['double_optin'] );

		try {
			$contact = \FluentCrmApi( 'contacts' )->createOrUpdate(
				array(
					'email'  => $email,
					'status' => $double ? 'pending' : 'subscribed',
					'lists'  => array_map( 'intval', array_filter( explode( ',', (string) $config['list_ids'] ) ) ),
					'tags'   => array_map( 'intval', array_filter( explode( ',', (string) $config['tag_ids'] ) ) ),
				)
			);
		} catch ( \Throwable $error ) {
			return self::result( false, $error->getMessage() );
		}

		if ( ! $contact ) {
			return self::result( false, 'FluentCRM did not save the contact.' );
		}

		if ( $double && 'pending' === ( $contact->status ?? '' ) && method_exists( $contact, 'sendDoubleOptinEmail' ) ) {
			$contact->sendDoubleOptinEmail();
		}

		return self::result( true, 'Saved.' );
	}

	/**
	 * A POST of JSON, signed with the shared secret when there is one: the
	 * receiver recomputes `sha256=` + HMAC of the body and compares.
	 *
	 * @param array<string, mixed> $config
	 * @param array<string, mixed> $lead
	 * @return array{ok: bool, message: string}
	 */
	private static function webhook( array $config, array $lead ): array {
		$url = (string) $config['url'];

		if ( '' === $url ) {
			return self::result( false, __( 'Enter the address to send to first.', 'imagina-player' ) );
		}

		$body = (string) wp_json_encode(
			array(
				'event'       => (string) ( $lead['event'] ?? 'lead.captured' ),
				'email'       => (string) ( $lead['email'] ?? '' ),
				'list'        => (string) ( $lead['list'] ?? '' ),
				'source_id'   => (int) ( $lead['source_id'] ?? 0 ),
				'source_url'  => (string) ( $lead['source_url'] ?? '' ),
				'position'    => (float) ( $lead['position'] ?? 0 ),
				'captured_at' => (string) ( $lead['captured_at'] ?? gmdate( 'c' ) ),
				'site'        => home_url( '/' ),
			)
		);

		$headers = array( 'X-Imagina-Event' => (string) ( $lead['event'] ?? 'lead.captured' ) );

		if ( '' !== (string) $config['secret'] ) {
			$headers['X-Imagina-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, (string) $config['secret'] );
		}

		$response = self::request( 'webhook', 'POST', $url, $headers, $body );

		return self::result( $response['ok'], $response['ok'] ? __( 'Sent.', 'imagina-player' ) : $response['message'] );
	}

	/**
	 * One request. Never to a private address: the webhook's is the admin's
	 * to choose, and "an address inside the server's own network" is not one
	 * a settings field should be able to reach.
	 *
	 * @param array<string, string>     $headers
	 * @param array<string, mixed>|string|null $body
	 * @return array{ok: bool, message: string, status: int, data: array<string, mixed>}
	 */
	private static function request( string $service, string $method, string $url, array $headers, $body = null ): array {
		/**
		 * Filter the address a service is reached at — for a proxy, or a
		 * service's regional host.
		 *
		 * @param string $url     The address.
		 * @param string $service mailchimp, brevo, mailerlite or webhook.
		 */
		$url  = (string) apply_filters( 'imagina_player_integration_url', $url, $service );
		$args = array(
			'method'     => $method,
			'timeout'    => self::TIMEOUT,
			'user-agent' => 'Imagina Player/' . \ImaginaPlayer\VERSION . '; ' . home_url( '/' ),
			'headers'    => $headers + array(
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = is_string( $body ) ? $body : (string) wp_json_encode( $body );
		}

		$response = wp_safe_remote_request( $url, $args );

		if ( $response instanceof WP_Error ) {
			return array(
				'ok'      => false,
				'message' => $response->get_error_message(),
				'status'  => 0,
				'data'    => array(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data   = is_array( $data ) ? $data : array();
		$ok     = $status >= 200 && $status < 300;

		// The service's own words when it has some: "Invalid API key",
		// "Member in compliance state", "Contact already in list".
		$message = $ok ? 'OK' : trim( (string) ( $data['detail'] ?? $data['message'] ?? $data['title'] ?? '' ) );

		if ( ! $ok && '' === $message ) {
			/* translators: %d: an HTTP status code. */
			$message = sprintf( __( 'The service answered with status %d.', 'imagina-player' ), $status );
		}

		return array(
			'ok'      => $ok,
			'message' => $message,
			'status'  => $status,
			'data'    => $data,
		);
	}

	/** @return array{ok: bool, message: string} */
	private static function result( bool $ok, string $message ): array {
		return array(
			'ok'      => $ok,
			'message' => $message,
		);
	}

	/**
	 * The last result of each service, for the settings screen, with the
	 * address shortened: the log is about whether sending works, not a second
	 * copy of the list.
	 *
	 * @param array{ok: bool, message: string} $result
	 */
	private static function log( string $service, array $result, string $email, int $attempt ): void {
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();

		$at = strpos( $email, '@' );

		$log[ $service ] = array(
			'ok'      => $result['ok'],
			'message' => substr( $result['message'], 0, 300 ),
			'email'   => false === $at ? '' : substr( $email, 0, 1 ) . '…' . substr( $email, $at ),
			'attempt' => $attempt,
			'time'    => time(),
		);

		update_option( self::LOG, $log, false );
	}
}
