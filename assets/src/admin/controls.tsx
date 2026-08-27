/**
 * Form primitives.
 *
 * Written here rather than pulled from @wordpress/components on purpose: the
 * point of this screen is that it does not look like a WordPress options page,
 * and the WordPress components carry that look with them.
 */

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
	return (
		<label className="imgpa-toggle">
			<input
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

export function NumberInput( { value, onChange, min, max, step = 1, suffix }: NumberProps ) {
	return (
		<span className="imgpa-number">
			<input
				type="number"
				value={ value }
				min={ min }
				max={ max }
				step={ step }
				onChange={ ( event ) => onChange( Number( event.target.value ) ) }
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

export function Card( { title, description, children }: { title?: string; description?: string; children: ReactNode } ) {
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

export function Notice( { tone, children }: { tone: 'info' | 'good' | 'warn'; children: ReactNode } ) {
	return <div className={ `imgpa-notice imgpa-notice--${ tone }` }>{ children }</div>;
}
