/**
 * Moments: a link that opens a player at a second, and buttons that jump to one.
 *
 * Its own chunk. Most pages carry neither a `?t=` in their address nor a
 * timestamp button, and the core should not carry the parsing and the
 * scrolling for them; this file arrives the first time either is seen.
 */

import type { Player } from './player';

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

		const seconds = parseMoment( moment );

		if ( ! Number.isFinite( seconds ) || seconds < 0 ) {
			continue;
		}

		const root = playerNamed( params.get( 'player' ) ?? '' );

		if ( root ) {
			jump( root, seconds, false, withPlayer );
		}

		return;
	}
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
