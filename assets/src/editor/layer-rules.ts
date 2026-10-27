/**
 * Why the server would leave an overlay out, as a code.
 *
 * The same rules as `Layers::sanitize()`, which drops a call to action with no
 * address, a caption with no words and a picture with no picture. The editor
 * says so on the overlay itself; without that, one that never appeared read
 * as the feature not working. `tests/test-editor-logic.php` runs both sides
 * over the same layers so they cannot drift apart.
 */

/** What is missing, or nothing. */
export type LayerGap = '' | 'words' | 'image' | 'label' | 'shortcode' | 'url';

export function layerGap(
	layer: Record< string, string | number | boolean | undefined >
): LayerGap {
	const type = String( layer.type ?? 'cta' );
	const has = ( key: string ): boolean =>
		'' !== String( layer[ key ] ?? '' ).trim();

	switch ( type ) {
		case 'email':
			return '';
		case 'text':
			return has( 'title' ) || has( 'text' ) ? '' : 'words';
		case 'image':
			return has( 'image' ) ? '' : 'image';
		case 'hotspot':
			return has( 'title' ) || has( 'text' ) || has( 'url' )
				? ''
				: 'label';
		case 'shortcode':
			return String( layer.shortcode ?? '' ).includes( '[' )
				? ''
				: 'shortcode';
		default:
			return has( 'url' ) ? '' : 'url';
	}
}
