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
 * How much to ask for at a time.
 *
 * Small enough that no single request can outlast a host's execution limit,
 * large enough that an hour-long recording is a few dozen requests rather than
 * a few hundred.
 */
const FIRST_SLICE = 4 * 1024 * 1024;

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

/**
 * How many times a piece that failed is asked for again.
 *
 * A fifty megabyte recording is thirteen requests in a row, and there was no
 * retry anywhere: one dropped connection — the thirteenth, in the case that
 * prompted this — threw away twelve pieces that had arrived perfectly. A far
 * end that fumbles one request in a dozen is completely ordinary.
 */
const SLICE_ATTEMPTS = 3;

/** How long to wait before asking again, multiplied by the attempt number. */
const RETRY_PAUSE = 400;

/**
 * How much of a file is enough to draw a waveform from.
 *
 * Below this a missing piece is a real failure and is reported as one. At or
 * above it, refusing to draw anything is the worse answer by a distance: the
 * case this comes from had 99.1% of a fifty-three minute recording in hand —
 * every slice but the last — and threw all of it away, twice, over the final
 * twenty-four seconds.
 */
const ENOUGH = 0.95;

export interface MeasureProgress {
	stage: 'downloading' | 'decoding';
	/** 0–1 while downloading, and unknown (-1) when the server sends no length. */
	ratio: number;
}

export interface MeasureResult {
	peaks: number[];
	duration: number;
}

/** What came back, and whether all of it did. */
interface Downloaded {
	buffer: ArrayBuffer;
	/** Bytes in hand. */
	got: number;
	/** Bytes the file has, where the server said so. Zero when it did not. */
	total: number;
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
	/**
	 * How much to ask the server for at a time.
	 *
	 * Same-origin only, and the reason it is adjustable is the same as for the
	 * window size: a test needs several slices out of a file small enough to
	 * measure twice, so that what is stitched back together can be compared
	 * with the same file fetched in one go.
	 */
	sliceBytes?: number;
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

	/*
	 * Slices, but only from this site.
	 *
	 * A `Range` header makes a cross-origin request non-simple, and the browser
	 * asks permission with an `OPTIONS` first. A media host that is perfectly
	 * happy to serve a plain `GET` to another domain will very often refuse
	 * that, so asking for a slice would break the files that work today in
	 * order to help the ones that do not.
	 *
	 * Same-origin has no preflight, and same-origin is where this matters: the
	 * doorway that fetches a remote file is on this site, and it is the request
	 * a host kills for taking too long.
	 */
	const sliceable = sameOrigin( url );
	const slice = options?.sliceBytes ?? FIRST_SLICE;

	const response = await window.fetch( url, {
		signal,
		credentials: 'same-origin',
		headers: sliceable ? { Range: 'bytes=0-' + ( slice - 1 ) } : undefined,
	} );

	if ( ! response.ok ) {
		throw await refusal( response, '' );
	}

	const downloaded = await read( response, url, slice, onProgress, signal );
	const buffer = downloaded.buffer;

	onProgress?.( { stage: 'decoding', ratio: 0 } );

	const measured =
		buffer.byteLength <= ( options?.wholeFileLimit ?? WHOLE_FILE_LIMIT )
			? await decodeWhole( buffer, bars )
			: await decodeInWindows(
					buffer,
					bars,
					onProgress,
					signal,
					options?.windowBytes
			  );

	// A no-op unless a piece went missing and the rest was worth keeping.
	return stretch( measured, downloaded, bars );
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

/**
 * Why a request was refused, as an error worth showing somebody.
 *
 * The doorway on this site says which step gave up, in a header, because what
 * it hands back otherwise is a status and no story: the file's own server
 * refusing us looks exactly like this site refusing us, and the two have
 * completely different answers.
 *
 * Shared by every fetch here, which it was not. The first request read the
 * reason and the ones that follow threw a bare `slice-failed-424` — a message
 * with no case to match on, so it fell through to "the browser could not read
 * it", which is the one thing that had not happened.
 *
 * @param response The refusal.
 * @param where    Which part of the download this was, for the message.
 */
async function refusal( response: Response, where: string ): Promise< Error > {
	let said = response.headers.get( 'x-imagina-reason' );
	let body = '';

	if ( ! said ) {
		/*
		 * The same tag travels in the body, for the sites where something in
		 * front of WordPress drops headers it does not recognise. Read
		 * defensively: this is an error page, and on those sites it might be
		 * the security plugin's error page rather than ours.
		 */
		body = await response.text().catch( () => '' );

		const match = body.slice( 0, 120 ).match( /^No: ([a-z0-9-]+)$/m );

		said = match ? match[ 1 ] : null;
	}

	const what = said ? 'proxy-' + said : 'fetch-failed-' + response.status;

	/*
	 * And whatever the far end actually said, when this site passed it on.
	 *
	 * `upstream-unreachable` covers a name that would not resolve, a
	 * certificate that would not verify, a connection reset at byte forty
	 * million and a timeout — four different problems with four different
	 * fixes, and the HTTP client names which one every time. That sentence
	 * used to be read into a variable on the server and dropped.
	 */
	let detail = response.headers.get( 'x-imagina-detail' ) ?? '';

	if ( ! detail && body ) {
		const why = body.match( /^Why: (.+)$/m );

		detail = why ? why[ 1 ] : '';
	}

	return new Error(
		[ what, where, detail.slice( 0, 200 ) ]
			.join( '|' )
			.replace( /\|+$/, '' )
	);
}

/**
 * Which piece of the download this is, in words.
 * @param at
 * @param slice
 * @param total
 */
function sliceName( at: number, slice: number, total: number ): string {
	return (
		'slice ' +
		( Math.floor( at / slice ) + 1 ) +
		' of ' +
		Math.ceil( total / slice )
	);
}

/**
 * Is this the site the page came from?
 * @param url
 */
function sameOrigin( url: string ): boolean {
	try {
		return (
			new URL( url, window.location.href ).origin ===
			window.location.origin
		);
	} catch {
		return false;
	}
}

/**
 * How big the whole file is, from a `Content-Range` header.
 * @param response
 */
function fullSize( response: Response ): number {
	const header = response.headers.get( 'content-range' ) ?? '';
	const total = header.split( '/' )[ 1 ] ?? '';

	return '*' === total ? 0 : Number( total ) || 0;
}

/**
 * Pull the rest of the file down a slice at a time.
 *
 * @param first      The first slice, already fetched.
 * @param url        Where the rest is.
 * @param total      How big the whole file is.
 * @param slice      How much to ask for at a time.
 * @param onProgress Called as each slice lands.
 * @param signal     Lets the caller give up.
 */
async function readInSlices(
	first: Response,
	url: string,
	total: number,
	slice: number,
	onProgress?: ( progress: MeasureProgress ) => void,
	signal?: AbortSignal
): Promise< Downloaded > {
	const merged = new Uint8Array( total );
	const head = new Uint8Array( await first.arrayBuffer() );

	merged.set( head, 0 );

	let at = head.length;

	onProgress?.( { stage: 'downloading', ratio: at / total } );

	while ( at < total ) {
		const end = Math.min( total, at + slice ) - 1;

		/*
		 * Named for where it happened. "Something failed" is not the same fact
		 * as "the ninth of thirteen pieces failed", and the second says whether
		 * the far end started refusing part-way through.
		 */
		const where = sliceName( at, slice, total );

		let bytes: Uint8Array | null = null;
		let last: Error | null = null;

		for ( let attempt = 1; attempt <= SLICE_ATTEMPTS; attempt++ ) {
			if ( signal?.aborted ) {
				throw new Error( 'aborted' );
			}

			try {
				const next = await window.fetch( url, {
					signal,
					credentials: 'same-origin',
					headers: { Range: `bytes=${ at }-${ end }` },
				} );

				if ( ! next.ok ) {
					throw await refusal( next, where );
				}

				const got = new Uint8Array( await next.arrayBuffer() );

				if ( 0 === got.length ) {
					// A server that answers a range with nothing would
					// otherwise spin here for ever.
					throw new Error( 'slice-empty|' + where );
				}

				bytes = got;
				break;
			} catch ( error ) {
				last =
					error instanceof Error
						? error
						: new Error( String( error ) );

				if ( attempt < SLICE_ATTEMPTS ) {
					await pause( RETRY_PAUSE * attempt );
				}
			}
		}

		if ( ! bytes ) {
			/*
			 * Everything but the last scrap of the file is still a waveform.
			 *
			 * This is the failure that started all of it: twelve of thirteen
			 * slices of a fifty-three minute recording in hand, and the whole
			 * measurement thrown away because the thirteenth did not come. The
			 * missing part is under five per cent, `stretch` puts the timeline
			 * back where it belongs, and the alternative on offer was nothing
			 * at all.
			 */
			if ( at / total >= ENOUGH ) {
				return {
					buffer: merged.buffer.slice( 0, at ),
					got: at,
					total,
				};
			}

			throw last ?? new Error( 'slice-empty|' + where );
		}

		merged.set( bytes.subarray( 0, total - at ), at );
		at += bytes.length;

		onProgress?.( {
			stage: 'downloading',
			ratio: Math.min( 1, at / total ),
		} );
	}

	return { buffer: merged.buffer, got: at, total };
}

/**
 * A complete file, in the shape a short one also comes back in.
 * @param buffer
 */
function whole( buffer: ArrayBuffer ): Downloaded {
	return { buffer, got: buffer.byteLength, total: buffer.byteLength };
}

/**
 * Wait, so that a far end which just refused is not asked again immediately.
 * @param ms
 */
function pause( ms: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
}

/**
 * Put the timeline back after a short download.
 *
 * The peaks describe the part that arrived, and if that was 99% of the file
 * then handing them back unchanged would say a fifty-three minute recording is
 * fifty-two and a half — every chapter mark and every stored position off by
 * half a minute. So the duration is scaled by the share that is missing, the
 * bars are squeezed into the share that is real, and the remainder is held at
 * the last value read rather than invented.
 *
 * Nothing to do when the whole file arrived, which is almost always.
 *
 * @param result     What was measured.
 * @param downloaded How much of the file it was measured from.
 * @param bars       How many values to hand back.
 */
function stretch(
	result: MeasureResult,
	downloaded: Downloaded,
	bars: number
): MeasureResult {
	const { got, total } = downloaded;

	if ( total <= 0 || got >= total || 0 === result.peaks.length ) {
		return result;
	}

	const share = got / total;
	const real = Math.max( 1, Math.round( bars * share ) );
	const peaks: number[] = [];

	for ( let i = 0; i < bars; i++ ) {
		const from =
			i < real
				? Math.min(
						result.peaks.length - 1,
						Math.floor( ( i / real ) * result.peaks.length )
				  )
				: result.peaks.length - 1;

		peaks.push( result.peaks[ from ] ?? 0 );
	}

	return { peaks, duration: result.duration / share };
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
 * @param url
 * @param slice
 * @param onProgress
 * @param signal
 */
async function read(
	response: Response,
	url: string,
	slice: number,
	onProgress?: ( progress: MeasureProgress ) => void,
	signal?: AbortSignal
): Promise< Downloaded > {
	/*
	 * A server that can serve slices, and a file worth slicing.
	 *
	 * The alternative is one request for the whole thing, and that is a request
	 * a host can kill: fetching a fifty megabyte recording through this site's
	 * own doorway means PHP holding a connection open for as long as the
	 * download takes, and where `max_execution_time` is thirty seconds it does
	 * not get to finish. What comes back is the web server's own 502, which is
	 * how this was reported. Slices keep every request short.
	 */
	if ( 206 === response.status ) {
		const total = fullSize( response );

		if ( total > slice ) {
			return readInSlices(
				response,
				url,
				total,
				slice,
				onProgress,
				signal
			);
		}

		// It fitted in the first slice, so that first slice is the file.
		return whole( await response.arrayBuffer() );
	}

	const total = Number( response.headers.get( 'content-length' ) ?? 0 );

	if ( ! response.body || ! total ) {
		onProgress?.( { stage: 'downloading', ratio: -1 } );

		return whole( await response.arrayBuffer() );
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

	return whole( merged.buffer );
}
