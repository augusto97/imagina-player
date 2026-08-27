export interface PlayerConfig {
	id: string;
	skin: 'wave' | 'bar' | 'minimal';
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
	i18n: Record< string, string >;
}

declare global {
	interface Window {
		imaginaPlayer?: RuntimeData;
	}
}
