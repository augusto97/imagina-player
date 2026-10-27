/**
 * A playlist that is a course.
 *
 * Its own chunk, loaded only by a list marked as a course. Each lesson is
 * marked once nine tenths of it has been watched; the list says how many are
 * done; coming back picks up the lesson that was playing, where it stopped;
 * and, when the author asks, a lesson further on opens only once the one
 * before it is done.
 *
 * Kept in the browser for everybody and, for a student who is signed in, with
 * their account as well, so it follows them to another device and to another
 * page carrying the same course.
 */

import type { Player } from './player';
import type { Playlist } from './playlist';

interface State {
	/** The lessons watched, by key. */
	w: string[];
	/** The lesson last loaded. */
	c: string;
	/** Where it was, in seconds. */
	p: number;
	/** When, in seconds since 1970, so the newer of two answers wins. */
	t: number;
}

interface Data {
	key: string;
	items: string[];
	sequential: boolean;
	sync: boolean;
	state: State | null;
}

const STORE = 'imagina-player-course:';

/** Watched once this much of a lesson has played. */
const DONE_AT = 0.9;

/** How often a position is sent while a lesson plays. */
const SYNC_EVERY = 20000;

export class Course {
	private readonly root: HTMLElement;

	private readonly playlist: Playlist;

	private readonly player: Player;

	private readonly data: Data;

	private state: State;

	private readonly rows: HTMLElement[];

	private readonly cleanup: Array< () => void > = [];

	private lastSent = 0;

	constructor(
		root: HTMLElement,
		playlist: Playlist,
		player: Player,
		raw: string
	) {
		this.root = root;
		this.playlist = playlist;
		this.player = player;
		this.data = JSON.parse( raw ) as Data;
		this.rows = Array.from(
			root.querySelectorAll< HTMLElement >( '.imgp-playlist__item' )
		);
		this.state = this.merge( this.local(), this.data.state );

		playlist.gate = ( index ) => this.allowed( index );
		playlist.onPlay = ( index ) => {
			this.state.c = this.data.items[ index ] ?? '';
			this.state.p = 0;
			this.save( true );
		};

		this.bindProgress();
		this.bindRestart();
		this.paint();
		this.resume();
	}

	destroy(): void {
		this.send( true );

		for ( const off of this.cleanup ) {
			off();
		}

		this.cleanup.length = 0;
		this.playlist.gate = null;
		this.playlist.onPlay = null;
	}

	/**
	 * A lesson may be played when every one before it is done — or always,
	 * for a course that is not one lesson at a time.
	 * @param index
	 */
	private allowed( index: number ): boolean {
		// The lesson that just ended counts, even if its last tenth was
		// skipped to. Asked here because the list moves on to the next one
		// before this course hears that the file ended.
		if ( this.player.media.ended ) {
			this.watch( this.playlist.index );
		}

		return ! this.data.sequential || index <= this.frontier();
	}

	/** The first lesson not watched: as far as a student may go. */
	private frontier(): number {
		const watched = new Set( this.state.w );
		const index = this.data.items.findIndex(
			( key ) => ! watched.has( key )
		);

		return -1 === index ? this.data.items.length : index;
	}

	private bindProgress(): void {
		const media = this.player.media;

		const tick = (): void => {
			const duration = media.duration;

			// Only while it plays: a lesson opened where it was left, or
			// dragged towards its end, has not been watched by being there.
			if (
				! media.paused &&
				Number.isFinite( duration ) &&
				duration > 0 &&
				media.currentTime / duration >= DONE_AT
			) {
				this.watch( this.playlist.index );
			}

			this.state.p = Math.floor( media.currentTime || 0 );

			if ( Date.now() - this.lastSent > SYNC_EVERY ) {
				this.save( true );
			}
		};

		// The last lesson, which has no next one to ask about it. By now a
		// list that moved on has another file loaded, which has not ended.
		const ended = (): void => {
			if ( media.ended ) {
				this.watch( this.playlist.index );
			}
		};
		const paused = (): void => {
			this.state.p = Math.floor( media.currentTime || 0 );
			this.save( true );
		};
		const leaving = (): void => {
			this.state.p = Math.floor( media.currentTime || 0 );
			this.save( false );
			this.send( true );
		};

		media.addEventListener( 'timeupdate', tick );
		media.addEventListener( 'ended', ended );
		media.addEventListener( 'pause', paused );
		window.addEventListener( 'pagehide', leaving );

		this.cleanup.push( () => {
			media.removeEventListener( 'timeupdate', tick );
			media.removeEventListener( 'ended', ended );
			media.removeEventListener( 'pause', paused );
			window.removeEventListener( 'pagehide', leaving );
		} );
	}

	private bindRestart(): void {
		const button = this.root.querySelector< HTMLButtonElement >(
			'.imgp-playlist__restart'
		);

		if ( ! button ) {
			return;
		}

		const restart = (): void => {
			this.state = { w: [], c: '', p: 0, t: now() };
			this.save( false, true );
			this.send( false, true );
			this.paint();
			this.playlist.play( 0, false );
			this.player.startOver();
		};

		button.addEventListener( 'click', restart );
		this.cleanup.push( () =>
			button.removeEventListener( 'click', restart )
		);
	}

	private watch( index: number ): void {
		const key = this.data.items[ index ];

		if ( ! key || this.state.w.includes( key ) ) {
			return;
		}

		this.state.w.push( key );
		this.save( false );
		this.send( false );
		this.paint();
	}

	/** Marks, locks and the count, from the state. */
	private paint(): void {
		const watched = new Set( this.state.w );
		const frontier = this.frontier();
		const i18n = window.imaginaPlayer?.i18n ?? {};
		let done = 0;

		this.rows.forEach( ( row, index ) => {
			const key = this.data.items[ index ] ?? '';
			const isWatched = watched.has( key );
			const isShut = this.data.sequential && index > frontier;
			const link = row.querySelector< HTMLElement >(
				'.imgp-playlist__link'
			);
			const status = row.querySelector< HTMLElement >(
				'.imgp-playlist__status'
			);

			done += isWatched ? 1 : 0;
			row.classList.toggle( 'is-watched', isWatched );
			row.classList.toggle( 'is-locked', isShut );

			if ( link ) {
				if ( isShut ) {
					link.setAttribute( 'aria-disabled', 'true' );
					link.title =
						i18n.courseLocked ??
						'Finish the one before to open this';
				} else {
					link.removeAttribute( 'aria-disabled' );
					link.removeAttribute( 'title' );
				}
			}

			if ( status ) {
				status.textContent = isWatched
					? i18n.courseWatched ?? 'Watched'
					: '';
			}
		} );

		const total = this.data.items.length;
		const text = this.root.querySelector< HTMLElement >(
			'.imgp-playlist__progress-text'
		);
		const bar = this.root.querySelector< HTMLElement >(
			'.imgp-playlist__progress-bar > span'
		);
		const restart = this.root.querySelector< HTMLElement >(
			'.imgp-playlist__restart'
		);

		if ( text ) {
			text.textContent = ( i18n.courseProgress ?? '%1$d of %2$d watched' )
				.replace( '%1$d', String( done ) )
				.replace( '%2$d', String( total ) );
		}

		if ( bar ) {
			bar.style.width = `${
				total ? Math.round( ( 100 * done ) / total ) : 0
			}%`;
		}

		if ( restart ) {
			restart.hidden = 0 === done;
		}
	}

	/**
	 * Back to the lesson that was playing, where it stopped. Loaded, not
	 * played.
	 */
	private resume(): void {
		const index = this.data.items.indexOf( this.state.c );
		// Read before loading the lesson, which starts its count at nought.
		const position = this.state.p;

		if ( index < 0 || ! this.allowed( index ) ) {
			return;
		}

		if ( index !== this.playlist.index ) {
			this.playlist.play( index, false );
		}

		// From another device the player has no position of its own for it.
		if ( position > 0 && this.player.media.currentTime < 1 ) {
			this.state.p = position;
			this.player.seekTo( position );
		}
	}

	/**
	 * The two answers, joined: every lesson watched in either, and the
	 * lesson and position from whichever is newer.
	 * @param mine
	 * @param theirs
	 */
	private merge( mine: State | null, theirs: State | null ): State {
		const empty: State = { w: [], c: '', p: 0, t: 0 };
		const a = mine ?? empty;
		const b = theirs ?? empty;
		const newer = b.t > a.t ? b : a;

		return {
			w: Array.from( new Set( [ ...a.w, ...b.w ] ) ),
			c: newer.c,
			p: newer.p,
			t: Math.max( a.t, b.t ),
		};
	}

	private local(): State | null {
		try {
			const raw = window.localStorage.getItem( STORE + this.data.key );
			const state = raw ? ( JSON.parse( raw ) as State ) : null;

			return state && Array.isArray( state.w ) ? state : null;
		} catch {
			return null;
		}
	}

	/**
	 * Kept in the browser, and sent to the account when there is one.
	 *
	 * Joined with what the browser already has rather than written over it:
	 * the same course open in another tab keeps its own count, and the one
	 * closed last would otherwise wipe out what the other had marked.
	 * @param remote  Also send it, at most every so often.
	 * @param replace Start over: what was there goes.
	 */
	private save( remote: boolean, replace = false ): void {
		this.state.t = now();

		try {
			const stored = replace ? null : this.local();

			if ( stored ) {
				this.state.w = Array.from(
					new Set( [ ...stored.w, ...this.state.w ] )
				);

				// A tab where nothing was played has no lesson to say.
				if ( ! this.state.c ) {
					this.state.c = stored.c;
					this.state.p = stored.p;
				}
			}

			window.localStorage.setItem(
				STORE + this.data.key,
				JSON.stringify( this.state )
			);
		} catch {
			// Storage off: the account, if any, still has it.
		}

		if ( remote && Date.now() - this.lastSent > SYNC_EVERY ) {
			this.send( false );
		}
	}

	/**
	 * To the student's account. Kept alive past the page for the last word
	 * before it closes.
	 * @param leaving
	 * @param reset
	 */
	private send( leaving: boolean, reset = false ): void {
		const runtime = window.imaginaPlayer;

		if ( ! this.data.sync || ! runtime?.restUrl || ! runtime.nonce ) {
			return;
		}

		this.lastSent = Date.now();

		void fetch( `${ runtime.restUrl.replace( /\/$/, '' ) }/progress`, {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: leaving,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': runtime.nonce,
			},
			body: JSON.stringify( {
				course: this.data.key,
				watched: this.state.w,
				current: this.state.c,
				position: this.state.p,
				reset,
			} ),
		} ).catch( () => undefined );
	}
}

function now(): number {
	return Math.floor( Date.now() / 1000 );
}
