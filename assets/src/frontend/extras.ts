/**
 * The "more" menu of an audio player: a sleep timer, the listener's own
 * bookmarks, and a link to the moment playing.
 *
 * Its own chunk, fetched the first time somebody opens the menu: most
 * listeners never will, and a page should not pay for it until one does.
 */

import type { Player } from './player';

interface Item {
	label: string;
	run?: () => void;
	/** A heading, not something to press. */
	note?: boolean;
	/** Leave the menu open, with a new list in it. */
	stay?: boolean;
}

/** Bookmarks per file, in this browser. */
const MARKS = 'imagina-player:bookmarks:';

/** Fading out takes this long at the end of a sleep timer. */
const FADE_MS = 6000;

const state = new WeakMap< Player, Extras >();

/**
 * Open (or close) the menu of one player.
 * @param player
 * @param button
 */
export function openExtras( player: Player, button: HTMLButtonElement ): void {
	let extras = state.get( player );

	if ( ! extras ) {
		extras = new Extras( player, button );
		state.set( player, extras );
	}

	extras.toggle();
}

class Extras {
	private readonly player: Player;

	private readonly button: HTMLButtonElement;

	private readonly menu: HTMLElement;

	private readonly i18n: Record< string, string >;

	private timer = 0;

	private ticker = 0;

	private sleepAt = 0;

	/** The file was set to go round again before "end of this one" said no. */
	private looped = false;

	private dismiss: ( () => void ) | null = null;

	constructor( player: Player, button: HTMLButtonElement ) {
		this.player = player;
		this.button = button;
		this.i18n = window.imaginaPlayer?.i18n ?? {};
		this.menu =
			button.parentElement?.querySelector< HTMLElement >(
				'.imgp__more-menu'
			) ?? document.createElement( 'div' );

		// The track that ends at the end of its file still ends the timer.
		player.media.addEventListener( 'ended', () => {
			if ( -1 === this.sleepAt ) {
				this.stopTimer();
				player.media.pause();
			}
		} );
	}

	toggle(): void {
		if ( ! this.menu.hidden ) {
			this.close( true );

			return;
		}

		this.show( this.mainItems() );
	}

	private mainItems(): Item[] {
		const items: Item[] = [
			{
				label: this.timerLabel(),
				stay: true,
				run: () => this.show( this.timerItems() ),
			},
			{
				label: this.i18n.bookmarkAdd ?? 'Bookmark this moment',
				run: () => this.addMark(),
			},
		];

		const marks = this.marks();

		if ( marks.length ) {
			items.push( {
				label: this.i18n.bookmarks ?? 'Bookmarks',
				note: true,
			} );

			for ( const at of marks ) {
				items.push( {
					label: stamp( at ),
					run: () => {
						this.player.dismissResume();
						this.player.seekTo( at );
					},
				} );
			}

			items.push( {
				label: this.i18n.bookmarksClear ?? 'Clear bookmarks',
				run: () => this.saveMarks( [] ),
			} );
		}

		items.push( {
			label: this.i18n.copyLink ?? 'Copy link to this moment',
			run: () => void this.copyLink(),
		} );

		return items;
	}

	private timerLabel(): string {
		const label = this.i18n.sleepTimer ?? 'Sleep timer';

		if ( -1 === this.sleepAt ) {
			return `${ label } · ${ this.i18n.sleepEnd ?? 'End of this one' }`;
		}

		if ( this.sleepAt > 0 ) {
			const minutes = Math.max(
				1,
				Math.round( ( this.sleepAt - Date.now() ) / 60000 )
			);

			return `${ label } · ${ (
				this.i18n.sleepLeft ?? '%d min left'
			).replace( '%d', String( minutes ) ) }`;
		}

		return `${ label } · ${ this.i18n.captionsOff ?? 'Off' }`;
	}

	private timerItems(): Item[] {
		const minutes = [ 15, 30, 45, 60 ];

		return [
			{
				label: `‹ ${ this.i18n.back ?? 'Back' }`,
				stay: true,
				run: () => this.show( this.mainItems() ),
			},
			{
				label: this.i18n.captionsOff ?? 'Off',
				run: () => this.stopTimer(),
			},
			...minutes.map( ( value ) => ( {
				label: ( this.i18n.sleepMinutes ?? '%d minutes' ).replace(
					'%d',
					String( value )
				),
				run: () => this.startTimer( value ),
			} ) ),
			{
				label: this.i18n.sleepEnd ?? 'End of this one',
				run: () => {
					this.stopTimer();
					this.sleepAt = -1;
					this.player.holdAtEnd = true;

					// A file that loops never ends: not this time round.
					this.looped = Boolean( this.player.media.loop );
					this.player.media.loop = false;
					this.mark();
				},
			},
		];
	}

	private startTimer( minutes: number ): void {
		this.stopTimer();
		this.sleepAt = Date.now() + minutes * 60000;
		this.timer = window.setTimeout(
			() => this.fadeOut(),
			minutes * 60000 - FADE_MS
		);
		this.mark();
	}

	private stopTimer(): void {
		window.clearTimeout( this.timer );
		window.clearInterval( this.ticker );
		this.sleepAt = 0;
		this.player.holdAtEnd = false;

		if ( this.looped ) {
			this.player.media.loop = true;
			this.looped = false;
		}

		this.mark();
	}

	/**
	 * Down over a few seconds rather than cut off: a listener falling
	 * asleep to something is woken by it stopping dead.
	 */
	private fadeOut(): void {
		const media = this.player.media;
		const start = media.volume;
		const began = Date.now();

		this.ticker = window.setInterval( () => {
			const done = Math.min( 1, ( Date.now() - began ) / FADE_MS );

			media.volume = start * ( 1 - done );

			if ( done >= 1 ) {
				window.clearInterval( this.ticker );
				media.pause();
				media.volume = start;
				this.sleepAt = 0;
				this.mark();
			}
		}, 200 );
	}

	/** The button says a timer is set. */
	private mark(): void {
		this.button.classList.toggle( 'is-sleeping', 0 !== this.sleepAt );
	}

	private marks(): number[] {
		try {
			const raw = window.localStorage.getItem(
				MARKS + this.player.trackKey
			);

			return raw
				? ( JSON.parse( raw ) as number[] ).filter( Number.isFinite )
				: [];
		} catch {
			return [];
		}
	}

	private saveMarks( marks: number[] ): void {
		try {
			window.localStorage.setItem(
				MARKS + this.player.trackKey,
				JSON.stringify( marks )
			);
		} catch {
			// Storage off: nothing to keep them in.
		}
	}

	private addMark(): void {
		const at = Math.floor( this.player.media.currentTime || 0 );
		const marks = this.marks().filter( ( mark ) => mark !== at );

		marks.push( at );
		marks.sort( ( a, b ) => a - b );
		this.saveMarks( marks.slice( -50 ) );
		this.say(
			( this.i18n.bookmarkAdded ?? 'Bookmarked at %s' ).replace(
				'%s',
				stamp( at )
			)
		);
	}

	/**
	 * The page's address at this second, naming this player when it has an
	 * anchor — the same link the video's gear copies.
	 */
	private async copyLink(): Promise< void > {
		const root = this.button.closest< HTMLElement >( '.imgp' );
		const url = new URL( window.location.href );
		const name = root?.closest< HTMLElement >( '.imgp-block' )?.id;

		url.searchParams.set(
			't',
			String( Math.floor( this.player.media.currentTime || 0 ) )
		);
		url.hash = '';

		if ( name ) {
			url.searchParams.set( 'player', name );
		} else {
			url.searchParams.delete( 'player' );
		}

		try {
			await navigator.clipboard.writeText( url.toString() );
			this.say( this.i18n.linkCopied ?? 'Link copied' );
		} catch {
			this.say( url.toString() );
		}
	}

	/**
	 * A line that goes away by itself.
	 * @param text
	 */
	private say( text: string ): void {
		const root = this.button.closest< HTMLElement >( '.imgp' );

		if ( ! root ) {
			return;
		}

		root.querySelector( '.imgp__toast' )?.remove();

		const toast = document.createElement( 'div' );

		toast.className = 'imgp__toast';
		toast.setAttribute( 'role', 'status' );
		toast.textContent = text;
		root.appendChild( toast );
		window.setTimeout( () => toast.remove(), 2200 );
	}

	private show( items: Item[] ): void {
		const menu = this.menu;

		this.dismiss?.();
		menu.textContent = '';

		let first: HTMLElement | null = null;

		for ( const item of items ) {
			if ( item.note ) {
				const note = document.createElement( 'p' );

				note.className = 'imgp__more-note';
				note.textContent = item.label;
				menu.appendChild( note );
				continue;
			}

			const button = document.createElement( 'button' );

			button.type = 'button';
			button.className = 'imgp__menuitem';
			button.setAttribute( 'role', 'menuitem' );
			button.tabIndex = -1;
			button.textContent = item.label;
			button.addEventListener( 'click', () => {
				item.run?.();

				if ( ! item.stay ) {
					this.close( true );
				}
			} );
			menu.appendChild( button );
			first ??= button;
		}

		menu.hidden = false;
		this.button.setAttribute( 'aria-expanded', 'true' );
		first?.focus();

		const onKey = ( event: KeyboardEvent ): void => {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				this.close( true );

				return;
			}

			const all = Array.from(
				menu.querySelectorAll< HTMLElement >( '.imgp__menuitem' )
			);
			const index = all.indexOf(
				menu.ownerDocument.activeElement as HTMLElement
			);
			let next = -1;

			if ( 'ArrowDown' === event.key ) {
				next = ( index + 1 ) % all.length;
			} else if ( 'ArrowUp' === event.key ) {
				next = ( index - 1 + all.length ) % all.length;
			}

			if ( next >= 0 && all[ next ] ) {
				event.preventDefault();
				all[ next ].focus();
			}
		};

		const onPointer = ( event: PointerEvent ): void => {
			const target = event.target as Node | null;

			if (
				target &&
				! menu.contains( target ) &&
				! this.button.contains( target )
			) {
				this.close( false );
			}
		};

		document.addEventListener( 'keydown', onKey );
		document.addEventListener( 'pointerdown', onPointer );

		this.dismiss = () => {
			document.removeEventListener( 'keydown', onKey );
			document.removeEventListener( 'pointerdown', onPointer );
			this.dismiss = null;
		};
	}

	private close( returnFocus: boolean ): void {
		this.dismiss?.();
		this.menu.hidden = true;
		this.menu.textContent = '';
		this.button.setAttribute( 'aria-expanded', 'false' );

		if ( returnFocus ) {
			this.button.focus();
		}
	}
}

function stamp( seconds: number ): string {
	const whole = Math.max( 0, Math.floor( seconds ) );
	const hours = Math.floor( whole / 3600 );
	const minutes = Math.floor( ( whole % 3600 ) / 60 );
	const rest = String( whole % 60 ).padStart( 2, '0' );

	return hours
		? `${ hours }:${ String( minutes ).padStart( 2, '0' ) }:${ rest }`
		: `${ minutes }:${ rest }`;
}
