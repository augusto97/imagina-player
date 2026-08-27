<?php
/**
 * Ties protection into WordPress: URLs, the media library UI, and cleanup.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Protection;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Integration {

	public function hooks(): void {
		add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_field' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'save_field' ), 10, 2 );
	}

	/**
	 * Hand out the signed streaming URL for anything in the vault.
	 *
	 * Filtering here rather than only inside the player means a protected file
	 * stays protected wherever its URL is used — a theme template, another
	 * plugin, a feed.
	 *
	 * @param string $url           Attachment URL.
	 * @param int    $attachment_id Attachment ID.
	 */
	public function filter_attachment_url( $url, $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( ! Vault::is_protected( $attachment_id ) ) {
			return $url;
		}

		$signed = ProtectedMedia::signed_url( $attachment_id );

		return '' !== $signed ? $signed : $url;
	}

	/**
	 * Add the protection toggle to the attachment edit panel.
	 *
	 * @param array<string, mixed> $fields Attachment fields.
	 * @param WP_Post              $post   Attachment post.
	 * @return array<string, mixed>
	 */
	public function add_field( $fields, $post ) {
		if ( ! Vault::is_eligible( (int) $post->ID ) ) {
			return $fields;
		}

		if ( ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return $fields;
		}

		$protected = Vault::is_protected( (int) $post->ID );

		$help = $protected
			? __( 'This file is stored outside the public uploads folder and is served through a signed, expiring link.', 'imagina-player' )
			: __( 'Moves the file out of the public uploads folder and serves it through a signed, expiring link.', 'imagina-player' );

		if ( ! ProtectedMedia::is_enabled() ) {
			$help .= ' ' . __( 'Protection is currently switched off in Settings → Imagina Player.', 'imagina-player' );
		}

		$fields['imagina_protected'] = array(
			'label' => __( 'Protect this file', 'imagina-player' ),
			'input' => 'html',
			'html'  => sprintf(
				'<label><input type="checkbox" name="attachments[%1$d][imagina_protected]" value="1" %2$s /> %3$s</label>',
				(int) $post->ID,
				checked( $protected, true, false ),
				esc_html__( 'Serve through a signed link', 'imagina-player' )
			),
			'helps' => esc_html( $help ),
		);

		return $fields;
	}

	/**
	 * Move the file in or out of the vault when the toggle changes.
	 *
	 * @param array<string, mixed> $post       Attachment post data.
	 * @param array<string, mixed> $attachment Submitted fields.
	 * @return array<string, mixed>
	 */
	public function save_field( $post, $attachment ) {
		$attachment_id = (int) ( $post['ID'] ?? 0 );

		if ( $attachment_id <= 0 || ! current_user_can( 'edit_post', $attachment_id ) ) {
			return $post;
		}

		if ( ! Vault::is_eligible( $attachment_id ) ) {
			return $post;
		}

		$wanted = ! empty( $attachment['imagina_protected'] );
		$actual = Vault::is_protected( $attachment_id );

		if ( $wanted === $actual ) {
			return $post;
		}

		$result = $wanted ? Vault::protect( $attachment_id ) : Vault::unprotect( $attachment_id );

		if ( is_wp_error( $result ) ) {
			$post['errors']['imagina_protected']['errors'][] = $result->get_error_message();
		}

		return $post;
	}
}
