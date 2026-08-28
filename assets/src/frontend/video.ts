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

import type { MediaCapabilities, PlayerMedia, VideoConfig } from './types';

/** How long a tap counts as part of a double tap. */
const DOUBLE_TAP_MS = 300;

/** Seconds a double tap at the edge of the picture seeks. */
const TAP_SEEK = 10;

/** Where the visitor's subtitle language is kept, across videos and visits. */
const CAPTION_KEY = 'imagina-player-captions';

interface Host {
	root: HTMLElement;
	/* The transport, which for a video on YouTube is a stand-in rather than an
	   element: everything about the picture works the same either way. */
	media: PlayerMedia;
	/* The element itself, when there is one. Full screen on the picture,
	   picture-in-picture, HLS and our own subtitles all need a real element,
	   and a provider video has none. */
	element: HTMLVideoElement | null;
	can: MediaCapabilities;
	i18n: Record< string, string >;
	/** The core's own play/pause, so both buttons agree on what toggling means. */
	toggle: () => void;
	seekBy: ( seconds: number ) => void;
	seekTo: ( seconds: number ) => void;
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

	private readonly media: PlayerMedia;

	/** Null for a video the provider serves; see `Host.element`. */
	private readonly element: HTMLVideoElement | null;

	private readonly can: MediaCapabilities;

	private readonly host: Host;

	private readonly config: VideoConfig;

	private readonly stage: HTMLElement | null;

	private idleTimer = 0;

	private lastTap = 0;

	private readonly cleanup: Array< () => void > = [];

	/** Removes the menu's outside-click and Escape listeners, when one is open. */
	private dismissMenu: ( () => void ) | null = null;

	/** Set only when hls.js took over playback; Safari never gets one. */
	private hls: { destroy: () => void } | null = null;

	constructor( host: Host, config: VideoConfig ) {
		this.host = host;
		this.root = host.root;
		this.media = host.media;
		this.element = host.element;
		this.can = host.can;
		this.config = config;
		this.stage = this.root.querySelector< HTMLElement >( '.imgp__stage' );

		this.bindBigPlay();
		this.bindState();
		this.bindChromeVisibility();
		this.bindFullscreen();
		this.bindPictureInPicture();
		this.bindKeyboard();
		this.bindGestures();
		this.bindCaptions();
		this.bindChapters();
		this.bindStoryboard();
		this.hardenContextMenu();

		if ( config.hls ) {
			void this.setupHls();
		}
	}

	/**
	 * Adaptive streaming, if this is a stream and the browser needs help.
	 *
	 * A chunk of its own again, because hls.js is around 400 KB — more than
	 * twenty times the rest of the player — and most videos are a plain MP4
	 * that will never touch it.
	 */
	private async setupHls(): Promise< void > {
		const element = this.element;

		// hls.js feeds a MediaSource into an element. A provider serves its own
		// adaptive stream inside its own frame, so there is nothing here to do.
		if ( ! element ) {
			return;
		}

		try {
			const { HlsStream } = await import(
				/* webpackChunkName: "imagina-hls-glue" */ './hls'
			);

			const stream = new HlsStream( {
				root: this.root,
				media: element,
				i18n: this.host.i18n,
				menu: ( items ) => this.openMenu( items ),
			} );

			if ( await stream.attach( element.currentSrc || element.src ) ) {
				this.hls = stream;
			}
		} catch {
			// The element keeps its own src, so Safari — which plays HLS by
			// itself — is unaffected, and anywhere else the error state the core
			// player already handles takes over.
		}
	}

	destroy(): void {
		window.clearTimeout( this.idleTimer );
		this.dismissMenu?.();
		this.hls?.destroy();

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

		/*
		 * The iPhone fallback only exists for a real element. A provider frame
		 * has no `webkitEnterFullscreen`, so on an iPhone a YouTube video goes
		 * full screen through YouTube's own button inside the frame instead —
		 * which is the only thing that works there.
		 */
		const media = ( this.can.elementFullscreen ? this.element : null ) as
			| ( HTMLVideoElement & WebkitFullscreen )
			| null;

		// iPhone Safari has no element fullscreen at all: only the video element
		// can go full screen, with its own native controls. Better than a button
		// that silently does nothing.
		const supported =
			document.fullscreenEnabled ||
			'webkitRequestFullscreen' in element ||
			( !! media && 'webkitEnterFullscreen' in media );

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
			} else if ( media?.webkitEnterFullscreen ) {
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
		const element = this.element;

		if (
			! element ||
			! this.can.pictureInPicture ||
			! ( 'pictureInPictureEnabled' in document ) ||
			! document.pictureInPictureEnabled ||
			element.disablePictureInPicture
		) {
			return;
		}

		button.hidden = false;

		this.on( button, 'click', () => {
			if ( document.pictureInPictureElement ) {
				void document.exitPictureInPicture().catch( () => undefined );

				return;
			}

			void element.requestPictureInPicture().catch( () => undefined );
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
	 * The panel both menus share.
	 *
	 * Built here rather than rendered by PHP because its contents depend on what
	 * the browser reports — a subtitle track that failed to load should not be
	 * offered — and because two menus that never open together do not need two
	 * containers.
	 * @param items
	 */
	private openMenu(
		items: Array< { label: string; active: boolean; onPick: () => void } >
	): void {
		const menu = this.root.querySelector< HTMLElement >( '.imgp__menu' );

		if ( ! menu ) {
			return;
		}

		if ( ! menu.hidden ) {
			this.closeMenu();

			return;
		}

		menu.textContent = '';

		for ( const item of items ) {
			const button = this.root.ownerDocument.createElement( 'button' );

			button.type = 'button';
			button.className = 'imgp__menuitem';
			button.setAttribute( 'role', 'menuitemradio' );
			button.setAttribute(
				'aria-checked',
				item.active ? 'true' : 'false'
			);
			button.textContent = item.label;

			button.addEventListener( 'click', () => {
				item.onPick();
				this.closeMenu();
			} );

			menu.appendChild( button );
		}

		menu.hidden = false;
		menu.querySelector< HTMLButtonElement >( 'button' )?.focus();

		// One dismissal path for a click anywhere else and for Escape, removed
		// as soon as it fires so these never pile up.
		const dismiss = ( event: Event ): void => {
			if ( event instanceof KeyboardEvent && 'Escape' !== event.key ) {
				return;
			}

			if (
				event instanceof PointerEvent &&
				( event.target as HTMLElement | null )?.closest(
					'.imgp__vcontrols'
				)
			) {
				return;
			}

			this.closeMenu();
		};

		this.dismissMenu = () => {
			this.root.ownerDocument.removeEventListener(
				'pointerdown',
				dismiss
			);
			this.root.ownerDocument.removeEventListener( 'keydown', dismiss );
		};

		this.root.ownerDocument.addEventListener( 'pointerdown', dismiss );
		this.root.ownerDocument.addEventListener( 'keydown', dismiss );
	}

	private closeMenu(): void {
		const menu = this.root.querySelector< HTMLElement >( '.imgp__menu' );

		if ( menu ) {
			menu.hidden = true;
			menu.textContent = '';
		}

		this.dismissMenu?.();
		this.dismissMenu = null;
	}

	/**
	 * Subtitles.
	 *
	 * The tracks are already in the DOM — the server put them there, so they
	 * work without this file at all. What this adds is a way to change them, and
	 * a memory: a visitor who turns Spanish subtitles on once should not have to
	 * do it again on the next video.
	 */
	private bindCaptions(): void {
		const button = this.root.querySelector< HTMLButtonElement >(
			'.imgp__vbtn--captions'
		);

		if ( ! button ) {
			return;
		}

		const tracks = this.subtitleTracks();

		if ( 0 === tracks.length ) {
			return;
		}

		button.hidden = false;

		// A track marked `default` is showing already; anything else starts off.
		for ( const track of tracks ) {
			track.mode = 'showing' === track.mode ? 'showing' : 'disabled';
		}

		const remembered = this.remembered();

		if ( remembered ) {
			this.showTrack( tracks, remembered );
		}

		this.syncCaptionButton();

		this.on( button, 'click', () => {
			const off = this.i18n( 'captionsOff', 'Off' );

			this.openMenu( [
				{
					label: off,
					active: ! tracks.some(
						( track ) => 'showing' === track.mode
					),
					onPick: () => {
						this.showTrack( tracks, null );
						this.remember( '' );
					},
				},
				...tracks.map( ( track ) => ( {
					label: track.label || track.language || off,
					active: 'showing' === track.mode,
					onPick: () => {
						this.showTrack( tracks, track.language || track.label );
						this.remember( track.language || track.label );
					},
				} ) ),
			] );
		} );
	}

	/** Every subtitle track, in DOM order, chapters excluded. */
	private subtitleTracks(): TextTrack[] {
		const tracks: TextTrack[] = [];
		const element = this.element;

		// A provider draws its own subtitles inside its own frame and will not
		// hand the text over, so there is no track list here to offer.
		if ( ! element || ! this.can.captions ) {
			return tracks;
		}

		for ( let i = 0; i < element.textTracks.length; i++ ) {
			const track = element.textTracks[ i ];

			if ( 'subtitles' === track.kind || 'captions' === track.kind ) {
				tracks.push( track );
			}
		}

		return tracks;
	}

	/**
	 * Exactly one showing, or none.
	 * @param tracks
	 * @param wanted
	 */
	private showTrack( tracks: TextTrack[], wanted: string | null ): void {
		for ( const track of tracks ) {
			const matches =
				null !== wanted &&
				'' !== wanted &&
				( track.language === wanted || track.label === wanted );

			track.mode = matches ? 'showing' : 'disabled';
		}

		this.syncCaptionButton();
	}

	private syncCaptionButton(): void {
		const button = this.root.querySelector< HTMLButtonElement >(
			'.imgp__vbtn--captions'
		);
		const on = this.subtitleTracks().some(
			( track ) => 'showing' === track.mode
		);

		button?.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		button?.classList.toggle( 'is-active', on );
	}

	/**
	 * The subtitle choice, remembered across videos and visits.
	 *
	 * Site-wide rather than per video, because the thing being remembered is a
	 * preference about the person, not about the file.
	 */
	private remembered(): string | null {
		try {
			return window.localStorage.getItem( CAPTION_KEY );
		} catch {
			return null;
		}
	}

	private remember( language: string ): void {
		try {
			window.localStorage.setItem( CAPTION_KEY, language );
		} catch {
			// Private browsing, or storage turned off. Not worth a word.
		}
	}

	/**
	 * Chapters: markers on the scrub bar, and a menu to jump between them.
	 *
	 * The markers are the point. A menu is a list of times; a marked scrub bar
	 * shows a viewer the shape of what they are about to watch without opening
	 * anything.
	 */
	/**
	 * The still that follows the pointer along the scrub bar.
	 *
	 * A bar without one is a guess: a reader looking for the moment the slide
	 * changes has to drop the playhead somewhere and correct, twice, with the
	 * sound jumping each time.
	 *
	 * Nothing is fetched until a pointer is actually on the bar — a reader who
	 * never scrubs never downloads the sprite, which on a long video is most of
	 * what this costs — and the parser is in its own chunk for the same reason.
	 */
	private bindStoryboard(): void {
		const src = this.config.storyboard;
		const seek = this.root.querySelector< HTMLElement >( '.imgp__seek' );

		if ( ! src || ! seek ) {
			return;
		}

		const preview = document.createElement( 'div' );

		preview.className = 'imgp__preview';
		preview.hidden = true;
		seek.appendChild( preview );

		let board: import('./storyboard').Storyboard | null = null;
		let asked = false;
		let paint: typeof import('./storyboard').paint | null = null;

		const move = ( event: PointerEvent ): void => {
			if ( ! asked ) {
				asked = true;

				void import(
					/* webpackChunkName: "imagina-storyboard" */ './storyboard'
				)
					.then( async ( module ) => {
						paint = module.paint;
						board = await module.load( src );
					} )
					.catch( () => {
						// No storyboard, no preview. The bar still works.
					} );
			}

			const duration = this.media.duration;

			if (
				! board ||
				! paint ||
				! Number.isFinite( duration ) ||
				duration <= 0
			) {
				return;
			}

			const box = seek.getBoundingClientRect();
			const ratio = Math.min(
				1,
				Math.max( 0, ( event.clientX - box.left ) / box.width )
			);
			const tile = board.at( ratio * duration );

			if ( ! tile ) {
				preview.hidden = true;

				return;
			}

			paint( preview, tile );
			preview.hidden = false;

			// Held inside the bar, so a still near either end does not hang off
			// the side of the picture.
			const half = tile.w / 2;
			const left = Math.min(
				box.width - half,
				Math.max( half, ratio * box.width )
			);

			preview.style.left = `${ left }px`;
		};

		const leave = (): void => {
			preview.hidden = true;
		};

		this.on( seek, 'pointermove', move as EventListener );
		this.on( seek, 'pointerleave', leave );
	}

	private bindChapters(): void {
		const chapters = this.config.chapters ?? [];

		if ( 0 === chapters.length ) {
			return;
		}

		this.paintMarkers( chapters );

		const button = this.root.querySelector< HTMLButtonElement >(
			'.imgp__vbtn--chapters'
		);

		if ( ! button ) {
			return;
		}

		button.hidden = false;

		this.on( button, 'click', () => {
			const now = this.media.currentTime;

			this.openMenu(
				chapters.map( ( chapter, index ) => {
					const next = chapters[ index + 1 ];

					return {
						label: chapter.title,
						active:
							now >= chapter.start &&
							( ! next || now < next.start ),
						onPick: () => this.host.seekTo( chapter.start ),
					};
				} )
			);
		} );
	}

	/**
	 * Ticks at each chapter boundary.
	 *
	 * Percentages, so they stay put when the player is resized, and skipped
	 * entirely until the duration is known — placing a marker against a duration
	 * of NaN puts every one of them at the left edge.
	 * @param chapters
	 */
	private paintMarkers(
		chapters: Array< { start: number; title: string } >
	): void {
		const scrubber =
			this.root.querySelector< HTMLElement >( '.imgp__scrubber' );

		if ( ! scrubber ) {
			return;
		}

		const paint = (): void => {
			const duration = this.media.duration;

			if ( ! Number.isFinite( duration ) || duration <= 0 ) {
				return;
			}

			scrubber.querySelector( '.imgp__marks' )?.remove();

			const marks = this.root.ownerDocument.createElement( 'div' );

			marks.className = 'imgp__marks';
			marks.setAttribute( 'aria-hidden', 'true' );

			for ( const chapter of chapters ) {
				if ( chapter.start <= 0 || chapter.start >= duration ) {
					continue;
				}

				const mark = this.root.ownerDocument.createElement( 'span' );

				mark.className = 'imgp__mark';
				mark.style.left = ( chapter.start / duration ) * 100 + '%';
				mark.title = chapter.title;

				marks.appendChild( mark );
			}

			scrubber.appendChild( marks );
		};

		paint();
		this.on( this.media, 'loadedmetadata', paint );
		this.on( this.media, 'durationchange', paint );
	}

	private i18n( key: string, fallback: string ): string {
		return this.host.i18n[ key ] ?? fallback;
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
		// A provider's frame has its own context menu and this page cannot
		// touch it; there is also no file of ours behind it to protect.
		if ( ! this.element || ! this.element.hasAttribute( 'controlslist' ) ) {
			// Downloading is allowed for this player; do not pretend otherwise.
			return;
		}

		this.on( this.media, 'contextmenu', ( event: MouseEvent ) =>
			event.preventDefault()
		);
	}
}

export type { Host as VideoHost };
