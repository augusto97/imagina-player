/**
 * A video that lives on YouTube or Vimeo, driven as if it were an element.
 *
 * The page cannot reach into a provider's frame — that is what the same-origin
 * policy is for — so the only way to know where playback is, or to move it, is
 * to talk to the frame through `postMessage` using whatever protocol that
 * provider publishes. Both of them publish one. Neither of them publishes the
 * same one.
 *
 * What comes out is a `PlayerMedia`: the dozen members the player core uses,
 * with events named the way the core already listens for them. Everything
 * above this file — the scrub bar, the clock, the keyboard, the calls to
 * action at a percentage — then works on a YouTube video without knowing that
 * is what it is.
 *
 * Two things are deliberate and worth keeping.
 *
 * The frame is not created until somebody presses play. An iframe in the
 * markup is a request to Google on every page view whether or not anyone
 * watches, and that is both a third-party cookie and about half a megabyte
 * charged to a page that may never use it. Until then there is a still image,
 * which is what a visitor sees anyway.
 *
 * And the provider's own API script is loaded from the provider, lazily, at
 * the same moment. It is theirs and it must come from them; what this file
 * controls is *when*, which is the part that shows up in PageSpeed.
 */

import type { MediaCapabilities, PlayerMedia, VideoConfig } from './types';

/** Where each provider's API lives. Loaded on play, never before. */
const API = {
	youtube: 'https://www.youtube.com/iframe_api',
	vimeo: 'https://player.vimeo.com/api/player.js',
} as const;

/** How often to ask YouTube where it is. It does not volunteer the information. */
const POLL_MS = 250;

type Listeners = Record< string, Set< EventListenerOrEventListenerObject > >;

/**
 * The half of a media element that is the same whichever provider it is.
 *
 * Providers report state by telling us about it rather than by having it read
 * off them, so every value the core might ask for is mirrored here as playback
 * reports it. A number that has not arrived yet is zero, which is what an
 * element that has not loaded its metadata says too.
 */
abstract class ProviderMedia extends EventTarget implements PlayerMedia {
	protected readonly host: HTMLElement;

	protected readonly config: VideoConfig;

	protected time = 0;

	protected length = 0;

	protected isPaused = true;

	protected isEnded = false;

	protected level = 1;

	protected isMuted = false;

	protected rate = 1;

	/** Set once the frame exists and its API has said it is ready. */
	protected ready = false;

	/** Anything asked for before the frame was ready, replayed once it is. */
	protected readonly queued: Array< () => void > = [];

	private readonly listeners: Listeners = {};

	constructor( host: HTMLElement, config: VideoConfig ) {
		super();
		this.host = host;
		this.config = config;
	}

	abstract capabilities: MediaCapabilities;

	/** Build the frame and connect to it. Called once, on the first play. */
	protected abstract mount(): Promise< void >;

	protected abstract command( name: string, value?: number | boolean ): void;

	get currentTime(): number {
		return this.time;
	}

	set currentTime( value: number ) {
		this.time = value;
		this.run( () => this.command( 'seek', value ) );
		this.emit( 'timeupdate' );
	}

	get duration(): number {
		return this.length;
	}

	get paused(): boolean {
		return this.isPaused;
	}

	get ended(): boolean {
		return this.isEnded;
	}

	get volume(): number {
		return this.level;
	}

	set volume( value: number ) {
		this.level = value;
		this.run( () => this.command( 'volume', value ) );
		this.emit( 'volumechange' );
	}

	get muted(): boolean {
		return this.isMuted;
	}

	set muted( value: boolean ) {
		this.isMuted = value;
		this.run( () => this.command( 'muted', value ) );
		this.emit( 'volumechange' );
	}

	get playbackRate(): number {
		return this.rate;
	}

	set playbackRate( value: number ) {
		this.rate = value;
		this.run( () => this.command( 'rate', value ) );
	}

	get src(): string {
		return this.config.embedUrl ?? '';
	}

	set src( _value: string ) {
		/*
		 * The core rewrites `src` when a signed link expires. A provider link
		 * never expires and is not ours to sign, so there is nothing to do —
		 * but it must not throw, because the core does not know the difference.
		 */
	}

	get currentSrc(): string {
		return this.src;
	}

	async play(): Promise< void > {
		if ( ! this.ready ) {
			await this.mount();
		}

		this.command( 'play' );
	}

	pause(): void {
		this.run( () => this.command( 'pause' ) );
	}

	removeAttribute(): void {
		// There are no native controls on a stand-in to take off.
	}

	setAttribute(): void {
		// Likewise.
	}

	/*
	 * `EventTarget` is inherited rather than reimplemented, so `addEventListener`
	 * behaves exactly as it does on an element — including `once` and removal —
	 * and the core needs no special case.
	 */
	protected emit( type: string ): void {
		this.dispatchEvent( new Event( type ) );
	}

	/**
	 * Do it now if the frame is ready, or remember it until it is.
	 * @param action
	 */
	protected run( action: () => void ): void {
		if ( this.ready ) {
			action();

			return;
		}

		this.queued.push( action );
	}

	protected flush(): void {
		this.ready = true;

		while ( this.queued.length ) {
			this.queued.shift()?.();
		}
	}

	/**
	 * Called by the subclass whenever the provider reports where it is.
	 * @param time
	 * @param duration
	 */
	protected report( time: number, duration: number ): void {
		if ( duration > 0 && duration !== this.length ) {
			this.length = duration;
			this.emit( 'durationchange' );
			this.emit( 'loadedmetadata' );
		}

		if ( time !== this.time ) {
			this.time = time;
			this.emit( 'timeupdate' );
		}
	}

	protected setPaused( paused: boolean ): void {
		if ( paused === this.isPaused ) {
			return;
		}

		this.isPaused = paused;

		if ( ! paused ) {
			this.isEnded = false;
		}

		this.emit( paused ? 'pause' : 'play' );

		if ( ! paused ) {
			this.emit( 'playing' );
		}
	}

	protected finish(): void {
		this.isEnded = true;
		this.isPaused = true;
		this.emit( 'ended' );
	}

	/** The box the frame goes in, once there is one. */
	protected frameHost(): HTMLElement {
		return this.host;
	}

	destroy(): void {
		this.queued.length = 0;
		this.host.replaceChildren();
		void this.listeners;
	}
}

/** Load a third-party script once, and let everyone waiting know. */
const scripts = new Map< string, Promise< void > >();

function loadScript( src: string ): Promise< void > {
	const existing = scripts.get( src );

	if ( existing ) {
		return existing;
	}

	const pending = new Promise< void >( ( resolve, reject ) => {
		const tag = document.createElement( 'script' );

		tag.src = src;
		tag.async = true;
		tag.onload = () => resolve();
		tag.onerror = () => reject( new Error( 'provider-script-failed' ) );
		document.head.appendChild( tag );
	} );

	scripts.set( src, pending );

	return pending;
}

class YouTubeMedia extends ProviderMedia {
	capabilities: MediaCapabilities = {
		// YouTube renders its own subtitles inside the frame, and refuses to
		// hand the text out. Ours would be a second set on top of theirs.
		captions: false,
		pictureInPicture: false,
		elementFullscreen: false,
	};

	private player: YT.Player | null = null;

	private timer = 0;

	protected async mount(): Promise< void > {
		await loadReadyYouTube();

		const api = window.YT;

		if ( ! api ) {
			throw new Error( 'youtube-api-missing' );
		}

		const mountPoint = document.createElement( 'div' );

		this.frameHost().replaceChildren( mountPoint );

		await new Promise< void >( ( resolve ) => {
			this.player = new api.Player( mountPoint, {
				videoId: this.config.providerId ?? '',
				playerVars: {
					// No related videos from other channels at the end, no
					// YouTube chrome we are about to draw ourselves, and no
					// keyboard handling competing with the page's own.
					rel: 0,
					modestbranding: 1,
					controls: 0,
					disablekb: 1,
					playsinline: 1,
					// Required by YouTube for a frame not on youtube.com.
					origin: window.location.origin,
				},
				events: {
					onReady: () => {
						this.flush();
						this.watch();
						resolve();
					},
					onStateChange: ( event: YT.OnStateChangeEvent ) => {
						// 0 ended, 1 playing, 2 paused, 3 buffering.
						if ( 0 === event.data ) {
							this.finish();
						} else if ( 1 === event.data ) {
							this.setPaused( false );
						} else if ( 2 === event.data ) {
							this.setPaused( true );
						} else if ( 3 === event.data ) {
							this.emit( 'waiting' );
						}
					},
					onError: () => this.emit( 'error' ),
				},
			} );
		} );
	}

	/*
	 * YouTube reports that state changed but never where playback is, so the
	 * clock has to be asked. Four times a second matches what a `timeupdate`
	 * fires at natively, which is what everything above expects.
	 */
	private watch(): void {
		window.clearInterval( this.timer );

		this.timer = window.setInterval( () => {
			if ( ! this.player ) {
				return;
			}

			this.report(
				this.player.getCurrentTime() || 0,
				this.player.getDuration() || 0
			);
		}, POLL_MS );
	}

	protected command( name: string, value?: number | boolean ): void {
		const player = this.player;

		if ( ! player ) {
			return;
		}

		if ( 'play' === name ) {
			player.playVideo();
		} else if ( 'pause' === name ) {
			player.pauseVideo();
		} else if ( 'seek' === name ) {
			player.seekTo( Number( value ), true );
		} else if ( 'volume' === name ) {
			player.setVolume( Number( value ) * 100 );
		} else if ( 'muted' === name ) {
			if ( value ) {
				player.mute();
			} else {
				player.unMute();
			}
		} else if ( 'rate' === name ) {
			player.setPlaybackRate( Number( value ) );
		}
	}

	destroy(): void {
		window.clearInterval( this.timer );
		this.player?.destroy?.();
		this.player = null;
		super.destroy();
	}
}

/**
 * YouTube's script announces itself through a global callback rather than by
 * resolving anything, and it may already be on the page from another plugin.
 */
function loadReadyYouTube(): Promise< void > {
	if ( window.YT?.Player ) {
		return Promise.resolve();
	}

	return new Promise< void >( ( resolve, reject ) => {
		const previous = window.onYouTubeIframeAPIReady;

		window.onYouTubeIframeAPIReady = () => {
			previous?.();
			resolve();
		};

		loadScript( API.youtube ).catch( reject );
	} );
}

class VimeoMedia extends ProviderMedia {
	capabilities: MediaCapabilities = {
		captions: false,
		pictureInPicture: false,
		elementFullscreen: false,
	};

	private player: Vimeo.Player | null = null;

	protected async mount(): Promise< void > {
		await loadScript( API.vimeo );

		const mountPoint = document.createElement( 'div' );

		this.frameHost().replaceChildren( mountPoint );

		const player = new window.Vimeo.Player( mountPoint, {
			id: Number( this.config.providerId ?? 0 ),
			h: this.config.providerHash || undefined,
			controls: false,
			playsinline: true,
			dnt: true,
		} );

		this.player = player;

		// Unlike YouTube, Vimeo reports progress on its own, so nothing is polled.
		player.on(
			'timeupdate',
			( data: { seconds: number; duration: number } ) =>
				this.report( data.seconds, data.duration )
		);
		player.on( 'play', () => this.setPaused( false ) );
		player.on( 'pause', () => this.setPaused( true ) );
		player.on( 'ended', () => this.finish() );
		player.on( 'bufferstart', () => this.emit( 'waiting' ) );
		player.on( 'error', () => this.emit( 'error' ) );

		await player.ready();

		const length = await player.getDuration().catch( () => 0 );

		this.report( 0, length );
		this.flush();
	}

	protected command( name: string, value?: number | boolean ): void {
		const player = this.player;

		if ( ! player ) {
			return;
		}

		const ignore = (): void => undefined;

		if ( 'play' === name ) {
			void player.play().catch( ignore );
		} else if ( 'pause' === name ) {
			void player.pause().catch( ignore );
		} else if ( 'seek' === name ) {
			void player.setCurrentTime( Number( value ) ).catch( ignore );
		} else if ( 'volume' === name ) {
			void player.setVolume( Number( value ) ).catch( ignore );
		} else if ( 'muted' === name ) {
			void player.setMuted( Boolean( value ) ).catch( ignore );
		} else if ( 'rate' === name ) {
			void player.setPlaybackRate( Number( value ) ).catch( ignore );
		}
	}

	destroy(): void {
		void this.player?.destroy?.();
		this.player = null;
		super.destroy();
	}
}

/**
 * Build the stand-in for whatever the server said this is.
 *
 * @param root   The player element.
 * @param config The video half of the player config.
 */
export function createProviderMedia(
	root: HTMLElement,
	config: VideoConfig
):
	| ( PlayerMedia & { capabilities: MediaCapabilities; destroy: () => void } )
	| null {
	const host = root.querySelector< HTMLElement >( '.imgp__embed' );

	if ( ! host ) {
		return null;
	}

	if ( 'youtube' === config.provider ) {
		return new YouTubeMedia( host, config );
	}

	if ( 'vimeo' === config.provider ) {
		return new VimeoMedia( host, config );
	}

	return null;
}

/* Only the members used above. Pulling in the providers' own type packages
   would mean shipping their build tooling to describe two objects. */
declare global {
	namespace YT {
		interface OnStateChangeEvent {
			data: number;
		}

		interface Player {
			playVideo: () => void;
			pauseVideo: () => void;
			seekTo: ( seconds: number, allowSeekAhead: boolean ) => void;
			setVolume: ( percent: number ) => void;
			mute: () => void;
			unMute: () => void;
			setPlaybackRate: ( rate: number ) => void;
			getCurrentTime: () => number;
			getDuration: () => number;
			destroy?: () => void;
		}
	}

	namespace Vimeo {
		interface Player {
			play: () => Promise< void >;
			pause: () => Promise< void >;
			ready: () => Promise< void >;
			setCurrentTime: ( seconds: number ) => Promise< number >;
			getDuration: () => Promise< number >;
			setVolume: ( level: number ) => Promise< number >;
			setMuted: ( muted: boolean ) => Promise< boolean >;
			setPlaybackRate: ( rate: number ) => Promise< number >;
			on: ( event: string, handler: ( data: never ) => void ) => void;
			destroy?: () => Promise< void >;
		}
	}

	interface Window {
		YT?: {
			Player: new (
				element: HTMLElement,
				options: Record< string, unknown >
			) => YT.Player;
		};
		onYouTubeIframeAPIReady?: () => void;
		Vimeo: {
			Player: new (
				element: HTMLElement,
				options: Record< string, unknown >
			) => Vimeo.Player;
		};
	}
}
