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
	Notice,
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { Swatches, TristateList } from './controls';
import { Preview } from './preview';
import {
	colourApplies,
	controlApplies,
	identify,
	isVideoSource,
} from '../shared/source';
import { SourceStatus, SourceWarning } from './source-notice';
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

/*
 * Two of the preset's switches are not about which controls appear at all —
 * they are how the player behaves — and they sat in the list of buttons because
 * that is where the loop that builds it happened to put them.
 */
const BEHAVIOUR = [ 'sticky', 'remember_position' ];

/** Which controls a video shows. */
const VIDEO_CONTROLS = [
	[ 'videoBigPlay', __( 'Play button over the picture', 'imagina-player' ) ],
	[ 'videoTitle', __( 'Title on the bar', 'imagina-player' ) ],
	[ 'videoTime', __( 'Elapsed and total time', 'imagina-player' ) ],
	[ 'videoSkip', __( 'Skip back and forward', 'imagina-player' ) ],
	[ 'videoVolume', __( 'Volume', 'imagina-player' ) ],
	[ 'videoSpeed', __( 'Speed control', 'imagina-player' ) ],
	[ 'videoCaptions', __( 'Subtitles button', 'imagina-player' ) ],
	[ 'videoChapters', __( 'Chapters button', 'imagina-player' ) ],
	[ 'videoSearch', __( 'Search what is said', 'imagina-player' ) ],
	[ 'videoPip', __( 'Picture-in-picture button', 'imagina-player' ) ],
	[ 'videoFullscreen', __( 'Fullscreen button', 'imagina-player' ) ],
] as const;

/** How it behaves, which is a different question from what it shows. */
const VIDEO_PLAYBACK = [
	[
		'videoFocusMode',
		__( 'Stop when it leaves the screen', 'imagina-player' ),
	],
] as const;

/** Settings that belong with the subtitles rather than with the buttons. */
const VIDEO_SUBTITLES = [
	[
		'videoCaptionsOn',
		__( 'Subtitles on from the start', 'imagina-player' ),
	],
] as const;

function editorData(): EditorData {
	return (
		window.imaginaPlayerEditor ?? {
			presets: [
				{ value: 'default', label: __( 'Default', 'imagina-player' ) },
			],
			skins: { wave: __( 'Waveform', 'imagina-player' ) },
			videoSkins: { theater: __( 'Theater', 'imagina-player' ) },
			overrides: {},
			presetShape: {},
			settingsUrl: '',
			frontendCss: '',
			frontendJs: '',
			frameCss: '',
			restUrl: '',
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

/*
 * Three answers, not two: a switch would force every block to freeze today's
 * site setting into itself, so changing the site later would leave old posts
 * behind. "Use site setting" is the default and stays live.
 */
const TRISTATE: Array< { value: string; label: string } > = [
	{ value: INHERIT, label: __( 'Use site setting', 'imagina-player' ) },
	{ value: 'yes', label: __( 'Show', 'imagina-player' ) },
	{ value: 'no', label: __( 'Hide', 'imagina-player' ) },
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

	/*
	 * Through the shared recogniser rather than a regular expression on the
	 * extension: a YouTube address has no extension, so the audio block used to
	 * treat one as audio and go looking for a waveform in a web page.
	 */
	const isVideo = isVideoBlock || isVideoSource( src );

	/** The video is somebody else's to serve, which narrows what we can offer. */
	const isProvider = [ 'youtube', 'vimeo' ].includes( identify( src ).kind );

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

	// What the server would show for the fields left empty here.
	const [ resolved, setResolved ] = useState( {
		title: '',
		artist: '',
		thumbnail: '',
	} );

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
									'Upload a video, pick one from your media library, or paste a YouTube or Vimeo address, an MP4, or an HLS stream (.m3u8).',
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
				{ /*
				     The panels answer one question each, in the order somebody
				     actually works: what is playing, how it looks, which
				     controls it has, how it behaves, what sits on top of it.

				     What was here before had grown by accretion: a "Video"
				     panel holding a corner radius, a poster, thirteen
				     dropdowns, a second thing called "Colours" and the
				     subtitle sizes — while a separate "Colours" panel and a
				     separate "Subtitles" panel sat above it. Nothing was
				     missing; it was just impossible to guess where anything
				     was.
				*/ }

				<PanelBody title={ __( 'Media', 'imagina-player' ) }>
					<SourceStatus src={ src } />

					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Title', 'imagina-player' ) }
						value={ String( attributes.title ?? '' ) }
						// The placeholder is what the server would show, not a
						// hint: an empty box beside a filled-in player gives no
						// reason to believe anything is happening.
						placeholder={ resolved.title }
						onChange={ ( value: string ) =>
							setAttributes( { title: value } )
						}
						help={
							resolved.title
								? sprintf(
										/* translators: %s: the title the file itself carries. */
										__(
											'Empty shows “%s”, taken from the file.',
											'imagina-player'
										),
										resolved.title
								  )
								: __(
										'Leave empty to use the file’s own title. This one has none, so set where titles come from under Track details.',
										'imagina-player'
								  )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Artist', 'imagina-player' ) }
						value={ String( attributes.artist ?? '' ) }
						placeholder={ resolved.artist }
						onChange={ ( value: string ) =>
							setAttributes( { artist: value } )
						}
						help={
							resolved.artist
								? sprintf(
										/* translators: %s: the artist the file itself carries. */
										__(
											'Empty shows “%s”, taken from the file.',
											'imagina-player'
										),
										resolved.artist
								  )
								: undefined
						}
					/>

					{ isVideo ? (
						<div className="imgp-editor__group">
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
														media.sizes?.large
															?.url ??
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
						</div>
					) : (
						<div className="imgp-editor__group">
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
														media.sizes?.thumbnail
															?.url ??
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
						</div>
					) }

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

				<PanelBody
					title={ __( 'Appearance', 'imagina-player' ) }
					initialOpen={ false }
				>
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
							...Object.entries(
								isVideo ? data.videoSkins : data.skins
							).map( ( [ value, label ] ) => ( {
								value,
								label,
							} ) ),
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

					<TextControl
						__nextHasNoMarginBottom
						type="number"
						min={ 0 }
						max={ 40 }
						label={ __( 'Rounded corners (px)', 'imagina-player' ) }
						help={ __(
							'Empty uses the preset. Rounds the picture, the bar and the floating card together.',
							'imagina-player'
						) }
						value={ String( attributes.borderRadius ?? '' ) }
						onChange={ ( value: string ) =>
							setAttributes( { borderRadius: value } )
						}
					/>

					{ isVideo && (
						<>
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Shape', 'imagina-player' ) }
								help={ __(
									'The player holds this shape before the video loads, so the page does not jump when it arrives.',
									'imagina-player'
								) }
								value={ String(
									attributes.aspectRatio ?? '16:9'
								) }
								options={ RATIOS }
								onChange={ ( value: string ) =>
									setAttributes( { aspectRatio: value } )
								}
							/>

							<SelectControl
								__nextHasNoMarginBottom
								label={ __(
									'Poster fills the box',
									'imagina-player'
								) }
								help={ __(
									'Crop to fill, or show the whole image and let the black show through.',
									'imagina-player'
								) }
								value={
									String(
										attributes.videoPosterFit ?? INHERIT
									) as ''
								}
								options={ [
									{
										value: INHERIT,
										label: __(
											'Use site setting',
											'imagina-player'
										),
									},
									{
										value: 'cover',
										label: __(
											'Crop to fill',
											'imagina-player'
										),
									},
									{
										value: 'contain',
										label: __(
											'Show all of it',
											'imagina-player'
										),
									},
								] }
								onChange={ ( value: string ) =>
									setAttributes( { videoPosterFit: value } )
								}
							/>
						</>
					) }

					{ ! isVideo && (
						<>
							<RangeControl
								__nextHasNoMarginBottom
								label={ __(
									'Waveform height',
									'imagina-player'
								) }
								min={ 24 }
								max={ 240 }
								allowReset
								resetFallbackValue={ undefined }
								value={
									Number( attributes.height ) || undefined
								}
								onChange={ ( value?: number ) =>
									setAttributes( {
										height: value
											? String( value )
											: INHERIT,
									} )
								}
								help={ __(
									'Unset uses the preset’s height.',
									'imagina-player'
								) }
							/>
						</>
					) }

					<p className="imgp-editor__note">
						{ __(
							'Colours left unset come from the preset, or from the site settings.',
							'imagina-player'
						) }
					</p>

					<Swatches
						items={ (
							[
								[
									'accent',
									__( 'Accent', 'imagina-player' ),
									'#1f2937',
								],
								[
									'waveColor',
									__( 'Waveform', 'imagina-player' ),
									'#c9ced6',
								],
								[
									'waveProgress',
									__( 'Played portion', 'imagina-player' ),
									'#1f2937',
								],
								[
									'videoChromeColor',
									__( 'Control bar', 'imagina-player' ),
									'#000000',
								],
								[
									'videoControlColor',
									__( 'Buttons and times', 'imagina-player' ),
									'#ffffff',
								],
								[
									'videoProgressColor',
									__( 'Played portion', 'imagina-player' ),
									'#1f2937',
								],
								[
									'videoCaptionColor',
									__( 'Subtitles', 'imagina-player' ),
									'#ffffff',
								],
								[
									'controlColor',
									__( 'Buttons', 'imagina-player' ),
									'#374151',
								],
								[
									'textColor',
									__( 'Title', 'imagina-player' ),
									'#111827',
								],
								[
									'metaColor',
									__( 'Artist', 'imagina-player' ),
									'#6b7280',
								],
							] as const
						 ).filter(
							( [ attribute ] ) =>
								/*
								 * One list, filtered by the shared rule, so a
								 * colour cannot appear on a block where it
								 * paints nothing — and cannot be forgotten on
								 * one where it does.
								 */
								colourApplies( attribute, isVideo ) &&
								( isVideo || ! attribute.startsWith( 'video' ) )
						) }
						values={ attributes }
						onChange={ ( attribute: string, value: string ) =>
							setAttributes( { [ attribute ]: value } )
						}
					/>
				</PanelBody>

				<PanelBody
					title={ __( 'Controls', 'imagina-player' ) }
					initialOpen={ false }
				>
					<p className="imgp-editor__note">
						{ __(
							'Which controls this player shows. Left on Site, each one follows the setting for the whole site.',
							'imagina-player'
						) }
					</p>

					{ isVideo ? (
						<TristateList
							items={ VIDEO_CONTROLS }
							values={ attributes }
							onChange={ ( attribute, value ) =>
								setAttributes( { [ attribute ]: value } )
							}
						/>
					) : (
						<TristateList
							items={ visibilityToggles( data )
								.filter(
									( { key } ) =>
										controlApplies( key, isVideo ) &&
										! BEHAVIOUR.includes( key )
								)
								.map(
									( { key, attribute } ) =>
										[ attribute, humanise( key ) ] as const
								) }
							values={ attributes }
							site={ Object.fromEntries(
								visibilityToggles( data ).map(
									( { key, attribute } ) => [
										attribute,
										Boolean( data.presetShape[ key ] ),
									]
								)
							) }
							onChange={ ( attribute, value ) =>
								setAttributes( { [ attribute ]: value } )
							}
						/>
					) }
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

					{ /* The help text under Autoplay has always said browsers
					     need it muted. Saying it is not the same as showing
					     that this block is in exactly that state, which reads
					     as the feature being broken. */ }
					{ Boolean( attributes.autoplay ) &&
						! Boolean( attributes.muted ) && (
							<Notice status="warning" isDismissible={ false }>
								<p>
									{ __(
										'Autoplay is on but the sound is not muted, so no browser will start this by itself. It will show its play button instead.',
										'imagina-player'
									) }
								</p>
								<Button
									variant="secondary"
									onClick={ () =>
										setAttributes( { muted: true } )
									}
								>
									{ __(
										'Start muted as well',
										'imagina-player'
									) }
								</Button>
							</Notice>
						) }
					{ ! isProvider && (
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Preload', 'imagina-player' ) }
							value={
								String( attributes.preload ?? INHERIT ) as ''
							}
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
					) }
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

					{ isVideo && (
						<>
							<TextControl
								__nextHasNoMarginBottom
								type="number"
								min={ 0 }
								step={ 100 }
								label={ __(
									'Hide the controls after (ms)',
									'imagina-player'
								) }
								help={ __(
									'While the video plays and nobody moves. Empty uses the site setting; zero keeps them up.',
									'imagina-player'
								) }
								value={ String(
									attributes.videoHideAfter ?? ''
								) }
								onChange={ ( value: string ) =>
									setAttributes( { videoHideAfter: value } )
								}
							/>
						</>
					) }

					{ /* Sticking to the corner and picking up where the
					     listener left off are behaviour, not controls, and
					     were in the list of buttons. */ }
					<TristateList
						items={ visibilityToggles( data )
							.filter(
								( { key } ) =>
									BEHAVIOUR.includes( key ) &&
									controlApplies( key, isVideo )
							)
							.map(
								( { key, attribute } ) =>
									[ attribute, humanise( key ) ] as const
							) }
						values={ attributes }
						onChange={ ( attribute, value ) =>
							setAttributes( { [ attribute ]: value } )
						}
					/>

					{ isVideo && (
						<TristateList
							items={ VIDEO_PLAYBACK }
							values={ attributes }
							onChange={ ( attribute, value ) =>
								setAttributes( { [ attribute ]: value } )
							}
						/>
					) }
				</PanelBody>

				{ isVideo && ! isProvider && (
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

						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Subtitle size', 'imagina-player' ) }
							value={
								String(
									attributes.videoCaptionSize ?? INHERIT
								) as ''
							}
							options={ [
								{
									value: INHERIT,
									label: __(
										'Use site setting',
										'imagina-player'
									),
								},
								{
									value: 'small',
									label: __( 'Small', 'imagina-player' ),
								},
								{
									value: 'medium',
									label: __( 'Medium', 'imagina-player' ),
								},
								{
									value: 'large',
									label: __( 'Large', 'imagina-player' ),
								},
								{
									value: 'xlarge',
									label: __( 'Very large', 'imagina-player' ),
								},
							] }
							onChange={ ( value: string ) =>
								setAttributes( { videoCaptionSize: value } )
							}
						/>

						<SelectControl
							__nextHasNoMarginBottom
							label={ __(
								'Behind the subtitles',
								'imagina-player'
							) }
							help={ __(
								'A solid band reads over any footage. A shadow is lighter but struggles over a bright, busy shot.',
								'imagina-player'
							) }
							value={
								String(
									attributes.videoCaptionBg ?? INHERIT
								) as ''
							}
							options={ [
								{
									value: INHERIT,
									label: __(
										'Use site setting',
										'imagina-player'
									),
								},
								{
									value: 'solid',
									label: __( 'Solid band', 'imagina-player' ),
								},
								{
									value: 'shadow',
									label: __(
										'Shadow only',
										'imagina-player'
									),
								},
								{
									value: 'none',
									label: __( 'Nothing', 'imagina-player' ),
								},
							] }
							onChange={ ( value: string ) =>
								setAttributes( { videoCaptionBg: value } )
							}
						/>

						<TristateList
							items={ VIDEO_SUBTITLES }
							values={ attributes }
							onChange={ ( attribute, value ) =>
								setAttributes( { [ attribute ]: value } )
							}
						/>
					</PanelBody>
				) }

				{ isVideo && (
					<PanelBody
						title={ __(
							'Chapters and previews',
							'imagina-player'
						) }
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

						<BaseControl
							__nextHasNoMarginBottom
							id="imgp-storyboard"
							label={ __( 'Scrub previews', 'imagina-player' ) }
							help={ __(
								'A WebVTT storyboard, which most video tools can export. It shows a still where the pointer is on the seek bar. Nothing is downloaded until a visitor actually drags it.',
								'imagina-player'
							) }
						>
							<div className="imgp-editor__media-picker">
								<MediaUploadCheck>
									<MediaUpload
										allowedTypes={ [ 'text' ] }
										value={ Number(
											attributes.storyboardId ?? 0
										) }
										onSelect={ ( media: {
											id?: number;
											url?: string;
										} ) =>
											setAttributes( {
												storyboard: media.url ?? '',
												storyboardId: media.id ?? 0,
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
												{ attributes.storyboard
													? __(
															'Replace',
															'imagina-player'
													  )
													: __(
															'Choose a storyboard file',
															'imagina-player'
													  ) }
											</Button>
										) }
									/>
								</MediaUploadCheck>
								{ Boolean( attributes.storyboard ) && (
									<Button
										variant="tertiary"
										isDestructive
										onClick={ () =>
											setAttributes( {
												storyboard: '',
												storyboardId: 0,
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
									/*
									 * Changing the kind changes when it makes
									 * sense to appear. A bar is a standing
									 * offer and belongs from the start; the
									 * other two interrupt, and belong at the
									 * end. Leaving a bar on the old default
									 * meant it only ever appeared once the
									 * video had finished, which reads as the
									 * feature not working.
									 */
									patchLayer( index, {
										type: value,
										at:
											'bar' === value
												? 0
												: Number( layer.at ?? 100 ),
									} )
								}
							/>
							<RangeControl
								__nextHasNoMarginBottom
								label={ __( 'Appears at', 'imagina-player' ) }
								help={
									0 === Number( layer.at ?? 100 )
										? __(
												'From the start, before anything is played.',
												'imagina-player'
										  )
										: __(
												'Percentage of the track. 100 means when it ends.',
												'imagina-player'
										  )
								}
								value={ Number( layer.at ?? 100 ) }
								min={ 0 }
								max={ 100 }
								step={ 5 }
								onChange={ ( value?: number ) =>
									patchLayer( index, { at: value ?? 100 } )
								}
							/>
							<RangeControl
								__nextHasNoMarginBottom
								label={ __( 'Goes away at', 'imagina-player' ) }
								help={
									0 === Number( layer.until ?? 0 )
										? __(
												'Zero: it stays once it has appeared.',
												'imagina-player'
										  )
										: __(
												'Percentage of the track. Rewinding past it brings it back.',
												'imagina-player'
										  )
								}
								value={ Number( layer.until ?? 0 ) }
								min={ 0 }
								max={ 100 }
								step={ 5 }
								onChange={ ( value?: number ) =>
									patchLayer( index, { until: value ?? 0 } )
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
										until: 0,
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

				{ isVideo && ! isProvider && (
					<PanelBody
						title={ __( 'Advanced', 'imagina-player' ) }
						initialOpen={ false }
					>
						{ /* Not offered for a provider: the file is not on this
						     site, so there is nothing here to withhold. */ }
						{ ! isProvider && (
							<SelectControl
								__nextHasNoMarginBottom
								label={ __(
									'Block the browser download',
									'imagina-player'
								) }
								help={ __(
									'Also removes “Save video as” and casting the raw file. It has no effect on a player that deliberately offers a download.',
									'imagina-player'
								) }
								value={ String(
									attributes.videoBlockDownload ?? INHERIT
								) }
								options={ TRISTATE }
								onChange={ ( value: string ) =>
									setAttributes( {
										videoBlockDownload: value,
									} )
								}
							/>
						) }
					</PanelBody>
				) }
			</InspectorControls>

			<SourceWarning src={ src } isVideoBlock={ isVideoBlock } />

			<WaveformNotice
				attachmentIds={ [ Number( attributes.attachmentId ?? 0 ) ] }
				urls={ Number( attributes.attachmentId ?? 0 ) ? [] : [ src ] }
				disabled={ isVideo }
				onMeasured={ () => setRefresh( ( n ) => n + 1 ) }
			/>

			<Preview
				onResolved={ setResolved }
				refresh={ refresh }
				attributes={ attributes }
				assets={ {
					frontendCss: data.frontendCss,
					frontendJs: data.frontendJs,
					frameCss: data.frameCss,
					restUrl: data.restUrl,
				} }
			/>
		</div>
	);
}
