export interface EditorData {
	presets: Array< { value: string; label: string } >;
	skins: Record< string, string >;
	overrides: Record< string, string >;
	presetShape: Record< string, string | number | boolean >;
	settingsUrl: string;
	frontendCss: string;
	frontendJs: string;
}

declare global {
	interface Window {
		imaginaPlayerEditor?: EditorData;
	}
}
