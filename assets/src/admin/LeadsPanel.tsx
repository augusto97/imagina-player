/**
 * The addresses players have captured.
 *
 * A table, not a dashboard. There is nothing to configure here and nothing to
 * chart: the questions this screen answers are "who signed up", "from which
 * offer", and "can I have that as a spreadsheet".
 */

import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { boot, deleteLead, exportUrl, listLeads } from './api';
import type { Lead } from './api';
import { Card, Notice, Select } from './controls';

const PER_PAGE = 50;

export function LeadsPanel() {
	const [ rows, setRows ] = useState< Lead[] >( [] );
	const [ lists, setLists ] = useState< string[] >( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ list, setList ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let cancelled = false;

		setLoading( true );

		listLeads( page, list )
			.then( ( result ) => {
				if ( cancelled ) {
					return;
				}

				setRows( result.rows );
				setTotal( result.total );
				setLists( result.lists.filter( Boolean ) );
				setError( '' );
			} )
			.catch( ( failure ) => {
				if ( cancelled ) {
					return;
				}

				setError(
					failure instanceof Error
						? failure.message
						: __( 'These could not be loaded.', 'imagina-player' )
				);
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setLoading( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ page, list ] );

	const remove = async ( id: number ): Promise< void > => {
		// Deleting an address someone gave you is not undoable and not
		// recoverable from anywhere else, so it is worth one question.
		if (
			// eslint-disable-next-line no-alert
			! window.confirm(
				__(
					'Delete this address? This cannot be undone.',
					'imagina-player'
				)
			)
		) {
			return;
		}

		try {
			await deleteLead( id );
		} catch {
			// The row stays, which is the truth: nothing was deleted. Saying
			// so is better than a request that fails into silence.
			// eslint-disable-next-line no-alert
			window.alert(
				__(
					'That address could not be deleted. Try again.',
					'imagina-player'
				)
			);

			return;
		}

		setRows( ( current ) => current.filter( ( row ) => row.id !== id ) );
		setTotal( ( current ) => Math.max( 0, current - 1 ) );
	};

	const pages = Math.max( 1, Math.ceil( total / PER_PAGE ) );
	const runtime = boot();

	return (
		<Card
			title={ __( 'Captured emails', 'imagina-player' ) }
			description={ __(
				'Addresses given through an email gate on a player. Grouped by the list name set on each one.',
				'imagina-player'
			) }
		>
			{ '' !== error && <Notice tone="warn">{ error }</Notice> }

			<div className="imgpa-row">
				{ lists.length > 0 && (
					<Select
						value={ list }
						onChange={ ( value ) => {
							setList( value );
							setPage( 1 );
						} }
						options={ [
							{
								value: '',
								label: __( 'Every list', 'imagina-player' ),
							},
							...lists.map( ( name ) => ( {
								value: name,
								label: name,
							} ) ),
						] }
					/>
				) }

				<a
					className="imgpa-btn imgpa-btn--ghost"
					href={ exportUrl( list, runtime.restUrl, runtime.nonce ) }
					download
				>
					{ __( 'Download as CSV', 'imagina-player' ) }
				</a>

				<span className="imgpa-hint">
					{ loading
						? __( 'Loading…', 'imagina-player' )
						: sprintf(
								/* translators: %d: number of captured addresses. */
								__( '%d in total', 'imagina-player' ),
								total
						  ) }
				</span>
			</div>

			{ ! loading && 0 === rows.length && (
				<Notice tone="info">
					{ __(
						'Nothing yet. Add an email gate to a player under Calls to action in the block, and addresses will appear here.',
						'imagina-player'
					) }
				</Notice>
			) }

			{ rows.length > 0 && (
				<div className="imgpa-tablewrap">
					<table className="imgpa-table">
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Email', 'imagina-player' ) }
								</th>
								<th scope="col">
									{ __( 'List', 'imagina-player' ) }
								</th>
								<th scope="col">
									{ __( 'From', 'imagina-player' ) }
								</th>
								<th scope="col">
									{ __( 'When', 'imagina-player' ) }
								</th>
								<th scope="col">
									<span className="imgpa-sr">
										{ __( 'Actions', 'imagina-player' ) }
									</span>
								</th>
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( row ) => (
								<tr key={ row.id }>
									<td>{ row.email }</td>
									<td>{ row.list || '—' }</td>
									<td className="imgpa-table__url">
										{ row.source_url ? (
											<a
												href={ row.source_url }
												target="_blank"
												rel="noreferrer"
											>
												{ row.source_url.replace(
													/^https?:\/\//,
													''
												) }
											</a>
										) : (
											'—'
										) }
									</td>
									<td>{ row.created_at }</td>
									<td>
										<button
											type="button"
											className="imgpa-btn imgpa-btn--danger"
											onClick={ () =>
												void remove( row.id )
											}
										>
											{ __( 'Delete', 'imagina-player' ) }
										</button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			{ pages > 1 && (
				<div className="imgpa-row">
					<button
						type="button"
						className="imgpa-btn imgpa-btn--ghost"
						disabled={ page <= 1 }
						onClick={ () => setPage( page - 1 ) }
					>
						{ __( 'Previous', 'imagina-player' ) }
					</button>
					<span className="imgpa-hint">
						{ sprintf(
							/* translators: 1: current page, 2: total pages. */
							__( 'Page %1$d of %2$d', 'imagina-player' ),
							page,
							pages
						) }
					</span>
					<button
						type="button"
						className="imgpa-btn imgpa-btn--ghost"
						disabled={ page >= pages }
						onClick={ () => setPage( page + 1 ) }
					>
						{ __( 'Next', 'imagina-player' ) }
					</button>
				</div>
			) }
		</Card>
	);
}
