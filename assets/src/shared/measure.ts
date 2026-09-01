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
 * gets one at all, and the player quietly shows a flat bar for ever.
 *
 * The way out is that one person can afford what everybody cannot. An editor
 * measuring a file once, in their own browser, costs that one download and
 * then nobody else ever pays.
 *
 * ## Why this decodes in pieces
 *
 * The first version of this handed the whole file to `decodeAudioData` and
 * asked for an 8 kHz context, on the reasoning that the context's rate is what
 * you get back. That is true of the result and not of the work: the decoder
 * expands the file at its own rate first and resamples afterwards, so a
 * fifty-three minute recording at 44.1 kHz stereo is about a gigabyte of float
 * samples in flight before anything is handed back.
 *
 * A browser given that does not fail in a way anybody can catch. Measured here,
 * `decodeAudioData` on a fifty-three minute file never resolved and never
 * rejected — the promise simply never settled, so the editor sat there with a
 * spinner and the file never got a waveform. Which is what was reported.
 *
 * So a long file is decoded a few megabytes at a time, each window reduced to a
 * handful of numbers and thrown away before the next one is read. Peak memory
 * stops depending on the length of the recording.
 */

/** Decode at this rate rather than the file's own. */
const SAMPLE_RATE = 8000;

/**
 * Above this, decode in windows.
 *
 * Below it the whole-file path is both simpler and slightly more accurate — it
 * sees every sample with no seams — and a twenty megabyte file is a few minutes
 * of audio, which no browser has trouble with.
 */
const WHOLE_FILE_LIMIT = 20 * 1024 * 1024;

/** How much of the file to hand the decoder at a time. */
const WINDOW_BYTES = 4 * 1024 * 1024;

/** Values kept per window, before everything is resampled to the bar count. */
const PER_WINDOW = 64;

export interface MeasureProgress {
	stage: 'downloading' | 'decoding';
	/** 0–1 while downloading, and unknown (-1) when the server sends no length. */
	ratio: number;
}

export interface MeasureResult {
	peaks: number[];
	duration: number;
}

export interface MeasureOptions {
	/**
	 * Files up to this many bytes are decoded in one go.
	 *
	 * A real setting rather than a way in for the tests, though the tests are
	 * what it is mostly for: setting it to zero sends a short file down the
	 * windowed path, which is the only way to compare the two on the same audio
	 * and see whether the pieces still add up to the whole.
	 */
	wholeFileLimit?: number;
	/** How much to hand the decoder at a time. */
	windowBytes?: number;
}

type AudioContextCtor = typeof AudioContext;
type OfflineCtor = typeof OfflineAudioContext;

function audioContext(): AudioContextCtor | null {
	const ctor =
		window.AudioContext ??
		( window as unknown as { webkitAudioContext?: AudioContextCtor } )
			.webkitAudioContext;

	return ctor ?? null;
}

function offlineContext(): OfflineCtor | null {
	const ctor =
		window.OfflineAudioContext ??
		( window as unknown as { webkitOfflineAudioContext?: OfflineCtor } )
			.webkitOfflineAudioContext;

	return ctor ?? null;
}

export function canMeasure(): boolean {
	return null !== audioContext();
}

/** A context to decode into, offline where the browser has one. */
function decoder(): BaseAudioContext {
	const Offline = offlineContext();

	if ( Offline ) {
		return new Offline( 1, SAMPLE_RATE, SAMPLE_RATE );
	}

	const Ctor = audioContext();

	if ( ! Ctor ) {
		throw new Error( 'no-audio-context' );
	}

	return new Ctor();
}

function release( context: BaseAudioContext ): void {
	if ( 'close' in context && 'function' === typeof context.close ) {
		void ( context as AudioContext ).close().catch( () => undefined );
	}
}

/**
 * Reduce one decoded window to a fixed number of peaks.
 * @param channel
 * @param into
 */
function reduce( channel: Float32Array, into: number ): number[] {
	const out: number[] = [];
	const per = Math.max( 1, Math.floor( channel.length / into ) );

	for ( let slot = 0; slot < into; slot++ ) {
		const start = slot * per;
		const end = Math.min( channel.length, start + per );
		let peak = 0;

		for ( let i = start; i < end; i++ ) {
			const value = Math.abs( channel[ i ] );

			if ( value > peak ) {
				peak = value;
			}
		}

		out.push( peak );
	}

	return out;
}

/**
 * Download a file and reduce it to a row of amplitudes.
 *
 * @param url        The file to measure.
 * @param bars       How many amplitudes to produce.
 * @param onProgress Called as the download runs, so a long one is visible.
 * @param signal     Lets the caller give up.
 * @param options
 */
export async function measure(
	url: string,
	bars: number,
	onProgress?: ( progress: MeasureProgress ) => void,
	signal?: AbortSignal,
	options?: MeasureOptions
): Promise< MeasureResult > {
	if ( ! canMeasure() ) {
		throw new Error( 'no-audio-context' );
	}

	const response = await window.fetch( url, {
		signal,
		credentials: 'same-origin',
	} );

	if ( ! response.ok ) {
		/*
		 * The doorway on this site says which step gave up, in a header,
		 * because what it hands back otherwise is a status and no story: the
		 * file's own server refusing us looks exactly like this site refusing
		 * us, and the two have completely different answers.
		 */
		let said = response.headers.get( 'x-imagina-reason' );

		if ( ! said ) {
			/*
			 * The same tag travels in the body, for the sites where something
			 * in front of WordPress drops headers it does not recognise. Read
			 * defensively: this is an error page, and on those sites it might
			 * be the security plugin's error page rather than ours.
			 */
			const body = await response.text().catch( () => '' );
			const match = body.slice( 0, 120 ).match( /^No: ([a-z0-9-]+)$/ );

			said = match ? match[ 1 ] : null;
		}

		throw new Error(
			said ? 'proxy-' + said : 'fetch-failed-' + response.status
		);
	}

	const buffer = await read( response, onProgress );

	onProgress?.( { stage: 'decoding', ratio: 0 } );

	if (
		buffer.byteLength <= ( options?.wholeFileLimit ?? WHOLE_FILE_LIMIT )
	) {
		return decodeWhole( buffer, bars );
	}

	return decodeInWindows(
		buffer,
		bars,
		onProgress,
		signal,
		options?.windowBytes
	);
}

/**
 * The short-file path: every sample, no seams.
 * @param buffer
 * @param bars
 */
async function decodeWhole(
	buffer: ArrayBuffer,
	bars: number
): Promise< MeasureResult > {
	const context = decoder();

	try {
		const audio = await context.decodeAudioData( buffer );

		return {
			peaks: reduce( audio.getChannelData( 0 ), bars ),
			duration: audio.duration,
		};
	} finally {
		release( context );
	}
}

/**
 * The long-file path.
 *
 * Each window is decoded on its own and reduced before the next is read, so
 * what is held at once is one window rather than the whole recording.
 *
 * Windows are cut on byte boundaries, which is fine for the formats where a
 * decoder finds its own way back into the stream — MP3, AAC and Ogg all carry
 * a sync word at the head of every frame, and a decoder handed a slice starting
 * mid-frame skips to the next one. A WAV has no frames, so its windows are
 * given a header of their own.
 * @param buffer
 * @param bars
 * @param onProgress
 * @param signal
 * @param windowBytes
 */
async function decodeInWindows(
	buffer: ArrayBuffer,
	bars: number,
	onProgress?: ( progress: MeasureProgress ) => void,
	signal?: AbortSignal,
	windowBytes = WINDOW_BYTES
): Promise< MeasureResult > {
	const wav = readWavHeader( buffer );
	const body = wav ? buffer.slice( wav.dataStart, wav.dataEnd ) : buffer;

	// Whole frames for a WAV, so a window never starts halfway through a sample.
	const size = wav
		? Math.max(
				wav.blockAlign,
				Math.floor( windowBytes / wav.blockAlign ) * wav.blockAlign
		  )
		: Math.max( windowBytes, Math.ceil( body.byteLength / 256 ) );

	const segments: Array< { duration: number; peaks: number[] } > = [];
	let total = 0;

	for ( let offset = 0; offset < body.byteLength; offset += size ) {
		if ( signal?.aborted ) {
			throw new Error( 'aborted' );
		}

		const end = Math.min( body.byteLength, offset + size );
		const slice = body.slice( offset, end );
		const window = wav ? withWavHeader( slice, wav ) : slice;

		const context = decoder();

		try {
			const audio = await context.decodeAudioData( window );

			if ( audio.duration > 0 ) {
				segments.push( {
					duration: audio.duration,
					peaks: reduce( audio.getChannelData( 0 ), PER_WINDOW ),
				} );

				total += audio.duration;
			}
		} catch {
			/*
			 * One window that will not decode is a gap in the picture, not a
			 * failure: the first slice of a file with a long tag block in front
			 * of it has no audio in it at all, and neither does the last one if
			 * it lands inside a trailing tag.
			 */
		} finally {
			release( context );
		}

		onProgress?.( {
			stage: 'decoding',
			ratio: Math.min( 1, end / body.byteLength ),
		} );
	}

	if ( 0 === segments.length || total <= 0 ) {
		throw new Error( 'decode-failed' );
	}

	return { peaks: resample( segments, total, bars ), duration: total };
}

/**
 * Lay the windows end to end on a timeline and read the bars off it.
 *
 * By duration rather than by count, because a variable bitrate file gives
 * different amounts of audio for the same number of bytes — dividing the bars
 * evenly between windows would stretch the quiet parts.
 *
 * @param segments Decoded windows, in order.
 * @param total    Their combined length in seconds.
 * @param bars     How many amplitudes to produce.
 */
function resample(
	segments: Array< { duration: number; peaks: number[] } >,
	total: number,
	bars: number
): number[] {
	const out: number[] = [];
	const step = total / bars;

	// Where each window starts on the timeline.
	const starts: number[] = [];
	let running = 0;

	for ( const segment of segments ) {
		starts.push( running );
		running += segment.duration;
	}

	for ( let bar = 0; bar < bars; bar++ ) {
		const from = bar * step;
		const to = from + step;
		let peak = 0;

		for ( let s = 0; s < segments.length; s++ ) {
			const segment = segments[ s ];
			const segStart = starts[ s ];
			const segEnd = segStart + segment.duration;

			if ( segEnd <= from || segStart >= to ) {
				continue;
			}

			const slot = segment.duration / segment.peaks.length;
			const first = Math.max(
				0,
				Math.floor( ( from - segStart ) / slot )
			);
			const last = Math.min(
				segment.peaks.length - 1,
				Math.ceil( ( to - segStart ) / slot )
			);

			for ( let i = first; i <= last; i++ ) {
				if ( segment.peaks[ i ] > peak ) {
					peak = segment.peaks[ i ];
				}
			}
		}

		out.push( peak );
	}

	return out;
}

interface WavShape {
	dataStart: number;
	dataEnd: number;
	blockAlign: number;
	/** The bytes up to and including `fmt `, reused for every window. */
	header: Uint8Array;
}

/**
 * Where a WAV keeps its samples, and what its header says about them.
 *
 * Returns null for anything that is not a WAV, which is every format that can
 * be cut on a byte boundary and decoded anyway.
 *
 * @param buffer The whole file.
 */
function readWavHeader( buffer: ArrayBuffer ): WavShape | null {
	if ( buffer.byteLength < 44 ) {
		return null;
	}

	const view = new DataView( buffer );
	const tag = ( at: number ): string =>
		String.fromCharCode(
			view.getUint8( at ),
			view.getUint8( at + 1 ),
			view.getUint8( at + 2 ),
			view.getUint8( at + 3 )
		);

	if ( 'RIFF' !== tag( 0 ) || 'WAVE' !== tag( 8 ) ) {
		return null;
	}

	let offset = 12;
	let fmtStart = -1;
	let fmtEnd = -1;
	let blockAlign = 0;

	while ( offset + 8 <= buffer.byteLength ) {
		const name = tag( offset );
		const size = view.getUint32( offset + 4, true );
		const body = offset + 8;

		if ( 'fmt ' === name ) {
			fmtStart = offset;
			fmtEnd = body + size;
			blockAlign = view.getUint16( body + 12, true );
		}

		if ( 'data' === name && fmtStart >= 0 && blockAlign > 0 ) {
			return {
				dataStart: body,
				dataEnd: Math.min( buffer.byteLength, body + size ),
				blockAlign,
				header: new Uint8Array( buffer.slice( 0, fmtEnd ) ),
			};
		}

		// Chunks are padded to an even length.
		offset = body + size + ( size % 2 );
	}

	return null;
}

/**
 * One window of samples, with a header in front so it is a file again.
 * @param slice
 * @param wav
 */
function withWavHeader( slice: ArrayBuffer, wav: WavShape ): ArrayBuffer {
	const out = new Uint8Array( wav.header.length + 8 + slice.byteLength );

	out.set( wav.header, 0 );

	const view = new DataView( out.buffer );

	// The RIFF size, which counts everything after the first eight bytes.
	view.setUint32( 4, out.length - 8, true );

	const at = wav.header.length;

	out[ at ] = 0x64; // d
	out[ at + 1 ] = 0x61; // a
	out[ at + 2 ] = 0x74; // t
	out[ at + 3 ] = 0x61; // a
	view.setUint32( at + 4, slice.byteLength, true );
	out.set( new Uint8Array( slice ), at + 8 );

	return out.buffer;
}

/**
 * Read the body, reporting progress where the server said how big it is.
 *
 * `arrayBuffer()` on its own is one long silence, and for a file this size the
 * difference between "working" and "frozen" is whether something moves.
 *
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

	/*
	 * Into one buffer of the right size rather than a list of chunks that is
	 * then copied into one: for a file this big, collecting and merging means
	 * holding it twice at the moment it matters least.
	 */
	const merged = new Uint8Array( total );
	const reader = response.body.getReader();
	let received = 0;

	for (;;) {
		const { done, value } = await reader.read();

		if ( done ) {
			break;
		}

		if ( received + value.length <= total ) {
			merged.set( value, received );
		}

		received += value.length;
		onProgress?.( { stage: 'downloading', ratio: received / total } );
	}

	// A server that lied about the length: fall back rather than hand back
	// a buffer with a tail of zeroes in it.
	if ( received !== total ) {
		throw new Error( 'length-mismatch' );
	}

	return merged.buffer;
}
