/**
 * The things that appear part-way through and ask something of the listener.
 *
 * Its own chunk, loaded only by a player that actually carries one — most do
 * not, and a call to action nobody configured should not cost anything.
 *
 * Not video-only. The markup is rendered by the server and merely hidden, so
 * the layer exists in the page for anything that reads pages rather than runs
 * them; all this file does is decide when to stop hiding it.
 */

import type { PlayerConfig, PlayerMedia, RuntimeData } from './types';

/** How long the thank-you stays up before the gate lets go. */
const THANKS_MS = 1600;

/** Layers a visitor has already dealt with, so they are not shown twice. */
const SEEN_KEY = 'imagina-player-layers';

interface LayerHost {
	root: HTMLElement;
	/* Not an element: a call to action at 60% has to work on a YouTube video
	   too, and that is driven through a stand-in rather than an element. */
	media: PlayerMedia;
	config: PlayerConfig;
	runtime: RuntimeData;
}

interface LayerSpec {
	type: 'cta' | 'bar' | 'email';
	at: number;
	skip: boolean;
	list: string;
}

export class LayerStack {
	private readonly host: LayerHost;

	private readonly elements: HTMLElement[];

	private readonly specs: LayerSpec[];

	/** Indexes already shown, so a rewind does not show them all over again. */
	private readonly shown = new Set< number >();

	private readonly cleanup: Array< () => void > = [];

	constructor( host: LayerHost ) {
		this.host = host;
		this.specs = ( host.config.layers ?? [] ) as LayerSpec[];
		this.elements = Array.from(
			host.root.querySelectorAll< HTMLElement >( '.imgp__layer' )
		);

		this.bindDismiss();
		this.bindForms();

		const watch = (): void => this.check();

		this.host.media.addEventListener( 'timeupdate', watch );
		this.host.media.addEventListener( 'ended', watch );

		this.cleanup.push( () => {
			this.host.media.removeEventListener( 'timeupdate', watch );
			this.host.media.removeEventListener( 'ended', watch );
		} );
	}

	destroy(): void {
		for ( const off of this.cleanup ) {
			off();
		}

		this.cleanup.length = 0;
	}

	/**
	 * Has playback reached any layer's moment?
	 *
	 * On `timeupdate`, which fires four times a second — cheap enough, and the
	 * only event that reports progress. A layer at 100% is triggered by `ended`
	 * rather than by the clock, because a track almost never reports a current
	 * time exactly equal to its duration.
	 */
	private check(): void {
		const duration = this.host.media.duration;

		if ( ! Number.isFinite( duration ) || duration <= 0 ) {
			return;
		}

		const percent = ( this.host.media.currentTime / duration ) * 100;
		const finished = this.host.media.ended;

		this.specs.forEach( ( spec, index ) => {
			if ( this.shown.has( index ) ) {
				return;
			}

			const due = spec.at >= 100 ? finished : percent >= spec.at;

			if ( ! due || this.dismissed( index ) ) {
				return;
			}

			this.show( index, spec );
		} );
	}

	private show( index: number, spec: LayerSpec ): void {
		const element = this.elements[ index ];

		if ( ! element ) {
			return;
		}

		this.shown.add( index );
		element.hidden = false;
		this.host.root.classList.add( 'has-layer' );

		// A bar sits alongside playback; the other two are asking a question, so
		// they stop it. Nothing resumes on its own afterwards: the person chose
		// to stop reading, and starting the video under them would be rude.
		if ( 'bar' !== spec.type ) {
			this.host.media.pause();
			this.host.root.classList.add( 'has-modal-layer' );

			element
				.querySelector< HTMLElement >(
					'input, a.imgp__layer-button, button'
				)
				?.focus();
		}
	}

	private hide( index: number ): void {
		const element = this.elements[ index ];

		if ( ! element ) {
			return;
		}

		element.hidden = true;
		this.remember( index );

		if ( this.elements.every( ( layer ) => layer.hidden ) ) {
			this.host.root.classList.remove( 'has-layer', 'has-modal-layer' );
		}
	}

	private bindDismiss(): void {
		this.elements.forEach( ( element, index ) => {
			const close =
				element.querySelector< HTMLButtonElement >(
					'.imgp__layer-close'
				);

			if ( ! close ) {
				return;
			}

			const dismiss = (): void => this.hide( index );

			close.addEventListener( 'click', dismiss );
			this.cleanup.push( () =>
				close.removeEventListener( 'click', dismiss )
			);
		} );

		// Escape closes whatever is open, which is what a person expects of
		// anything that covers the thing they were doing.
		const escape = ( event: KeyboardEvent ): void => {
			if ( 'Escape' !== event.key ) {
				return;
			}

			this.elements.forEach( ( element, index ) => {
				if ( ! element.hidden && this.specs[ index ]?.skip ) {
					this.hide( index );
				}
			} );
		};

		this.host.root.addEventListener( 'keydown', escape );
		this.cleanup.push( () =>
			this.host.root.removeEventListener( 'keydown', escape )
		);
	}

	private bindForms(): void {
		this.elements.forEach( ( element, index ) => {
			const form =
				element.querySelector< HTMLFormElement >( '.imgp__layer-form' );

			if ( ! form ) {
				return;
			}

			const submit = ( event: Event ): void => {
				event.preventDefault();
				void this.send( form, element, index );
			};

			form.addEventListener( 'submit', submit );
			this.cleanup.push( () =>
				form.removeEventListener( 'submit', submit )
			);
		} );
	}

	private async send(
		form: HTMLFormElement,
		element: HTMLElement,
		index: number
	): Promise< void > {
		const email = form.querySelector< HTMLInputElement >(
			'input[type="email"]'
		);

		if ( ! email || ! email.value.trim() ) {
			email?.focus();

			return;
		}

		// Checked here as well as on the server, because a person who mistypes
		// their address should hear about it from the field they typed it into,
		// not from a round trip.
		if ( ! email.checkValidity() ) {
			this.fail( element, email.validationMessage );
			email.focus();

			return;
		}

		const button = form.querySelector< HTMLButtonElement >(
			'button[type="submit"]'
		);

		if ( button ) {
			button.disabled = true;
		}

		try {
			const response = await window.fetch(
				this.host.runtime.restUrl + '/lead',
				{
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( {
						email: email.value.trim(),
						list: this.specs[ index ]?.list ?? '',
						// The honeypot, sent as the server rendered it: empty
						// from a person, filled by anything that fills forms.
						website:
							form.querySelector< HTMLInputElement >(
								'input[name="website"]'
							)?.value ?? '',
						source: this.host.config.protectedId || 0,
						at: Math.round( this.host.media.currentTime ),
					} ),
				}
			);

			if ( ! response.ok ) {
				const body = ( await response
					.json()
					.catch( () => ( {} ) ) ) as {
					message?: string;
				};

				this.fail( element, body.message ?? '' );

				if ( button ) {
					button.disabled = false;
				}

				return;
			}
		} catch {
			this.fail( element, '' );

			if ( button ) {
				button.disabled = false;
			}

			return;
		}

		form.hidden = true;
		element.querySelector< HTMLElement >( '.imgp__layer-fine' )?.remove();

		const thanks = element.querySelector< HTMLElement >(
			'.imgp__layer-thanks'
		);

		if ( thanks ) {
			thanks.hidden = false;
		}

		// The gate lets go by itself once the thank-you has been read, and the
		// video picks up where it stopped. Making someone find a close button
		// after they have just given you their address is a poor thank-you.
		window.setTimeout( () => {
			this.hide( index );
			void this.host.media.play().catch( () => undefined );
		}, THANKS_MS );
	}

	private fail( element: HTMLElement, message: string ): void {
		let error =
			element.querySelector< HTMLElement >( '.imgp__layer-error' );

		if ( ! error ) {
			error = element.ownerDocument.createElement( 'p' );
			error.className = 'imgp__layer-error';
			// Announced rather than only shown: someone who cannot see the field
			// turn red still needs to be told what went wrong.
			error.setAttribute( 'role', 'alert' );
			(
				element.querySelector( '.imgp__layer-action' ) ??
				element.querySelector( '.imgp__layer-body' )
			)?.appendChild( error );
		}

		error.textContent =
			message ||
			this.host.runtime.i18n.layerFailed ||
			'That could not be sent. Please try again.';
	}

	/**
	 * Layers a visitor has already answered or dismissed.
	 *
	 * Per player and per layer, kept in the browser: showing the same gate to
	 * the same person on every visit is how a conversion tool becomes a reason
	 * to leave.
	 * @param index
	 */
	private dismissed( index: number ): boolean {
		try {
			const seen = JSON.parse(
				window.localStorage.getItem( SEEN_KEY ) ?? '{}'
			) as Record< string, boolean >;

			return true === seen[ this.key( index ) ];
		} catch {
			return false;
		}
	}

	private remember( index: number ): void {
		try {
			const seen = JSON.parse(
				window.localStorage.getItem( SEEN_KEY ) ?? '{}'
			) as Record< string, boolean >;

			seen[ this.key( index ) ] = true;
			window.localStorage.setItem( SEEN_KEY, JSON.stringify( seen ) );
		} catch {
			// Storage off. The layer shows again next time, which is a smaller
			// problem than not working at all.
		}
	}

	private key( index: number ): string {
		return this.host.config.id + ':' + index;
	}
}
