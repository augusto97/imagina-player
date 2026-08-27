<?php
/**
 * Signed grants that let an anonymous visitor store the waveform their browser
 * computed.
 *
 * Waveform generation in the browser is the only option on hosts without
 * ffmpeg, and the result has to be written back so the next visitor does not
 * repeat the decode. Accepting an unauthenticated write needs a bound: a token
 * is minted server-side only for a track the site itself rendered, so the
 * endpoint can never be used to write peaks for arbitrary keys.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Peaks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PeaksToken {

	private const TTL = WEEK_IN_SECONDS;

	public static function create( string $key, int $resolution ): string {
		if ( '' === $key ) {
			return '';
		}

		$expires = time() + self::TTL;
		$payload = self::encode_payload( $key, $resolution, $expires );

		return $payload . '.' . self::sign( $payload );
	}

	/**
	 * Validate a token and return its payload.
	 *
	 * @return array{key: string, resolution: int}|null
	 */
	public static function verify( string $token ): ?array {
		$parts = explode( '.', $token );

		if ( count( $parts ) !== 2 ) {
			return null;
		}

		[ $payload, $signature ] = $parts;

		if ( ! hash_equals( self::sign( $payload ), $signature ) ) {
			return null;
		}

		$decoded = base64_decode( strtr( $payload, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- token payload.

		if ( ! is_string( $decoded ) ) {
			return null;
		}

		$fields = explode( '|', $decoded );

		if ( count( $fields ) !== 3 ) {
			return null;
		}

		[ $key, $resolution, $expires ] = $fields;

		if ( (int) $expires < time() ) {
			return null;
		}

		return array(
			'key'        => $key,
			'resolution' => (int) $resolution,
		);
	}

	private static function encode_payload( string $key, int $resolution, int $expires ): string {
		$raw = $key . '|' . $resolution . '|' . $expires;

		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- token payload.
	}

	private static function sign( string $payload ): string {
		return hash_hmac( 'sha256', $payload, wp_salt( 'imagina_player_peaks' ) );
	}
}
