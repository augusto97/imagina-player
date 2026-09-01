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

	/*
	 * The doorway on this site tried and was refused by the file's own server.
	 * The most common cause by a distance is hotlink protection or a signed-URL
	 * rule on a bucket or CDN, and no setting here can change that — so the
	 * message points at the place that can.
	 */
	if ( message.startsWith( 'proxy-upstream-' ) ) {
		const status = message.replace( 'proxy-upstream-', '' );

		if ( 'unreachable' === status ) {
			return __(
				'this site could not reach the file’s own server',
				'imagina-player'
			);
		}

		return sprintf(
			/* translators: %s: HTTP status the remote server returned. */
			__(
				'the server hosting the file answered %s to this site as well — check that domain’s hotlink protection or signed-link rules',
				'imagina-player'
			),
			status
		);
	}

	if ( 'proxy-not-media' === message ) {
		return __(
			'the address does not return an audio or video file',
			'imagina-player'
		);
	}

	if ( 'proxy-too-large' === message ) {
		return __(
			'the file is larger than this site will fetch on your behalf',
			'imagina-player'
		);
	}

	if ( 'proxy-bad-url' === message ) {
		return __(
			'that address was refused as unsafe to fetch',
			'imagina-player'
		);
	}

	if ( message.startsWith( 'fetch-failed' ) ) {
		const status = message.replace( 'fetch-failed-', '' );

		/*
		 * 401 and 403 from this site's own doorway is the nonce, not the file:
		 * the request reached WordPress and WordPress would not have it.
		 */
		if ( '401' === status || '403' === status ) {
			return __(
				'this site refused the request that fetches the file — reload the editor and try again',
				'imagina-player'
			);
		}

		/*
		 * A gateway error with none of this plugin's own reasons attached did
		 * not come from this plugin: every refusal it makes says which step
		 * gave up. Something between the browser and PHP answered instead — a
		 * firewall, a security plugin, a proxy, or the web server after PHP
		 * stopped.
		 *
		 * Which of those it is cannot be told apart from here, and guessing has
		 * already cost somebody an afternoon and a server setting they were
		 * warned not to change. So this names what is known and points at the
		 * one place that can see the rest.
		 */
		/*
		 * A gateway error carries none of this plugin's reasons for a second
		 * reason as well as the first: a web server may replace a 5xx from PHP
		 * with its own page, header and body alike. Refusals are sent as 4xx
		 * now so they survive that, which means a 5xx here really is somebody
		 * else's.
		 */
		if ( '502' === status || '504' === status || '503' === status ) {
			return sprintf(
				/* translators: %s: the HTTP status returned. */
				__(
					'something between the browser and WordPress answered %s — this plugin did not, because every refusal it makes says why. Settings → Imagina Player → Waveforms has a check that asks the server directly and reports what it finds.',
					'imagina-player'
				),
				status
			);
		}

		return sprintf(
			/* translators: %s: HTTP status code, or "?" when there was none. */
			__(
				'the server answered %s when asked for the file',
				'imagina-player'
			),
			status || '?'
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
