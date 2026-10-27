export interface EditorData {
	presets: Array< { value: string; label: string } >;
	skins: Record< string, string >;
	/** Separate, because a skin belongs to a medium and these arrange a picture. */
	videoSkins: Record< string, string >;
	overrides: Record< string, string >;
	presetShape: Record< string, string | number | boolean >;
	/** What each preset actually says, by preset key. */
	presetValues?: Record<
		string,
		Record< string, string | number | boolean >
	>;
	/** The site's video settings, by setting key. */
	video?: Record< string, string | number | boolean >;
	/** Video setting key => the block attribute that overrides it. */
	videoOverrides?: Record< string, string >;
	settingsUrl: string;
	frontendCss: string;
	frontendJs: string;
	frameCss: string;
	/** Where the player inside a preview asks for a stored waveform. */
	restUrl: string;
	/** Where the player's on-demand pieces live. */
	assetUrl: string;
	/** Those pieces, to be written into the preview frame with the script. */
	frontendChunks: string[];
}

declare global {
	interface Window {
		imaginaPlayerEditor?: EditorData;
	}
}
