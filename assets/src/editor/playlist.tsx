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

interface Item {
	id?: number;
	src?: string;
	title?: string;
	artist?: string;
	thumbnail?: string;
	duration?: number;
}

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

/** What the media library hands back for one selected file. */
interface Selected {
	id?: number;
	url?: string;
	title?: string;
	fileLength?: number;
	image?: { src?: string };
	meta?: { artist?: string; length_formatted?: string };
}

/* Typed as plain strings so the control accepts whatever was saved, rather
   than only the two shapes offered today. */
const LAYOUTS: Array< { value: string; label: string } > = [
	{ value: 'list', label: __( 'List', 'imagina-player' ) },
	{ value: 'grid', label: __( 'Grid of covers', 'imagina-player' ) },
];

function toItem( media: Selected ): Item {
	return {
		id: media.id ?? 0,
		src: media.url ?? '',
		title: media.title ?? '',
		artist: media.meta?.artist ?? '',
		// The library's own thumbnail, which for audio is the embedded cover
		// art WordPress extracted on upload.
		thumbnail: media.image?.src ?? '',
		duration: 0,
	};
}

export function PlaylistEdit( { attributes, setAttributes }: EditProps ) {
	const blockProps = useBlockProps( { className: 'imgp-playlist-editor' } );
	const items = attributes.items ?? [];

	// Only to re-key the list after measuring, so the covers and titles are
	// re-read rather than left showing what was there before.
	const [ refresh, setRefresh ] = useState( 0 );

	const replace = ( next: Item[] ): void => setAttributes( { items: next } );

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
							'Choose several files from your media library. They play in the order you pick them, and you can reorder them afterwards.',
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
								replace( media.map( toItem ) )
							}
							render={ ( { open }: { open: () => void } ) => (
								<ToolbarButton
									icon="edit"
									label={ __(
										'Edit tracks',
										'imagina-player'
									) }
									onClick={ open }
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
						label={ __( 'Shape', 'imagina-player' ) }
						value={ attributes.layout ?? 'list' }
						options={ LAYOUTS }
						onChange={ ( value: string ) =>
							setAttributes( { layout: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<WaveformNotice
				attachmentIds={ items.map( ( item ) => item.id ?? 0 ) }
				onMeasured={ () => setRefresh( ( n ) => n + 1 ) }
			/>

			<ol className="imgp-playlist-editor__items" key={ refresh }>
				{ items.map( ( item, index ) => (
					<li key={ item.id ?? index }>
						{ item.thumbnail && (
							// eslint-disable-next-line jsx-a11y/alt-text -- decorative cover art beside its own title.
							<img
								className="imgp-playlist-editor__art"
								src={ item.thumbnail }
								alt=""
							/>
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
					</li>
				) ) }
			</ol>
		</div>
	);
}
