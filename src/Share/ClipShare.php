<?php
/**
 * What a shared clip looks like where it is shared.
 *
 * Three things, for a link with `t` and, for a clip, `end`:
 *
 * - The card: Open Graph and Twitter tags naming the clip, with its picture,
 *   which is what Facebook, X, LinkedIn and WhatsApp read. Left to an SEO
 *   plugin when there is one, which writes the page's own.
 * - oEmbed: the page names an endpoint that answers with a player in a frame,
 *   which is what WordPress, Slack, Discord and Notion read to show the clip
 *   playable rather than as a link.
 * - The frame itself: the post's address with `imagina_embed`, a page with
 *   nothing on it but that player, starting at the clip.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Share;

use ImaginaPlayer\Assets;
use ImaginaPlayer\Media\Track;
use ImaginaPlayer\Player\Access;
use ImaginaPlayer\Player\Attributes;
use ImaginaPlayer\Render\PlayerRenderer;
use ImaginaPlayer\Rest\PeaksController;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ClipShare {

	public const EMBED_VAR = 'imagina_embed';

	/** The frame's size when the site asking says nothing. */
	private const WIDTH = 640;

	private const AUDIO_HEIGHT = 180;

	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'template_redirect', array( $this, 'maybe_embed' ), 1 );
		add_action( 'wp', array( $this, 'prepare_card' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			PeaksController::REST_NAMESPACE,
			'/oembed',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'oembed' ),
				// Public, as oEmbed is: it answers only for what a visitor
				// may already see, and with nothing a visitor could not.
				'permission_callback' => '__return_true',
				'args'                => array(
					'url'       => array(
						'type'     => 'string',
						'required' => true,
					),
					'format'    => array( 'type' => 'string' ),
					'maxwidth'  => array( 'type' => 'integer' ),
					'maxheight' => array( 'type' => 'integer' ),
				),
			)
		);
	}

	/**
	 * The post a public address is, with the clip it carries, if it is a
	 * post anybody may read.
	 *
	 * @return array{post: WP_Post, clip: array{start: float, end: float, player: string}, attributes: array<string, mixed>}|null
	 */
	public static function resolve( string $url ): ?array {
		$home = wp_parse_url( home_url() );
		$here = wp_parse_url( $url );

		if ( ! is_array( $here ) || strtolower( (string) ( $here['host'] ?? '' ) ) !== strtolower( (string) ( $home['host'] ?? '' ) ) ) {
			return null;
		}

		parse_str( (string) ( $here['query'] ?? '' ), $query );
		$clip = Clip::from_query( $query );
		$post = get_post( url_to_postid( $url ) );

		if ( null === $clip || ! $post instanceof WP_Post || ! self::readable( $post ) ) {
			return null;
		}

		$attributes = Clip::player( $post, $clip['player'] );

		return null === $attributes ? null : array(
			'post'       => $post,
			'clip'       => $clip,
			'attributes' => $attributes,
		);
	}

	/** Published, public, and not behind a password. */
	private static function readable( WP_Post $post ): bool {
		return 'publish' === $post->post_status
			&& '' === (string) $post->post_password
			&& is_post_type_viewable( $post->post_type );
	}

	/**
	 * The oEmbed answer for a clip's address.
	 *
	 * A player the visitor could not play — one behind "Who can play it" —
	 * is a link with its title and picture rather than a frame: the frame
	 * would only show the offer, and a stranger's site is not where to
	 * make it.
	 */
	public function oembed( WP_REST_Request $request ): WP_REST_Response {
		if ( 'xml' === strtolower( (string) $request->get_param( 'format' ) ) ) {
			return new WP_REST_Response( array( 'message' => 'Only JSON is offered.' ), 501 );
		}

		$found = self::resolve( (string) $request->get_param( 'url' ) );

		if ( null === $found ) {
			return new WP_REST_Response( array( 'message' => 'Not a clip on this site.' ), 404 );
		}

		[ 'post' => $post, 'clip' => $clip, 'attributes' => $attributes ] = $found;

		$atts  = Attributes::sanitize( $attributes );
		$track = Track::from_attributes( $atts );
		$image = Clip::image( $track, $post );
		$data  = array(
			'version'       => '1.0',
			'type'          => 'link',
			'title'         => Clip::title( $track, $post, $clip ),
			'provider_name' => (string) get_bloginfo( 'name' ),
			'provider_url'  => home_url( '/' ),
		);

		if ( '' !== $image ) {
			$data['thumbnail_url'] = $image;
		}

		$playable = $track->is_playable() && Access::ANYONE === (string) $atts['accessRule'];

		if ( $playable ) {
			[ $width, $height ] = self::size( $track, $atts, (int) $request->get_param( 'maxwidth' ), (int) $request->get_param( 'maxheight' ) );

			$data['type']   = $track->is_video() ? 'video' : 'rich';
			$data['width']  = $width;
			$data['height'] = $height;
			$data['html']   = sprintf(
				'<iframe src="%s" width="%d" height="%d" title="%s" style="border:0;max-width:100%%" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen loading="lazy"></iframe>',
				esc_url( Clip::url( $post, $clip, true ) ),
				$width,
				$height,
				esc_attr( $data['title'] )
			);
		}

		/**
		 * Filter the oEmbed answer for a shared clip.
		 *
		 * @param array<string, mixed> $data The answer.
		 * @param WP_Post              $post The post the clip is of.
		 */
		return new WP_REST_Response( apply_filters( 'imagina_player_clip_oembed', $data, $post ), 200 );
	}

	/**
	 * The frame's size: as wide as asked, at most, and as tall as the
	 * picture's shape says.
	 *
	 * @param array<string, mixed> $atts
	 * @return array{0: int, 1: int}
	 */
	private static function size( Track $track, array $atts, int $max_width, int $max_height ): array {
		$width = $max_width > 0 ? min( $max_width, self::WIDTH ) : self::WIDTH;

		if ( ! $track->is_video() ) {
			$height = self::AUDIO_HEIGHT;
		} else {
			[ $w, $h ] = array_map( 'intval', explode( ':', (string) $atts['aspectRatio'] ) ) + array( 16, 9 );
			$height    = (int) round( $width * max( 1, $h ) / max( 1, $w ) );
		}

		if ( $max_height > 0 && $height > $max_height ) {
			// Narrower rather than cropped: the picture keeps its shape.
			$width  = $track->is_video() ? (int) floor( $width * $max_height / $height ) : $width;
			$height = $max_height;
		}

		return array( $width, $height );
	}

	/**
	 * On a page opened from a clip: the card, and the oEmbed endpoint in
	 * place of WordPress's own, which would describe the whole post.
	 */
	public function prepare_card(): void {
		if ( ! is_singular() || isset( $_GET[ self::EMBED_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- a public address, read only.
			return;
		}

		$post = get_queried_object();
		$clip = Clip::from_query( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification -- a public address, read only.

		if ( ! $post instanceof WP_Post || null === $clip || ! self::readable( $post ) ) {
			return;
		}

		$attributes = Clip::player( $post, $clip['player'] );

		if ( null === $attributes ) {
			return;
		}

		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		add_action(
			'wp_head',
			function () use ( $post, $clip, $attributes ): void {
				echo $this->card( $post, $clip, $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in card().
			},
			1
		);
	}

	/**
	 * The tags for a clip's card.
	 *
	 * @param array{start: float, end: float, player: string} $clip
	 * @param array<string, mixed>                            $attributes
	 */
	public function card( WP_Post $post, array $clip, array $attributes ): string {
		$atts   = Attributes::sanitize( $attributes );
		$track  = Track::from_attributes( $atts );
		$url    = Clip::url( $post, $clip );
		$title  = Clip::title( $track, $post, $clip );
		$image  = Clip::image( $track, $post );
		$html   = sprintf(
			'<link rel="alternate" type="application/json+oembed" href="%s" />' . "\n",
			esc_url( add_query_arg( array( 'url' => rawurlencode( $url ) ), rest_url( PeaksController::REST_NAMESPACE . '/oembed' ) ) )
		);

		/**
		 * Whether to print the clip's Open Graph and Twitter tags. Off when
		 * an SEO plugin is writing the page's own: two sets of tags is a
		 * page that says two different things about itself.
		 *
		 * @param bool    $print Print them.
		 * @param WP_Post $post  The post the clip is of.
		 */
		if ( ! apply_filters( 'imagina_player_clip_open_graph', ! self::seo_plugin(), $post ) ) {
			return $html;
		}

		$excerpt = wp_trim_words( wp_strip_all_tags( strip_shortcodes( (string) ( '' !== $post->post_excerpt ? $post->post_excerpt : $post->post_content ) ) ), 30, '…' );
		$tags    = array(
			'og:type'        => $track->is_video() ? 'video.other' : 'website',
			'og:title'       => $title,
			'og:description' => $excerpt,
			'og:url'         => $url,
			'og:site_name'   => (string) get_bloginfo( 'name' ),
			'og:image'       => $image,
		);

		foreach ( $tags as $property => $content ) {
			if ( '' !== $content ) {
				$html .= sprintf( '<meta property="%s" content="%s" />' . "\n", esc_attr( $property ), esc_attr( $content ) );
			}
		}

		$twitter = array(
			'twitter:card'        => '' !== $image ? 'summary_large_image' : 'summary',
			'twitter:title'       => $title,
			'twitter:description' => $excerpt,
			'twitter:image'       => $image,
		);

		foreach ( $twitter as $name => $content ) {
			if ( '' !== $content ) {
				$html .= sprintf( '<meta name="%s" content="%s" />' . "\n", esc_attr( $name ), esc_attr( $content ) );
			}
		}

		return $html;
	}

	/** Whether an SEO plugin that writes Open Graph tags is active. */
	private static function seo_plugin(): bool {
		foreach ( array( 'WPSEO_VERSION', 'RANK_MATH_VERSION', 'AIOSEO_VERSION', 'SEOPRESS_VERSION', 'THE_SEO_FRAMEWORK_VERSION' ) as $constant ) {
			if ( defined( $constant ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The frame: the post's player on a page of its own.
	 */
	public function maybe_embed(): void {
		if ( ! isset( $_GET[ self::EMBED_VAR ] ) || ! is_singular() ) { // phpcs:ignore WordPress.Security.NonceVerification -- a public address, read only.
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post || ! self::readable( $post ) ) {
			status_header( 404 );
			exit;
		}

		$clip       = Clip::from_query( wp_unslash( $_GET ) ) ?? array( 'start' => 0.0, 'end' => 0.0, 'player' => '' ); // phpcs:ignore WordPress.Security.NonceVerification -- a public address, read only.
		$attributes = Clip::player( $post, $clip['player'] );

		if ( null === $attributes ) {
			status_header( 404 );
			exit;
		}

		echo $this->embed_page( $post, $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		exit;
	}

	/**
	 * A page with nothing on it but the player.
	 *
	 * Without the theme: its header, its menus and its styles are the site's,
	 * and in a frame on somebody else's page they are in the way. Not sticky,
	 * which in a frame would follow nothing anywhere.
	 *
	 * @param array<string, mixed> $attributes
	 */
	public function embed_page( WP_Post $post, array $attributes ): string {
		$player = ( new PlayerRenderer() )->render( array( 'sticky' => 'no' ) + $attributes, $post->ID );

		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex' );
		}

		ob_start();
		wp_print_styles( array( Assets::FRONTEND_HANDLE ) );
		$styles = (string) ob_get_clean();

		ob_start();
		wp_print_scripts( array( Assets::FRONTEND_HANDLE ) );
		$scripts = (string) ob_get_clean();

		return '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr( (string) get_bloginfo( 'charset' ) ) . '">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
			. '<title>' . esc_html( (string) get_the_title( $post ) ) . '</title>' . $styles
			. '<style>html,body{margin:0;padding:0;background:transparent}</style></head><body>'
			. '<div class="imgp-block imgp-block--embed">' . $player . '</div>' . $scripts . '</body></html>';
	}
}
