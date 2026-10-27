/**
 * The same video in other qualities, and in other languages.
 *
 * A quality is another file of the same video, at another size; the viewer
 * picks one under the quality button. A soundtrack is an audio file as long
 * as the video, played in step with the picture in place of its own sound —
 * a dubbing — and picked from the gear.
 */

import { MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import { Button, PanelBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

interface Choice {
	src?: string;
	id?: number;
	label?: string;
	srclang?: string;
}

interface SourcesPanelProps {
	attributes: Record< string, unknown >;
	setAttributes: ( next: Record< string, unknown > ) => void;
	/** A stream offers its own qualities; only soundtracks are added to it. */
	isStream: boolean;
}

interface ListProps {
	items: Choice[];
	onChange: ( items: Choice[] ) => void;
	type: 'video' | 'audio';
	placeholder: string;
	withLanguage?: boolean;
	addLabel: string;
}

function ChoiceList( {
	items,
	onChange,
	type,
	placeholder,
	withLanguage,
	addLabel,
}: ListProps ) {
	const patch = ( index: number, next: Choice ): void =>
		onChange(
			items.map( ( item, i ) =>
				i === index ? { ...item, ...next } : item
			)
		);

	return (
		<>
			{ items.map( ( item, index ) => (
				<div className="imgp-editor__row" key={ index }>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Name in the menu', 'imagina-player' ) }
						value={ String( item.label ?? '' ) }
						placeholder={ placeholder }
						onChange={ ( value: string ) =>
							patch( index, { label: value } )
						}
					/>
					{ withLanguage && (
						<TextControl
							__nextHasNoMarginBottom
							label={ __( 'Language code', 'imagina-player' ) }
							value={ String( item.srclang ?? '' ) }
							placeholder="en"
							help={ __(
								'Remembered for the viewer: the next video in this language starts in it.',
								'imagina-player'
							) }
							onChange={ ( value: string ) =>
								patch( index, { srclang: value } )
							}
						/>
					) }
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'File address', 'imagina-player' ) }
						value={ String( item.src ?? '' ) }
						type="url"
						onChange={ ( value: string ) =>
							// Typed by hand: no longer the library's file.
							patch( index, { src: value, id: 0 } )
						}
					/>
					<div className="imgp-editor__media-picker">
						<MediaUploadCheck>
							<MediaUpload
								allowedTypes={ [ type ] }
								onSelect={ ( media: {
									id?: number;
									url?: string;
								} ) =>
									patch( index, {
										src: media.url ?? '',
										id: media.id ?? 0,
									} )
								}
								render={ ( { open }: { open: () => void } ) => (
									<Button
										variant="secondary"
										onClick={ open }
									>
										{ item.src
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
								onChange(
									items.filter( ( _item, i ) => i !== index )
								)
							}
						>
							{ __( 'Remove', 'imagina-player' ) }
						</Button>
					</div>
				</div>
			) ) }

			{ items.length < 8 && (
				<Button
					variant="secondary"
					onClick={ () =>
						onChange( [
							...items,
							{
								src: '',
								id: 0,
								label: '',
								...( withLanguage ? { srclang: '' } : {} ),
							},
						] )
					}
				>
					{ addLabel }
				</Button>
			) }
		</>
	);
}

export function SourcesPanel( {
	attributes,
	setAttributes,
	isStream,
}: SourcesPanelProps ) {
	const sources = ( attributes.sources ?? [] ) as Choice[];
	const audioTracks = ( attributes.audioTracks ?? [] ) as Choice[];

	return (
		<PanelBody
			title={ __( 'Qualities and languages', 'imagina-player' ) }
			initialOpen={ false }
		>
			{ isStream ? (
				<p className="imgp-editor__hint">
					{ __(
						'A stream offers its own qualities, and its own languages when it carries them.',
						'imagina-player'
					) }
				</p>
			) : (
				<>
					<p className="imgp-editor__hint">
						{ __(
							'The same video in other sizes. The viewer picks one under the quality button, and the choice is remembered.',
							'imagina-player'
						) }
					</p>
					<TextControl
						__nextHasNoMarginBottom
						label={ __(
							'Name of the main file',
							'imagina-player'
						) }
						value={ String( attributes.qualityLabel ?? '' ) }
						placeholder={ __( 'Original', 'imagina-player' ) }
						help={ __(
							'As it appears in the menu, such as 1080p.',
							'imagina-player'
						) }
						onChange={ ( value: string ) =>
							setAttributes( { qualityLabel: value } )
						}
					/>
					<ChoiceList
						items={ sources }
						type="video"
						placeholder="720p"
						addLabel={ __( 'Add a quality', 'imagina-player' ) }
						onChange={ ( next ) =>
							setAttributes( { sources: next } )
						}
					/>
				</>
			) }

			<hr />

			<p className="imgp-editor__hint">
				{ __(
					'Another language for the same video: an audio file as long as the video, played in step with the picture in place of its own sound. The viewer picks it in the settings menu.',
					'imagina-player'
				) }
			</p>
			<TextControl
				__nextHasNoMarginBottom
				label={ __(
					'Name of the video’s own sound',
					'imagina-player'
				) }
				value={ String( attributes.audioLabel ?? '' ) }
				placeholder={ __( 'Original', 'imagina-player' ) }
				onChange={ ( value: string ) =>
					setAttributes( { audioLabel: value } )
				}
			/>
			<ChoiceList
				items={ audioTracks }
				type="audio"
				placeholder={ __( 'English', 'imagina-player' ) }
				withLanguage
				addLabel={ __( 'Add a soundtrack', 'imagina-player' ) }
				onChange={ ( next ) => setAttributes( { audioTracks: next } ) }
			/>
		</PanelBody>
	);
}
