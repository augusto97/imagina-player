/**
 * Subtitles and chapters from text somebody pastes.
 *
 * Kept free of the editor so it can be tested on its own (see
 * `tests/test-transcripts.php`). Three kinds of text come in:
 *
 * - A transcript with its times — "0:00 Hola", "[00:01:23] Speaker: …", the
 *   way YouTube, Descript or Otter export one. Each line runs until the next.
 * - A transcript without times. It is laid out across the length of the
 *   video in proportion to its text: close enough to read along with, and
 *   said to be approximate, because it is.
 * - Subtitles already — SRT or WebVTT pasted whole — which are only tidied.
 */

export interface Cue {
	start: number;
	end: number;
	text: string;
}

export interface Chapter {
	start: number;
	title: string;
}

/** What a subtitle line holds before it is broken in two. */
const LINE = 42;

/** What one cue holds: two lines. */
const CUE = LINE * 2;

/** Shortest and longest a cue stays on screen when the text decides. */
const MIN_SECONDS = 1.2;
const MAX_SECONDS = 7;

const STAMP =
	/^\s*[[(]?\s*((?:\d{1,2}:)?\d{1,2}:\d{2}(?:[.,]\d{1,3})?)\s*[\])]?\s*[-–—:|]?\s*/;

/**
 * `1:23`, `01:02:03`, `00:00:05,250` as seconds; NaN for anything else.
 * @param text
 */
export function clockToSeconds( text: string ): number {
	const match = text
		.trim()
		.match( /^(?:(\d{1,2}):)?(\d{1,2}):(\d{2})(?:[.,](\d{1,3}))?$/ );

	if ( ! match ) {
		return NaN;
	}

	const [ , hours, minutes, seconds, fraction ] = match;

	return (
		Number( hours ?? 0 ) * 3600 +
		Number( minutes ) * 60 +
		Number( seconds ) +
		( fraction ? Number( `0.${ fraction }` ) : 0 )
	);
}

/**
 * `83.5` as `00:01:23.500`, as WebVTT writes it.
 * @param seconds
 */
export function vttClock( seconds: number ): string {
	const whole = Math.max( 0, seconds );
	const millis = Math.round( ( whole - Math.floor( whole ) ) * 1000 );

	// 59.9996 rounds to a thousand milliseconds: carried rather than printed.
	if ( 1000 === millis ) {
		return vttClock( Math.floor( whole ) + 1 );
	}

	const pad = ( value: number, size = 2 ): string =>
		String( value ).padStart( size, '0' );

	return `${ pad( Math.floor( whole / 3600 ) ) }:${ pad(
		Math.floor( ( whole % 3600 ) / 60 )
	) }:${ pad( Math.floor( whole % 60 ) ) }.${ pad( millis, 3 ) }`;
}

/**
 * Whether pasted text is subtitles already: it has cue timings.
 * @param text
 */
export function isSubtitles( text: string ): boolean {
	return /\d{1,2}:\d{2}[.,]\d{3}\s*-->\s*/.test( text );
}

/**
 * SRT or WebVTT, pasted, as cues.
 * @param text
 */
export function parseSubtitles( text: string ): Cue[] {
	const cues: Cue[] = [];
	// A byte-order mark before the first cue is not text.
	const clean = 0xfeff === text.charCodeAt( 0 ) ? text.slice( 1 ) : text;
	const blocks = clean.replace( /\r\n?/g, '\n' ).split( /\n\s*\n/ );

	for ( const block of blocks ) {
		const lines = block.split( '\n' );
		const at = lines.findIndex( ( line ) => line.includes( '-->' ) );

		if ( at < 0 ) {
			continue;
		}

		const [ from, to ] = lines[ at ]
			.split( '-->' )
			.map( ( part ) =>
				clockToSeconds( part.trim().split( /\s+/ )[ 0 ] )
			);
		const body = lines
			.slice( at + 1 )
			.join( '\n' )
			.trim();

		if ( Number.isFinite( from ) && Number.isFinite( to ) && body ) {
			cues.push( { start: from, end: to, text: body } );
		}
	}

	return cues;
}

/**
 * Lines that start with their time, or null when most do not.
 * @param text
 */
export function parseTimedLines(
	text: string
): Array< { start: number; text: string } > | null {
	const lines = text
		.replace( /\r\n?/g, '\n' )
		.split( '\n' )
		.map( ( line ) => line.trim() )
		.filter( Boolean );
	const timed: Array< { start: number; text: string } > = [];
	let stamped = 0;

	for ( const line of lines ) {
		const match = line.match( STAMP );
		const start = match ? clockToSeconds( match[ 1 ] ) : NaN;

		if ( match && Number.isFinite( start ) ) {
			stamped++;
			timed.push( {
				start,
				text: line.slice( match[ 0 ].length ).trim(),
			} );
		} else if ( timed.length ) {
			// A line without a time belongs to the one above it.
			const last = timed[ timed.length - 1 ];

			last.text = `${ last.text } ${ line }`.trim();
		}
	}

	if ( 0 === lines.length || stamped / lines.length < 0.5 ) {
		return null;
	}

	return timed
		.filter( ( line ) => line.text )
		.sort( ( a, b ) => a.start - b.start );
}

/**
 * Text cut into pieces a subtitle can hold: at the end of a sentence where
 * there is one, at a word otherwise.
 * @param text
 */
export function chunk( text: string ): string[] {
	const sentences =
		text
			.replace( /\s+/g, ' ' )
			.trim()
			.match( /[^.!?…]+[.!?…]+["'»”)]*|[^.!?…]+$/g ) ?? [];
	const pieces: string[] = [];

	for ( const raw of sentences ) {
		let sentence = raw.trim();

		while ( sentence.length > CUE ) {
			let cut = sentence.lastIndexOf( ' ', CUE );

			// A break after a comma reads better than one in mid-clause.
			const comma = sentence.lastIndexOf( ', ', CUE );

			if ( comma > CUE / 2 ) {
				cut = comma + 1;
			}

			if ( cut <= 0 ) {
				cut = CUE;
			}

			pieces.push( sentence.slice( 0, cut ).trim() );
			sentence = sentence.slice( cut ).trim();
		}

		if ( sentence ) {
			pieces.push( sentence );
		}
	}

	return pieces;
}

/**
 * Pieces of text laid out between two moments, each given time in
 * proportion to its length.
 * @param pieces
 * @param from
 * @param to
 */
function spread( pieces: string[], from: number, to: number ): Cue[] {
	const total = pieces.reduce( ( sum, piece ) => sum + piece.length, 0 );
	const cues: Cue[] = [];
	let at = from;

	for ( const piece of pieces ) {
		const share = total ? ( ( to - from ) * piece.length ) / total : 0;
		const end = Math.min( to, at + share );

		cues.push( { start: at, end, text: piece } );
		at = end;
	}

	return cues;
}

/**
 * Cues from a pasted transcript, whatever kind it is.
 *
 * @param text
 * @param duration The video's length in seconds; 0 when it is not known.
 * @return The cues, and whether their times are a guess.
 */
export function transcriptToCues(
	text: string,
	duration: number
): { cues: Cue[]; approximate: boolean } {
	if ( isSubtitles( text ) ) {
		return { cues: parseSubtitles( text ), approximate: false };
	}

	const timed = parseTimedLines( text );

	if ( timed ) {
		const cues: Cue[] = [];

		timed.forEach( ( line, index ) => {
			const next = timed[ index + 1 ]?.start;
			const pieces = chunk( line.text );
			// The last line, or one followed by silence, gets the time its
			// text needs rather than everything up to the next.
			const needed = Math.min(
				MAX_SECONDS * pieces.length,
				Math.max( MIN_SECONDS, line.text.length / 15 )
			);
			// Up to the next line, but not on screen through a long silence.
			const end =
				undefined === next
					? line.start + needed
					: Math.min(
							next,
							line.start + Math.max( needed * 2, MAX_SECONDS )
					  );

			cues.push(
				...spread(
					pieces,
					line.start,
					Math.max( end, line.start + 0.5 )
				)
			);
		} );

		return { cues, approximate: false };
	}

	const pieces = chunk( text );
	const length =
		duration > 0
			? duration
			: pieces.reduce( ( sum, piece ) => sum + piece.length / 15, 0 );

	return { cues: spread( pieces, 0, length ), approximate: true };
}

/**
 * A cue's text in at most two lines, broken at a word near the middle.
 * @param text
 */
export function wrap( text: string ): string {
	const flat = text.replace( /\s+/g, ' ' ).trim();

	if ( flat.length <= LINE || flat.includes( '\n' ) ) {
		return flat;
	}

	const middle = Math.floor( flat.length / 2 );
	const before = flat.lastIndexOf( ' ', middle );
	const after = flat.indexOf( ' ', middle );
	const cut =
		-1 === before || ( -1 !== after && after - middle < middle - before )
			? after
			: before;

	return -1 === cut
		? flat
		: `${ flat.slice( 0, cut ) }\n${ flat.slice( cut + 1 ) }`;
}

/**
 * Cues as a WebVTT file.
 * @param cues
 */
export function toVtt( cues: Cue[] ): string {
	const lines = [ 'WEBVTT', '' ];

	for ( const cue of cues ) {
		if ( ! cue.text.trim() || ! ( cue.end > cue.start ) ) {
			continue;
		}

		lines.push(
			`${ vttClock( cue.start ) } --> ${ vttClock( cue.end ) }`,
			// A blank line ends a cue, and "-->" starts one: neither may
			// appear in a cue's own text.
			wrap( cue.text.replace( /-->/g, '→' ) ).replace( /\n\s*\n/g, '\n' ),
			''
		);
	}

	return lines.join( '\n' );
}

/**
 * Chapters as YouTube's description writes them — "0:00 Intro", "Intro
 * 0:00", "1:30 - Part two" — or from a chapters file pasted whole.
 * @param text
 */
export function parseChapters( text: string ): Chapter[] {
	if ( isSubtitles( text ) ) {
		return parseSubtitles( text ).map( ( cue ) => ( {
			start: cue.start,
			title: cue.text.replace( /\s+/g, ' ' ),
		} ) );
	}

	const chapters: Chapter[] = [];

	for ( const raw of text.replace( /\r\n?/g, '\n' ).split( '\n' ) ) {
		const line = raw.trim();
		const first = line.match( STAMP );
		const last = line.match(
			/^(.*?)\s*[-–—:|(\[]?\s*((?:\d{1,2}:)?\d{1,2}:\d{2})\s*[)\]]?$/
		);
		let start = NaN;
		let title = '';

		if ( first ) {
			start = clockToSeconds( first[ 1 ] );
			title = line.slice( first[ 0 ].length );
		} else if ( last ) {
			start = clockToSeconds( last[ 2 ] );
			title = last[ 1 ];
		}

		title = title.replace( /^[-–—:|\s]+|[-–—:|\s]+$/g, '' );

		if ( Number.isFinite( start ) && title ) {
			chapters.push( { start, title } );
		}
	}

	return chapters.sort( ( a, b ) => a.start - b.start );
}
