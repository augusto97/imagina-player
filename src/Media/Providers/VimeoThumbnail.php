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
	 * Ask Vimeo, at two doors.
	 *
	 * The first is the oEmbed endpoint WordPress core uses for a pasted Vimeo
	 * link. For a video whose owner has hidden it from Vimeo.com, or allowed
	 * it only on chosen sites, Vimeo answers that door with the player and
	 * nothing else — no title, no picture — while the player itself, once on
	 * the page, plainly has a picture to show. Seen on a real site: "Vimeo
	 * answered, but without a picture", beside a Vimeo player showing one.
	 *
	 * So the second door is the one the player uses: its configuration, which
	 * carries the stills it draws. It is asked only when the first gave no
	 * picture, and only with this site named as the asker, which is how the
	 * player's own request is allowed in.
	 *
	 * @return array{url: string, code: string, detail: string, soon: bool}
	 *         `code` names what happened; `detail` is whatever the far end or
	 *         the HTTP client said, verbatim; `soon` is whether it is worth
	 *         asking again in minutes rather than an hour.
	 */
	private static function fetch( Provider $provider ): array {
		$first = self::ask_oembed( $provider );

		if ( '' !== $first['url'] || 'no-picture' !== $first['code'] ) {
			return $first;
		}

		$second = self::ask_player_config( $provider );

		// The player's door answered with a picture, or with nothing at all —
		// in which case the first door's honest answer is the one to keep.
		return '' !== $second['url'] ? $second : $first;
	}

	/**
	 * A request to Vimeo, saying which site is asking.
	 *
	 * A video its owner allows only on chosen sites is described only to a
	 * request from one of them. A browser says where it is from by itself;
	 * a server does not unless told to, and this site is the site the video
	 * is embedded on.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function request( string $url ) {
		return wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 2,
				'headers'     => array(
					'Referer' => home_url( '/' ),
				),
			)
		);
	}

	/**
	 * @return array{url: string, code: string, detail: string, soon: bool}
	 */
	private static function none( string $code, string $detail = '', bool $soon = false ): array {
		return array(
			'url'    => '',
			'code'   => $code,
			'detail' => $detail,
			'soon'   => $soon,
		);
	}

	/**
	 * @return array{url: string, code: string, detail: string, soon: bool}
	 */
	private static function found( string $url ): array {
		return array(
			'url'    => $url,
			'code'   => '',
			'detail' => '',
			'soon'   => false,
		);
	}

	/**
	 * The first door: the oEmbed endpoint, asked the way WordPress asks it.
	 *
	 * @return array{url: string, code: string, detail: string, soon: bool}
	 */
	private static function ask_oembed( Provider $provider ): array {
		$target = 'https://vimeo.com/' . rawurlencode( $provider->id );

		if ( '' !== $provider->hash ) {
			$target .= '/' . rawurlencode( $provider->hash );
		}

		$response = self::request(
			add_query_arg(
				array(
					'url'   => rawurlencode( $target ),
					'width' => 1280,
				),
				'https://vimeo.com/api/oembed.json'
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::none( 'unreachable', $response->get_error_message(), true );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			return self::none( 'status', (string) $status, 429 === $status || $status >= 500 );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['thumbnail_url'] ) || ! is_string( $body['thumbnail_url'] ) ) {
			return self::none( 'no-picture' );
		}

		return self::trusted( $body['thumbnail_url'] );
	}

	/**
	 * The second door: the player's own configuration, which lists its stills.
	 *
	 * Not a documented endpoint — it is the one Vimeo's player reads on
	 * load, and it has been stable for years. Anything unexpected in its
	 * answer is treated as no picture, which is where things stood anyway.
	 *
	 * @return array{url: string, code: string, detail: string, soon: bool}
	 */
	private static function ask_player_config( Provider $provider ): array {
		$url = 'https://player.vimeo.com/video/' . rawurlencode( $provider->id ) . '/config';

		if ( '' !== $provider->hash ) {
			$url = add_query_arg( array( 'h' => rawurlencode( $provider->hash ) ), $url );
		}

		$response = self::request( $url );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return self::none( 'no-picture' );
		}

		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$thumbs = is_array( $body ) ? ( $body['video']['thumbs'] ?? null ) : null;

		if ( ! is_array( $thumbs ) || array() === $thumbs ) {
			return self::none( 'no-picture' );
		}

		/*
		 * Keyed by width — "640", "1280", "base" — and the widest is wanted.
		 * `base` is the picture without a size, which Vimeo serves at a
		 * default width; it is the fallback when no sized one is listed.
		 */
		$best  = '';
		$width = -1;

		foreach ( $thumbs as $key => $candidate ) {
			if ( ! is_string( $candidate ) || '' === $candidate ) {
				continue;
			}

			$size = is_numeric( $key ) ? (int) $key : 0;

			if ( $size > $width ) {
				$width = $size;
				$best  = $candidate;
			}
		}

		return '' === $best ? self::none( 'no-picture' ) : self::trusted( $best );
	}

	/**
	 * An address that came from a third party and is about to be printed
	 * into an `img src`: https only, and a host Vimeo actually serves
	 * pictures from.
	 *
	 * @return array{url: string, code: string, detail: string, soon: bool}
	 */
	private static function trusted( string $url ): array {
		$parts = wp_parse_url( $url );
		$host  = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';

		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return self::none( 'untrusted', $host );
		}

		$allowed = in_array( $host, self::PICTURE_HOSTS, true ) || str_ends_with( $host, '.vimeocdn.com' );

		return $allowed ? self::found( $url ) : self::none( 'untrusted', $host );
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
				return __( 'Vimeo answered, but without a picture for this video — it does that for a video hidden from Vimeo.com or allowed only on chosen sites, and this site was named as the asker without success', 'imagina-player' );

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
