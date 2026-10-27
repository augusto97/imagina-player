/**
 * The playlist block's editor half.
 *
 * Deliberately not a preview of the finished playlist. The list is the thing
 * being edited and the player below it is the same one the audio block already
 * shows, so what earns its place here is the order of the tracks and the
 * ability to change it — which is what the editor cannot do on the front end.
 */

import {
	BlockControls,
	InspectorControls,
	MediaPlaceholder,
	MediaUpload,
	MediaUploadCheck,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	Button,
	PanelBody,
	SelectControl,
	TextControl,
	ToolbarButton,
	ToolbarGroup,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { WaveformNotice } from './waveform-notice';
import { AccessPanel } from './access-panel';
import { clock, merge, seconds, toItem } from './playlist-items';
import type { Item, Selected } from './playlist-items';

interface PlaylistAttributes {
	items?: Item[];
	layout?: string;
	heading?: string;
	preset?: string;
	[ key: string ]: unknown;
}

interface EditProps {
	attributes: PlaylistAttributes;
	setAttributes: ( next: Partial< PlaylistAttributes > ) => void;
}

/* Typed as plain strings so the control accepts whatever was saved, rather
   than only the two shapes offered today. */
const LAYOUTS: Array< { value: string; label: string } > = [
	{ value: 'list', label: __( 'List', 'imagina-player' ) },
	{ value: 'grid', label: __( 'Grid of covers', 'imagina-player' ) },
	{
		value: 'side',
		label: __( 'Video with the list beside it', 'imagina-player' ),
	},
	{
		value: 'rail',
		label: __(
			'Video with a rail of thumbnails under it',
			'imagina-player'
		),
	},
	{
		value: 'slider',
		label: __( 'Video with a slider of cards under it', 'imagina-player' ),
	},
];

interface Preset {
	value: string;
	label: string;
}

function presets(): Preset[] {
	return (
		(
			window as unknown as {
				imaginaPlayerEditor?: { presets?: Preset[] };
			}
		 ).imaginaPlayerEditor?.presets ?? [
			{ value: 'default', label: __( 'Default', 'imagina-player' ) },
		]
	);
}

export function PlaylistEdit( { attributes, setAttributes }: EditProps ) {
	const blockProps = useBlockProps( { className: 'imgp-playlist-editor' } );
	const items = attributes.items ?? [];

	// Only to re-key the list after measuring, so the covers and titles are
	// re-read rather than left showing what was there before.
	const [ refresh, setRefresh ] = useState( 0 );
	const [ address, setAddress ] = useState( '' );
	const [ open, setOpen ] = useState( -1 );
	// What is being typed into a length, so "3:" is not wiped on the way to
	// "3:14".
	const [ typed, setTyped ] = useState( '' );

	const replace = ( next: Item[] ): void => setAttributes( { items: next } );

	const patch = ( index: number, next: Partial< Item > ): void =>
		replace(
			items.map( ( item, i ) =>
				i === index ? { ...item, ...next } : item
			)
		);

	const move = ( index: number, by: number ): void => {
		const target = index + by;

		if ( target < 0 || target >= items.length ) {
			return;
		}

		const next = items.slice();

		[ next[ index ], next[ target ] ] = [ next[ target ], next[ index ] ];
		replace( next );
	};

	if ( 0 === items.length ) {
		return (
			<div { ...blockProps }>
				<MediaPlaceholder
					icon="playlist-audio"
					labels={ {
						title: __( 'Imagina Playlist', 'imagina-player' ),
						instructions: __(
							'Choose several files from your media library, or start from one address. They play in the order you pick them, and you can reorder them and add more afterwards.',
							'imagina-player'
						),
					} }
					accept="audio/*,video/*"
					allowedTypes={ [ 'audio', 'video' ] }
					multiple
					onSelect={ ( media: Selected[] | Selected ) =>
						replace(
							( Array.isArray( media ) ? media : [ media ] ).map(
								toItem
							)
						)
					}
					onSelectURL={ ( url: string ) =>
						replace( [
							{
								id: 0,
								src: url,
								title: '',
								artist: '',
								thumbnail: '',
								duration: 0,
							},
						] )
					}
				/>
			</div>
		);
	}

	return (
		<div { ...blockProps }>
			<BlockControls>
				<ToolbarGroup>
					<MediaUploadCheck>
						<MediaUpload
							multiple
							addToGallery
							gallery={ false }
							allowedTypes={ [ 'audio', 'video' ] }
							value={ items.map( ( item ) => item.id ?? 0 ) }
							onSelect={ ( media: Selected[] ) =>
								replace( merge( items, media ) )
							}
							render={ ( {
								open: pick,
							}: {
								open: () => void;
							} ) => (
								<ToolbarButton
									icon="edit"
									label={ __(
										'Edit tracks',
										'imagina-player'
									) }
									onClick={ pick }
								/>
							) }
						/>
					</MediaUploadCheck>
				</ToolbarGroup>
			</BlockControls>

			<InspectorControls>
				<PanelBody title={ __( 'Playlist', 'imagina-player' ) }>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Heading', 'imagina-player' ) }
						value={ attributes.heading ?? '' }
						onChange={ ( value: string ) =>
							setAttributes( { heading: value } )
						}
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Layout', 'imagina-player' ) }
						value={ attributes.layout ?? 'list' }
						options={ LAYOUTS }
						onChange={ ( value: string ) =>
							setAttributes( { layout: value } )
						}
					/>
					{ /* The preset was an attribute the block honoured and
					     the editor never offered. */ }
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Preset', 'imagina-player' ) }
						value={ attributes.preset ?? 'default' }
						options={ presets() }
						onChange={ ( value: string ) =>
							setAttributes( { preset: value } )
						}
					/>
				</PanelBody>

				<PanelBody
					title={ __( 'Add by address', 'imagina-player' ) }
					initialOpen={ false }
				>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Address', 'imagina-player' ) }
						help={ __(
							'A YouTube or Vimeo link, an MP4, an MP3 or an HLS stream on any site.',
							'imagina-player'
						) }
						placeholder="https://…"
						value={ address }
						onChange={ setAddress }
					/>
					<Button
						variant="secondary"
						disabled={ ! /^https?:\/\/\S+$/.test( address.trim() ) }
						onClick={ () => {
							replace( [
								...items,
								{
									id: 0,
									src: address.trim(),
									title: '',
									artist: '',
									thumbnail: '',
									duration: 0,
								},
							] );
							setAddress( '' );
						} }
					>
						{ __( 'Add to the list', 'imagina-player' ) }
					</Button>
				</PanelBody>

				<AccessPanel
					attributes={ attributes }
					setAttributes={ setAttributes }
				/>

				<PanelBody title={ __( 'Waveforms', 'imagina-player' ) }>
					{ /*
					     In the sidebar, like every other editing tool. On the
					     block it read as something the visitors would see.
					*/ }
					<WaveformNotice
						attachmentIds={ items.map( ( item ) => item.id ?? 0 ) }
						urls={ items
							.filter( ( item ) => ! item.id )
							.map( ( item ) => item.src ?? '' ) }
						onMeasured={ () => setRefresh( ( n ) => n + 1 ) }
					/>
				</PanelBody>
			</InspectorControls>

			<ol className="imgp-playlist-editor__items" key={ refresh }>
				{ items.map( ( item, index ) => (
					// The position and the file together: the same file can be
					// in the list twice, and two rows with one key made React
					// mix their fields up.
					<li key={ `${ index }-${ item.id || item.src }` }>
						{ item.thumbnail ? (
							// eslint-disable-next-line jsx-a11y/alt-text -- decorative cover art beside its own title.
							<img
								className="imgp-playlist-editor__art"
								src={ item.thumbnail }
								alt=""
							/>
						) : (
							// Holds the column, so a row without a cover lines up.
							<span className="imgp-playlist-editor__art imgp-playlist-editor__art--blank" />
						) }

						<TextControl
							__nextHasNoMarginBottom
							label={ sprintf(
								/* translators: %d: track number. */
								__( 'Track %d', 'imagina-player' ),
								index + 1
							) }
							value={ item.title ?? '' }
							onChange={ ( value: string ) => {
								const next = items.slice();

								next[ index ] = { ...item, title: value };
								replace( next );
							} }
						/>

						<div className="imgp-playlist-editor__actions">
							<Button
								size="small"
								icon="admin-generic"
								label={ __( 'Details', 'imagina-player' ) }
								isPressed={ open === index }
								onClick={ () => {
									setOpen( open === index ? -1 : index );
									setTyped( '' );
								} }
							/>
							<Button
								size="small"
								icon="arrow-up-alt2"
								label={ __( 'Move up', 'imagina-player' ) }
								disabled={ 0 === index }
								onClick={ () => move( index, -1 ) }
							/>
							<Button
								size="small"
								icon="arrow-down-alt2"
								label={ __( 'Move down', 'imagina-player' ) }
								disabled={ index === items.length - 1 }
								onClick={ () => move( index, 1 ) }
							/>
							<Button
								size="small"
								isDestructive
								variant="tertiary"
								onClick={ () =>
									replace(
										items.filter( ( _x, i ) => i !== index )
									)
								}
							>
								{ __( 'Remove', 'imagina-player' ) }
							</Button>
						</div>

						{ open === index && (
							<div className="imgp-playlist-editor__details">
								<TextControl
									__nextHasNoMarginBottom
									label={ __( 'Artist', 'imagina-player' ) }
									value={ item.artist ?? '' }
									onChange={ ( value: string ) =>
										patch( index, { artist: value } )
									}
								/>
								<div className="imgp-editor__media-picker">
									<MediaUploadCheck>
										<MediaUpload
											allowedTypes={ [ 'image' ] }
											onSelect={ ( media: {
												url?: string;
												sizes?: Record<
													string,
													{ url?: string }
												>;
											} ) =>
												patch( index, {
													thumbnail:
														media.sizes?.medium
															?.url ??
														media.url ??
														'',
												} )
											}
											render={ ( {
												open: choose,
											}: {
												open: () => void;
											} ) => (
												<Button
													variant="secondary"
													size="small"
													onClick={ choose }
												>
													{ item.thumbnail
														? __(
																'Replace cover',
																'imagina-player'
														  )
														: __(
																'Choose a cover',
																'imagina-player'
														  ) }
												</Button>
											) }
										/>
									</MediaUploadCheck>
									{ item.thumbnail && (
										<Button
											variant="tertiary"
											size="small"
											isDestructive
											onClick={ () =>
												patch( index, {
													thumbnail: '',
												} )
											}
										>
											{ __(
												'Remove cover',
												'imagina-player'
											) }
										</Button>
									) }
								</div>
								{ ! item.id && (
									<>
										<TextControl
											__nextHasNoMarginBottom
											label={ __(
												'Address',
												'imagina-player'
											) }
											value={ item.src ?? '' }
											onChange={ ( value: string ) =>
												patch( index, { src: value } )
											}
										/>
										<TextControl
											__nextHasNoMarginBottom
											label={ __(
												'Length',
												'imagina-player'
											) }
											help={ __(
												'As in 3:14. Files from the media library know their own.',
												'imagina-player'
											) }
											placeholder="3:14"
											value={
												typed ||
												clock( item.duration ?? 0 )
											}
											onChange={ ( value: string ) => {
												setTyped( value );
												patch( index, {
													duration: seconds( value ),
												} );
											} }
										/>
									</>
								) }
							</div>
						) }
					</li>
				) ) }
			</ol>
		</div>
	);
}
