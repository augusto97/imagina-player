/**
 * Waveform data: decoding what the server cached, and computing it in the
 * browser when the server could not.
 */

const decodeCache = new Map< string, Float32Array >();

/**
 * Server peaks arrive as base64-encoded bytes — one byte per bar.
 * @param encoded
 */
export function decodePeaks( encoded: string ): Float32Array {
	const cached = decodeCache.get( encoded );

	if ( cached ) {
		return cached;
	}

	let binary: string;

	try {
		binary = window.atob( encoded );
	} catch {
		return new Float32Array( 0 );
	}

	const out = new Float32Array( binary.length );

	for ( let i = 0; i < binary.length; i++ ) {
		out[ i ] = binary.charCodeAt( i ) / 255;
	}

	decodeCache.set( encoded, out );

	return out;
}

/**
 * Resample to an arbitrary bar count.
 *
 * By loudness across the bucket, the same measure the server and the editor
 * use. This kept each bucket's loudest value, which on a long recording
 * saturates — every few seconds of speech holds a syllable at full volume —
 * and quietly re-introduced, for any player narrower than the stored
 * resolution, the flat comb that changing the measure had removed.
 * @param peaks
 * @param bars
 */
export function resample( peaks: Float32Array, bars: number ): Float32Array {
	if ( bars < 1 || peaks.length === 0 ) {
		return new Float32Array( 0 );
	}

	if ( peaks.length === bars ) {
		return peaks;
	}

	const out = new Float32Array( bars );
	const bucket = peaks.length / bars;

	for ( let i = 0; i < bars; i++ ) {
		const start = Math.floor( i * bucket );
		const end = Math.min(
			peaks.length,
			Math.max( start + 1, Math.ceil( ( i + 1 ) * bucket ) )
		);
		let energy = 0;

		for ( let j = start; j < end; j++ ) {
			energy += peaks[ j ] * peaks[ j ];
		}

		out[ i ] = Math.sqrt( energy / Math.max( 1, end - start ) );
	}

	return out;
}

/**
 * Hand computed peaks back to the site. Failure is silent: a waveform that did
 * not persist is a cache miss for the next visitor, not a user-facing error.
 * @param restUrl
 * @param token
 * @param peaks
 * @param duration
 */
export async function storePeaks(
	restUrl: string,
	token: string,
	peaks: Float32Array,
	duration: number
): Promise< void > {
	if ( ! token || ! restUrl ) {
		return;
	}

	try {
		await fetch( `${ restUrl }/peaks`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				token,
				duration,
				peaks: Array.from(
					peaks,
					( value ) => Math.round( value * 1000 ) / 1000
				),
			} ),
		} );
	} catch {
		// Ignored on purpose.
	}
}

/**
 * Peaks already loaded this page, and loads still in flight, keyed by track.
 *
 * Several players can share one track — an inline player plus a sticky one, a
 * track repeated down an archive page — and without this each of them would
 * fetch the waveform, and on a cold cache download and decode the whole file,
 * for a result they all end up with anyway.
 */
const resolvedPeaks = new Map< string, Float32Array >();

const pendingPeaks = new Map< string, Promise< Float32Array | null > >();

export function rememberPeaks( key: string, peaks: Float32Array ): void {
	if ( key && peaks.length > 0 ) {
		resolvedPeaks.set( key, peaks );
	}
}

/**
 * Run `load` at most once per key, sharing the result with every caller.
 * @param key
 * @param load
 */
export function sharedPeaks(
	key: string,
	load: () => Promise< Float32Array | null >
): Promise< Float32Array | null > {
	const done = resolvedPeaks.get( key );

	if ( done ) {
		return Promise.resolve( done );
	}

	const inFlight = pendingPeaks.get( key );

	if ( inFlight ) {
		return inFlight;
	}

	const promise = load()
		.then( ( peaks ) => {
			pendingPeaks.delete( key );

			if ( peaks && peaks.length > 0 ) {
				resolvedPeaks.set( key, peaks );
			}

			return peaks;
		} )
		.catch( () => {
			pendingPeaks.delete( key );

			return null;
		} );

	pendingPeaks.set( key, promise );

	return promise;
}
