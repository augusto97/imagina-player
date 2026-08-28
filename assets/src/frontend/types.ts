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
