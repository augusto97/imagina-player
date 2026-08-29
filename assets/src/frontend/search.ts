/**
 * Finding the moment a word is said.
 *
 * On a forty-minute talk the difference between a video somebody watches and a
 * video somebody uses is whether they can get to the part about pricing without
 * dragging the bar and guessing. Chapters help when the author wrote them;
 * this works on what was actually said.
 *
 * The text is already in the page — the subtitle tracks the player loaded — so
 * there is nothing to fetch and nothing to index on the server. What is here is
 * the reading of those cues and the matching, in its own chunk, loaded when
 * somebody opens the box and not before.
 */

export interface Hit {
	/** Where in the video, in seconds. */
	at: number;
	/** The line, as it was said. */
	text: string;
}

/**
 * Every cue of every subtitle track that is loaded, oldest first.
 *
 * A track only has cues once the browser has fetched it, which for a track
 * that has never been shown may not have happened. Setting `hidden` asks for
 * the text without putting it on the picture.
 *
 * @param element The video element carrying the tracks.
 */
export function collect( element: HTMLVideoElement ): Hit[] {
	const hits: Hit[] = [];

	for ( let i = 0; i < element.textTracks.length; i++ ) {
		const track = element.textTracks[ i ];

		if ( 'subtitles' !== track.kind && 'captions' !== track.kind ) {
			continue;
		}

		// Loads the cues without showing them. A track left `disabled` never
		// fetches its file, so there would be nothing to search.
		if ( 'disabled' === track.mode ) {
			track.mode = 'hidden';
		}

		const cues = track.cues;

		if ( ! cues ) {
			continue;
		}

		for ( let c = 0; c < cues.length; c++ ) {
			const cue = cues[ c ] as VTTCue;
			const text = String( cue.text ?? '' )
				// Cues carry their own markup — <v Speaker>, <i>, <c.classname>
				// — which nobody is searching for.
				.replace( /<[^>]*>/g, '' )
				.replace( /\s+/g, ' ' )
				.trim();

			if ( text ) {
				hits.push( { at: cue.startTime, text } );
			}
		}
	}

	return hits.sort( ( a, b ) => a.at - b.at );
}

/**
 * Fold accents and case, so "pagina" finds "página".
 *
 * Spanish is the first language this plugin is used in, and a search that only
 * matches when the accent is typed correctly is a search nobody uses twice.
 *
 * @param value Anything typed or spoken.
 */
export function fold( value: string ): string {
	return (
		value
			/*
			 * `ñ` is protected before the rest are stripped. Decomposing it
			 * gives `n` plus a combining tilde, and taking that off turns "año"
			 * into "ano" — two different words, one of which nobody wants to
			 * find in a transcript by accident. In Spanish it is a letter, not
			 * an accented `n`.
			 */
			.replace( /[ñÑ]/g, '\u0001' )
			.normalize( 'NFD' )
			.replace( /[\u0300-\u036f]/g, '' )
			.replace( /\u0001/g, 'ñ' )
			.toLowerCase()
	);
}

/**
 * The lines that contain what was typed.
 *
 * @param hits  Every cue.
 * @param query What was typed.
 * @param limit How many to return.
 */
export function search( hits: Hit[], query: string, limit = 12 ): Hit[] {
	const needle = fold( query.trim() );

	if ( needle.length < 2 ) {
		return [];
	}

	const found: Hit[] = [];

	for ( const hit of hits ) {
		if ( fold( hit.text ).includes( needle ) ) {
			found.push( hit );

			if ( found.length >= limit ) {
				break;
			}
		}
	}

	return found;
}
