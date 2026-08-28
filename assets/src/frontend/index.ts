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
	const config = JSON.parse( root.dataset.imaginaPlayer || '{}' ) as {
		video?: VideoConfig;
	};

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

		build( root, createProviderMedia( root, video ) );
	} catch ( error ) {
		// The still image and the link under it are already in the page, so a
		// failure here leaves something that works rather than a blank box.
		if ( window.console ) {
			window.console.warn( 'Imagina Player:', error );
		}
	}
}

function build(
	root: HTMLElement,
	standIn: ConstructorParameters< typeof Player >[ 2 ]
): void {
	try {
		players.set( root, new Player( root, runtime(), standIn ) );
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

	// The player has to exist before the playlist can drive it, and a lazily
	// initialised one does not until it is scrolled to.
	create( host );

	const player = players.get( host );
	const data = root.getAttribute( 'data-imagina-playlist' );

	if ( ! player || ! data ) {
		return;
	}

	let tracks: TrackChange[];

	try {
		tracks = JSON.parse( data ) as TrackChange[];
	} catch {
		return;
	}

	import( /* webpackChunkName: "imagina-playlist" */ './playlist' )
		.then( ( { Playlist } ) => new Playlist( root, player, tracks ) )
		.catch( () => undefined );
}

function boot(): void {
	scan();

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
			}
		} ).observe( document.body, { childList: true, subtree: true } );
	}
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
