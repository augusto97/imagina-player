/**
 * Measuring one track, wherever the file lives.
 *
 * The editor's notice had this and the settings screen did not, which meant
 * "Generate missing waveforms" could only ever measure files on this domain: a
 * track hosted anywhere else failed at the first fetch, because a browser may
 * not read another domain's file unless that domain says so, and most media
 * hosts do not. The doorway that gets around it existed and one of the two
 * callers knew about it.
 *
 * So it lives here, and both use it.
 */

import { measure } from './measure';
import type { MeasureProgress, MeasureResult } from './measure';

/**
 * Measure a file, through this site's own doorway if it has to be.
 *
 * @param src        Where the audio is.
 * @param bars       How many values to produce.
 * @param onProgress Called as the download runs.
 * @param signal     Lets the caller give up.
 */
export async function measureTrack(
	src: string,
	bars: number,
	onProgress?: ( progress: MeasureProgress ) => void,
	signal?: AbortSignal
): Promise< MeasureResult > {
	try {
		return await measure( src, bars, onProgress, signal );
	} catch ( direct ) {
		/*
		 * Almost always CORS: the file is on another domain and that domain has
		 * not said this one may read it. Nothing about the file is wrong, so
		 * rather than give up, ask our own server to fetch it and hand it over
		 * same-origin.
		 */
		try {
			return await measure( proxied( src ), bars, onProgress, signal );
		} catch ( viaProxy ) {
			/*
			 * Both ways failed, and the one worth reporting is the second.
			 *
			 * The direct attempt failing is expected for any file on another
			 * domain — that is the whole reason the doorway exists — so
			 * reporting it says "cross-origin refusal", which is true and
			 * useless: it names the thing that was supposed to be worked around
			 * rather than the reason the workaround did not work.
			 */
			void direct;
			throw viaProxy;
		}
	}
}

/**
 * The same file, fetched through this site rather than directly.
 *
 * @param src The remote address.
 */
export function proxied( src: string ): string {
	const root = ( window.wpApiSettings?.root ?? '/wp-json/' ).replace(
		/\/$/,
		'/'
	);

	return (
		root +
		'imagina-player/v1/peaks/proxy?src=' +
		encodeURIComponent( src ) +
		'&_wpnonce=' +
		encodeURIComponent( window.wpApiSettings?.nonce ?? '' )
	);
}

declare global {
	interface Window {
		/** Printed by WordPress on any screen that loads the REST client. */
		wpApiSettings?: { root?: string; nonce?: string };
	}
}
