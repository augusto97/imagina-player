import { Player } from './player';
import type { RuntimeData } from './types';
import './style.scss';

const SELECTOR = '[data-imagina-player]';

const initialised = new WeakSet< HTMLElement >();

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

	try {
		// eslint-disable-next-line no-new
		new Player( root, runtime() );
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

					node.querySelectorAll< HTMLElement >( SELECTOR ).forEach(
						observe
					);
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
