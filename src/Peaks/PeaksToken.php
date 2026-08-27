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

use ImaginaPlayer\Support\Signature;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PeaksToken {

	private const TTL = WEEK_IN_SECONDS;

	private const CONTEXT = 'peaks';

	public static function create( string $key, int $resolution ): string {
		if ( '' === $key ) {
			return '';
		}

		return Signature::create(
			array(
				'key' => $key,
				'res' => $resolution,
			),
			self::TTL,
			self::CONTEXT
		);
	}

	/**
	 * Validate a token and return its payload.
	 *
	 * @return array{key: string, resolution: int}|null
	 */
	public static function verify( string $token ): ?array {
		$claims = Signature::verify( $token, self::CONTEXT );

		if ( null === $claims || empty( $claims['key'] ) ) {
			return null;
		}

		return array(
			'key'        => (string) $claims['key'],
			'resolution' => (int) ( $claims['res'] ?? 0 ),
		);
	}
}
