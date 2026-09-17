import './public-path';
import { Player } from './player';
import type { RuntimeData, TrackChange, VideoConfig } from './types';
import './style.scss';

const SELECTOR = '[data-imagina-player]';

const PLAYLIST_SELECTOR = '[data-imagina-playlist]';

const initialised = new WeakSet< HTMLElement >();

/** Players by root element, so a playlist can find the one it belongs to. */
const players = new WeakMap< HTMLElement, Player >();

function runtime(): RuntimeData {
	return (
		window.imaginaPlayer ?? {
			restUrl: '',
			lazyInit: true,
			maxComputeBytes: 25 * 1024 * 1024,
			i18n: {},
		}
	);
}

function create( root: HTMLElement ): void {
	if ( initialised.has( root ) ) {
		return;
	}

	initialised.add( root );

	/*
	 * A video on YouTube or Vimeo has no element to drive, so a stand-in has to
	 * exist before the player is built. That lives in its own chunk and arrives
	 * asynchronously; everything else stays synchronous, so a page of audio
	 * players pays nothing for this.
	 */
	let config: { video?: VideoConfig } = {};

	try {
		config = JSON.parse( root.dataset.imaginaPlayer || '{}' );
	} catch {
		// One player with a mangled attribute — a filter, a minifier — is one
		// player that does not start, not a batch of them.
		return;
	}

	if ( config.video?.provider ) {
		void start( root, config.video );

		return;
	}

	build( root, null );
}

async function start( root: HTMLElement, video: VideoConfig ): Promise< void > {
	try {
		const { createProviderMedia } = await import(
			/* webpackChunkName: "imagina-provider" */ './provider'
		);

		const standIn = createProviderMedia( root, video );

		build( root, standIn );

		// After the player exists, so the chrome is already listening when the
		// first `play` arrives.
		void standIn?.start();
	} catch ( error ) {
		// The still image and the link under it are already in the page, so a
		// failure here leaves something that works rather than a blank box.
		if ( window.console ) {
			window.console.warn( 'Imagina Player:', error );
		}
	}
}

/** Callers waiting for a player that is still being built. */
const waiting = new WeakMap<
	HTMLElement,
	Array< ( player: Player ) => void >
>();

function build(
	root: HTMLElement,
	standIn: ConstructorParameters< typeof Player >[ 2 ]
): void {
	try {
		const player = new Player( root, runtime(), standIn );

		players.set( root, player );

		for ( const callback of waiting.get( root ) ?? [] ) {
			callback( player );
		}

		waiting.delete( root );
	} catch ( error ) {
		// A single broken player must not take the rest of the page with it.
		if ( window.console ) {
			window.console.warn( 'Imagina Player:', error );
		}
	}
}

let observer: IntersectionObserver | null = null;

function observe( root: HTMLElement ): void {
	if ( ! runtime().lazyInit || ! ( 'IntersectionObserver' in window ) ) {
		create( root );

		return;
	}

	if ( ! observer ) {
		observer = new IntersectionObserver(
			( entries ) => {
				for ( const entry of entries ) {
					if ( entry.isIntersecting ) {
						observer?.unobserve( entry.target );
						create( entry.target as HTMLElement );
					}
				}
			},
			// Start a little before the player is on screen so its waveform is
			// already drawn by the time the reader gets to it.
			{ rootMargin: '200px 0px' }
		);
	}

	observer.observe( root );
}

export function scan( scope: ParentNode = document ): void {
	scope.querySelectorAll< HTMLElement >( SELECTOR ).forEach( observe );
	scope.querySelectorAll< HTMLElement >( PLAYLIST_SELECTOR ).forEach( wire );
}

/**
 * Give a playlist control of the player inside it.
 *
 * The list is already usable before this runs — every item is a link to its own
 * file — so this is an upgrade, not the feature. Which is why a failure to load
 * the chunk is survivable and silent.
 * @param root
 */
function wire( root: HTMLElement ): void {
	if ( initialised.has( root ) ) {
		return;
	}

	initialised.add( root );

	const host = root.querySelector< HTMLElement >( SELECTOR );

	if ( ! host ) {
		return;
	}

	const data = root.getAttribute( 'data-imagina-playlist' );

	if ( ! data ) {
		return;
	}

	let tracks: TrackChange[];

	try {
		tracks = JSON.parse( data ) as TrackChange[];
	} catch {
		return;
	}

	/*
	 * The player has to exist before the playlist can drive it, and it may
	 * not yet: a lazily initialised one waits to be scrolled to, and one for
	 * a YouTube or Vimeo video waits for its stand-in to arrive. Asking for
	 * it synchronously found nothing for those, and a list of YouTube videos
	 * never got its runtime at all.
	 */
	withPlayer( host, ( player ) => {
		import( /* webpackChunkName: "imagina-playlist" */ './playlist' )
			.then( ( { Playlist } ) => new Playlist( root, player, tracks ) )
			.catch( () => undefined );
	} );
}

/**
 * Run something against a player, building it first if it is still waiting
 * to be scrolled to.
 *
 * A timestamp button above the fold points at a player below it that a lazy
 * page has not built yet, and a provider video is built only once its
 * stand-in has arrived — so "the player for this root" is a promise, not a
 * lookup.
 * @param root
 * @param callback
 */
function withPlayer(
	root: HTMLElement,
	callback: ( player: Player ) => void
): void {
	const existing = players.get( root );

	if ( existing ) {
		callback( existing );

		return;
	}

	waiting.set( root, [ ...( waiting.get( root ) ?? [] ), callback ] );
	observer?.unobserve( root );
	create( root );
}

/**
 * Links to a moment and timestamp buttons live in their own chunk, fetched
 * the first time a page shows it needs them: an address carrying `t=`, or
 * a click on a button the shortcode wrote.
 */
function moments(): Promise< typeof import('./moments') > {
	return import( /* webpackChunkName: "imagina-moments" */ './moments' );
}

function bindMoments(): void {
	if ( /[?#&]t=/.test( window.location.search + window.location.hash ) ) {
		moments()
			.then( ( m ) => m.followDeepLink( withPlayer ) )
			.catch( () => undefined );
	}

	// Delegated, so buttons written by the shortcode, by a theme or added by
	// an AJAX load all work, and a button placed before its player finds it.
	document.addEventListener( 'click', ( event ) => {
		const button = (
			event.target as HTMLElement | null
		 )?.closest< HTMLElement >( '[data-imgp-time]' );

		if ( ! button ) {
			return;
		}

		event.preventDefault();
		moments()
			.then( ( m ) => m.pressTimestamp( button, withPlayer ) )
			.catch( () => undefined );
	} );
}

function boot(): void {
	scan();
	bindMoments();

	// Players injected later — infinite scroll, AJAX filters, the block editor
	// preview — are picked up without a second script.
	if ( 'MutationObserver' in window ) {
		new MutationObserver( ( mutations ) => {
			for ( const mutation of mutations ) {
				for ( const node of Array.from( mutation.addedNodes ) ) {
					if ( ! ( node instanceof HTMLElement ) ) {
						continue;
					}

					if ( node.matches( SELECTOR ) ) {
						observe( node );
					}

					if ( node.matches( PLAYLIST_SELECTOR ) ) {
						wire( node );
					}

					scan( node );
				}

				/*
				 * And the ones that left. Only additions were watched, so a
				 * player removed by the same infinite scroll or AJAX filter
				 * that added it kept its canvases, its decoded peaks and its
				 * listeners for the life of the page, and stayed in the set
				 * every play walks.
				 */
				for ( const node of Array.from( mutation.removedNodes ) ) {
					if ( ! ( node instanceof HTMLElement ) ) {
						continue;
					}

					const gone = node.matches( SELECTOR )
						? [ node ]
						: Array.from(
								node.querySelectorAll< HTMLElement >( SELECTOR )
						  );

					for ( const root of gone ) {
						players.get( root )?.destroy();
						players.delete( root );
					}
				}
			}
		} ).observe( document.body, { childList: true, subtree: true } );
	}
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
