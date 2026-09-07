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

	const attempt = fetch( url, { credentials: 'same-origin' } )
		.then( ( response ) => ( response.ok ? response.text() : null ) )
		.catch( () => null );

	fetched.set( url, attempt );

	return attempt;
}

/**
 * Text that is about to sit inside a `<script>` or `<style>` element.
 *
 * The only way out of either is its own closing tag, and the parser also
 * ends a script at `<!--` in some states; both are broken up. Neither occurs
 * in the plugin's own files today, and this is what keeps that from ever
 * mattering.
 */
export function escapeInline( code: string ): string {
	return code.replace( /<\/(script|style)/gi, '<\\/$1' ).replace( /<!--/g, '<\\!--' );
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
	const texts = await Promise.all( [ ...styles, script ].map( fetchAsset ) );
	const scriptText = texts.pop() ?? null;

	let complete = true;

	const head = styles
		.map( ( url, index ) => {
			const text = texts[ index ];

			if ( null === text ) {
				complete = false;

				return `<link rel="stylesheet" href="${ escapeAttribute( url ) }">`;
			}

			return `<style>${ escapeInline( text ) }</style>`;
		} )
		.join( '\n' );

	let tail: string;

	if ( null === scriptText ) {
		complete = false;
		tail = `<script src="${ escapeAttribute( script ) }"></script>`;
	} else {
		tail = `<script>${ escapeInline( scriptText ) }</script>`;
	}

	return { head, tail, complete };
}

function escapeAttribute( value: string ): string {
	return value.replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' );
}
