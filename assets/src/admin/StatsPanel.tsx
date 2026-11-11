/**
 * How the site's players are watched.
 *
 * Counts, not people: plays, time watched, how far viewers got and which
 * offers they pressed, per player and page. The figures a site owner asks
 * for first — "is anybody watching", "where do they stop", "does the button
 * work" — and a spreadsheet of the rest.
 */

import { Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { dateI18n } from '@wordpress/date';

import { downloadStats, loadStats, loadStatsDetail } from './api';
import type { StatsDetail, StatsRow, StatsSummary } from './api';
import { Card, Field, Notice, NumberInput, Select, Toggle } from './controls';
import type { SettingsPayload } from './types';

interface PanelProps {
	settings: SettingsPayload;
	onChange: ( patch: Partial< SettingsPayload > ) => void;
}

const PERIODS = [ 7, 30, 90, 365 ];

/** Points of the file the table view of the audience lists. */
const MARKS = [ 0, 10, 25, 50, 75, 90, 99 ];

const number = ( value: number ): string =>
	new Intl.NumberFormat( undefined, {
		notation: value >= 10000 ? 'compact' : 'standard',
		maximumFractionDigits: 1,
	} ).format( value );

const percent = ( share: number ): string =>
	new Intl.NumberFormat( undefined, {
		style: 'percent',
		maximumFractionDigits: 0,
	} ).format( share );

const clock = ( seconds: number ): string => {
	const s = Math.max( 0, Math.round( seconds ) );
	const h = Math.floor( s / 3600 );
	const m = Math.floor( ( s % 3600 ) / 60 );
	const rest = String( s % 60 ).padStart( 2, '0' );

	return h > 0
		? `${ h }:${ String( m ).padStart( 2, '0' ) }:${ rest }`
		: `${ m }:${ rest }`;
};

/**
 * Time watched, in the unit that reads best.
 * @param seconds Seconds watched.
 */
const watched = ( seconds: number ): string =>
	seconds < 3600
		? sprintf(
				/* translators: %s: a number of minutes. */
				__( '%s min', 'imagina-player' ),
				number( Math.round( seconds / 60 ) )
		  )
		: sprintf(
				/* translators: %s: a number of hours. */
				__( '%s h', 'imagina-player' ),
				number( Math.round( seconds / 360 ) / 10 )
		  );

const day = ( value: string ): string =>
	// Midday, so no time zone moves it to the day before or after.
	dateI18n( 'j M', `${ value }T12:00:00Z`, undefined );

/**
 * How much of the file an average play covered.
 * @param row One player on one page.
 */
const averageShare = ( row: StatsRow ): number | null =>
	row.plays > 0 && row.duration > 0
		? Math.min( 1, row.seconds / row.plays / row.duration )
		: null;

/** The width a chart has to draw in, followed as the screen changes. */
function useWidth< T extends HTMLElement >(): [ React.RefObject< T >, number ] {
	const ref = useRef< T >( null );
	const [ width, setWidth ] = useState( 0 );

	useEffect( () => {
		const element = ref.current;

		if ( ! element ) {
			return;
		}

		setWidth( element.clientWidth );

		const observer = new ResizeObserver( () =>
			setWidth( element.clientWidth )
		);

		observer.observe( element );

		return () => observer.disconnect();
	}, [] );

	return [ ref, width ];
}

interface Point {
	value: number;
	/** What the point is, for the tooltip and the axis. */
	label: string;
	/** Its value as the reader should see it. */
	shown: string;
}

const PAD = { top: 12, right: 12, bottom: 24, left: 44 };

/**
 * One series over an even axis: a 2px line over a light wash, recessive
 * grid, and a crosshair that finds the nearest point — by pointer or by the
 * arrow keys.
 * @param root0             The chart.
 * @param root0.points      The points, evenly spaced.
 * @param root0.max         The value at the top of the plot.
 * @param root0.ticks       Values that get a gridline.
 * @param root0.tickLabel   How a gridline's value is written.
 * @param root0.labelEvery  How many points apart the axis labels are.
 * @param root0.description What the chart shows, for a screen reader.
 * @param root0.height      Its height in pixels.
 */
function LineChart( {
	points,
	max,
	ticks,
	tickLabel,
	labelEvery,
	description,
	height = 180,
}: {
	points: Point[];
	max: number;
	ticks: number[];
	tickLabel: ( value: number ) => string;
	/** Which points get a label on the axis. */
	labelEvery: number;
	description: string;
	height?: number;
} ) {
	const [ ref, width ] = useWidth< HTMLDivElement >();
	const [ active, setActive ] = useState< number | null >( null );

	const plotW = Math.max( 0, width - PAD.left - PAD.right );
	const plotH = height - PAD.top - PAD.bottom;
	const step = points.length > 1 ? plotW / ( points.length - 1 ) : 0;
	const x = ( index: number ): number => PAD.left + index * step;
	const y = ( value: number ): number =>
		PAD.top + plotH - ( max > 0 ? ( value / max ) * plotH : 0 );

	const line = points
		.map(
			( point, index ) =>
				`${ 0 === index ? 'M' : 'L' }${ x( index ).toFixed( 1 ) },${ y(
					point.value
				).toFixed( 1 ) }`
		)
		.join( '' );
	const area =
		points.length > 1
			? `${ line }L${ x( points.length - 1 ).toFixed( 1 ) },${
					PAD.top + plotH
			  }L${ PAD.left },${ PAD.top + plotH }Z`
			: '';

	const nearest = ( clientX: number, box: DOMRect ): number =>
		Math.min(
			points.length - 1,
			Math.max(
				0,
				Math.round(
					( clientX - box.left - PAD.left ) / Math.max( step, 1 )
				)
			)
		);

	const current = null === active ? null : points[ active ];

	// The first and last labels sit inside the plot rather than over its edge.
	const anchor = ( index: number ): 'start' | 'middle' | 'end' => {
		if ( 0 === index ) {
			return 'start';
		}

		return index === points.length - 1 ? 'end' : 'middle';
	};

	return (
		<div className="imgpa-chart" ref={ ref }>
			{ width > 0 && points.length > 0 && (
				<svg
					width={ width }
					height={ height }
					role="img"
					aria-label={ description }
					tabIndex={ 0 }
					onPointerMove={ ( event ) =>
						setActive(
							nearest(
								event.clientX,
								event.currentTarget.getBoundingClientRect()
							)
						)
					}
					onPointerLeave={ () => setActive( null ) }
					onBlur={ () => setActive( null ) }
					onKeyDown={ ( event ) => {
						if (
							'ArrowRight' !== event.key &&
							'ArrowLeft' !== event.key
						) {
							return;
						}

						event.preventDefault();
						setActive( ( index ) =>
							Math.min(
								points.length - 1,
								Math.max(
									0,
									( index ?? -1 ) +
										( 'ArrowRight' === event.key ? 1 : -1 )
								)
							)
						);
					} }
				>
					{ ticks.map( ( tick ) => (
						<g key={ tick }>
							<line
								className="imgpa-chart__grid"
								x1={ PAD.left }
								x2={ width - PAD.right }
								y1={ y( tick ) }
								y2={ y( tick ) }
							/>
							<text
								className="imgpa-chart__tick"
								x={ PAD.left - 8 }
								y={ y( tick ) }
								dy="0.32em"
								textAnchor="end"
							>
								{ tickLabel( tick ) }
							</text>
						</g>
					) ) }

					{ points.map( ( point, index ) =>
						0 === index % labelEvery ||
						index === points.length - 1 ? (
							<text
								key={ index }
								className="imgpa-chart__tick"
								x={ x( index ) }
								y={ height - 6 }
								textAnchor={ anchor( index ) }
							>
								{ point.label }
							</text>
						) : null
					) }

					<path className="imgpa-chart__area" d={ area } />
					<path className="imgpa-chart__line" d={ line } />

					{ null !== active && current && (
						<g>
							<line
								className="imgpa-chart__cross"
								x1={ x( active ) }
								x2={ x( active ) }
								y1={ PAD.top }
								y2={ PAD.top + plotH }
							/>
							<circle
								className="imgpa-chart__dot"
								cx={ x( active ) }
								cy={ y( current.value ) }
								r={ 4 }
							/>
						</g>
					) }
				</svg>
			) }

			{ null !== active && current && (
				<div
					className="imgpa-chart__tip"
					style={ {
						left: Math.min(
							Math.max( x( active ), 70 ),
							width - 70
						),
					} }
					role="status"
				>
					<div className="imgpa-chart__value">{ current.shown }</div>
					<div className="imgpa-chart__label">{ current.label }</div>
				</div>
			) }
		</div>
	);
}

/**
 * Round gridlines: zero, the middle and the top, on clean numbers.
 * @param peak The largest value drawn.
 */
function niceTicks( peak: number ): { max: number; ticks: number[] } {
	if ( peak <= 0 ) {
		return { max: 1, ticks: [ 0, 1 ] };
	}

	const magnitude = Math.pow( 10, Math.floor( Math.log10( peak ) ) );
	const unit =
		[ 1, 2, 2.5, 5, 10 ].find( ( n ) => n * magnitude * 2 >= peak ) ?? 10;
	const half = Math.max( 1, unit * magnitude );

	return { max: half * 2, ticks: [ 0, half, half * 2 ] };
}

function Detail( { row, days }: { row: StatsRow; days: number } ) {
	const [ detail, setDetail ] = useState< StatsDetail | null >( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let cancelled = false;

		loadStatsDetail( row.key, row.post, days )
			.then( ( result ) => ! cancelled && setDetail( result ) )
			.catch(
				() =>
					! cancelled &&
					setError(
						__( 'This could not be loaded.', 'imagina-player' )
					)
			);

		return () => {
			cancelled = true;
		};
	}, [ row.key, row.post, days ] );

	if ( error ) {
		return <Notice tone="warn">{ error }</Notice>;
	}

	if ( ! detail ) {
		return (
			<p className="imgpa-hint">{ __( 'Loading…', 'imagina-player' ) }</p>
		);
	}

	const viewings = detail.reach.reduce( ( sum, n ) => sum + n, 0 );
	const at = ( index: number ): string =>
		row.duration > 0
			? clock( ( index / 100 ) * row.duration )
			: percent( index / 100 );
	const half = detail.retention.findIndex( ( share ) => share < 0.5 );
	const clicks = Object.entries( detail.clicks )
		.map( ( [ index, count ] ) => ( {
			index: Number( index ),
			count,
		} ) )
		.filter( ( entry ) => entry.count > 0 )
		.sort( ( a, b ) => a.index - b.index );

	return (
		<div className="imgpa-stats__detail">
			<h3>{ __( 'Who was still watching', 'imagina-player' ) }</h3>

			{ 0 === viewings ? (
				<p className="imgpa-hint">
					{ __(
						'Not enough viewings in this period to draw this.',
						'imagina-player'
					) }
				</p>
			) : (
				<>
					<p className="imgpa-hint">
						{ sprintf(
							/* translators: %s: a number of viewings. */
							_n(
								'From %s viewing.',
								'From %s viewings.',
								viewings,
								'imagina-player'
							),
							number( viewings )
						) }{ ' ' }
						{ half > 0 &&
							sprintf(
								/* translators: %s: a moment in the media, as 1:23 or 40%. */
								__(
									'Half had stopped by %s.',
									'imagina-player'
								),
								at( half )
							) }
					</p>

					<LineChart
						points={ detail.retention.map( ( share, index ) => ( {
							value: share,
							label: at( index ),
							shown: sprintf(
								/* translators: %s: a share of the viewers, as 64%. */
								__( '%s still watching', 'imagina-player' ),
								percent( share )
							),
						} ) ) }
						max={ 1 }
						ticks={ [ 0, 0.5, 1 ] }
						tickLabel={ percent }
						labelEvery={ 25 }
						description={ __(
							'The share of viewers still watching at each point of the media.',
							'imagina-player'
						) }
					/>

					<details className="imgpa-stats__table">
						<summary>
							{ __( 'Show as a table', 'imagina-player' ) }
						</summary>
						<table className="imgpa-table">
							<thead>
								<tr>
									<th scope="col">
										{ __( 'At', 'imagina-player' ) }
									</th>
									<th scope="col">
										{ __(
											'Still watching',
											'imagina-player'
										) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ MARKS.map( ( mark ) => (
									<tr key={ mark }>
										<td>{ at( mark ) }</td>
										<td>
											{ percent(
												detail.retention[ mark ] ?? 0
											) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</details>
				</>
			) }

			{ detail.layers.length > 0 && (
				<>
					<h3>{ __( 'Overlays pressed', 'imagina-player' ) }</h3>
					<table className="imgpa-table">
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Overlay', 'imagina-player' ) }
								</th>
								<th scope="col">
									{ __( 'Presses', 'imagina-player' ) }
								</th>
								<th scope="col">
									{ __( 'Per play', 'imagina-player' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ detail.layers.map( ( layer, index ) => {
								const count =
									clicks.find(
										( entry ) => entry.index === index
									)?.count ?? 0;

								return (
									<tr key={ index }>
										<td>
											{ layer.label ||
												sprintf(
													/* translators: %d: the overlay's number in the block's list. */
													__(
														'Overlay %d',
														'imagina-player'
													),
													index + 1
												) }
										</td>
										<td>{ number( count ) }</td>
										<td>
											{ row.plays > 0
												? percent( count / row.plays )
												: '—' }
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				</>
			) }

			{ 0 === detail.layers.length && clicks.length > 0 && (
				<p className="imgpa-hint">
					{ sprintf(
						/* translators: %s: a number of presses. */
						__( '%s presses on its overlays.', 'imagina-player' ),
						number(
							clicks.reduce(
								( sum, entry ) => sum + entry.count,
								0
							)
						)
					) }
				</p>
			) }
		</div>
	);
}

export function StatsPanel( { settings, onChange }: PanelProps ) {
	const [ days, setDays ] = useState( 30 );
	const [ data, setData ] = useState< StatsSummary | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ open, setOpen ] = useState( '' );
	const [ exporting, setExporting ] = useState( false );
	const [ exportError, setExportError ] = useState( '' );

	const stats = settings.stats;
	const set = ( patch: Partial< SettingsPayload[ 'stats' ] > ): void =>
		onChange( { stats: { ...stats, ...patch } } );

	useEffect( () => {
		let cancelled = false;

		setLoading( true );

		loadStats( days )
			.then( ( result ) => {
				if ( ! cancelled ) {
					setData( result );
					setError( '' );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setError(
						__(
							'The statistics could not be loaded.',
							'imagina-player'
						)
					);
				}
			} )
			.finally( () => ! cancelled && setLoading( false ) );

		return () => {
			cancelled = true;
		};
	}, [ days ] );

	const rows = data?.rows ?? [];
	const totals = rows.reduce(
		( sum, row ) => ( {
			plays: sum.plays + row.plays,
			seconds: sum.seconds + row.seconds,
			complete: sum.complete + row.complete,
			click: sum.click + row.click,
			// Only what has a length counts towards "how much of it".
			length:
				sum.length +
				( row.duration > 0 ? row.plays * row.duration : 0 ),
			measured: sum.measured + ( row.duration > 0 ? row.seconds : 0 ),
		} ),
		{
			plays: 0,
			seconds: 0,
			complete: 0,
			click: 0,
			length: 0,
			measured: 0,
		}
	);
	const daily = Object.entries( data?.daily ?? {} );
	const scale = niceTicks(
		Math.max( 0, ...daily.map( ( [ , plays ] ) => plays ) )
	);

	return (
		<>
			<Card
				title={ __( 'Statistics', 'imagina-player' ) }
				description={ __(
					'Plays, time watched, how far viewers got and which overlays they pressed, for every player on a published page. Counts only: nothing that identifies a viewer is stored.',
					'imagina-player'
				) }
			>
				{ ! stats.enabled && (
					<Notice tone="warn">
						{ __(
							'Counting is off, so nothing new is added. What was counted before is still shown.',
							'imagina-player'
						) }
					</Notice>
				) }
				{ '' !== error && <Notice tone="warn">{ error }</Notice> }

				<div className="imgpa-row">
					<Select
						value={ String( days ) }
						onChange={ ( value ) => {
							setDays( Number( value ) );
							setOpen( '' );
						} }
						options={ PERIODS.map( ( period ) => ( {
							value: String( period ),
							label: sprintf(
								/* translators: %d: a number of days. */
								__( 'Last %d days', 'imagina-player' ),
								period
							),
						} ) ) }
					/>

					<button
						type="button"
						className="imgpa-btn imgpa-btn--ghost"
						disabled={ exporting || 0 === rows.length }
						onClick={ () => {
							setExporting( true );
							setExportError( '' );
							downloadStats( days )
								.catch( () =>
									setExportError(
										__(
											'The file could not be prepared. Reload the page and try again.',
											'imagina-player'
										)
									)
								)
								.finally( () => setExporting( false ) );
						} }
					>
						{ exporting
							? __( 'Preparing…', 'imagina-player' )
							: __( 'Download as CSV', 'imagina-player' ) }
					</button>

					{ exportError && (
						<span
							className="imgpa-hint imgpa-hint--error"
							role="alert"
						>
							{ exportError }
						</span>
					) }

					{ loading && (
						<span className="imgpa-hint">
							{ __( 'Loading…', 'imagina-player' ) }
						</span>
					) }
				</div>

				<div
					className={ `imgpa-stats${
						loading && data ? ' is-loading' : ''
					}` }
				>
					<dl className="imgpa-tiles">
						<div className="imgpa-tile">
							<dt>{ __( 'Plays', 'imagina-player' ) }</dt>
							<dd>{ number( totals.plays ) }</dd>
						</div>
						<div className="imgpa-tile">
							<dt>{ __( 'Time watched', 'imagina-player' ) }</dt>
							<dd>{ watched( totals.seconds ) }</dd>
						</div>
						<div className="imgpa-tile">
							<dt>
								{ __( 'Average watched', 'imagina-player' ) }
							</dt>
							<dd>
								{ totals.length > 0
									? percent(
											Math.min(
												1,
												totals.measured / totals.length
											)
									  )
									: '—' }
							</dd>
						</div>
						<div className="imgpa-tile">
							<dt>
								{ __( 'Watched to the end', 'imagina-player' ) }
							</dt>
							<dd>
								{ totals.plays > 0
									? percent(
											Math.min(
												1,
												totals.complete / totals.plays
											)
									  )
									: '—' }
							</dd>
						</div>
						<div className="imgpa-tile">
							<dt>
								{ __( 'Overlays pressed', 'imagina-player' ) }
							</dt>
							<dd>{ number( totals.click ) }</dd>
						</div>
					</dl>

					{ totals.plays > 0 && (
						<>
							<h3>{ __( 'Plays per day', 'imagina-player' ) }</h3>
							<LineChart
								points={ daily.map( ( [ date, plays ] ) => ( {
									value: plays,
									label: day( date ),
									shown: sprintf(
										/* translators: %s: a number of plays. */
										_n(
											'%s play',
											'%s plays',
											plays,
											'imagina-player'
										),
										number( plays )
									),
								} ) ) }
								max={ scale.max }
								ticks={ scale.ticks }
								tickLabel={ number }
								labelEvery={ Math.max(
									1,
									Math.ceil( daily.length / 6 )
								) }
								description={ __(
									'Plays on every player of the site, day by day.',
									'imagina-player'
								) }
							/>
						</>
					) }

					{ ! loading && 0 === rows.length && '' === error && (
						<Notice tone="info">
							{ __(
								'Nothing counted in this period yet. Plays are counted on published pages from now on; plays in the editor and on this screen are not.',
								'imagina-player'
							) }
						</Notice>
					) }

					{ rows.length > 0 && (
						<div className="imgpa-tablewrap">
							<table className="imgpa-table imgpa-table--stats">
								<thead>
									<tr>
										<th scope="col">
											{ __( 'Media', 'imagina-player' ) }
										</th>
										<th scope="col">
											{ __( 'Page', 'imagina-player' ) }
										</th>
										<th scope="col" className="is-num">
											{ __( 'Plays', 'imagina-player' ) }
										</th>
										<th scope="col" className="is-num">
											{ __(
												'Average watched',
												'imagina-player'
											) }
										</th>
										<th scope="col" className="is-num">
											{ __(
												'To the end',
												'imagina-player'
											) }
										</th>
										<th scope="col" className="is-num">
											{ __(
												'Overlays',
												'imagina-player'
											) }
										</th>
										<th scope="col">
											<span className="imgpa-sr">
												{ __(
													'Details',
													'imagina-player'
												) }
											</span>
										</th>
									</tr>
								</thead>
								<tbody>
									{ rows.map( ( row ) => {
										const id = `${ row.key }:${ row.post }`;
										const share = averageShare( row );

										return (
											<Fragment key={ id }>
												<tr>
													<td>
														{ row.title ||
															__(
																'Untitled',
																'imagina-player'
															) }
													</td>
													<td className="imgpa-table__url">
														{ row.pageUrl ? (
															<a
																href={
																	row.pageUrl
																}
																target="_blank"
																rel="noreferrer"
															>
																{ row.page ||
																	row.pageUrl }
															</a>
														) : (
															'—'
														) }
													</td>
													<td className="is-num">
														{ number( row.plays ) }
													</td>
													<td className="is-num">
														{ null === share
															? '—'
															: percent( share ) }
													</td>
													<td className="is-num">
														{ row.plays > 0
															? percent(
																	Math.min(
																		1,
																		row.complete /
																			row.plays
																	)
															  )
															: '—' }
													</td>
													<td className="is-num">
														{ number( row.click ) }
													</td>
													<td>
														<button
															type="button"
															className="imgpa-btn imgpa-btn--ghost"
															aria-expanded={
																open === id
															}
															onClick={ () =>
																setOpen(
																	open === id
																		? ''
																		: id
																)
															}
														>
															{ open === id
																? __(
																		'Hide',
																		'imagina-player'
																  )
																: __(
																		'Details',
																		'imagina-player'
																  ) }
														</button>
													</td>
												</tr>
												{ open === id && (
													<tr className="imgpa-table__detail">
														<td colSpan={ 7 }>
															<Detail
																row={ row }
																days={ days }
															/>
														</td>
													</tr>
												) }
											</Fragment>
										);
									} ) }
								</tbody>
							</table>
						</div>
					) }
				</div>
			</Card>

			<Card title={ __( 'Counting', 'imagina-player' ) }>
				<div className="imgpa-toggles">
					<Toggle
						label={ __(
							'Count how players are watched',
							'imagina-player'
						) }
						help={ __(
							'Each player reports its plays, the time watched, how far the viewer got and the overlays pressed, when the page is closed or the media ends. No cookie is set and no address is stored.',
							'imagina-player'
						) }
						checked={ stats.enabled }
						onChange={ ( enabled ) => set( { enabled } ) }
					/>
				</div>

				<Field
					label={ __( 'Keep statistics for', 'imagina-player' ) }
					help={ __(
						'Days older than this are deleted once a day.',
						'imagina-player'
					) }
				>
					<NumberInput
						value={ stats.keep_days }
						min={ 30 }
						max={ 3650 }
						suffix={ __( 'days', 'imagina-player' ) }
						onChange={ ( value ) => set( { keep_days: value } ) }
					/>
				</Field>
			</Card>
		</>
	);
}
