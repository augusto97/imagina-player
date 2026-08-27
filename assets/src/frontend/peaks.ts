/**
 * Waveform data: decoding what the server cached, and computing it in the
 * browser when the server could not.
 */

const decodeCache = new Map< string, Float32Array >();

/**
 * Server peaks arrive as base64-encoded bytes — one byte per bar.
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
 * Resample to an arbitrary bar count, keeping each bucket's peak so quiet
 * passages next to loud ones stay visible.
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
		const end = Math.min( peaks.length, Math.max( start + 1, Math.ceil( ( i + 1 ) * bucket ) ) );
		let max = 0;

		for ( let j = start; j < end; j++ ) {
			const value = peaks[ j ];

			if ( value > max ) {
				max = value;
			}
		}

		out[ i ] = max;
	}

	return out;
}

/**
 * Decode the file with Web Audio and measure it.
 *
 * This costs a full download plus a decode, so it runs once per track and the
 * result is posted back to the site for every later visitor.
 */
export async function computePeaks(
	url: string,
	resolution: number,
	signal?: AbortSignal
): Promise< { peaks: Float32Array; duration: number } | null > {
	const AudioCtx =
		window.AudioContext ??
		( window as unknown as { webkitAudioContext?: typeof AudioContext } ).webkitAudioContext;

	if ( ! AudioCtx ) {
		return null;
	}

	let buffer: ArrayBuffer;

	try {
		const response = await fetch( url, { signal, credentials: 'omit', mode: 'cors' } );

		if ( ! response.ok ) {
			return null;
		}

		buffer = await response.arrayBuffer();
	} catch {
		return null;
	}

	const context = new AudioCtx();

	try {
		const audio = await context.decodeAudioData( buffer );
		const channel = audio.getChannelData( 0 );
		const peaks = new Float32Array( resolution );
		const bucket = channel.length / resolution;
		let max = 0;

		for ( let i = 0; i < resolution; i++ ) {
			const start = Math.floor( i * bucket );
			const end = Math.min( channel.length, Math.floor( ( i + 1 ) * bucket ) );
			let peak = 0;

			// Step through the bucket rather than reading every sample: at 44.1 kHz a
			// three-minute track is eight million samples and the extra precision is
			// invisible at one pixel per bar.
			const step = Math.max( 1, Math.floor( ( end - start ) / 512 ) );

			for ( let j = start; j < end; j += step ) {
				const value = Math.abs( channel[ j ] );

				if ( value > peak ) {
					peak = value;
				}
			}

			peaks[ i ] = peak;

			if ( peak > max ) {
				max = peak;
			}
		}

		if ( max > 0 ) {
			for ( let i = 0; i < peaks.length; i++ ) {
				peaks[ i ] = peaks[ i ] / max;
			}
		}

		return { peaks, duration: audio.duration };
	} catch {
		return null;
	} finally {
		void context.close();
	}
}

/**
 * Hand computed peaks back to the site. Failure is silent: a waveform that did
 * not persist is a cache miss for the next visitor, not a user-facing error.
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
				peaks: Array.from( peaks, ( value ) => Math.round( value * 1000 ) / 1000 ),
			} ),
		} );
	} catch {
		// Ignored on purpose.
	}
}
