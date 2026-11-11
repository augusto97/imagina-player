/**
 * Counting how a player is watched, and telling the site.
 *
 * Its own chunk, loaded on a player's first play: a page that is only read
 * never downloads it, and the player core carries no more than the hook.
 *
 * What is sent is counts and nothing else — a play, the seconds watched since
 * the last report, how far into the file this viewing has got, which layers
 * were pressed. Nothing identifies the viewer: no cookie is set and nothing is
 * kept in the browser, so there is nothing to ask consent for.
 *
 * Sent when the page is put away, when the track changes and when it ends,
 * as one small request each time — and every few minutes on a long viewing,
 * so a tab that is never closed properly loses only the last stretch.
 */

import type { Player } from './player';

/** How far a viewing got is counted in hundredths of the file. */
const BUCKETS = 100;

/** A share watched that counts as having seen it to the end. */
const COMPLETE_AT = 0.9;

/** A jump bigger than this between two ticks is a seek, not watching. */
const MAX_TICK = 2;

/** Report a long viewing this often, in seconds watched. */
const REPORT_EVERY = 300;

export class Audience {
	private readonly host: Player;

	private readonly cleanup: Array< () => void > = [];

	/** This viewing has been counted as a play. */
	private counted = false;

	private play = 0;

	private seconds = 0;

	/** Seconds watched since the last report, for the periodic one. */
	private sinceReport = 0;

	/** Where the clock was at the last tick, or -1 after a seek. */
	private last = -1;

	/** The furthest hundredth this viewing has reached, and the one last told. */
	private furthest = -1;

	private told = -1;

	private completed = false;

	private complete = 0;

	private clicks: Record< number, number > = {};

	constructor( host: Player ) {
		this.host = host;

		const { media, root } = host;
		const listen = (
			target: EventTarget,
			type: string,
			handler: ( event: Event ) => void,
			capture = false
		): void => {
			target.addEventListener( type, handler, capture );
			this.cleanup.push( () =>
				target.removeEventListener( type, handler, capture )
			);
		};

		listen( media, 'play', () => this.started() );
		listen( media, 'timeupdate', () => this.tick() );
		listen( media, 'seeking', () => {
			this.last = -1;
		} );
		listen( media, 'ended', () => {
			// A playlist may already have moved on to the next track, whose
			// viewing has not started.
			if ( this.counted ) {
				this.reach( 1 );
				this.flush();
			}
		} );

		// Pressing an offer, a link over the picture or a spot; sending an
		// address. Closing one is not interest in it.
		listen(
			root,
			'click',
			( event ) => {
				const target = event.target as Element | null;
				const action = target?.closest( 'a, button.imgp__layer-link' );

				if ( action && ! action.closest( '.imgp__layer-close' ) ) {
					this.click( action );
				}
			},
			true
		);
		listen(
			root,
			'submit',
			( event ) => this.click( event.target as Element | null ),
			true
		);

		listen( document, 'visibilitychange', () => {
			if ( 'hidden' === document.visibilityState ) {
				this.flush();
			}
		} );
		listen( window, 'pagehide', () => this.flush() );

		// Loaded by the first play, which has already happened.
		if ( ! media.paused ) {
			this.started();
		}
	}

	private started(): void {
		if ( ! this.counted ) {
			this.counted = true;
			this.play = 1;
		}

		this.last = this.host.media.currentTime;
	}

	private tick(): void {
		const { media } = this.host;
		const now = media.currentTime;

		if ( media.paused ) {
			this.last = now;

			return;
		}

		if ( this.last >= 0 ) {
			const step = now - this.last;

			if (
				step > 0 &&
				step < MAX_TICK * Math.max( 1, media.playbackRate )
			) {
				this.seconds += step;
				this.sinceReport += step;
			}
		}

		this.last = now;

		const length = this.host.duration();

		if ( length > 0 ) {
			this.reach( now / length );
		}

		if ( this.sinceReport >= REPORT_EVERY ) {
			this.flush();
		}
	}

	/**
	 * Note how far this viewing got, as a share of the file.
	 * @param share Where the clock is, as a share of the file.
	 */
	private reach( share: number ): void {
		const bucket = Math.min(
			BUCKETS - 1,
			Math.max( 0, Math.floor( share * BUCKETS ) )
		);

		this.furthest = Math.max( this.furthest, bucket );

		if ( ! this.completed && share >= COMPLETE_AT ) {
			this.completed = true;
			this.complete = 1;
		}
	}

	private click( element: Element | null ): void {
		const layer = element?.closest< HTMLElement >( '[data-layer-index]' );
		const index = Number( layer?.dataset.layerIndex ?? -1 );

		if ( index >= 0 ) {
			this.clicks[ index ] = ( this.clicks[ index ] ?? 0 ) + 1;
		}
	}

	/**
	 * Send what has been counted since the last report, if anything.
	 *
	 * `sendBeacon`, because it is the one request a browser promises to make
	 * while a page is going away, and as plain text, because that is a
	 * request it makes without asking the server's permission first.
	 */
	flush(): void {
		// The current track's: a playlist changes it as it moves on.
		const stats = this.host.config.stats;
		const moved = this.furthest > this.told;
		const seconds = Math.round( this.seconds );
		const clicked = Object.keys( this.clicks ).length > 0;

		if (
			! stats ||
			! this.host.runtime.restUrl ||
			( ! this.play &&
				! seconds &&
				! moved &&
				! this.complete &&
				! clicked )
		) {
			return;
		}

		const body = JSON.stringify( {
			key: stats.key,
			post: stats.post,
			token: stats.token,
			title: stats.title,
			duration: Math.round( this.host.duration() * 10 ) / 10,
			play: this.play,
			seconds,
			complete: this.complete,
			from: moved ? this.told : -1,
			to: moved ? this.furthest : -1,
			clicks: this.clicks,
		} );

		const url = `${ this.host.runtime.restUrl }/stats`;
		let sent = false;

		// A beacon refused — or thrown, for an address it will not send to
		// — goes as an ordinary request that outlives the page.
		try {
			sent = navigator.sendBeacon(
				url,
				new Blob( [ body ], { type: 'text/plain' } )
			);
		} catch {}

		if ( ! sent ) {
			fetch( url, {
				method: 'POST',
				body,
				keepalive: true,
				credentials: 'omit',
				headers: { 'Content-Type': 'text/plain' },
			} ).catch( () => undefined );
		}

		this.play = 0;
		this.seconds -= seconds;
		this.sinceReport = 0;
		this.complete = 0;
		this.clicks = {};

		if ( moved ) {
			this.told = this.furthest;
		}
	}

	/**
	 * A new track in the same player: what was counted goes to the old one,
	 * and the next play is a new viewing.
	 */
	switchTrack(): void {
		this.flush();
		this.counted = false;
		this.completed = false;
		this.furthest = -1;
		this.told = -1;
		this.seconds = 0;
		this.last = -1;
	}

	destroy(): void {
		this.flush();
		this.cleanup.forEach( ( undo ) => undo() );
		this.cleanup.length = 0;
	}
}
