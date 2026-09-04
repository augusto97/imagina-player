/**
 * How a preview frame tells the page holding it how tall it is.
 *
 * The block editor and the settings screen both show the player inside an
 * iframe with `sandbox="allow-scripts"` — an opaque origin, on purpose, so
 * the preview cannot reach admin cookies or the page around it. The same
 * wall works the other way: the page cannot read the frame's document, so
 * the code that measured `contentDocument.body.scrollHeight` measured
 * nothing, silently, and every preview stayed at its starting height. An
 * audio player happens to fit in that; a 16:9 video was cut off at the top
 * fifth, in the editor only, on every theme.
 *
 * So the frame reports its own height, with a message. The holder trusts a
 * message only from the window it created, and only a number in a sane
 * range: the sandbox is there because the markup might one day carry
 * something it should not, and a preview a mile tall is the most that
 * something could do here.
 */

/** The `type` on the message a frame posts. */
export const FRAME_HEIGHT_TYPE = 'imgp-preview-height';

/** No player is shorter; nothing a preview shows is taller. */
export const FRAME_HEIGHT_MIN = 40;
export const FRAME_HEIGHT_MAX = 4000;

/**
 * The script the frame runs. A string, because it is printed into the
 * frame's own document at the end of its body.
 *
 * It reports on load, once the canvas has painted, and whenever the document
 * changes size — a waveform arriving, a poster loading, the window resizing.
 * Only a changed height is sent, so a busy page is not a stream of messages.
 */
export const FRAME_HEIGHT_SCRIPT =
	'(function(){' +
	'var last=-1;' +
	'function height(){var d=document.documentElement,b=document.body;' +
	'return Math.ceil(Math.max(d?d.scrollHeight:0,b?b.scrollHeight:0));}' +
	'function report(){var h=height();if(h===last||!(h>0)){return;}last=h;' +
	'window.parent.postMessage({type:"' +
	FRAME_HEIGHT_TYPE +
	'",height:h},"*");}' +
	'if(window.ResizeObserver){var o=new ResizeObserver(report);' +
	'o.observe(document.documentElement);if(document.body){o.observe(document.body);}}' +
	'window.addEventListener("load",report);' +
	'setTimeout(report,120);setTimeout(report,500);setTimeout(report,1500);' +
	'report();' +
	'})();';

/**
 * Listen for the frame's reports.
 *
 * @param frame    Where the frame is now — a getter, because the element is
 *                 replaced on every re-render. It must exist when this is
 *                 called: the listener goes on the window that owns it.
 * @param onHeight Called with each new height, already clamped.
 * @return A function that stops listening.
 */
export function listenForFrameHeight(
	frame: () => HTMLIFrameElement | null,
	onHeight: ( height: number ) => void
): () => void {
	const handler = ( event: MessageEvent ): void => {
		const target = frame();

		// Only the window this holder created. Any other frame on the page —
		// another preview, an embed, an advert — can post whatever it likes.
		if ( ! target || ! target.contentWindow || event.source !== target.contentWindow ) {
			return;
		}

		const height = reportedHeight( event.data );

		if ( null !== height ) {
			onHeight( height );
		}
	};

	/*
	 * On the window that holds the frame element, not on `window`. In the
	 * block editor the block is drawn inside the editor's own canvas iframe,
	 * so the preview frame's parent — the window it posts to — is the canvas,
	 * while this code runs in the window above it. A listener on `window`
	 * there waits for a message that arrives one window down and never
	 * hears it, which is what happened on every site running WordPress 6.3
	 * or later with a block theme.
	 */
	const view = frame()?.ownerDocument.defaultView ?? window;

	view.addEventListener( 'message', handler );

	return () => view.removeEventListener( 'message', handler );
}

/**
 * The height carried by a message, or null when it is not one of ours.
 *
 * Its own function so it can be tested without a window: the shape check is
 * the part that decides whether a hostile page can size the frame.
 */
export function reportedHeight( data: unknown ): number | null {
	if ( ! data || 'object' !== typeof data ) {
		return null;
	}

	const message = data as { type?: unknown; height?: unknown };

	if ( FRAME_HEIGHT_TYPE !== message.type || 'number' !== typeof message.height || ! Number.isFinite( message.height ) ) {
		return null;
	}

	return Math.min( FRAME_HEIGHT_MAX, Math.max( FRAME_HEIGHT_MIN, Math.ceil( message.height ) ) );
}
