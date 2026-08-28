export interface EditorData {
	presets: Array< { value: string; label: string } >;
	skins: Record< string, string >;
	/** Separate, because a skin belongs to a medium and these arrange a picture. */
	videoSkins: Record< string, string >;
	overrides: Record< string, string >;
	presetShape: Record< string, string | number | boolean >;
	settingsUrl: string;
	frontendCss: string;
	frontendJs: string;
	frameCss: string;
}

declare global {
	interface Window {
		imaginaPlayerEditor?: EditorData;
	}
}
