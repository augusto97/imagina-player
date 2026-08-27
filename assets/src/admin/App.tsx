import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { loadSettings, saveSettings } from './api';
import { AdvancedPanel, BrandingPanel, ProtectionPanel, WaveformsPanel } from './panels';
import { PresetsPanel } from './PresetsPanel';
import type { Preset, SettingsPayload } from './types';

type Section = 'presets' | 'branding' | 'waveforms' | 'protection' | 'advanced';

const SECTIONS: Array< { id: Section; label: string } > = [
	{ id: 'presets', label: __( 'Presets', 'imagina-player' ) },
	{ id: 'branding', label: __( 'Branding', 'imagina-player' ) },
	{ id: 'waveforms', label: __( 'Waveforms', 'imagina-player' ) },
	{ id: 'protection', label: __( 'Protection', 'imagina-player' ) },
	{ id: 'advanced', label: __( 'Advanced', 'imagina-player' ) },
];

export function App() {
	const [ settings, setSettings ] = useState< SettingsPayload | null >( null );
	const [ section, setSection ] = useState< Section >( 'presets' );
	const [ dirty, setDirty ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ message, setMessage ] = useState( '' );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		loadSettings()
			.then( setSettings )
			.catch( () => setError( __( 'The settings could not be loaded.', 'imagina-player' ) ) );
	}, [] );

	// Leaving with unsaved changes loses them, and this screen has no autosave.
	useEffect( () => {
		if ( ! dirty ) {
			return;
		}

		const warn = ( event: BeforeUnloadEvent ): void => {
			event.preventDefault();
			event.returnValue = '';
		};

		window.addEventListener( 'beforeunload', warn );

		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );

	const patch = ( changes: Partial< SettingsPayload > ): void => {
		setSettings( ( current ) => ( current ? { ...current, ...changes } : current ) );
		setDirty( true );
		setMessage( '' );
	};

	const save = async (): Promise< void > => {
		if ( ! settings ) {
			return;
		}

		setSaving( true );
		setError( '' );

		try {
			const saved = await saveSettings( {
				presets: settings.presets,
				peaks: settings.peaks,
				protection: settings.protection,
				advanced: settings.advanced,
				branding: settings.branding,
			} );

			setSettings( saved );
			setDirty( false );
			setMessage( __( 'Saved.', 'imagina-player' ) );
		} catch {
			setError( __( 'The settings could not be saved.', 'imagina-player' ) );
		} finally {
			setSaving( false );
		}
	};

	if ( error && ! settings ) {
		return <div className="imgpa imgpa--error">{ error }</div>;
	}

	if ( ! settings ) {
		return (
			<div className="imgpa imgpa--loading">
				<span className="imgpa-spinner" aria-hidden="true" />
				{ __( 'Loading…', 'imagina-player' ) }
			</div>
		);
	}

	return (
		<div className="imgpa">
			<header className="imgpa-header">
				<div className="imgpa-header__brand">
					<span className="imgpa-logo" aria-hidden="true">
						<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
							<path d="M3 12h2M8 6v12M13 9v6M18 4v16M21 11v2" />
						</svg>
					</span>
					<span>
						<strong>{ __( 'Imagina Player', 'imagina-player' ) }</strong>
						<small>{ settings.system.version }</small>
					</span>
				</div>

				<div className="imgpa-header__actions">
					{ message && <span className="imgpa-saved">{ message }</span> }
					{ error && <span className="imgpa-error">{ error }</span> }
					<button
						type="button"
						className="imgpa-btn imgpa-btn--primary"
						onClick={ save }
						disabled={ saving || ! dirty }
					>
						{ saving ? __( 'Saving…', 'imagina-player' ) : __( 'Save changes', 'imagina-player' ) }
					</button>
				</div>
			</header>

			<div className="imgpa-body">
				<nav className="imgpa-nav" aria-label={ __( 'Sections', 'imagina-player' ) }>
					{ SECTIONS.map( ( item ) => (
						<button
							key={ item.id }
							type="button"
							className={ `imgpa-nav__item${ section === item.id ? ' is-active' : '' }` }
							aria-current={ section === item.id ? 'page' : undefined }
							onClick={ () => setSection( item.id ) }
						>
							{ item.label }
						</button>
					) ) }
				</nav>

				<main className="imgpa-main">
					{ 'presets' === section && (
						<PresetsPanel
							settings={ settings }
							onChange={ ( presets: Record< string, Preset > ) => patch( { presets } ) }
						/>
					) }
							{ 'branding' === section && <BrandingPanel settings={ settings } onChange={ patch } /> }
					{ 'waveforms' === section && <WaveformsPanel settings={ settings } onChange={ patch } /> }
					{ 'protection' === section && <ProtectionPanel settings={ settings } onChange={ patch } /> }
					{ 'advanced' === section && <AdvancedPanel settings={ settings } onChange={ patch } /> }
				</main>
			</div>
		</div>
	);
}
