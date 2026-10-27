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
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Spinner } from '@wordpress/components';

import { inlineAssets } from '../shared/frame-assets';
import {
	FRAME_HEIGHT_SCRIPT,
	listenForFrameHeight,
} from '../shared/frame-height';

/** Attributes drawn by the editor around the frame, not by the player in it. */
const WRAPPER_ONLY = [
	'className',
	'align',
	'anchor',
	'style',
	'lock',
	'metadata',
];

interface EditorAssets {
	frontendCss: string;
	frontendJs: string;
	frameCss: string;
	restUrl: string;
	assetUrl?: string;
	frontendChunks?: string[];
}

interface PreviewProps {
	/**
	 * Whatever the block holds. Lists (subtitle tracks, chapters) go through
	 * unchanged: the preview posts them to the renderer, which is the only side
	 * that needs to understand their shape.
	 */
	attributes: Record< string, unknown >;
	/** The post being edited, for a block whose file is one of its fields. */
	postId?: number;
	assets: EditorAssets;
	/**
	 * What the server will show for fields the block leaves empty, so they can
	 * be offered as placeholders rather than as blank boxes.
	 */
	onResolved?: ( resolved: {
		title: string;
		artist: string;
		thumbnail: string;
		posterReason: string;
	} ) => void;
	/**
	 * Bumped to force a re-render when nothing in the attributes changed.
	 *
	 * Measuring a waveform stores it against the file, not against the block,
	 * so without this the preview kept showing the flat bar after the author
	 * had just fixed it — which reads as the button having done nothing.
	 */
	refresh?: number;
}

export function Preview( {
	attributes,
	postId = 0,
	assets,
	onResolved,
	refresh = 0,
}: PreviewProps ) {
	const [ doc, setDoc ] = useState( '' );
	const [ failed, setFailed ] = useState( '' );
	const [ attempt, setAttempt ] = useState( 0 );
	const [ linked, setLinked ] = useState( 0 );
	const [ height, setHeight ] = useState( 150 );
	const frame = useRef< HTMLIFrameElement | null >( null );

	/*
	 * Only what the rendered player actually depends on. The wrapper's own
	 * attributes — a class, an alignment, an anchor, spacing — are drawn by
	 * the editor around the frame, and re-rendering the player for each
	 * keystroke in one of them was a request and a reload for nothing.
	 */
	const relevant = Object.fromEntries(
		Object.entries( attributes ).filter(
			( [ key ] ) => ! WRAPPER_ONLY.includes( key )
		)
	);
	const signature = JSON.stringify( relevant );

	useEffect( () => {
		let cancelled = false;

		const timer = window.setTimeout( () => {
			apiFetch( {
				path: '/imagina-player/v1/preview',
				method: 'POST',
				data: { attributes: relevant, postId },
			} )
				.then( async ( result ) => {
					/*
					 * The stylesheet and script go into the frame as text. Linked
					 * from inside it they are requests from an opaque origin, and
					 * hosts that refuse those for static files gave a preview with
					 * the browser's bare controls and no styling at all.
					 */
					const inlined = await inlineAssets(
						[ assets.frameCss, assets.frontendCss ],
						assets.frontendJs,
						assets.frontendChunks ?? []
					);

					if ( cancelled ) {
						return;
					}

					const { html, resolved, linkedCaptions } = result as {
						html: string;
						linkedCaptions?: number;
						resolved?: {
							title: string;
							artist: string;
							thumbnail: string;
							posterReason: string;
						};
					};

					if ( resolved ) {
						onResolved?.( resolved );
					}

					/*
					 * No synthetic waveform here any more. It used to stand in
					 * whenever a track had none cached, on the reasoning that a
					 * flat bar looks broken — but it meant the editor drew a
					 * waveform the front end would never show, so the only place
					 * anybody found out was the live site. A preview that lies
					 * is worse than one that looks plain.
					 */
					const markup = html;

					setFailed( '' );
					setLinked( Number( linkedCaptions ?? 0 ) );
					setDoc(
						`<!doctype html><html><head><meta charset="utf-8">
						${ inlined.head }
						</head><body>${ markup }
						<script>window.imaginaPlayer={restUrl:"${ assets.restUrl }",assetUrl:"${
							assets.assetUrl ?? ''
						}",lazyInit:false,maxComputeBytes:0,i18n:{}};</script>
						${ inlined.tail }
						<script>${ FRAME_HEIGHT_SCRIPT }</script>
						</body></html>`
					);
				} )
				.catch( ( error: { message?: string } ) => {
					if ( ! cancelled ) {
						setFailed(
							error?.message ||
								__(
									'No answer from the site.',
									'imagina-player'
								)
						);
					}
				} );
		}, 300 );

		return () => {
			cancelled = true;
			window.clearTimeout( timer );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [
		signature,
		postId,
		refresh,
		attempt,
		assets.frontendCss,
		assets.frontendJs,
		assets.frameCss,
	] );

	/*
	 * The frame is sandboxed, so its document cannot be read from here — the
	 * measuring that used to happen here measured nothing, and every preview
	 * stayed at its starting height. The frame reports its own height instead.
	 * A pixel short and it grows a scrollbar, which narrows the content and
	 * grows a second one across the bottom, hence the two.
	 */
	useEffect(
		() =>
			listenForFrameHeight(
				() => frame.current,
				( reported ) => setHeight( Math.max( 90, reported + 2 ) )
			),
		[]
	);

	if ( failed ) {
		return (
			<div className="imgp-editor__preview imgp-editor__preview--failed">
				<p>
					{ sprintf(
						/* translators: %s: the reason the site gave. */
						__(
							'The preview could not be loaded: %s',
							'imagina-player'
						),
						failed
					) }
				</p>
				<Button
					variant="secondary"
					onClick={ () => {
						setFailed( '' );
						setAttempt( ( n ) => n + 1 );
					} }
				>
					{ __( 'Try again', 'imagina-player' ) }
				</Button>
			</div>
		);
	}

	return (
		<div className="imgp-editor__preview">
			{ ! doc && (
				<div className="imgp-editor__preview-loading">
					<Spinner />
					{ __( 'Loading the preview…', 'imagina-player' ) }
				</div>
			) }
			<iframe
				ref={ frame }
				title={ __( 'Player preview', 'imagina-player' ) }
				className="imgp-editor__preview-frame"
				style={ { height: `${ height }px` } }
				srcDoc={ doc }
				// Scripts, and nothing else: an opaque origin, so the preview
				// cannot reach admin cookies or the parent document if the
				// renderer ever lets something through unescaped.
				sandbox="allow-scripts"
				scrolling="no"
				hidden={ ! doc }
			/>
			{ linked > 0 && (
				<p className="imgp-editor__preview-note">
					{ sprintf(
						/* translators: %d: how many subtitle files. */
						_n(
							'%d subtitle file is on another site, so it is not shown in this preview. It works on the published page.',
							'%d subtitle files are on another site, so they are not shown in this preview. They work on the published page.',
							linked,
							'imagina-player'
						),
						linked
					) }
				</p>
			) }
		</div>
	);
}
