/**
 * Moments: a link that opens a player at a second, and buttons that jump to one.
 *
 * Its own chunk. Most pages carry neither a `?t=` in their address nor a
 * timestamp button, and the core should not carry the parsing and the
 * scrolling for them; this file arrives the first time either is seen.
 */

import type { Player } from './player';
import { formatTime } from './utils';

const SELECTOR = '[data-imagina-player]';

/** How a page hands over a player that may still be waiting to be built. */
export type WithPlayer = (
	root: HTMLElement,
	callback: ( player: Player ) => void
) => void;

/**
 * `95`, `1m35s`, `1:35` or `1:02:03` as seconds; NaN for anything else.
 * @param text
 */
export function parseMoment( text: string ): number {
	const value = text.trim().toLowerCase();

	if ( ! value ) {
		return NaN;
	}

	const clock = value.match( /^(\d+):(\d{1,2})(?::(\d{1,2}))?(?:\.(\d+))?$/ );

	if ( clock ) {
		const parts = [ clock[ 1 ], clock[ 2 ], clock[ 3 ] ]
			.filter( ( part ) => undefined !== part )
			.map( Number );

		return parts.reduce( ( total, part ) => total * 60 + part, 0 );
	}

	const units = value.match(
		/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+(?:\.\d+)?)s?)?$/
	);

	if ( ! units ) {
		return NaN;
	}

	return (
		Number( units[ 1 ] ?? 0 ) * 3600 +
		Number( units[ 2 ] ?? 0 ) * 60 +
		Number( units[ 3 ] ?? 0 )
	);
}

/**
 * The player a button or a link means.
 *
 * By name first — the block's "HTML anchor" field puts an id on the wrapper,
 * and a page with two interviews needs to say which one — and otherwise the
 * first player on the page, which is what a page with one player means.
 * @param name
 */
export function playerNamed( name: string ): HTMLElement | null {
	if ( name ) {
		const named = document.getElementById( name );

		if ( named ) {
			return named.matches( SELECTOR )
				? named
				: named.querySelector< HTMLElement >( SELECTOR );
		}
	}

	return document.querySelector< HTMLElement >( SELECTOR );
}

/**
 * Take the player to a moment, and start it when asked to.
 *
 * Seeking is done twice on purpose: once now, which a file already loaded
 * honours, and again when the duration arrives, because a seek before that
 * is clamped to nothing by a player that does not know how long it is yet.
 * @param root
 * @param seconds
 * @param play
 * @param withPlayer
 */
export function jump(
	root: HTMLElement,
	seconds: number,
	play: boolean,
	withPlayer: WithPlayer
): void {
	withPlayer( root, ( player ) => {
		// Where the link or button says beats where the viewer last stopped.
		player.dismissResume();
		player.seekTo( seconds );

		if ( ! ( player.media.duration > 0 ) ) {
			player.media.addEventListener(
				'loadedmetadata',
				() => player.seekTo( seconds ),
				{ once: true }
			);
		}

		if ( play && player.media.paused ) {
			player.toggle();
		}

		const box = root.getBoundingClientRect();
		const viewport =
			window.innerHeight || document.documentElement.clientHeight;

		if ( box.top < 0 || box.bottom > viewport ) {
			root.scrollIntoView( { block: 'center', behavior: 'smooth' } );
		}
	} );
}

/**
 * A link to a moment: `?t=95`, `#t=1m35s`, with `player=` naming one.
 *
 * Seeks without playing. A page that starts a video by itself because the
 * address said so is the behaviour browsers block autoplay to stop, and a
 * seeked, paused player with the moment on its scrub bar is what a shared
 * link is for.
 * @param withPlayer
 */
export function followDeepLink( withPlayer: WithPlayer ): void {
	const sources = [
		window.location.search,
		window.location.hash.replace( /^#/, '?' ),
	];

	for ( const source of sources ) {
		const params = new URLSearchParams( source );
		const moment = params.get( 't' );

		if ( null === moment ) {
			continue;
		}

		// `t=95&end=130`, or a media fragment's `t=95,130`.
		const [ from, to ] = moment.split( ',' );
		const seconds = parseMoment( from ?? '' );
		const end = parseMoment( params.get( 'end' ) ?? to ?? '' );

		if ( ! Number.isFinite( seconds ) || seconds < 0 ) {
			continue;
		}

		const root = playerNamed( params.get( 'player' ) ?? '' );

		if ( root ) {
			jump( root, seconds, false, withPlayer );

			if ( Number.isFinite( end ) && end > seconds ) {
				withPlayer( root, ( player ) => clip( player, seconds, end ) );
			}
		}

		return;
	}
}

/**
 * A clip: the player stops where the link says, once, and offers the clip
 * again or the rest.
 *
 * Only for as long as the viewer stays inside it. Somebody who drags the
 * playhead outside the clip has chosen to watch something else, and is not
 * stopped at a second they have left behind.
 * @param player
 * @param start
 * @param end
 */
export function clip( player: Player, start: number, end: number ): void {
	const media = player.media;
	let timer = 0;

	const stop = (): void => {
		window.clearTimeout( timer );
		media.removeEventListener( 'timeupdate', check );
		media.removeEventListener( 'seeking', left );
	};

	const finish = (): void => {
		stop();
		media.pause();
		offerMore( player, start, end );
	};

	/*
	 * `timeupdate` comes four times a second, so the last stretch is timed
	 * instead: stopping a quarter of a second late is the first word of the
	 * next sentence.
	 */
	const check = (): void => {
		const left = end - media.currentTime;

		window.clearTimeout( timer );

		if ( left <= 0.05 ) {
			finish();
		} else if ( left < 0.6 && ! media.paused ) {
			timer = window.setTimeout(
				finish,
				( left * 1000 ) / ( media.playbackRate || 1 )
			);
		}
	};

	const left = (): void => {
		const at = media.currentTime;

		if ( at < start - 1 || at > end + 0.5 ) {
			stop();
		}
	};

	media.addEventListener( 'timeupdate', check );
	media.addEventListener( 'seeking', left );
}

/**
 * Where a clip ends: say so, and offer it again or the rest.
 * @param player
 * @param start
 * @param end
 */
function offerMore( player: Player, start: number, end: number ): void {
	const host = player.root;
	const i18n = window.imaginaPlayer?.i18n ?? {};
	const doc = host.ownerDocument;
	const chip = doc.createElement( 'div' );
	const text = doc.createElement( 'span' );
	const again = doc.createElement( 'button' );
	const more = doc.createElement( 'button' );

	host.querySelector( '.imgp__resume' )?.remove();

	chip.className = 'imgp__resume imgp__resume--clip';
	chip.setAttribute( 'role', 'status' );
	text.className = 'imgp__resume-text';
	text.textContent = `${
		i18n.clipEnded ?? 'End of the clip'
	} · ${ formatTime( start ) }–${ formatTime( end ) }`;

	for ( const [ button, label ] of [
		[ again, i18n.clipReplay ?? 'Replay clip' ],
		[ more, i18n.clipContinue ?? 'Keep watching' ],
	] as const ) {
		button.type = 'button';
		button.className = 'imgp__resume-restart';
		button.textContent = label;
	}

	again.addEventListener( 'click', () => {
		chip.remove();
		player.seekTo( start );
		clip( player, start, end );

		if ( player.media.paused ) {
			player.toggle();
		}
	} );
	more.addEventListener( 'click', () => {
		chip.remove();

		if ( player.media.paused ) {
			player.toggle();
		}
	} );

	chip.append( text, again, more );
	host.appendChild( chip );
}

/**
 * A timestamp button was pressed: seek the player it names and start it.
 * @param button
 * @param withPlayer
 */
export function pressTimestamp(
	button: HTMLElement,
	withPlayer: WithPlayer
): void {
	const seconds = parseMoment( button.dataset.imgpTime ?? '' );

	if ( ! Number.isFinite( seconds ) ) {
		return;
	}

	const root = playerNamed( button.dataset.imgpPlayer ?? '' );

	if ( root ) {
		jump( root, seconds, true, withPlayer );
	}
}
