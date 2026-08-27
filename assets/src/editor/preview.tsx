/**
 * A static stand-in for the real player.
 *
 * The front-end bundle is not loaded inside the editor canvas iframe, so the
 * preview reproduces the same markup and class names — which the block's editor
 * stylesheet does load — and draws a decorative waveform instead of decoding the
 * file. It looks like the published player without pretending to be it.
 */

interface PreviewProps {
	title: string;
	artist: string;
	thumbnail: string;
	skin: string;
	showArtist: boolean;
	showTitle: boolean;
	showVolume: boolean;
	showTime: boolean;
	style: Record< string, string >;
}

const BARS = 96;

/**
 * A fixed pseudo-random silhouette: deterministic so the preview does not
 * reshuffle on every keystroke.
 */
function barHeights(): number[] {
	const out: number[] = [];

	for ( let i = 0; i < BARS; i++ ) {
		const wave = Math.sin( i * 0.35 ) * 0.25 + Math.sin( i * 1.7 ) * 0.2 + Math.sin( i * 0.11 ) * 0.3;

		out.push( Math.min( 1, Math.max( 0.12, 0.55 + wave ) ) );
	}

	return out;
}

const HEIGHTS = barHeights();

export function Preview( {
	title,
	artist,
	thumbnail,
	skin,
	showArtist,
	showTitle,
	showVolume,
	showTime,
	style,
}: PreviewProps ) {
	return (
		<div className={ `imgp imgp--skin-${ skin } imgp--preview` } style={ style }>
			{ 'minimal' !== skin && (
				<div className="imgp__scrubber">
					{ 'wave' === skin ? (
						<div className="imgp__wave-preview" aria-hidden="true">
							{ HEIGHTS.map( ( height, index ) => (
								<span
									// eslint-disable-next-line react/no-array-index-key
									key={ index }
									style={ { height: `${ height * 100 }%` } }
								/>
							) ) }
						</div>
					) : (
						<div className="imgp__track" aria-hidden="true">
							<div className="imgp__progress" style={ { transform: 'scaleX(0.35)' } } />
						</div>
					) }

					{ showTime && (
						<div className="imgp__seek">
							<span className="imgp__time imgp__time--current">0:00</span>
							<span className="imgp__time imgp__time--total">--:--</span>
						</div>
					) }
				</div>
			) }

			<div className="imgp__bar">
				{ thumbnail && (
					<div className="imgp__thumb">
						<img src={ thumbnail } alt="" width="72" height="72" />
					</div>
				) }

				<span className="imgp__play" role="presentation">
					<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true">
						<path d="M8 5.14v13.72L19 12z" />
					</svg>
				</span>

				{ ( showArtist || showTitle ) && (
					<div className="imgp__meta">
						{ showArtist && <span className="imgp__artist">{ artist }</span> }
						{ showTitle && <span className="imgp__title">{ title }</span> }
					</div>
				) }

				{ showVolume && (
					<div className="imgp__controls">
						<div className="imgp__volume">
							<span className="imgp__mute" role="presentation">
								<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true">
									<path d="M3 9v6h4l5 5V4L7 9H3z" />
								</svg>
							</span>
							<span className="imgp__volume-track" aria-hidden="true" />
						</div>
					</div>
				) }
			</div>
		</div>
	);
}
