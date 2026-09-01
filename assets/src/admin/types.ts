export interface Preset {
	label: string;
	description: string;
	skin: string;
	accent: string;
	wave_color: string;
	wave_progress: string;
	wave_bars: number;
	wave_gap: number;
	wave_reflection: number;
	text_color: string;
	meta_color: string;
	control_color: string;
	background: string;
	height: number;
	border_radius: number;
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
	sticky_position: string;
	on_end: string;
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
		custom_css: string;
	};
	branding: {
		accent: string;
		wave_color: string;
		text_color: string;
		meta_color: string;
		control_color: string;
		logo: string;
		logo_link: string;
		logo_height: number;
	};
	metadata: {
		title_from: string;
		artist_from: string;
		from_filename: boolean;
		use_cover: boolean;
	};
	video: {
		ratio: string;
		hide_after: number;
		show_pip: boolean;
		show_fullscreen: boolean;
		show_speed: boolean;
		big_play: boolean;
		block_download: boolean;
		/** Load YouTube from the domain that sets no cookie before playback. */
		provider_privacy: boolean;
		/** Hide the provider's own interface on a video hosted elsewhere. */
		provider_bare: boolean;
		/** The bar over the picture, and the subtitle text on it. */
		chrome_color: string;
		caption_color: string;
		control_color: string;
		progress_color: string;
		/* The rest of the controls, each its own answer for video. */
		show_captions: boolean;
		show_chapters: boolean;
		/** A box that finds the moment a word is said. */
		show_search: boolean;
		show_skip: boolean;
		show_time: boolean;
		show_volume: boolean;
		show_title: boolean;
		/** Stop when the tab is hidden or the picture leaves the screen. */
		focus_mode: boolean;
		captions_on: boolean;
		poster_fit: string;
		caption_size: string;
		caption_bg: string;
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
		ffmpegState:
			| 'ok'
			| 'processes-disabled'
			| 'path-missing'
			| 'path-not-ffmpeg'
			| 'not-installed';
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
	frameCss: string;
	docsUrl: string;
}

declare global {
	interface Window {
		imaginaPlayerAdmin?: AdminBoot;
	}
}
