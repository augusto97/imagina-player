<?php
/**
 * A clip, as an address carries it: `?t=95&end=130&player=intro`.
 *
 * Read on the server for what a shared link shows before anybody presses
 * play: the card a social network or a chat draws for it, and the page an
 * embed loads. The playing itself — from the start, stopping at the end — is
 * the browser's, in `moments.ts`, which reads the same three values.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Share;

use ImaginaPlayer\Media\Track;
use ImaginaPlayer\Shortcodes\PlayerShortcode;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Clip {

	/** The blocks a clip can be of; a playlist is its first item. */
	private const BLOCKS = array( 'imagina/audio-player', 'imagina/video-player', 'imagina/playlist' );

	/**
	 * `95`, `1:35`, `1:02:03` or `1m35s` as seconds; -1 for anything else.
	 * The same forms the browser reads.
	 */
	public static function moment( string $text ): float {
		$value = strtolower( trim( $text ) );

		if ( '' === $value ) {
			return -1.0;
		}

		if ( preg_match( '/^(\d+):(\d{1,2})(?::(\d{1,2}))?(?:\.(\d+))?$/', $value, $clock ) ) {
			$parts = array_map( 'intval', array_values( array_filter( array( $clock[1], $clock[2], $clock[3] ?? null ), static fn( $part ): bool => null !== $part && '' !== $part ) ) );
			$total = 0;

			foreach ( $parts as $part ) {
				$total = $total * 60 + $part;
			}

			return (float) $total;
		}

		if ( preg_match( '/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+(?:\.\d+)?)s?)?$/', $value, $units ) && '' !== $units[0] ) {
			return (int) ( $units[1] ?? 0 ) * 3600 + (int) ( $units[2] ?? 0 ) * 60 + (float) ( $units[3] ?? 0 );
		}

		return -1.0;
	}

	/**
	 * The clip a query string asks for, or null when it asks for none.
	 *
	 * @param array<string, mixed> $query
	 * @return array{start: float, end: float, player: string}|null End is 0 for a moment rather than a clip.
	 */
	public static function from_query( array $query ): ?array {
		$t = $query['t'] ?? null;

		if ( ! is_string( $t ) ) {
			return null;
		}

		// `t=95&end=130`, or a media fragment's `t=95,130`.
		$parts = explode( ',', $t, 2 );
		$start = self::moment( $parts[0] );
		$end   = self::moment( is_string( $query['end'] ?? null ) ? (string) $query['end'] : ( $parts[1] ?? '' ) );

		if ( $start < 0 ) {
			return null;
		}

		return array(
			'start'  => $start,
			'end'    => $end > $start ? $end : 0.0,
			'player' => self::name( is_string( $query['player'] ?? null ) ? (string) $query['player'] : '' ),
		);
	}

	/** A block's HTML anchor, as the editor allows one. */
	public static function name( string $name ): string {
		return substr( (string) preg_replace( '/[\s#"\'<>`]+/u', '', $name ), 0, 128 );
	}

	/**
	 * The attributes of the player a post carries under this name, or of its
	 * first player when there is no name or nothing carries it.
	 *
	 * Blocks, and the shortcode wherever it was written. A page built with
	 * Elementor keeps its content elsewhere and is not read: its clips still
	 * play, and the card shown for them is the page's own.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function player( WP_Post $post, string $name = '' ): ?array {
		return self::find( parse_blocks( (string) $post->post_content ), $name );
	}

	/**
	 * The same, in blocks already parsed.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<string, mixed>|null
	 */
	public static function find( array $blocks, string $name = '' ): ?array {
		$found = array();

		self::collect( $blocks, $found );

		if ( array() === $found ) {
			return null;
		}

		foreach ( $found as $attributes ) {
			if ( '' !== $name && $name === (string) ( $attributes['anchor'] ?? '' ) ) {
				return $attributes;
			}
		}

		return $found[0];
	}

	/**
	 * Every player in blocks already parsed, in the order they appear.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( array $blocks ): array {
		$found = array();

		self::collect( $blocks, $found );

		return $found;
	}

	/**
	 * @param array<int, array<string, mixed>>  $blocks
	 * @param array<int, array<string, mixed>> $found
	 */
	private static function collect( array $blocks, array &$found ): void {
		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );

			if ( in_array( $name, self::BLOCKS, true ) ) {
				$attributes = (array) ( $block['attrs'] ?? array() );

				if ( 'imagina/playlist' === $name ) {
					$first      = (array) ( ( (array) ( $attributes['items'] ?? array() ) )[0] ?? array() );
					$attributes = array(
						'src'          => (string) ( $first['src'] ?? '' ),
						'attachmentId' => (int) ( $first['id'] ?? 0 ),
						'title'        => (string) ( $first['title'] ?? '' ),
						'thumbnail'    => (string) ( $first['thumbnail'] ?? '' ),
						'anchor'       => (string) ( $attributes['anchor'] ?? '' ),
						'accessRule'   => (string) ( $attributes['accessRule'] ?? '' ),
						'accessIds'    => (string) ( $attributes['accessIds'] ?? '' ),
					);
				}

				$found[] = $attributes;
			} elseif ( '' !== (string) ( $block['innerHTML'] ?? '' ) && str_contains( (string) $block['innerHTML'], '[' . PlayerShortcode::TAG ) ) {
				preg_match_all( '/' . get_shortcode_regex( array( PlayerShortcode::TAG ) ) . '/', (string) $block['innerHTML'], $shortcodes, PREG_SET_ORDER );

				foreach ( $shortcodes as $shortcode ) {
					$found[] = PlayerShortcode::attributes( (array) shortcode_parse_atts( $shortcode[3] ) );
				}
			}

			self::collect( (array) ( $block['innerBlocks'] ?? array() ), $found );
		}
	}

	/**
	 * What the card calls it: the player's title, or the post's, with the
	 * clip's times.
	 *
	 * @param array{start: float, end: float, player: string} $clip
	 */
	public static function title( Track $track, WP_Post $post, array $clip ): string {
		$name = '' !== $track->title ? $track->title : (string) get_the_title( $post );

		if ( $clip['end'] > 0 ) {
			/* translators: 1: what is playing, 2: where the clip starts, 3: where it ends. */
			return sprintf( __( '%1$s (%2$s–%3$s)', 'imagina-player' ), $name, self::clock( $clip['start'] ), self::clock( $clip['end'] ) );
		}

		/* translators: 1: what is playing, 2: the moment the link opens at. */
		return sprintf( __( '%1$s, from %2$s', 'imagina-player' ), $name, self::clock( $clip['start'] ) );
	}

	/** `95` as `1:35`, `3725` as `1:02:05`. */
	public static function clock( float $seconds ): string {
		$whole   = max( 0, (int) floor( $seconds ) );
		$hours   = intdiv( $whole, 3600 );
		$minutes = intdiv( $whole % 3600, 60 );
		$rest    = str_pad( (string) ( $whole % 60 ), 2, '0', STR_PAD_LEFT );

		return $hours > 0
			? $hours . ':' . str_pad( (string) $minutes, 2, '0', STR_PAD_LEFT ) . ':' . $rest
			: $minutes . ':' . $rest;
	}

	/** The picture for the card: the poster, the cover, or the post's own. */
	public static function image( Track $track, WP_Post $post ): string {
		foreach ( array( $track->poster, $track->thumbnail ) as $image ) {
			if ( '' !== $image ) {
				return $image;
			}
		}

		return (string) ( get_the_post_thumbnail_url( $post, 'large' ) ?: '' );
	}

	/**
	 * The clip's own address: the post's, with the clip and nothing else.
	 *
	 * @param array{start: float, end: float, player: string} $clip
	 */
	public static function url( WP_Post $post, array $clip, bool $embed = false ): string {
		$args = array( 't' => (string) floor( $clip['start'] ) );

		if ( $clip['end'] > 0 ) {
			$args['end'] = (string) ceil( $clip['end'] );
		}

		if ( '' !== $clip['player'] ) {
			$args['player'] = rawurlencode( $clip['player'] );
		}

		if ( $embed ) {
			$args[ ClipShare::EMBED_VAR ] = '1';
		}

		return add_query_arg( $args, (string) get_permalink( $post ) );
	}
}
