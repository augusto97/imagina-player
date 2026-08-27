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
	sticky: boolean;
	duration: number;
	peaksKey: string;
	peaksToken: string;
	canCompute: boolean;
	/** Attachment ID when the file is served through a signed, expiring link. */
	protectedId: number;
}

export interface RuntimeData {
	restUrl: string;
	lazyInit: boolean;
	/** Largest file the browser may download and decode to build a waveform. */
	maxComputeBytes: number;
	i18n: Record< string, string >;
}

declare global {
	interface Window {
		imaginaPlayer?: RuntimeData;
	}
}
