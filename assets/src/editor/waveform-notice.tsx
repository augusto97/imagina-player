/**
 * The one place an author can find out their waveform is missing, and fix it.
 *
 * Before this, a track with no stored waveform looked fine in the editor — the
 * preview drew a synthetic one — and showed a flat bar on the live site. The
 * author had no way of knowing until a visitor told them.
 *
 * So the preview no longer lies, and this says what is wrong and offers the
 * only thing that will actually help on a host with no ffmpeg: measure it
 * here, once, in this browser, and store it for everyone.
 */

import apiFetch from '@wordpress/api-fetch';
import { Button, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { canMeasure, measure } from '../shared/measure';
import type { MeasureProgress } from '../shared/measure';

interface Props {
	attachmentId: number;
	hasPeaks: boolean;
	isVideo: boolean;
	onMeasured: () => void;
}

export function WaveformNotice( {
	attachmentId,
	hasPeaks,
	isVideo,
	onMeasured,
}: Props ) {
	const [ status, setStatus ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ done, setDone ] = useState( false );

	// A video draws no waveform, so a missing one is not a problem to report.
	// Nor is a file that is not in the library: there is nothing to store it
	// against, and nothing to measure it from reliably.
	if ( isVideo || hasPeaks || done || ! attachmentId ) {
		return null;
	}

	const run = async (): Promise< void > => {
		setBusy( true );
		setStatus( __( 'Starting…', 'imagina-player' ) );

		try {
			const url = ( await apiFetch( {
				path: '/wp/v2/media/' + attachmentId,
			} ) ) as { source_url?: string };

			if ( ! url.source_url ) {
				throw new Error( 'no-url' );
			}

			const result = await measure(
				url.source_url,
				400,
				( progress: MeasureProgress ) => {
					if ( 'decoding' === progress.stage ) {
						setStatus( __( 'Measuring…', 'imagina-player' ) );

						return;
					}

					setStatus(
						progress.ratio < 0
							? __( 'Downloading…', 'imagina-player' )
							: sprintf(
									/* translators: %d: percentage downloaded. */
									__( 'Downloading… %d%%', 'imagina-player' ),
									Math.round( progress.ratio * 100 )
							  )
					);
				}
			);

			await apiFetch( {
				path: '/imagina-player/v1/peaks/store',
				method: 'POST',
				data: {
					attachmentId,
					peaks: result.peaks,
					duration: result.duration,
				},
			} );

			setDone( true );
			onMeasured();
		} catch {
			setStatus(
				__(
					'That could not be measured here. The file may be too long for this browser, or served from somewhere it cannot be read.',
					'imagina-player'
				)
			);
		} finally {
			setBusy( false );
		}
	};

	return (
		<Notice status="warning" isDismissible={ false }>
			<p>
				{ __(
					'This track has no waveform stored, so it will show a plain progress bar on your site.',
					'imagina-player'
				) }
			</p>

			{ canMeasure() ? (
				<p>
					<Button variant="primary" onClick={ run } disabled={ busy }>
						{ busy
							? status
							: __(
									'Measure it in this browser',
									'imagina-player'
							  ) }
					</Button>{ ' ' }
					<span className="imgp-editor__hint">
						{ __(
							'Downloads the file once, here, and stores the result for every visitor.',
							'imagina-player'
						) }
					</span>
				</p>
			) : (
				<p>
					{ __(
						'This browser cannot measure audio.',
						'imagina-player'
					) }
				</p>
			) }

			{ ! busy && '' !== status && (
				<p className="imgp-editor__hint">{ status }</p>
			) }
		</Notice>
	);
}
