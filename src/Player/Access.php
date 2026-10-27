<?php
/**
 * Who may play a player.
 *
 * A course sells the lesson, not the page it sits on, and the page is often
 * public — a sales page with the first video free and the rest behind a
 * purchase. So the rule is on the player: anyone, anyone signed in, certain
 * roles, people who bought a product, members of a plan, students of a
 * course. A player that may not play for this visitor is drawn without its
 * file: no address, no subtitles, no download link, nothing in the page to
 * lift — and an offer where the picture would be.
 *
 * Whoever can edit the post always sees the player, so an author is never
 * locked out of their own lesson.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Player;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Access {

	/** Everyone: the rule that is no rule. */
	public const ANYONE = '';

	/**
	 * Every rule this knows, with whether the plugin it needs is active.
	 *
	 * @return array<string, array{label: string, ids: string, available: bool}>
	 */
	public static function rules(): array {
		return array(
			'logged_in'      => array(
				'label'     => __( 'Anyone signed in', 'imagina-player' ),
				'ids'       => '',
				'available' => true,
			),
			'roles'          => array(
				'label'     => __( 'Certain roles', 'imagina-player' ),
				'ids'       => __( 'Role names, separated by commas: subscriber, customer.', 'imagina-player' ),
				'available' => true,
			),
			'woocommerce'    => array(
				'label'     => __( 'Bought a product (WooCommerce)', 'imagina-player' ),
				'ids'       => __( 'Product IDs, separated by commas. Buying any one of them is enough.', 'imagina-player' ),
				'available' => function_exists( 'wc_customer_bought_product' ),
			),
			'wc_memberships' => array(
				'label'     => __( 'Member of a plan (WooCommerce Memberships)', 'imagina-player' ),
				'ids'       => __( 'Membership plan IDs, separated by commas.', 'imagina-player' ),
				'available' => function_exists( 'wc_memberships_is_user_active_member' ),
			),
			'memberpress'    => array(
				'label'     => __( 'Member of a membership (MemberPress)', 'imagina-player' ),
				'ids'       => __( 'Membership IDs, separated by commas.', 'imagina-player' ),
				'available' => defined( 'MEPR_VERSION' ) || class_exists( 'MeprUser' ),
			),
			'learndash'      => array(
				'label'     => __( 'Enrolled in a course (LearnDash)', 'imagina-player' ),
				'ids'       => __( 'Course IDs, separated by commas.', 'imagina-player' ),
				'available' => function_exists( 'sfwd_lms_has_access' ),
			),
		);
	}

	/**
	 * Whether this visitor may play a player with these attributes.
	 *
	 * @param array<string, mixed> $atts    Sanitised attributes.
	 * @param int|null             $post_id The post the player is on.
	 */
	public static function allows( array $atts, ?int $post_id = null ): bool {
		$rule    = self::rule( (string) ( $atts['accessRule'] ?? '' ) );
		$user_id = (int) get_current_user_id();
		$post_id = $post_id ?? (int) get_the_ID();

		if ( self::ANYONE === $rule ) {
			$allowed = true;
		} elseif ( $user_id > 0 && ( current_user_can( 'manage_options' ) || ( $post_id > 0 && current_user_can( 'edit_post', $post_id ) ) ) ) {
			// The author of the lesson, and whoever runs the site.
			$allowed = true;
		} elseif ( $user_id <= 0 ) {
			$allowed = false;
		} else {
			$allowed = self::passes( $rule, self::ids( (string) ( $atts['accessIds'] ?? '' ) ), $user_id );
		}

		/**
		 * Filter whether a visitor may play a player.
		 *
		 * For a rule this plugin does not know: a CRM tag, a custom field, a
		 * date. Return false to lock the player, true to open it.
		 *
		 * @param bool                 $allowed Whether the visitor may play it.
		 * @param array<string, mixed> $atts    The player's attributes.
		 * @param int                  $user_id The visitor, or 0.
		 * @param int                  $post_id The post the player is on, or 0.
		 */
		return (bool) apply_filters( 'imagina_player_can_view', $allowed, $atts, $user_id, (int) $post_id );
	}

	/**
	 * A rule this knows, or none.
	 */
	public static function rule( string $rule ): string {
		$rule = sanitize_key( $rule );

		return array_key_exists( $rule, self::rules() ) ? $rule : self::ANYONE;
	}

	/**
	 * The list a rule is checked against: role names or numeric IDs.
	 *
	 * @return array<int, string>
	 */
	public static function ids( string $list ): array {
		$ids = array();

		foreach ( preg_split( '/[\s,]+/', $list ) ?: array() as $id ) {
			$id = sanitize_key( $id );

			if ( '' !== $id ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param array<int, string> $ids Role names or IDs.
	 */
	private static function passes( string $rule, array $ids, int $user_id ): bool {
		switch ( $rule ) {
			case 'logged_in':
				return true;

			case 'roles':
				$user = get_userdata( $user_id );

				return $user instanceof \WP_User && array() !== array_intersect( $ids, (array) $user->roles );

			case 'woocommerce':
				if ( ! function_exists( 'wc_customer_bought_product' ) ) {
					return false;
				}

				$user = get_userdata( $user_id );

				foreach ( $ids as $id ) {
					if ( ctype_digit( $id ) && wc_customer_bought_product( $user instanceof \WP_User ? (string) $user->user_email : '', $user_id, (int) $id ) ) {
						return true;
					}
				}

				return false;

			case 'wc_memberships':
				if ( ! function_exists( 'wc_memberships_is_user_active_member' ) ) {
					return false;
				}

				foreach ( $ids as $id ) {
					if ( ctype_digit( $id ) && wc_memberships_is_user_active_member( $user_id, (int) $id ) ) {
						return true;
					}
				}

				return false;

			case 'memberpress':
				// MemberPress answers through a capability of its own.
				foreach ( $ids as $id ) {
					if ( ctype_digit( $id ) && user_can( $user_id, 'mepr-active', 'memberships:' . $id ) ) {
						return true;
					}
				}

				return false;

			case 'learndash':
				if ( ! function_exists( 'sfwd_lms_has_access' ) ) {
					return false;
				}

				foreach ( $ids as $id ) {
					if ( ctype_digit( $id ) && sfwd_lms_has_access( (int) $id, $user_id ) ) {
						return true;
					}
				}

				return false;
		}

		return false;
	}

	/**
	 * Where the offer's button goes when the author did not say: the product
	 * or the course the rule names, which is where somebody gets in.
	 *
	 * @param array<string, mixed> $atts Sanitised attributes.
	 */
	public static function offer_url( array $atts ): string {
		$url = (string) ( $atts['accessUrl'] ?? '' );

		if ( '' !== $url ) {
			return $url;
		}

		$rule = self::rule( (string) ( $atts['accessRule'] ?? '' ) );
		$ids  = self::ids( (string) ( $atts['accessIds'] ?? '' ) );

		if ( in_array( $rule, array( 'woocommerce', 'learndash' ), true ) && isset( $ids[0] ) && ctype_digit( $ids[0] ) ) {
			$link = get_permalink( (int) $ids[0] );

			return is_string( $link ) ? $link : '';
		}

		return '';
	}

	/**
	 * What the offer says when the author did not say.
	 *
	 * @param array<string, mixed> $atts     Sanitised attributes.
	 * @param bool                 $is_video Whether it is a video.
	 */
	public static function message( array $atts, bool $is_video ): string {
		$message = trim( (string) ( $atts['accessMessage'] ?? '' ) );

		if ( '' !== $message ) {
			return $message;
		}

		$rule = self::rule( (string) ( $atts['accessRule'] ?? '' ) );

		return match ( $rule ) {
			'logged_in'   => $is_video
				? __( 'Sign in to watch this video.', 'imagina-player' )
				: __( 'Sign in to listen to this recording.', 'imagina-player' ),
			'woocommerce' => $is_video
				? __( 'This video comes with a purchase.', 'imagina-player' )
				: __( 'This recording comes with a purchase.', 'imagina-player' ),
			'learndash'   => $is_video
				? __( 'This video is part of a course.', 'imagina-player' )
				: __( 'This recording is part of a course.', 'imagina-player' ),
			default       => $is_video
				? __( 'This video is for members.', 'imagina-player' )
				: __( 'This recording is for members.', 'imagina-player' ),
		};
	}

	/**
	 * The offer's button label when the author did not give one.
	 *
	 * @param array<string, mixed> $atts Sanitised attributes.
	 */
	public static function button( array $atts ): string {
		$label = trim( (string) ( $atts['accessButton'] ?? '' ) );

		if ( '' !== $label ) {
			return $label;
		}

		return match ( self::rule( (string) ( $atts['accessRule'] ?? '' ) ) ) {
			'woocommerce' => __( 'Buy it', 'imagina-player' ),
			'learndash'   => __( 'Join the course', 'imagina-player' ),
			default       => __( 'Find out how', 'imagina-player' ),
		};
	}

	/**
	 * Keep a page with a player that opened for this visitor out of a page
	 * cache, so it is never served to somebody it would not open for.
	 */
	public static function do_not_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}
}
