<?php
/**
 * Several tracks, one player.
 *
 * The player itself is the ordinary one — the same renderer, the same skins,
 * the same protection. What this adds is a list beside it and the ability to
 * change what is loaded without rebuilding anything, because a playlist that
 * re-created the player on every click would lose the volume the listener set,
 * the speed they chose and the element they had focused.
 *
 * The first track is rendered fully, server-side, so the page is a working
 * player before any JavaScript runs. Every other item is a link that happens to
 * do something better when it can.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Render;

use ImaginaPlayer\Media\Track;
use ImaginaPlayer\Peaks\PeaksRepository;
use ImaginaPlayer\Player\Attributes;
use ImaginaPlayer\Protection\Vault;
use ImaginaPlayer\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PlaylistRenderer {

	/**
	 * How the list sits against the player.
	 *
	 * `list` and `grid` are the audio shapes. The other three are for video:
	 * the list beside the picture, a rail of thumbnails under it, and a
	 * slider of larger cards with arrows.
	 */
	public const LAYOUTS = array( 'list', 'grid', 'side', 'rail', 'slider' );

	/** The layouts that run sideways and scroll. */
	public const HORIZONTAL = array( 'rail', 'slider' );

	private PlayerRenderer $player;

	private PeaksRepository $peaks;

	public function __construct( ?PlayerRenderer $player = null, ?PeaksRepository $peaks = null ) {
		$this->player = $player ?? new PlayerRenderer();
		$this->peaks  = $peaks ?? new PeaksRepository();
	}

	/**
	 * @param array<string, mixed> $atts Raw block attributes.
	 */
	public function render( array $atts ): string {
		$items = self::sanitize_items( (array) ( $atts['items'] ?? array() ) );

		if ( array() === $items ) {
			return '<div class="imgp imgp--empty"><p>'
				. esc_html__( 'Imagina Player: this playlist has no tracks yet.', 'imagina-player' )
				. '</p></div>';
		}

		$layout  = self::layout( (string) ( $atts['layout'] ?? 'list' ) );
		$heading = sanitize_text_field( (string) ( $atts['heading'] ?? '' ) );
		$id      = 'imgp-pl-' . wp_generate_password( 8, false, false );

		// Each item as the player would see it: a video's poster, a provider's
		// still, an upload's cover and length, resolved once here so the list
		// and the runtime agree.
		$items = array_map( array( $this, 'resolve' ), $items );

		// The first item is rendered as a real player. The rest are data the
		// runtime swaps in — but they are also links, so they work without it.
		$first = $items[0];

		$player = $this->player->render(
			array(
				'src'          => $first['src'],
				'attachmentId' => $first['id'],
				'title'        => $first['title'],
				'artist'       => $first['artist'],
				'thumbnail'    => $first['thumbnail'],
				'poster'       => $first['poster'],
				'preset'       => (string) ( $atts['preset'] ?? Settings::DEFAULT_PRESET ),
			)
		);

		$tracks     = array_map( array( $this, 'client_track' ), $items );
		$horizontal = in_array( $layout, self::HORIZONTAL, true );

		ob_start();
		?>
		<div
			class="imgp-playlist imgp-playlist--<?php echo esc_attr( $layout ); ?>"
			id="<?php echo esc_attr( $id ); ?>"
			data-imagina-playlist="<?php echo esc_attr( (string) wp_json_encode( $tracks ) ); ?>"
		>
			<?php if ( '' !== $heading ) : ?>
				<h3 class="imgp-playlist__heading"><?php echo esc_html( $heading ); ?></h3>
			<?php endif; ?>

			<div class="imgp-playlist__player">
				<?php echo $player; // phpcs:ignore WordPress.Security.EscapeOutput -- rendered by PlayerRenderer, which escapes. ?>
			</div>

			<?php if ( 'slider' === $layout ) : ?>
				<?php
				/*
				 * Arrows for the slider, hidden until the script knows the
				 * cards overflow; a row that fits needs no arrows, and the
				 * row scrolls by hand either way. In a strip with the row,
				 * so they sit over its ends rather than the picture's.
				 */
				?>
				<div class="imgp-playlist__strip">
				<button type="button" class="imgp-playlist__nav imgp-playlist__nav--prev" aria-label="<?php esc_attr_e( 'Previous', 'imagina-player' ); ?>" hidden><?php echo Icons::get( 'back-arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG. ?></button>
				<button type="button" class="imgp-playlist__nav imgp-playlist__nav--next" aria-label="<?php esc_attr_e( 'Next', 'imagina-player' ); ?>" hidden><?php echo Icons::get( 'forward-arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static SVG. ?></button>
			<?php endif; ?>

			<ol class="imgp-playlist__items">
				<?php foreach ( $items as $index => $item ) : ?>
					<li class="imgp-playlist__item<?php echo 0 === $index ? ' is-current' : ''; ?>">
						<?php
						/*
						 * A link to the file, not a button. Without JavaScript it
						 * plays the track — which is what a person clicking a track
						 * in a list is asking for. With it, the click is caught and
						 * the current player takes over instead.
						 */
						$time = $item['duration'] > 0
							? '<span class="imgp-playlist__time">' . esc_html( self::clock( $item['duration'] ) ) . '</span>'
							: '';
						?>
						<a class="imgp-playlist__link" href="<?php echo esc_url( $item['src'] ); ?>" data-index="<?php echo esc_attr( (string) $index ); ?>">
							<?php if ( '' !== $item['thumbnail'] || $item['video'] ) : ?>
								<span class="imgp-playlist__cover<?php echo '' === $item['thumbnail'] ? ' imgp-playlist__cover--blank' : ''; ?><?php echo $item['video'] ? ' imgp-playlist__cover--video' : ''; ?>">
									<?php if ( '' !== $item['thumbnail'] ) : ?>
										<img class="imgp-playlist__art" src="<?php echo esc_url( $item['thumbnail'] ); ?>" alt="" loading="lazy" decoding="async" width="64" height="64" />
									<?php endif; ?>
									<?php if ( $horizontal ) : ?>
										<?php echo $time; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>
									<?php endif; ?>
								</span>
							<?php endif; ?>

							<span class="imgp-playlist__text">
								<span class="imgp-playlist__title"><?php echo esc_html( $item['title'] ); ?></span>
								<?php if ( '' !== $item['artist'] ) : ?>
									<span class="imgp-playlist__artist"><?php echo esc_html( $item['artist'] ); ?></span>
								<?php endif; ?>
							</span>

							<?php if ( ! $horizontal || '' === $item['thumbnail'] && ! $item['video'] ) : ?>
								<?php echo $time; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>
							<?php endif; ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ol>
			<?php if ( 'slider' === $layout ) : ?>
				</div>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * What the runtime needs to swap a track in.
	 *
	 * Peaks travel with the item when the server already has them measured: the
	 * alternative is a request per track as the listener clicks through an
	 * album, and the whole waveform pipeline exists to avoid exactly that.
	 *
	 * @param array<string, mixed> $item Sanitised item.
	 * @return array<string, mixed>
	 */
	private function client_track( array $item ): array {
		$key    = (string) $item['peaksKey'];
		$record = '' === $key ? null : $this->peaks->get( $key );

		$track = array(
			'src'         => $item['src'],
			'title'       => $item['title'],
			'artist'      => $item['artist'],
			'thumbnail'   => $item['thumbnail'],
			'poster'      => $item['poster'],
			'duration'    => $item['duration'],
			'peaksKey'    => $key,
			'peaks'       => is_array( $record ) ? (string) $record['peaks'] : '',
			'protectedId' => Vault::is_protected( $item['id'] ) ? $item['id'] : 0,
		);

		// A provider's video is switched through the provider's own player,
		// which needs its name for the video rather than an address.
		if ( '' !== $item['provider'] ) {
			$track['provider']     = $item['provider'];
			$track['providerId']   = $item['providerId'];
			$track['providerHash'] = $item['providerHash'];
		}

		return $track;
	}

	/**
	 * An item as the player would see it.
	 *
	 * A video item wants a picture in the list whether or not the author gave
	 * it one: the poster a provider serves, or the cover an upload carries.
	 * An upload also knows its own length. The player resolves all of this
	 * for the one it shows; the list needs it for every item.
	 *
	 * @param array<string, mixed> $item Sanitised item.
	 * @return array<string, mixed>
	 */
	private function resolve( array $item ): array {
		$track = Track::from_attributes(
			array(
				'src'          => $item['src'],
				'attachmentId' => $item['id'],
				'title'        => $item['title'],
				'thumbnail'    => $item['thumbnail'],
			)
		);

		$duration = $item['duration'];

		if ( $duration <= 0 && $item['id'] > 0 && function_exists( 'wp_get_attachment_metadata' ) ) {
			$meta     = (array) ( wp_get_attachment_metadata( $item['id'] ) ?: array() );
			$duration = max( 0.0, (float) ( $meta['length'] ?? 0 ) );
		}

		return array_merge(
			$item,
			array(
				'video'        => $track->is_video(),
				'poster'       => $track->poster,
				'provider'     => $track->is_provider() && null !== $track->provider ? $track->provider->name : '',
				'providerId'   => null !== $track->provider ? $track->provider->id : '',
				'providerHash' => null !== $track->provider ? $track->provider->hash : '',
				'peaksKey'     => $track->peaks_key(),
				// A picture for the list: the author's, else the poster or cover.
				'thumbnail'    => '' !== $item['thumbnail'] ? $item['thumbnail'] : ( '' !== $track->poster ? $track->poster : $track->thumbnail ),
				'duration'     => $duration,
			)
		);
	}

	/**
	 * One of the layouts, or the list.
	 */
	public static function layout( string $layout ): string {
		return in_array( $layout, self::LAYOUTS, true ) ? $layout : 'list';
	}

	/**
	 * @param array<int, mixed> $items Raw items.
	 * @return array<int, array{id: int, src: string, title: string, artist: string, thumbnail: string, duration: float}>
	 */
	public static function sanitize_items( array $items ): array {
		$clean = array();

		/*
		 * Every attachment in one round trip, before the loop asks about them
		 * one at a time. wp_get_attachment_url() loads the post and its meta on
		 * first sight, which for a playlist of N uploads was 2N queries; primed,
		 * it is two whatever N is.
		 */
		$ids = array();

		foreach ( $items as $item ) {
			if ( is_array( $item ) && (int) ( $item['id'] ?? 0 ) > 0 ) {
				$ids[] = (int) $item['id'];
			}
		}

		if ( array() !== $ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_values( array_unique( $ids ) ), false, true );
		}

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$id  = (int) ( $item['id'] ?? 0 );
			$src = Attributes::sanitize_media_url( (string) ( $item['src'] ?? '' ) );

			// An attachment's current URL wins, for the same reason it does on a
			// single player: a file moved into the vault answers on a signed URL
			// now, and the one saved in the block would bypass it or 404.
			if ( $id > 0 ) {
				$current = wp_get_attachment_url( $id );

				if ( $current ) {
					$src = (string) $current;
				}
			}

			if ( '' === $src ) {
				continue;
			}

			$clean[] = array(
				'id'        => $id,
				'src'       => $src,
				'title'     => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
				'artist'    => sanitize_text_field( (string) ( $item['artist'] ?? '' ) ),
				'thumbnail' => Attributes::sanitize_media_url( (string) ( $item['thumbnail'] ?? '' ) ),
				'duration'  => max( 0.0, (float) ( $item['duration'] ?? 0 ) ),
			);
		}

		return $clean;
	}

	private static function clock( float $seconds ): string {
		$whole   = (int) round( $seconds );
		$minutes = intdiv( $whole, 60 );

		return sprintf( '%d:%02d', $minutes, $whole % 60 );
	}
}
