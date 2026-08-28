import { registerBlockType } from '@wordpress/blocks';

import { Edit } from './edit';
import { PlaylistEdit } from './playlist';
import './editor.scss';

/**
 * Title, category, icon and the attribute schema all come from the server-side
 * registration, so only the editor half is declared here. The cast keeps
 * TypeScript from insisting on metadata that WordPress has already supplied.
 */
type BlockSettings = Parameters< typeof registerBlockType >[ 1 ];

registerBlockType( 'imagina/audio-player', {
	edit: Edit,
	// Dynamic block: the server renders the markup so a preset change reaches
	// every published player without re-saving posts.
	save: () => null,
} as unknown as BlockSettings );

registerBlockType( 'imagina/playlist', {
	edit: PlaylistEdit,
	save: () => null,
} as unknown as BlockSettings );
