/**
 * Subtitles and chapters without leaving the block.
 *
 * Subtitles from a transcript somebody pastes, or written by a speech-to-text
 * service when the site has one set up; either way the result is a WebVTT
 * file in the media library, listed with the block's other subtitles. And
 * chapters from a list pasted as YouTube writes them, or from a file.
 */

import { MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import {
	Button,
	Notice,
	TextareaControl,
	TextControl,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { parseChapters, toVtt, transcriptToCues } from './transcript-tools';

interface ToolProps {
	attributes: Record< string, unknown >;
	setAttributes: ( next: Record< string, unknown > ) => void;
}

interface Track {
	src: string;
	srclang: string;
	label: string;
	kind: string;
	default: boolean;
}

type Message = { tone: 'success' | 'warning' | 'error'; text: string } | null;

/** How often the editor asks how a transcription is going. */
const POLL_MS = 4000;

/** And for how long, before it says the job is taking too long to wait for. */
const POLL_FOR_MS = 30 * 60 * 1000;

/**
 * How long a file is, asked of the browser; 0 when it will not say.
 * @param src
 */
function duration( src: string ): Promise< number > {
	return new Promise( ( resolve ) => {
		const media = document.createElement( 'video' );
		const done = ( value: number ): void => {
			media.removeAttribute( 'src' );
			resolve( Number.isFinite( value ) && value > 0 ? value : 0 );
		};

		window.setTimeout( () => done( 0 ), 8000 );
		media.preload = 'metadata';
		media.addEventListener( 'loadedmetadata', () =>
			done( media.duration )
		);
		media.addEventListener( 'error', () => done( 0 ) );
		media.src = src;
	} );
}

function clockLabel( seconds: number ): string {
	const whole = Math.max( 0, Math.round( seconds ) );
	const hours = Math.floor( whole / 3600 );
	const minutes = Math.floor( ( whole % 3600 ) / 60 );
	const rest = String( whole % 60 ).padStart( 2, '0' );

	return hours
		? `${ hours }:${ String( minutes ).padStart( 2, '0' ) }:${ rest }`
		: `${ minutes }:${ rest }`;
}

function withTrack(
	attributes: Record< string, unknown >,
	track: Omit< Track, 'kind' | 'default' >
): Track[] {
	const tracks = ( attributes.tracks ?? [] ) as Track[];

	return [
		...tracks,
		{ ...track, kind: 'subtitles', default: 0 === tracks.length },
	];
}

export function SubtitleTools( { attributes, setAttributes }: ToolProps ) {
	const [ open, setOpen ] = useState< '' | 'paste' | 'auto' >( '' );
	const [ text, setText ] = useState( '' );
	const [ label, setLabel ] = useState( '' );
	const [ code, setCode ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ message, setMessage ] = useState< Message >( null );
	const polling = useRef( 0 );
	const src = String( attributes.src ?? '' );
	const attachment = Number( attributes.attachmentId ?? 0 );
	const service = Boolean( window.imaginaPlayerEditor?.transcription );

	useEffect( () => () => window.clearTimeout( polling.current ), [] );

	const fromTranscript = async (): Promise< void > => {
		setBusy( true );
		setMessage( null );

		try {
			const { cues, approximate } = transcriptToCues(
				text,
				src ? await duration( src ) : 0
			);

			if ( 0 === cues.length ) {
				setMessage( {
					tone: 'error',
					text: __(
						'There is no text there to make subtitles from.',
						'imagina-player'
					),
				} );

				return;
			}

			const form = new window.FormData();
			const name = `subtitles${ code ? '-' + code : '' }.vtt`;

			form.append(
				'file',
				new window.File( [ toVtt( cues ) ], name, { type: 'text/vtt' } )
			);
			form.append( 'title', label || name );

			const media = ( await apiFetch( {
				path: '/wp/v2/media',
				method: 'POST',
				body: form,
			} ) ) as { source_url?: string };

			setAttributes( {
				tracks: withTrack( attributes, {
					src: media.source_url ?? '',
					srclang: code,
					label: label || code,
				} ),
			} );
			setText( '' );
			setOpen( '' );
			setMessage(
				approximate
					? {
							tone: 'warning',
							text: __(
								'Subtitles added. The transcript had no times, so its text is spread over the length of the video: watch them through and adjust the file if a line comes too early or too late.',
								'imagina-player'
							),
					  }
					: {
							tone: 'success',
							text: __( 'Subtitles added.', 'imagina-player' ),
					  }
			);
		} catch ( error ) {
			setMessage( {
				tone: 'error',
				text:
					( error as { message?: string } )?.message ??
					__(
						'The subtitles could not be saved to the media library.',
						'imagina-player'
					),
			} );
		} finally {
			setBusy( false );
		}
	};

	const poll = ( job: string, started: number ): void => {
		polling.current = window.setTimeout( async () => {
			try {
				const state = ( await apiFetch( {
					path: `/imagina-player/v1/transcribe/${ job }`,
				} ) ) as {
					status: string;
					url?: string;
					message?: string;
					srclang?: string;
				};

				if ( 'done' === state.status && state.url ) {
					setAttributes( {
						tracks: withTrack( attributes, {
							src: state.url,
							srclang: state.srclang ?? code,
							label: label || state.srclang || code,
						} ),
					} );
					setBusy( false );
					setOpen( '' );
					setMessage( {
						tone: 'success',
						text: __(
							'Subtitles added. A service that listens makes mistakes too: read them through.',
							'imagina-player'
						),
					} );

					return;
				}

				if ( 'failed' === state.status ) {
					setBusy( false );
					setMessage( {
						tone: 'error',
						text:
							state.message ??
							__( 'The transcription failed.', 'imagina-player' ),
					} );

					return;
				}
			} catch {
				// A request that did not come back is asked again.
			}

			if ( Date.now() - started > POLL_FOR_MS ) {
				setBusy( false );
				setMessage( {
					tone: 'warning',
					text: __(
						'This is taking longer than the editor waits. The subtitles will be in the media library when it is done.',
						'imagina-player'
					),
				} );

				return;
			}

			poll( job, started );
		}, POLL_MS );
	};

	const transcribe = async (): Promise< void > => {
		setBusy( true );
		setMessage( null );

		try {
			const { job } = ( await apiFetch( {
				path: '/imagina-player/v1/transcribe',
				method: 'POST',
				data: { attachment, language: code },
			} ) ) as { job: string };

			poll( job, Date.now() );
		} catch ( error ) {
			setBusy( false );
			setMessage( {
				tone: 'error',
				text:
					( error as { message?: string } )?.message ??
					__(
						'The transcription could not start.',
						'imagina-player'
					),
			} );
		}
	};

	const language = (
		<>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Language name', 'imagina-player' ) }
				value={ label }
				placeholder={ __( 'Español', 'imagina-player' ) }
				onChange={ setLabel }
			/>
			<TextControl
				__nextHasNoMarginBottom
				label={ __( 'Language code', 'imagina-player' ) }
				value={ code }
				placeholder="es"
				onChange={ setCode }
			/>
		</>
	);

	return (
		<div className="imgp-editor__tools">
			<div className="imgp-editor__media-picker">
				<Button
					variant="secondary"
					onClick={ () => setOpen( 'paste' === open ? '' : 'paste' ) }
					aria-expanded={ 'paste' === open }
					disabled={ busy }
				>
					{ __( 'From a transcript…', 'imagina-player' ) }
				</Button>
				{ service && (
					<Button
						variant="secondary"
						onClick={ () =>
							setOpen( 'auto' === open ? '' : 'auto' )
						}
						aria-expanded={ 'auto' === open }
						disabled={ busy }
					>
						{ __( 'Transcribe automatically…', 'imagina-player' ) }
					</Button>
				) }
			</div>

			{ 'paste' === open && (
				<div className="imgp-editor__row">
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Transcript', 'imagina-player' ) }
						help={ __(
							'Lines that start with their time, such as 1:23 or [00:01:23], are placed at that time. Without times, the text is spread over the length of the video. SRT or WebVTT pasted whole is kept as it is.',
							'imagina-player'
						) }
						value={ text }
						rows={ 8 }
						onChange={ setText }
					/>
					{ language }
					<Button
						variant="primary"
						onClick={ () => void fromTranscript() }
						disabled={ busy || '' === text.trim() }
						isBusy={ busy }
					>
						{ __( 'Make the subtitles', 'imagina-player' ) }
					</Button>
				</div>
			) }

			{ 'auto' === open && (
				<div className="imgp-editor__row">
					{ attachment > 0 ? (
						<>
							<p className="imgp-editor__hint">
								{ __(
									'The sound is sent to the service set up under Imagina Player → Integrations, and the subtitles it writes are saved to the media library. It takes a few minutes for a long recording; you can keep editing.',
									'imagina-player'
								) }
							</p>
							{ language }
							<Button
								variant="primary"
								onClick={ () => void transcribe() }
								disabled={ busy }
								isBusy={ busy }
							>
								{ busy
									? __( 'Transcribing…', 'imagina-player' )
									: __( 'Transcribe', 'imagina-player' ) }
							</Button>
						</>
					) : (
						<p className="imgp-editor__hint">
							{ __(
								'Only a file from the media library can be transcribed.',
								'imagina-player'
							) }
						</p>
					) }
				</div>
			) }

			{ message && (
				<Notice
					status={ message.tone }
					isDismissible
					onRemove={ () => setMessage( null ) }
				>
					{ message.text }
				</Notice>
			) }
		</div>
	);
}

export function ChapterTools( { setAttributes }: ToolProps ) {
	const [ open, setOpen ] = useState( false );
	const [ text, setText ] = useState( '' );
	const [ message, setMessage ] = useState< Message >( null );

	const use = ( source: string ): void => {
		const chapters = parseChapters( source );

		if ( 0 === chapters.length ) {
			setMessage( {
				tone: 'error',
				text: __(
					'No chapters there: each line needs a time, such as 1:30 Second part.',
					'imagina-player'
				),
			} );

			return;
		}

		setAttributes( {
			chapters: chapters.map( ( chapter ) => ( {
				start: clockLabel( chapter.start ),
				title: chapter.title,
			} ) ),
		} );
		setOpen( false );
		setText( '' );
		setMessage( {
			tone: 'success',
			text: __( 'Chapters replaced.', 'imagina-player' ),
		} );
	};

	return (
		<div className="imgp-editor__tools">
			<div className="imgp-editor__media-picker">
				<Button
					variant="secondary"
					onClick={ () => setOpen( ! open ) }
					aria-expanded={ open }
				>
					{ __( 'Paste chapters…', 'imagina-player' ) }
				</Button>
				<MediaUploadCheck>
					<MediaUpload
						allowedTypes={ [ 'text' ] }
						onSelect={ ( media: { url?: string } ) => {
							if ( ! media.url ) {
								return;
							}

							window
								.fetch( media.url, {
									credentials: 'same-origin',
								} )
								.then( ( response ) => response.text() )
								.then( use )
								.catch( () =>
									setMessage( {
										tone: 'error',
										text: __(
											'That file could not be read.',
											'imagina-player'
										),
									} )
								);
						} }
						render={ ( { open: choose }: { open: () => void } ) => (
							<Button variant="secondary" onClick={ choose }>
								{ __( 'From a file…', 'imagina-player' ) }
							</Button>
						) }
					/>
				</MediaUploadCheck>
			</div>

			{ open && (
				<div className="imgp-editor__row">
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Chapters', 'imagina-player' ) }
						help={ __(
							'One a line, as in a YouTube description: 0:00 Introduction, 1:30 Second part. They replace the chapters above.',
							'imagina-player'
						) }
						value={ text }
						rows={ 6 }
						onChange={ setText }
					/>
					<Button
						variant="primary"
						onClick={ () => use( text ) }
						disabled={ '' === text.trim() }
					>
						{ __( 'Use these chapters', 'imagina-player' ) }
					</Button>
				</div>
			) }

			{ message && (
				<Notice
					status={ message.tone }
					isDismissible
					onRemove={ () => setMessage( null ) }
				>
					{ message.text }
				</Notice>
			) }
		</div>
	);
}
