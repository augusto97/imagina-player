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

export interface ComputeOptions {
	/** Refuse to download more than this. */
	maxBytes: number;
	/** Give up after this long, however far it got. */
	timeoutMs: number;
}

export type ComputeFailure =
	| 'unsupported'
	| 'too-large'
	| 'timeout'
	| 'failed'
	/*
	 * The file is there and the browser is not allowed to read it. Almost
	 * always a file on another domain — a bucket or a CDN — that has not been
	 * told this site may fetch it. Its own category because it used to be
	 * reported as "too large", which sent people to the size settings for a
	 * problem that has nothing to do with size.
	 */
	| 'unreachable'
	/** Nobody asked: the caller set a budget of nothing. */
	| 'not-attempted';

export interface ComputeResult {
	peaks: Float32Array;
	duration: number;
}

/**
 * Ask the server how big the file is before committing to downloading it.
 *
 * Returns -1 when the size cannot be determined, which is treated as "too risky
 * to decode" rather than "go ahead".
 * @param url
 * @param signal
 */
async function probeSize(
	url: string,
	signal: AbortSignal
): Promise< number > {
	try {
		const head = await fetch( url, {
			method: 'HEAD',
			signal,
			credentials: 'same-origin',
		} );

		if ( head.ok ) {
			const length = head.headers.get( 'content-length' );

			if ( length ) {
				return Number( length );
			}
		}
	} catch {
		// Some hosts reject HEAD; fall through to the range probe.
	}

	try {
		// One byte is enough: the total comes back in Content-Range.
		const probe = await fetch( url, {
			headers: { Range: 'bytes=0-0' },
			signal,
			credentials: 'same-origin',
		} );

		const range = probe.headers.get( 'content-range' );
		const total = range ? Number( range.split( '/' )[ 1 ] ) : NaN;

		return Number.isFinite( total ) ? total : -1;
	} catch {
		return -1;
	}
}

/**
 * Decode the file with Web Audio and measure it.
 *
 * Bounded on purpose. `decodeAudioData` expands the file to raw float PCM in
 * memory: a 76-minute recording at 44.1 kHz stereo is about 1.6 GB, which does
 * not fail cleanly — it grinds the tab. So the size is probed first and anything
 * past the cap is declined, leaving the server to generate the waveform.
 * @param url
 * @param resolution
 * @param options
 */
export async function computePeaks(
	url: string,
	resolution: number,
	options: ComputeOptions
): Promise< ComputeResult | ComputeFailure > {
	const AudioCtx =
		window.AudioContext ??
		( window as unknown as { webkitAudioContext?: typeof AudioContext } )
			.webkitAudioContext;

	if ( ! AudioCtx ) {
		return 'unsupported';
	}

	const controller = new AbortController();
	// Armed before the request, not at first use: it exists to cut off a fetch
	// that never answers, so a later declaration would defeat it.
	// eslint-disable-next-line @wordpress/no-unused-vars-before-return
	const timer = window.setTimeout(
		() => controller.abort(),
		options.timeoutMs
	);

	try {
		/*
		 * A size of zero is the caller saying "do not download anything here",
		 * which the block preview does: it has the server's stored peaks or it
		 * has none, and either way an editor should not be made to download the
		 * file to look at a block. Asked before the HEAD, which used to go out
		 * first — one request per player, per preview, for an answer that was
		 * already known.
		 */
		if ( 0 === options.maxBytes ) {
			return 'not-attempted';
		}

		const size = await probeSize( url, controller.signal );

		// Nothing came back about the file at all: neither a HEAD nor a range
		// request could reach it.
		if ( size < 0 ) {
			return 'unreachable';
		}

		if ( size > options.maxBytes ) {
			return 'too-large';
		}

		const response = await fetch( url, {
			signal: controller.signal,
			credentials: 'same-origin',
		} );

		if ( ! response.ok ) {
			return 403 === response.status || 401 === response.status
				? 'unreachable'
				: 'failed';
		}

		const buffer = await response.arrayBuffer();
		const context = new AudioCtx();

		try {
			const audio = await context.decodeAudioData( buffer );
			const channel = audio.getChannelData( 0 );
			const peaks = new Float32Array( resolution );
			const bucket = channel.length / resolution;
			let max = 0;

			for ( let i = 0; i < resolution; i++ ) {
				const start = Math.floor( i * bucket );
				const end = Math.min(
					channel.length,
					Math.floor( ( i + 1 ) * bucket )
				);
				let energy = 0;
				let counted = 0;

				// Step through the bucket rather than reading every sample: at 44.1 kHz a
				// three-minute track is eight million samples and the extra precision is
				// invisible at one pixel per bar.
				const step = Math.max( 1, Math.floor( ( end - start ) / 512 ) );

				/*
				 * Loudness across the bucket, not the loudest instant in it —
				 * the same measure the editor and the server use, so a track
				 * measured here and the same track measured there draw the same
				 * picture. The loudest instant saturates on speech: every bar
				 * of a lecture contains a syllable at full volume, so every bar
				 * comes out the same height.
				 */
				for ( let j = start; j < end; j += step ) {
					energy += channel[ j ] * channel[ j ];
					counted++;
				}

				const peak = Math.sqrt( energy / Math.max( 1, counted ) );

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
		} finally {
			void context.close();
		}
	} catch ( error ) {
		return controller.signal.aborted ? 'timeout' : 'failed';
	} finally {
		window.clearTimeout( timer );
	}
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
