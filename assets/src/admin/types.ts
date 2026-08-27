export interface Preset {
	label: string;
	skin: string;
	accent: string;
	wave_color: string;
	wave_progress: string;
	wave_bars: number;
	wave_gap: number;
	wave_reflection: number;
	text_color: string;
	meta_color: string;
	background: string;
	height: number;
	rounded_bars: boolean;
	show_artist: boolean;
	show_title: boolean;
	show_thumbnail: boolean;
	show_volume: boolean;
	show_time: boolean;
	show_download: boolean;
	show_speed: boolean;
	show_skip: boolean;
	skip_seconds: number;
	sticky: boolean;
	preload: string;
	remember_position: boolean;
	[ key: string ]: string | number | boolean;
}

export interface SettingsPayload {
	presets: Record< string, Preset >;
	peaks: {
		resolution: number;
		server_generation: boolean;
		client_fallback: boolean;
		ffmpeg_path: string;
		max_client_mb: number;
	};
	protection: {
		enabled: boolean;
		require_login: boolean;
		bind_to_user: boolean;
		bind_to_ip: boolean;
		ttl: number;
		delivery: string;
		xaccel_prefix: string;
	};
	advanced: {
		load_frontend_css: boolean;
		lazy_init: boolean;
	};
	schema: {
		presetDefaults: Preset;
		skins: Record< string, string >;
		skinNotes: Record< string, string >;
		defaultPreset: string;
	};
	system: {
		ffmpeg: boolean;
		ffmpegBinary: string;
		vaultDir: string;
		vaultName: string;
		htaccess: boolean;
		version: string;
	};
}

export interface AdminBoot {
	restUrl: string;
	nonce: string;
	frontendCss: string;
	frontendJs: string;
	docsUrl: string;
}

declare global {
	interface Window {
		imaginaPlayerAdmin?: AdminBoot;
	}
}
