/**
 * A file named by a custom field of the post the block is shown on.
 *
 * For a template: one block, and every product or post supplies its own
 * file. Its own component because it is offered in two places — beside the
 * other Media settings once a file is chosen, and on its own before one is,
 * since in a template there is no file to choose.
 */

import { PanelBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

interface DynamicSourceProps {
	/** The custom field the block names, or an empty string. */
	sourceField: string;
	/** The block's own file, which is the default where the field is empty. */
	src: string;
	setAttributes: ( attributes: Record< string, unknown > ) => void;
}

export function DynamicSourcePanel( {
	sourceField,
	src,
	setAttributes,
}: DynamicSourceProps ) {
	return (
		<PanelBody
			title={ __( 'Dynamic source', 'imagina-player' ) }
			initialOpen={ !! sourceField }
		>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Custom field key', 'imagina-player' ) }
				value={ sourceField }
				placeholder="video_url"
				help={ __(
					'The name of a custom field on the post this block is shown on — an ACF or JetEngine field, or any post meta — whose value is the file’s address or its media library ID. In a product template, each product supplies its own file. A key starting with an underscore is hidden meta and is not read.',
					'imagina-player'
				) }
				onChange={ ( value: string ) =>
					setAttributes( { sourceField: value.trim() } )
				}
			/>
			{ sourceField && (
				<p className="imgp-editor__hint">
					{ src
						? __(
								'The block’s own file is shown only where the field is empty.',
								'imagina-player'
						  )
						: __(
								'Where the field is empty, visitors see nothing — unless the block is also given a file of its own.',
								'imagina-player'
						  ) }
				</p>
			) }
		</PanelBody>
	);
}
