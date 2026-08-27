import apiFetch from '@wordpress/api-fetch';

import type { AdminBoot, Preset, SettingsPayload } from './types';

export function boot(): AdminBoot {
	return (
		window.imaginaPlayerAdmin ?? {
			restUrl: '',
			nonce: '',
			frontendCss: '',
			frontendJs: '',
			frameCss: '',
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

export interface SelfCheckResult {
	status: 'pass' | 'fail' | 'warn' | 'skip';
	summary: string;
	checks: Array< {
		id: string;
		label: string;
		status: 'pass' | 'fail' | 'warn' | 'skip';
		detail: string;
	} >;
}

/**
 * Ask the site to try to break into its own vault and report what happened.
 */
export function runProtectionSelfCheck(): Promise< SelfCheckResult > {
	return apiFetch( {
		path: '/imagina-player/v1/protection/self-check',
		method: 'POST',
	} ) as Promise< SelfCheckResult >;
}
