/**
 * What the block made of the address it was given.
 *
 * Two things, in two places, for one reason: the block canvas is where an
 * author looks to see the post. Anything printed there reads as content that
 * will be published, so a remark about how the block works does not belong in
 * it — it belongs in the sidebar, with the rest of the block's settings.
 *
 * A fault is different. An address the player cannot play will publish as a
 * broken player, and telling the author only in a panel they may never open is
 * how that reaches the live site. That one stays in the canvas.
 */

import { ExternalLink, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { identify, placement } from '../shared/source';

interface Props {
	src: string;
	/** True for the video block, whose expectations are narrower. */
	isVideoBlock: boolean;
}

/**
 * The sidebar half: what this address is, beside the fields that describe it.
 *
 * @param props     Component props.
 * @param props.src The address the block holds.
 */
export function SourceStatus( { src }: Pick< Props, 'src' > ) {
	if ( 'sidebar' !== placement( src ) ) {
		return null;
	}

	const name = 'youtube' === identify( src ).kind ? 'YouTube' : 'Vimeo';

	return (
		<div className="imgp-editor__source">
			<p className="imgp-editor__source-line">
				<span className="imgp-editor__source-badge">{ name }</span>
				{ sprintf(
					/* translators: %s: YouTube or Vimeo. */
					__( 'This video is hosted by %s.', 'imagina-player' ),
					name
				) }
			</p>
			<p className="imgp-editor__hint">
				{ __(
					'It plays here with your own controls. Nothing is requested from them until a visitor presses play, and because the file is not on your site the download protection does not cover it.',
					'imagina-player'
				) }
			</p>
		</div>
	);
}

/**
 * The canvas half: only when the block will publish something broken.
 *
 * @param props              Component props.
 * @param props.src          The address the block holds.
 * @param props.isVideoBlock Whether this is the video block.
 */
export function SourceWarning( { src, isVideoBlock }: Props ) {
	if ( 'canvas' !== placement( src ) ) {
		return null;
	}

	return (
		<Notice status="warning" isDismissible={ false }>
			<p>
				{ isVideoBlock
					? __(
							'This address is not something the player can show. It handles YouTube and Vimeo links, MP4 and WebM files, and HLS streams (.m3u8).',
							'imagina-player'
					  )
					: __(
							'This address is not something the player can play. It handles audio and video files, HLS streams (.m3u8), and YouTube and Vimeo links.',
							'imagina-player'
					  ) }
			</p>
			<p className="imgp-editor__hint">
				{ __(
					'A page or a channel is not a video. For YouTube the address of a single video is the one with watch?v= in it, or a youtu.be link.',
					'imagina-player'
				) }{ ' ' }
				<ExternalLink href={ src }>
					{ __( 'Open what you pasted', 'imagina-player' ) }
				</ExternalLink>
			</p>
		</Notice>
	);
}
