/**
 * A list of tracks feeding one player.
 *
 * Its own chunk, loaded only by a page that has a playlist on it.
 *
 * The list is rendered by the server as links to the files themselves, so
 * before any of this runs, clicking a track plays it — which is what a person
 * clicking a track in a list is asking for. All this does is catch the click
 * and hand it to the player that is already on the page, so the listener keeps
 * their volume, their speed and their place in the page.
 */

import type { Player } from './player';
import type { TrackChange } from './types';

/** Where the last track played is remembered, per playlist. */
const RESUME_KEY = 'imagina-player-playlist';

export class Playlist {
	private readonly root: HTMLElement;

	private readonly player: Player;

	private readonly tracks: TrackChange[];

	private readonly links: HTMLAnchorElement[];

	private current = 0;

	private readonly cleanup: Array< () => void > = [];

	constructor( root: HTMLElement, player: Player, tracks: TrackChange[] ) {
		this.root = root;
		this.player = player;
		this.tracks = tracks;
		this.links = Array.from(
			root.querySelectorAll< HTMLAnchorElement >( '.imgp-playlist__link' )
		);

		this.bindClicks();
		this.bindAdvance();
		this.restore();
	}

	destroy(): void {
		for ( const off of this.cleanup ) {
			off();
		}

		this.cleanup.length = 0;
	}

	private bindClicks(): void {
		this.links.forEach( ( link, index ) => {
			const click = ( event: MouseEvent ): void => {
				// A modified click is a request to open the file somewhere else,
				// and taking that over would be rude. Let the link be a link.
				if (
					event.metaKey ||
					event.ctrlKey ||
					event.shiftKey ||
					event.altKey ||
					0 !== event.button
				) {
					return;
				}

				event.preventDefault();
				this.play( index );
			};

			link.addEventListener( 'click', click );
			this.cleanup.push( () =>
				link.removeEventListener( 'click', click )
			);
		} );
	}

	/**
	 * When one finishes, the next begins.
	 *
	 * Except at the end of the list, where stopping is the right answer: looping
	 * an album back to track one because nobody was there to stop it is how a
	 * player ends up playing to an empty room all night.
	 */
	private bindAdvance(): void {
		const ended = (): void => {
			if ( this.current + 1 < this.tracks.length ) {
				this.play( this.current + 1 );
			}
		};

		this.player.media.addEventListener( 'ended', ended );
		this.cleanup.push( () =>
			this.player.media.removeEventListener( 'ended', ended )
		);
	}

	play( index: number, autoplay = true ): void {
		const track = this.tracks[ index ];

		if ( ! track ) {
			return;
		}

		this.current = index;

		this.links.forEach(
			( link, i ) =>
				link.parentElement?.classList.toggle(
					'is-current',
					i === index
				)
		);

		// Announced, because for someone using a screen reader the only sign
		// that anything happened is that the audio changed.
		this.links[ index ]?.setAttribute( 'aria-current', 'true' );
		this.links.forEach( ( link, i ) => {
			if ( i !== index ) {
				link.removeAttribute( 'aria-current' );
			}
		} );

		this.player.loadTrack( track, autoplay );
		this.remember( index );
	}

	/**
	 * Come back to the track the listener left off on.
	 *
	 * Loaded, not played: arriving on a page and having audio start by itself is
	 * the single most disliked thing a media player does.
	 */
	private restore(): void {
		const index = this.remembered();

		if ( index > 0 && index < this.tracks.length ) {
			this.play( index, false );
		}
	}

	private key(): string {
		return this.root.id || 'playlist';
	}

	private remembered(): number {
		try {
			const store = JSON.parse(
				window.localStorage.getItem( RESUME_KEY ) ?? '{}'
			) as Record< string, number >;

			return Number( store[ this.key() ] ?? 0 );
		} catch {
			return 0;
		}
	}

	private remember( index: number ): void {
		try {
			const store = JSON.parse(
				window.localStorage.getItem( RESUME_KEY ) ?? '{}'
			) as Record< string, number >;

			store[ this.key() ] = index;
			window.localStorage.setItem( RESUME_KEY, JSON.stringify( store ) );
		} catch {
			// Storage off. The list starts at the top next time.
		}
	}
}
