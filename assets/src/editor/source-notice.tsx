/**
 * What the block makes of the address it was given.
 *
 * This exists because of a report that reads badly and was entirely fair: a
 * YouTube address pasted into the video block produced an audio player that
 * showed nothing, played nothing, and had no thumbnail. YouTube was never
 * supported — but nothing anywhere said so, so the only way to find out was to
 * publish the page and look at it.
 *
 * Now the block says what it recognised, before saving. When it recognises
 * nothing, it says that too, which is the case that was silent before.
 */

import { ExternalLink, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { identify } from '../shared/source';

interface Props {
	src: string;
	/** True for the video block, whose expectations are narrower. */
	isVideoBlock: boolean;
}

export function SourceNotice( { src, isVideoBlock }: Props ) {
	if ( ! src.trim() ) {
		return null;
	}

	const source = identify( src );

	if ( 'youtube' === source.kind || 'vimeo' === source.kind ) {
		const name = 'youtube' === source.kind ? 'YouTube' : 'Vimeo';

		return (
			<Notice status="success" isDismissible={ false }>
				<p>
					{ sprintf(
						/* translators: %s: YouTube or Vimeo. */
						__(
							'A video from %s. It plays in this player, with your own controls — the picture and the sound come from them.',
							'imagina-player'
						),
						name
					) }
				</p>
				<p className="imgp-editor__hint">
					{ __(
						'Nothing is requested from them until a visitor presses play, so the video costs your page nothing until it is watched. It is also not a file on your site, so the download protection does not apply to it.',
						'imagina-player'
					) }
				</p>
			</Notice>
		);
	}

	/*
	 * A file or a stream. Nothing to say — the player has always handled these
	 * and a notice on every well-formed block is just noise.
	 */
	if ( 'file' === source.kind || 'hls' === source.kind ) {
		return null;
	}

	// Anything left is an address this cannot play, which used to be silent.
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
