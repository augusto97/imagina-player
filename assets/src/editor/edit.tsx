import {
	BlockControls,
	InspectorControls,
	MediaPlaceholder,
	MediaReplaceFlow,
	MediaUpload,
	MediaUploadCheck,
	PanelColorSettings,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	BaseControl,
	Button,
	ExternalLink,
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { Preview } from './preview';
import type { EditorData } from './types';

type Attributes = Record< string, string | number | boolean >;

interface EditProps {
	attributes: Attributes;
	setAttributes: ( next: Partial< Attributes > ) => void;
}

const INHERIT = '';

function editorData(): EditorData {
	return (
		window.imaginaPlayerEditor ?? {
			presets: [ { value: 'default', label: __( 'Default', 'imagina-player' ) } ],
			skins: { wave: __( 'Waveform', 'imagina-player' ) },
			overrides: {},
			presetShape: {},
			settingsUrl: '',
		}
	);
}

/**
 * Attribute names for the tri-state visibility toggles, derived from the schema
 * the server sent rather than repeated here.
 */
function visibilityToggles( data: EditorData ): Array< { key: string; attribute: string } > {
	return Object.entries( data.overrides )
		.filter( ( [ key ] ) => key.startsWith( 'show_' ) || 'sticky' === key || 'remember_position' === key )
		.map( ( [ key, attribute ] ) => ( { key, attribute } ) );
}

function humanise( key: string ): string {
	const labels: Record< string, string > = {
		show_artist: __( 'Show artist', 'imagina-player' ),
		show_title: __( 'Show title', 'imagina-player' ),
		show_thumbnail: __( 'Show thumbnail', 'imagina-player' ),
		show_volume: __( 'Show volume', 'imagina-player' ),
		show_time: __( 'Show times', 'imagina-player' ),
		show_download: __( 'Show download button', 'imagina-player' ),
		show_speed: __( 'Show speed control', 'imagina-player' ),
		show_skip: __( 'Show skip buttons', 'imagina-player' ),
		sticky: __( 'Stick to the bottom while playing', 'imagina-player' ),
		remember_position: __( 'Remember playback position', 'imagina-player' ),
	};

	return labels[ key ] ?? key.replace( /_/g, ' ' );
}

export function Edit( { attributes, setAttributes }: EditProps ) {
	const data = editorData();
	const blockProps = useBlockProps( { className: 'imgp-block-editor' } );

	const src = String( attributes.src ?? '' );
	const preset = String( attributes.preset ?? 'default' );
	const thumbnail = String( attributes.thumbnail ?? '' );
	const downloadUrl = String( attributes.downloadUrl ?? '' );

	const inherited = ( attribute: string, presetKey: string ): boolean => {
		const override = attributes[ attribute ];

		if ( INHERIT === override || undefined === override ) {
			return Boolean( data.presetShape[ presetKey ] );
		}

		return 'yes' === override;
	};

	const previewStyle: Record< string, string > = {};

	for ( const [ property, attribute ] of [
		[ '--imgp-accent', 'accent' ],
		[ '--imgp-wave', 'waveColor' ],
		[ '--imgp-wave-progress', 'waveProgress' ],
		[ '--imgp-text', 'textColor' ],
		[ '--imgp-meta', 'metaColor' ],
	] as const ) {
		const value = String( attributes[ attribute ] ?? '' );

		if ( value ) {
			previewStyle[ property ] = value;
		}
	}

	const height = Number( attributes.height ?? 0 );

	if ( height > 0 ) {
		previewStyle[ '--imgp-wave-height' ] = `${ height }px`;
	}

	if ( ! src ) {
		return (
			<div { ...blockProps }>
				<MediaPlaceholder
					icon="format-audio"
					labels={ {
						title: __( 'Imagina Audio Player', 'imagina-player' ),
						instructions: __(
							'Upload an audio file, pick one from your media library, or paste a URL from your streaming provider.',
							'imagina-player'
						),
					} }
					accept="audio/*,video/*"
					allowedTypes={ [ 'audio', 'video' ] }
					onSelect={ ( media: { id?: number; url?: string; title?: string; artist?: string } ) =>
						setAttributes( {
							src: media.url ?? '',
							attachmentId: media.id ?? 0,
							title: attributes.title || media.title || '',
						} )
					}
					onSelectURL={ ( url: string ) => setAttributes( { src: url, attachmentId: 0 } ) }
				/>
			</div>
		);
	}

	return (
		<div { ...blockProps }>
			<BlockControls>
				<MediaReplaceFlow
					mediaId={ Number( attributes.attachmentId ?? 0 ) }
					mediaURL={ src }
					accept="audio/*,video/*"
					allowedTypes={ [ 'audio', 'video' ] }
					onSelect={ ( media: { id?: number; url?: string; title?: string } ) =>
						setAttributes( { src: media.url ?? '', attachmentId: media.id ?? 0 } )
					}
					onSelectURL={ ( url: string ) => setAttributes( { src: url, attachmentId: 0 } ) }
				/>
			</BlockControls>

			<InspectorControls>
				<PanelBody title={ __( 'Track', 'imagina-player' ) }>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Title', 'imagina-player' ) }
						value={ String( attributes.title ?? '' ) }
						onChange={ ( value: string ) => setAttributes( { title: value } ) }
						help={ __( 'Leave empty to use the file’s own title.', 'imagina-player' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Artist', 'imagina-player' ) }
						value={ String( attributes.artist ?? '' ) }
						onChange={ ( value: string ) => setAttributes( { artist: value } ) }
					/>
					<BaseControl
						__nextHasNoMarginBottom
						id="imgp-thumbnail"
						label={ __( 'Cover image', 'imagina-player' ) }
						help={ __( 'Shown next to the title. Optional.', 'imagina-player' ) }
					>
						<div className="imgp-editor__media-picker">
							{ thumbnail && (
								<img
									className="imgp-editor__media-preview"
									src={ thumbnail }
									alt=""
								/>
							) }
							<MediaUploadCheck
								fallback={
									<TextControl
										__nextHasNoMarginBottom
										label={ __( 'Cover image URL', 'imagina-player' ) }
										value={ thumbnail }
										onChange={ ( value: string ) =>
											setAttributes( { thumbnail: value, thumbnailId: 0 } )
										}
									/>
								}
							>
								<MediaUpload
									allowedTypes={ [ 'image' ] }
									value={ Number( attributes.thumbnailId ?? 0 ) }
									onSelect={ ( media: { id?: number; url?: string; sizes?: Record< string, { url: string } > } ) =>
										setAttributes( {
											// Prefer a resized copy: the player shows it at 72px.
											thumbnail: media.sizes?.thumbnail?.url ?? media.url ?? '',
											thumbnailId: media.id ?? 0,
										} )
									}
									render={ ( { open }: { open: () => void } ) => (
										<Button variant="secondary" onClick={ open }>
											{ thumbnail
												? __( 'Replace cover image', 'imagina-player' )
												: __( 'Choose from media library', 'imagina-player' ) }
										</Button>
									) }
								/>
							</MediaUploadCheck>
							{ thumbnail && (
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () => setAttributes( { thumbnail: '', thumbnailId: 0 } ) }
								>
									{ __( 'Remove', 'imagina-player' ) }
								</Button>
							) }
						</div>
					</BaseControl>

					<BaseControl
						__nextHasNoMarginBottom
						id="imgp-download"
						label={ __( 'Download file', 'imagina-player' ) }
						help={ __( 'Optional. Defaults to the audio file itself.', 'imagina-player' ) }
					>
						<div className="imgp-editor__media-picker">
							{ downloadUrl && (
								<code className="imgp-editor__media-path">{ downloadUrl }</code>
							) }
							<MediaUploadCheck
								fallback={
									<TextControl
										__nextHasNoMarginBottom
										label={ __( 'Download URL', 'imagina-player' ) }
										value={ downloadUrl }
										onChange={ ( value: string ) => setAttributes( { downloadUrl: value } ) }
									/>
								}
							>
								<MediaUpload
									allowedTypes={ [ 'audio', 'video', 'application' ] }
									onSelect={ ( media: { url?: string } ) =>
										setAttributes( { downloadUrl: media.url ?? '' } )
									}
									render={ ( { open }: { open: () => void } ) => (
										<Button variant="secondary" onClick={ open }>
											{ downloadUrl
												? __( 'Replace download file', 'imagina-player' )
												: __( 'Choose from media library', 'imagina-player' ) }
										</Button>
									) }
								/>
							</MediaUploadCheck>
							{ downloadUrl && (
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () => setAttributes( { downloadUrl: '' } ) }
								>
									{ __( 'Remove', 'imagina-player' ) }
								</Button>
							) }
						</div>
					</BaseControl>
				</PanelBody>

				<PanelBody title={ __( 'Preset', 'imagina-player' ) }>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Preset', 'imagina-player' ) }
						value={ preset }
						options={ data.presets }
						onChange={ ( value: string ) => setAttributes( { preset: value } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Skin', 'imagina-player' ) }
						value={ String( attributes.skin ?? INHERIT ) }
						options={ [
							{ value: INHERIT, label: __( 'Use preset', 'imagina-player' ) },
							...Object.entries( data.skins ).map( ( [ value, label ] ) => ( { value, label } ) ),
						] }
						onChange={ ( value: string ) => setAttributes( { skin: value } ) }
					/>
					{ data.settingsUrl && (
						<p>
							<ExternalLink href={ data.settingsUrl }>
								{ __( 'Edit presets', 'imagina-player' ) }
							</ExternalLink>
						</p>
					) }
				</PanelBody>

				<PanelBody title={ __( 'Controls', 'imagina-player' ) } initialOpen={ false }>
					{ visibilityToggles( data ).map( ( { key, attribute } ) => (
						<ToggleControl
							__nextHasNoMarginBottom
							key={ attribute }
							label={ humanise( key ) }
							checked={ inherited( attribute, key ) }
							onChange={ ( value: boolean ) => setAttributes( { [ attribute ]: value ? 'yes' : 'no' } ) }
						/>
					) ) }
				</PanelBody>

				<PanelBody title={ __( 'Playback', 'imagina-player' ) } initialOpen={ false }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Autoplay', 'imagina-player' ) }
						checked={ Boolean( attributes.autoplay ) }
						onChange={ ( value: boolean ) => setAttributes( { autoplay: value } ) }
						help={ __( 'Browsers only allow autoplay when the player is muted.', 'imagina-player' ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Loop', 'imagina-player' ) }
						checked={ Boolean( attributes.loop ) }
						onChange={ ( value: boolean ) => setAttributes( { loop: value } ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Start muted', 'imagina-player' ) }
						checked={ Boolean( attributes.muted ) }
						onChange={ ( value: boolean ) => setAttributes( { muted: value } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Preload', 'imagina-player' ) }
						value={ String( attributes.preload ?? INHERIT ) as '' }
						options={ [
							{ value: INHERIT, label: __( 'Use preset', 'imagina-player' ) },
							{ value: 'none', label: __( 'None', 'imagina-player' ) },
							{ value: 'metadata', label: __( 'Metadata', 'imagina-player' ) },
							{ value: 'auto', label: __( 'Auto', 'imagina-player' ) },
						] }
						onChange={ ( value: string ) => setAttributes( { preload: value } ) }
					/>
					<BaseControl __nextHasNoMarginBottom id="imgp-start-time">
						<TextControl
							__nextHasNoMarginBottom
							type="number"
							label={ __( 'Start at (seconds)', 'imagina-player' ) }
							value={ String( attributes.startTime ?? 0 ) }
							onChange={ ( value: string ) => setAttributes( { startTime: Number( value ) || 0 } ) }
						/>
					</BaseControl>
				</PanelBody>

				<PanelColorSettings
					title={ __( 'Colours', 'imagina-player' ) }
					initialOpen={ false }
					enableAlpha={ false }
					// Every colour is shown, not hidden behind a menu: these are the
					// settings people came here to change.
					colorSettings={ (
						[
							[ 'accent', __( 'Accent', 'imagina-player' ) ],
							[ 'waveColor', __( 'Waveform', 'imagina-player' ) ],
							[ 'waveProgress', __( 'Played portion', 'imagina-player' ) ],
							[ 'textColor', __( 'Title', 'imagina-player' ) ],
							[ 'metaColor', __( 'Artist', 'imagina-player' ) ],
						] as const
					).map( ( [ attribute, label ] ) => ( {
						label,
						value: String( attributes[ attribute ] ?? '' ) || undefined,
						// Clearing a swatch means "inherit from the preset" again.
						onChange: ( value?: string ) => setAttributes( { [ attribute ]: value ?? INHERIT } ),
					} ) ) }
				>
					<p className="imgp-editor__hint">
						{ __( 'Leave a colour unset to use the preset’s.', 'imagina-player' ) }
					</p>
				</PanelColorSettings>

				<PanelBody title={ __( 'Size', 'imagina-player' ) } initialOpen={ false }>
					<RangeControl
						__nextHasNoMarginBottom
						label={ __( 'Waveform height', 'imagina-player' ) }
						min={ 24 }
						max={ 240 }
						allowReset
						resetFallbackValue={ undefined }
						value={ Number( attributes.height ) || undefined }
						onChange={ ( value?: number ) =>
							setAttributes( { height: value ? String( value ) : INHERIT } )
						}
						help={ __( 'Unset uses the preset’s height.', 'imagina-player' ) }
					/>
				</PanelBody>

			</InspectorControls>

			<Preview
				title={ String( attributes.title ?? __( 'Untitled track', 'imagina-player' ) ) }
				artist={ String( attributes.artist ?? '' ) }
				thumbnail={ inherited( 'showThumbnail', 'show_thumbnail' ) ? String( attributes.thumbnail ?? '' ) : '' }
				skin={ String( attributes.skin || data.presetShape.skin || 'wave' ) }
				showArtist={ inherited( 'showArtist', 'show_artist' ) && Boolean( attributes.artist ) }
				showTitle={ inherited( 'showTitle', 'show_title' ) }
				showVolume={ inherited( 'showVolume', 'show_volume' ) }
				showTime={ inherited( 'showTime', 'show_time' ) }
				style={ previewStyle }
			/>
		</div>
	);
}
