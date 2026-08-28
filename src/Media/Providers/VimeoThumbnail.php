<?php
/**
 * Vimeo's poster image, which cannot be guessed.
 *
 * YouTube's thumbnail lives at a predictable address, so it costs nothing to
 * point at. Vimeo's is a CDN path nobody can construct, so it has to be asked
 * for — and asking on every page view would put a third-party request in front
 * of every visitor, which is the opposite of the point.
 *
 * So it is asked once and remembered. A failure is remembered too, for a
 * shorter time: a provider that is down should not mean this site re-asks on
 * every request until it comes back.
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

	/** Long enough not to hammer a provider that is down, short enough to recover. */
	private const TTL_MISS = HOUR_IN_SECONDS;

	public static function get( Provider $provider ): string {
		if ( 'vimeo' !== $provider->name || '' === $provider->id ) {
			return '';
		}

		$key    = self::PREFIX . md5( $provider->id . '|' . $provider->hash );
		$cached = get_transient( $key );

		if ( is_string( $cached ) ) {
			// An empty string is a remembered failure, not a cache miss.
			return $cached;
		}

		$url = self::fetch( $provider );

		set_transient( $key, $url, '' === $url ? self::TTL_MISS : self::TTL );

		return $url;
	}

	private static function fetch( Provider $provider ): string {
		$target = 'https://vimeo.com/' . rawurlencode( $provider->id );

		if ( '' !== $provider->hash ) {
			$target .= '/' . rawurlencode( $provider->hash );
		}

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

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['thumbnail_url'] ) || ! is_string( $body['thumbnail_url'] ) ) {
			return '';
		}

		/*
		 * The address came from a third party and is about to be printed into
		 * an `img src`, so it is checked rather than trusted: https only, and a
		 * host Vimeo actually serves pictures from.
		 */
		$parts = wp_parse_url( $body['thumbnail_url'] );

		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return '';
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );

		$allowed = in_array( $host, array( 'i.vimeocdn.com', 'vimeocdn.com', 'i.vimeocdn.net' ), true )
			|| str_ends_with( $host, '.vimeocdn.com' );

		return $allowed ? $body['thumbnail_url'] : '';
	}
}
