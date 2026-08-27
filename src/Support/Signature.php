<?php
/**
 * HMAC-signed, self-describing tokens.
 *
 * Used wherever the plugin has to hand a claim to the browser and trust it back:
 * waveform write grants and protected media URLs. Each caller passes its own
 * `$context`, so a token minted for one purpose can never validate for another.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Signature {

	/**
	 * Sign a set of claims.
	 *
	 * @param array<string, scalar> $claims  Claims to carry. `exp` is added here.
	 * @param int                   $ttl     Seconds the token stays valid.
	 * @param string                $context Purpose, e.g. `stream` or `peaks`.
	 * @param int|null              $issued  Issue time, for window alignment.
	 */
	public static function create( array $claims, int $ttl, string $context, ?int $issued = null ): string {
		$claims['exp'] = ( $issued ?? time() ) + max( 60, $ttl );

		$payload = self::encode( $claims );

		return $payload . '.' . self::sign( $payload, $context );
	}

	/**
	 * Verify a token and return its claims, or null if it is invalid or expired.
	 *
	 * @return array<string, scalar>|null
	 */
	public static function verify( string $token, string $context ): ?array {
		$parts = explode( '.', $token );

		if ( count( $parts ) !== 2 ) {
			return null;
		}

		[ $payload, $signature ] = $parts;

		if ( ! hash_equals( self::sign( $payload, $context ), $signature ) ) {
			return null;
		}

		$decoded = base64_decode( strtr( $payload, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- token payload.

		if ( ! is_string( $decoded ) ) {
			return null;
		}

		$claims = json_decode( $decoded, true );

		if ( ! is_array( $claims ) || ! isset( $claims['exp'] ) ) {
			return null;
		}

		if ( (int) $claims['exp'] < time() ) {
			return null;
		}

		return $claims;
	}

	/**
	 * Round a timestamp down to a fixed window.
	 *
	 * Signed URLs go into the page HTML, and a page cache will serve that HTML to
	 * everyone for as long as it holds it. Issuing from the start of a window
	 * means every visitor in that window gets the *same* URL, so the cached page
	 * stays coherent instead of carrying a token minted for one visitor.
	 */
	public static function window_start( int $window, ?int $now = null ): int {
		$window = max( 60, $window );
		$now    = $now ?? time();

		return intdiv( $now, $window ) * $window;
	}

	/**
	 * @param array<string, scalar> $claims Claims.
	 */
	private static function encode( array $claims ): string {
		$json = (string) wp_json_encode( $claims );

		return rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- token payload.
	}

	private static function sign( string $payload, string $context ): string {
		return hash_hmac( 'sha256', $context . '|' . $payload, wp_salt( 'imagina_player_' . $context ) );
	}
}
