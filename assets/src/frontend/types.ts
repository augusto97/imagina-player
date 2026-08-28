export interface PlayerConfig {
	id: string;
	skin: string;
	/** Waveform mirrored around the centre line. */
	centered: boolean;
	bars: number;
	gap: number;
	reflection: number;
	resolution: number;
	startTime: number;
	skipSeconds: number;
	remember: boolean;
	/** What happens when the track finishes: reset, loop or stop. */
	onEnd: string;
	sticky: boolean;
	duration: number;
	peaksKey: string;
	peaksToken: string;
	canCompute: boolean;
	/** Attachment ID when the file is served through a signed, expiring link. */
	protectedId: number;
	/** Present only for video. Its absence is what keeps the video chunk unloaded. */
	video?: VideoConfig;
	/** Present only when a player carries one, for the same reason. */
	layers?: Array< {
		type: 'cta' | 'bar' | 'email';
		/** Percentage of the track at which it appears. */
		at: number;
		skip: boolean;
		list: string;
	} >;
}

/**
 * What the player core needs from whatever is playing.
 *
 * `HTMLMediaElement` satisfies this by simply being itself, which is the point:
 * for a file on this site nothing is adapted and nothing costs anything. A
 * video on YouTube or Vimeo cannot be an element — it is a frame this page is
 * not allowed to reach into — so it is driven through the provider's own API
 * and presented here as the same handful of members.
 *
 * Deliberately the members the core actually uses and no more. Widening this
 * means writing more of somebody else's player, and every one of them behaves
 * slightly differently.
 */
export interface PlayerMedia extends EventTarget {
	currentTime: number;
	readonly duration: number;
	readonly paused: boolean;
	readonly ended: boolean;
	volume: number;
	muted: boolean;
	playbackRate: number;
	src: string;
	readonly currentSrc: string;
	play: () => Promise< void >;
	pause: () => void;
	/** Only ever used to take the native controls off; a stand-in ignores it. */
	removeAttribute: ( name: string ) => void;
	setAttribute: ( name: string, value: string ) => void;
}

/** What a provider stand-in can do beyond the transport, if anything. */
export interface MediaCapabilities {
	/** Text tracks belong to us, rather than to somebody else's player. */
	captions: boolean;
	pictureInPicture: boolean;
	/** Full screen goes to the element itself rather than to the stage around it. */
	elementFullscreen: boolean;
}

export interface VideoConfig {
	/** As `w:h`, already validated server-side. */
	ratio: string;
	poster: string;
	/** Milliseconds of stillness before the controls fade out during playback. */
	hideAfter: number;
	/**
	 * Chapter starts, so markers can be drawn without re-parsing the VTT the
	 * browser was already given.
	 */
	chapters: Array< { start: number; title: string } >;
	/** The source is an HLS manifest, so adaptive streaming may be needed. */
	hls: boolean;
	/** `youtube`, `vimeo`, or absent when the file is served from here. */
	provider?: string;
	/** The provider's identifier for the video. */
	providerId?: string;
	/** Vimeo's unlisted hash, which the embed will not load without. */
	providerHash?: string;
	/** The frame address, built by the server so the privacy setting applies. */
	embedUrl?: string;
}

/** One item of a playlist, as the server hands it over. */
export interface TrackChange {
	src: string;
	title: string;
	artist?: string;
	thumbnail?: string;
	duration?: number;
	peaksKey?: string;
	/** Base64 peaks, when the server already had them measured. */
	peaks?: string;
	protectedId?: number;
}

export interface RuntimeData {
	restUrl: string;
	lazyInit: boolean;
	/** Largest file the browser may download and decode to build a waveform. */
	maxComputeBytes: number;
	/** Where the built files live, so lazily-loaded chunks can be found. */
	assetUrl?: string;
	i18n: Record< string, string >;
}

declare global {
	interface Window {
		imaginaPlayer?: RuntimeData;
	}

	/** Webpack's chunk base URL, writable at runtime. Its name, not ours. */
	// eslint-disable-next-line no-var, camelcase, @typescript-eslint/naming-convention
	var __webpack_public_path__: string;
}
