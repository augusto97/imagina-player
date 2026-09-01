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
	/** Zero for a track pasted from a streaming provider. */
	id: number;
	src: string;
	hasPeaks: boolean;
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
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ signature, disabled ] );

	if ( disabled || 0 === missing.length ) {
		return null;
	}

	const run = async (): Promise< void > => {
		setBusy( true );

		let done = 0;
		const failed: string[] = [];
		const reasons: string[] = [];

		for ( let i = 0; i < missing.length; i++ ) {
			const track = missing[ i ];

			const report = ( progress: MeasureProgress ): void =>
				setStatus( label( progress, i + 1, missing.length ) );

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
						 * Both ways failed. Which one to report is the direct
						 * attempt: the proxy's failure is usually just the same
						 * problem again, and the first message is the one that
						 * says what is actually wrong with the file.
						 */
						throw direct;
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

				done++;
			} catch ( error ) {
				// One file that will not decode should not stop the others —
				// but why it failed is kept, because "some files could not be
				// measured" is not something anybody can act on.
				failed.push( track.src );
				reasons.push( reason( error ) );
			}
		}

		setBusy( false );
		setMissing( ( current ) =>
			current.filter( ( track ) => failed.includes( track.src ) )
		);
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
 * The same file, fetched through this site rather than directly.
 *
 * @param src The remote address.
 */
/**
 * What went wrong, in words somebody can act on.
 *
 * The failures here have different answers — a file the browser cannot reach
 * is a server setting, a file it cannot decode is a format problem, and a file
 * that runs out of memory is neither — and they were all reported as "some
 * files could not be measured", which tells you nothing at all.
 *
 * @param error Whatever was thrown.
 */
function reason( error: unknown ): string {
	const message =
		error instanceof Error ? error.message : String( error ?? '' );

	if ( message.startsWith( 'fetch-failed' ) ) {
		return sprintf(
			/* translators: %s: HTTP status code, or "?" when there was none. */
			__(
				'the server answered %s when asked for the file',
				'imagina-player'
			),
			message.replace( 'fetch-failed-', '' ) || '?'
		);
	}

	if ( 'length-mismatch' === message ) {
		return __(
			'the download stopped early — the file may be behind something that cuts long transfers off',
			'imagina-player'
		);
	}

	if ( 'no-audio-context' === message ) {
		return __( 'this browser cannot decode audio', 'imagina-player' );
	}

	if ( 'decode-failed' === message ) {
		return __( 'nothing in the file decoded as audio', 'imagina-player' );
	}

	if ( error instanceof DOMException || /decode/i.test( message ) ) {
		return __(
			'the browser could not decode it — check that the file plays',
			'imagina-player'
		);
	}

	/*
	 * A network error with no status is what a cross-origin refusal looks like
	 * from here: the browser will not say more than "failed", on purpose.
	 */
	return __(
		'the browser could not read it, which is usually a cross-origin refusal',
		'imagina-player'
	);
}

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
