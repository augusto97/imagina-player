/**
 * "Resume from 12:40 · Start over".
 *
 * A player that silently opens at 12:40 looks broken to somebody who does not
 * remember stopping there, and gives no way back to the start short of
 * dragging the bar. The chip is a sentence and a button, and it leaves by
 * itself once the viewer has clearly carried on.
 *
 * Its own chunk: only a player that remembers positions, on a visit where
 * there is one to offer, ever needs it.
 */

import { formatTime } from './utils';

/** How long the offer stays once playback has begun. */
const LINGER = 8000;

interface Host {
	root: HTMLElement;
	media: { addEventListener: EventTarget[ 'addEventListener' ] };
	i18n: Record< string, string >;
	/** Back to the beginning, and forget the stored position. */
	restart: () => void;
}

/**
 * Show the chip; returns the way to take it down.
 * @param host
 * @param seconds
 */
export function offerResume( host: Host, seconds: number ): () => void {
	const doc = host.root.ownerDocument;
	const chip = doc.createElement( 'div' );
	let timer = 0;

	const hide = (): void => {
		window.clearTimeout( timer );
		chip.remove();
	};

	chip.className = 'imgp__resume';
	chip.setAttribute( 'role', 'status' );

	const text = doc.createElement( 'span' );

	text.className = 'imgp__resume-text';
	text.textContent = ( host.i18n.resumeFrom ?? 'Resume from %s' ).replace(
		'%s',
		formatTime( seconds )
	);

	const restart = doc.createElement( 'button' );

	restart.type = 'button';
	restart.className = 'imgp__resume-restart';
	restart.textContent = host.i18n.startOver ?? 'Start over';
	restart.addEventListener( 'click', () => {
		host.restart();
		hide();
	} );

	const close = doc.createElement( 'button' );

	close.type = 'button';
	close.className = 'imgp__resume-close';
	close.setAttribute( 'aria-label', host.i18n.dismiss ?? 'Dismiss' );
	close.textContent = '×';
	close.addEventListener( 'click', hide );

	chip.append( text, restart, close );
	host.root.appendChild( chip );

	host.media.addEventListener(
		'play',
		() => {
			window.clearTimeout( timer );
			timer = window.setTimeout( hide, LINGER );
		},
		{ once: true }
	);

	return hide;
}
