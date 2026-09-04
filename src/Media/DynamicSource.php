<?php
/**
 * A file named by a custom field of the post the player is shown on.
 *
 * A template — a WooCommerce product page built in the site editor, a
 * custom post type's single view — holds one block, and every post that
 * template shows needs its own file. The block cannot carry the address,
 * because the block is the same for all of them; the post can, in a custom
 * field: one made with ACF or JetEngine, or plain post meta. The block names
 * the field, and at render time the file is read from the post in front of
 * the visitor.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Media;

use ImaginaPlayer\Player\Attributes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DynamicSource {

	/** The attribute that names the field. */
	public const ATTRIBUTE = 'sourceField';

	/**
	 * Replace the block's own file with the one the post's field names.
	 *
	 * The block's own file, if it has one, stays as the fallback for a post
	 * whose field is empty — a template can show a default that way.
	 *
	 * @param array<string, mixed> $atts    Sanitised attributes.
	 * @param int                  $post_id The post being shown.
	 * @return array<string, mixed>
	 */
	public static function apply( array $atts, int $post_id ): array {
		$key = self::key( $atts );

		if ( '' === $key || $post_id <= 0 ) {
			return $atts;
		}

		$found = self::read( $key, $post_id );

		if ( null === $found ) {
			return $atts;
		}

		$atts['attachmentId'] = $found['attachmentId'];
		$atts['src']          = $found['src'];

		return $atts;
	}

	/**
	 * The field the block names, or an empty string.
	 *
	 * @param array<string, mixed> $atts Sanitised attributes.
	 */
	public static function key( array $atts ): string {
		return (string) ( $atts[ self::ATTRIBUTE ] ?? '' );
	}

	/**
	 * What the field holds, as a file this player can use.
	 *
	 * @return array{attachmentId: int, src: string}|null Null when the field
	 *                                                    is empty, unreadable,
	 *                                                    or holds no file.
	 */
	public static function read( string $key, int $post_id ): ?array {
		/*
		 * Hidden meta is not read. Whatever a site keeps under a key starting
		 * with an underscore — a token, a signed address, another plugin's
		 * bookkeeping — was never meant for a page, and a contributor who can
		 * place a block should not be able to print it into one by naming the
		 * key. ACF and JetEngine store a field's value under its own name, so
		 * their fields are all reachable; only their bookkeeping is not.
		 */
		if ( '' === $key || is_protected_meta( $key, 'post' ) ) {
			return null;
		}

		return self::normalise( get_post_meta( $post_id, $key, true ) );
	}

	/**
	 * The shapes a field plugin stores a file in, brought to one.
	 *
	 * A media field usually stores the attachment ID (ACF's "File" and
	 * JetEngine's "Media" fields by default), a URL field the address, and
	 * some store an array with both. Anything else is not a file.
	 *
	 * @param mixed $value What the meta holds.
	 * @return array{attachmentId: int, src: string}|null
	 */
	public static function normalise( $value ): ?array {
		if ( is_array( $value ) ) {
			// ACF's array return format, JetEngine's "array" media format.
			foreach ( array( 'ID', 'id', 'attachment_id' ) as $id_key ) {
				if ( ! empty( $value[ $id_key ] ) && is_numeric( $value[ $id_key ] ) ) {
					return self::normalise( (int) $value[ $id_key ] );
				}
			}

			if ( ! empty( $value['url'] ) && is_string( $value['url'] ) ) {
				return self::normalise( $value['url'] );
			}

			return null;
		}

		if ( is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^[0-9]+$/', $value ) ) ) {
			$id = (int) $value;

			// A number is an attachment only if there is one: anything else
			// stored as a number is not a file.
			if ( $id > 0 && 'attachment' === get_post_type( $id ) ) {
				return array(
					'attachmentId' => $id,
					'src'          => '',
				);
			}

			return null;
		}

		if ( is_string( $value ) ) {
			$src = Attributes::sanitize_media_url( $value );

			// The same rule as the block's own address field: http or https,
			// and nothing else. A field holding `javascript:` is not a file.
			if ( '' !== $src && 1 === preg_match( '#^https?://#i', $src ) ) {
				return array(
					'attachmentId' => 0,
					'src'          => $src,
				);
			}
		}

		return null;
	}

	/**
	 * A field key as a block may store it.
	 *
	 * Meta keys are free text in WordPress; what is accepted here is what
	 * ACF, JetEngine and hand-written meta actually use. Bounded because a
	 * key is looked up on every page view.
	 */
	public static function sanitize_key( string $key ): string {
		$key = trim( $key );

		return 1 === preg_match( '/^[A-Za-z0-9_\-]{1,100}$/', $key ) ? $key : '';
	}

	/**
	 * The post a player rendered right now belongs to.
	 *
	 * Inside a loop — a product template, a single post — that is the post
	 * being shown; outside one it is whatever the page is about.
	 */
	public static function current_post_id(): int {
		$id = (int) get_the_ID();

		if ( $id > 0 ) {
			return $id;
		}

		return function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
	}
}
