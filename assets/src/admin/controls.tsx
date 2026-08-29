/**
 * Form primitives.
 *
 * Written here rather than pulled from @wordpress/components on purpose: the
 * point of this screen is that it does not look like a WordPress options page,
 * and the WordPress components carry that look with them.
 */

import { useId } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

interface FieldProps {
	label: string;
	help?: string;
	children: ReactNode;
	wide?: boolean;
}

export function Field( { label, help, children, wide }: FieldProps ) {
	return (
		<div className={ `imgpa-field${ wide ? ' imgpa-field--wide' : '' }` }>
			<div className="imgpa-field__label">
				<span>{ label }</span>
				{ help && <small>{ help }</small> }
			</div>
			<div className="imgpa-field__control">{ children }</div>
		</div>
	);
}

interface ToggleProps {
	checked: boolean;
	onChange: ( value: boolean ) => void;
	label: string;
	help?: string;
}

export function Toggle( { checked, onChange, label, help }: ToggleProps ) {
	// Nesting the input inside the label associates the two for a browser, but
	// not for every assistive technology, and not for a test that looks for the
	// pairing. An explicit id says it outright.
	const id = useId();

	return (
		<label className="imgpa-toggle" htmlFor={ id }>
			<input
				id={ id }
				type="checkbox"
				checked={ checked }
				onChange={ ( event ) => onChange( event.target.checked ) }
			/>
			<span className="imgpa-toggle__track" aria-hidden="true">
				<span className="imgpa-toggle__thumb" />
			</span>
			<span className="imgpa-toggle__text">
				{ label }
				{ help && <small>{ help }</small> }
			</span>
		</label>
	);
}

interface SelectProps {
	value: string;
	options: Array< { value: string; label: string } >;
	onChange: ( value: string ) => void;
	id?: string;
}

export function Select( { value, options, onChange, id }: SelectProps ) {
	return (
		<select
			id={ id }
			className="imgpa-select"
			value={ value }
			onChange={ ( event ) => onChange( event.target.value ) }
		>
			{ options.map( ( option ) => (
				<option key={ option.value } value={ option.value }>
					{ option.label }
				</option>
			) ) }
		</select>
	);
}

interface NumberProps {
	value: number;
	onChange: ( value: number ) => void;
	min?: number;
	max?: number;
	step?: number;
	suffix?: string;
}

export function NumberInput( {
	value,
	onChange,
	min,
	max,
	step = 1,
	suffix,
}: NumberProps ) {
	return (
		<span className="imgpa-number">
			<input
				type="number"
				value={ value }
				min={ min }
				max={ max }
				step={ step }
				onChange={ ( event ) =>
					onChange( Number( event.target.value ) )
				}
			/>
			{ suffix && <em>{ suffix }</em> }
		</span>
	);
}

interface ColorProps {
	value: string;
	onChange: ( value: string ) => void;
}

const HEX = /^#[0-9a-fA-F]{6}$/;

export function ColorInput( { value, onChange }: ColorProps ) {
	return (
		<span className="imgpa-color">
			<input
				type="color"
				// A custom property or a short hex is valid but not something the
				// native swatch can show; it falls back rather than blanking the
				// text field the user actually edits.
				value={ HEX.test( value ) ? value : '#000000' }
				onChange={ ( event ) => onChange( event.target.value ) }
				aria-label={ value }
			/>
			<input
				type="text"
				className="imgpa-color__text"
				value={ value }
				spellCheck={ false }
				onChange={ ( event ) => onChange( event.target.value ) }
			/>
		</span>
	);
}

/**
 * A colour that is also allowed to be "no colour at all".
 *
 * A plain text field could express both, but it left the common case — pick a
 * colour — without a picker, and left "transparent" looking like an empty box.
 * The choice is explicit here, and the picker appears once it is relevant.
 * @param root0
 * @param root0.value
 * @param root0.onChange
 */
export function ColorOrTransparent( { value, onChange }: ColorProps ) {
	const transparent = '' === value || 'transparent' === value;

	return (
		<span className="imgpa-colorset">
			<span className="imgpa-segment" role="group">
				<button
					type="button"
					className={ `imgpa-segment__option${
						transparent ? ' is-active' : ''
					}` }
					aria-pressed={ transparent }
					onClick={ () => onChange( 'transparent' ) }
				>
					{ __( 'Transparent', 'imagina-player' ) }
				</button>
				<button
					type="button"
					className={ `imgpa-segment__option${
						transparent ? '' : ' is-active'
					}` }
					aria-pressed={ ! transparent }
					// Starting from white rather than black: a player dropped onto a
					// page is far more often on a light background.
					onClick={ () =>
						onChange( transparent ? '#ffffff' : value )
					}
				>
					{ __( 'Colour', 'imagina-player' ) }
				</button>
			</span>

			{ ! transparent && (
				<ColorInput value={ value } onChange={ onChange } />
			) }
		</span>
	);
}

/**
 * A colour that is also allowed to be "work it out for me".
 *
 * The same shape as the control above, for the two video colours whose right
 * answer follows another setting: the buttons on the bar read against the bar's
 * own colour, and the played part of the seek bar takes the accent. Both were
 * fixed in the stylesheet before this — white icons and the waveform's colour —
 * so a pale control bar hid its own buttons and the played line could not be
 * reached from a video block at all.
 *
 * @param root0
 * @param root0.value
 * @param root0.onChange
 * @param root0.fallback  What the picker opens on when leaving automatic.
 * @param root0.autoLabel What automatic means here, in words.
 */
export function ColorOrAuto( {
	value,
	onChange,
	fallback = '#ffffff',
	autoLabel,
}: ColorProps & { fallback?: string; autoLabel?: string } ) {
	const auto = '' === value || 'auto' === value;

	return (
		<span className="imgpa-colorset">
			<span className="imgpa-segment" role="group">
				<button
					type="button"
					className={ `imgpa-segment__option${
						auto ? ' is-active' : ''
					}` }
					aria-pressed={ auto }
					onClick={ () => onChange( 'auto' ) }
				>
					{ __( 'Automatic', 'imagina-player' ) }
				</button>
				<button
					type="button"
					className={ `imgpa-segment__option${
						auto ? '' : ' is-active'
					}` }
					aria-pressed={ ! auto }
					onClick={ () => onChange( auto ? fallback : value ) }
				>
					{ __( 'Colour', 'imagina-player' ) }
				</button>
			</span>

			{ auto ? (
				Boolean( autoLabel ) && (
					<span className="imgpa-hint">{ autoLabel }</span>
				)
			) : (
				<ColorInput value={ value } onChange={ onChange } />
			) }
		</span>
	);
}

interface TextProps {
	value: string;
	onChange: ( value: string ) => void;
	placeholder?: string;
	mono?: boolean;
}

export function TextInput( { value, onChange, placeholder, mono }: TextProps ) {
	return (
		<input
			type="text"
			className={ `imgpa-text${ mono ? ' imgpa-text--mono' : '' }` }
			value={ value }
			placeholder={ placeholder }
			spellCheck={ false }
			onChange={ ( event ) => onChange( event.target.value ) }
		/>
	);
}

export function Card( {
	title,
	description,
	children,
}: {
	title?: string;
	description?: string;
	children: ReactNode;
} ) {
	return (
		<section className="imgpa-card">
			{ title && (
				<header className="imgpa-card__head">
					<h2>{ title }</h2>
					{ description && <p>{ description }</p> }
				</header>
			) }
			<div className="imgpa-card__body">{ children }</div>
		</section>
	);
}

export function Notice( {
	tone,
	children,
}: {
	tone: 'info' | 'good' | 'warn';
	children: ReactNode;
} ) {
	return (
		<div className={ `imgpa-notice imgpa-notice--${ tone }` }>
			{ children }
		</div>
	);
}

/**
 * The media library, opened from a plain admin screen.
 *
 * The block editor has `MediaUpload` for this; a settings page does not — it
 * gets `wp.media`, the frame the rest of wp-admin uses, which is only present
 * once the screen calls `wp_enqueue_media()`. If it is missing the field still
 * works as a URL box rather than showing a button that does nothing.
 */
interface MediaFrame {
	on: ( event: string, handler: () => void ) => void;
	open: () => void;
	state: () => {
		get: ( key: string ) => {
			first: () => { toJSON: () => MediaAttachment };
		};
	};
}

interface MediaAttachment {
	url?: string;
	sizes?: Record< string, { url?: string } >;
}

type MediaFactory = ( args: Record< string, unknown > ) => MediaFrame;

function mediaFactory(): MediaFactory | null {
	const media = ( window as unknown as { wp?: { media?: MediaFactory } } ).wp
		?.media;

	return 'function' === typeof media ? media : null;
}

export function hasMediaLibrary(): boolean {
	return null !== mediaFactory();
}

interface MediaProps {
	value: string;
	onChange: ( url: string ) => void;
	placeholder?: string;
	/** Restricted to this MIME family, e.g. `image`. */
	type?: string;
	title?: string;
	/** Preferred registered image size; falls back to the full URL. */
	size?: string;
}

export function MediaInput( {
	value,
	onChange,
	placeholder,
	type = 'image',
	title,
	size,
}: MediaProps ) {
	const factory = mediaFactory();

	const open = (): void => {
		if ( ! factory ) {
			return;
		}

		const frame = factory( {
			title: title ?? __( 'Choose an image', 'imagina-player' ),
			library: { type },
			button: { text: __( 'Use this image', 'imagina-player' ) },
			multiple: false,
		} );

		frame.on( 'select', () => {
			const attachment = frame
				.state()
				.get( 'selection' )
				.first()
				.toJSON();
			const scaled = size ? attachment.sizes?.[ size ]?.url : undefined;

			onChange( scaled ?? attachment.url ?? '' );
		} );

		frame.open();
	};

	return (
		<span className="imgpa-media">
			{ '' !== value && (
				<span className="imgpa-media__preview">
					{ /* eslint-disable-next-line jsx-a11y/alt-text -- decorative echo of the field's own value. */ }
					<img src={ value } alt="" />
				</span>
			) }

			<input
				type="text"
				className="imgpa-text imgpa-text--mono"
				value={ value }
				placeholder={ placeholder }
				spellCheck={ false }
				onChange={ ( event ) => onChange( event.target.value ) }
			/>

			{ factory && (
				<button
					type="button"
					className="imgpa-btn imgpa-btn--ghost"
					onClick={ open }
				>
					{ __( 'Media library', 'imagina-player' ) }
				</button>
			) }

			{ '' !== value && (
				<button
					type="button"
					className="imgpa-btn imgpa-btn--ghost"
					onClick={ () => onChange( '' ) }
				>
					{ __( 'Remove', 'imagina-player' ) }
				</button>
			) }
		</span>
	);
}
