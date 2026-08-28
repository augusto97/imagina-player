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

		$layout  = 'grid' === ( $atts['layout'] ?? 'list' ) ? 'grid' : 'list';
		$heading = sanitize_text_field( (string) ( $atts['heading'] ?? '' ) );
		$id      = 'imgp-pl-' . wp_generate_password( 8, false, false );

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
				'preset'       => (string) ( $atts['preset'] ?? Settings::DEFAULT_PRESET ),
			)
		);

		$tracks = array_map( array( $this, 'client_track' ), $items );

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
						?>
						<a class="imgp-playlist__link" href="<?php echo esc_url( $item['src'] ); ?>" data-index="<?php echo esc_attr( (string) $index ); ?>">
							<?php if ( '' !== $item['thumbnail'] ) : ?>
								<img class="imgp-playlist__art" src="<?php echo esc_url( $item['thumbnail'] ); ?>" alt="" loading="lazy" decoding="async" width="64" height="64" />
							<?php endif; ?>

							<span class="imgp-playlist__text">
								<span class="imgp-playlist__title"><?php echo esc_html( $item['title'] ); ?></span>
								<?php if ( '' !== $item['artist'] ) : ?>
									<span class="imgp-playlist__artist"><?php echo esc_html( $item['artist'] ); ?></span>
								<?php endif; ?>
							</span>

							<?php if ( $item['duration'] > 0 ) : ?>
								<span class="imgp-playlist__time"><?php echo esc_html( self::clock( $item['duration'] ) ); ?></span>
							<?php endif; ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ol>
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
		$track  = Track::from_attributes(
			array(
				'src'          => $item['src'],
				'attachmentId' => $item['id'],
				'title'        => $item['title'],
			)
		);
		$key    = $track->peaks_key();
		$record = '' === $key ? null : $this->peaks->get( $key );

		return array(
			'src'         => $item['src'],
			'title'       => $item['title'],
			'artist'      => $item['artist'],
			'thumbnail'   => $item['thumbnail'],
			'duration'    => $item['duration'],
			'peaksKey'    => $key,
			'peaks'       => is_array( $record ) ? (string) $record['peaks'] : '',
			'protectedId' => Vault::is_protected( $item['id'] ) ? $item['id'] : 0,
		);
	}

	/**
	 * @param array<int, mixed> $items Raw items.
	 * @return array<int, array{id: int, src: string, title: string, artist: string, thumbnail: string, duration: float}>
	 */
	public static function sanitize_items( array $items ): array {
		$clean = array();

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
