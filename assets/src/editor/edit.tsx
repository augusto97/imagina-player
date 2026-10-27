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
import apiFetch from '@wordpress/api-fetch';
import { useSelect } from '@wordpress/data';

import { AccessPanel } from './access-panel';
import { Swatches, Tristate, TristateList } from './controls';
import { DynamicSourcePanel } from './dynamic-source';
import { Preview } from './preview';
import {
	colourApplies,
	controlApplies,
	identify,
	isVideoSource,
} from '../shared/source';
import { SourceStatus, SourceWarning } from './source-notice';
import { SourcesPanel } from './sources-panel';
import { WaveformNotice } from './waveform-notice';
import type { EditorData } from './types';
import { layerGap } from './layer-rules';
import type { LayerGap } from './layer-rules';

/** A subtitle track or a chapter, as the block stores it. */
type ListItem = Record< string, string | number | boolean >;

type Attributes = Record<
	string,
	string | number | boolean | ListItem[] | undefined
>;

interface EditProps {
	attributes: Attributes;
	setAttributes: ( next: Record< string, unknown > ) => void;
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
	[ 'videoTitle', __( 'Title and artist', 'imagina-player' ) ],
	[ 'videoTime', __( 'Elapsed and total time', 'imagina-player' ) ],
	[ 'videoSkip', __( 'Skip back and forward', 'imagina-player' ) ],
	[ 'videoVolume', __( 'Volume', 'imagina-player' ) ],
	[ 'videoSpeed', __( 'Speed control', 'imagina-player' ) ],
	[ 'videoCaptions', __( 'Subtitles button', 'imagina-player' ) ],
	[ 'videoChapters', __( 'Chapters button', 'imagina-player' ) ],
	[ 'videoSearch', __( 'Search what is said', 'imagina-player' ) ],
	[
		'videoTranscript',
		__( 'Transcript under the picture', 'imagina-player' ),
	],
	[ 'videoPip', __( 'Picture-in-picture button', 'imagina-player' ) ],
	[ 'videoFullscreen', __( 'Fullscreen button', 'imagina-player' ) ],
] as const;

/** How it behaves, which is a different question from what it shows. */
const VIDEO_PLAYBACK = [
	[
		'videoFocusMode',
		__( 'Stop when it leaves the screen', 'imagina-player' ),
	],
	[
		'videoProviderBare',
		__( 'Hide the provider’s own interface', 'imagina-player' ),
	],
] as const;

/*
 * Controls that do nothing for a video YouTube or Vimeo serves: search and
 * the transcript read subtitle files this site holds, and picture-in-picture
 * needs a video element of our own. Offering them there was a switch that
 * did nothing.
 */
const NOT_FOR_PROVIDERS = [ 'videoSearch', 'videoTranscript', 'videoPip' ];

/** And the other way round: only a provider has an interface to hide. */
const ONLY_FOR_PROVIDERS = [ 'videoProviderBare' ];

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
			assetUrl: '',
			frontendChunks: [],
		}
	);
}

/**
 * What the block's own preset says, falling back to the default preset and
 * then to the factory's, so "Site: on" means what this block will do.
 * @param data
 * @param preset
 */
function presetSays(
	data: EditorData,
	preset: string
): Record< string, string | number | boolean > {
	return (
		data.presetValues?.[ preset ] ??
		data.presetValues?.default ??
		data.presetShape
	);
}

/**
 * A colour a picker can open on: a value that is a colour, or the fallback.
 * @param value
 * @param fallback
 */
function colour( value: unknown, fallback: string ): string {
	return 'string' === typeof value && /^#[0-9a-f]{6}$/i.test( value )
		? value
		: fallback;
}

/**
 * The site's answer for each video attribute, from the site's video settings.
 * @param data
 */
function videoSays( data: EditorData ): Record< string, boolean > {
	const site: Record< string, boolean > = {};

	for ( const [ key, attribute ] of Object.entries(
		data.videoOverrides ?? {}
	) ) {
		const value = data.video?.[ key ];

		if ( 'boolean' === typeof value ) {
			site[ attribute ] = value;
		}
	}

	return site;
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

function humanise( key: string, isVideo = false ): string {
	if ( 'sticky' === key ) {
		return isVideo
			? __( 'Keep playing in a corner while scrolling', 'imagina-player' )
			: __( 'Stick to the edge while scrolling', 'imagina-player' );
	}

	if ( 'remember_position' === key ) {
		return isVideo
			? __( 'Remember where the viewer left off', 'imagina-player' )
			: __( 'Remember where the listener left off', 'imagina-player' );
	}

	const labels: Record< string, string > = {
		show_artist: __( 'Show artist', 'imagina-player' ),
		show_title: __( 'Show title', 'imagina-player' ),
		show_thumbnail: __( 'Show thumbnail', 'imagina-player' ),
		show_volume: __( 'Show volume', 'imagina-player' ),
		show_time: __( 'Show times', 'imagina-player' ),
		show_download: __( 'Show download button', 'imagina-player' ),
		show_speed: __( 'Show speed control', 'imagina-player' ),
		show_skip: __( 'Show skip buttons', 'imagina-player' ),
		show_more: __( 'More: sleep timer, bookmarks, link', 'imagina-player' ),
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
	{ value: 'text', label: __( 'Text over the picture', 'imagina-player' ) },
	{ value: 'image', label: __( 'Image over the picture', 'imagina-player' ) },
	{
		value: 'hotspot',
		label: __( 'Hotspot (a spot to press)', 'imagina-player' ),
	},
	{ value: 'shortcode', label: __( 'Shortcode', 'imagina-player' ) },
];

/** The kinds that decorate rather than ask: they never stop playback. */
const DECOR_KINDS = [ 'text', 'image', 'hotspot', 'shortcode' ];

/*
 * Beside audio there is no picture: a text, an image and a shortcode go into
 * the flow under the player, and a spot to press has nothing to sit on.
 */
const AUDIO_LAYER_TYPES: Array< { value: string; label: string } > =
	LAYER_TYPES.filter( ( { value } ) => 'hotspot' !== value ).map(
		( type ) => {
			const plain: Record< string, string > = {
				text: __( 'Text under the player', 'imagina-player' ),
				image: __( 'Image under the player', 'imagina-player' ),
			};

			return plain[ type.value ]
				? { value: type.value, label: plain[ type.value ] }
				: type;
		}
	);

/**
 * Where a decoration goes when the author has not said.
 * @param kind
 */
function defaultPosition( kind: string ): string {
	if ( 'image' === kind ) {
		return 'top-right';
	}

	return 'shortcode' === kind ? 'center' : 'bottom-left';
}

/**
 * When a layer appears if the author has not said: a decoration from the start.
 * @param kind
 */
function defaultAt( kind: string ): number {
	return 'bar' === kind || DECOR_KINDS.includes( kind ) ? 0 : 100;
}

/** Where a decoration sits over the picture. */
const POSITIONS: Array< { value: string; label: string } > = [
	{ value: 'top-left', label: __( 'Top left', 'imagina-player' ) },
	{ value: 'top', label: __( 'Top', 'imagina-player' ) },
	{ value: 'top-right', label: __( 'Top right', 'imagina-player' ) },
	{ value: 'left', label: __( 'Left', 'imagina-player' ) },
	{ value: 'center', label: __( 'Centre', 'imagina-player' ) },
	{ value: 'right', label: __( 'Right', 'imagina-player' ) },
	{ value: 'bottom-left', label: __( 'Bottom left', 'imagina-player' ) },
	{ value: 'bottom', label: __( 'Bottom', 'imagina-player' ) },
	{ value: 'bottom-right', label: __( 'Bottom right', 'imagina-player' ) },
];

/** The four corners a mark can sit in. */
const CORNERS: Array< { value: string; label: string } > = [
	{ value: 'top-left', label: __( 'Top left', 'imagina-player' ) },
	{ value: 'top-right', label: __( 'Top right', 'imagina-player' ) },
	{ value: 'bottom-left', label: __( 'Bottom left', 'imagina-player' ) },
	{ value: 'bottom-right', label: __( 'Bottom right', 'imagina-player' ) },
];

/**
 * Why the server would leave a layer out, in the author's words, or nothing.
 * @param layer
 */
function layerProblem( layer: ListItem ): string {
	const messages: Record< LayerGap, string > = {
		'': '',
		words: __(
			'Needs a headline or some text to appear.',
			'imagina-player'
		),
		image: __( 'Needs an image to appear.', 'imagina-player' ),
		label: __(
			'Needs a label, some text or a link to appear.',
			'imagina-player'
		),
		shortcode: __(
			'Needs a shortcode, in brackets, to appear.',
			'imagina-player'
		),
		url: __(
			'Needs an address for its button to appear.',
			'imagina-player'
		),
	};

	return messages[ layerGap( layer ) ];
}

/**
 * When an overlay can be closed, what that means for this kind.
 * @param kind
 */
function closeHelp( kind: string ): string {
	if ( 'email' === kind ) {
		return __(
			'Without this, the email gate has to be answered to carry on.',
			'imagina-player'
		);
	}

	if ( 'cta' === kind ) {
		return __(
			'One that appears part-way through can always be closed, so the video is never stuck behind it. This decides it for one at the end.',
			'imagina-player'
		);
	}

	return __( 'Adds a small × to put it away.', 'imagina-player' );
}

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
	const sourceField = String( attributes.sourceField ?? '' );
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

	// What "Site" means for this block: its own preset, and the site's video
	// settings.
	const presetValues = presetSays( data, preset );
	const videoSite = videoSays( data );

	/*
	 * After a different file is put in, the subtitles, chapters, poster and
	 * storyboard of the old one are still on the block. Kept, since a new cut
	 * of the same lesson wants them, but said, with a way to clear them.
	 */
	const [ replaced, setReplaced ] = useState( false );

	const replaceWith = ( next: { src: string; attachmentId: number } ) => {
		const provider = [ 'youtube', 'vimeo' ].includes(
			identify( next.src ).kind
		);

		setAttributes( {
			...next,
			// A provider's video cannot carry our subtitle files, and the
			// panel that lists them is not shown for one, so they could never
			// be removed.
			...( provider ? { tracks: [], sources: [], audioTracks: [] } : {} ),
		} );

		setReplaced(
			( attributes.tracks as ListItem[] | undefined )?.length ||
				( attributes.chapters as ListItem[] | undefined )?.length ||
				Boolean( attributes.poster ) ||
				Boolean( attributes.storyboard )
				? true
				: false
		);
	};

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
		posterReason: '',
	} );

	const [ askingVimeo, setAskingVimeo ] = useState( false );

	/*
	 * Forget what Vimeo said and ask once more. A picture is remembered for
	 * a month and a refusal for an hour, so without this an author who has
	 * just made the video public would be told for the next hour that it is
	 * private. The preview is refreshed afterwards either way: it is the
	 * preview that carries the answer.
	 */
	const askVimeoAgain = (): void => {
		setAskingVimeo( true );

		apiFetch( {
			path: '/imagina-player/v1/provider/poster',
			method: 'POST',
			data: { src },
		} )
			.catch( () => undefined )
			.finally( () => {
				setAskingVimeo( false );
				setRefresh( ( n ) => n + 1 );
			} );
	};

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

	/*
	 * The post being edited, for a block that takes its file from one of its
	 * custom fields: the preview can then show that post's file. In the site
	 * editor this is the template, which has no such field, and the preview
	 * says so instead.
	 */
	const postId = useSelect( ( select ) => {
		const editor = select( 'core/editor' ) as
			| { getCurrentPostId?: () => number | null | undefined }
			| undefined;

		return Number( editor?.getCurrentPostId?.() ?? 0 ) || 0;
	}, [] );

	if ( ! src && ! sourceField ) {
		return (
			<>
				<InspectorControls>
					<DynamicSourcePanel
						sourceField={ sourceField }
						src={ src }
						setAttributes={ setAttributes }
					/>
				</InspectorControls>
				<div { ...blockProps }>
					<MediaPlaceholder
						icon={ isVideoBlock ? 'format-video' : 'format-audio' }
						labels={ {
							title: isVideoBlock
								? __( 'Imagina Video Player', 'imagina-player' )
								: __(
										'Imagina Audio Player',
										'imagina-player'
								  ),
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
			</>
		);
	}

	return (
		<div { ...blockProps }>
			<BlockControls>
				<MediaReplaceFlow
					mediaId={ Number( attributes.attachmentId ?? 0 ) }
					mediaURL={ src }
					accept={ isVideoBlock ? 'video/*' : 'audio/*,video/*' }
					allowedTypes={
						isVideoBlock ? [ 'video' ] : [ 'audio', 'video' ]
					}
					onSelect={ ( media: {
						id?: number;
						url?: string;
						title?: string;
					} ) =>
						replaceWith( {
							src: media.url ?? '',
							attachmentId: media.id ?? 0,
						} )
					}
					onSelectURL={ ( url: string ) =>
						replaceWith( { src: url, attachmentId: 0 } )
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
					{ /*
					     In the sidebar, not on the block.

					     These used to render inside the block itself, above the
					     player, where every one of them reads as part of the
					     page being built — somebody looking over the author's
					     shoulder cannot tell an editing note from content, and
					     assumed the visitors would see "Measure this waveform
					     again" printed above the audio. Editing tools belong
					     where the editing tools are.
					*/ }
					<SourceStatus src={ src } />
					<SourceWarning src={ src } isVideoBlock={ isVideoBlock } />

					{ replaced && (
						<Notice
							status="info"
							onRemove={ () => setReplaced( false ) }
						>
							<p>
								{ __(
									'The file changed. The subtitles, chapters, poster and storyboard of the previous one are still on this block.',
									'imagina-player'
								) }
							</p>
							<Button
								variant="secondary"
								onClick={ () => {
									setAttributes( {
										tracks: [],
										chapters: [],
										poster: '',
										posterId: 0,
										storyboard: '',
										storyboardId: 0,
									} );
									setReplaced( false );
								} }
							>
								{ __( 'Clear them', 'imagina-player' ) }
							</Button>
						</Notice>
					) }

					{ resolved.posterReason && (
						<Notice
							status="warning"
							isDismissible={ false }
							className="imgp-editor__poster-notice"
						>
							<p>
								{ sprintf(
									/* translators: %s: why Vimeo did not hand over the picture. */
									__(
										'Vimeo did not hand over the video’s picture: %s. Choose a poster below, or ask again once the video is public.',
										'imagina-player'
									),
									resolved.posterReason
								) }
							</p>
							<Button
								variant="secondary"
								isBusy={ askingVimeo }
								disabled={ askingVimeo }
								onClick={ askVimeoAgain }
							>
								{ __( 'Ask Vimeo again', 'imagina-player' ) }
							</Button>
						</Notice>
					) }

					<WaveformNotice
						attachmentIds={ [
							Number( attributes.attachmentId ?? 0 ),
						] }
						urls={
							Number( attributes.attachmentId ?? 0 )
								? []
								: [ src ]
						}
						disabled={ isVideo }
						onMeasured={ () => setRefresh( ( n ) => n + 1 ) }
					/>

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
						help={
							isVideo
								? __(
										'Optional. Defaults to the video file itself.',
										'imagina-player'
								  )
								: __(
										'Optional. Defaults to the audio file itself.',
										'imagina-player'
								  )
						}
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

				<DynamicSourcePanel
					sourceField={ sourceField }
					src={ src }
					setAttributes={ setAttributes }
				/>

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
								value={ String( attributes.aspectRatio ?? '' ) }
								options={ [
									{
										value: '',
										label: sprintf(
											/* translators: %s: the site's shape, as in 16:9. */
											__(
												'Use site setting (%s)',
												'imagina-player'
											),
											String(
												data.video?.ratio ?? '16:9'
											)
										),
									},
									...RATIOS,
								] }
								onChange={ ( value: string ) =>
									setAttributes( { aspectRatio: value } )
								}
							/>

							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Poster', 'imagina-player' ) }
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

							{ /* A mark in a corner of the picture, the whole way
							     through. It was in the schema and drawn by the
							     renderer, with no way to set it but a
							     shortcode. */ }
							<BaseControl
								__nextHasNoMarginBottom
								id="imgp-watermark"
								label={ __( 'Watermark', 'imagina-player' ) }
								help={ __(
									'A logo in a corner of the picture. It makes a copy traceable; it does not stop one being made.',
									'imagina-player'
								) }
							>
								<div className="imgp-editor__media-picker">
									{ Boolean( attributes.watermark ) && (
										<img
											className="imgp-editor__thumb"
											src={ String(
												attributes.watermark
											) }
											alt=""
										/>
									) }
									<MediaUploadCheck>
										<MediaUpload
											allowedTypes={ [ 'image' ] }
											value={ Number(
												attributes.watermarkId ?? 0
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
													watermark:
														media.sizes?.medium
															?.url ??
														media.url ??
														'',
													watermarkId: media.id ?? 0,
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
													{ attributes.watermark
														? __(
																'Replace',
																'imagina-player'
														  )
														: __(
																'Choose an image',
																'imagina-player'
														  ) }
												</Button>
											) }
										/>
									</MediaUploadCheck>
									{ Boolean( attributes.watermark ) && (
										<Button
											variant="tertiary"
											isDestructive
											onClick={ () =>
												setAttributes( {
													watermark: '',
													watermarkId: 0,
												} )
											}
										>
											{ __( 'Remove', 'imagina-player' ) }
										</Button>
									) }
								</div>
							</BaseControl>

							{ /* The person watching, by name, drifting over the
							     picture: for lessons that are paid for. */ }
							<SelectControl
								__nextHasNoMarginBottom
								label={ __(
									'Viewer’s name over the picture',
									'imagina-player'
								) }
								help={ __(
									'Shown only to somebody signed in, and only their own. It moves every few seconds, so a screen recording carries the name of whoever made it. Picture-in-picture is turned off with it.',
									'imagina-player'
								) }
								value={
									String(
										attributes.videoViewerMark ?? ''
									) as ''
								}
								options={ [
									{
										value: '',
										label: sprintf(
											/* translators: %s: what the site does now. */
											__(
												'Use site setting (%s)',
												'imagina-player'
											),
											(
												{
													off: __(
														'off',
														'imagina-player'
													),
													name: __(
														'name',
														'imagina-player'
													),
													email: __(
														'email',
														'imagina-player'
													),
													both: __(
														'name and email',
														'imagina-player'
													),
												} as Record< string, string >
											 )[
												String(
													data.video?.viewer_mark ??
														'off'
												)
											] ?? __( 'off', 'imagina-player' )
										),
									},
									{
										value: 'off',
										label: __( 'Off', 'imagina-player' ),
									},
									{
										value: 'name',
										label: __(
											'Their name',
											'imagina-player'
										),
									},
									{
										value: 'email',
										label: __(
											'Their email',
											'imagina-player'
										),
									},
									{
										value: 'both',
										label: __(
											'Their name and email',
											'imagina-player'
										),
									},
								] }
								onChange={ ( value: string ) =>
									setAttributes( { videoViewerMark: value } )
								}
							/>

							{ Boolean( attributes.watermark ) && (
								<>
									<SelectControl
										__nextHasNoMarginBottom
										label={ __(
											'Watermark corner',
											'imagina-player'
										) }
										value={ String(
											attributes.watermarkPosition ??
												'top-right'
										) }
										options={ CORNERS }
										onChange={ ( value: string ) =>
											setAttributes( {
												watermarkPosition: value,
											} )
										}
									/>
									<RangeControl
										__nextHasNoMarginBottom
										label={ __(
											'Watermark opacity (%)',
											'imagina-player'
										) }
										min={ 5 }
										max={ 100 }
										step={ 5 }
										value={ Number(
											attributes.watermarkOpacity ?? 55
										) }
										onChange={ ( value?: number ) =>
											setAttributes( {
												watermarkOpacity: value ?? 55,
											} )
										}
									/>
								</>
							) }
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
						items={
							/*
							 * Each starts on the colour this block will
							 * actually get — its preset's, or the site's
							 * video settings — so the picker opens on
							 * what is there rather than on the factory's.
							 */
							(
								[
									[
										'accent',
										__( 'Accent', 'imagina-player' ),
										colour(
											presetValues.accent,
											'#1f2937'
										),
									],
									[
										'waveColor',
										__( 'Waveform', 'imagina-player' ),
										colour(
											presetValues.wave_color,
											'#c9ced6'
										),
									],
									[
										'waveProgress',
										__(
											'Played portion',
											'imagina-player'
										),
										colour(
											presetValues.wave_progress,
											'#1f2937'
										),
									],
									[
										'videoChromeColor',
										__( 'Control bar', 'imagina-player' ),
										colour(
											data.video?.chrome_color,
											'#000000'
										),
									],
									[
										'videoControlColor',
										__(
											'Buttons and times',
											'imagina-player'
										),
										colour(
											data.video?.control_color,
											'#ffffff'
										),
									],
									[
										'videoProgressColor',
										__(
											'Played portion',
											'imagina-player'
										),
										colour(
											data.video?.progress_color,
											colour(
												presetValues.accent,
												'#ffffff'
											)
										),
									],
									[
										'videoCaptionColor',
										__( 'Subtitles', 'imagina-player' ),
										colour(
											data.video?.caption_color,
											'#ffffff'
										),
									],
									[
										'controlColor',
										__( 'Buttons', 'imagina-player' ),
										colour(
											presetValues.control_color,
											'#374151'
										),
									],
									[
										'textColor',
										__( 'Title', 'imagina-player' ),
										colour(
											presetValues.text_color,
											'#111827'
										),
									],
									[
										'metaColor',
										__( 'Artist', 'imagina-player' ),
										colour(
											presetValues.meta_color,
											'#6b7280'
										),
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
									( isVideo ||
										! attribute.startsWith( 'video' ) )
							)
						}
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
						{ isVideo
							? __(
									'Which controls this player shows. Left on Site, each one follows the video settings for the whole site, and says what those are.',
									'imagina-player'
							  )
							: __(
									'Which controls this player shows. Left on Site, each one follows this block’s preset, and says what that is.',
									'imagina-player'
							  ) }
					</p>

					{ isVideo ? (
						<TristateList
							items={ VIDEO_CONTROLS.filter(
								( [ attribute ] ) =>
									! isProvider ||
									! NOT_FOR_PROVIDERS.includes( attribute )
							) }
							values={ attributes }
							site={ videoSite }
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
										Boolean( presetValues[ key ] ),
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
								step={ 0.5 }
								label={ __(
									'Hide the controls after (seconds)',
									'imagina-player'
								) }
								help={ sprintf(
									/* translators: %s: the site's setting, in seconds. */
									__(
										'While the video plays and nobody moves. Empty uses the site setting (%s s); zero keeps them up.',
										'imagina-player'
									),
									String(
										Number(
											data.video?.hide_after ?? 2600
										) / 1000
									)
								) }
								// Seconds here, as on the settings screen; the
								// block still stores milliseconds.
								value={
									'' ===
									String( attributes.videoHideAfter ?? '' )
										? ''
										: String(
												Number(
													attributes.videoHideAfter
												) / 1000
										  )
								}
								onChange={ ( value: string ) =>
									setAttributes( {
										videoHideAfter:
											'' === value.trim()
												? ''
												: String(
														Math.max(
															0,
															Math.round(
																Number(
																	value
																) * 1000
															)
														) || 0
												  ),
									} )
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
									[
										attribute,
										humanise( key, isVideo ),
									] as const
							) }
						values={ attributes }
						onOff
						site={ Object.fromEntries(
							visibilityToggles( data )
								.filter( ( { key } ) =>
									BEHAVIOUR.includes( key )
								)
								.map( ( { key, attribute } ) => [
									attribute,
									Boolean( presetValues[ key ] ),
								] )
						) }
						onChange={ ( attribute, value ) =>
							setAttributes( { [ attribute ]: value } )
						}
					/>

					{ isVideo && (
						<TristateList
							items={ VIDEO_PLAYBACK.filter(
								( [ attribute ] ) =>
									isProvider ||
									! ONLY_FOR_PROVIDERS.includes( attribute )
							) }
							values={ attributes }
							onOff
							site={ videoSite }
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
							onOff
							site={ videoSite }
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
					title={ __(
						'Overlays and calls to action',
						'imagina-player'
					) }
					initialOpen={ false }
				>
					<p className="imgp-editor__hint">
						{ isVideo
							? __(
									'Appears part-way through. A call to action and an email gate stop playback until answered or closed; a bar, a text, an image, a hotspot and a shortcode sit alongside it.',
									'imagina-player'
							  )
							: __(
									'Appears part-way through, under the player. A call to action and an email gate stop playback until answered or closed; a bar, a text, an image and a shortcode sit alongside it.',
									'imagina-player'
							  ) }
					</p>

					{ layers.map( ( layer, index ) => (
						<div className="imgp-editor__row" key={ index }>
							{ layerProblem( layer ) && (
								<Notice
									status="warning"
									isDismissible={ false }
									className="imgp-editor__layer-notice"
								>
									{ layerProblem( layer ) }
								</Notice>
							) }
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Kind', 'imagina-player' ) }
								value={ String( layer.type ?? 'cta' ) }
								options={
									isVideo ? LAYER_TYPES : AUDIO_LAYER_TYPES
								}
								onChange={ ( value: string ) =>
									/*
									 * Changing the kind changes when it makes
									 * sense to appear. A bar or a decoration
									 * is a standing thing and belongs from
									 * the start; the other two interrupt,
									 * and belong at the end. Leaving a bar
									 * on the old default meant it only ever
									 * appeared once the video had finished,
									 * which reads as the feature not
									 * working.
									 */
									patchLayer( index, {
										type: value,
										at:
											'bar' === value ||
											DECOR_KINDS.includes( value )
												? 0
												: Number( layer.at ?? 100 ),
									} )
								}
							/>
							<RangeControl
								__nextHasNoMarginBottom
								label={ __( 'Appears at', 'imagina-player' ) }
								help={
									0 ===
									Number(
										layer.at ??
											defaultAt(
												String( layer.type ?? '' )
											)
									)
										? __(
												'From the start, before anything is played.',
												'imagina-player'
										  )
										: __(
												'Percentage of the track. 100 means when it ends.',
												'imagina-player'
										  )
								}
								value={ Number(
									layer.at ??
										defaultAt( String( layer.type ?? '' ) )
								) }
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
							{ 'shortcode' === layer.type && (
								<TextControl
									__nextHasNoMarginBottom
									label={ __(
										'Shortcode',
										'imagina-player'
									) }
									help={ __(
										'Any shortcode another plugin provides — a form, a button, a countdown. It runs when the page is shown.',
										'imagina-player'
									) }
									value={ String( layer.shortcode ?? '' ) }
									placeholder='[contact-form-7 id="12"]'
									onChange={ ( value: string ) =>
										patchLayer( index, {
											shortcode: value,
										} )
									}
								/>
							) }

							{ 'image' === layer.type && (
								<>
									<MediaUploadCheck>
										<MediaUpload
											allowedTypes={ [ 'image' ] }
											value={ Number(
												layer.imageId ?? 0
											) }
											onSelect={ ( media: {
												id?: number;
												url?: string;
											} ) =>
												patchLayer( index, {
													imageId: Number(
														media.id ?? 0
													),
													image: String(
														media.url ?? ''
													),
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
													{ layer.image
														? __(
																'Change image',
																'imagina-player'
														  )
														: __(
																'Choose an image',
																'imagina-player'
														  ) }
												</Button>
											) }
										/>
									</MediaUploadCheck>
									<TextControl
										__nextHasNoMarginBottom
										label={ __(
											'Image URL',
											'imagina-player'
										) }
										value={ String( layer.image ?? '' ) }
										placeholder="https://…"
										onChange={ ( value: string ) =>
											patchLayer( index, {
												image: value,
												imageId: 0,
											} )
										}
									/>
									<RangeControl
										__nextHasNoMarginBottom
										label={ __(
											'Width (percent of the picture)',
											'imagina-player'
										) }
										value={ Number( layer.width ?? 25 ) }
										min={ 5 }
										max={ 100 }
										step={ 5 }
										onChange={ ( value?: number ) =>
											patchLayer( index, {
												width: value ?? 25,
											} )
										}
									/>
								</>
							) }

							{ 'hotspot' === layer.type && (
								<>
									<RangeControl
										__nextHasNoMarginBottom
										label={ __(
											'Across (percent from the left)',
											'imagina-player'
										) }
										value={ Number( layer.x ?? 50 ) }
										min={ 0 }
										max={ 100 }
										onChange={ ( value?: number ) =>
											patchLayer( index, {
												x: value ?? 50,
											} )
										}
									/>
									<RangeControl
										__nextHasNoMarginBottom
										label={ __(
											'Down (percent from the top)',
											'imagina-player'
										) }
										value={ Number( layer.y ?? 50 ) }
										min={ 0 }
										max={ 100 }
										onChange={ ( value?: number ) =>
											patchLayer( index, {
												y: value ?? 50,
											} )
										}
									/>
								</>
							) }

							{ isVideo &&
								( 'text' === layer.type ||
									'image' === layer.type ||
									'shortcode' === layer.type ) && (
									<SelectControl
										__nextHasNoMarginBottom
										label={ __(
											'Position',
											'imagina-player'
										) }
										value={ String(
											layer.position ??
												defaultPosition(
													String( layer.type ?? '' )
												)
										) }
										options={ POSITIONS }
										onChange={ ( value: string ) =>
											patchLayer( index, {
												position: value,
											} )
										}
									/>
								) }

							{ 'shortcode' !== layer.type && (
								<>
									<TextControl
										__nextHasNoMarginBottom
										label={
											'image' === layer.type
												? __(
														'Alternative text',
														'imagina-player'
												  )
												: __(
														'Headline',
														'imagina-player'
												  )
										}
										value={ String( layer.title ?? '' ) }
										onChange={ ( value: string ) =>
											patchLayer( index, {
												title: value,
											} )
										}
									/>
									{ 'image' !== layer.type && (
										<TextControl
											__nextHasNoMarginBottom
											label={ __(
												'Text',
												'imagina-player'
											) }
											value={ String( layer.text ?? '' ) }
											onChange={ ( value: string ) =>
												patchLayer( index, {
													text: value,
												} )
											}
										/>
									) }
								</>
							) }

							{ ! DECOR_KINDS.includes(
								String( layer.type ?? 'cta' )
							) && (
								<TextControl
									__nextHasNoMarginBottom
									label={ __(
										'Button label',
										'imagina-player'
									) }
									value={ String( layer.button ?? '' ) }
									onChange={ ( value: string ) =>
										patchLayer( index, { button: value } )
									}
								/>
							) }

							{ 'shortcode' !== layer.type &&
								( 'email' === layer.type ? (
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
												patchLayer( index, {
													list: value,
												} )
											}
										/>
										<TextControl
											__nextHasNoMarginBottom
											label={ __(
												'Small print',
												'imagina-player'
											) }
											value={ String(
												layer.consent ?? ''
											) }
											onChange={ ( value: string ) =>
												patchLayer( index, {
													consent: value,
												} )
											}
										/>
										<TextControl
											__nextHasNoMarginBottom
											label={ __(
												'Thank-you message',
												'imagina-player'
											) }
											help={ __(
												'Shown once the address is in, before playback carries on.',
												'imagina-player'
											) }
											placeholder={ __(
												'Thank you.',
												'imagina-player'
											) }
											value={ String(
												layer.thanks ?? ''
											) }
											onChange={ ( value: string ) =>
												patchLayer( index, {
													thanks: value,
												} )
											}
										/>
									</>
								) : (
									<>
										<TextControl
											__nextHasNoMarginBottom
											label={
												DECOR_KINDS.includes(
													String( layer.type ?? '' )
												)
													? __(
															'Links to (optional)',
															'imagina-player'
													  )
													: __(
															'Button links to',
															'imagina-player'
													  )
											}
											value={ String( layer.url ?? '' ) }
											placeholder="https://…"
											onChange={ ( value: string ) =>
												patchLayer( index, {
													url: value,
												} )
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
								) ) }

							{ /* A bar can always be closed; there is nothing to
							     decide for one. */ }
							{ 'bar' !== layer.type && (
								<ToggleControl
									__nextHasNoMarginBottom
									label={ __(
										'Can be closed',
										'imagina-player'
									) }
									help={ closeHelp(
										String( layer.type ?? 'cta' )
									) }
									checked={ Boolean( layer.skip ) }
									onChange={ ( value: boolean ) =>
										patchLayer( index, { skip: value } )
									}
								/>
							) }

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
						{ __( 'Add an overlay', 'imagina-player' ) }
					</Button>
				</PanelBody>

				{ isVideo && ! isProvider && (
					<SourcesPanel
						attributes={ attributes }
						setAttributes={ setAttributes }
						isStream={ 'hls' === identify( src ).kind }
					/>
				) }

				<AccessPanel
					attributes={ attributes }
					setAttributes={ setAttributes }
				/>

				{ isVideo && ! isProvider && (
					<PanelBody
						title={ __( 'Advanced', 'imagina-player' ) }
						initialOpen={ false }
					>
						{ /* Not offered for a provider: the file is not on this
						     site, so there is nothing here to withhold. */ }
						{ ! isProvider && (
							<Tristate
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
								onOff
								siteValue={ videoSite.videoBlockDownload }
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

			<Preview
				onResolved={ setResolved }
				refresh={ refresh }
				attributes={ attributes }
				postId={ postId }
				assets={ {
					frontendCss: data.frontendCss,
					frontendJs: data.frontendJs,
					frameCss: data.frameCss,
					restUrl: data.restUrl,
					assetUrl: data.assetUrl,
					frontendChunks: data.frontendChunks,
				} }
			/>
		</div>
	);
}
