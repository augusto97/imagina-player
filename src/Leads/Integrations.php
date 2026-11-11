<?php
/**
 * Where a captured address is sent besides this site's own table.
 *
 * Kept in an option of its own rather than with the rest of the settings: the
 * settings screen hands its whole option to the browser, and API keys have no
 * business there. The admin sees each key as its last four characters, and a
 * key is changed only by typing a new one.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Leads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Integrations {

	public const OPTION = 'imagina_player_integrations';

	/** What a key looks like on the admin's screen. */
	private const MASK = '••••';

	/** The fields that are secrets, by service. */
	private const SECRETS = array(
		'mailchimp'  => 'api_key',
		'brevo'      => 'api_key',
		'mailerlite' => 'api_key',
		'webhook'    => 'secret',
	);

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function defaults(): array {
		return array(
			'mailchimp'  => array(
				'enabled'       => false,
				'api_key'       => '',
				'list_id'       => '',
				// "Pending" until the person confirms from Mailchimp's own
				// email, which is what an audience in the EU usually needs.
				'double_optin'  => false,
				// The gate's list name as a Mailchimp tag, so each offer can
				// be told apart inside one audience.
				'tag_with_list' => true,
			),
			'brevo'      => array(
				'enabled'  => false,
				'api_key'  => '',
				'list_ids' => '',
			),
			'mailerlite' => array(
				'enabled'   => false,
				'api_key'   => '',
				'group_ids' => '',
			),
			'fluentcrm'  => array(
				'enabled'      => false,
				'list_ids'     => '',
				'tag_ids'      => '',
				'double_optin' => false,
			),
			'webhook'    => array(
				'enabled' => false,
				'url'     => '',
				'secret'  => '',
			),
		);
	}

	/**
	 * Everything, keys included: for sending, never for the browser.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();

		foreach ( self::defaults() as $service => $defaults ) {
			$out[ $service ] = array_merge( $defaults, array_intersect_key( (array) ( $stored[ $service ] ?? array() ), $defaults ) );
		}

		return $out;
	}

	/** Whether any service is switched on, so a capture knows to queue. */
	public static function any_enabled(): bool {
		foreach ( self::get() as $service ) {
			if ( ! empty( $service['enabled'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * As the admin's screen shows it: each secret as its last four
	 * characters, and whether there is one.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function for_admin(): array {
		$all = self::get();

		foreach ( self::SECRETS as $service => $field ) {
			$secret = (string) $all[ $service ][ $field ];

			$all[ $service ][ $field ]            = self::mask( $secret );
			$all[ $service ][ $field . '_saved' ] = '' !== $secret;
		}

		return $all;
	}

	public static function mask( string $secret ): string {
		if ( '' === $secret ) {
			return '';
		}

		return self::MASK . ( strlen( $secret ) >= 8 ? substr( $secret, -4 ) : '' );
	}

	/**
	 * Store what the admin sent.
	 *
	 * A secret that comes back as its own mask is kept as it was; an empty one
	 * is removed; anything else replaces it. Everything is reduced to what its
	 * field can hold — an address is an http or https address, a list of
	 * numbers is numbers.
	 *
	 * @param array<string, mixed> $incoming
	 * @return array<string, array<string, mixed>>
	 */
	public static function save( array $incoming ): array {
		$current = self::get();
		$next    = $current;

		foreach ( self::defaults() as $service => $defaults ) {
			$input = is_array( $incoming[ $service ] ?? null ) ? $incoming[ $service ] : array();

			foreach ( $defaults as $field => $default ) {
				if ( ! array_key_exists( $field, $input ) ) {
					continue;
				}

				$value = $input[ $field ];

				if ( ( self::SECRETS[ $service ] ?? '' ) === $field ) {
					$value = (string) $value;

					if ( str_starts_with( $value, self::MASK ) ) {
						continue;
					}

					$next[ $service ][ $field ] = self::secret( $value );
					continue;
				}

				$next[ $service ][ $field ] = match ( true ) {
					is_bool( $default )   => (bool) $value,
					'url' === $field      => self::url( (string) $value ),
					str_ends_with( $field, '_ids' ) => self::ids( (string) $value, 'mailerlite' === $service ),
					'list_id' === $field  => substr( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $value ), 0, 40 ),
					default               => sanitize_text_field( (string) $value ),
				};
			}
		}

		update_option( self::OPTION, $next, false );

		return $next;
	}

	/** An API key or a shared secret: printable, no spaces, not absurdly long. */
	private static function secret( string $value ): string {
		return substr( (string) preg_replace( '/[^\x21-\x7E]/', '', trim( $value ) ), 0, 200 );
	}

	/** An address a webhook may be sent to: http or https, and nothing else. */
	private static function url( string $value ): string {
		$value = trim( $value );

		if ( '' === $value || ! preg_match( '#^https?://#i', $value ) ) {
			return '';
		}

		return esc_url_raw( $value, array( 'http', 'https' ) );
	}

	/**
	 * `3, 7,12` as `3,7,12`. MailerLite's group numbers are longer than an
	 * integer on some hosts, so they are kept as digits rather than cast.
	 */
	public static function ids( string $value, bool $as_digits = false ): string {
		$ids = array();

		foreach ( preg_split( '/[\s,]+/', $value ) ?: array() as $part ) {
			if ( preg_match( '/^\d{1,30}$/', $part ) ) {
				$ids[] = $as_digits ? $part : (string) (int) $part;
			}
		}

		return implode( ',', array_slice( array_values( array_unique( $ids ) ), 0, 20 ) );
	}
}
