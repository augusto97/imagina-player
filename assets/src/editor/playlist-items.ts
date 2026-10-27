/**
 * The playlist block's list, as data: what the editor does to it, without the
 * editor. Kept apart so `tests/test-editor-logic.php` can run it.
 */

export interface Item {
	id?: number;
	src?: string;
	title?: string;
	artist?: string;
	thumbnail?: string;
	duration?: number;
}

/** What the media library hands back for one selected file. */
export interface Selected {
	id?: number;
	url?: string;
	title?: string;
	/** "3:14", as the library formats it. */
	fileLength?: string;
	image?: { src?: string };
	meta?: { artist?: string; length_formatted?: string };
}

/**
 * Seconds from "1:02:03", "3:14" or "75"; zero for anything else.
 * @param written
 */
export function seconds( written: string | undefined ): number {
	if ( ! written || ! /^\d+(:\d{1,2}){0,2}$/.test( written.trim() ) ) {
		return 0;
	}

	return written
		.trim()
		.split( ':' )
		.reduce( ( total, part ) => total * 60 + Number( part ), 0 );
}

/**
 * The other way: "3:14" from 194.
 * @param total
 */
export function clock( total: number ): string {
	if ( ! total ) {
		return '';
	}

	const hours = Math.floor( total / 3600 );
	const minutes = Math.floor( ( total % 3600 ) / 60 );
	const rest = String( Math.round( total % 60 ) ).padStart( 2, '0' );

	return hours
		? `${ hours }:${ String( minutes ).padStart( 2, '0' ) }:${ rest }`
		: `${ minutes }:${ rest }`;
}

export function toItem( media: Selected ): Item {
	return {
		id: media.id ?? 0,
		src: media.url ?? '',
		title: media.title ?? '',
		artist: media.meta?.artist ?? '',
		// The library's own thumbnail, which for audio is the embedded cover
		// art WordPress extracted on upload.
		thumbnail: media.image?.src ?? '',
		duration: seconds( media.fileLength ?? media.meta?.length_formatted ),
	};
}

/**
 * A new selection from the library, merged into the list rather than
 * replacing it.
 *
 * "Edit tracks" used to rebuild every item from the library: titles the
 * author had typed were lost, the order went back to the order of picking,
 * and addresses added by hand vanished. Now what stays keeps its place and
 * its edits, what was picked is added at the end, and what was left out of
 * the selection goes.
 * @param items
 * @param media
 */
export function merge( items: Item[], media: Selected[] ): Item[] {
	const picked = new Set( media.map( ( one ) => Number( one.id ?? 0 ) ) );
	const kept = items.filter(
		( item ) => ! item.id || picked.has( Number( item.id ) )
	);
	const present = new Set( kept.map( ( item ) => Number( item.id ?? 0 ) ) );

	return [
		...kept,
		...media
			.filter( ( one ) => ! present.has( Number( one.id ?? 0 ) ) )
			.map( toItem ),
	];
}
