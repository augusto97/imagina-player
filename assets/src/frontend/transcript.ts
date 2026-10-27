/**
 * The whole of what is said, under the picture.
 *
 * A list of every subtitle line with its time, following playback and
 * searchable: the reader's way through a forty-minute talk. Built from the
 * subtitle tracks the player already carries, so nothing is fetched twice and
 * nothing is indexed on the server.
 *
 * Its own chunk, fetched the first time the panel is opened.
 */

import { collect, fold, type Hit } from './search';
import type { PlayerMedia } from './types';

interface Host {
	root: HTMLElement;
	element: HTMLVideoElement;
	media: PlayerMedia;
	i18n: Record< string, string >;
	seekTo: ( seconds: number ) => void;
}

/**
 * `1:35`, or `1:02:03` past an hour.
 * @param seconds
 */
function stamp( seconds: number ): string {
	const whole = Math.floor( seconds );
	const h = Math.floor( whole / 3600 );
	const m = Math.floor( ( whole % 3600 ) / 60 );
	const s = whole % 60;
	const pad = ( n: number ): string => String( n ).padStart( 2, '0' );

	return h > 0
		? `${ h }:${ pad( m ) }:${ pad( s ) }`
		: `${ m }:${ pad( s ) }`;
}

/**
 * Write a line into `target`, with what was searched for marked.
 *
 * Text nodes and a `<mark>`, never markup: the line is subtitle text, which
 * is whatever the file said.
 *
 * @param target Where the line goes.
 * @param text   The line.
 * @param needle What was typed, already folded; empty for none.
 */
function paint( target: HTMLElement, text: string, needle: string ): void {
	target.textContent = '';

	const plain = text.normalize( 'NFC' );
	const folded = fold( plain );

	// Folding keeps one character per character for ordinary text; where it
	// does not, the positions cannot be trusted and the line goes unmarked.
	const at =
		needle && folded.length === plain.length
			? folded.indexOf( needle )
			: -1;

	if ( at < 0 ) {
		target.textContent = plain;

		return;
	}

	const doc = target.ownerDocument;
	const mark = doc.createElement( 'mark' );

	mark.className = 'imgp__cue-match';
	mark.textContent = plain.slice( at, at + needle.length );

	target.append(
		doc.createTextNode( plain.slice( 0, at ) ),
		mark,
		doc.createTextNode( plain.slice( at + needle.length ) )
	);
}

/**
 * Fill the panel and keep it in step with playback.
 *
 * @param host
 * @param panel The `<details>` the server rendered.
 * @return A way to stop following playback.
 */
export function mount( host: Host, panel: HTMLElement ): () => void {
	const body = panel.querySelector< HTMLElement >( '.imgp__transcript-body' );

	if ( ! body ) {
		return () => undefined;
	}

	const doc = host.root.ownerDocument;
	const input = panel.querySelector< HTMLInputElement >(
		'.imgp__transcript-search'
	);
	const note = panel.querySelector< HTMLElement >( '.imgp__transcript-note' );
	const count = panel.querySelector< HTMLElement >(
		'.imgp__transcript-count'
	);
	const follow = panel.querySelector< HTMLInputElement >(
		'.imgp__transcript-follow input'
	);

	let hits: Hit[] = [];
	let rows: HTMLButtonElement[] = [];
	let current = -1;
	/** Set while the pointer is over the list: no scrolling under it. */
	let hovering = false;

	const say = ( text: string ): void => {
		if ( note ) {
			note.textContent = text;
			note.hidden = '' === text;
		}
	};

	const build = (): void => {
		hits = collect( host.element );
		body.textContent = '';
		rows = [];
		current = -1;

		if ( count ) {
			count.textContent = hits.length > 0 ? String( hits.length ) : '';
		}

		if ( 0 === hits.length ) {
			say(
				host.i18n.searchEmpty ??
					'The subtitles for this video have not loaded yet.'
			);

			return;
		}

		say( '' );

		for ( const hit of hits ) {
			/*
			 * A button inside a list item. The button used to carry
			 * `role="listitem"` itself, which takes away what it is: a screen
			 * reader announced "list item" and nothing to press.
			 */
			const item = doc.createElement( 'li' );
			const row = doc.createElement( 'button' );

			row.type = 'button';
			row.className = 'imgp__cue';

			const when = doc.createElement( 'span' );

			when.className = 'imgp__cue-at';
			when.textContent = stamp( hit.at );

			const said = doc.createElement( 'span' );

			said.className = 'imgp__cue-said';
			said.textContent = hit.text;

			row.append( when, said );
			row.addEventListener( 'click', () => {
				host.seekTo( hit.at );

				if ( host.media.paused ) {
					void host.media.play().catch( () => undefined );
				}
			} );

			item.appendChild( row );
			body.appendChild( item );
			rows.push( row );
		}

		filter();
		keepUp();
	};

	const filter = (): void => {
		const typed = ( input?.value ?? '' ).trim();
		const needle =
			typed.length >= 2 ? fold( typed.normalize( 'NFC' ) ) : '';
		let shown = 0;

		rows.forEach( ( row, index ) => {
			const hit = hits[ index ];
			const match =
				! needle || fold( hit?.text ?? '' ).includes( needle );
			const item = row.parentElement;

			if ( item ) {
				item.hidden = ! match;
			}

			const said = row.querySelector< HTMLElement >( '.imgp__cue-said' );

			if ( said && hit ) {
				paint( said, hit.text, match ? needle : '' );
			}

			if ( match ) {
				shown++;
			}
		} );

		if ( hits.length > 0 ) {
			say( 0 === shown ? host.i18n.searchNone ?? 'Nothing found.' : '' );
		}
	};

	/** Mark the line being said, and keep it in view when asked to. */
	const keepUp = (): void => {
		const now = host.media.currentTime;
		let index = -1;

		for ( let i = 0; i < hits.length; i++ ) {
			if ( hits[ i ].at <= now ) {
				index = i;
			} else {
				break;
			}
		}

		if ( index === current ) {
			return;
		}

		rows[ current ]?.classList.remove( 'is-current' );
		rows[ current ]?.removeAttribute( 'aria-current' );
		current = index;

		const row = rows[ index ];

		if ( ! row ) {
			return;
		}

		row.classList.add( 'is-current' );
		row.setAttribute( 'aria-current', 'true' );

		const wanted = follow ? follow.checked : true;

		if (
			wanted &&
			! hovering &&
			! row.parentElement?.hidden &&
			( panel as HTMLDetailsElement ).open
		) {
			// Kept inside the list rather than the page: `scrollIntoView`
			// would drag the whole page to the line.
			const top =
				row.offsetTop - body.clientHeight / 2 + row.offsetHeight / 2;

			body.scrollTo( { top: Math.max( 0, top ), behavior: 'smooth' } );
		}
	};

	build();

	/*
	 * A subtitle file that had not arrived when the panel opened arrives a
	 * moment later, and announces it on its `<track>` element. A fixed retry
	 * after a second and a bit left a slow file's panel saying "not loaded
	 * yet" for good.
	 */
	const tracks = Array.from(
		host.element.querySelectorAll< HTMLTrackElement >( 'track' )
	).filter( ( track ) => 'chapters' !== track.kind );
	const rebuild = (): void => build();

	for ( const track of tracks ) {
		track.addEventListener( 'load', rebuild );
	}

	const enter = (): void => {
		hovering = true;
	};
	const leave = (): void => {
		hovering = false;
	};

	input?.addEventListener( 'input', filter );
	body.addEventListener( 'pointerenter', enter );
	body.addEventListener( 'pointerleave', leave );
	host.media.addEventListener( 'timeupdate', keepUp );
	host.media.addEventListener( 'seeked', keepUp );

	return () => {
		for ( const track of tracks ) {
			track.removeEventListener( 'load', rebuild );
		}

		input?.removeEventListener( 'input', filter );
		body.removeEventListener( 'pointerenter', enter );
		body.removeEventListener( 'pointerleave', leave );
		host.media.removeEventListener( 'timeupdate', keepUp );
		host.media.removeEventListener( 'seeked', keepUp );
	};
}
