/**
 * Measuring a waveform in the browser, for the person editing the site.
 *
 * There is already a version of this for visitors, in the front-end bundle,
 * and it is deliberately timid: it refuses anything over a size cap, because
 * nobody browsing a page should download ninety megabytes to look at a
 * picture of some audio.
 *
 * That timidity leaves a hole. On a host with no ffmpeg, a long recording gets
 * no waveform from the server and none from the visitor either — so it never
 * gets one at all, and the player quietly shows a flat bar for ever. Which is
 * exactly what happened.
 *
 * The way out is that one person can afford what everybody cannot. An editor
 * measuring a file once, in their own browser, costs that one download and
 * then nobody else ever pays. So this is the same idea as the visitor path
 * with the cap taken off and a progress report added, because a ninety
 * megabyte download deserves to be visible.
 */

/** Decode at this rate rather than the file's own. */
const SAMPLE_RATE = 8000;

export interface MeasureProgress {
	stage: 'downloading' | 'decoding';
	/** 0–1 while downloading, and unknown (-1) when the server sends no length. */
	ratio: number;
}

export interface MeasureResult {
	peaks: number[];
	duration: number;
}

type AudioContextCtor = typeof AudioContext;

function audioContext(): AudioContextCtor | null {
	const ctor =
		window.AudioContext ??
		( window as unknown as { webkitAudioContext?: AudioContextCtor } )
			.webkitAudioContext;

	return ctor ?? null;
}

export function canMeasure(): boolean {
	return null !== audioContext();
}

/**
 * Download a file and reduce it to a row of amplitudes.
 *
 * @param url        The file to measure.
 * @param bars       How many amplitudes to produce.
 * @param onProgress Called as the download runs, so a long one is visible.
 * @param signal     Lets the caller give up.
 */
export async function measure(
	url: string,
	bars: number,
	onProgress?: ( progress: MeasureProgress ) => void,
	signal?: AbortSignal
): Promise< MeasureResult > {
	const Ctor = audioContext();

	if ( ! Ctor ) {
		throw new Error( 'no-audio-context' );
	}

	const response = await window.fetch( url, {
		signal,
		credentials: 'same-origin',
	} );

	if ( ! response.ok ) {
		throw new Error( 'fetch-failed' );
	}

	const buffer = await read( response, onProgress );

	onProgress?.( { stage: 'decoding', ratio: 1 } );

	/*
	 * Decoding at 8 kHz rather than the file's own rate. A plain AudioContext
	 * decodes at the hardware rate — 48 kHz — which for a 77-minute recording
	 * is about 900 MB of float samples in memory, and that is how a tab dies.
	 * An OfflineAudioContext decodes at whatever rate it was built with, and
	 * 8 kHz is six times less for a picture that is a few hundred bars wide.
	 */
	const Offline =
		window.OfflineAudioContext ??
		(
			window as unknown as {
				webkitOfflineAudioContext?: typeof OfflineAudioContext;
			}
		 ).webkitOfflineAudioContext;

	const context = Offline
		? new Offline( 1, SAMPLE_RATE, SAMPLE_RATE )
		: new Ctor();

	const audio = await context.decodeAudioData( buffer );
	const channel = audio.getChannelData( 0 );
	const peaks: number[] = [];
	const per = Math.max( 1, Math.floor( channel.length / bars ) );

	for ( let bar = 0; bar < bars; bar++ ) {
		const start = bar * per;
		const end = Math.min( channel.length, start + per );
		let peak = 0;

		for ( let i = start; i < end; i++ ) {
			const value = Math.abs( channel[ i ] );

			if ( value > peak ) {
				peak = value;
			}
		}

		peaks.push( peak );
	}

	if ( 'close' in context && 'function' === typeof context.close ) {
		void ( context as AudioContext ).close();
	}

	return { peaks, duration: audio.duration };
}

/**
 * Read the body, reporting progress where the server said how big it is.
 *
 * `arrayBuffer()` on its own is one long silence, and for a file this size the
 * difference between "working" and "frozen" is whether something moves.
 * @param response
 * @param onProgress
 */
async function read(
	response: Response,
	onProgress?: ( progress: MeasureProgress ) => void
): Promise< ArrayBuffer > {
	const total = Number( response.headers.get( 'content-length' ) ?? 0 );

	if ( ! response.body || ! total ) {
		onProgress?.( { stage: 'downloading', ratio: -1 } );

		return response.arrayBuffer();
	}

	const reader = response.body.getReader();
	const chunks: Uint8Array[] = [];
	let received = 0;

	for (;;) {
		const { done, value } = await reader.read();

		if ( done ) {
			break;
		}

		chunks.push( value );
		received += value.length;
		onProgress?.( { stage: 'downloading', ratio: received / total } );
	}

	const merged = new Uint8Array( received );
	let offset = 0;

	for ( const chunk of chunks ) {
		merged.set( chunk, offset );
		offset += chunk.length;
	}

	return merged.buffer;
}
