/**
 * Where the browser should look for the lazily-loaded chunks.
 *
 * Webpack's default is to work it out from `document.currentScript.src`, which
 * is right until something inlines the bundle into the page — and optimisation
 * plugins do exactly that. With no script URL to read, the automatic path
 * *throws*, and the whole player dies before it renders anything.
 *
 * So WordPress, which knows perfectly well where the files are, says so. The
 * automatic behaviour stays as the fallback for anything that loads the bundle
 * without our inline data.
 *
 * Imported for its side effect, and first, because webpack reads this at the
 * moment a chunk is requested and there is no second chance.
 */

const url = window.imaginaPlayer?.assetUrl;

if ( url ) {
	// The name is webpack's, not ours: it is the free variable the compiler
	// rewrites into the chunk loader, so it cannot be spelled any other way.
	// eslint-disable-next-line camelcase
	__webpack_public_path__ = url;
}

export {};
