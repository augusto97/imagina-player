/**
 * The two controls the inspector needs more than once.
 *
 * A block setting that can inherit has three answers, not two — use the site's,
 * show it, hide it — and the obvious way to offer three answers is a dropdown.
 * Thirteen of those in a column is what the video panel had become: thirteen
 * identical rows, each taking a click to read, none of them telling you at a
 * glance which ones this block had actually changed.
 *
 * A segmented control says all three answers at once, in a third of the height,
 * and the selected segment is the answer. Where a row differs from the site's
 * setting it is marked, so a long list can be scanned for what this block does
 * differently rather than read from the top.
 */

import { __ } from '@wordpress/i18n';

/** No answer of its own: whatever the site is set to. */
export const INHERIT = '';

interface TristateProps {
	label: string;
	value: string;
	onChange: ( value: string ) => void;
	/** What the site's own setting is, shown on the inherit segment. */
	siteValue?: boolean;
	help?: string;
	/**
	 * On and Off rather than Show and Hide, for a setting that is about how
	 * the player behaves rather than whether something is on screen. "Hide"
	 * on "Hide YouTube's interface" meant showing it.
	 */
	onOff?: boolean;
}

export function Tristate( {
	label,
	value,
	onChange,
	siteValue,
	help,
	onOff = false,
}: TristateProps ) {
	const current = INHERIT === value || undefined === value ? INHERIT : value;
	/*
	 * The inherit segment says what it inherits where that is known, because
	 * "Site" on its own leaves you clicking through to find out what the site
	 * actually does.
	 */
	let inheritLabel: string = __( 'Site', 'imagina-player' );

	if ( true === siteValue ) {
		inheritLabel = __( 'Site: on', 'imagina-player' );
	} else if ( false === siteValue ) {
		inheritLabel = __( 'Site: off', 'imagina-player' );
	}

	const options: Array< { value: string; label: string } > = [
		{ value: INHERIT, label: inheritLabel },
		{
			value: 'yes',
			label: onOff
				? __( 'On', 'imagina-player' )
				: __( 'Show', 'imagina-player' ),
		},
		{
			value: 'no',
			label: onOff
				? __( 'Off', 'imagina-player' )
				: __( 'Hide', 'imagina-player' ),
		},
	];

	return (
		<div
			className={ `imgp-editor__tri${
				INHERIT === current ? '' : ' is-set'
			}` }
		>
			<span className="imgp-editor__tri-label">{ label }</span>
			<span className="imgp-editor__tri-options" role="group">
				{ options.map( ( option ) => (
					<button
						key={ option.value || 'inherit' }
						type="button"
						className={ `imgp-editor__tri-option${
							current === option.value ? ' is-active' : ''
						}` }
						aria-pressed={ current === option.value }
						aria-label={ `${ label }: ${ option.label }` }
						onClick={ () => onChange( option.value ) }
					>
						{ option.label }
					</button>
				) ) }
			</span>
			{ help && <span className="imgp-editor__tri-help">{ help }</span> }
		</div>
	);
}

interface TristateListProps {
	items: ReadonlyArray< readonly [ string, string ] >;
	values: Record< string, unknown >;
	onChange: ( attribute: string, value: string ) => void;
	/** Site defaults, keyed by attribute, so each row can say what it inherits. */
	site?: Record< string, boolean >;
	/** On and Off rather than Show and Hide; see `Tristate`. */
	onOff?: boolean;
}

/**
 * A run of settings that all inherit the same way.
 * @param root0
 * @param root0.items
 * @param root0.values
 * @param root0.onChange
 * @param root0.site
 * @param root0.onOff
 */
export function TristateList( {
	items,
	values,
	onChange,
	site,
	onOff = false,
}: TristateListProps ) {
	return (
		<div className="imgp-editor__tri-list">
			{ items.map( ( [ attribute, label ] ) => (
				<Tristate
					key={ attribute }
					label={ label }
					value={ String( values[ attribute ] ?? INHERIT ) }
					siteValue={ site?.[ attribute ] }
					onOff={ onOff }
					onChange={ ( value ) => onChange( attribute, value ) }
				/>
			) ) }
		</div>
	);
}

interface SwatchesProps {
	items: ReadonlyArray< readonly [ string, string, string ] >;
	values: Record< string, unknown >;
	onChange: ( attribute: string, value: string ) => void;
	/** Reset puts the row back to the preset or the site setting. */
	resetLabel?: string;
}

/**
 * A row of colours, each with a way back to the setting it came from.
 *
 * Two near-identical versions of this existed — one for the audio colours and
 * one inside the video panel — with different fallbacks and different reset
 * behaviour, which is how the same block ended up with two things called
 * "Colours".
 * @param root0
 * @param root0.items
 * @param root0.values
 * @param root0.onChange
 */
export function Swatches( { items, values, onChange }: SwatchesProps ) {
	return (
		<div className="imgp-editor__swatches">
			{ items.map( ( [ attribute, label, fallback ] ) => {
				const stored = String( values[ attribute ] ?? '' );
				const set = '' !== stored && 'auto' !== stored;

				return (
					<div
						className={ `imgp-editor__swatch${
							set ? ' is-set' : ''
						}` }
						key={ attribute }
					>
						<input
							type="color"
							id={ `imgp-swatch-${ attribute }` }
							aria-label={ label }
							value={ set ? stored : fallback }
							onChange={ ( event ) =>
								onChange( attribute, event.target.value )
							}
						/>
						<label htmlFor={ `imgp-swatch-${ attribute }` }>
							{ label }
						</label>
						<button
							type="button"
							className="imgp-editor__swatch-reset"
							disabled={ ! set }
							onClick={ () => onChange( attribute, INHERIT ) }
						>
							{ __( 'Reset', 'imagina-player' ) }
						</button>
					</div>
				);
			} ) }
		</div>
	);
}
