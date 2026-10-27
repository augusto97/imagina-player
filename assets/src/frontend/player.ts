import { decodePeaks, rememberPeaks, sharedPeaks, storePeaks } from './peaks';
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
	/** Switch to another of the provider's videos inside the same frame. */
	load?: ( id: string, hash: string, play: boolean ) => void;
};
import { clamp, formatTime, rafThrottle } from './utils';
import { Waveform } from './waveform';

/** What the audio button cycles through; the video chrome offers a list. */
const SPEEDS = [ 1, 1.25, 1.5, 2, 0.75 ];

/** A stored position shorter than this is not worth offering to resume. */
const RESUME_WORTH = 5;

const STORAGE_PREFIX = 'imagina-player:position:';

/**
 * A refusal from `play()` that is not the file's fault.
 *
 * `AbortError` is a play interrupted by a pause or a new source a moment
 * later; `NotAllowedError` is the browser declining to start sound without a
 * gesture. Both were taken for a broken file, and the player put itself in
 * its error state over a video that was perfectly fine.
 * @param error
 */
function isBenignRefusal( error: unknown ): boolean {
	const name = ( error as { name?: string } | null )?.name ?? '';

	return 'AbortError' === name || 'NotAllowedError' === name;
}

/**
 * Whether the grant to store a waveform has run out.
 *
 * A page held in a full-page cache outlives the week the grant lasts; past
 * that, measuring the file in the visitor's browser only ends in a refusal
 * when the result is sent. The grant says when it expires in its own
 * payload, so that is checked first and the work is not done.
 * @param token
 */
function grantExpired( token: string ): boolean {
	try {
		const payload = token.split( '.' )[ 0 ] ?? '';
		const claims = JSON.parse(
			atob( payload.replace( /-/g, '+' ).replace( /_/g, '/' ) )
		) as { exp?: number };

		return 'number' === typeof claims.exp && claims.exp * 1000 < Date.now();
	} catch {
		return false;
	}
}

/** How long a refreshed link must play before another refresh is allowed. */
const SOURCE_REFRESH_GRACE = 60000;

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

	/** The floating behaviour, once its chunk has arrived. */
	private sticky: { destroy: () => void } | null = null;

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

	/** The window resize fallback, when there is no ResizeObserver. */
	private onWindowResize: ( () => void ) | null = null;

	/** Set once the video chunk has loaded; absent for audio, always. */
	private video: {
		destroy: () => void;
		/** True when the chrome took the click: it opened a menu instead. */
		pickSpeed?: () => boolean;
		/** True when a stream took the new address itself. */
		replaceSource?: ( url: string ) => boolean;
	} | null = null;

	/** Takes down the "resume from" offer, while it is showing. */
	private resumeHide: ( () => void ) | null = null;

	/** The signed address this file was last served at, for a refresh. */
	private signedSrc = '';

	/** The position restored from memory this load, or zero. */
	private resumedAt = 0;

	/** The last whole second written to storage, so it is not written twice. */
	private lastSaved = -1;

	private unbindPositionSaving: ( () => void ) | null = null;

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
		// Kept as the server wrote it: once hls.js takes over a stream, the
		// element's own address is a blob and the link is gone from it.
		this.signedSrc = element?.getAttribute( 'src' ) ?? '';
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

		/*
		 * A remembered position wins over the block's start time: the author
		 * chose where everybody starts, the viewer chose where they stopped,
		 * and the second is the more specific. Both used to be applied in
		 * turn, so the playhead sat at the start time while the chip said it
		 * was resuming somewhere else.
		 */
		if ( ! this.restorePosition() && this.config.startTime > 0 ) {
			this.seekTo( this.config.startTime );
		}

		this.bindPositionSaving();

		if ( this.config.sticky ) {
			void this.setupSticky();
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
					setSpeed: ( rate: number ) => this.setSpeed( rate ),
					speed: () => this.media.playbackRate || 1,
					refreshSource: () => void this.recoverSource(),
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
		/*
		 * Not only `playing`. A YouTube video sought while paused reports
		 * buffering and then simply pauses again, and the spinner stayed on
		 * a video that was not loading anything.
		 */
		for ( const type of [ 'playing', 'pause', 'seeked', 'canplay' ] ) {
			media.addEventListener( type, () =>
				this.root.classList.remove( 'is-buffering' )
			);
		}
		media.addEventListener( 'error', () => {
			void this.recoverSource();
		} );

		if ( this.config.duration > 0 && this.totalTimeEl ) {
			this.totalTimeEl.textContent = formatTime( this.config.duration );
		}

		/*
		 * A player built after its file had already loaded — a lazily
		 * enhanced one, scrolled to after the browser fetched the metadata —
		 * never hears `loadedmetadata`, and its total stayed at "--:--".
		 */
		this.onDurationKnown();
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
			// A video offers a list; pressing through five speeds to reach
			// the one before is fine for a button in an audio bar and not for
			// a picture somebody is watching.
			if ( this.video?.pickSpeed?.() ) {
				return;
			}

			this.speedIndex = ( this.speedIndex + 1 ) % SPEEDS.length;
			this.setSpeed( SPEEDS[ this.speedIndex ] );
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
			// Kept, so destroy() can take it off again.
			this.onWindowResize = () => {
				this.waveform?.resize();
				this.render();
			};

			window.addEventListener( 'resize', this.onWindowResize );
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

		if (
			! this.config.canCompute ||
			! this.config.peaksToken ||
			grantExpired( this.config.peaksToken )
		) {
			return null;
		}

		let computePeaks: typeof import('./compute').computePeaks;

		try {
			( { computePeaks } = await import(
				/* webpackChunkName: "imagina-compute" */ './compute'
			) );
		} catch {
			return null;
		}

		if ( this.destroyed ) {
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
				'unknown-size':
					'the server did not say how big this file is, so it was not downloaded to be measured. Generate the waveform on the server or from the block editor.',
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

	/**
	 * The floating player, from its own chunk — only a player set to follow
	 * the reader ever fetches it.
	 */
	private async setupSticky(): Promise< void > {
		if ( typeof IntersectionObserver === 'undefined' ) {
			return;
		}

		try {
			const { Sticky } = await import(
				/* webpackChunkName: "imagina-sticky" */ './sticky'
			);

			if ( this.destroyed ) {
				return;
			}

			this.sticky = new Sticky( {
				root: this.root,
				media: this.media,
				element: this.element,
				changed: () => {
					this.waveform?.resize();
					this.render();
				},
			} );
		} catch {
			// The player stays where it is, which is where it was anyway.
		}
	}

	private onDurationKnown(): void {
		const duration = this.duration();

		if ( this.totalTimeEl && duration > 0 ) {
			this.totalTimeEl.textContent = formatTime( duration );
		}

		/*
		 * A remembered position at the very end, or past it — the file was
		 * replaced by a shorter one — is not somewhere to resume: playing
		 * from it ends at once and starts again from nothing, under a chip
		 * claiming otherwise. Forgotten, and the start time applies.
		 */
		if (
			this.resumedAt > 0 &&
			duration > 0 &&
			this.resumedAt >= duration - RESUME_WORTH
		) {
			this.resumedAt = 0;
			this.clearPosition();
			this.hideResume();
			this.seekTo(
				this.config.startTime > 0 ? this.config.startTime : 0
			);
		}

		this.render();
	}

	private onTimeUpdate = rafThrottle( (): void => {
		if ( this.scrubbing ) {
			return;
		}

		this.render();
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
	}

	/**
	 * What happens at the end of a track: `reset`, `loop` or `stop`.
	 *
	 * Read by a playlist, which decides the end of a list for itself.
	 */
	get endBehaviour(): string {
		return this.config.onEnd;
	}

	/**
	 * Set by a playlist that owns what happens at the end of each track. A
	 * player looping its track while the list moved on to the next one
	 * started a play the next track's load interrupted at once.
	 */
	endHandledElsewhere = false;

	private onEnded(): void {
		this.root.classList.remove( 'is-playing' );
		this.clearPosition();

		if ( this.endHandledElsewhere ) {
			this.render();

			return;
		}

		if ( 'loop' === this.config.onEnd ) {
			this.seekTo( 0 );
			void this.media.play().catch( () => undefined );

			return;
		}

		// `stop` leaves the playhead where it finished; `reset` rewinds it.
		if ( 'stop' !== this.config.onEnd ) {
			this.seekTo( 0 );
		}

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

	/**
	 * Where this player's position is kept.
	 *
	 * The waveform key only exists for a player that draws one, and a video
	 * on YouTube has no `src` of its own — so two such videos on one site
	 * shared a single empty key, and each opened where the other had
	 * stopped. The layer key is minted from the source for every player.
	 */
	private storageKey(): string {
		return `${ STORAGE_PREFIX }${
			this.config.peaksKey || this.config.layerKey || this.media.src
		}`;
	}

	/**
	 * Go back to where this viewer stopped, if they stopped somewhere.
	 *
	 * @return Whether a position was restored.
	 */
	private restorePosition(): boolean {
		this.hideResume();
		this.resumedAt = 0;
		this.lastSaved = -1;

		if ( ! this.config.remember ) {
			return false;
		}

		try {
			const stored = Number(
				window.localStorage.getItem( this.storageKey() ) ?? 0
			);

			if ( ! ( stored >= RESUME_WORTH ) ) {
				return false;
			}

			const duration = this.duration();

			// Known to be at the end already: not a place to resume.
			if ( duration > 0 && stored >= duration - RESUME_WORTH ) {
				this.clearPosition();

				return false;
			}

			this.resumedAt = stored;
			this.lastSaved = Math.floor( stored );
			this.seekTo( stored );
			this.offerResume( stored );

			return true;
		} catch {
			// Storage can be unavailable in private mode; the player still works.
			return false;
		}
	}

	/**
	 * Keep the position as playback moves, and when the visitor leaves.
	 *
	 * It used to be written from the animation-frame loop, which a browser
	 * stops in a background tab — so a listener who switched tabs for half
	 * an hour came back to where they switched — and four times a second
	 * while visible, whether or not the second had changed. Now on each new
	 * second, on pause, and when the page is hidden or closed.
	 */
	private bindPositionSaving(): void {
		if ( ! this.config.remember ) {
			return;
		}

		const save = (): void => this.savePosition();
		const leaving = (): void => {
			if ( 'hidden' === document.visibilityState ) {
				this.savePosition( true );
			}
		};
		const closing = (): void => this.savePosition( true );

		this.media.addEventListener( 'timeupdate', save );
		this.media.addEventListener( 'pause', closing );
		document.addEventListener( 'visibilitychange', leaving );
		window.addEventListener( 'pagehide', closing );

		this.unbindPositionSaving = () => {
			this.media.removeEventListener( 'timeupdate', save );
			this.media.removeEventListener( 'pause', closing );
			document.removeEventListener( 'visibilitychange', leaving );
			window.removeEventListener( 'pagehide', closing );
		};
	}

	private savePosition( force = false ): void {
		if ( ! this.config.remember || this.scrubbing ) {
			return;
		}

		const second = Math.floor( this.media.currentTime );

		if (
			second < RESUME_WORTH ||
			( ! force && second === this.lastSaved )
		) {
			return;
		}

		// The end is not a position worth keeping; `ended` clears it.
		const duration = this.duration();

		if ( duration > 0 && second >= duration - 1 ) {
			return;
		}

		this.lastSaved = second;

		try {
			window.localStorage.setItem( this.storageKey(), String( second ) );
		} catch {
			// Ignored.
		}
	}

	/**
	 * Take the "resume from" offer down — for a link to a moment, which says
	 * where to be more precisely than a memory does.
	 */
	dismissResume(): void {
		this.resumedAt = 0;
		this.hideResume();
	}

	/**
	 * Say where playback is picking up from, and offer the beginning.
	 *
	 * The chip is in its own chunk; a page where nothing is resumed never
	 * fetches it.
	 * @param seconds
	 */
	private offerResume( seconds: number ): void {
		import( /* webpackChunkName: "imagina-resume" */ './resume' )
			.then( ( { offerResume } ) => {
				// Gone before it arrived: the viewer, or a link, already
				// moved the playhead somewhere else.
				if (
					this.destroyed ||
					this.resumedAt !== seconds ||
					Math.abs( this.media.currentTime - seconds ) > 1
				) {
					return;
				}

				this.hideResume();
				this.resumeHide = offerResume(
					{
						root: this.root,
						media: this.media,
						i18n: this.runtime.i18n,
						restart: () => {
							this.clearPosition();
							this.seekTo( 0 );
						},
					},
					seconds
				);
			} )
			.catch( () => undefined );
	}

	private hideResume(): void {
		this.resumeHide?.();
		this.resumeHide = null;
	}

	private clearPosition(): void {
		this.lastSaved = -1;

		try {
			window.localStorage.removeItem( this.storageKey() );
		} catch {
			// Ignored.
		}
	}

	setSpeed( rate: number ): void {
		const index = SPEEDS.indexOf( rate );

		if ( index >= 0 ) {
			this.speedIndex = index;
		}

		this.media.playbackRate = rate;

		if ( this.speedButton ) {
			this.speedButton.textContent = `${ rate }×`;
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
			void this.media.play().catch( ( error: unknown ) => {
				if ( ! isBenignRefusal( error ) ) {
					void this.recoverSource();
				}
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

		/*
		 * The link that expired goes with the request: it is what shows this
		 * visitor was given the player. The server no longer hands a working
		 * link to anyone who only knows the file's number.
		 */
		let token = '';

		try {
			token =
				new URL(
					this.signedSrc,
					window.location.href
				).searchParams.get( 'imgpt' ) ?? '';
		} catch {
			// No link to prove anything with; the server will say no.
		}

		try {
			const response = await fetch(
				`${ this.runtime.restUrl }/stream-url?id=${ encodeURIComponent(
					String( this.config.protectedId )
				) }&token=${ encodeURIComponent( token ) }`,
				{
					credentials: 'same-origin',
					// As the visitor, when there is one: without the nonce a
					// logged-in request is treated as anonymous.
					headers: this.runtime.nonce
						? { 'X-WP-Nonce': this.runtime.nonce }
						: undefined,
				}
			);

			if ( ! response.ok ) {
				throw new Error( 'stream-url unavailable' );
			}

			const data = ( await response.json() ) as { url?: string };

			if ( ! data.url ) {
				throw new Error( 'stream-url empty' );
			}

			this.root.classList.remove( 'has-error' );

			/*
			 * A link lasts minutes and a lecture lasts hours, so a long session
			 * expires more than once. The one-refresh guard stops a loop on a
			 * link that is refused outright; once the new one has played for a
			 * while, the next expiry deserves a refresh of its own.
			 */
			window.setTimeout( () => {
				this.sourceRefreshed = false;
			}, SOURCE_REFRESH_GRACE );

			this.signedSrc = data.url;

			// A stream is fed by hls.js through a MediaSource; handing the
			// element the manifest's address would play nothing in any browser
			// but Safari. The stream takes the new address itself.
			if ( this.video?.replaceSource?.( data.url ) ) {
				return true;
			}

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
	/**
	 * Whether this player can take the track without leaving the page.
	 *
	 * A file into a file player, or a provider's video into that provider's
	 * frame. A YouTube video cannot be poured into a `<video>` element, nor
	 * a file into YouTube's frame; for those the list's own link is the
	 * honest answer, and the caller leaves it to be one.
	 * @param track
	 */
	canLoad( track: TrackChange ): boolean {
		const provider = this.config.video?.provider ?? '';

		if ( ! track.provider ) {
			return '' === provider;
		}

		return (
			track.provider === provider &&
			'function' === typeof this.standIn?.load
		);
	}

	loadTrack( track: TrackChange, play = true ): void {
		this.savePosition();
		this.hideResume();

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

		// A video's still comes back up for the new one, and the picture is
		// "not started" again until it plays.
		const poster =
			this.root.querySelector< HTMLImageElement >( '.imgp__poster img' );

		if ( poster && track.poster ) {
			poster.src = track.poster;
		}

		if ( this.config.video ) {
			this.root.classList.remove( 'is-started' );
		}

		// What the viewer closes or reaches on this track is remembered
		// against this track, not the one the page opened with.
		if ( track.layerKey ) {
			this.config.layerKey = track.layerKey;
		}

		if ( track.provider && this.standIn?.load ) {
			this.standIn.load(
				track.providerId ?? '',
				track.providerHash ?? '',
				play
			);
			this.render();
			this.restorePosition();

			return;
		}

		// The waveform belongs to the file, so it goes with it. Cleared before
		// the new source loads rather than after, or the old shape is on screen
		// under the new track for as long as the fetch takes.
		this.peaksRequested = false;
		this.waveform?.clear();
		this.root.classList.remove( 'imgp--no-peaks' );

		this.media.src = track.src;
		this.signedSrc = track.src;
		this.element?.load();

		if ( track.peaks ) {
			this.waveform?.setPeaks( decodePeaks( track.peaks ) );
		} else if ( this.config.peaksKey ) {
			void this.loadPeaks();
		}

		this.render();
		this.restorePosition();

		if ( play ) {
			void this.media.play().catch( ( error: unknown ) => {
				if ( ! isBenignRefusal( error ) ) {
					void this.recoverSource();
				}
			} );
		}
	}

	destroy(): void {
		this.destroyed = true;
		this.hideResume();
		this.unbindPositionSaving?.();
		this.sticky?.destroy();
		this.layers?.destroy();
		this.resizeObserver?.disconnect();
		this.video?.destroy();
		// A provider stand-in holds an iframe and a polling timer; neither
		// went away with the player before this.
		this.standIn?.destroy();

		if ( this.onWindowResize ) {
			window.removeEventListener( 'resize', this.onWindowResize );
		}

		instances.delete( this );
	}
}
