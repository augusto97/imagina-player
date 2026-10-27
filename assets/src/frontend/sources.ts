/**
 * Other files for the same video: a quality each, and soundtracks played in
 * step with the picture — a dubbing.
 *
 * Its own chunk, loaded only by a video that has either, or by a stream
 * Safari plays by itself, which lists its own soundtracks.
 *
 * A soundtrack beside the video is a second, hidden audio element that
 * follows the picture: it plays, pauses, seeks and changes speed with it, is
 * put back when it drifts, and holds the picture while it catches up. The
 * picture's own sound is muted meanwhile, and the player's volume and mute —
 * which know nothing of this — are pointed at the soundtrack instead, so the
 * slider and the button go on doing what they say.
 */

import type { AudioChoices, SourceChoice, VideoConfig } from './types';

interface Host {
	root: HTMLElement;
	element: HTMLVideoElement;
	menu: (
		items: Array< { label: string; active: boolean; onPick: () => void } >,
		opener: HTMLElement | null
	) => void;
	switchSource: ( src: string, protectedId: number ) => void;
	audio: ( choices: AudioChoices ) => void;
}

/** A soundtrack as Safari lists it for a stream. */
interface NativeTrack {
	label: string;
	language: string;
	enabled: boolean;
}

interface NativeTrackList {
	length: number;
	[ index: number ]: NativeTrack;
	addEventListener: EventTarget[ 'addEventListener' ];
}

/** The quality and the language last chosen, for every video on the site. */
const QUALITY_KEY = 'imagina-player:quality';
const AUDIO_KEY = 'imagina-player:audio';

/** Seconds the soundtrack may wander from the picture before it is put back. */
const DRIFT = 0.3;

/** At most one correction this often, but a seek by the viewer. */
const RESEEK_MS = 1000;

/** The picture waits for the soundtrack at most this often, and this long. */
const HOLD_GAP_MS = 2500;
const HOLD_MAX_MS = 4000;

/** Corrections that did not take before the soundtrack is put away. */
const MISSES = 2;

const MUTED = Object.getOwnPropertyDescriptor(
	HTMLMediaElement.prototype,
	'muted'
) as PropertyDescriptor;
const VOLUME = Object.getOwnPropertyDescriptor(
	HTMLMediaElement.prototype,
	'volume'
) as PropertyDescriptor;

function read( key: string ): string {
	try {
		return window.localStorage.getItem( key ) ?? '';
	} catch {
		return '';
	}
}

function keep( key: string, value: string ): void {
	try {
		window.localStorage.setItem( key, value );
	} catch {
		// Storage off: this page keeps the choice, the next does not.
	}
}

export class Sources {
	private readonly host: Host;

	private readonly qualities: SourceChoice[];

	private readonly dubs: SourceChoice[];

	private quality = 0;

	private dubIndex = 0;

	/** The soundtrack's own element, made the first time one is chosen. */
	private dub: HTMLAudioElement | null = null;

	/** The picture was paused to let the soundtrack catch up. */
	private held = false;

	/** When it last was, so that it cannot happen over and over. */
	private heldAt = 0;

	/** A signed soundtrack has been refreshed lately. */
	private refreshed = false;

	private readonly cleanup: Array< () => void > = [];

	constructor( host: Host, config: VideoConfig, native: boolean ) {
		this.host = host;
		this.qualities = config.qualities ?? [];
		this.dubs = config.dubs ?? [];

		if ( this.qualities.length > 1 ) {
			this.bindQuality();
		}

		if ( this.dubs.length > 1 ) {
			this.bindDubs();
		}

		if ( native ) {
			this.bindNative();
		}
	}

	destroy(): void {
		this.pickDub( 0, false );

		for ( const off of this.cleanup ) {
			off();
		}

		this.cleanup.length = 0;
	}

	private on(
		target: EventTarget,
		type: string,
		listener: () => void
	): void {
		target.addEventListener( type, listener );
		this.cleanup.push( () => target.removeEventListener( type, listener ) );
	}

	private bindQuality(): void {
		const button = this.host.root.querySelector< HTMLButtonElement >(
			'.imgp__vbtn--quality'
		);
		const stored = read( QUALITY_KEY );
		const index = this.qualities.findIndex(
			( choice ) => choice.label === stored
		);

		if ( index > 0 ) {
			this.pickQuality( index, false );
		}

		if ( ! button ) {
			return;
		}

		button.hidden = false;
		this.on( button, 'click', () =>
			this.host.menu(
				this.qualities.map( ( choice, i ) => ( {
					label: choice.label,
					active: i === this.quality,
					onPick: () => this.pickQuality( i, true ),
				} ) ),
				button
			)
		);
	}

	private pickQuality( index: number, remember: boolean ): void {
		const choice = this.qualities[ index ];

		if ( ! choice || index === this.quality ) {
			return;
		}

		this.quality = index;
		this.host.switchSource( choice.src, choice.protectedId );

		if ( remember ) {
			keep( QUALITY_KEY, choice.label );
		}
	}

	private bindDubs(): void {
		this.host.audio( {
			labels: () => this.dubs.map( ( dub ) => dub.label ),
			current: () => this.dubIndex,
			pick: ( index ) => this.pickDub( index, true ),
		} );

		const stored = read( AUDIO_KEY );
		const index = stored
			? this.dubs.findIndex(
					( dub, i ) =>
						i > 0 &&
						( dub.srclang === stored || dub.label === stored )
			  )
			: -1;

		if ( index > 0 ) {
			this.pickDub( index, false );
		}
	}

	private pickDub( index: number, remember: boolean ): void {
		const choice = this.dubs[ index ];

		if ( ! choice || index === this.dubIndex ) {
			return;
		}

		if ( 0 === index ) {
			this.stopDub();
		} else {
			this.startDub( choice );
		}

		this.dubIndex = index;

		if ( remember ) {
			keep( AUDIO_KEY, index ? choice.srclang || choice.label : '' );
		}
	}

	private startDub( choice: SourceChoice ): void {
		const video = this.host.element;
		const audio = this.dub ?? this.makeDub();

		// From the picture's own sound: the listener's volume goes with it,
		// and the player's controls are pointed at the soundtrack.
		if ( 0 === this.dubIndex ) {
			audio.volume = VOLUME.get?.call( video ) as number;
			audio.muted = MUTED.get?.call( video ) as boolean;
			MUTED.set?.call( video, true );

			for ( const [ name, descriptor ] of [
				[ 'volume', VOLUME ],
				[ 'muted', MUTED ],
			] as const ) {
				Object.defineProperty( video, name, {
					configurable: true,
					get: () => descriptor.get?.call( audio ),
					set: ( value: unknown ) => {
						descriptor.set?.call( audio, value );
						video.dispatchEvent( new Event( 'volumechange' ) );
					},
				} );
			}
		}

		this.refreshed = false;
		audio.src = choice.src;
		audio.playbackRate = video.playbackRate;
		audio.currentTime = video.currentTime;

		if ( ! video.paused ) {
			void audio.play().catch( () => undefined );
		}
	}

	private stopDub(): void {
		const video = this.host.element;
		const audio = this.dub;

		if ( ! audio || 0 === this.dubIndex ) {
			return;
		}

		// The picture's own sound back, at the volume the listener left.
		Reflect.deleteProperty( video, 'volume' );
		Reflect.deleteProperty( video, 'muted' );
		VOLUME.set?.call( video, audio.volume );
		MUTED.set?.call( video, audio.muted );

		audio.pause();
		audio.removeAttribute( 'src' );
		audio.load();
		this.held = false;
	}

	/**
	 * The hidden element, and the picture's events it follows.
	 */
	private makeDub(): HTMLAudioElement {
		const video = this.host.element;
		const audio = video.ownerDocument.createElement( 'audio' );

		audio.preload = 'auto';
		this.dub = audio;

		const live = (): boolean => this.dubIndex > 0;
		let lastSeek = 0;
		let target = -1;
		let misses = 0;
		let holdTimer = 0;

		/*
		 * Put back only when it has wandered, and not over and over: putting
		 * a soundtrack back makes it stop and fetch again, and a file its
		 * server cannot seek in never arrives where it is sent.
		 */
		const follow = ( force: boolean ): void => {
			if (
				live() &&
				Math.abs( audio.currentTime - video.currentTime ) > DRIFT &&
				( force || Date.now() - lastSeek > RESEEK_MS )
			) {
				lastSeek = Date.now();
				target = video.currentTime;
				audio.currentTime = target;
			}
		};
		const release = (): void => {
			window.clearTimeout( holdTimer );

			if ( this.held ) {
				this.held = false;
				void video.play().catch( () => undefined );
			}
		};

		this.on( video, 'play', () => {
			if ( live() ) {
				follow( false );
				void audio.play().catch( () => undefined );
			}
		} );

		for ( const type of [ 'pause', 'waiting', 'emptied', 'ended' ] ) {
			this.on( video, type, () => audio.pause() );
		}

		this.on( video, 'playing', () => {
			if ( live() && audio.paused ) {
				void audio.play().catch( () => undefined );
			}
		} );
		this.on( video, 'seeked', () => follow( true ) );
		this.on( video, 'timeupdate', () => ! video.paused && follow( false ) );
		this.on( video, 'ratechange', () => {
			audio.playbackRate = video.playbackRate;
		} );

		// A correction that did not take: twice, and the soundtrack cannot
		// be kept with the picture, which goes back to its own sound.
		this.on( audio, 'seeked', () => {
			if ( target < 0 ) {
				return;
			}

			misses =
				Math.abs( audio.currentTime - target ) > DRIFT ? misses + 1 : 0;
			target = -1;

			if ( misses >= MISSES ) {
				misses = 0;
				release();
				this.pickDub( 0, false );
			}
		} );

		// The soundtrack ran out of data: the picture waits for it, now and
		// then and not for long.
		this.on( audio, 'waiting', () => {
			if (
				live() &&
				! video.paused &&
				Date.now() - this.heldAt > HOLD_GAP_MS
			) {
				this.held = true;
				this.heldAt = Date.now();
				video.pause();
				holdTimer = window.setTimeout( release, HOLD_MAX_MS );
			}
		} );
		this.on( audio, 'canplay', release );
		this.on( audio, 'error', () => void this.recover() );

		return audio;
	}

	/**
	 * A soundtrack that will not play. A protected one is asked for again on a
	 * fresh signed address, once; anything else, or a second failure, goes
	 * back to the picture's own sound rather than leave the video silent.
	 */
	private async recover(): Promise< void > {
		const audio = this.dub;
		const choice = this.dubs[ this.dubIndex ];
		const runtime = window.imaginaPlayer;
		const video = this.host.element;

		if ( ! audio || ! choice || ! this.dubIndex || ! audio.src ) {
			return;
		}

		if ( choice.protectedId && ! this.refreshed && runtime?.restUrl ) {
			this.refreshed = true;

			try {
				const token =
					new URL( audio.src ).searchParams.get( 'imgpt' ) ?? '';
				const response = await fetch(
					`${ runtime.restUrl }/stream-url?id=${
						choice.protectedId
					}&token=${ encodeURIComponent( token ) }`,
					{
						credentials: 'same-origin',
						headers: runtime.nonce
							? { 'X-WP-Nonce': runtime.nonce }
							: undefined,
					}
				);
				const data = ( await response.json() ) as { url?: string };

				if ( response.ok && data.url ) {
					audio.src = data.url;
					audio.currentTime = video.currentTime;

					if ( ! video.paused ) {
						void audio.play().catch( () => undefined );
					}

					// A long film outlives more than one signed address.
					window.setTimeout( () => {
						this.refreshed = false;
					}, 60000 );

					return;
				}
			} catch {
				// Falls through to the picture's own sound.
			}
		}

		this.pickDub( 0, false );
	}

	/**
	 * A stream Safari plays by itself carries its soundtracks in the element,
	 * listed as they are found.
	 */
	private bindNative(): void {
		const tracks = (
			this.host.element as unknown as { audioTracks?: NativeTrackList }
		 ).audioTracks;

		if ( ! tracks ) {
			return;
		}

		const all = (): NativeTrack[] => Array.from( tracks );
		const pick = ( index: number ): void =>
			all().forEach( ( track, i ) => {
				track.enabled = i === index;
			} );

		const offer = (): void => {
			const list = all();

			if ( list.length < 2 ) {
				return;
			}

			const stored = read( AUDIO_KEY );
			const preferred = stored
				? list.findIndex(
						( track ) =>
							track.language === stored || track.label === stored
				  )
				: -1;

			if ( preferred >= 0 ) {
				pick( preferred );
			}

			this.host.audio( {
				labels: () =>
					all().map(
						( track, i ) =>
							track.label || track.language || String( i + 1 )
					),
				current: () =>
					Math.max(
						0,
						all().findIndex( ( track ) => track.enabled )
					),
				pick: ( index ) => {
					pick( index );

					const track = all()[ index ];

					keep( AUDIO_KEY, track?.language || track?.label || '' );
				},
			} );
		};

		this.on( tracks as unknown as EventTarget, 'addtrack', offer );
		offer();
	}
}
