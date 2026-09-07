/**
 * The preview frame's stylesheet and script, fetched by the page that holds
 * the frame and written into it, rather than linked from inside it.
 *
 * The frame is sandboxed, which gives it an opaque origin: to the server,
 * every request it makes comes from nowhere — `Origin: null`, a cross-site
 * fetch with no referrer. Plenty of hosts refuse exactly that for a static
 * file: hotlink protection, a `Cross-Origin-Resource-Policy: same-origin`
 * header on everything under wp-content, a firewall rule. The page loading
 * the editor is the site itself, so the same files fetched from there come
 * back — and once inlined, the frame has nothing left to ask for.
 *
 * Seen on a real site as a preview drawn with the browser's bare controls
 * and no stylesheet, with `ERR_BLOCKED_BY_RESPONSE.NotSameOrigin 403` in the
 * console for each of the plugin's own files.
 */

const fetched = new Map< string, Promise< string | null > >();

/**
 * How long a file may take before the frame is built without it.
 *
 * A request that never settles would otherwise hold the preview forever —
 * and in headless Chromium, whose virtual clock waits for the network, it
 * held the test harness forever too. Past this the file is linked instead.
 */
export const FETCH_TIMEOUT_MS = 8000;

/**
 * The text of a same-origin asset, or null when it cannot be had.
 *
 * Remembered per address for the life of the page: the editor previews on
 * every change, and the files are versioned in their address.
 */
export function fetchAsset( url: string ): Promise< string | null > {
	const known = fetched.get( url );

	if ( known ) {
		return known;
	}

	/*
	 * Only a web address is fetched. Anything else — `file:` in a test
	 * harness, say — cannot be fetched from a page, and asking is at best an
	 * error and at worst a request that never settles.
	 */
	if ( '' === url || ! isWebAddress( url ) ) {
		const none = Promise.resolve( null );

		fetched.set( url, none );

		return none;
	}

	const controller =
		'undefined' !== typeof AbortController ? new AbortController() : null;
	const timer = window.setTimeout(
		() => controller?.abort(),
		FETCH_TIMEOUT_MS
	);

	const attempt = fetch( url, {
		credentials: 'same-origin',
		signal: controller?.signal,
	} )
		.then( ( response ) => ( response.ok ? response.text() : null ) )
		.catch( () => null )
		.finally( () => window.clearTimeout( timer ) );

	fetched.set( url, attempt );

	return attempt;
}

/**
 * Text that is about to sit inside a `<script>` or `<style>` element.
 *
 * The only way out of either is its own closing tag, and a comment opener
 * changes how the parser reads a script; both are broken up. Neither occurs
 * in the plugin's own files today, and this is what keeps that from ever
 * mattering.
 *
 * The comment opener is spelled in two halves on purpose. Written whole, it
 * sits in this bundle — and a page that inlines the bundle, as the test
 * harness does, is then read by the parser as a script that never ends.
 */
const COMMENT_OPENER = new RegExp( '<' + '!--', 'g' );

export function escapeInline( code: string ): string {
	return code
		.replace( /<\/(script|style)/gi, '<\\/$1' )
		.replace( COMMENT_OPENER, '<\\!--' );
}

export interface InlinedAssets {
	/** Goes in the frame's head: the stylesheets. */
	head: string;
	/** Goes at the end of the frame's body: the script. */
	tail: string;
	/** Whether every file was inlined, or some had to be left as a link. */
	complete: boolean;
}

/**
 * Stylesheets and a script as markup for the frame, inlined where they
 * could be fetched and linked where they could not — a link at least works
 * on a host that allows it.
 *
 * @param styles Stylesheet addresses, in order.
 * @param script The script's address.
 */
export async function inlineAssets( styles: string[], script: string ): Promise< InlinedAssets > {
	// An address that is missing is nothing to include, not something to fail
	// on: a caller without a frame stylesheet still gets its player styled.
	const wanted = styles.map( ( url ) => String( url ?? '' ).trim() ).filter( ( url ) => '' !== url );
	const wantedScript = String( script ?? '' ).trim();

	const texts = await Promise.all( [ ...wanted, wantedScript ].map( fetchAsset ) );
	const scriptText = texts.pop() ?? null;

	let complete = true;

	const head = wanted
		.map( ( url, index ) => {
			const text = texts[ index ];

			if ( null === text ) {
				complete = false;

				return `<link rel="stylesheet" href="${ escapeAttribute( url ) }">`;
			}

			return `<style>${ escapeInline( text ) }` + STYLE_END;
		} )
		.join( '\n' );

	let tail: string;

	if ( '' === wantedScript ) {
		tail = '';
	} else if ( null === scriptText ) {
		complete = false;
		tail = `<script src="${ escapeAttribute( wantedScript ) }">` + SCRIPT_END;
	} else {
		tail = `<script>${ escapeInline( scriptText ) }` + SCRIPT_END;
	}

	return { head, tail, complete };
}

/*
 * The closing tags, spelled in two halves for the same reason as the comment
 * opener above: written whole they sit in this bundle, and a page that
 * inlines the bundle ends its script right there.
 */
const SCRIPT_END = '</' + 'script>';
const STYLE_END = '</' + 'style>';

function escapeAttribute( value: string ): string {
	return value.replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' );
}

/**
 * Whether an address, relative or not, is one the page can fetch.
 */
function isWebAddress( url: string ): boolean {
	try {
		const protocol = new URL( url, window.location.href ).protocol;

		return 'http:' === protocol || 'https:' === protocol;
	} catch {
		return false;
	}
}
