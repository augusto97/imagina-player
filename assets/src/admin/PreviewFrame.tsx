/**
 * Live preview of a preset.
 *
 * The markup comes from the real renderer over REST, and runs inside an iframe
 * with the real front-end stylesheet and script. Two reasons: the preview is
 * then the actual player rather than a lookalike that drifts, and the admin
 * stylesheet cannot leak into it and flatter the result.
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { boot, renderPreview } from './api';
import type { Preset } from './types';

export function PreviewFrame( { preset }: { preset: Preset } ) {
	const [ doc, setDoc ] = useState( '' );
	const [ failed, setFailed ] = useState( false );
	const frame = useRef< HTMLIFrameElement | null >( null );
	const [ height, setHeight ] = useState( 180 );

	useEffect( () => {
		let cancelled = false;

		// Debounced: dragging a colour picker would otherwise fire a request per
		// pixel of travel.
		const timer = window.setTimeout( () => {
			renderPreview( preset )
				.then( ( result ) => {
					if ( cancelled ) {
						return;
					}

					const { frontendCss, frontendJs, frameCss } = boot();
					const html = result.html.replace(
						'data-imagina-player=',
						`data-peaks="${ result.peaks }" data-imagina-player=`
					);

					setFailed( false );
					setDoc(
						`<!doctype html><html><head><meta charset="utf-8">
						<link rel="stylesheet" href="${ frameCss }">
						<link rel="stylesheet" href="${ frontendCss }">
						<style>body { padding: 24px 0; }</style>
						</head><body>${ html }
						<script>window.imaginaPlayer = { restUrl: "", lazyInit: false, maxComputeBytes: 0, i18n: {} };</script>
						<script src="${ frontendJs }"></script>
						</body></html>`
					);
				} )
				.catch( () => {
					if ( ! cancelled ) {
						setFailed( true );
					}
				} );
		}, 250 );

		return () => {
			cancelled = true;
			window.clearTimeout( timer );
		};
	}, [ preset ] );

	// Match the frame to its content so tall skins are not cropped.
	const measure = (): void => {
		const body = frame.current?.contentDocument?.body;

		if ( body ) {
			setHeight( Math.max( 140, body.scrollHeight ) );
		}
	};

	if ( failed ) {
		return (
			<div className="imgpa-preview imgpa-preview--failed">
				{ __( 'The preview could not be loaded.', 'imagina-player' ) }
			</div>
		);
	}

	return (
		<div className="imgpa-preview">
			<span className="imgpa-preview__label">
				{ __( 'Live preview', 'imagina-player' ) }
			</span>
			<iframe
				ref={ frame }
				title={ __( 'Player preview', 'imagina-player' ) }
				className="imgpa-preview__frame"
				style={ { height: `${ height }px` } }
				srcDoc={ doc }
				onLoad={ measure }
			/>
		</div>
	);
}
