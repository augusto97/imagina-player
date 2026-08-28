/**
 * The one place an author finds out a waveform is missing, and fixes it.
 *
 * Before this, a track with none looked fine in the editor — the preview drew a
 * synthetic one — and showed a flat bar on the live site. There was nowhere to
 * find out and nowhere to act; the only route was the settings screen, which
 * meant leaving the post after every upload.
 *
 * So it belongs here, next to the file that has the problem, and it handles a
 * list as well as a single track: a playlist is where several files arrive at
 * once, which is exactly when nobody wants to go and press a button somewhere
 * else five times.
 */

import apiFetch from '@wordpress/api-fetch';
import { Button, Notice } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { canMeasure, measure } from '../shared/measure';
import type { MeasureProgress } from '../shared/measure';

/** Bars to measure. Matches the shipped default resolution. */
const BARS = 400;

interface Track {
	id: number;
	hasPeaks: boolean;
	url: string;
}

interface Props {
	/** The files to check. Empty, or all zeroes, renders nothing. */
	attachmentIds: number[];
	/** Videos draw no waveform, so a missing one is not a problem to report. */
	disabled?: boolean;
	/** Called after at least one waveform has been stored. */
	onMeasured: () => void;
}

export function WaveformNotice( {
	attachmentIds,
	disabled,
	onMeasured,
}: Props ) {
	const [ missing, setMissing ] = useState< Track[] >( [] );
	const [ status, setStatus ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const ids = attachmentIds.filter( Boolean );
	const signature = ids.join( ',' );

	useEffect( () => {
		if ( disabled || '' === signature ) {
			setMissing( [] );

			return;
		}

		let cancelled = false;

		apiFetch( { path: '/imagina-player/v1/peaks/status?ids=' + signature } )
			.then( ( result ) => {
				if ( cancelled ) {
					return;
				}

				const { tracks } = result as { tracks: Track[] };

				setMissing( tracks.filter( ( track ) => ! track.hasPeaks ) );
			} )
			.catch( () => {
				// Asking failed. Saying nothing is better than crying wolf.
				if ( ! cancelled ) {
					setMissing( [] );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ signature, disabled ] );

	if ( disabled || 0 === missing.length ) {
		return null;
	}

	const run = async (): Promise< void > => {
		setBusy( true );

		let done = 0;
		const failed: string[] = [];

		for ( let i = 0; i < missing.length; i++ ) {
			const track = missing[ i ];

			try {
				const result = await measure(
					track.url,
					BARS,
					( progress: MeasureProgress ) =>
						setStatus( label( progress, i + 1, missing.length ) )
				);

				await apiFetch( {
					path: '/imagina-player/v1/peaks/store',
					method: 'POST',
					data: {
						attachmentId: track.id,
						peaks: result.peaks,
						duration: result.duration,
					},
				} );

				done++;
			} catch {
				// One file that will not decode should not stop the others.
				failed.push( String( track.id ) );
			}
		}

		setBusy( false );
		setMissing( ( current ) =>
			current.filter( ( track ) => failed.includes( String( track.id ) ) )
		);
		setStatus(
			0 === failed.length
				? ''
				: __(
						'Some files could not be measured here. They may be too long for this browser, or served from somewhere it cannot read them.',
						'imagina-player'
				  )
		);

		if ( done > 0 ) {
			onMeasured();
		}
	};

	return (
		<Notice status="warning" isDismissible={ false }>
			<p>
				{ sprintf(
					/* translators: %d: number of files with no waveform. */
					_n(
						'%d file here has no waveform, so it will show a plain progress bar on your site.',
						'%d files here have no waveform, so they will show a plain progress bar on your site.',
						missing.length,
						'imagina-player'
					),
					missing.length
				) }
			</p>

			{ canMeasure() ? (
				<p>
					<Button variant="primary" onClick={ run } disabled={ busy }>
						{ busy
							? status || __( 'Working…', 'imagina-player' )
							: _n(
									'Generate it now',
									'Generate them now',
									missing.length,
									'imagina-player'
							  ) }
					</Button>{ ' ' }
					<span className="imgp-editor__hint">
						{ __(
							'Measured here, in this browser, and stored for every visitor. Nobody browsing your site downloads anything extra.',
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

/**
 * What is happening, said in the button.
 *
 * A ninety-megabyte download in silence reads as a hang, so the percentage is
 * worth the noise.
 *
 * @param progress Where the measuring has got to.
 * @param index    Which file, counting from one.
 * @param total    How many there are.
 */
function label(
	progress: MeasureProgress,
	index: number,
	total: number
): string {
	const what =
		'decoding' === progress.stage
			? __( 'measuring', 'imagina-player' )
			: sprintf(
					/* translators: %d: percentage downloaded. */
					__( 'downloading %d%%', 'imagina-player' ),
					Math.max( 0, Math.round( progress.ratio * 100 ) )
			  );

	if ( total < 2 ) {
		return what;
	}

	return sprintf(
		/* translators: 1: current file number, 2: total files, 3: what is happening */
		__( '%1$d of %2$d — %3$s', 'imagina-player' ),
		index,
		total,
		what
	);
}
