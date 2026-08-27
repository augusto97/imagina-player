import { computePeaks, decodePeaks, rememberPeaks, sharedPeaks, storePeaks } from './peaks';
import type { PlayerConfig, RuntimeData } from './types';
import { clamp, formatTime, rafThrottle } from './utils';
import { Waveform } from './waveform';

const SPEEDS = [ 1, 1.25, 1.5, 2, 0.75 ];

const STORAGE_PREFIX = 'imagina-player:position:';

/**
 * Players registered on the page, so starting one can stop the rest.
 */
const instances = new Set< Player >();

export class Player {
	readonly root: HTMLElement;

	readonly media: HTMLMediaElement;

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

	private scrubbing = false;

	private speedIndex = 0;

	private peaksRequested = false;

	private sourceRefreshed = false;

	private destroyed = false;

	constructor( root: HTMLElement, runtime: RuntimeData ) {
		this.root = root;
		this.runtime = runtime;
		this.config = JSON.parse( root.dataset.imaginaPlayer || '{}' ) as PlayerConfig;

		const media = root.querySelector< HTMLMediaElement >( '.imgp__media' );

		if ( ! media ) {
			throw new Error( 'Imagina Player: no media element found.' );
		}

		this.media = media;
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

		instances.add( this );
	}

	private bindMedia(): void {
		const media = this.media;

		media.addEventListener( 'loadedmetadata', () => this.onDurationKnown() );
		media.addEventListener( 'durationchange', () => this.onDurationKnown() );
		media.addEventListener( 'timeupdate', this.onTimeUpdate );
		media.addEventListener( 'play', () => this.onPlay() );
		media.addEventListener( 'pause', () => this.onPause() );
		media.addEventListener( 'ended', () => this.onEnded() );
		media.addEventListener( 'waiting', () => this.root.classList.add( 'is-buffering' ) );
		media.addEventListener( 'playing', () => this.root.classList.remove( 'is-buffering' ) );
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
				this.speedButton.textContent = `${ SPEEDS[ this.speedIndex ] }×`;
			}
		} );

		this.root.querySelector( '.imgp__skip--back' )?.addEventListener( 'click', () => {
			this.seekTo( this.media.currentTime - this.config.skipSeconds );
		} );

		this.root.querySelector( '.imgp__skip--forward' )?.addEventListener( 'click', () => {
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

			return rect.width > 0 ? clamp( ( event.clientX - rect.left ) / rect.width, 0, 1 ) : 0;
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
		const canvas = this.root.querySelector< HTMLCanvasElement >( '.imgp__wave' );

		if ( ! canvas || this.config.skin !== 'wave' ) {
			return;
		}

		this.waveform = new Waveform( canvas, {
			barWidth: Math.max( 1, this.config.bars || 3 ),
			gap: Math.max( 0, this.config.gap ?? 1 ),
			reflection: clamp( this.config.reflection ?? 0.25, 0, 0.8 ),
			rounded: this.root.classList.contains( 'imgp--rounded' ),
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
			return;
		}

		this.peaksRequested = true;
		this.root.classList.add( 'is-analyzing' );

		// `sharedPeaks` collapses every player showing this track into one
		// request — and, on a cold cache, one download and decode.
		const peaks = await sharedPeaks( this.config.peaksKey, () => this.fetchOrComputePeaks() );

		this.root.classList.remove( 'is-analyzing' );

		if ( peaks && ! this.destroyed ) {
			this.waveform?.setPeaks( peaks );
		}
	}

	/**
	 * Ask the REST cache first — the waveform may have been generated since this
	 * page was rendered — then fall back to decoding the file here and handing
	 * the result back to the site.
	 */
	private async fetchOrComputePeaks(): Promise< Float32Array | null > {
		try {
			const url = `${ this.runtime.restUrl }/peaks?key=${ encodeURIComponent( this.config.peaksKey ) }`;
			const response = await fetch( url );

			if ( response.ok ) {
				const data = ( await response.json() ) as { peaks?: string | null };

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

		const computed = await computePeaks( this.media.currentSrc || this.media.src, this.config.resolution );

		if ( ! computed ) {
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

		this.stickyObserver = new IntersectionObserver(
			( entries ) => {
				const entry = entries[ 0 ];

				if ( ! entry ) {
					return;
				}

				// Only detach while something is actually playing — a paused player
				// scrolling by should not pin itself to the viewport.
				const shouldStick = ! entry.isIntersecting && ! this.media.paused;

				this.setStuck( shouldStick );
			},
			{ threshold: 0 }
		);

		this.stickyObserver.observe( this.root );
	}

	private setStuck( stuck: boolean ): void {
		if ( stuck === this.root.classList.contains( 'is-stuck' ) ) {
			return;
		}

		if ( stuck ) {
			const height = this.root.offsetHeight;

			this.stickyPlaceholder = document.createElement( 'div' );
			this.stickyPlaceholder.className = 'imgp__sticky-placeholder';
			this.stickyPlaceholder.style.height = `${ height }px`;
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
		this.playButton?.setAttribute( 'aria-label', this.runtime.i18n.pause ?? 'Pause' );

		// Colours can change after a theme swap or a dark-mode toggle.
		this.applyWaveColors();
		this.waveform?.resize();
	}

	private onPause(): void {
		this.root.classList.remove( 'is-playing' );
		this.playButton?.setAttribute( 'aria-pressed', 'false' );
		this.playButton?.setAttribute( 'aria-label', this.runtime.i18n.play ?? 'Play' );

		if ( this.root.classList.contains( 'is-stuck' ) ) {
			this.setStuck( false );
		}
	}

	private onEnded(): void {
		this.root.classList.remove( 'is-playing' );
		this.clearPosition();
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
		const ratio = duration > 0 ? clamp( this.media.currentTime / duration, 0, 1 ) : 0;

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
			this.seek.setAttribute( 'aria-valuenow', String( Math.round( ratio * 100 ) ) );
			this.seek.setAttribute( 'aria-valuetext', formatTime( time ) );
		}
	}

	private syncVolumeUi(): void {
		const muted = this.media.muted || this.media.volume === 0;

		this.root.classList.toggle( 'is-muted', muted );
		this.muteButton?.setAttribute( 'aria-pressed', muted ? 'true' : 'false' );
		this.muteButton?.setAttribute(
			'aria-label',
			muted ? this.runtime.i18n.unmute ?? 'Unmute' : this.runtime.i18n.mute ?? 'Mute'
		);

		if ( this.volumeSlider ) {
			this.volumeSlider.value = String( muted ? 0 : this.media.volume );
		}
	}

	private duration(): number {
		if ( Number.isFinite( this.media.duration ) && this.media.duration > 0 ) {
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
			window.localStorage.setItem( this.storageKey(), String( Math.floor( this.media.currentTime ) ) );
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
		const target = duration > 0 ? clamp( seconds, 0, duration ) : Math.max( 0, seconds );

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
		if ( ! this.config.protectedId || this.sourceRefreshed || ! this.runtime.restUrl ) {
			this.root.classList.add( 'has-error' );

			return false;
		}

		this.sourceRefreshed = true;

		const resumeAt = this.media.currentTime;
		const wasPlaying = ! this.media.paused;

		try {
			const response = await fetch(
				`${ this.runtime.restUrl }/stream-url?id=${ encodeURIComponent( String( this.config.protectedId ) ) }`,
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
			this.media.load();

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

	destroy(): void {
		this.destroyed = true;
		this.resizeObserver?.disconnect();
		this.stickyObserver?.disconnect();
		instances.delete( this );
	}
}
