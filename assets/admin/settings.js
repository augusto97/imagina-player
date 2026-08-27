/**
 * Settings screen helpers.
 *
 * Plain ES5-compatible JavaScript with no build step: it exists so the colour
 * swatches can drive their text inputs without inline event handlers, which a
 * strict Content-Security-Policy would block.
 */
( function () {
	'use strict';

	document.addEventListener( 'input', function ( event ) {
		var swatch = event.target;

		if ( ! swatch.classList || ! swatch.classList.contains( 'imgp-swatch' ) ) {
			return;
		}

		var field = document.getElementById( swatch.getAttribute( 'data-target' ) );

		if ( field ) {
			field.value = swatch.value;
		}
	} );

	// Typing a colour by hand keeps the swatch in step.
	document.addEventListener( 'input', function ( event ) {
		var field = event.target;

		if ( ! field.id || ! /^#[0-9a-fA-F]{6}$/.test( field.value ) ) {
			return;
		}

		var swatch = document.querySelector( '.imgp-swatch[data-target="' + field.id + '"]' );

		if ( swatch ) {
			swatch.value = field.value;
		}
	} );
}() );
