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

import type {
	AudioChoices,
	MediaCapabilities,
	PlayerMedia,
	VideoConfig,
} from './types';

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
	/** The core's own rate change, so the audio-style button stays in step. */
	setSpeed: ( rate: number ) => void;
	speed: () => number;
	/** Ask for a fresh signed address, when a stream reports its own expired. */
	refreshSource?: () => void;
	/** Another file for the same video, from the same moment. */
	switchSource: ( src: string, protectedId: number ) => void;
}

/** What the speed list offers. */
const SPEED_CHOICES = [ 0.5, 0.75, 1, 1.25, 1.5, 1.75, 2 ];

/** How long "Link copied" stays on the picture. */
const TOAST_FOR = 1800;

/** How far from the middle of the big button a finger still means it. */
const DISC_REACH = 48;

/**
 * Whether a key pressed on this element is that element's, not the player's.
 *
 * One rule for every place a key can land. The scrub bar handles its own
 * arrows and space — and both it and the player acted, so a left arrow sought
 * fifteen seconds and space played and paused in the same instant. A
 * `<summary>` opens on space; a link or a button activates on Enter; a menu
 * walks its list with the arrows; a form inside an overlay, or another
 * plugin's output, is typed into.
 * @param target
 * @param key
 */
function ownsKey( target: HTMLElement | null, key: string ): boolean {
	if ( ! target ) {
		return false;
	}

	if (
		target.isContentEditable ||
		/^(INPUT|TEXTAREA|SELECT)$/.test( target.tagName )
	) {
		return true;
	}

	if (
		target.closest(
			'[role="slider"], [role="menu"], .imgp__menu, .imgp__layer, .imgp__transcript'
		)
	) {
		return true;
	}

	return (
		( ' ' === key || 'Enter' === key ) &&
		!! target.closest(
			'button, a[href], summary, [role="button"], [role^="menuitem"]'
		)
	);
}

interface MenuItem {
	label: string;
	active: boolean;
	onPick: () => void;
	/** An action rather than one of a set of choices: no checked state. */
	action?: boolean;
	/** Leave the menu open after picking: the item opened another list in it. */
	keepOpen?: boolean;
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

	/** The button whose list is in the panel, while one is open. */
	private menuOwner: HTMLElement | null = null;

	/** Set only when hls.js took over playback; Safari never gets one. */
	private hls: {
		destroy: () => void;
		replaceSource: ( url: string ) => void;
	} | null = null;

	private toastTimer = 0;

	private destroyed = false;

	/** Soundtracks to offer in the gear, once something has found two. */
	private audio: AudioChoices | null = null;

	/** Other files for this video, when it has any. */
	private sources: { destroy: () => void } | null = null;

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
		this.bindMeasure();
		this.bindChromeVisibility();
		this.bindFullscreen();
		this.bindPictureInPicture();
		this.bindKeyboard();
		this.bindGestures();
		this.bindCaptions();
		this.bindChapters();
		this.bindSettings();
		this.bindStoryboard();
		this.bindSearch();
		this.bindTranscript();
		this.bindFocusMode();
		this.bindViewerMark();
		this.hardenContextMenu();

		if ( config.hls ) {
			void this.setupHls();
		}

		if ( config.qualities || config.dubs ) {
			void this.setupSources();
		}
	}

	/**
	 * Other qualities and soundtracks — files beside the video, or a stream's
	 * own soundtracks where Safari plays it. A chunk of its own: most videos
	 * have neither.
	 * @param native The browser is playing a stream by itself.
	 */
	private async setupSources( native = false ): Promise< void > {
		const element = this.element;

		if ( ! element ) {
			return;
		}

		try {
			const { Sources } = await import(
				/* webpackChunkName: "imagina-sources" */ './sources'
			);

			if ( ! this.destroyed ) {
				this.sources = new Sources(
					{
						root: this.root,
						element,
						menu: ( items, opener ) =>
							this.openMenu( items, opener ),
						switchSource: this.host.switchSource,
						audio: ( choices ) => {
							this.audio = choices;
						},
					},
					this.config,
					native
				);
			}
		} catch {
			// The main file and its own sound play on.
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
				menu: ( items, opener ) => this.openMenu( items, opener ),
				expired: () => this.host.refreshSource?.(),
				audio: ( choices ) => {
					this.audio = choices;
				},
			} );

			if ( this.destroyed ) {
				stream.destroy();

				return;
			}

			if ( await stream.attach( element.currentSrc || element.src ) ) {
				this.hls = stream;
			} else {
				// Safari plays the stream itself and lists its soundtracks.
				void this.setupSources( true );
			}
		} catch {
			// The element keeps its own src, so Safari — which plays HLS by
			// itself — is unaffected, and anywhere else the error state the core
			// player already handles takes over.
		}
	}

	/**
	 * Hand a stream a fresh signed address, when one is playing.
	 *
	 * @return Whether there was a stream to take it.
	 * @param url
	 */
	replaceSource( url: string ): boolean {
		if ( ! this.hls ) {
			return false;
		}

		this.hls.replaceSource( url );

		return true;
	}

	destroy(): void {
		this.destroyed = true;
		window.clearTimeout( this.idleTimer );
		window.clearTimeout( this.toastTimer );
		this.dismissMenu?.();
		this.hls?.destroy();
		this.sources?.destroy();

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

		let finger = false;
		let shown = false;

		this.on( button, 'pointerdown', ( event: PointerEvent ) => {
			finger = 'touch' === event.pointerType;
			shown = ! this.root.classList.contains( 'is-chrome-idle' );
		} );

		this.on( button, 'click', ( event: MouseEvent ) => {
			event.preventDefault();

			/*
			 * The button covers the whole picture, so on a phone every tap on
			 * a playing video paused it — the tap meant to bring the controls
			 * back included. While it plays, a finger pauses only on the disc
			 * in the middle, and only when the controls were already up to
			 * show it; anywhere else the tap is for the controls.
			 */
			if ( finger && ! this.media.paused ) {
				const box = button.getBoundingClientRect();
				const off = Math.hypot(
					event.clientX - ( box.left + box.width / 2 ),
					event.clientY - ( box.top + box.height / 2 )
				);

				finger = false;

				if ( ! shown || off > DISC_REACH ) {
					return;
				}
			}

			this.host.toggle();
		} );
	}

	/**
	 * Sizes the stylesheet cannot know: how tall the bar is, how tall the
	 * picture is, and how tall the title over it.
	 *
	 * Subtitles are lifted by the bar's height and menus fit the picture by
	 * it; both were fixed numbers, so the words sat on the controls and a
	 * menu on a phone ran off the top of the picture.
	 */
	private bindMeasure(): void {
		const chrome =
			this.root.querySelector< HTMLElement >( '.imgp__chrome' );

		if ( ! chrome || 'undefined' === typeof ResizeObserver ) {
			return;
		}

		const from =
			chrome.querySelector< HTMLElement >( '.imgp__scrubber' ) ?? chrome;
		const top = this.root.querySelector< HTMLElement >( '.imgp__top' );
		const style = this.root.style;

		const measure = (): void => {
			style.setProperty(
				'--imgp-chrome-h',
				`${ Math.round(
					chrome.getBoundingClientRect().bottom -
						from.getBoundingClientRect().top
				) }px`
			);

			if ( this.stage ) {
				style.setProperty(
					'--imgp-stage-h',
					`${ this.stage.clientHeight }px`
				);
			}

			if ( top ) {
				style.setProperty( '--imgp-top-h', `${ top.offsetHeight }px` );
			}
		};

		const observer = new ResizeObserver( measure );

		observer.observe( this.root );
		observer.observe( chrome );
		measure();
		this.cleanup.push( () => observer.disconnect() );
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
		const wake = ( event: Event ): void => {
			/*
			 * A finger is not a pointer that hovers. Its `pointerdown` used to
			 * show the controls, and the tap handler — on `pointerup` of the
			 * same touch — then toggled them straight back off: on a phone the
			 * controls flashed and vanished on every tap. Touch is decided by
			 * the tap handler alone.
			 */
			if (
				event instanceof PointerEvent &&
				'touch' === event.pointerType &&
				( 'pointerdown' === event.type || 'pointermove' === event.type )
			) {
				return;
			}

			this.showChrome();
		};

		for ( const type of [ 'pointermove', 'pointerdown', 'focusin' ] ) {
			this.on( this.root, type, wake );
		}

		/*
		 * On the media, not the player: media events do not bubble, so these
		 * two never reached the listener on the player at all. With a mouse
		 * nobody noticed — moving it starts the countdown — but a video
		 * started with a tap kept its controls up for as long as it played.
		 */
		for ( const type of [ 'play', 'pause' ] ) {
			this.on( this.media, type, wake );
		}

		this.on( this.root, 'pointerleave', ( event: PointerEvent ) => {
			if ( 'touch' !== event.pointerType ) {
				this.hideChrome();
			}
		} );
	}

	/**
	 * Bring the controls up, and set them to fade again after a while.
	 */
	private showChrome(): void {
		window.clearTimeout( this.idleTimer );
		this.root.classList.remove( 'is-chrome-idle' );

		if ( this.media.paused ) {
			return;
		}

		/*
		 * Zero means the controls stay up for good — the setting's help text,
		 * the block's help text and the default's comment all say so.
		 */
		if ( this.config.hideAfter <= 0 ) {
			return;
		}

		this.idleTimer = window.setTimeout( () => {
			const active = this.root.ownerDocument.activeElement;

			/*
			 * Kept up while the keyboard is in the controls — focus a person
			 * can see. A tap or a click leaves focus on what was pressed too,
			 * and counting that kept the controls up for good on every phone
			 * once anything had been touched.
			 */
			if (
				this.menuOwner ||
				( active &&
					this.root.contains( active ) &&
					active.matches( ':focus-visible' ) )
			) {
				return;
			}

			this.root.classList.add( 'is-chrome-idle' );
		}, this.config.hideAfter );
	}

	/**
	 * Let the controls fade now — while playing, and never with a menu open.
	 */
	private hideChrome(): void {
		if (
			this.media.paused ||
			this.config.hideAfter <= 0 ||
			this.menuOwner
		) {
			return;
		}

		window.clearTimeout( this.idleTimer );
		this.root.classList.add( 'is-chrome-idle' );
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

		const doc = document as Document & {
			webkitFullscreenElement?: Element | null;
			webkitExitFullscreen?: () => void;
		};
		const current = (): Element | null =>
			doc.fullscreenElement ?? doc.webkitFullscreenElement ?? null;
		const leave = (): void => {
			if ( doc.exitFullscreen ) {
				void doc.exitFullscreen().catch( () => undefined );
			} else {
				doc.webkitExitFullscreen?.();
			}
		};

		this.on( button, 'click', () => {
			if ( current() === this.root ) {
				leave();

				return;
			}

			if ( current() ) {
				leave();
			}

			if ( element.requestFullscreen ) {
				void element.requestFullscreen().catch( () => undefined );
			} else if ( element.webkitRequestFullscreen ) {
				void element.webkitRequestFullscreen();
			} else if ( media?.webkitEnterFullscreen ) {
				media.webkitEnterFullscreen();
			}
		} );

		const sync = (): void => {
			const on = current() === this.root;

			this.root.classList.toggle( 'is-fullscreen', on );
			button.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		};

		// Safari before 16.4 announces only the prefixed event, so the
		// player never knew it was full screen there.
		this.on( document, 'fullscreenchange', sync );
		this.on( document, 'webkitfullscreenchange', sync );

		// The iPhone's own full screen, on the element.
		if ( media ) {
			this.on( media, 'webkitbeginfullscreen', () =>
				this.root.classList.add( 'is-fullscreen' )
			);
			this.on( media, 'webkitendfullscreen', () =>
				this.root.classList.remove( 'is-fullscreen' )
			);
		}
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

		const sync = ( on: boolean ): void => {
			this.root.classList.toggle( 'is-pip', on );
			button.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		};

		this.on( element, 'enterpictureinpicture', () => sync( true ) );
		this.on( element, 'leavepictureinpicture', () => sync( false ) );
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
			if (
				event.defaultPrevented ||
				event.ctrlKey ||
				event.metaKey ||
				event.altKey ||
				ownsKey( event.target as HTMLElement | null, event.key )
			) {
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

			if ( this.root.classList.contains( 'is-chrome-idle' ) ) {
				this.showChrome();
			} else {
				this.hideChrome();
			}
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
	 * @param opener
	 * @param replace Swap the list in the open panel rather than toggling it.
	 */
	private openMenu(
		items: MenuItem[],
		opener: HTMLElement | null,
		replace = false
	): void {
		const menu = this.root.querySelector< HTMLElement >( '.imgp__menu' );

		if ( ! menu ) {
			return;
		}

		if ( ! menu.hidden ) {
			/*
			 * The same button again closes what it opened. A different button
			 * swaps its own list in: it used to only close the open one, so
			 * reaching the gear with the speed list up took two presses.
			 */
			if ( ! replace && opener === this.menuOwner ) {
				this.closeMenu( true );

				return;
			}

			this.releaseMenu( false, replace ? opener : null );
		}

		menu.classList.remove( 'imgp__menu--search' );
		menu.textContent = '';

		const doc = this.root.ownerDocument;
		let first: HTMLButtonElement | null = null;
		let checked: HTMLButtonElement | null = null;

		for ( const item of items ) {
			const button = doc.createElement( 'button' );

			button.type = 'button';
			button.className = 'imgp__menuitem';
			button.tabIndex = -1;

			if ( item.action ) {
				button.setAttribute( 'role', 'menuitem' );
			} else {
				button.setAttribute( 'role', 'menuitemradio' );
				button.setAttribute(
					'aria-checked',
					item.active ? 'true' : 'false'
				);
			}

			button.textContent = item.label;

			button.addEventListener( 'click', () => {
				item.onPick();

				if ( ! item.keepOpen ) {
					this.closeMenu( true );
				}
			} );

			menu.appendChild( button );
			first ??= button;

			if ( item.active && ! item.action ) {
				checked ??= button;
			}
		}

		this.showMenu( menu, opener );
		( checked ?? first )?.focus();
	}

	/**
	 * Put the panel up for a button, and arm the ways of putting it away.
	 * @param menu
	 * @param opener
	 */
	private showMenu( menu: HTMLElement, opener: HTMLElement | null ): void {
		menu.hidden = false;
		this.menuOwner = opener;
		this.root.classList.add( 'has-menu' );
		opener?.setAttribute( 'aria-expanded', 'true' );
		this.showChrome();

		const doc = this.root.ownerDocument;

		/*
		 * Escape closes and hands focus back to the button; the arrows walk the
		 * list, as a menu's do. Before, Escape dropped focus on the page
		 * itself, and the controls then faded because nothing inside the
		 * player had focus any more.
		 */
		const onKey = ( event: KeyboardEvent ): void => {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				this.closeMenu( true );

				return;
			}

			if ( ! menu.contains( event.target as Node | null ) ) {
				return;
			}

			const items = Array.from(
				menu.querySelectorAll< HTMLElement >( '.imgp__menuitem' )
			);

			if ( 0 === items.length ) {
				return;
			}

			const index = items.indexOf( doc.activeElement as HTMLElement );
			let next = -1;

			switch ( event.key ) {
				case 'ArrowDown':
					next = ( index + 1 ) % items.length;
					break;
				case 'ArrowUp':
					next = ( index - 1 + items.length ) % items.length;
					break;
				case 'Home':
					next = 0;
					break;
				case 'End':
					next = items.length - 1;
					break;
			}

			if ( next >= 0 ) {
				event.preventDefault();
				items[ next ].focus();
			}
		};

		const onPointer = ( event: PointerEvent ): void => {
			const target = event.target as HTMLElement | null;

			if (
				! target ||
				menu.contains( target ) ||
				this.menuOwner?.contains( target ) ||
				// Another bar button opens its own list with its click.
				target.closest( '.imgp__vbtn, .imgp__speed' )
			) {
				return;
			}

			this.closeMenu( false );
		};

		// Tabbing out of the list is leaving it.
		const onFocusOut = ( event: FocusEvent ): void => {
			const next = event.relatedTarget as Node | null;

			if (
				next &&
				! menu.contains( next ) &&
				! this.menuOwner?.contains( next )
			) {
				this.closeMenu( false );
			}
		};

		doc.addEventListener( 'keydown', onKey );
		doc.addEventListener( 'pointerdown', onPointer );
		menu.addEventListener( 'focusout', onFocusOut );

		this.dismissMenu = () => {
			doc.removeEventListener( 'keydown', onKey );
			doc.removeEventListener( 'pointerdown', onPointer );
			menu.removeEventListener( 'focusout', onFocusOut );
		};
	}

	/**
	 * Put the panel away.
	 * @param returnFocus Hand focus back to the button that opened it.
	 */
	private closeMenu( returnFocus = false ): void {
		const menu = this.root.querySelector< HTMLElement >( '.imgp__menu' );

		if ( menu ) {
			menu.hidden = true;
			menu.textContent = '';
			menu.classList.remove( 'imgp__menu--search' );
		}

		this.releaseMenu( returnFocus );
	}

	/**
	 * Disarm the panel's listeners and let go of the button that owned it.
	 * @param returnFocus Hand focus back to that button.
	 * @param keep        A button that stays the owner (a list swapped in place).
	 */
	private releaseMenu(
		returnFocus = false,
		keep: HTMLElement | null = null
	): void {
		this.dismissMenu?.();
		this.dismissMenu = null;

		const owner = this.menuOwner;

		this.menuOwner = null;

		if ( ! keep ) {
			this.root.classList.remove( 'has-menu' );
		}

		if ( owner && owner !== keep ) {
			owner.setAttribute( 'aria-expanded', 'false' );
		}

		/*
		 * Back to the gear when the list came from a button the narrow bar
		 * does not show: focus on something that is not drawn goes nowhere,
		 * and the page's own focus with it.
		 */
		if ( returnFocus ) {
			( owner && ! owner.getClientRects().length
				? this.root.querySelector< HTMLElement >(
						'.imgp__vbtn--settings'
				  )
				: owner
			)?.focus();
		}
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
			// No tracks of ours. A provider may still have its own, drawn
			// inside its frame; the same button then switches those.
			if ( 'function' === typeof this.media.captionTracks ) {
				void this.bindProviderCaptions( button );
			}

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
		} else if (
			this.config.captionsOn &&
			! tracks.some( ( track ) => 'showing' === track.mode )
		) {
			/*
			 * On from the first frame, for a site whose audience mostly watches
			 * with the sound off. Only when the viewer has not chosen for
			 * themselves: a remembered choice is a person's, and this is the
			 * author's guess about people who have not made one.
			 */
			this.showTrack( tracks, tracks[ 0 ].language || tracks[ 0 ].label );
		}

		this.syncCaptionButton();

		this.on( button, 'click', () => {
			const off = this.i18n( 'captionsOff', 'Off' );

			this.openMenu(
				[
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
							this.showTrack(
								tracks,
								track.language || track.label
							);
							this.remember( track.language || track.label );
						},
					} ) ),
				],
				button
			);
		} );
	}

	/**
	 * The provider's own subtitles, switched from this bar.
	 *
	 * YouTube and Vimeo draw theirs inside their frame and will not hand the
	 * text over, so they cannot take this player's size and backing. But with
	 * the provider's interface hidden, this button is the only way a viewer
	 * has to reach them at all — and a video with subtitles that nobody can
	 * turn on is a video without subtitles.
	 *
	 * The list exists only once the provider's frame does, which is built on
	 * the first play, so the button appears then. The viewer's remembered
	 * language and the author's "on from the start" apply exactly as they do
	 * to this player's own tracks.
	 * @param button
	 */
	private async bindProviderCaptions(
		button: HTMLButtonElement
	): Promise< void > {
		const media = this.media;

		if ( ! media.captionTracks || ! media.setCaptionTrack ) {
			return;
		}

		const list = await media.captionTracks();

		if ( 0 === list.length || ! this.root.isConnected ) {
			return;
		}

		const pick = ( code: string ): void => {
			media.setCaptionTrack?.( code );
			button.setAttribute(
				'aria-pressed',
				'' !== code ? 'true' : 'false'
			);
			button.classList.toggle( 'is-active', '' !== code );
		};

		const remembered = this.remembered();
		const known = ( wanted: string | null ): string =>
			list.find(
				( track ) => track.code === wanted || track.label === wanted
			)?.code ?? '';

		if ( remembered ) {
			pick( known( remembered ) );
		} else if ( this.config.captionsOn ) {
			pick( media.captionTrack || list[ 0 ].code );
		} else {
			// Loading the list can switch the provider's subtitles on by
			// itself; the viewer has not asked, so they go back off.
			pick( '' );
		}

		button.hidden = false;

		this.on( button, 'click', () => {
			const off = this.i18n( 'captionsOff', 'Off' );
			const current = media.captionTrack ?? '';

			this.openMenu(
				[
					{
						label: off,
						active: '' === current,
						onPick: () => {
							pick( '' );
							this.remember( '' );
						},
					},
					...list.map( ( track ) => ( {
						label: track.label,
						active: track.code === current,
						onPick: () => {
							pick( track.code );
							this.remember( track.code );
						},
					} ) ),
				],
				button
			);
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

	/**
	 * Stop when nobody is watching.
	 *
	 * Two ways to not be watching: the tab is in the background, or the picture
	 * has scrolled off the screen. Presto calls this Focus Mode and it is off by
	 * default here for the same reason — someone who scrolls on while a talk
	 * plays is usually doing that on purpose — but for a lesson where the
	 * picture carries the meaning, playing to an empty screen is worse.
	 *
	 * Deliberately does not resume. Starting a video under somebody because
	 * they scrolled back is the behaviour everyone complains about.
	 */
	/**
	 * The viewer's name, moved to a new place on the picture every few
	 * seconds and put back if something takes it away.
	 *
	 * Still in one corner it is cropped out of a recording in one step;
	 * moving, it is in every frame somewhere. Taking it out of the page from
	 * the browser's tools is possible, and is answered by putting it back —
	 * which is a deterrent, as the whole feature is, not a lock.
	 */
	private bindViewerMark(): void {
		const mark =
			this.root.querySelector< HTMLElement >( '.imgp__viewer-mark' );
		const stage = this.stage;

		if ( ! mark || ! stage ) {
			return;
		}

		const place = (): void => {
			mark.style.setProperty(
				'--imgp-vm-x',
				`${ 4 + Math.round( Math.random() * 56 ) }%`
			);
			mark.style.setProperty(
				'--imgp-vm-y',
				`${ 6 + Math.round( Math.random() * 70 ) }%`
			);
		};

		place();

		const timer = window.setInterval( () => {
			if ( ! mark.isConnected || ! stage.contains( mark ) ) {
				stage.appendChild( mark );
			}

			mark.hidden = false;
			mark.classList.add( 'is-moving' );
			window.setTimeout( () => {
				place();
				mark.classList.remove( 'is-moving' );
			}, 650 );
		}, 9000 );

		this.cleanup.push( () => window.clearInterval( timer ) );
	}

	private bindFocusMode(): void {
		if ( ! this.config.focus ) {
			return;
		}

		const stop = (): void => {
			/*
			 * Picture-in-picture exists to leave the tab or scroll away while
			 * the video goes on; full screen moves the player out of the page.
			 * Neither is "nobody is watching".
			 */
			const pip = (
				document as Document & {
					pictureInPictureElement?: Element | null;
				}
			 ).pictureInPictureElement;

			if (
				( this.element && pip === this.element ) ||
				document.fullscreenElement === this.root
			) {
				return;
			}

			if ( ! this.media.paused ) {
				this.media.pause();
			}
		};

		this.on( document, 'visibilitychange', () => {
			if ( document.hidden ) {
				stop();
			}
		} );

		if ( typeof IntersectionObserver === 'undefined' ) {
			return;
		}

		const observer = new IntersectionObserver(
			( entries ) => {
				if ( entries[ 0 ] && ! entries[ 0 ].isIntersecting ) {
					stop();
				}
			},
			{ threshold: 0.25 }
		);

		observer.observe( this.root );
		this.cleanup.push( () => observer.disconnect() );
	}

	/**
	 * Finding the moment a word is said.
	 *
	 * On a forty-minute talk this is the difference between a video somebody
	 * watches and a video somebody uses: getting to the part about pricing
	 * without dragging the bar and guessing. Chapters do that when the author
	 * wrote them; this works on what was actually said.
	 *
	 * The text is the subtitle tracks the player already has, so there is
	 * nothing to index on the server and nothing extra to download. The reading
	 * and matching are in their own chunk, fetched when somebody opens the box.
	 */
	private bindSearch(): void {
		const button = this.root.querySelector< HTMLButtonElement >(
			'.imgp__vbtn--search'
		);
		const element = this.element;

		if ( ! button || ! element ) {
			return;
		}

		button.hidden = false;

		/*
		 * Ask the browser for the subtitle files now, rather than when somebody
		 * opens the box. A track left `disabled` never fetches its file, so
		 * reading the cues at open time found none of them and the box reported
		 * that the subtitles had not loaded — which was true, and stayed true.
		 *
		 * `hidden` loads the text without putting it on the picture, so this
		 * does not turn subtitles on for anybody.
		 */
		for ( let i = 0; i < element.textTracks.length; i++ ) {
			const track = element.textTracks[ i ];

			if (
				( 'subtitles' === track.kind || 'captions' === track.kind ) &&
				'disabled' === track.mode
			) {
				track.mode = 'hidden';
			}
		}

		let hits: import('./search').Hit[] | null = null;
		let find: typeof import('./search').search | null = null;
		let collect: typeof import('./search').collect | null = null;

		this.on( button, 'click', () => {
			void ( async () => {
				if ( ! find ) {
					try {
						const module = await import(
							/* webpackChunkName: "imagina-search" */ './search'
						);

						find = module.search;
						collect = module.collect;
						hits = collect( element );
					} catch {
						return;
					}
				}

				this.openSearch(
					hits ?? [],
					find,
					() => ( collect ? collect( element ) : [] ),
					button
				);
			} )();
		} );
	}

	/**
	 * The box itself: type, and pick a moment.
	 *
	 * Built in the menu the other buttons already use, so it closes the same
	 * way and there is only ever one thing open over the picture.
	 *
	 * @param initial   Every line of every subtitle track, as they stood when
	 *                  the box was opened.
	 * @param find      The matcher from the search chunk.
	 * @param recollect Reads the cues again, for a subtitle file that had not
	 *                  arrived yet when the box opened.
	 * @param opener
	 */
	private openSearch(
		initial: import('./search').Hit[],
		find: typeof import('./search').search,
		recollect: () => import('./search').Hit[],
		opener: HTMLElement
	): void {
		let hits = initial;
		const menu = this.root.querySelector< HTMLElement >( '.imgp__menu' );

		if ( ! menu ) {
			return;
		}

		if ( ! menu.hidden ) {
			if ( opener === this.menuOwner ) {
				this.closeMenu( true );

				return;
			}

			this.releaseMenu();
		}

		const doc = this.root.ownerDocument;

		menu.textContent = '';
		menu.classList.add( 'imgp__menu--search' );

		const input = doc.createElement( 'input' );

		input.type = 'search';
		input.className = 'imgp__search-input';
		input.placeholder = this.i18n(
			'searchPlaceholder',
			'Search what is said'
		);
		input.setAttribute( 'aria-label', input.placeholder );

		const list = doc.createElement( 'div' );

		list.className = 'imgp__search-results';

		const note = doc.createElement( 'p' );

		note.className = 'imgp__search-note';
		note.textContent =
			0 === hits.length
				? this.i18n(
						'searchEmpty',
						'The subtitles for this video have not loaded yet.'
				  )
				: '';

		const render = (): void => {
			list.textContent = '';

			if ( input.value.trim().length < 2 ) {
				note.textContent = '';

				return;
			}

			/*
			 * A file that had not arrived when the box opened has usually
			 * arrived by the time somebody has finished typing, so asking
			 * again costs one pass over the cues and saves a box that is
			 * permanently empty.
			 */
			if ( 0 === hits.length ) {
				hits = recollect();
			}

			const found = find( hits, input.value );

			if ( 0 === found.length ) {
				note.textContent = this.i18n( 'searchNone', 'Nothing found.' );

				return;
			}

			note.textContent = '';

			for ( const hit of found ) {
				const row = doc.createElement( 'button' );

				row.type = 'button';
				row.className = 'imgp__search-hit';

				const when = doc.createElement( 'span' );

				when.className = 'imgp__search-at';
				when.textContent = stamp( hit.at );

				const said = doc.createElement( 'span' );

				said.className = 'imgp__search-said';
				said.textContent = hit.text;

				row.append( when, said );
				row.addEventListener( 'click', () => {
					this.host.seekTo( hit.at );
					this.closeMenu( true );
				} );

				list.appendChild( row );
			}
		};

		input.addEventListener( 'input', render );
		menu.append( input, note, list );
		// The same ways out as every other list: Escape, a click elsewhere,
		// tabbing away. It used to close only from its own button.
		this.showMenu( menu, opener );
		input.focus();
	}

	/**
	 * The gear: playback speed as a list, and a link to this moment.
	 */
	private bindSettings(): void {
		const button = this.root.querySelector< HTMLButtonElement >(
			'.imgp__vbtn--settings'
		);

		if ( ! button || ! this.root.querySelector( '.imgp__menu' ) ) {
			return;
		}

		button.hidden = false;

		this.on( button, 'click', () => {
			this.openMenu( this.settingsItems(), button );
		} );
	}

	/**
	 * A second list in the gear, with the way back to the first, as a
	 * settings menu's second level has.
	 * @param items
	 */
	private sublist( items: MenuItem[] ): void {
		const settings = this.root.querySelector< HTMLElement >(
			'.imgp__vbtn--settings'
		);

		this.openMenu(
			[
				{
					label: `‹ ${ this.i18n( 'back', 'Back' ) }`,
					active: false,
					action: true,
					keepOpen: true,
					onPick: () =>
						this.openMenu( this.settingsItems(), settings, true ),
				},
				...items,
			],
			settings,
			true
		);
	}

	private settingsItems(): MenuItem[] {
		const items: MenuItem[] = [];
		const settings = this.root.querySelector< HTMLElement >(
			'.imgp__vbtn--settings'
		);
		const audio = this.audio;

		// Only when the author left the speed control on: the gear does not
		// bring back a control that was switched off.
		if ( this.root.querySelector( '.imgp__speed' ) ) {
			items.push( {
				label: `${ this.i18n( 'speed', 'Speed' ) } · ${ this.speedLabel(
					this.host.speed()
				) }`,
				active: false,
				action: true,
				keepOpen: true,
				onPick: () => this.sublist( this.speedItems() ),
			} );
		}

		// The soundtrack: a dubbing, or the stream's own languages.
		if ( audio && audio.labels().length > 1 ) {
			const labels = audio.labels();

			items.push( {
				label: `${ this.i18n( 'audioTrack', 'Audio' ) } · ${
					labels[ audio.current() ] ?? ''
				}`,
				active: false,
				action: true,
				keepOpen: true,
				onPick: () =>
					this.sublist(
						labels.map( ( label, index ) => ( {
							label,
							active: index === audio.current(),
							onPick: () => audio.pick( index ),
						} ) )
					),
			} );
		}

		/*
		 * Whatever the bar has but cannot show at this width — chapters,
		 * search, quality, picture-in-picture and the download on a phone —
		 * is offered here under its own name, and does what its button would.
		 * Hidden by the width, not switched off: a button the author turned
		 * off is not on the bar at all.
		 */
		for ( const button of this.root.querySelectorAll< HTMLElement >(
			'.imgp__vbtn, .imgp__download'
		) ) {
			if (
				button.hidden ||
				button.classList.contains( 'imgp__vbtn--settings' ) ||
				button.getClientRects().length
			) {
				continue;
			}

			items.push( {
				label: button.getAttribute( 'aria-label' ) ?? '',
				active: false,
				action: true,
				keepOpen: true,
				onPick: () => {
					// A button that does something rather than open a list
					// leaves the gear's list up behind it; put it away.
					button.click();

					if ( this.menuOwner === settings ) {
						this.closeMenu( true );
					}
				},
			} );
		}

		items.push( {
			label: this.i18n( 'copyLink', 'Copy link to this moment' ),
			active: false,
			action: true,
			onPick: () => void this.copyMomentLink(),
		} );

		return items;
	}

	private speedItems(): MenuItem[] {
		const current = this.host.speed();

		return SPEED_CHOICES.map( ( rate ) => ( {
			label: this.speedLabel( rate ),
			active: Math.abs( rate - current ) < 0.001,
			onPick: () => this.host.setSpeed( rate ),
		} ) );
	}

	private speedLabel( rate: number ): string {
		return 1 === rate ? this.i18n( 'speedNormal', 'Normal' ) : `${ rate }×`;
	}

	/**
	 * The speed button, when the core asks: the list instead of the cycle.
	 *
	 * @return True when a menu was opened or closed; false leaves the core to
	 *         cycle as it does for audio.
	 */
	pickSpeed(): boolean {
		const menu = this.root.querySelector< HTMLElement >( '.imgp__menu' );

		if ( ! menu ) {
			return false;
		}

		this.openMenu(
			this.speedItems(),
			this.root.querySelector< HTMLElement >( '.imgp__speed' )
		);

		return true;
	}

	/**
	 * The page's address with `t=` at the current second — and `player=`
	 * naming this one when the block was given an anchor, so a page with
	 * two videos opens the right one.
	 */
	private async copyMomentLink(): Promise< void > {
		const win = this.root.ownerDocument.defaultView ?? window;
		const url = new URL( win.location.href );
		const name = this.root.closest< HTMLElement >( '.imgp-block' )?.id;

		url.searchParams.set(
			't',
			String( Math.floor( this.media.currentTime ) )
		);
		url.hash = '';

		if ( name ) {
			url.searchParams.set( 'player', name );
		} else {
			url.searchParams.delete( 'player' );
		}

		try {
			await win.navigator.clipboard.writeText( url.toString() );
			this.toast( this.i18n( 'linkCopied', 'Link copied' ) );
		} catch {
			// No clipboard — an insecure page, or permission refused. Show
			// the link so it can still be copied by hand.
			this.toast( url.toString() );
		}
	}

	/**
	 * A line over the picture that goes away by itself.
	 * @param text
	 */
	private toast( text: string ): void {
		const doc = this.root.ownerDocument;

		this.root.querySelector( '.imgp__toast' )?.remove();

		const toast = doc.createElement( 'div' );

		toast.className = 'imgp__toast';
		toast.setAttribute( 'role', 'status' );
		toast.textContent = text;
		( this.stage ?? this.root ).appendChild( toast );

		window.clearTimeout( this.toastTimer );
		this.toastTimer = window.setTimeout( () => toast.remove(), TOAST_FOR );
	}

	private bindChapters(): void {
		// In order of time whatever order they were written in: which one is
		// "current" is read from the next one along.
		const chapters = [ ...( this.config.chapters ?? [] ) ].sort(
			( a, b ) => a.start - b.start
		);

		if ( 0 === chapters.length ) {
			return;
		}

		this.paintMarkers( chapters );
		this.bindChapterTitles( chapters );

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
				} ),
				button
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

	/**
	 * The transcript under the picture.
	 *
	 * The server rendered the panel, closed; the first time it is opened the
	 * lines are read from the subtitle tracks and follow playback from then
	 * on. Only a real element carries tracks this can read, which is also
	 * why the server only prints the panel for one.
	 */
	private bindTranscript(): void {
		const panel =
			this.root.querySelector< HTMLDetailsElement >(
				'.imgp__transcript'
			);
		const element = this.element;

		if ( ! panel || ! element ) {
			return;
		}

		// Ask for the subtitle files now, as the search box does, so the
		// lines are there by the time somebody opens the panel.
		for ( let i = 0; i < element.textTracks.length; i++ ) {
			const track = element.textTracks[ i ];

			if ( 'disabled' === track.mode ) {
				track.mode = 'hidden';
			}
		}

		let mounted = false;

		const open = (): void => {
			if ( mounted || ! panel.open ) {
				return;
			}

			mounted = true;

			import(
				/* webpackChunkName: "imagina-transcript" */ './transcript'
			)
				.then( ( { mount } ) => {
					// Arrived after the player was taken down: nothing to fill.
					if ( this.destroyed ) {
						return;
					}

					this.cleanup.push(
						mount(
							{
								root: this.root,
								element,
								media: this.media,
								i18n: this.host.i18n,
								seekTo: ( seconds ) =>
									this.host.seekTo( seconds ),
							},
							panel
						)
					);
				} )
				.catch( () => {
					mounted = false;
				} );
		};

		this.on( panel, 'toggle', open );
		open();
	}

	/**
	 * The chapter under the pointer, named.
	 *
	 * The bar is cut into segments by the markers; this says which one the
	 * pointer is over, and the time there, in a tip that follows it. Only a
	 * pointer — a finger dragging the bar is covering the tip anyway.
	 * @param chapters
	 */
	private bindChapterTitles(
		chapters: Array< { start: number; title: string } >
	): void {
		const scrubber =
			this.root.querySelector< HTMLElement >( '.imgp__scrubber' );

		if ( ! scrubber ) {
			return;
		}

		const tip = this.root.ownerDocument.createElement( 'div' );

		tip.className = 'imgp__chapter-tip';
		tip.hidden = true;
		tip.setAttribute( 'aria-hidden', 'true' );

		const name = this.root.ownerDocument.createElement( 'span' );

		name.className = 'imgp__chapter-tip-title';

		const when = this.root.ownerDocument.createElement( 'span' );

		when.className = 'imgp__chapter-tip-time';
		tip.append( name, when );
		scrubber.appendChild( tip );

		const sorted = [ ...chapters ].sort( ( a, b ) => a.start - b.start );

		const move = ( event: PointerEvent ): void => {
			const duration = this.media.duration;

			if ( ! Number.isFinite( duration ) || duration <= 0 ) {
				tip.hidden = true;

				return;
			}

			const box = scrubber.getBoundingClientRect();
			const ratio = Math.min(
				1,
				Math.max( 0, ( event.clientX - box.left ) / box.width )
			);
			const at = ratio * duration;
			let current: { start: number; title: string } | null = null;

			for ( const chapter of sorted ) {
				if ( chapter.start <= at ) {
					current = chapter;
				}
			}

			if ( ! current ) {
				tip.hidden = true;

				return;
			}

			name.textContent = current.title;
			when.textContent = stamp( at );
			tip.hidden = false;

			// Kept inside the bar so a title near either end does not hang
			// off the side of the picture.
			const half = tip.offsetWidth / 2;

			tip.style.left = `${ Math.min(
				box.width - half,
				Math.max( half, ratio * box.width )
			) }px`;
		};

		this.on( scrubber, 'pointermove', move );
		this.on( scrubber, 'pointerleave', () => {
			tip.hidden = true;
		} );
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

/**
 * A timestamp a person reads, for a search result.
 *
 * Its own function rather than the core's `formatTime`, which lives in the main
 * bundle: this file is a chunk and pulling a helper across would drag the
 * import graph the wrong way for one line of arithmetic.
 *
 * @param seconds Where in the video.
 */
function stamp( seconds: number ): string {
	const whole = Math.max( 0, Math.floor( seconds ) );
	const hours = Math.floor( whole / 3600 );
	const minutes = Math.floor( ( whole % 3600 ) / 60 );
	const rest = whole % 60;
	const pad = ( n: number ): string => String( n ).padStart( 2, '0' );

	return hours > 0
		? `${ hours }:${ pad( minutes ) }:${ pad( rest ) }`
		: `${ minutes }:${ pad( rest ) }`;
}
