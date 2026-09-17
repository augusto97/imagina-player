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

	/** The list itself, which the sideways layouts scroll. */
	private readonly list: HTMLElement | null;

	private current = 0;

	private readonly cleanup: Array< () => void > = [];

	constructor( root: HTMLElement, player: Player, tracks: TrackChange[] ) {
		this.root = root;
		this.player = player;
		this.tracks = tracks;
		this.links = Array.from(
			root.querySelectorAll< HTMLAnchorElement >( '.imgp-playlist__link' )
		);
		this.list = root.querySelector< HTMLElement >(
			'.imgp-playlist__items'
		);

		this.bindClicks();
		this.bindAdvance();
		this.bindArrows();
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

				/*
				 * A video the player cannot take — YouTube into a file
				 * player, a file into YouTube's frame — is left to the
				 * link, which opens it. Better an honest page change than
				 * a click that does nothing.
				 */
				const track = this.tracks[ index ];

				if ( ! track || ! this.player.canLoad( track ) ) {
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
		this.reveal( index );
	}

	/**
	 * Bring the current item into view along a sideways list.
	 *
	 * The list's own scroll only: `scrollIntoView` would also move the page
	 * to the item, and a track advancing while the reader is elsewhere on
	 * the page must not drag them back.
	 * @param index
	 */
	private reveal( index: number ): void {
		const list = this.list;
		const link = this.links[ index ];

		if (
			! list ||
			! link ||
			( list.scrollWidth <= list.clientWidth &&
				list.scrollHeight <= list.clientHeight )
		) {
			return;
		}

		const item = link.parentElement ?? link;

		// A list beside the picture scrolls down; a rail or slider along.
		if ( list.scrollHeight > list.clientHeight + 1 ) {
			list.scrollTo( {
				top:
					item.offsetTop -
					list.clientHeight / 2 +
					item.offsetHeight / 2,
				behavior: 'smooth',
			} );

			return;
		}

		list.scrollTo( {
			left: item.offsetLeft - list.clientWidth / 2 + item.offsetWidth / 2,
			behavior: 'smooth',
		} );
	}

	/**
	 * The slider's arrows: shown once the cards overflow, and each moves the
	 * row most of a width, so the last card seen becomes the first.
	 */
	private bindArrows(): void {
		const list = this.list;
		const prev = this.root.querySelector< HTMLButtonElement >(
			'.imgp-playlist__nav--prev'
		);
		const next = this.root.querySelector< HTMLButtonElement >(
			'.imgp-playlist__nav--next'
		);

		if ( ! list || ! prev || ! next ) {
			return;
		}

		const review = (): void => {
			const overflow = list.scrollWidth > list.clientWidth + 1;

			prev.hidden = ! overflow;
			next.hidden = ! overflow;
			prev.disabled = list.scrollLeft <= 0;
			next.disabled =
				list.scrollLeft + list.clientWidth >= list.scrollWidth - 1;
		};

		const by = ( direction: number ): void => {
			list.scrollBy( {
				left: direction * list.clientWidth * 0.8,
				behavior: 'smooth',
			} );
		};

		const back = (): void => by( -1 );
		const forward = (): void => by( 1 );

		prev.addEventListener( 'click', back );
		next.addEventListener( 'click', forward );
		list.addEventListener( 'scroll', review, { passive: true } );
		window.addEventListener( 'resize', review );

		this.cleanup.push( () => {
			prev.removeEventListener( 'click', back );
			next.removeEventListener( 'click', forward );
			list.removeEventListener( 'scroll', review );
			window.removeEventListener( 'resize', review );
		} );

		review();
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
