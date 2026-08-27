import apiFetch from '@wordpress/api-fetch';

import type { AdminBoot, Preset, SettingsPayload } from './types';

export function boot(): AdminBoot {
	return (
		window.imaginaPlayerAdmin ?? {
			restUrl: '',
			nonce: '',
			frontendCss: '',
			frontendJs: '',
			docsUrl: '',
		}
	);
}

export function loadSettings(): Promise< SettingsPayload > {
	return apiFetch( { path: '/imagina-player/v1/settings' } ) as Promise< SettingsPayload >;
}

export function saveSettings( settings: Partial< SettingsPayload > ): Promise< SettingsPayload > {
	return apiFetch( {
		path: '/imagina-player/v1/settings',
		method: 'POST',
		data: settings,
	} ) as Promise< SettingsPayload >;
}

export function renderPreview( preset: Preset ): Promise< { html: string; peaks: string } > {
	return apiFetch( {
		path: '/imagina-player/v1/preview',
		method: 'POST',
		data: { preset },
	} ) as Promise< { html: string; peaks: string } >;
}

export interface PendingWaveforms {
	pending: Array< { id: number; title: string } >;
	total: number;
	available: boolean;
}

export function listPendingWaveforms(): Promise< PendingWaveforms > {
	return apiFetch( { path: '/imagina-player/v1/peaks/pending' } ) as Promise< PendingWaveforms >;
}

export function generateWaveform( attachmentId: number ): Promise< unknown > {
	return apiFetch( {
		path: '/imagina-player/v1/peaks/generate',
		method: 'POST',
		data: { attachmentId },
	} );
}
