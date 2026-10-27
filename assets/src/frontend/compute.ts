/**
 * Measuring a waveform in a visitor's browser, when the server could not.
 *
 * Its own chunk. Most players arrive with their waveform in the page, and the
 * few that do not are measured once and never again — so the code for doing
 * it is fetched by the one visitor who needs it, not carried by every page.
 *
 * Decoded in windows, by the same code the editor uses. Handing the whole
 * file to `decodeAudioData` expands it to float samples at the file's own
 * rate first: a 25 MB recording, inside the size cap, is close to 600 MB of
 * memory on a visitor's phone, and a tab that runs out does not fail in a way
 * anything can catch. In windows, memory stops depending on the length.
 */

import { canMeasure, measure } from '../shared/measure';

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
	 * always a file on another domain that has not been told this site may
	 * fetch it.
	 */
	| 'unreachable'
	/*
	 * The file answered but would not say how big it is, so it was not
	 * downloaded: with no size there is no way to keep to the cap. A
	 * different answer from "unreachable", which sent people looking for a
	 * cross-origin problem on a file that is on their own site.
	 */
	| 'unknown-size'
	/** Nobody asked: the caller set a budget of nothing. */
	| 'not-attempted';

export interface ComputeResult {
	peaks: Float32Array;
	duration: number;
}

/**
 * How big the file is, before committing to download it.
 *
 * @return The size; -1 when nothing could be read at all; -2 when the file
 *         answered but gave no size.
 * @param url
 * @param signal
 */
async function probeSize(
	url: string,
	signal: AbortSignal
): Promise< number > {
	let answered = false;

	try {
		const head = await fetch( url, {
			method: 'HEAD',
			signal,
			credentials: 'same-origin',
		} );

		answered = head.ok;

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

		answered = answered || probe.ok;

		const range = probe.headers.get( 'content-range' );
		const total = range ? Number( range.split( '/' )[ 1 ] ) : NaN;

		if ( Number.isFinite( total ) ) {
			return total;
		}
	} catch {
		// Nothing more to try.
	}

	return answered ? -2 : -1;
}

/**
 * Measure the file at `url`, or say why not.
 * @param url
 * @param resolution
 * @param options
 */
export async function computePeaks(
	url: string,
	resolution: number,
	options: ComputeOptions
): Promise< ComputeResult | ComputeFailure > {
	if ( 0 === options.maxBytes ) {
		return 'not-attempted';
	}

	if ( ! canMeasure() ) {
		return 'unsupported';
	}

	const controller = new AbortController();
	// Armed before the request: it exists to cut off a fetch that never
	// answers, so arming it later would defeat it.
	// eslint-disable-next-line @wordpress/no-unused-vars-before-return
	const timer = window.setTimeout(
		() => controller.abort(),
		options.timeoutMs
	);

	try {
		const size = await probeSize( url, controller.signal );

		if ( -1 === size ) {
			return 'unreachable';
		}

		if ( -2 === size ) {
			return 'unknown-size';
		}

		if ( size > options.maxBytes ) {
			return 'too-large';
		}

		const result = await measure(
			url,
			resolution,
			undefined,
			controller.signal
		);

		return {
			peaks: Float32Array.from( result.peaks ),
			duration: result.duration,
		};
	} catch ( error ) {
		if ( controller.signal.aborted ) {
			return 'timeout';
		}

		/*
		 * A refused download comes back as `fetch-failed-<status>`; a file on
		 * another domain that has not allowed this one to read it never
		 * reaches a status at all, and the browser throws a `TypeError`.
		 */
		const message = error instanceof Error ? error.message : '';
		const status = Number(
			( /fetch-failed-(\d+)/.exec( message ) ?? [] )[ 1 ]
		);

		if ( 403 === status || 401 === status || error instanceof TypeError ) {
			return 'unreachable';
		}

		return 'failed';
	} finally {
		window.clearTimeout( timer );
	}
}
