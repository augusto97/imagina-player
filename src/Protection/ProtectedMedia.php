<?php
/**
 * Policy for protected media: who may stream what, and how a URL is signed.
 *
 * The token says *which* file is being asked for; it is not the authorisation.
 * Login, user binding and any membership rule are evaluated fresh on every
 * request, so a signed URL that leaks still runs into the same checks the
 * original visitor did.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Protection;

use ImaginaPlayer\Settings;
use ImaginaPlayer\Support\Signature;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProtectedMedia {

	public const CONTEXT = 'stream';

	public const QUERY_VAR = 'imagina_media';

	public const TOKEN_VAR = 'imgpt';

	/**
	 * Build a signed streaming URL for an attachment.
	 *
	 * Tokens are issued from the start of a fixed window rather than "now", so
	 * every visitor served the same cached page gets the same URL.
	 */
	public static function signed_url( int $attachment_id ): string {
		if ( $attachment_id <= 0 ) {
			return '';
		}

		$settings = Settings::protection();
		$ttl      = (int) $settings['ttl'];

		$claims = array( 'id' => $attachment_id );

		if ( ! empty( $settings['bind_to_user'] ) ) {
			$claims['u'] = get_current_user_id();
		}

		if ( ! empty( $settings['bind_to_ip'] ) ) {
			$claims['ip'] = self::client_fingerprint();
		}

		// A user- or IP-bound token is unique per visitor anyway, so aligning it
		// to a window would only shorten its life for no benefit.
		$issued = ( isset( $claims['u'] ) || isset( $claims['ip'] ) )
			? null
			: Signature::window_start( $ttl );

		$token = Signature::create( $claims, $ttl, self::CONTEXT, $issued );

		return add_query_arg(
			array(
				self::QUERY_VAR => $attachment_id,
				self::TOKEN_VAR => $token,
			),
			home_url( '/' )
		);
	}

	/**
	 * Decide whether the current request may stream this attachment.
	 *
	 * @return true|string True, or a machine-readable denial reason.
	 */
	public static function authorize( int $attachment_id, string $token ) {
		$claims = Signature::verify( $token, self::CONTEXT );

		if ( null === $claims ) {
			return 'invalid_token';
		}

		if ( (int) ( $claims['id'] ?? 0 ) !== $attachment_id ) {
			return 'token_mismatch';
		}

		if ( isset( $claims['u'] ) && (int) $claims['u'] !== get_current_user_id() ) {
			return 'wrong_user';
		}

		if ( isset( $claims['ip'] ) && ! hash_equals( (string) $claims['ip'], self::client_fingerprint() ) ) {
			return 'wrong_network';
		}

		$settings = Settings::protection();

		if ( ! empty( $settings['require_login'] ) && ! is_user_logged_in() ) {
			return 'login_required';
		}

		/**
		 * Final say on whether the current visitor may stream this file.
		 *
		 * This is the hook for membership, LMS and e-commerce plugins: return
		 * false to deny regardless of the token.
		 *
		 * @param bool $allowed       Whether streaming is allowed.
		 * @param int  $attachment_id Attachment being requested.
		 */
		if ( ! apply_filters( 'imagina_player_can_stream', true, $attachment_id ) ) {
			return 'denied_by_filter';
		}

		return true;
	}

	/**
	 * Whether protection is switched on at all.
	 */
	public static function is_enabled(): bool {
		$settings = Settings::protection();

		return ! empty( $settings['enabled'] );
	}

	/**
	 * A coarse network fingerprint. Deliberately coarse: pinning to the exact
	 * address breaks playback for anyone whose phone changes cell or whose ISP
	 * rotates addresses mid-track, so the last octet of IPv4 and the interface
	 * half of IPv6 are dropped.
	 */
	public static function client_fingerprint(): string {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed, never output.
		$ip = filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';

		if ( '' === $ip ) {
			return '';
		}

		if ( str_contains( $ip, ':' ) ) {
			$parts = array_slice( explode( ':', $ip ), 0, 4 );
			$ip    = implode( ':', $parts );
		} else {
			$parts = explode( '.', $ip );
			array_pop( $parts );
			$ip = implode( '.', $parts );
		}

		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'imagina_player_stream' ) ), 0, 16 );
	}
}
