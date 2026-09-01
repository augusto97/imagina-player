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

import { reason } from '../shared/failure';
import { canMeasure, measure } from '../shared/measure';
import type { MeasureProgress } from '../shared/measure';

/** Bars to measure. Matches the shipped default resolution. */
const BARS = 400;

interface Track {
	/** Zero for a track pasted from a streaming provider. */
	id: number;
	src: string;
	/** Whether a waveform is stored at all. */
	hasPeaks: boolean;
	/** Whether it was measured the way this version measures. */
	current: boolean;
}

interface Props {
	/** Library files to check. Zeroes are ignored. */
	attachmentIds?: number[];
	/** Addresses to check, for tracks that are not in the library. */
	urls?: string[];
	/** Videos draw no waveform, so a missing one is not a problem to report. */
	disabled?: boolean;
	/** Called after at least one waveform has been stored. */
	onMeasured: () => void;
}

export function WaveformNotice( {
	attachmentIds = [],
	urls = [],
	disabled,
	onMeasured,
}: Props ) {
	const [ missing, setMissing ] = useState< Track[] >( [] );
	const [ old, setOld ] = useState< Track[] >( [] );
	const [ done, setDone ] = useState< Track[] >( [] );
	const [ status, setStatus ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const ids = attachmentIds.filter( Boolean ).join( ',' );

	/*
	 * Newline-separated, not comma-separated: a URL may legally contain a
	 * comma, and splitting on one would cut somebody's signed link in half.
	 */
	const addresses = urls.filter( Boolean ).join( '\n' );
	const signature = ids + '|' + addresses;

	useEffect( () => {
		if ( disabled || '|' === signature ) {
			setMissing( [] );
			setOld( [] );
			setDone( [] );

			return;
		}

		let cancelled = false;

		const query = new URLSearchParams();

		if ( ids ) {
			query.set( 'ids', ids );
		}

		if ( addresses ) {
			query.set( 'urls', addresses );
		}

		apiFetch( {
			path: '/imagina-player/v1/peaks/status?' + query.toString(),
		} )
			.then( ( result ) => {
				if ( cancelled ) {
					return;
				}

				const { tracks } = result as { tracks: Track[] };

				setMissing( tracks.filter( ( track ) => ! track.hasPeaks ) );

				/*
				 * A waveform that exists but was measured an older way. It is
				 * drawn, and it can be better, so this is an offer rather than
				 * a warning.
				 */
				setOld(
					tracks.filter(
						( track ) => track.hasPeaks && ! track.current
					)
				);

				setDone(
					tracks.filter(
						( track ) => track.hasPeaks && track.current
					)
				);
			} )
			.catch( () => {
				// Asking failed. Saying nothing is better than crying wolf.
				if ( ! cancelled ) {
					setMissing( [] );
					setOld( [] );
					setDone( [] );
				}
			} );

		return () => {
			cancelled = true;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ signature, disabled ] );

	const tracks = missing.length + old.length + done.length;

	if ( disabled || 0 === tracks ) {
		return null;
	}

	/**
	 * Measure a list of tracks and store what comes back.
	 *
	 * Takes the list rather than reading one: the same work is offered three
	 * ways now — files with no waveform, files whose waveform was measured an
	 * older way, and a plain "do it again" for the rest, which is what somebody
	 * goes looking for when a picture looks wrong and nothing on screen admits
	 * that measuring is a thing that can be asked for.
	 *
	 * @param list Which tracks to measure.
	 */
	const run = async ( list: Track[] ): Promise< void > => {
		setBusy( true );

		let stored = 0;
		const failed: string[] = [];
		const reasons: string[] = [];

		for ( let i = 0; i < list.length; i++ ) {
			const track = list[ i ];

			const report = ( progress: MeasureProgress ): void =>
				setStatus( label( progress, i + 1, list.length ) );

			try {
				let result;

				try {
					result = await measure( track.src, BARS, report );
				} catch ( direct ) {
					/*
					 * Almost always CORS: the file is on another domain and
					 * that domain has not said this one may read it. Nothing
					 * about the file is wrong, so rather than give up, ask our
					 * own server to fetch it and hand it over same-origin.
					 */
					try {
						result = await measure(
							proxied( track.src ),
							BARS,
							report
						);
					} catch ( viaProxy ) {
						/*
						 * Both ways failed, and the one worth reporting is the
						 * second.
						 *
						 * The direct attempt failing is expected for any file on
						 * another domain — that is the whole reason the doorway
						 * exists — so reporting it says "cross-origin refusal",
						 * which is true and useless: it names the thing that was
						 * supposed to be worked around rather than the reason
						 * the workaround did not work.
						 */
						void direct;
						throw viaProxy;
					}
				}

				await apiFetch( {
					path: '/imagina-player/v1/peaks/store',
					method: 'POST',
					data: {
						attachmentId: track.id,
						src: track.src,
						peaks: result.peaks,
						duration: result.duration,
					},
				} );

				stored++;
			} catch ( error ) {
				// One file that will not decode should not stop the others —
				// but why it failed is kept, because "some files could not be
				// measured" is not something anybody can act on.
				failed.push( track.src );
				reasons.push( reason( error ) );
			}
		}

		setBusy( false );

		/*
		 * Whatever was measured moves out of the two lists that offer work and
		 * into the one that does not, so the notice reflects what just happened
		 * without asking the server all over again.
		 */
		const kept = ( current: Track[] ): Track[] =>
			current.filter( ( track ) => failed.includes( track.src ) );

		const moved = ( current: Track[] ): Track[] =>
			current.filter( ( track ) => ! failed.includes( track.src ) );

		setDone( ( current ) => [
			...current.filter( ( track ) => ! list.includes( track ) ),
			...moved( list ),
		] );
		setMissing( kept );
		setOld( kept );
		setStatus(
			0 === failed.length
				? ''
				: sprintf(
						/* translators: 1: how many files, 2: why the first one failed */
						_n(
							'%1$d file could not be measured here: %2$s',
							'%1$d files could not be measured here. The first: %2$s',
							failed.length,
							'imagina-player'
						),
						failed.length,
						reasons[ 0 ] ?? ''
				  )
		);

		if ( stored > 0 ) {
			onMeasured();
		}
	};

	if ( ! canMeasure() ) {
		return missing.length > 0 ? (
			<Notice status="warning" isDismissible={ false }>
				<p>
					{ __(
						'This browser cannot measure audio.',
						'imagina-player'
					) }
				</p>
			</Notice>
		) : null;
	}

	const working = busy ? status || __( 'Working…', 'imagina-player' ) : '';

	/*
	 * Three offers, and the third is the one that was missing.
	 *
	 * Before this the whole component disappeared the moment every track had a
	 * waveform, so a picture that looked wrong had nowhere to be questioned
	 * from: measuring was something the editor did to you when it felt a file
	 * was lacking, and never something you could ask for. Which is exactly what
	 * was reported — somebody went looking for the button, took the audio out
	 * and put it back to try to provoke it, and there was nothing.
	 */
	return (
		<>
			{ missing.length > 0 && (
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

					<p>
						<Button
							variant="primary"
							onClick={ () => run( missing ) }
							disabled={ busy }
						>
							{ working ||
								_n(
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
				</Notice>
			) }

			{ old.length > 0 && (
				<Notice status="info" isDismissible={ false }>
					<p>
						{ sprintf(
							/* translators: %d: number of files measured an older way. */
							_n(
								'%d waveform here was measured an older way, which draws long recordings almost flat.',
								'%d waveforms here were measured an older way, which draws long recordings almost flat.',
								old.length,
								'imagina-player'
							),
							old.length
						) }
					</p>

					<p>
						<Button
							variant="primary"
							onClick={ () => run( old ) }
							disabled={ busy }
						>
							{ working ||
								_n(
									'Measure it again',
									'Measure them again',
									old.length,
									'imagina-player'
								) }
						</Button>
					</p>
				</Notice>
			) }

			{ done.length > 0 && (
				/*
				 * A button, not a link.
				 *
				 * As a link it sat above the first field in the panel looking
				 * like a caption, and was reported as easy to miss — which it
				 * was: nothing about blue underlined text in a column of form
				 * controls says "this does something". The other two offers in
				 * this component are buttons; there was no reason this one was
				 * not, beyond it being the quiet case.
				 */
				<div className="imgp-editor__remeasure">
					<Button
						variant="secondary"
						onClick={ () => run( done ) }
						disabled={ busy }
					>
						{ working ||
							_n(
								'Measure this waveform again',
								'Measure these waveforms again',
								done.length,
								'imagina-player'
							) }
					</Button>

					<p className="imgp-editor__hint">
						{ __(
							'Only needed if the shape looks wrong, or after changing the number of bars.',
							'imagina-player'
						) }
					</p>
				</div>
			) }

			{ ! busy && '' !== status && (
				<p className="imgp-editor__hint">{ status }</p>
			) }
		</>
	);
}

/**
 * The same file, fetched through this site rather than directly.
 *
 * @param src The remote address.
 */
function proxied( src: string ): string {
	const root = ( window.wpApiSettings?.root ?? '/wp-json/' ).replace(
		/\/$/,
		'/'
	);

	return (
		root +
		'imagina-player/v1/peaks/proxy?src=' +
		encodeURIComponent( src ) +
		'&_wpnonce=' +
		encodeURIComponent( window.wpApiSettings?.nonce ?? '' )
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

declare global {
	interface Window {
		/** Printed by WordPress on any screen that loads the REST client. */
		wpApiSettings?: { root?: string; nonce?: string };
	}
}
