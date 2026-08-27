import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	Card,
	ColorInput,
	ColorOrTransparent,
	Field,
	NumberInput,
	Select,
	TextInput,
	Toggle,
} from './controls';
import { PreviewFrame } from './PreviewFrame';
import type { Preset, SettingsPayload } from './types';

type Tab = 'controls' | 'behaviour' | 'style';

interface Props {
	settings: SettingsPayload;
	onChange: ( presets: Record< string, Preset > ) => void;
}

/**
 * `Podcast semanal` -> `podcast-semanal`, kept unique against what exists.
 * @param label
 * @param taken
 */
function slugify( label: string, taken: string[] ): string {
	const base =
		label
			.toLowerCase()
			.normalize( 'NFD' )
			.replace( /[̀-ͯ]/g, '' )
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' ) || 'preset';

	let key = base;
	let n = 2;

	while ( taken.includes( key ) ) {
		key = `${ base }-${ n }`;
		n++;
	}

	return key;
}

export function PresetsPanel( { settings, onChange }: Props ) {
	const keys = Object.keys( settings.presets );
	const [ active, setActive ] = useState(
		keys[ 0 ] ?? settings.schema.defaultPreset
	);
	const [ tab, setTab ] = useState< Tab >( 'controls' );

	const preset = settings.presets[ active ] ?? settings.schema.presetDefaults;

	const update = ( changes: Partial< Preset > ): void => {
		onChange( {
			...settings.presets,
			[ active ]: { ...preset, ...changes } as Preset,
		} );
	};

	const addPreset = (): void => {
		const key = slugify( __( 'New preset', 'imagina-player' ), keys );

		onChange( {
			...settings.presets,
			[ key ]: {
				...settings.schema.presetDefaults,
				label: __( 'New preset', 'imagina-player' ),
			},
		} );
		setActive( key );
	};

	const duplicate = (): void => {
		const key = slugify( `${ preset.label } copy`, keys );

		onChange( {
			...settings.presets,
			[ key ]: {
				...preset,
				label: `${ preset.label } (${ __(
					'copy',
					'imagina-player'
				) })`,
			},
		} );
		setActive( key );
	};

	const remove = (): void => {
		const next = { ...settings.presets };

		delete next[ active ];
		onChange( next );
		setActive( Object.keys( next )[ 0 ] ?? settings.schema.defaultPreset );
	};

	const isDefault = active === settings.schema.defaultPreset;

	return (
		<div className="imgpa-presets">
			<aside className="imgpa-presets__list">
				<div className="imgpa-presets__list-head">
					<h2>{ __( 'Presets', 'imagina-player' ) }</h2>
					<button
						type="button"
						className="imgpa-btn imgpa-btn--ghost"
						onClick={ addPreset }
					>
						{ __( 'Add', 'imagina-player' ) }
					</button>
				</div>
				<ul>
					{ keys.map( ( key ) => (
						<li key={ key }>
							<button
								type="button"
								className={ `imgpa-presets__item${
									key === active ? ' is-active' : ''
								}` }
								onClick={ () => setActive( key ) }
							>
								<span
									className="imgpa-presets__swatch"
									style={ {
										background:
											settings.presets[ key ].accent,
									} }
								/>
								<span className="imgpa-presets__name">
									{ settings.presets[ key ].label }
									<small>
										{ settings.schema.skins[
											settings.presets[ key ].skin
										] ?? settings.presets[ key ].skin }
									</small>
								</span>
							</button>
						</li>
					) ) }
				</ul>
			</aside>

			<div className="imgpa-presets__editor">
				<PreviewFrame preset={ preset } />

				<div className="imgpa-tabs" role="tablist">
					{ (
						[
							[ 'controls', __( 'Controls', 'imagina-player' ) ],
							[
								'behaviour',
								__( 'Behaviour', 'imagina-player' ),
							],
							[ 'style', __( 'Style', 'imagina-player' ) ],
						] as Array< [ Tab, string ] >
					 ).map( ( [ id, label ] ) => (
						<button
							key={ id }
							type="button"
							role="tab"
							aria-selected={ tab === id }
							className={ `imgpa-tabs__tab${
								tab === id ? ' is-active' : ''
							}` }
							onClick={ () => setTab( id ) }
						>
							{ label }
						</button>
					) ) }
				</div>

				<Card>
					<Field label={ __( 'Preset name', 'imagina-player' ) } wide>
						<TextInput
							value={ preset.label }
							onChange={ ( label ) => update( { label } ) }
						/>
					</Field>

					<Field
						label={ __( 'Description', 'imagina-player' ) }
						help={ __(
							'A note to yourself about when to use this preset.',
							'imagina-player'
						) }
						wide
					>
						<TextInput
							value={ preset.description }
							placeholder={ __(
								'For the weekly podcast',
								'imagina-player'
							) }
							onChange={ ( description ) =>
								update( { description } )
							}
						/>
					</Field>

					{ 'controls' === tab && (
						<>
							<Field
								label={ __( 'Skin', 'imagina-player' ) }
								help={
									settings.schema.skinNotes[ preset.skin ]
								}
								wide
							>
								<Select
									value={ preset.skin }
									onChange={ ( skin ) => update( { skin } ) }
									options={ Object.entries(
										settings.schema.skins
									).map( ( [ value, label ] ) => ( {
										value,
										label,
									} ) ) }
								/>
							</Field>

							<div className="imgpa-toggles">
								<Toggle
									label={ __( 'Artist', 'imagina-player' ) }
									checked={ preset.show_artist }
									onChange={ ( value ) =>
										update( { show_artist: value } )
									}
								/>
								<Toggle
									label={ __( 'Title', 'imagina-player' ) }
									checked={ preset.show_title }
									onChange={ ( value ) =>
										update( { show_title: value } )
									}
								/>
								<Toggle
									label={ __(
										'Cover image',
										'imagina-player'
									) }
									checked={ preset.show_thumbnail }
									onChange={ ( value ) =>
										update( { show_thumbnail: value } )
									}
								/>
								<Toggle
									label={ __( 'Times', 'imagina-player' ) }
									checked={ preset.show_time }
									onChange={ ( value ) =>
										update( { show_time: value } )
									}
								/>
								<Toggle
									label={ __( 'Volume', 'imagina-player' ) }
									checked={ preset.show_volume }
									onChange={ ( value ) =>
										update( { show_volume: value } )
									}
								/>
								<Toggle
									label={ __(
										'Skip buttons',
										'imagina-player'
									) }
									checked={ preset.show_skip }
									onChange={ ( value ) =>
										update( { show_skip: value } )
									}
								/>
								<Toggle
									label={ __(
										'Playback speed',
										'imagina-player'
									) }
									checked={ preset.show_speed }
									onChange={ ( value ) =>
										update( { show_speed: value } )
									}
								/>
								<Toggle
									label={ __( 'Download', 'imagina-player' ) }
									checked={ preset.show_download }
									onChange={ ( value ) =>
										update( { show_download: value } )
									}
								/>
							</div>

							{ preset.show_skip && (
								<Field
									label={ __(
										'Skip amount',
										'imagina-player'
									) }
								>
									<NumberInput
										value={ preset.skip_seconds }
										min={ 1 }
										max={ 120 }
										suffix={ __(
											'seconds',
											'imagina-player'
										) }
										onChange={ ( value ) =>
											update( { skip_seconds: value } )
										}
									/>
								</Field>
							) }
						</>
					) }

					{ 'behaviour' === tab && (
						<>
							<Field
								label={ __( 'Preload', 'imagina-player' ) }
								help={ __(
									'“Metadata” fetches just enough to know the duration. “None” waits until play is pressed.',
									'imagina-player'
								) }
							>
								<Select
									value={ preset.preload }
									onChange={ ( preload ) =>
										update( { preload } )
									}
									options={ [
										{
											value: 'none',
											label: __(
												'None',
												'imagina-player'
											),
										},
										{
											value: 'metadata',
											label: __(
												'Metadata',
												'imagina-player'
											),
										},
										{
											value: 'auto',
											label: __(
												'Auto',
												'imagina-player'
											),
										},
									] }
								/>
							</Field>

							<Field
								label={ __(
									'When the track finishes',
									'imagina-player'
								) }
							>
								<Select
									value={ preset.on_end }
									onChange={ ( value ) =>
										update( { on_end: value } )
									}
									options={ [
										{
											value: 'reset',
											label: __(
												'Rewind to the start',
												'imagina-player'
											),
										},
										{
											value: 'loop',
											label: __(
												'Play again',
												'imagina-player'
											),
										},
										{
											value: 'stop',
											label: __(
												'Stop where it ended',
												'imagina-player'
											),
										},
									] }
								/>
							</Field>

							<div className="imgpa-toggles">
								<Toggle
									label={ __(
										'Stick to the bottom while playing',
										'imagina-player'
									) }
									help={ __(
										'The player follows the reader when it scrolls out of view.',
										'imagina-player'
									) }
									checked={ preset.sticky }
									onChange={ ( sticky ) =>
										update( { sticky } )
									}
								/>
								<Toggle
									label={ __(
										'Remember playback position',
										'imagina-player'
									) }
									help={ __(
										'Each listener resumes where they left off.',
										'imagina-player'
									) }
									checked={ preset.remember_position }
									onChange={ ( value ) =>
										update( { remember_position: value } )
									}
								/>
							</div>

							{ preset.sticky && (
								<Field
									label={ __(
										'Where it sticks',
										'imagina-player'
									) }
								>
									<Select
										value={ preset.sticky_position }
										onChange={ ( value ) =>
											update( { sticky_position: value } )
										}
										options={ [
											{
												value: 'bottom',
												label: __(
													'Full width, bottom',
													'imagina-player'
												),
											},
											{
												value: 'bottom-left',
												label: __(
													'Bottom left',
													'imagina-player'
												),
											},
											{
												value: 'bottom-right',
												label: __(
													'Bottom right',
													'imagina-player'
												),
											},
										] }
									/>
								</Field>
							) }
						</>
					) }

					{ 'style' === tab && (
						<>
							<Field
								label={ __( 'Accent', 'imagina-player' ) }
								help={ __(
									'Play button and highlights.',
									'imagina-player'
								) }
							>
								<ColorInput
									value={ preset.accent }
									onChange={ ( accent ) =>
										update( { accent } )
									}
								/>
							</Field>
							<Field label={ __( 'Waveform', 'imagina-player' ) }>
								<ColorInput
									value={ preset.wave_color }
									onChange={ ( value ) =>
										update( { wave_color: value } )
									}
								/>
							</Field>
							<Field
								label={ __(
									'Played portion',
									'imagina-player'
								) }
							>
								<ColorInput
									value={ preset.wave_progress }
									onChange={ ( value ) =>
										update( { wave_progress: value } )
									}
								/>
							</Field>
							<Field label={ __( 'Title', 'imagina-player' ) }>
								<ColorInput
									value={ preset.text_color }
									onChange={ ( value ) =>
										update( { text_color: value } )
									}
								/>
							</Field>
							<Field label={ __( 'Artist', 'imagina-player' ) }>
								<ColorInput
									value={ preset.meta_color }
									onChange={ ( value ) =>
										update( { meta_color: value } )
									}
								/>
							</Field>
							<Field
								label={ __( 'Background', 'imagina-player' ) }
								help={ __(
									'Transparent lets the page behind the player show through.',
									'imagina-player'
								) }
							>
								<ColorOrTransparent
									value={ preset.background }
									onChange={ ( background ) =>
										update( { background } )
									}
								/>
							</Field>

							<Field
								label={ __(
									'Waveform height',
									'imagina-player'
								) }
							>
								<NumberInput
									value={ preset.height }
									min={ 24 }
									max={ 400 }
									suffix="px"
									onChange={ ( height ) =>
										update( { height } )
									}
								/>
							</Field>
							<Field
								label={ __( 'Bar width', 'imagina-player' ) }
							>
								<NumberInput
									value={ preset.wave_bars }
									min={ 1 }
									max={ 40 }
									suffix="px"
									onChange={ ( value ) =>
										update( { wave_bars: value } )
									}
								/>
							</Field>
							<Field
								label={ __(
									'Gap between bars',
									'imagina-player'
								) }
							>
								<NumberInput
									value={ preset.wave_gap }
									min={ 0 }
									max={ 20 }
									suffix="px"
									onChange={ ( value ) =>
										update( { wave_gap: value } )
									}
								/>
							</Field>
							<Field
								label={ __(
									'Corner radius',
									'imagina-player'
								) }
								help={ __(
									'Rounds the whole player.',
									'imagina-player'
								) }
							>
								<NumberInput
									value={ preset.border_radius }
									min={ 0 }
									max={ 40 }
									suffix="px"
									onChange={ ( value ) =>
										update( { border_radius: value } )
									}
								/>
							</Field>
							<Field
								label={ __( 'Reflection', 'imagina-player' ) }
								help={ __(
									'Share of the height given to the mirrored copy below.',
									'imagina-player'
								) }
							>
								<NumberInput
									value={ preset.wave_reflection }
									min={ 0 }
									max={ 0.8 }
									step={ 0.05 }
									onChange={ ( value ) =>
										update( { wave_reflection: value } )
									}
								/>
							</Field>
							<div className="imgpa-toggles">
								<Toggle
									label={ __(
										'Rounded bars',
										'imagina-player'
									) }
									checked={ preset.rounded_bars }
									onChange={ ( value ) =>
										update( { rounded_bars: value } )
									}
								/>
							</div>
						</>
					) }
				</Card>

				<div className="imgpa-presets__actions">
					<button
						type="button"
						className="imgpa-btn imgpa-btn--ghost"
						onClick={ duplicate }
					>
						{ __( 'Duplicate', 'imagina-player' ) }
					</button>
					{ ! isDefault && (
						<button
							type="button"
							className="imgpa-btn imgpa-btn--danger"
							onClick={ remove }
						>
							{ __( 'Delete preset', 'imagina-player' ) }
						</button>
					) }
					{ isDefault && (
						<span className="imgpa-hint">
							{ __(
								'The default preset cannot be deleted — blocks fall back to it.',
								'imagina-player'
							) }
						</span>
					) }
				</div>
			</div>
		</div>
	);
}
