/**
 * Everything a video needs and audio does not.
 *
 * This file is deliberately reachable only through a dynamic `import()` from
 * the player core, so webpack emits it as its own chunk. A page with nothing
 * but audio players never requests it — which is the whole point, because
 * PageSpeed measures what a page actually downloads, not what it might.
 *
 * It attaches to a player that is already running: seeking, volume, position
 * memory and the signed-link refresh all belong to the core and work the same
 * for both media. What lives here is the picture: the poster, the play button
 * in the middle, controls that fade, full screen, picture-in-picture, the
 * keyboard and touch.
 */

import type { VideoConfig } from './types';

/** How long a tap counts as part of a double tap. */
const DOUBLE_TAP_MS = 300;

/** Seconds a double tap at the edge of the picture seeks. */
const TAP_SEEK = 10;

interface Host {
	root: HTMLElement;
	media: HTMLVideoElement;
	i18n: Record< string, string >;
	/** The core's own play/pause, so both buttons agree on what toggling means. */
	toggle: () => void;
	seekBy: ( seconds: number ) => void;
}

/**
 * A browser vendor-prefixed enough to still need this in 2026: Safari.
 */
interface WebkitFullscreen {
	webkitRequestFullscreen?: () => Promise< void > | void;
	webkitExitFullscreen?: () => Promise< void > | void;
	webkitDisplayingFullscreen?: boolean;
	webkitEnterFullscreen?: () => void;
}

export class VideoChrome {
	private readonly root: HTMLElement;

	private readonly media: HTMLVideoElement;

	private readonly host: Host;

	private readonly config: VideoConfig;

	private readonly stage: HTMLElement | null;

	private idleTimer = 0;

	private lastTap = 0;

	private readonly cleanup: Array< () => void > = [];

	constructor( host: Host, config: VideoConfig ) {
		this.host = host;
		this.root = host.root;
		this.media = host.media;
		this.config = config;
		this.stage = this.root.querySelector< HTMLElement >( '.imgp__stage' );

		this.bindBigPlay();
		this.bindState();
		this.bindChromeVisibility();
		this.bindFullscreen();
		this.bindPictureInPicture();
		this.bindKeyboard();
		this.bindGestures();
		this.hardenContextMenu();
	}

	destroy(): void {
		window.clearTimeout( this.idleTimer );

		for ( const off of this.cleanup ) {
			off();
		}

		this.cleanup.length = 0;
	}

	/**
	 * Register a listener and remember how to remove it.
	 * @param target
	 * @param type
	 * @param handler
	 * @param options
	 */
	private on< K extends keyof HTMLElementEventMap >(
		target: EventTarget,
		type: K | string,
		handler: ( event: never ) => void,
		options?: AddEventListenerOptions
	): void {
		const listener = handler as EventListener;

		target.addEventListener( type, listener, options );
		this.cleanup.push( () =>
			target.removeEventListener( type, listener, options )
		);
	}

	private bindBigPlay(): void {
		const button =
			this.root.querySelector< HTMLButtonElement >( '.imgp__bigplay' );

		if ( ! button ) {
			return;
		}

		this.on( button, 'click', ( event: MouseEvent ) => {
			event.preventDefault();
			this.host.toggle();
		} );
	}

	/**
	 * Classes the stylesheet reacts to. `is-started` is one-way on purpose: the
	 * poster comes back on a replay in no player anyone expects.
	 */
	private bindState(): void {
		const started = (): void => this.root.classList.add( 'is-started' );

		this.on( this.media, 'play', started );
		this.on( this.media, 'playing', started );

		if ( ! this.media.paused ) {
			started();
		}
	}

	/**
	 * The controls fade while the video plays and nothing is happening, and come
	 * straight back on any sign of a person. They never fade while paused —
	 * a paused video with no controls looks broken — and never while the
	 * keyboard is inside them, or a keyboard user would lose their place.
	 */
	private bindChromeVisibility(): void {
		const wake = (): void => {
			window.clearTimeout( this.idleTimer );
			this.root.classList.remove( 'is-chrome-idle' );

			if ( this.media.paused ) {
				return;
			}

			this.idleTimer = window.setTimeout( () => {
				if (
					this.root.contains( this.root.ownerDocument.activeElement )
				) {
					return;
				}

				this.root.classList.add( 'is-chrome-idle' );
			}, this.config.hideAfter );
		};

		for ( const type of [
			'pointermove',
			'pointerdown',
			'focusin',
			'play',
			'pause',
		] ) {
			this.on( this.root, type, wake );
		}

		this.on( this.root, 'pointerleave', () => {
			if ( ! this.media.paused ) {
				this.root.classList.add( 'is-chrome-idle' );
			}
		} );
	}

	private bindFullscreen(): void {
		const button = this.root.querySelector< HTMLButtonElement >(
			'.imgp__vbtn--fullscreen'
		);

		if ( ! button ) {
			return;
		}

		const element = this.root as HTMLElement & WebkitFullscreen;
		const media = this.media as HTMLVideoElement & WebkitFullscreen;

		// iPhone Safari has no element fullscreen at all: only the video element
		// can go full screen, with its own native controls. Better than a button
		// that silently does nothing.
		const supported =
			document.fullscreenEnabled ||
			'webkitRequestFullscreen' in element ||
			'webkitEnterFullscreen' in media;

		if ( ! supported ) {
			return;
		}

		button.hidden = false;

		this.on( button, 'click', () => {
			if ( document.fullscreenElement === this.root ) {
				void document.exitFullscreen();

				return;
			}

			if ( document.fullscreenElement ) {
				void document.exitFullscreen();
			}

			if ( element.requestFullscreen ) {
				void element.requestFullscreen().catch( () => undefined );
			} else if ( element.webkitRequestFullscreen ) {
				void element.webkitRequestFullscreen();
			} else if ( media.webkitEnterFullscreen ) {
				media.webkitEnterFullscreen();
			}
		} );

		this.on( document, 'fullscreenchange', () => {
			this.root.classList.toggle(
				'is-fullscreen',
				document.fullscreenElement === this.root
			);
		} );
	}

	private bindPictureInPicture(): void {
		const button =
			this.root.querySelector< HTMLButtonElement >( '.imgp__vbtn--pip' );

		if ( ! button ) {
			return;
		}

		// `disablePictureInPicture` is set by the renderer when the file is meant
		// to stay put; honouring it here keeps one decision in one place.
		if (
			! ( 'pictureInPictureEnabled' in document ) ||
			! document.pictureInPictureEnabled ||
			this.media.disablePictureInPicture
		) {
			return;
		}

		button.hidden = false;

		this.on( button, 'click', () => {
			if ( document.pictureInPictureElement ) {
				void document.exitPictureInPicture().catch( () => undefined );

				return;
			}

			void this.media.requestPictureInPicture().catch( () => undefined );
		} );
	}

	/**
	 * The shortcuts every video player has.
	 *
	 * Scoped to the player, never global: a page can hold several, and a site
	 * has a search box. Presto ships with these switched off entirely so they
	 * would not collide with its email form — the collision is real, and the
	 * answer is to check the target, not to abandon the keyboard.
	 */
	private bindKeyboard(): void {
		this.on( this.root, 'keydown', ( event: KeyboardEvent ) => {
			const target = event.target as HTMLElement | null;

			if (
				target &&
				( target.isContentEditable ||
					/^(INPUT|TEXTAREA|SELECT)$/.test( target.tagName ) )
			) {
				return;
			}

			// The space bar on a focused button is that button's business.
			if ( ' ' === event.key && target instanceof HTMLButtonElement ) {
				return;
			}

			const handled = this.shortcut( event.key );

			if ( handled ) {
				event.preventDefault();
			}
		} );
	}

	private shortcut( key: string ): boolean {
		switch ( key ) {
			case ' ':
			case 'k':
			case 'K':
				this.host.toggle();

				return true;
			case 'ArrowLeft':
			case 'j':
			case 'J':
				this.host.seekBy( -TAP_SEEK );

				return true;
			case 'ArrowRight':
			case 'l':
			case 'L':
				this.host.seekBy( TAP_SEEK );

				return true;
			case 'm':
			case 'M':
				this.media.muted = ! this.media.muted;

				return true;
			case 'f':
			case 'F':
				this.root
					.querySelector< HTMLButtonElement >(
						'.imgp__vbtn--fullscreen'
					)
					?.click();

				return true;
			case 'ArrowUp':
				this.media.volume = Math.min( 1, this.media.volume + 0.1 );

				return true;
			case 'ArrowDown':
				this.media.volume = Math.max( 0, this.media.volume - 0.1 );

				return true;
			default:
				return false;
		}
	}

	/**
	 * Touch: one tap shows or hides the controls, two taps at an edge seek.
	 *
	 * Deliberately not "one tap plays": on a phone the first tap is how you find
	 * out what the controls even are, and a tap that starts playback while the
	 * finger was reaching for the scrub bar is the most annoying thing a video
	 * player does.
	 */
	private bindGestures(): void {
		if ( ! this.stage ) {
			return;
		}

		this.on( this.stage, 'pointerup', ( event: PointerEvent ) => {
			if ( 'touch' !== event.pointerType ) {
				return;
			}

			const target = event.target as HTMLElement | null;

			// A tap that landed on a control is that control's.
			if ( target?.closest( '.imgp__chrome, .imgp__layers' ) ) {
				return;
			}

			const now = Date.now();

			if ( now - this.lastTap < DOUBLE_TAP_MS ) {
				const bounds = this.stage!.getBoundingClientRect();
				const third = bounds.width / 3;
				const x = event.clientX - bounds.left;

				if ( x < third ) {
					this.host.seekBy( -TAP_SEEK );
				} else if ( x > bounds.width - third ) {
					this.host.seekBy( TAP_SEEK );
				} else {
					this.host.toggle();
				}

				this.lastTap = 0;

				return;
			}

			this.lastTap = now;
			this.root.classList.toggle( 'is-chrome-idle' );
		} );
	}

	/**
	 * Take the browser's own "Save video as…" off the menu.
	 *
	 * This stops a right-click and nothing more: anyone who opens the developer
	 * tools still sees the URL, and a screen recorder always works. It is worth
	 * doing anyway because it is the path almost everyone takes, and because
	 * neither Presto nor Fluent does even this for a self-hosted file. The
	 * protection that matters is that the URL expires and the file is not in a
	 * public folder — this is the layer above that, not a substitute for it.
	 */
	private hardenContextMenu(): void {
		if ( ! this.media.hasAttribute( 'controlslist' ) ) {
			// Downloading is allowed for this player; do not pretend otherwise.
			return;
		}

		this.on( this.media, 'contextmenu', ( event: MouseEvent ) =>
			event.preventDefault()
		);
	}
}

export type { Host as VideoHost };
