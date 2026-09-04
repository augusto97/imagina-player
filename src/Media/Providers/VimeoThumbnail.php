<?php
/**
 * Vimeo's poster image, which cannot be guessed.
 *
 * YouTube's thumbnail lives at a predictable address, so it costs nothing to
 * point at. Vimeo's is a CDN path nobody can construct, so it has to be asked
 * for — and asking on every page view would put a third-party request in front
 * of every visitor, which is the opposite of the point.
 *
 * So it is asked once and remembered. A failure is remembered too, with the
 * reason, because "no picture" on its own sent somebody looking for a bug in
 * the wrong place: from the editor, a video Vimeo refuses to describe and a
 * host that cannot reach Vimeo at all looked exactly the same, and neither
 * looked any different from the plugin not trying.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Media\Providers;

use ImaginaPlayer\Media\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VimeoThumbnail {

	private const PREFIX = 'imgp_vimeo_thumb_';

	/** A month. The picture on a published video effectively never changes. */
	private const TTL = MONTH_IN_SECONDS;

	/**
	 * Vimeo answered and said no. An hour: the owner may be making the video
	 * public right now, and the editor offers a way to ask sooner.
	 */
	private const TTL_REFUSED = HOUR_IN_SECONDS;

	/**
	 * Vimeo could not be reached, or was overloaded. Five minutes — long
	 * enough not to hammer a provider that is down, short enough that a
	 * timeout during one preview does not cost the author the next hour.
	 * It used to be the same hour as a refusal, which is how a single slow
	 * answer turned into "the plugin does not do Vimeo thumbnails".
	 */
	private const TTL_UNREACHABLE = 300;

	/** Hosts Vimeo actually serves pictures from. */
	private const PICTURE_HOSTS = array( 'i.vimeocdn.com', 'vimeocdn.com', 'i.vimeocdn.net' );

	public static function get( Provider $provider ): string {
		return self::status( $provider )['url'];
	}

	/**
	 * The picture, or why there is none.
	 *
	 * @return array{url: string, why: string} `why` is a sentence for the
	 *                                          editor and is empty when there
	 *                                          is a picture.
	 */
	public static function status( Provider $provider ): array {
		if ( 'vimeo' !== $provider->name || '' === $provider->id ) {
			return array( 'url' => '', 'why' => '' );
		}

		$key    = self::key( $provider );
		$cached = get_transient( $key );

		if ( is_array( $cached ) && isset( $cached['url'], $cached['code'], $cached['detail'] ) ) {
			return array(
				'url' => (string) $cached['url'],
				'why' => self::explain( (string) $cached['code'], (string) $cached['detail'] ),
			);
		}

		/*
		 * Rows written by earlier versions were a bare string. A picture is a
		 * picture; a remembered miss carried no reason and is asked again,
		 * which is also what un-sticks a site that cached one before this
		 * version and would otherwise show nothing for the rest of the hour.
		 */
		if ( is_string( $cached ) && '' !== $cached ) {
			return array( 'url' => $cached, 'why' => '' );
		}

		$answer = self::fetch( $provider );

		if ( '' !== $answer['url'] ) {
			$ttl = self::TTL;
		} else {
			$ttl = $answer['soon'] ? self::TTL_UNREACHABLE : self::TTL_REFUSED;
		}

		set_transient(
			$key,
			array(
				'url'    => $answer['url'],
				'code'   => $answer['code'],
				'detail' => $answer['detail'],
			),
			$ttl
		);

		return array(
			'url' => $answer['url'],
			'why' => self::explain( $answer['code'], $answer['detail'] ),
		);
	}

	/**
	 * Drop what is remembered, so the next ask goes to Vimeo.
	 *
	 * For the editor's "ask again": an author who has just made a video
	 * public should not be told for the next hour that it is private.
	 */
	public static function forget( Provider $provider ): void {
		if ( 'vimeo' !== $provider->name || '' === $provider->id ) {
			return;
		}

		delete_transient( self::key( $provider ) );
	}

	private static function key( Provider $provider ): string {
		return self::PREFIX . md5( $provider->id . '|' . $provider->hash );
	}

	/**
	 * Ask Vimeo, once.
	 *
	 * @return array{url: string, code: string, detail: string, soon: bool}
	 *         `code` names what happened; `detail` is whatever the far end or
	 *         the HTTP client said, verbatim; `soon` is whether it is worth
	 *         asking again in minutes rather than an hour.
	 */
	private static function fetch( Provider $provider ): array {
		$target = 'https://vimeo.com/' . rawurlencode( $provider->id );

		if ( '' !== $provider->hash ) {
			$target .= '/' . rawurlencode( $provider->hash );
		}

		/*
		 * The same endpoint WordPress core uses for a Vimeo link pasted into a
		 * post, asked the same way, so a site whose posts can embed Vimeo can
		 * get its pictures too.
		 */
		$response = wp_safe_remote_get(
			add_query_arg(
				array(
					'url'   => rawurlencode( $target ),
					'width' => 1280,
				),
				'https://vimeo.com/api/oembed.json'
			),
			array(
				'timeout'     => 5,
				'redirection' => 2,
			)
		);

		$none = static fn( string $code, string $detail = '', bool $soon = false ): array => array(
			'url'    => '',
			'code'   => $code,
			'detail' => $detail,
			'soon'   => $soon,
		);

		if ( is_wp_error( $response ) ) {
			return $none( 'unreachable', $response->get_error_message(), true );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			return $none( 'status', (string) $status, 429 === $status || $status >= 500 );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['thumbnail_url'] ) || ! is_string( $body['thumbnail_url'] ) ) {
			return $none( 'no-picture' );
		}

		/*
		 * The address came from a third party and is about to be printed into
		 * an `img src`, so it is checked rather than trusted: https only, and a
		 * host Vimeo actually serves pictures from.
		 */
		$parts = wp_parse_url( $body['thumbnail_url'] );
		$host  = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';

		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return $none( 'untrusted', $host );
		}

		$allowed = in_array( $host, self::PICTURE_HOSTS, true ) || str_ends_with( $host, '.vimeocdn.com' );

		return $allowed
			? array(
				'url'    => $body['thumbnail_url'],
				'code'   => '',
				'detail' => '',
				'soon'   => false,
			)
			: $none( 'untrusted', $host );
	}

	/**
	 * What happened, in a sentence the author can act on.
	 *
	 * Built when read rather than when stored, so it is in the reader's
	 * language, and so the words can change without touching what sites have
	 * remembered.
	 */
	private static function explain( string $code, string $detail ): string {
		switch ( $code ) {
			case '':
				return '';

			case 'unreachable':
				return sprintf(
					/* translators: %s: what the HTTP client reported, verbatim. */
					__( 'this site could not reach Vimeo — %s', 'imagina-player' ),
					$detail
				);

			case 'status':
				if ( '403' === $detail ) {
					return __( 'Vimeo answered 403 — the video is private, or its owner has restricted where it may be embedded', 'imagina-player' );
				}

				if ( '404' === $detail ) {
					return __( 'Vimeo answered 404 — there is no video at that address', 'imagina-player' );
				}

				return sprintf(
					/* translators: %s: the HTTP status Vimeo returned. */
					__( 'Vimeo answered %s when asked for it', 'imagina-player' ),
					$detail
				);

			case 'no-picture':
				return __( 'Vimeo answered, but without a picture for this video', 'imagina-player' );

			case 'untrusted':
				return sprintf(
					/* translators: %s: the host the picture was on. */
					__( 'Vimeo pointed at a picture on %s, which this site does not trust', 'imagina-player' ),
					$detail
				);
		}

		return __( 'Vimeo did not hand over a picture', 'imagina-player' );
	}
}
