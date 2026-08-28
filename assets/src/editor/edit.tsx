import {
	BlockControls,
	InspectorControls,
	MediaPlaceholder,
	MediaReplaceFlow,
	MediaUpload,
	MediaUploadCheck,
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
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Preview } from './preview';
import { WaveformNotice } from './waveform-notice';
import type { EditorData } from './types';

/** A subtitle track or a chapter, as the block stores it. */
type ListItem = Record< string, string | number | boolean >;

type Attributes = Record<
	string,
	string | number | boolean | ListItem[] | undefined
>;

interface EditProps {
	attributes: Attributes;
	setAttributes: ( next: Partial< Attributes > ) => void;
	/** Which block this is; the video one is video whatever the file says. */
	name?: string;
}

const INHERIT = '';

const HEX = /^#[0-9a-fA-F]{6}$/;

function editorData(): EditorData {
	return (
		window.imaginaPlayerEditor ?? {
			presets: [
				{ value: 'default', label: __( 'Default', 'imagina-player' ) },
			],
			skins: { wave: __( 'Waveform', 'imagina-player' ) },
			overrides: {},
			presetShape: {},
			settingsUrl: '',
			frontendCss: '',
			frontendJs: '',
			frameCss: '',
		}
	);
}

/**
 * Attribute names for the tri-state visibility toggles, derived from the schema
 * the server sent rather than repeated here.
 * @param data
 */
function visibilityToggles(
	data: EditorData
): Array< { key: string; attribute: string } > {
	return Object.entries( data.overrides )
		.filter(
			( [ key ] ) =>
				key.startsWith( 'show_' ) ||
				'sticky' === key ||
				'remember_position' === key
		)
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

/**
 * Shapes offered for a video. Declared as data, and typed as plain strings, so
 * the control accepts whatever was saved rather than only these four.
 */
const LAYER_TYPES: Array< { value: string; label: string } > = [
	{
		value: 'cta',
		label: __( 'Call to action (stops playback)', 'imagina-player' ),
	},
	{
		value: 'bar',
		label: __( 'Bar (does not stop playback)', 'imagina-player' ),
	},
	{ value: 'email', label: __( 'Email gate', 'imagina-player' ) },
];

const RATIOS: Array< { value: string; label: string } > = [
	{ value: '16:9', label: __( 'Widescreen (16:9)', 'imagina-player' ) },
	{ value: '4:3', label: __( 'Classic (4:3)', 'imagina-player' ) },
	{ value: '1:1', label: __( 'Square (1:1)', 'imagina-player' ) },
	{ value: '9:16', label: __( 'Vertical (9:16)', 'imagina-player' ) },
];

export function Edit( { attributes, setAttributes, name }: EditProps ) {
	const data = editorData();
	const blockProps = useBlockProps( { className: 'imgp-block-editor' } );

	const src = String( attributes.src ?? '' );
	const preset = String( attributes.preset ?? 'default' );
	const thumbnail = String( attributes.thumbnail ?? '' );
	const downloadUrl = String( attributes.downloadUrl ?? '' );
	const poster = String( attributes.poster ?? '' );

	// The block decides first. Falling back to the extension is what the audio
	// block needs — it has always accepted a video file — but it is a guess,
	// and a guess is why these panels used to appear only sometimes.
	const isVideoBlock = 'imagina/video-player' === name;

	const isVideo =
		isVideoBlock || /\.(mp4|m4v|webm|ogv|mov|m3u8)(\?|#|$)/i.test( src );

	const tracks = ( attributes.tracks ?? [] ) as ListItem[];
	const chapters = ( attributes.chapters ?? [] ) as ListItem[];

	const patchTrack = ( index: number, patch: ListItem ): void =>
		setAttributes( {
			tracks: tracks.map( ( item, i ) =>
				i === index ? { ...item, ...patch } : item
			),
		} );

	const layers = ( attributes.layers ?? [] ) as ListItem[];

	/*
	 * Assumed present until the preview reports otherwise, so the notice does
	 * not flash up on every keystroke while the preview is in flight.
	 */
	// Bumped after a waveform is measured, so the preview goes and fetches the
	// one that now exists. Measuring stores it against the file, not against
	// the block, so nothing in the attributes changes to trigger it.
	const [ refresh, setRefresh ] = useState( 0 );

	const patchLayer = ( index: number, patch: ListItem ): void =>
		setAttributes( {
			layers: layers.map( ( item, i ) =>
				i === index ? { ...item, ...patch } : item
			),
		} );

	const patchChapter = ( index: number, patch: ListItem ): void =>
		setAttributes( {
			chapters: chapters.map( ( item, i ) =>
				i === index ? { ...item, ...patch } : item
			),
		} );

	const inherited = ( attribute: string, presetKey: string ): boolean => {
		const override = attributes[ attribute ];

		if ( INHERIT === override || undefined === override ) {
			return Boolean( data.presetShape[ presetKey ] );
		}

		return 'yes' === override;
	};

	if ( ! src ) {
		return (
			<div { ...blockProps }>
				<MediaPlaceholder
					icon={ isVideoBlock ? 'format-video' : 'format-audio' }
					labels={ {
						title: isVideoBlock
							? __( 'Imagina Video Player', 'imagina-player' )
							: __( 'Imagina Audio Player', 'imagina-player' ),
						instructions: isVideoBlock
							? __(
									'Upload a video, pick one from your media library, or paste the address of an MP4 or an HLS stream (.m3u8).',
									'imagina-player'
							  )
							: __(
									'Upload an audio file, pick one from your media library, or paste a URL from your streaming provider.',
									'imagina-player'
							  ),
					} }
					accept={ isVideoBlock ? 'video/*' : 'audio/*,video/*' }
					allowedTypes={
						isVideoBlock ? [ 'video' ] : [ 'audio', 'video' ]
					}
					onSelect={ ( media: {
						id?: number;
						url?: string;
						title?: string;
						artist?: string;
					} ) =>
						setAttributes( {
							src: media.url ?? '',
							attachmentId: media.id ?? 0,
							title: attributes.title || media.title || '',
						} )
					}
					onSelectURL={ ( url: string ) =>
						setAttributes( { src: url, attachmentId: 0 } )
					}
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
					onSelect={ ( media: {
						id?: number;
						url?: string;
						title?: string;
					} ) =>
						setAttributes( {
							src: media.url ?? '',
							attachmentId: media.id ?? 0,
						} )
					}
					onSelectURL={ ( url: string ) =>
						setAttributes( { src: url, attachmentId: 0 } )
					}
				/>
			</BlockControls>

			<InspectorControls>
				<PanelBody title={ __( 'Track', 'imagina-player' ) }>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Title', 'imagina-player' ) }
						value={ String( attributes.title ?? '' ) }
						onChange={ ( value: string ) =>
							setAttributes( { title: value } )
						}
						help={ __(
							'Leave empty to use the file’s own title.',
							'imagina-player'
						) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Artist', 'imagina-player' ) }
						value={ String( attributes.artist ?? '' ) }
						onChange={ ( value: string ) =>
							setAttributes( { artist: value } )
						}
					/>
					<BaseControl
						__nextHasNoMarginBottom
						id="imgp-thumbnail"
						label={ __( 'Cover image', 'imagina-player' ) }
						help={ __(
							'Shown next to the title. Optional.',
							'imagina-player'
						) }
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
										label={ __(
											'Cover image URL',
											'imagina-player'
										) }
										value={ thumbnail }
										onChange={ ( value: string ) =>
											setAttributes( {
												thumbnail: value,
												thumbnailId: 0,
											} )
										}
									/>
								}
							>
								<MediaUpload
									allowedTypes={ [ 'image' ] }
									value={ Number(
										attributes.thumbnailId ?? 0
									) }
									onSelect={ ( media: {
										id?: number;
										url?: string;
										sizes?: Record<
											string,
											{ url: string }
										>;
									} ) =>
										setAttributes( {
											// Prefer a resized copy: the player shows it at 72px.
											thumbnail:
												media.sizes?.thumbnail?.url ??
												media.url ??
												'',
											thumbnailId: media.id ?? 0,
										} )
									}
									render={ ( {
										open,
									}: {
										open: () => void;
									} ) => (
										<Button
											variant="secondary"
											onClick={ open }
										>
											{ thumbnail
												? __(
														'Replace cover image',
														'imagina-player'
												  )
												: __(
														'Choose from media library',
														'imagina-player'
												  ) }
										</Button>
									) }
								/>
							</MediaUploadCheck>
							{ thumbnail && (
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () =>
										setAttributes( {
											thumbnail: '',
											thumbnailId: 0,
										} )
									}
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
						help={ __(
							'Optional. Defaults to the audio file itself.',
							'imagina-player'
						) }
					>
						<div className="imgp-editor__media-picker">
							{ downloadUrl && (
								<code className="imgp-editor__media-path">
									{ downloadUrl }
								</code>
							) }
							<MediaUploadCheck
								fallback={
									<TextControl
										__nextHasNoMarginBottom
										label={ __(
											'Download URL',
											'imagina-player'
										) }
										value={ downloadUrl }
										onChange={ ( value: string ) =>
											setAttributes( {
												downloadUrl: value,
											} )
										}
									/>
								}
							>
								<MediaUpload
									allowedTypes={ [
										'audio',
										'video',
										'application',
									] }
									onSelect={ ( media: { url?: string } ) =>
										setAttributes( {
											downloadUrl: media.url ?? '',
										} )
									}
									render={ ( {
										open,
									}: {
										open: () => void;
									} ) => (
										<Button
											variant="secondary"
											onClick={ open }
										>
											{ downloadUrl
												? __(
														'Replace download file',
														'imagina-player'
												  )
												: __(
														'Choose from media library',
														'imagina-player'
												  ) }
										</Button>
									) }
								/>
							</MediaUploadCheck>
							{ downloadUrl && (
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () =>
										setAttributes( { downloadUrl: '' } )
									}
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
						onChange={ ( value: string ) =>
							setAttributes( { preset: value } )
						}
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Skin', 'imagina-player' ) }
						value={ String( attributes.skin ?? INHERIT ) }
						options={ [
							{
								value: INHERIT,
								label: __( 'Use preset', 'imagina-player' ),
							},
							...Object.entries( data.skins ).map(
								( [ value, label ] ) => ( { value, label } )
							),
						] }
						onChange={ ( value: string ) =>
							setAttributes( { skin: value } )
						}
					/>
					{ data.settingsUrl && (
						<p>
							<ExternalLink href={ data.settingsUrl }>
								{ __( 'Edit presets', 'imagina-player' ) }
							</ExternalLink>
						</p>
					) }
				</PanelBody>

				<PanelBody
					title={ __( 'Controls', 'imagina-player' ) }
					initialOpen={ false }
				>
					{ visibilityToggles( data ).map( ( { key, attribute } ) => (
						<ToggleControl
							__nextHasNoMarginBottom
							key={ attribute }
							label={ humanise( key ) }
							checked={ inherited( attribute, key ) }
							onChange={ ( value: boolean ) =>
								setAttributes( {
									[ attribute ]: value ? 'yes' : 'no',
								} )
							}
						/>
					) ) }
				</PanelBody>

				<PanelBody
					title={ __( 'Playback', 'imagina-player' ) }
					initialOpen={ false }
				>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Autoplay', 'imagina-player' ) }
						checked={ Boolean( attributes.autoplay ) }
						onChange={ ( value: boolean ) =>
							setAttributes( { autoplay: value } )
						}
						help={ __(
							'Browsers only allow autoplay when the player is muted.',
							'imagina-player'
						) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Loop', 'imagina-player' ) }
						checked={ Boolean( attributes.loop ) }
						onChange={ ( value: boolean ) =>
							setAttributes( { loop: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Start muted', 'imagina-player' ) }
						checked={ Boolean( attributes.muted ) }
						onChange={ ( value: boolean ) =>
							setAttributes( { muted: value } )
						}
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Preload', 'imagina-player' ) }
						value={ String( attributes.preload ?? INHERIT ) as '' }
						options={ [
							{
								value: INHERIT,
								label: __( 'Use preset', 'imagina-player' ),
							},
							{
								value: 'none',
								label: __( 'None', 'imagina-player' ),
							},
							{
								value: 'metadata',
								label: __( 'Metadata', 'imagina-player' ),
							},
							{
								value: 'auto',
								label: __( 'Auto', 'imagina-player' ),
							},
						] }
						onChange={ ( value: string ) =>
							setAttributes( { preload: value } )
						}
					/>
					<BaseControl __nextHasNoMarginBottom id="imgp-start-time">
						<TextControl
							__nextHasNoMarginBottom
							type="number"
							label={ __(
								'Start at (seconds)',
								'imagina-player'
							) }
							value={ String( attributes.startTime ?? 0 ) }
							onChange={ ( value: string ) =>
								setAttributes( {
									startTime: Number( value ) || 0,
								} )
							}
						/>
					</BaseControl>
				</PanelBody>

				<PanelBody
					title={ __( 'Colours', 'imagina-player' ) }
					initialOpen={ false }
				>
					<p className="imgp-editor__hint">
						{ __(
							'Leave a colour unset to use the preset’s.',
							'imagina-player'
						) }
					</p>

					{ (
						[
							[ 'accent', __( 'Accent', 'imagina-player' ) ],
							[ 'waveColor', __( 'Waveform', 'imagina-player' ) ],
							[
								'waveProgress',
								__( 'Played portion', 'imagina-player' ),
							],
							[ 'textColor', __( 'Title', 'imagina-player' ) ],
							[ 'metaColor', __( 'Artist', 'imagina-player' ) ],
						] as const
					 ).map( ( [ attribute, label ] ) => {
						const value = String( attributes[ attribute ] ?? '' );

						return (
							<BaseControl
								__nextHasNoMarginBottom
								key={ attribute }
								id={ `imgp-colour-${ attribute }` }
								label={ label }
							>
								<div className="imgp-editor__colour">
									<input
										type="color"
										id={ `imgp-colour-${ attribute }` }
										// A swatch cannot show "unset"; it falls back to a
										// neutral while the text field carries the real state.
										value={
											HEX.test( value )
												? value
												: '#cccccc'
										}
										onChange={ ( event ) =>
											setAttributes( {
												[ attribute ]:
													event.target.value,
											} )
										}
									/>
									<input
										type="text"
										className="imgp-editor__colour-text"
										value={ value }
										placeholder={ __(
											'From preset',
											'imagina-player'
										) }
										spellCheck={ false }
										onChange={ ( event ) =>
											setAttributes( {
												[ attribute ]:
													event.target.value,
											} )
										}
									/>
									{ value && (
										<Button
											variant="tertiary"
											size="small"
											onClick={ () =>
												setAttributes( {
													[ attribute ]: INHERIT,
												} )
											}
										>
											{ __( 'Reset', 'imagina-player' ) }
										</Button>
									) }
								</div>
							</BaseControl>
						);
					} ) }
				</PanelBody>

				{ ! isVideo && (
					<PanelBody
						title={ __( 'Size', 'imagina-player' ) }
						initialOpen={ false }
					>
						<RangeControl
							__nextHasNoMarginBottom
							label={ __( 'Waveform height', 'imagina-player' ) }
							min={ 24 }
							max={ 240 }
							allowReset
							resetFallbackValue={ undefined }
							value={ Number( attributes.height ) || undefined }
							onChange={ ( value?: number ) =>
								setAttributes( {
									height: value ? String( value ) : INHERIT,
								} )
							}
							help={ __(
								'Unset uses the preset’s height.',
								'imagina-player'
							) }
						/>
					</PanelBody>
				) }

				{ isVideo && (
					<PanelBody
						title={ __( 'Subtitles', 'imagina-player' ) }
						initialOpen={ false }
					>
						<p className="imgp-editor__hint">
							{ __(
								'WebVTT or SubRip (.vtt or .srt). SubRip files are converted for the browser automatically.',
								'imagina-player'
							) }
						</p>

						{ tracks.map( ( track, index ) => (
							<div className="imgp-editor__row" key={ index }>
								<TextControl
									__nextHasNoMarginBottom
									label={ __(
										'Language name',
										'imagina-player'
									) }
									value={ String( track.label ?? '' ) }
									placeholder={ __(
										'Español',
										'imagina-player'
									) }
									onChange={ ( value: string ) =>
										patchTrack( index, { label: value } )
									}
								/>
								<TextControl
									__nextHasNoMarginBottom
									label={ __(
										'Language code',
										'imagina-player'
									) }
									value={ String( track.srclang ?? '' ) }
									placeholder="es"
									help={ __(
										'Two letters, as in es, en, pt-br.',
										'imagina-player'
									) }
									onChange={ ( value: string ) =>
										patchTrack( index, { srclang: value } )
									}
								/>
								<ToggleControl
									__nextHasNoMarginBottom
									label={ __(
										'Show by default',
										'imagina-player'
									) }
									checked={ Boolean( track.default ) }
									onChange={ ( value: boolean ) =>
										setAttributes( {
											tracks: tracks.map(
												( item, i ) => ( {
													...item,
													// Only one can be the default; a
													// browser has no answer for two.
													default:
														value && i === index,
												} )
											),
										} )
									}
								/>
								<div className="imgp-editor__media-picker">
									{ track.src && (
										<code className="imgp-editor__media-path">
											{ String( track.src )
												.split( '/' )
												.pop() }
										</code>
									) }
									<MediaUploadCheck>
										<MediaUpload
											allowedTypes={ [ 'text' ] }
											onSelect={ ( media: {
												url?: string;
											} ) =>
												patchTrack( index, {
													src: media.url ?? '',
												} )
											}
											render={ ( {
												open,
											}: {
												open: () => void;
											} ) => (
												<Button
													variant="secondary"
													onClick={ open }
												>
													{ track.src
														? __(
																'Replace file',
																'imagina-player'
														  )
														: __(
																'Choose file',
																'imagina-player'
														  ) }
												</Button>
											) }
										/>
									</MediaUploadCheck>
									<Button
										variant="tertiary"
										isDestructive
										onClick={ () =>
											setAttributes( {
												tracks: tracks.filter(
													( _item, i ) => i !== index
												),
											} )
										}
									>
										{ __( 'Remove', 'imagina-player' ) }
									</Button>
								</div>
							</div>
						) ) }

						<Button
							variant="secondary"
							onClick={ () =>
								setAttributes( {
									tracks: [
										...tracks,
										{
											src: '',
											srclang: '',
											label: '',
											kind: 'subtitles',
											default: false,
										},
									],
								} )
							}
						>
							{ __( 'Add subtitles', 'imagina-player' ) }
						</Button>
					</PanelBody>
				) }

				{ isVideo && (
					<PanelBody
						title={ __( 'Chapters', 'imagina-player' ) }
						initialOpen={ false }
					>
						<p className="imgp-editor__hint">
							{ __(
								'Marks on the progress bar, and a menu to jump between sections. Times can be written as 90, 1:30 or 0:01:30.',
								'imagina-player'
							) }
						</p>

						{ chapters.map( ( chapter, index ) => (
							<div className="imgp-editor__row" key={ index }>
								<TextControl
									__nextHasNoMarginBottom
									label={ __(
										'Starts at',
										'imagina-player'
									) }
									value={ String( chapter.start ?? '' ) }
									placeholder="1:30"
									onChange={ ( value: string ) =>
										patchChapter( index, { start: value } )
									}
								/>
								<TextControl
									__nextHasNoMarginBottom
									label={ __( 'Title', 'imagina-player' ) }
									value={ String( chapter.title ?? '' ) }
									onChange={ ( value: string ) =>
										patchChapter( index, { title: value } )
									}
								/>
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () =>
										setAttributes( {
											chapters: chapters.filter(
												( _item, i ) => i !== index
											),
										} )
									}
								>
									{ __( 'Remove', 'imagina-player' ) }
								</Button>
							</div>
						) ) }

						<Button
							variant="secondary"
							onClick={ () =>
								setAttributes( {
									chapters: [
										...chapters,
										{ start: '', title: '' },
									],
								} )
							}
						>
							{ __( 'Add chapter', 'imagina-player' ) }
						</Button>
					</PanelBody>
				) }

				{ isVideo && (
					<PanelBody title={ __( 'Video', 'imagina-player' ) }>
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Shape', 'imagina-player' ) }
							help={ __(
								'The player holds this shape before the video loads, so the page does not jump when it arrives.',
								'imagina-player'
							) }
							value={ String( attributes.aspectRatio ?? '16:9' ) }
							options={ RATIOS }
							onChange={ ( value: string ) =>
								setAttributes( { aspectRatio: value } )
							}
						/>

						<BaseControl
							__nextHasNoMarginBottom
							id="imgp-poster"
							label={ __( 'Poster', 'imagina-player' ) }
							help={ __(
								'Shown before play. Often the largest image on the page, so pick one that is already the right size.',
								'imagina-player'
							) }
						>
							<div className="imgp-editor__media-picker">
								{ poster && (
									<img
										className="imgp-editor__thumb"
										src={ poster }
										alt=""
									/>
								) }
								<MediaUploadCheck>
									<MediaUpload
										allowedTypes={ [ 'image' ] }
										value={ Number(
											attributes.posterId ?? 0
										) }
										onSelect={ ( media: {
											id?: number;
											url?: string;
											sizes?: Record<
												string,
												{ url?: string }
											>;
										} ) =>
											setAttributes( {
												poster:
													media.sizes?.large?.url ??
													media.url ??
													'',
												posterId: media.id ?? 0,
											} )
										}
										render={ ( {
											open,
										}: {
											open: () => void;
										} ) => (
											<Button
												variant="secondary"
												onClick={ open }
											>
												{ poster
													? __(
															'Replace',
															'imagina-player'
													  )
													: __(
															'Choose from media library',
															'imagina-player'
													  ) }
											</Button>
										) }
									/>
								</MediaUploadCheck>
								{ poster && (
									<Button
										variant="tertiary"
										isDestructive
										onClick={ () =>
											setAttributes( {
												poster: '',
												posterId: 0,
											} )
										}
									>
										{ __( 'Remove', 'imagina-player' ) }
									</Button>
								) }
							</div>
						</BaseControl>
					</PanelBody>
				) }

				<PanelBody
					title={ __( 'Calls to action', 'imagina-player' ) }
					initialOpen={ false }
				>
					<p className="imgp-editor__hint">
						{ __(
							'Appears part-way through. A bar sits alongside playback; the other two stop it until the listener answers or closes them.',
							'imagina-player'
						) }
					</p>

					{ layers.map( ( layer, index ) => (
						<div className="imgp-editor__row" key={ index }>
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Kind', 'imagina-player' ) }
								value={ String( layer.type ?? 'cta' ) }
								options={ LAYER_TYPES }
								onChange={ ( value: string ) =>
									patchLayer( index, { type: value } )
								}
							/>
							<RangeControl
								__nextHasNoMarginBottom
								label={ __( 'Appears at', 'imagina-player' ) }
								help={ __(
									'Percentage of the track. 100 means when it ends.',
									'imagina-player'
								) }
								value={ Number( layer.at ?? 100 ) }
								min={ 0 }
								max={ 100 }
								step={ 5 }
								onChange={ ( value?: number ) =>
									patchLayer( index, { at: value ?? 100 } )
								}
							/>
							<TextControl
								__nextHasNoMarginBottom
								label={ __( 'Headline', 'imagina-player' ) }
								value={ String( layer.title ?? '' ) }
								onChange={ ( value: string ) =>
									patchLayer( index, { title: value } )
								}
							/>
							<TextControl
								__nextHasNoMarginBottom
								label={ __( 'Text', 'imagina-player' ) }
								value={ String( layer.text ?? '' ) }
								onChange={ ( value: string ) =>
									patchLayer( index, { text: value } )
								}
							/>
							<TextControl
								__nextHasNoMarginBottom
								label={ __( 'Button label', 'imagina-player' ) }
								value={ String( layer.button ?? '' ) }
								onChange={ ( value: string ) =>
									patchLayer( index, { button: value } )
								}
							/>

							{ 'email' === layer.type ? (
								<>
									<TextControl
										__nextHasNoMarginBottom
										label={ __(
											'List name',
											'imagina-player'
										) }
										help={ __(
											'Groups the addresses this player captures. Anything you like: "course", "newsletter".',
											'imagina-player'
										) }
										value={ String( layer.list ?? '' ) }
										onChange={ ( value: string ) =>
											patchLayer( index, { list: value } )
										}
									/>
									<TextControl
										__nextHasNoMarginBottom
										label={ __(
											'Small print',
											'imagina-player'
										) }
										value={ String( layer.consent ?? '' ) }
										onChange={ ( value: string ) =>
											patchLayer( index, {
												consent: value,
											} )
										}
									/>
								</>
							) : (
								<>
									<TextControl
										__nextHasNoMarginBottom
										label={ __(
											'Button links to',
											'imagina-player'
										) }
										value={ String( layer.url ?? '' ) }
										placeholder="https://…"
										onChange={ ( value: string ) =>
											patchLayer( index, { url: value } )
										}
									/>
									<ToggleControl
										__nextHasNoMarginBottom
										label={ __(
											'Open in a new tab',
											'imagina-player'
										) }
										checked={ Boolean( layer.newTab ) }
										onChange={ ( value: boolean ) =>
											patchLayer( index, {
												newTab: value,
											} )
										}
									/>
								</>
							) }

							<ToggleControl
								__nextHasNoMarginBottom
								label={ __(
									'Can be closed',
									'imagina-player'
								) }
								help={ __(
									'Without this, an email gate has to be answered to carry on.',
									'imagina-player'
								) }
								checked={ Boolean( layer.skip ) }
								onChange={ ( value: boolean ) =>
									patchLayer( index, { skip: value } )
								}
							/>

							<Button
								variant="tertiary"
								isDestructive
								onClick={ () =>
									setAttributes( {
										layers: layers.filter(
											( _item, i ) => i !== index
										),
									} )
								}
							>
								{ __( 'Remove', 'imagina-player' ) }
							</Button>
						</div>
					) ) }

					<Button
						variant="secondary"
						onClick={ () =>
							setAttributes( {
								layers: [
									...layers,
									{
										type: 'cta',
										at: 100,
										title: '',
										text: '',
										button: '',
										url: '',
										skip: true,
										newTab: true,
									},
								],
							} )
						}
					>
						{ __( 'Add a call to action', 'imagina-player' ) }
					</Button>
				</PanelBody>
			</InspectorControls>

			<WaveformNotice
				attachmentIds={ [ Number( attributes.attachmentId ?? 0 ) ] }
				disabled={ isVideo }
				onMeasured={ () => setRefresh( ( n ) => n + 1 ) }
			/>

			<Preview
				refresh={ refresh }
				attributes={ attributes }
				assets={ {
					frontendCss: data.frontendCss,
					frontendJs: data.frontendJs,
					frameCss: data.frameCss,
				} }
			/>
		</div>
	);
}
