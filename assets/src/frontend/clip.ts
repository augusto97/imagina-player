/**
 * Choosing a clip: a start, an end, and a link that plays just that.
 *
 * Its own chunk, fetched the first time somebody asks to share a clip, from
 * the video's settings menu or the audio player's "more" menu. The link it
 * makes is the page's own address with `t` and `end`; the page, opened with
 * it, plays from the start and stops at the end (see `moments.ts`).
 */

import { parseMoment } from './moments';
import { formatTime } from './utils';

interface ClipHost {
	/** The player's own element, `.imgp`. */
	root: HTMLElement;
	media: { currentTime: number; duration: number };
	/** A line that goes away by itself. */
	say: ( text: string ) => void;
	/** Put the panel away. */
	done: () => void;
}

/** How long a clip is when nobody has said yet. */
const DEFAULT_LENGTH = 30;

/**
 * This page's address for a clip of this player.
 *
 * Whole seconds, the start rounded down and the end up, so that the clip
 * never loses the edge of what was chosen. The player is named by the
 * block's HTML anchor when it has one, as a link to a moment names it.
 * @param root
 * @param start
 * @param end
 */
export function clipUrl(
	root: HTMLElement,
	start: number,
	end: number
): string {
	const win = root.ownerDocument.defaultView ?? window;
	const url = new URL( win.location.href );
	const name = root.closest< HTMLElement >( '.imgp-block' )?.id;

	url.hash = '';
	url.searchParams.set( 't', String( Math.floor( start ) ) );
	url.searchParams.set( 'end', String( Math.ceil( end ) ) );

	if ( name ) {
		url.searchParams.set( 'player', name );
	} else {
		url.searchParams.delete( 'player' );
	}

	return url.toString();
}

/**
 * The form: start and end, each with a "now" button, and the link to copy.
 * @param host
 */
export function clipForm( host: ClipHost ): HTMLFormElement {
	const doc = host.root.ownerDocument;
	const i18n = window.imaginaPlayer?.i18n ?? {};
	const form = doc.createElement( 'form' );
	const now = (): number => Math.floor( host.media.currentTime || 0 );
	const duration = host.media.duration;
	const length =
		Number.isFinite( duration ) && duration > 0
			? Math.floor( duration )
			: Infinity;
	const start = Math.min( now(), Math.max( 0, length - 1 ) );

	form.className = 'imgp__clip';
	form.noValidate = true;

	const title = doc.createElement( 'p' );

	title.className = 'imgp__clip-title';
	title.textContent = i18n.clipShare ?? 'Share a clip';
	form.appendChild( title );

	const field = ( label: string, value: number ): HTMLInputElement => {
		const row = doc.createElement( 'label' );
		const name = doc.createElement( 'span' );
		const input = doc.createElement( 'input' );
		const set = doc.createElement( 'button' );

		row.className = 'imgp__clip-row';
		name.textContent = label;
		input.type = 'text';
		input.className = 'imgp__search-input imgp__clip-input';
		input.inputMode = 'numeric';
		input.value = formatTime( value );
		set.type = 'button';
		set.className = 'imgp__clip-now';
		set.textContent = i18n.clipNow ?? 'Now';
		set.addEventListener( 'click', () => {
			input.value = formatTime( now() );
		} );

		row.append( name, input, set );
		form.appendChild( row );

		return input;
	};

	const from = field( i18n.clipStart ?? 'Start', start );
	const to = field(
		i18n.clipEnd ?? 'End',
		Math.min( length, start + DEFAULT_LENGTH )
	);

	const note = doc.createElement( 'p' );

	note.className = 'imgp__clip-note';
	note.setAttribute( 'role', 'alert' );
	note.hidden = true;

	// Where the clipboard is not allowed, the link to copy by hand.
	const link = doc.createElement( 'input' );

	link.type = 'text';
	link.readOnly = true;
	link.className = 'imgp__search-input imgp__clip-link';
	link.hidden = true;

	const copy = doc.createElement( 'button' );

	copy.type = 'submit';
	copy.className = 'imgp__menuitem imgp__clip-copy';
	copy.textContent = i18n.clipCopy ?? 'Copy clip link';

	form.append( note, link, copy );

	form.addEventListener( 'submit', ( event ) => {
		event.preventDefault();

		const a = parseMoment( from.value );
		const b = parseMoment( to.value );

		if ( ! ( Number.isFinite( a ) && Number.isFinite( b ) && b > a ) ) {
			note.textContent =
				i18n.clipInvalid ?? 'The end has to come after the start.';
			note.hidden = false;

			return;
		}

		note.hidden = true;

		const url = clipUrl( host.root, a, b );
		const clipboard = ( doc.defaultView ?? window ).navigator.clipboard;

		void (
			clipboard
				? clipboard.writeText( url )
				: Promise.reject( new Error( 'no clipboard' ) )
		).then(
			() => {
				host.say( i18n.clipCopied ?? 'Clip link copied' );
				host.done();
			},
			() => {
				link.value = url;
				link.hidden = false;
				link.focus();
				link.select();
			}
		);
	} );

	return form;
}
