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

	/**
	 * Build waveforms for every file that is missing one.
	 *
	 * Sequential rather than parallel: each call runs ffmpeg over a whole file,
	 * and firing twenty at once is how you take a shared host down.
	 */
	var button = document.getElementById( 'imgp-generate-waveforms' );
	var status = document.getElementById( 'imgp-generate-status' );

	function say( message ) {
		if ( status ) {
			status.textContent = message;
		}
	}

	function request( url, options ) {
		options = options || {};
		options.headers = options.headers || {};
		options.headers['X-WP-Nonce'] = button.getAttribute( 'data-nonce' );
		options.credentials = 'same-origin';

		return fetch( url, options );
	}

	function generate( items, index, done, failed ) {
		if ( index >= items.length ) {
			say(
				done + ' of ' + items.length + ' generated' +
				( failed ? ', ' + failed + ' could not be read' : '' ) + '.'
			);
			button.disabled = false;
			return;
		}

		say( 'Generating ' + ( index + 1 ) + ' of ' + items.length + ': ' + items[ index ].title );

		request( button.getAttribute( 'data-rest' ) + '/peaks/generate', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { attachmentId: items[ index ].id } )
		} )
			.then( function ( response ) {
				return response.ok ? done + 1 : done;
			} )
			.catch( function () {
				return done;
			} )
			.then( function ( nextDone ) {
				generate(
					items,
					index + 1,
					nextDone,
					failed + ( nextDone === done ? 1 : 0 )
				);
			} );
	}

	if ( button ) {
		button.addEventListener( 'click', function () {
			button.disabled = true;
			say( 'Looking for files without a waveform…' );

			request( button.getAttribute( 'data-rest' ) + '/peaks/pending' )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( data ) {
					if ( ! data.pending || ! data.pending.length ) {
						say( 'Every file already has a waveform.' );
						button.disabled = false;
						return;
					}

					generate( data.pending, 0, 0, 0 );
				} )
				.catch( function () {
					say( 'The list of pending files could not be loaded.' );
					button.disabled = false;
				} );
		} );
	}

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
