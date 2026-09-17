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

		if ( 0 === hits.length ) {
			say(
				host.i18n.searchEmpty ??
					'The subtitles for this video have not loaded yet.'
			);

			return;
		}

		say( '' );

		for ( const hit of hits ) {
			const row = doc.createElement( 'button' );

			row.type = 'button';
			row.className = 'imgp__cue';
			row.setAttribute( 'role', 'listitem' );

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

			body.appendChild( row );
			rows.push( row );
		}

		filter();
		follow();
	};

	const filter = (): void => {
		const needle = fold( ( input?.value ?? '' ).trim() );
		let shown = 0;

		rows.forEach( ( row, index ) => {
			const hit = hits[ index ];
			const match =
				needle.length < 2 || fold( hit?.text ?? '' ).includes( needle );

			row.hidden = ! match;

			if ( match ) {
				shown++;
			}
		} );

		if ( hits.length > 0 ) {
			say( 0 === shown ? host.i18n.searchNone ?? 'Nothing found.' : '' );
		}
	};

	/** Mark the line being said, and keep it in view. */
	const follow = (): void => {
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
		current = index;

		const row = rows[ index ];

		if ( ! row ) {
			return;
		}

		row.classList.add( 'is-current' );

		if ( ! hovering && ! row.hidden && ! panel.hidden ) {
			// Kept inside the list rather than the page: `scrollIntoView`
			// would drag the whole page to the line.
			const top =
				row.offsetTop - body.clientHeight / 2 + row.offsetHeight / 2;

			body.scrollTo( { top: Math.max( 0, top ), behavior: 'smooth' } );
		}
	};

	/*
	 * A file that had not arrived when the panel opened has usually arrived a
	 * moment later, so an empty build asks again once, briefly.
	 */
	build();

	let retry = 0;

	if ( 0 === hits.length ) {
		retry = window.setTimeout( build, 1200 );
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
	host.media.addEventListener( 'timeupdate', follow );
	host.media.addEventListener( 'seeked', follow );

	return () => {
		window.clearTimeout( retry );
		input?.removeEventListener( 'input', filter );
		body.removeEventListener( 'pointerenter', enter );
		body.removeEventListener( 'pointerleave', leave );
		host.media.removeEventListener( 'timeupdate', follow );
		host.media.removeEventListener( 'seeked', follow );
	};
}
