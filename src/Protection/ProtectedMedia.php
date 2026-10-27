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
	 * How long after it expires a link may still be traded for a new one.
	 *
	 * A page held in a full-page cache outlives the links inside it; a week
	 * or two covers every cache anyone keeps. Past that, the page is reloaded
	 * and the link in it is fresh anyway.
	 */
	public const REFRESH_GRACE = 14 * DAY_IN_SECONDS;

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
		// A link to a file of a post that has since been made private, or
		// unpublished, stops working with the post rather than with the link.
		if ( ! self::visible( $attachment_id ) ) {
			return 'not_visible';
		}

		if ( ! apply_filters( 'imagina_player_can_stream', true, $attachment_id ) ) {
			return 'denied_by_filter';
		}

		return true;
	}

	/**
	 * Whether protection is switched on at all.
	 */
	/**
	 * Whether an expired link may be traded for a fresh one.
	 *
	 * The old link is the proof: it can only have come from a page this site
	 * served with the player on it. Asking by attachment number alone — what
	 * the refresh used to need — handed a working link to anyone who counted
	 * upwards from one, for files attached to drafts and private posts too.
	 *
	 * @param int    $attachment_id The file.
	 * @param string $old_token     The token from the link that expired.
	 */
	public static function can_refresh( int $attachment_id, string $old_token ): bool {
		$claims = Signature::verify( $old_token, self::CONTEXT, self::REFRESH_GRACE );

		if ( null === $claims || (int) ( $claims['id'] ?? 0 ) !== $attachment_id ) {
			return false;
		}

		// A link minted for one person is not renewed for another.
		if ( isset( $claims['u'] ) && (int) $claims['u'] !== get_current_user_id() ) {
			return false;
		}

		return self::visible( $attachment_id );
	}

	/**
	 * Whether this visitor may see the post the file belongs to.
	 *
	 * The rule core applies to media: a file attached to a published post is
	 * public; one attached to a draft or a private post is not, unless the
	 * visitor may read that post. A file attached to nothing is the library's.
	 */
	public static function visible( int $attachment_id ): bool {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}

		$parent = (int) get_post_field( 'post_parent', $attachment_id );

		if ( $parent <= 0 ) {
			return true;
		}

		return 'publish' === get_post_status( $parent ) || current_user_can( 'read_post', $parent );
	}

	/**
	 * The visitor's address, as this site sees it.
	 *
	 * `REMOTE_ADDR`, unless the site says otherwise: behind a CDN or a proxy
	 * that is the proxy's address, shared by every visitor, so a site there
	 * can hand the real one over with the `imagina_player_client_ip` filter
	 * (reading whichever header its proxy is trusted to set).
	 */
	public static function client_ip(): string {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated below, never output.
		$ip = (string) apply_filters( 'imagina_player_client_ip', $ip );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

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
		$ip = self::client_ip();

		if ( '' === $ip ) {
			return '';
		}

		if ( str_contains( $ip, ':' ) ) {
			/*
			 * A /48, the block a single subscriber is usually given. It was a
			 * /64, and one household with a /56 has 256 of those — so one
			 * person counted as hundreds of networks against every limit.
			 * Expanded first, so `2001:db8::1` and `2001:0db8:0:0::1` agree.
			 */
			$packed = inet_pton( $ip );
			$hex    = false === $packed ? '' : bin2hex( $packed );
			$ip     = implode( ':', str_split( substr( $hex, 0, 12 ), 4 ) );
		} else {
			$parts = explode( '.', $ip );
			array_pop( $parts );
			$ip = implode( '.', $parts );
		}

		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'imagina_player_stream' ) ), 0, 16 );
	}
}
