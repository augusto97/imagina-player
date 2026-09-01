import {
	computePeaks,
	decodePeaks,
	rememberPeaks,
	sharedPeaks,
	storePeaks,
} from './peaks';
import type {
	MediaCapabilities,
	PlayerConfig,
	PlayerMedia,
	RuntimeData,
	TrackChange,
	VideoConfig,
} from './types';

/** What the provider chunk hands over: a media stand-in that can be torn down. */
type ProviderStandIn = PlayerMedia & {
	capabilities: MediaCapabilities;
	destroy: () => void;
};
import { clamp, formatTime, rafThrottle } from './utils';
import { Waveform } from './waveform';

const SPEEDS = [ 1, 1.25, 1.5, 2, 0.75 ];

const STORAGE_PREFIX = 'imagina-player:position:';

/** How long the browser may spend downloading and decoding a file. */
const ANALYZE_TIMEOUT = 30000;

/** How long the "analysing" highlight may run before it gives up visually. */
const ANALYZE_UI_TIMEOUT = 20000;

/**
 * Players registered on the page, so starting one can stop the rest.
 */
const instances = new Set< Player >();

export class Player {
	readonly root: HTMLElement;

	readonly media: PlayerMedia;

	private readonly config: PlayerConfig;

	private readonly runtime: RuntimeData;

	private readonly playButton: HTMLButtonElement | null;

	private readonly seek: HTMLElement | null;

	private readonly currentTimeEl: HTMLElement | null;

	private readonly totalTimeEl: HTMLElement | null;

	private readonly progressEl: HTMLElement | null;

	private readonly volumeSlider: HTMLInputElement | null;

	private readonly muteButton: HTMLButtonElement | null;

	private readonly speedButton: HTMLButtonElement | null;

	private waveform: Waveform | null = null;

	private resizeObserver: ResizeObserver | null = null;

	private stickyPlaceholder: HTMLElement | null = null;

	private stickyObserver: IntersectionObserver | null = null;

	/** Set once the reader has sent a floating player away. */
	private stickyDismissed = false;

	private scrubbing = false;

	private speedIndex = 0;

	private peaksRequested = false;

	private sourceRefreshed = false;

	private destroyed = false;

	/**
	 * The real element, when there is one. A video on YouTube has no element,
	 * so anything that needs one — full screen on the picture, picture in
	 * picture, our own subtitles — has to check rather than assume.
	 */
	private readonly element: HTMLMediaElement | null;

	private readonly standIn: ProviderStandIn | null;

	/** Set once the video chunk has loaded; absent for audio, always. */
	private video: { destroy: () => void } | null = null;

	/** Set once the layer chunk has loaded; absent unless one was configured. */
	private layers: { destroy: () => void } | null = null;

	/**
	 * @param root    The player element.
	 * @param runtime Page-wide settings.
	 * @param standIn What to drive instead of an element, for a video that
	 *                lives on YouTube or Vimeo. Built by the provider chunk,
	 *                which is why it arrives from outside rather than being
	 *                looked up here.
	 */
	constructor(
		root: HTMLElement,
		runtime: RuntimeData,
		standIn?: ProviderStandIn | null
	) {
		this.root = root;
		this.runtime = runtime;
		this.config = JSON.parse(
			root.dataset.imaginaPlayer || '{}'
		) as PlayerConfig;

		const element = root.querySelector< HTMLMediaElement >(
			'audio.imgp__media, video.imgp__media'
		);

		if ( ! standIn && ! element ) {
			throw new Error( 'Imagina Player: no media element found.' );
		}

		this.standIn = standIn ?? null;
		this.element = element;
		this.media = standIn ?? ( element as HTMLMediaElement );
		this.playButton = root.querySelector( '.imgp__play' );
		this.seek = root.querySelector( '.imgp__seek' );
		this.currentTimeEl = root.querySelector( '.imgp__time--current' );
		this.totalTimeEl = root.querySelector( '.imgp__time--total' );
		this.progressEl = root.querySelector( '.imgp__progress' );
		this.volumeSlider = root.querySelector( '.imgp__volume-slider' );
		this.muteButton = root.querySelector( '.imgp__mute' );
		this.speedButton = root.querySelector( '.imgp__speed' );

		this.enhance();
	}

	private enhance(): void {
		// Native controls were rendered so the player still works without this
		// script; now that it has run, the custom UI takes over.
		this.media.removeAttribute( 'controls' );
		this.root.classList.add( 'is-enhanced' );

		this.bindMedia();
		this.bindControls();
		this.setupWaveform();
		this.restorePosition();

		if ( this.config.startTime > 0 ) {
			this.seekTo( this.config.startTime );
		}

		if ( this.config.sticky ) {
			this.setupSticky();
		}

		if ( this.config.video ) {
			void this.setupVideo( this.config.video );
		}

		if ( this.config.layers?.length ) {
			void this.setupLayers();
		}

		instances.add( this );
	}

	/**
	 * Load the video chrome, and only then.
	 *
	 * A dynamic import so webpack emits it as its own file: a page with nothing
	 * but audio players never asks for it, and never pays for it. The import
	 * failing is survivable — the core player still plays the video, it just
	 * looks plain — so it is caught rather than allowed to break the page.
	 * @param config
	 */
	private async setupVideo( config: VideoConfig ): Promise< void > {
		/*
		 * A video on YouTube is not an element, and gating on `instanceof
		 * HTMLVideoElement` meant a provider video got no chrome at all — no
		 * play button, no scrub bar, nothing. What the chrome needs is a
		 * picture to draw on, which both have; what only an element can do is
		 * declared separately and checked where it is used.
		 */
		if ( ! this.standIn && ! ( this.media instanceof HTMLVideoElement ) ) {
			return;
		}

		try {
			const { VideoChrome } = await import(
				/* webpackChunkName: "imagina-video" */ './video'
			);

			if ( this.destroyed ) {
				return;
			}

			this.video = new VideoChrome(
				{
					root: this.root,
					media: this.media,
					element: this.element as HTMLVideoElement | null,
					can: this.standIn?.capabilities ?? {
						captions: true,
						pictureInPicture: true,
						elementFullscreen: true,
					},
					i18n: this.runtime.i18n,
					toggle: () => this.toggle(),
					seekBy: ( seconds: number ) =>
						this.seekTo( this.media.currentTime + seconds ),
					seekTo: ( seconds: number ) => this.seekTo( seconds ),
				},
				config
			);
		} catch {
			// The video plays without its chrome. Leave the native controls on
			// rather than leaving the visitor with nothing to press.
			this.media.setAttribute( 'controls', 'controls' );
		}
	}

	private bindMedia(): void {
		const media = this.media;

		media.addEventListener( 'loadedmetadata', () =>
			this.onDurationKnown()
		);
		media.addEventListener( 'durationchange', () =>
			this.onDurationKnown()
		);
		media.addEventListener( 'timeupdate', this.onTimeUpdate );
		media.addEventListener( 'play', () => this.onPlay() );
		media.addEventListener( 'pause', () => this.onPause() );
		media.addEventListener( 'ended', () => this.onEnded() );
		media.addEventListener( 'waiting', () =>
			this.root.classList.add( 'is-buffering' )
		);
		media.addEventListener( 'playing', () =>
			this.root.classList.remove( 'is-buffering' )
		);
		media.addEventListener( 'error', () => {
			void this.recoverSource();
		} );

		if ( this.config.duration > 0 && this.totalTimeEl ) {
			this.totalTimeEl.textContent = formatTime( this.config.duration );
		}
	}

	private bindControls(): void {
		this.playButton?.addEventListener( 'click', () => this.toggle() );

		this.muteButton?.addEventListener( 'click', () => {
			this.media.muted = ! this.media.muted;
			this.syncVolumeUi();
		} );

		this.volumeSlider?.addEventListener( 'input', () => {
			const value = Number( this.volumeSlider?.value ?? 1 );

			this.media.volume = clamp( value, 0, 1 );
			this.media.muted = value === 0;
			this.syncVolumeUi();
		} );

		this.speedButton?.addEventListener( 'click', () => {
			this.speedIndex = ( this.speedIndex + 1 ) % SPEEDS.length;
			this.media.playbackRate = SPEEDS[ this.speedIndex ];

			if ( this.speedButton ) {
				this.speedButton.textContent = `${
					SPEEDS[ this.speedIndex ]
				}×`;
			}
		} );

		this.root
			.querySelector( '.imgp__skip--back' )
			?.addEventListener( 'click', () => {
				this.seekTo( this.media.currentTime - this.config.skipSeconds );
			} );

		this.root
			.querySelector( '.imgp__skip--forward' )
			?.addEventListener( 'click', () => {
				this.seekTo( this.media.currentTime + this.config.skipSeconds );
			} );

		this.bindSeek();
		this.syncVolumeUi();
	}

	private bindSeek(): void {
		const seek = this.seek;

		if ( ! seek ) {
			return;
		}

		const positionFromEvent = ( event: PointerEvent ): number => {
			const rect = seek.getBoundingClientRect();

			return rect.width > 0
				? clamp( ( event.clientX - rect.left ) / rect.width, 0, 1 )
				: 0;
		};

		seek.addEventListener( 'pointerdown', ( event: PointerEvent ) => {
			// Let the browser handle secondary buttons and modified clicks.
			if ( event.button !== 0 ) {
				return;
			}

			this.scrubbing = true;
			seek.setPointerCapture( event.pointerId );
			this.root.classList.add( 'is-scrubbing' );
			this.previewProgress( positionFromEvent( event ) );
		} );

		seek.addEventListener( 'pointermove', ( event: PointerEvent ) => {
			if ( ! this.scrubbing ) {
				return;
			}

			this.previewProgress( positionFromEvent( event ) );
		} );

		const release = ( event: PointerEvent ): void => {
			if ( ! this.scrubbing ) {
				return;
			}

			this.scrubbing = false;
			this.root.classList.remove( 'is-scrubbing' );

			const duration = this.duration();

			if ( duration > 0 ) {
				this.seekTo( positionFromEvent( event ) * duration );
			}
		};

		seek.addEventListener( 'pointerup', release );
		seek.addEventListener( 'pointercancel', release );

		seek.addEventListener( 'keydown', ( event: KeyboardEvent ) => {
			const duration = this.duration();
			const step = event.shiftKey ? 30 : 5;
			let handled = true;

			switch ( event.key ) {
				case 'ArrowRight':
				case 'ArrowUp':
					this.seekTo( this.media.currentTime + step );
					break;
				case 'ArrowLeft':
				case 'ArrowDown':
					this.seekTo( this.media.currentTime - step );
					break;
				case 'Home':
					this.seekTo( 0 );
					break;
				case 'End':
					this.seekTo( duration );
					break;
				case ' ':
				case 'Enter':
					this.toggle();
					break;
				default:
					handled = false;
			}

			if ( handled ) {
				event.preventDefault();
			}
		} );
	}

	private setupWaveform(): void {
		const canvas =
			this.root.querySelector< HTMLCanvasElement >( '.imgp__wave' );

		if ( ! canvas ) {
			return;
		}

		this.waveform = new Waveform( canvas, {
			barWidth: Math.max( 1, this.config.bars || 3 ),
			gap: Math.max( 0, this.config.gap ?? 1 ),
			reflection: this.config.centered
				? 0
				: clamp( this.config.reflection ?? 0.25, 0, 0.8 ),
			rounded: this.root.classList.contains( 'imgp--rounded' ),
			centered: Boolean( this.config.centered ),
		} );

		this.applyWaveColors();
		this.waveform.resize();

		const inline = this.root.dataset.peaks;

		if ( inline ) {
			const peaks = decodePeaks( inline );

			rememberPeaks( this.config.peaksKey, peaks );
			this.waveform.setPeaks( peaks );
		} else {
			void this.loadPeaks();
		}

		if ( typeof ResizeObserver !== 'undefined' ) {
			this.resizeObserver = new ResizeObserver( () => {
				this.waveform?.resize();
				this.render();
			} );

			this.resizeObserver.observe( canvas );
		} else {
			window.addEventListener( 'resize', () => {
				this.waveform?.resize();
				this.render();
			} );
		}
	}

	private applyWaveColors(): void {
		const styles = window.getComputedStyle( this.root );

		this.waveform?.setColors(
			styles.getPropertyValue( '--imgp-wave' ).trim(),
			styles.getPropertyValue( '--imgp-wave-progress' ).trim()
		);
	}

	/**
	 * Fill in a waveform the server did not have.
	 */
	private async loadPeaks(): Promise< void > {
		if ( this.peaksRequested || ! this.config.peaksKey ) {
			this.root.classList.add( 'imgp--no-peaks' );

			return;
		}

		this.peaksRequested = true;
		this.root.classList.add( 'is-analyzing' );

		// The analysing state drives a moving highlight. Whatever happens below,
		// that highlight stops: an animation with no end looks like a broken page.
		const stopAnalyzing = window.setTimeout( () => {
			this.root.classList.remove( 'is-analyzing' );
		}, ANALYZE_UI_TIMEOUT );

		let peaks: Float32Array | null = null;

		try {
			// `sharedPeaks` collapses every player showing this track into one
			// request — and, on a cold cache, one download and decode.
			peaks = await sharedPeaks( this.config.peaksKey, () =>
				this.fetchOrComputePeaks()
			);
		} finally {
			window.clearTimeout( stopAnalyzing );
			this.root.classList.remove( 'is-analyzing' );
		}

		if ( this.destroyed ) {
			return;
		}

		if ( peaks && peaks.length > 0 ) {
			this.waveform?.setPeaks( peaks );

			return;
		}

		// No waveform available. Show a plain progress bar rather than a row of
		// stubs that reads as a player that failed to load.
		this.root.classList.add( 'imgp--no-peaks' );
		this.waveform?.setPlaceholder();
	}

	/**
	 * Ask the REST cache first — the waveform may have been generated since this
	 * page was rendered — then fall back to decoding the file here and handing
	 * the result back to the site.
	 */
	private async fetchOrComputePeaks(): Promise< Float32Array | null > {
		try {
			const url = `${
				this.runtime.restUrl
			}/peaks?key=${ encodeURIComponent( this.config.peaksKey ) }`;
			const response = await fetch( url );

			if ( response.ok ) {
				const data = ( await response.json() ) as {
					peaks?: string | null;
				};

				if ( data.peaks ) {
					return decodePeaks( data.peaks );
				}
			}
		} catch {
			// Fall through to client-side computation.
		}

		if ( ! this.config.canCompute || ! this.config.peaksToken ) {
			return null;
		}

		const computed = await computePeaks(
			this.media.currentSrc || this.media.src,
			this.config.resolution,
			{
				maxBytes: this.runtime.maxComputeBytes,
				timeoutMs: ANALYZE_TIMEOUT,
			}
		);

		if ( typeof computed === 'string' ) {
			/*
			 * Say why once, in the console: a silent flat waveform is otherwise
			 * impossible to explain from the outside.
			 *
			 * Each reason has a different answer, and they used to share one
			 * message. A file on a bucket that refuses cross-origin reads was
			 * reported as too large, which sends somebody to the size settings
			 * to fix a permissions problem — and nothing they do there can help.
			 */
			const said: Partial< Record< string, string > > = {
				'too-large':
					'this file is too large to analyse in the browser. Generate the waveform on the server (Settings → Imagina Player → Waveforms), or from the block editor.',
				unreachable:
					'the browser was not allowed to read this file, so it cannot measure it. It is on another domain that does not permit cross-origin reads. Generate the waveform from the block editor instead — that route goes through this site.',
				timeout:
					'reading this file took too long, so no waveform was measured.',
			};

			const why = said[ computed ];

			if ( why && window.console ) {
				window.console.info( 'Imagina Player: ' + why );
			}

			return null;
		}

		void storePeaks(
			this.runtime.restUrl,
			this.config.peaksToken,
			computed.peaks,
			computed.duration
		);

		return computed.peaks;
	}

	private setupSticky(): void {
		if ( typeof IntersectionObserver === 'undefined' ) {
			return;
		}

		const unstick =
			this.root.querySelector< HTMLButtonElement >( '.imgp__unstick' );

		unstick?.addEventListener( 'click', () => {
			/*
			 * For the rest of this page view. Sending it back only for it to
			 * return on the next scroll is not dismissing it, and a floating
			 * player that cannot be got rid of is the thing people actually
			 * dislike about floating players.
			 */
			this.stickyDismissed = true;
			this.setStuck( false );
		} );

		this.stickyObserver = new IntersectionObserver(
			( entries ) => {
				const entry = entries[ 0 ];

				if ( ! entry ) {
					return;
				}

				void entry;
				this.reviewSticky();
			},
			{ threshold: 0 }
		);

		this.stickyObserver.observe( this.root );

		/*
		 * Playing and pausing have to be asked as well. An observer only fires
		 * when the intersection *changes*, so a player that is already out of
		 * view when playback starts — the listener carried on from a playlist,
		 * or a keyboard shortcut, or an autoplaying video scrolled past before
		 * it began — would never be reconsidered, and would sit off-screen
		 * playing to nobody.
		 */
		const review = (): void => this.reviewSticky();

		this.media.addEventListener( 'play', review );
		this.media.addEventListener( 'pause', review );
	}

	/**
	 * Decide whether the player should be following the reader.
	 *
	 * Only while something is actually playing: a paused player pinning itself
	 * to the corner because somebody scrolled past it is an advert.
	 */
	private reviewSticky(): void {
		if ( this.stickyDismissed || this.media.paused ) {
			this.setStuck( false );

			return;
		}

		/*
		 * Measured here rather than remembered from the observer. The observer
		 * reports *changes*, so a player that was already off screen when the
		 * page loaded has never been reported at all, and a remembered "it is
		 * visible" would be wrong from the start — an autoplaying video below
		 * the fold, or a listener carrying on from a playlist, would play to
		 * nobody. Asking is one layout read on an event that is rare.
		 */
		const box = this.stuckBox();

		this.setStuck(
			box.bottom <= 0 ||
				box.top >=
					( window.innerHeight ||
						document.documentElement.clientHeight )
		);
	}

	/**
	 * Where the player sits, or where it would sit if it were not floating.
	 *
	 * Once it has detached its own rectangle is the floating card, which is
	 * always on screen — so asking it whether it is visible would always say
	 * yes and it would snap back the moment it left.
	 */
	private stuckBox(): DOMRect {
		return ( this.stickyPlaceholder ?? this.root ).getBoundingClientRect();
	}

	private setStuck( stuck: boolean ): void {
		if ( stuck === this.root.classList.contains( 'is-stuck' ) ) {
			return;
		}

		if ( stuck ) {
			// Measured before the class lands, because the class is what makes
			// the player small: reading it afterwards holds open the gap of the
			// floating card rather than of the player that left.
			const height = this.root.offsetHeight;

			this.stickyPlaceholder = document.createElement( 'div' );
			this.stickyPlaceholder.className = 'imgp__sticky-placeholder';
			this.stickyPlaceholder.style.height = `${ height }px`;
			// The player leaves the flow either way, so the gap it left behind has
			// to be held open regardless of where it docks.
			this.root.after( this.stickyPlaceholder );
			this.root.classList.add( 'is-stuck' );
		} else {
			this.stickyPlaceholder?.remove();
			this.stickyPlaceholder = null;
			this.root.classList.remove( 'is-stuck' );
		}

		this.waveform?.resize();
		this.render();
	}

	private onDurationKnown(): void {
		const duration = this.duration();

		if ( this.totalTimeEl && duration > 0 ) {
			this.totalTimeEl.textContent = formatTime( duration );
		}

		this.render();
	}

	private onTimeUpdate = rafThrottle( (): void => {
		if ( this.scrubbing ) {
			return;
		}

		this.render();
		this.savePosition();
	} );

	private onPlay(): void {
		for ( const other of instances ) {
			if ( other !== this && ! other.media.paused ) {
				other.media.pause();
			}
		}

		this.root.classList.add( 'is-playing' );
		this.playButton?.setAttribute( 'aria-pressed', 'true' );
		this.playButton?.setAttribute(
			'aria-label',
			this.runtime.i18n.pause ?? 'Pause'
		);

		// Colours can change after a theme swap or a dark-mode toggle.
		this.applyWaveColors();
		this.waveform?.resize();
	}

	private onPause(): void {
		this.root.classList.remove( 'is-playing' );
		this.playButton?.setAttribute( 'aria-pressed', 'false' );
		this.playButton?.setAttribute(
			'aria-label',
			this.runtime.i18n.play ?? 'Play'
		);

		if ( this.root.classList.contains( 'is-stuck' ) ) {
			this.setStuck( false );
		}
	}

	private onEnded(): void {
		this.root.classList.remove( 'is-playing' );
		this.clearPosition();

		if ( 'loop' === this.config.onEnd ) {
			this.seekTo( 0 );
			void this.media.play().catch( () => undefined );

			return;
		}

		// `stop` leaves the playhead where it finished; `reset` rewinds it.
		if ( 'stop' !== this.config.onEnd ) {
			this.seekTo( 0 );
		}

		this.setStuck( false );
		this.render();
	}

	private previewProgress( ratio: number ): void {
		const duration = this.duration();

		this.waveform?.setProgress( ratio );

		if ( this.progressEl ) {
			this.progressEl.style.transform = `scaleX(${ ratio })`;
		}

		if ( duration > 0 ) {
			this.paintTime( ratio * duration, ratio );
		}
	}

	private render(): void {
		const duration = this.duration();
		const ratio =
			duration > 0 ? clamp( this.media.currentTime / duration, 0, 1 ) : 0;

		this.waveform?.setProgress( ratio );

		if ( this.progressEl ) {
			this.progressEl.style.transform = `scaleX(${ ratio })`;
		}

		this.paintTime( this.media.currentTime, ratio );
	}

	private paintTime( time: number, ratio: number ): void {
		if ( this.currentTimeEl ) {
			this.currentTimeEl.textContent = formatTime( time );
			// The elapsed badge rides the playhead, the way the waveform players
			// people are used to behave.
			this.currentTimeEl.style.left = `${ ratio * 100 }%`;
		}

		if ( this.seek ) {
			this.seek.setAttribute(
				'aria-valuenow',
				String( Math.round( ratio * 100 ) )
			);
			this.seek.setAttribute( 'aria-valuetext', formatTime( time ) );
		}
	}

	private syncVolumeUi(): void {
		const muted = this.media.muted || this.media.volume === 0;

		this.root.classList.toggle( 'is-muted', muted );
		this.muteButton?.setAttribute(
			'aria-pressed',
			muted ? 'true' : 'false'
		);
		this.muteButton?.setAttribute(
			'aria-label',
			muted
				? this.runtime.i18n.unmute ?? 'Unmute'
				: this.runtime.i18n.mute ?? 'Mute'
		);

		if ( this.volumeSlider ) {
			this.volumeSlider.value = String( muted ? 0 : this.media.volume );
		}
	}

	private duration(): number {
		if (
			Number.isFinite( this.media.duration ) &&
			this.media.duration > 0
		) {
			return this.media.duration;
		}

		return this.config.duration > 0 ? this.config.duration : 0;
	}

	private storageKey(): string {
		return `${ STORAGE_PREFIX }${ this.config.peaksKey || this.media.src }`;
	}

	private restorePosition(): void {
		if ( ! this.config.remember ) {
			return;
		}

		try {
			const stored = window.localStorage.getItem( this.storageKey() );

			if ( stored ) {
				this.seekTo( Number( stored ) );
			}
		} catch {
			// Storage can be unavailable in private mode; the player still works.
		}
	}

	private savePosition(): void {
		if ( ! this.config.remember || this.media.currentTime < 5 ) {
			return;
		}

		try {
			window.localStorage.setItem(
				this.storageKey(),
				String( Math.floor( this.media.currentTime ) )
			);
		} catch {
			// Ignored.
		}
	}

	private clearPosition(): void {
		try {
			window.localStorage.removeItem( this.storageKey() );
		} catch {
			// Ignored.
		}
	}

	seekTo( seconds: number ): void {
		const duration = this.duration();
		const target =
			duration > 0
				? clamp( seconds, 0, duration )
				: Math.max( 0, seconds );

		try {
			this.media.currentTime = target;
		} catch {
			// Seeking before metadata is known throws in some browsers.
		}

		this.render();
	}

	toggle(): void {
		if ( this.media.paused ) {
			void this.media.play().catch( () => {
				void this.recoverSource();
			} );
		} else {
			this.media.pause();
		}
	}

	/**
	 * A protected file is served through a signed link that expires. A page held
	 * in a full-page cache outlives its own URLs, so rather than shortening the
	 * cache, ask for a fresh link once and pick up where playback stopped.
	 */
	private async recoverSource(): Promise< boolean > {
		if (
			! this.config.protectedId ||
			this.sourceRefreshed ||
			! this.runtime.restUrl
		) {
			this.root.classList.add( 'has-error' );

			return false;
		}

		this.sourceRefreshed = true;

		const resumeAt = this.media.currentTime;
		const wasPlaying = ! this.media.paused;

		try {
			const response = await fetch(
				`${ this.runtime.restUrl }/stream-url?id=${ encodeURIComponent(
					String( this.config.protectedId )
				) }`,
				{ credentials: 'same-origin' }
			);

			if ( ! response.ok ) {
				throw new Error( 'stream-url unavailable' );
			}

			const data = ( await response.json() ) as { url?: string };

			if ( ! data.url ) {
				throw new Error( 'stream-url empty' );
			}

			this.root.classList.remove( 'has-error' );
			this.media.src = data.url;
			// Only a real element reloads. A provider link is not ours to sign
			// and never expires, so there is nothing here for a stand-in to do.
			this.element?.load();

			if ( resumeAt > 0 ) {
				this.media.addEventListener(
					'loadedmetadata',
					() => this.seekTo( resumeAt ),
					{ once: true }
				);
			}

			if ( wasPlaying ) {
				void this.media.play().catch( () => {
					this.root.classList.add( 'has-error' );
				} );
			}

			return true;
		} catch {
			this.root.classList.add( 'has-error' );

			return false;
		}
	}

	/**
	 * Calls to action, bars and email gates — for audio as much as video.
	 *
	 * Its own chunk, because most players carry none and a feature nobody
	 * configured should not be downloaded by anybody.
	 */
	private async setupLayers(): Promise< void > {
		try {
			const { LayerStack } = await import(
				/* webpackChunkName: "imagina-layers" */ './layers'
			);

			if ( this.destroyed ) {
				return;
			}

			this.layers = new LayerStack( {
				root: this.root,
				media: this.media,
				config: this.config,
				runtime: this.runtime,
			} );
		} catch {
			// The layers stay hidden, which is the same as not having them. The
			// track plays either way.
		}
	}

	/**
	 * Swap in a different track without tearing the player down.
	 *
	 * A playlist that rebuilt the player for every item would lose the volume
	 * the listener set, the speed they chose and the element they had focused,
	 * and would flash the whole shell on every click. This changes what is
	 * playing and leaves everything about the player alone.
	 *
	 * @param track The item to play.
	 * @param play  Whether to start it. False when restoring a page's last item.
	 */
	loadTrack( track: TrackChange, play = true ): void {
		this.savePosition();

		this.config.peaksKey = track.peaksKey ?? '';
		this.config.duration = track.duration ?? 0;
		this.config.protectedId = track.protectedId ?? 0;
		this.sourceRefreshed = false;

		const title = this.root.querySelector< HTMLElement >( '.imgp__title' );
		const artist =
			this.root.querySelector< HTMLElement >( '.imgp__artist' );
		const thumb =
			this.root.querySelector< HTMLImageElement >( '.imgp__thumb img' );

		if ( title ) {
			title.textContent = track.title;
		}

		if ( artist ) {
			artist.textContent = track.artist ?? '';
		}

		if ( thumb && track.thumbnail ) {
			thumb.src = track.thumbnail;
		}

		// The waveform belongs to the file, so it goes with it. Cleared before
		// the new source loads rather than after, or the old shape is on screen
		// under the new track for as long as the fetch takes.
		this.peaksRequested = false;
		this.waveform?.clear();
		this.root.classList.remove( 'imgp--no-peaks' );

		this.media.src = track.src;
		this.element?.load();

		if ( track.peaks ) {
			this.waveform?.setPeaks( decodePeaks( track.peaks ) );
		} else if ( this.config.peaksKey ) {
			void this.loadPeaks();
		}

		this.render();
		this.restorePosition();

		if ( play ) {
			void this.media.play().catch( () => {
				void this.recoverSource();
			} );
		}
	}

	destroy(): void {
		this.destroyed = true;
		this.layers?.destroy();
		this.resizeObserver?.disconnect();
		this.stickyObserver?.disconnect();
		this.video?.destroy();
		instances.delete( this );
	}
}
