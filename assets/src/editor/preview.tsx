/**
 * The block preview.
 *
 * It renders the real player: markup from the real renderer over REST, inside an
 * iframe that loads the real front-end stylesheet and script. The previous
 * version was a React lookalike that reproduced the markup by hand, and it fell
 * behind the renderer the first time the layouts changed — the card, compact and
 * pill skins all drew as the old stacked one.
 *
 * The iframe also keeps the editor's stylesheet out, which otherwise flatters
 * the result in ways the published page will not.
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

interface EditorAssets {
	frontendCss: string;
	frontendJs: string;
}

interface PreviewProps {
	attributes: Record< string, string | number | boolean >;
	assets: EditorAssets;
}

export function Preview( { attributes, assets }: PreviewProps ) {
	const [ doc, setDoc ] = useState( '' );
	const [ failed, setFailed ] = useState( false );
	const [ height, setHeight ] = useState( 150 );
	const frame = useRef< HTMLIFrameElement | null >( null );

	// Only what the rendered player actually depends on: typing into an unrelated
	// field should not re-request the preview.
	const signature = JSON.stringify( attributes );

	useEffect( () => {
		let cancelled = false;

		const timer = window.setTimeout( () => {
			apiFetch( {
				path: '/imagina-player/v1/preview',
				method: 'POST',
				data: { attributes },
			} )
				.then( ( result ) => {
					if ( cancelled ) {
						return;
					}

					const { html, peaks } = result as { html: string; peaks: string };

					// Real peaks win; the synthetic set only stands in when the track
					// has none cached yet, so the preview is never a flat bar.
					const markup = html.includes( 'data-peaks=' )
						? html
						: html.replace( 'data-imagina-player=', `data-peaks="${ peaks }" data-imagina-player=` );

					setFailed( false );
					setDoc(
						`<!doctype html><html><head><meta charset="utf-8">
						<link rel="stylesheet" href="${ assets.frontendCss }">
						<style>body{margin:0;padding:4px 0;background:transparent;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}</style>
						</head><body>${ markup }
						<script>window.imaginaPlayer={restUrl:"",lazyInit:false,maxComputeBytes:0,i18n:{}};</script>
						<script src="${ assets.frontendJs }"></script>
						</body></html>`
					);
				} )
				.catch( () => {
					if ( ! cancelled ) {
						setFailed( true );
					}
				} );
		}, 300 );

		return () => {
			cancelled = true;
			window.clearTimeout( timer );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ signature, assets.frontendCss, assets.frontendJs ] );

	const measure = (): void => {
		const body = frame.current?.contentDocument?.body;

		if ( body ) {
			setHeight( Math.max( 90, body.scrollHeight ) );
		}
	};

	if ( failed ) {
		return (
			<div className="imgp-editor__preview imgp-editor__preview--failed">
				{ __( 'The preview could not be loaded.', 'imagina-player' ) }
			</div>
		);
	}

	return (
		<div className="imgp-editor__preview">
			<iframe
				ref={ frame }
				title={ __( 'Player preview', 'imagina-player' ) }
				className="imgp-editor__preview-frame"
				style={ { height: `${ height }px` } }
				srcDoc={ doc }
				onLoad={ measure }
			/>
			{ /* Clicks belong to the block, not to the player inside the frame:
			     without this the block cannot be selected by clicking it. */ }
			<div className="imgp-editor__preview-catcher" aria-hidden="true" />
		</div>
	);
}
