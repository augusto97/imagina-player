/**
 * The player that follows the reader.
 *
 * Its own chunk: only a player the author set to follow ever needs it, and
 * the core every page loads should not carry it for the rest.
 *
 * It floats only while something is playing — a paused player pinning itself
 * to the corner because somebody scrolled past it is an advert — and it goes
 * home when the reader scrolls back to where it was.
 */

import type { PlayerMedia } from './types';

interface Host {
	root: HTMLElement;
	media: PlayerMedia;
	element: HTMLMediaElement | null;
	/** Told after the player docks or leaves, so it can redraw at its new size. */
	changed: () => void;
}

export class Sticky {
	private readonly host: Host;

	private placeholder: HTMLElement | null = null;

	private readonly observer: IntersectionObserver;

	private dismissed = false;

	private destroyed = false;

	private readonly cleanup: Array< () => void > = [];

	constructor( host: Host ) {
		this.host = host;

		const unstick =
			host.root.querySelector< HTMLButtonElement >( '.imgp__unstick' );
		const dismiss = (): void => {
			/*
			 * For the rest of this page view. Sending it back only for it to
			 * return on the next scroll is not dismissing it, and a floating
			 * player that cannot be got rid of is the thing people actually
			 * dislike about floating players.
			 */
			this.dismissed = true;
			this.set( false );
		};

		unstick?.addEventListener( 'click', dismiss );
		this.cleanup.push(
			() => unstick?.removeEventListener( 'click', dismiss )
		);

		this.observer = new IntersectionObserver( () => this.review(), {
			threshold: 0,
		} );
		this.observer.observe( host.root );

		/*
		 * Playing and pausing have to be asked as well. An observer only fires
		 * when the intersection *changes*, so a player already out of view
		 * when playback starts — a playlist carrying on, a keyboard shortcut,
		 * an autoplaying video below the fold — would never be reconsidered.
		 */
		const review = (): void => this.review();

		for ( const type of [ 'play', 'pause', 'ended' ] ) {
			host.media.addEventListener( type, review );
			this.cleanup.push( () =>
				host.media.removeEventListener( type, review )
			);
		}
	}

	/** Back into the page, whatever is happening. */
	release(): void {
		this.set( false );
	}

	destroy(): void {
		this.destroyed = true;
		// A player taken off the page while floating left its placeholder,
		// holding open a gap where nothing would ever return.
		this.set( false );
		this.observer.disconnect();

		for ( const off of this.cleanup ) {
			off();
		}

		this.cleanup.length = 0;
	}

	/**
	 * Decide whether the player should be following the reader.
	 *
	 * Measured here rather than remembered from the observer, which reports
	 * changes: a player already off screen when the page loaded has never
	 * been reported at all.
	 */
	private review(): void {
		// Full screen and picture-in-picture are already somewhere else; a
		// floating card behind either is a second copy nobody can see.
		const pip = (
			document as Document & {
				pictureInPictureElement?: Element | null;
			}
		 ).pictureInPictureElement;
		const elsewhere =
			!! document.fullscreenElement ||
			( !! this.host.element && pip === this.host.element );

		if ( this.dismissed || this.host.media.paused || elsewhere ) {
			this.set( false );

			return;
		}

		/*
		 * Where the player sits, or where it would sit if it were not
		 * floating: once detached, its own rectangle is the floating card,
		 * which is always on screen.
		 */
		const box = (
			this.placeholder ?? this.host.root
		).getBoundingClientRect();
		const height =
			window.innerHeight || document.documentElement.clientHeight;

		this.set( box.bottom <= 0 || box.top >= height );
	}

	private set( stuck: boolean ): void {
		const root = this.host.root;

		if ( stuck === root.classList.contains( 'is-stuck' ) ) {
			return;
		}

		if ( stuck ) {
			// Measured before the class lands, because the class is what makes
			// the player small.
			const height = root.offsetHeight;
			const placeholder = document.createElement( 'div' );

			placeholder.className = 'imgp__sticky-placeholder';
			placeholder.style.height = `${ height }px`;
			root.after( placeholder );
			root.classList.add( 'is-stuck' );
			this.placeholder = placeholder;

			/*
			 * Watch the gap, not the player. Once floating, the player is
			 * always on screen, so watching it never reported the reader
			 * scrolling back — and it stayed in the corner beside its own
			 * empty slot until it was paused.
			 */
			this.observer.unobserve( root );
			this.observer.observe( placeholder );
		} else {
			if ( this.placeholder ) {
				this.observer.unobserve( this.placeholder );
			}

			this.placeholder?.remove();
			this.placeholder = null;
			root.classList.remove( 'is-stuck' );

			if ( ! this.destroyed ) {
				this.observer.observe( root );
			}
		}

		this.host.changed();
	}
}
