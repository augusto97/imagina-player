/**
 * The still that appears where the pointer is on the scrub bar.
 *
 * A scrub bar without one is a guess: a reader who wants the bit where the
 * speaker changes slide has to drop the playhead somewhere and correct, twice,
 * with the sound jumping each time.
 *
 * The file is a WebVTT storyboard, which is what every tool that makes these
 * produces and what other players take: cues whose payload is an image with a
 * `#xywh=` fragment naming the tile inside a sprite sheet. One image holds a
 * hundred stills, so the whole feature is one request.
 *
 * Its own chunk, and not fetched until a pointer is actually on the bar. A
 * reader who never scrubs never downloads the sprite, which on a long video is
 * the larger half of the cost.
 */

/** One still: when it starts, and where to find it. */
interface Tile {
	start: number;
	end: number;
	url: string;
	x: number;
	y: number;
	w: number;
	h: number;
}

export interface Storyboard {
	at: ( seconds: number ) => Tile | null;
}

/**
 * `00:00:10.000` or `00:10.000`, which are both legal and both common.
 *
 * @param stamp One side of a cue's timing line.
 */
function seconds( stamp: string ): number {
	const parts = stamp.trim().split( ':' ).map( Number );

	if ( parts.some( ( n ) => ! Number.isFinite( n ) ) ) {
		return NaN;
	}

	if ( 3 === parts.length ) {
		return parts[ 0 ] * 3600 + parts[ 1 ] * 60 + parts[ 2 ];
	}

	return 2 === parts.length ? parts[ 0 ] * 60 + parts[ 1 ] : NaN;
}

/**
 * Turn a storyboard file into tiles.
 *
 * Deliberately forgiving about everything except the parts it needs: comments,
 * cue identifiers, NOTE blocks and Windows line endings all appear in files
 * these tools produce, and none of them changes what a cue means.
 *
 * @param text The file.
 * @param base The address it came from, so relative image names resolve.
 */
export function parse( text: string, base: string ): Tile[] {
	const tiles: Tile[] = [];
	const lines = text.replace( /\r\n?/g, '\n' ).split( '\n' );

	for ( let i = 0; i < lines.length; i++ ) {
		const timing = lines[ i ].match( /^\s*([\d:.]+)\s*-->\s*([\d:.]+)/ );

		if ( ! timing ) {
			continue;
		}

		const start = seconds( timing[ 1 ] );
		const end = seconds( timing[ 2 ] );
		const payload = ( lines[ i + 1 ] ?? '' ).trim();

		if ( ! Number.isFinite( start ) || ! payload ) {
			continue;
		}

		const [ name, fragment ] = payload.split( '#xywh=' );

		if ( ! fragment ) {
			continue;
		}

		const [ x, y, w, h ] = fragment.split( ',' ).map( Number );

		if (
			! Number.isFinite( w ) ||
			! Number.isFinite( h ) ||
			w <= 0 ||
			h <= 0
		) {
			continue;
		}

		let url: string;

		try {
			url = new URL( name.trim(), base ).href;
		} catch {
			continue;
		}

		tiles.push( {
			start,
			end: Number.isFinite( end ) ? end : start,
			url,
			x,
			y,
			w,
			h,
		} );
	}

	return tiles.sort( ( a, b ) => a.start - b.start );
}

/**
 * Fetch and parse a storyboard, once.
 *
 * @param src The address of the WebVTT file.
 */
export async function load( src: string ): Promise< Storyboard | null > {
	const response = await window.fetch( src, { credentials: 'same-origin' } );

	if ( ! response.ok ) {
		return null;
	}

	const tiles = parse( await response.text(), response.url || src );

	if ( 0 === tiles.length ) {
		return null;
	}

	return {
		/*
		 * The last tile that has started. A binary search would be tidier and
		 * on a few hundred tiles is not worth the extra code — this runs on
		 * pointer move, where the browser is already doing more than this.
		 */
		at( time: number ): Tile | null {
			let found: Tile | null = null;

			for ( const tile of tiles ) {
				if ( tile.start > time ) {
					break;
				}

				found = tile;
			}

			return found;
		},
	};
}

/**
 * Show a tile in an element, sized to it.
 *
 * @param element Where to paint it.
 * @param tile    The still to show.
 */
export function paint( element: HTMLElement, tile: Tile ): void {
	element.style.width = `${ tile.w }px`;
	element.style.height = `${ tile.h }px`;
	element.style.backgroundImage = `url("${ tile.url }")`;
	element.style.backgroundPosition = `-${ tile.x }px -${ tile.y }px`;
}
