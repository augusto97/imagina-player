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
	return apiFetch( {
		path: '/imagina-player/v1/settings',
	} ) as Promise< SettingsPayload >;
}

export function saveSettings(
	settings: Partial< SettingsPayload >
): Promise< SettingsPayload > {
	return apiFetch( {
		path: '/imagina-player/v1/settings',
		method: 'POST',
		data: settings,
	} ) as Promise< SettingsPayload >;
}

export function renderPreview(
	preset: Preset,
	medium: 'audio' | 'video' = 'audio',
	video?: Record< string, unknown >
): Promise< { html: string; peaks: string } > {
	return apiFetch( {
		path: '/imagina-player/v1/preview',
		method: 'POST',
		data: { preset, medium, video },
	} ) as Promise< { html: string; peaks: string } >;
}

export interface DiagnosisStep {
	step: string;
	ok: boolean;
	detail?: string;
	error?: string;
	seconds?: number;
	status?: number;
	type?: string;
	length?: string;
	acceptsRanges?: string;
	contentRange?: string;
	bytes?: number;
}

export interface Diagnosis {
	environment: Record< string, unknown >;
	steps: DiagnosisStep[];
}

/**
 * Ask this server what happens when it goes for a file.
 *
 * Reaching it at all is part of the answer: it has the same shape as the route
 * that fetches a remote file — a URL inside a query string — which is a shape
 * security layers are suspicious of. A gateway error here means the request
 * never reached PHP, and nothing in PHP's settings can change that.
 * @param src
 */
export function diagnoseFile( src: string ): Promise< Diagnosis > {
	return apiFetch( {
		path:
			'/imagina-player/v1/peaks/diagnose?src=' +
			encodeURIComponent( src ),
	} ) as Promise< Diagnosis >;
}

export interface PendingWaveforms {
	pending: Array< {
		id: number;
		title: string;
		/** So the browser can fetch it when the server cannot measure it. */
		url: string;
		bytes: number;
	} >;
	total: number;
	available: boolean;
}

export function listPendingWaveforms(): Promise< PendingWaveforms > {
	return apiFetch( {
		path: '/imagina-player/v1/peaks/pending',
	} ) as Promise< PendingWaveforms >;
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

export interface Lead {
	id: number;
	email: string;
	list: string;
	source_url: string;
	created_at: string;
}

export interface LeadPage {
	rows: Lead[];
	total: number;
	lists: string[];
}

export function listLeads( page = 1, list = '' ): Promise< LeadPage > {
	const query = new URLSearchParams( {
		page: String( page ),
		perPage: '50',
	} );

	if ( list ) {
		query.set( 'list', list );
	}

	return apiFetch( {
		path: '/imagina-player/v1/leads?' + query.toString(),
	} ) as Promise< LeadPage >;
}

export function deleteLead( id: number ): Promise< unknown > {
	return apiFetch( {
		path: '/imagina-player/v1/leads?id=' + id,
		method: 'DELETE',
	} );
}

/**
 * The export URL, for a plain link.
 *
 * A link rather than a fetch: the browser's own download handling is better
 * than anything reconstructed with a blob, and it keeps the nonce in the URL
 * where a `<a download>` can carry it.
 * @param list
 * @param restUrl
 * @param nonce
 */
export function exportUrl(
	list: string,
	restUrl: string,
	nonce: string
): string {
	const query = new URLSearchParams( { _wpnonce: nonce } );

	if ( list ) {
		query.set( 'list', list );
	}

	return restUrl + '/leads/export?' + query.toString();
}

/**
 * Store a waveform measured here, in this browser.
 *
 * The way out for a host with no ffmpeg: the visitor-side fallback refuses
 * anything large, and rightly so, but the person running the site can afford
 * the download once so that nobody else has to.
 * @param attachmentId
 * @param peaks
 * @param duration
 */
export function storeWaveform(
	attachmentId: number,
	peaks: number[],
	duration: number
): Promise< unknown > {
	return apiFetch( {
		path: '/imagina-player/v1/peaks/store',
		method: 'POST',
		data: { attachmentId, peaks, duration },
	} );
}
