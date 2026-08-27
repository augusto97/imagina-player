import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Card, ColorInput, Field, NumberInput, Select, TextInput, Toggle } from './controls';
import { PreviewFrame } from './PreviewFrame';
import type { Preset, SettingsPayload } from './types';

type Tab = 'controls' | 'behaviour' | 'style';

interface Props {
	settings: SettingsPayload;
	onChange: ( presets: Record< string, Preset > ) => void;
}

/**
 * `Podcast semanal` -> `podcast-semanal`, kept unique against what exists.
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
	const [ active, setActive ] = useState( keys[ 0 ] ?? settings.schema.defaultPreset );
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
			[ key ]: { ...settings.schema.presetDefaults, label: __( 'New preset', 'imagina-player' ) },
		} );
		setActive( key );
	};

	const duplicate = (): void => {
		const key = slugify( `${ preset.label } copy`, keys );

		onChange( {
			...settings.presets,
			[ key ]: { ...preset, label: `${ preset.label } (${ __( 'copy', 'imagina-player' ) })` },
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
					<button type="button" className="imgpa-btn imgpa-btn--ghost" onClick={ addPreset }>
						{ __( 'Add', 'imagina-player' ) }
					</button>
				</div>
				<ul>
					{ keys.map( ( key ) => (
						<li key={ key }>
							<button
								type="button"
								className={ `imgpa-presets__item${ key === active ? ' is-active' : '' }` }
								onClick={ () => setActive( key ) }
							>
								<span
									className="imgpa-presets__swatch"
									style={ { background: settings.presets[ key ].accent } }
								/>
								<span className="imgpa-presets__name">
									{ settings.presets[ key ].label }
									<small>
										{ settings.schema.skins[ settings.presets[ key ].skin ] ??
											settings.presets[ key ].skin }
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
							[ 'behaviour', __( 'Behaviour', 'imagina-player' ) ],
							[ 'style', __( 'Style', 'imagina-player' ) ],
						] as Array< [ Tab, string ] >
					).map( ( [ id, label ] ) => (
						<button
							key={ id }
							type="button"
							role="tab"
							aria-selected={ tab === id }
							className={ `imgpa-tabs__tab${ tab === id ? ' is-active' : '' }` }
							onClick={ () => setTab( id ) }
						>
							{ label }
						</button>
					) ) }
				</div>

				<Card>
					<Field label={ __( 'Preset name', 'imagina-player' ) } wide>
						<TextInput value={ preset.label } onChange={ ( label ) => update( { label } ) } />
					</Field>

					{ 'controls' === tab && (
						<>
							<Field
								label={ __( 'Skin', 'imagina-player' ) }
								help={ settings.schema.skinNotes[ preset.skin ] }
								wide
							>
								<Select
									value={ preset.skin }
									onChange={ ( skin ) => update( { skin } ) }
									options={ Object.entries( settings.schema.skins ).map( ( [ value, label ] ) => ( {
										value,
										label,
									} ) ) }
								/>
							</Field>

							<div className="imgpa-toggles">
								<Toggle
									label={ __( 'Artist', 'imagina-player' ) }
									checked={ preset.show_artist }
									onChange={ ( show_artist ) => update( { show_artist } ) }
								/>
								<Toggle
									label={ __( 'Title', 'imagina-player' ) }
									checked={ preset.show_title }
									onChange={ ( show_title ) => update( { show_title } ) }
								/>
								<Toggle
									label={ __( 'Cover image', 'imagina-player' ) }
									checked={ preset.show_thumbnail }
									onChange={ ( show_thumbnail ) => update( { show_thumbnail } ) }
								/>
								<Toggle
									label={ __( 'Times', 'imagina-player' ) }
									checked={ preset.show_time }
									onChange={ ( show_time ) => update( { show_time } ) }
								/>
								<Toggle
									label={ __( 'Volume', 'imagina-player' ) }
									checked={ preset.show_volume }
									onChange={ ( show_volume ) => update( { show_volume } ) }
								/>
								<Toggle
									label={ __( 'Skip buttons', 'imagina-player' ) }
									checked={ preset.show_skip }
									onChange={ ( show_skip ) => update( { show_skip } ) }
								/>
								<Toggle
									label={ __( 'Playback speed', 'imagina-player' ) }
									checked={ preset.show_speed }
									onChange={ ( show_speed ) => update( { show_speed } ) }
								/>
								<Toggle
									label={ __( 'Download', 'imagina-player' ) }
									checked={ preset.show_download }
									onChange={ ( show_download ) => update( { show_download } ) }
								/>
							</div>

							{ preset.show_skip && (
								<Field label={ __( 'Skip amount', 'imagina-player' ) }>
									<NumberInput
										value={ preset.skip_seconds }
										min={ 1 }
										max={ 120 }
										suffix={ __( 'seconds', 'imagina-player' ) }
										onChange={ ( skip_seconds ) => update( { skip_seconds } ) }
									/>
								</Field>
							) }
						</>
					) }

					{ 'behaviour' === tab && (
						<>
							<Field
								label={ __( 'Preload', 'imagina-player' ) }
								help={ __( '“Metadata” fetches just enough to know the duration. “None” waits until play is pressed.', 'imagina-player' ) }
							>
								<Select
									value={ preset.preload }
									onChange={ ( preload ) => update( { preload } ) }
									options={ [
										{ value: 'none', label: __( 'None', 'imagina-player' ) },
										{ value: 'metadata', label: __( 'Metadata', 'imagina-player' ) },
										{ value: 'auto', label: __( 'Auto', 'imagina-player' ) },
									] }
								/>
							</Field>

							<div className="imgpa-toggles">
								<Toggle
									label={ __( 'Stick to the bottom while playing', 'imagina-player' ) }
									help={ __( 'The player follows the reader when it scrolls out of view.', 'imagina-player' ) }
									checked={ preset.sticky }
									onChange={ ( sticky ) => update( { sticky } ) }
								/>
								<Toggle
									label={ __( 'Remember playback position', 'imagina-player' ) }
									help={ __( 'Each listener resumes where they left off.', 'imagina-player' ) }
									checked={ preset.remember_position }
									onChange={ ( remember_position ) => update( { remember_position } ) }
								/>
							</div>
						</>
					) }

					{ 'style' === tab && (
						<>
							<Field label={ __( 'Accent', 'imagina-player' ) } help={ __( 'Play button and highlights.', 'imagina-player' ) }>
								<ColorInput value={ preset.accent } onChange={ ( accent ) => update( { accent } ) } />
							</Field>
							<Field label={ __( 'Waveform', 'imagina-player' ) }>
								<ColorInput value={ preset.wave_color } onChange={ ( wave_color ) => update( { wave_color } ) } />
							</Field>
							<Field label={ __( 'Played portion', 'imagina-player' ) }>
								<ColorInput value={ preset.wave_progress } onChange={ ( wave_progress ) => update( { wave_progress } ) } />
							</Field>
							<Field label={ __( 'Title', 'imagina-player' ) }>
								<ColorInput value={ preset.text_color } onChange={ ( text_color ) => update( { text_color } ) } />
							</Field>
							<Field label={ __( 'Artist', 'imagina-player' ) }>
								<ColorInput value={ preset.meta_color } onChange={ ( meta_color ) => update( { meta_color } ) } />
							</Field>
							<Field label={ __( 'Background', 'imagina-player' ) } help={ __( 'Use “transparent” to inherit the page.', 'imagina-player' ) }>
								<TextInput
									value={ preset.background }
									mono
									onChange={ ( background ) => update( { background } ) }
								/>
							</Field>

							<Field label={ __( 'Waveform height', 'imagina-player' ) }>
								<NumberInput
									value={ preset.height }
									min={ 24 }
									max={ 400 }
									suffix="px"
									onChange={ ( height ) => update( { height } ) }
								/>
							</Field>
							<Field label={ __( 'Bar width', 'imagina-player' ) }>
								<NumberInput
									value={ preset.wave_bars }
									min={ 1 }
									max={ 40 }
									suffix="px"
									onChange={ ( wave_bars ) => update( { wave_bars } ) }
								/>
							</Field>
							<Field label={ __( 'Gap between bars', 'imagina-player' ) }>
								<NumberInput
									value={ preset.wave_gap }
									min={ 0 }
									max={ 20 }
									suffix="px"
									onChange={ ( wave_gap ) => update( { wave_gap } ) }
								/>
							</Field>
							<Field label={ __( 'Reflection', 'imagina-player' ) } help={ __( 'Share of the height given to the mirrored copy below.', 'imagina-player' ) }>
								<NumberInput
									value={ preset.wave_reflection }
									min={ 0 }
									max={ 0.8 }
									step={ 0.05 }
									onChange={ ( wave_reflection ) => update( { wave_reflection } ) }
								/>
							</Field>
							<div className="imgpa-toggles">
								<Toggle
									label={ __( 'Rounded bars', 'imagina-player' ) }
									checked={ preset.rounded_bars }
									onChange={ ( rounded_bars ) => update( { rounded_bars } ) }
								/>
							</div>
						</>
					) }
				</Card>

				<div className="imgpa-presets__actions">
					<button type="button" className="imgpa-btn imgpa-btn--ghost" onClick={ duplicate }>
						{ __( 'Duplicate', 'imagina-player' ) }
					</button>
					{ ! isDefault && (
						<button type="button" className="imgpa-btn imgpa-btn--danger" onClick={ remove }>
							{ __( 'Delete preset', 'imagina-player' ) }
						</button>
					) }
					{ isDefault && (
						<span className="imgpa-hint">
							{ __( 'The default preset cannot be deleted — blocks fall back to it.', 'imagina-player' ) }
						</span>
					) }
				</div>
			</div>
		</div>
	);
}
