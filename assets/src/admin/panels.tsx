import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { canMeasure, measure } from '../shared/measure';
import {
	generateWaveform,
	listPendingWaveforms,
	runProtectionSelfCheck,
	storeWaveform,
} from './api';
import type { SelfCheckResult } from './api';
import {
	Card,
	ColorInput,
	ColorOrAuto,
	Field,
	MediaInput,
	Notice,
	NumberInput,
	Select,
	TextInput,
	Toggle,
} from './controls';
import { PreviewFrame } from './PreviewFrame';
import type { SettingsPayload } from './types';

interface PanelProps {
	settings: SettingsPayload;
	onChange: ( patch: Partial< SettingsPayload > ) => void;
}

/**
 * Why ffmpeg is unavailable, said in terms of what to do about it.
 *
 * One message for three situations was the bug: a host that forbids running
 * any process, a path typed in wrong, and nothing installed all read as
 * "not found", and only the last one is answered by asking the host to
 * install it.
 * @param state
 */
function ffmpegProblem(
	state: SettingsPayload[ 'system' ][ 'ffmpegState' ]
): string {
	switch ( state ) {
		case 'processes-disabled':
			return __(
				'This host does not let PHP start other programs (popen is in disable_functions), so ffmpeg cannot be used even if it is installed.',
				'imagina-player'
			);
		case 'path-missing':
			return __(
				'The ffmpeg path set below does not point at a file that exists on this server.',
				'imagina-player'
			);
		case 'path-not-ffmpeg':
			return __(
				'The ffmpeg path set below was reachable but did not answer as ffmpeg. Check the path, or clear it to search the usual locations.',
				'imagina-player'
			);
		default:
			return __(
				'ffmpeg is not installed on this server. Ask your host to add it, or set its path below if it lives somewhere unusual.',
				'imagina-player'
			);
	}
}

export function WaveformsPanel( { settings, onChange }: PanelProps ) {
	const [ status, setStatus ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const peaks = settings.peaks;
	const set = ( patch: Partial< SettingsPayload[ 'peaks' ] > ): void =>
		onChange( { peaks: { ...peaks, ...patch } } );

	/**
	 * Sequential, not parallel: each call runs ffmpeg over a whole file, and
	 * twenty at once is how a shared host falls over.
	 */
	/**
	 * Measure one file in this browser and store the result.
	 *
	 * Only reached when the server has no ffmpeg. It downloads the whole file,
	 * which is why progress is reported down to the percent: a ninety-megabyte
	 * recording takes a while, and silence reads as a hang.
	 *
	 * @param item       The pending file.
	 * @param item.id    Its attachment id.
	 * @param item.title Its name, for the progress line.
	 * @param item.url   Where to fetch it from.
	 * @param index      Its place in the run.
	 * @param total      How many there are.
	 */
	const measureHere = async (
		item: { id: number; title: string; url: string },
		index: number,
		total: number
	): Promise< void > => {
		if ( ! item.url ) {
			throw new Error( 'no-url' );
		}

		const result = await measure(
			item.url,
			peaks.resolution,
			( progress ) => {
				setStatus(
					sprintf(
						/* translators: 1: current file number, 2: total, 3: file name, 4: what is happening */
						__( '%1$d of %2$d — %3$s — %4$s', 'imagina-player' ),
						index,
						total,
						item.title,
						'decoding' === progress.stage
							? __( 'measuring', 'imagina-player' )
							: sprintf(
									/* translators: %d: percentage downloaded. */
									__( 'downloading %d%%', 'imagina-player' ),
									Math.max(
										0,
										Math.round( progress.ratio * 100 )
									)
							  )
					)
				);
			}
		);

		await storeWaveform( item.id, result.peaks, result.duration );
	};

	const generateAll = async (): Promise< void > => {
		setBusy( true );
		setStatus(
			__( 'Looking for files without a waveform…', 'imagina-player' )
		);

		try {
			const { pending } = await listPendingWaveforms();

			if ( ! pending.length ) {
				setStatus(
					__( 'Every file already has a waveform.', 'imagina-player' )
				);

				return;
			}

			let done = 0;

			for ( let i = 0; i < pending.length; i++ ) {
				setStatus(
					sprintf(
						/* translators: 1: current file number, 2: total, 3: file name */
						__( 'Generating %1$d of %2$d: %3$s', 'imagina-player' ),
						i + 1,
						pending.length,
						pending[ i ].title
					)
				);

				try {
					if ( settings.system.ffmpeg ) {
						await generateWaveform( pending[ i ].id );
					} else {
						await measureHere(
							pending[ i ],
							i + 1,
							pending.length
						);
					}

					done++;
				} catch {
					// One unreadable file should not stop the rest.
				}
			}

			setStatus(
				sprintf(
					/* translators: 1: number generated, 2: total */
					__( '%1$d of %2$d generated.', 'imagina-player' ),
					done,
					pending.length
				)
			);
		} catch {
			setStatus(
				__(
					'The list of pending files could not be loaded.',
					'imagina-player'
				)
			);
		} finally {
			setBusy( false );
		}
	};

	return (
		<>
			<Card
				title={ __( 'Waveforms', 'imagina-player' ) }
				description={ __(
					'How the shape of each track is measured and stored.',
					'imagina-player'
				) }
			>
				{ settings.system.ffmpeg ? (
					<Notice tone="good">
						{ __(
							'ffmpeg was found on this server, so waveforms are generated here.',
							'imagina-player'
						) }
						{ settings.system.ffmpegBinary && (
							<code> { settings.system.ffmpegBinary }</code>
						) }
					</Notice>
				) : (
					<Notice tone="warn">
						<strong>
							{ ffmpegProblem( settings.system.ffmpegState ) }
						</strong>{ ' ' }
						{ __(
							'Short files still get a waveform from the visitor’s own browser. Long ones do not — nobody browsing a page should download ninety megabytes to look at a picture — so without ffmpeg they show a plain progress bar. You can measure them here instead: “Generate missing waveforms” below downloads each file once, in this browser, and stores the result for everyone.',
							'imagina-player'
						) }
					</Notice>
				) }

				<Field
					label={ __( 'Bars per waveform', 'imagina-player' ) }
					help={ __(
						'How many amplitude samples are stored per track. 400 suits players up to about 1200px wide.',
						'imagina-player'
					) }
				>
					<NumberInput
						value={ peaks.resolution }
						min={ 32 }
						max={ 2000 }
						onChange={ ( resolution ) => set( { resolution } ) }
					/>
				</Field>

				<Field
					label={ __( 'ffmpeg path', 'imagina-player' ) }
					help={ __(
						'Leave empty to search the usual locations.',
						'imagina-player'
					) }
				>
					<TextInput
						value={ peaks.ffmpeg_path }
						mono
						placeholder="/usr/bin/ffmpeg"
						onChange={ ( value ) => set( { ffmpeg_path: value } ) }
					/>
				</Field>

				<div className="imgpa-toggles">
					<Toggle
						label={ __(
							'Generate on the server',
							'imagina-player'
						) }
						help={ __(
							'Runs outside the page request, so nobody waits for it.',
							'imagina-player'
						) }
						checked={ peaks.server_generation }
						onChange={ ( value ) =>
							set( { server_generation: value } )
						}
					/>
					<Toggle
						label={ __(
							'Let the browser fill in the gaps',
							'imagina-player'
						) }
						help={ __(
							'The first visitor analyses a missing waveform and the site stores it. Short files only.',
							'imagina-player'
						) }
						checked={ peaks.client_fallback }
						onChange={ ( value ) =>
							set( { client_fallback: value } )
						}
					/>
				</div>

				<Field
					label={ __( 'Browser size limit', 'imagina-player' ) }
					help={ __(
						'Larger files are never analysed in the browser: decoding expands audio to raw samples in memory, and an hour of stereo is well over a gigabyte.',
						'imagina-player'
					) }
				>
					<NumberInput
						value={ peaks.max_client_mb }
						min={ 1 }
						max={ 200 }
						suffix="MB"
						onChange={ ( value ) =>
							set( { max_client_mb: value } )
						}
					/>
				</Field>
			</Card>

			<Card
				title={ __( 'Generate now', 'imagina-player' ) }
				description={ __(
					'Builds waveforms for every audio and video file that does not have one yet, without waiting for scheduled tasks.',
					'imagina-player'
				) }
			>
				<div className="imgpa-row">
					<button
						type="button"
						className="imgpa-btn"
						disabled={
							busy ||
							( ! settings.system.ffmpeg && ! canMeasure() )
						}
						onClick={ generateAll }
					>
						{ busy
							? __( 'Working…', 'imagina-player' )
							: __(
									'Generate missing waveforms',
									'imagina-player'
							  ) }
					</button>
					<span
						className="imgpa-hint"
						role="status"
						aria-live="polite"
					>
						{ status }
					</span>
				</div>
			</Card>
		</>
	);
}

export function ProtectionPanel( { settings, onChange }: PanelProps ) {
	const protection = settings.protection;
	const set = ( patch: Partial< SettingsPayload[ 'protection' ] > ): void =>
		onChange( { protection: { ...protection, ...patch } } );

	const hour = 3600;

	return (
		<>
			<Card
				title={ __( 'Protected media', 'imagina-player' ) }
				description={ __(
					'Files you mark as protected move out of the public uploads folder and are served through a signed link that expires. Mark a file on its own screen in the media library.',
					'imagina-player'
				) }
			>
				<Notice tone="info">
					{ __(
						'This stops the file URL being copied, shared or hotlinked, and can require a login or a membership check. It cannot stop someone who is allowed to listen from recording what they hear — nothing short of DRM can.',
						'imagina-player'
					) }
				</Notice>

				<div className="imgpa-toggles">
					<Toggle
						label={ __(
							'Serve protected files through signed links',
							'imagina-player'
						) }
						checked={ protection.enabled }
						onChange={ ( enabled ) => set( { enabled } ) }
					/>
				</div>

				<Field
					label={ __( 'Link lifetime', 'imagina-player' ) }
					help={ __(
						'A shared link stops working after this. Players ask for a fresh one automatically, so page caching stays safe.',
						'imagina-player'
					) }
				>
					<Select
						value={ String( protection.ttl ) }
						onChange={ ( ttl ) => set( { ttl: Number( ttl ) } ) }
						options={ [
							{
								value: String( hour ),
								label: __( '1 hour', 'imagina-player' ),
							},
							{
								value: String( 6 * hour ),
								label: __( '6 hours', 'imagina-player' ),
							},
							{
								value: String( 12 * hour ),
								label: __( '12 hours', 'imagina-player' ),
							},
							{
								value: String( 24 * hour ),
								label: __( '24 hours', 'imagina-player' ),
							},
							{
								value: String( 168 * hour ),
								label: __( '7 days', 'imagina-player' ),
							},
						] }
					/>
				</Field>

				<div className="imgpa-toggles">
					<Toggle
						label={ __(
							'Require a logged-in user',
							'imagina-player'
						) }
						checked={ protection.require_login }
						onChange={ ( value ) =>
							set( { require_login: value } )
						}
					/>
					<Toggle
						label={ __(
							'Tie each link to the user it was issued to',
							'imagina-player'
						) }
						checked={ protection.bind_to_user }
						onChange={ ( value ) => set( { bind_to_user: value } ) }
					/>
					<Toggle
						label={ __(
							'Tie each link to the visitor’s network',
							'imagina-player'
						) }
						help={ __(
							'Adds a barrier to forwarding, but a listener moving from Wi-Fi to mobile needs a fresh link.',
							'imagina-player'
						) }
						checked={ protection.bind_to_ip }
						onChange={ ( value ) => set( { bind_to_ip: value } ) }
					/>
				</div>
			</Card>

			<Card
				title={ __( 'Delivery', 'imagina-player' ) }
				description={ __(
					'Streaming through PHP keeps a worker busy for the whole playback. On a site with long tracks, hand the transfer to the web server.',
					'imagina-player'
				) }
			>
				<Field label={ __( 'Method', 'imagina-player' ) }>
					<Select
						value={ protection.delivery }
						onChange={ ( delivery ) => set( { delivery } ) }
						options={ [
							{
								value: 'php',
								label: __(
									'PHP (works everywhere)',
									'imagina-player'
								),
							},
							{
								value: 'xaccel',
								label: __(
									'X-Accel-Redirect (nginx)',
									'imagina-player'
								),
							},
							{
								value: 'xsendfile',
								label: __(
									'X-Sendfile (Apache/LiteSpeed)',
									'imagina-player'
								),
							},
						] }
					/>
				</Field>

				<Field label={ __( 'X-Accel location', 'imagina-player' ) }>
					<TextInput
						value={ protection.xaccel_prefix }
						mono
						onChange={ ( value ) =>
							set( { xaccel_prefix: value } )
						}
					/>
				</Field>

				<Field
					label={ __( 'Protected files live in', 'imagina-player' ) }
					wide
				>
					<code className="imgpa-code">
						{ settings.system.vaultDir }
					</code>
				</Field>

				{ settings.system.htaccess ? (
					<Notice tone="good">
						{ __(
							'This server reads .htaccess, and the plugin writes deny rules into that folder. Nothing else to do.',
							'imagina-player'
						) }
					</Notice>
				) : (
					<Notice tone="warn">
						<p>
							{ __(
								'This server does not appear to read .htaccess. The folder name is unguessable, but add this to your server configuration so the files cannot be reached directly at all:',
								'imagina-player'
							) }
						</p>
						<pre className="imgpa-pre">
							{ `location ^~ /wp-content/uploads/${ settings.system.vaultName }/ {\n\tinternal;\n}` }
							{ 'xaccel' === protection.delivery &&
								`\n\nlocation ${ protection.xaccel_prefix } {\n\tinternal;\n\talias ${ settings.system.vaultDir }/;\n}` }
						</pre>
					</Notice>
				) }
			</Card>

			<SelfCheckCard />
		</>
	);
}

/**
 * Does the protection actually protect?
 *
 * Everything above is a statement of intent. Whether the web server enforces it
 * is a separate question with a well-known wrong answer — nginx never reads the
 * .htaccess the plugin writes — so this asks the server instead of asking the
 * settings. It drops a decoy file in the vault, fetches it over real HTTP with
 * no cookies, and reports the status line.
 */
function SelfCheckCard() {
	const [ result, setResult ] = useState< SelfCheckResult | null >( null );
	const [ running, setRunning ] = useState( false );
	const [ error, setError ] = useState( '' );

	const run = async (): Promise< void > => {
		setRunning( true );
		setError( '' );

		try {
			setResult( await runProtectionSelfCheck() );
		} catch ( failure ) {
			setResult( null );
			setError(
				failure instanceof Error
					? failure.message
					: __( 'The check could not be run.', 'imagina-player' )
			);
		} finally {
			setRunning( false );
		}
	};

	const tone = ( status: SelfCheckResult[ 'status' ] ): 'good' | 'warn' =>
		'pass' === status ? 'good' : 'warn';

	return (
		<Card
			title={ __( 'Check that it works', 'imagina-player' ) }
			description={ __(
				'Tries to reach a protected file the way a stranger would: a real request to this site, from this site, carrying no login. What comes back is what any visitor would get.',
				'imagina-player'
			) }
		>
			<div className="imgpa-row">
				<button
					type="button"
					className="imgpa-btn imgpa-btn--primary"
					onClick={ run }
					disabled={ running }
				>
					{ running
						? __( 'Checking…', 'imagina-player' )
						: __( 'Run the check', 'imagina-player' ) }
				</button>
				{ result && (
					<span className="imgpa-hint">{ result.summary }</span>
				) }
			</div>

			{ '' !== error && <Notice tone="warn">{ error }</Notice> }

			{ result && (
				<>
					<Notice tone={ tone( result.status ) }>
						{ result.summary }
					</Notice>

					<ul className="imgpa-checks">
						{ result.checks.map( ( check ) => (
							<li
								key={ check.id }
								className={ `imgpa-check imgpa-check--${ check.status }` }
							>
								<span
									className="imgpa-check__mark"
									aria-hidden="true"
								>
									{ MARKS[ check.status ] }
								</span>
								<span className="imgpa-check__text">
									<strong>{ check.label }</strong>
									<span className="imgpa-sr">
										{ STATUS_LABELS[ check.status ]() }
									</span>
									{ '' !== check.detail && (
										<span className="imgpa-check__detail">
											{ check.detail }
										</span>
									) }
								</span>
							</li>
						) ) }
					</ul>
				</>
			) }
		</Card>
	);
}

const MARKS: Record< SelfCheckResult[ 'status' ], string > = {
	pass: '✓',
	fail: '✕',
	warn: '!',
	skip: '–',
};

/* Read out by a screen reader, which cannot see the colour or the glyph. */
const STATUS_LABELS: Record< SelfCheckResult[ 'status' ], () => string > = {
	pass: () => __( 'Passed.', 'imagina-player' ),
	fail: () => __( 'Failed.', 'imagina-player' ),
	warn: () => __( 'Needs attention.', 'imagina-player' ),
	skip: () => __( 'Not checked.', 'imagina-player' ),
};

/**
 * Where a track's name comes from when a block does not say.
 *
 * All of this already happened and none of it could be seen or changed: an
 * author saw two empty fields and no reason to believe anything would fill
 * them, which is indistinguishable from the feature not existing.
 * @param root0
 * @param root0.settings
 * @param root0.onChange
 */
export function MetadataPanel( { settings, onChange }: PanelProps ) {
	const metadata = settings.metadata;
	const set = ( patch: Partial< SettingsPayload[ 'metadata' ] > ): void =>
		onChange( { metadata: { ...metadata, ...patch } } );

	return (
		<>
			<Card
				title={ __( 'Track details', 'imagina-player' ) }
				description={ __(
					'What a player shows when you leave the title or the artist empty in the block. Anything you type in the block always wins.',
					'imagina-player'
				) }
			>
				<Field
					label={ __( 'Title', 'imagina-player' ) }
					help={ __(
						'Everything tries the file’s own tags first, then what the file is called in your library, then the file name itself.',
						'imagina-player'
					) }
				>
					<Select
						value={ metadata.title_from }
						onChange={ ( value ) => set( { title_from: value } ) }
						options={ [
							{
								value: 'auto',
								label: __(
									'Whatever is there, in that order',
									'imagina-player'
								),
							},
							{
								value: 'tags',
								label: __(
									'Only the file’s tags',
									'imagina-player'
								),
							},
							{
								value: 'post',
								label: __(
									'Only the media library title',
									'imagina-player'
								),
							},
							{
								value: 'file',
								label: __(
									'Only the file name',
									'imagina-player'
								),
							},
							{
								value: 'none',
								label: __( 'Leave it empty', 'imagina-player' ),
							},
						] }
					/>
				</Field>

				<Field label={ __( 'Artist', 'imagina-player' ) }>
					<Select
						value={ metadata.artist_from }
						onChange={ ( value ) => set( { artist_from: value } ) }
						options={ [
							{
								value: 'auto',
								label: __(
									'From the file’s tags',
									'imagina-player'
								),
							},
							{
								value: 'none',
								label: __( 'Leave it empty', 'imagina-player' ),
							},
						] }
					/>
				</Field>

				<div className="imgpa-toggles">
					<Toggle
						label={ __(
							'Fall back to the file name',
							'imagina-player'
						) }
						help={ __(
							'“2024–03–11_mi-conferencia_01.mp3” becomes “Mi conferencia 01”. It is the only thing a track pasted from a streaming provider has, since there are no tags to read.',
							'imagina-player'
						) }
						checked={ metadata.from_filename }
						onChange={ ( value ) =>
							set( { from_filename: value } )
						}
					/>
					<Toggle
						label={ __(
							'Use the cover art inside the file',
							'imagina-player'
						) }
						help={ __(
							'Audio files often carry their own artwork, and WordPress pulls it out when you upload.',
							'imagina-player'
						) }
						checked={ metadata.use_cover }
						onChange={ ( value ) => set( { use_cover: value } ) }
					/>
				</div>
			</Card>
		</>
	);
}

/**
 * How every video behaves, unless a block says otherwise.
 *
 * These were hardcoded in the renderer until 1.9.0. That is worth saying out
 * loud: the video player worked, and none of it could be changed, which from
 * the outside is indistinguishable from the feature not being finished.
 * @param root0
 * @param root0.settings
 * @param root0.onChange
 */
export function VideoPanel( { settings, onChange }: PanelProps ) {
	const video = settings.video;
	const set = ( patch: Partial< SettingsPayload[ 'video' ] > ): void =>
		onChange( { video: { ...video, ...patch } } );

	return (
		<>
			{ /*
			     The preset editor has had a live preview since the beginning
			     and this screen had none, so every video setting was a guess
			     followed by publishing a post to look at it. It is the same
			     frame, the same renderer, and it takes the settings as they
			     are on screen rather than as they were last saved.
			*/ }
			<Card
				title={ __( 'Preview', 'imagina-player' ) }
				description={ __(
					'The default preset, drawn as a video, with the settings below as they stand now.',
					'imagina-player'
				) }
			>
				<PreviewFrame
					preset={
						/*
						 * Named, not "whichever came first": a site that has
						 * renamed or reordered its presets would otherwise get
						 * a preview of a different one than a block with no
						 * preset chosen actually uses.
						 */
						settings.presets.default ??
						settings.presets[ Object.keys( settings.presets )[ 0 ] ]
					}
					medium="video"
					video={ video as unknown as Record< string, unknown > }
				/>
			</Card>

			<Card
				title={ __( 'The picture', 'imagina-player' ) }
				description={ __(
					'How a video is shaped and what shows before it plays.',
					'imagina-player'
				) }
			>
				<Field
					label={ __( 'Shape', 'imagina-player' ) }
					help={ __(
						'What a block uses when you have not chosen one. The box holds this shape before the video loads, so the page does not jump.',
						'imagina-player'
					) }
				>
					<Select
						value={ video.ratio }
						onChange={ ( value ) => set( { ratio: value } ) }
						options={ [
							{
								value: '16:9',
								label: __(
									'Widescreen (16:9)',
									'imagina-player'
								),
							},
							{
								value: '4:3',
								label: __( 'Classic (4:3)', 'imagina-player' ),
							},
							{
								value: '1:1',
								label: __( 'Square (1:1)', 'imagina-player' ),
							},
							{
								value: '9:16',
								label: __(
									'Vertical (9:16)',
									'imagina-player'
								),
							},
						] }
					/>
				</Field>

				<Field
					label={ __( 'Poster', 'imagina-player' ) }
					help={ __(
						'Crop fills the box and cuts the edges. Fit shows the whole image and lets the black show through.',
						'imagina-player'
					) }
				>
					<Select
						value={ video.poster_fit }
						onChange={ ( value ) => set( { poster_fit: value } ) }
						options={ [
							{
								value: 'cover',
								label: __( 'Crop to fill', 'imagina-player' ),
							},
							{
								value: 'contain',
								label: __(
									'Fit whole image',
									'imagina-player'
								),
							},
						] }
					/>
				</Field>

				<div className="imgpa-toggles">
					<Toggle
						label={ __(
							'Big play button over the picture',
							'imagina-player'
						) }
						help={ __(
							'Turn off for a bare picture with only the control bar.',
							'imagina-player'
						) }
						checked={ video.big_play }
						onChange={ ( value ) => set( { big_play: value } ) }
					/>
				</div>
			</Card>

			<Card
				title={ __( 'Controls', 'imagina-player' ) }
				description={ __(
					'What appears on the bar over the video, and how long it stays.',
					'imagina-player'
				) }
			>
				<Field
					label={ __( 'Hide the controls after', 'imagina-player' ) }
					help={ __(
						'Seconds of stillness while playing. Zero keeps them up for good, which suits a lesson more than a film.',
						'imagina-player'
					) }
				>
					<NumberInput
						value={ Math.round( video.hide_after / 100 ) / 10 }
						min={ 0 }
						max={ 20 }
						step={ 0.5 }
						suffix="s"
						onChange={ ( value ) =>
							set( { hide_after: Math.round( value * 1000 ) } )
						}
					/>
				</Field>

				<div className="imgpa-toggles">
					<Toggle
						label={ __( 'Full screen', 'imagina-player' ) }
						checked={ video.show_fullscreen }
						onChange={ ( value ) =>
							set( { show_fullscreen: value } )
						}
					/>
					<Toggle
						label={ __( 'Picture in picture', 'imagina-player' ) }
						help={ __(
							'The button only appears where the browser supports it.',
							'imagina-player'
						) }
						checked={ video.show_pip }
						onChange={ ( value ) => set( { show_pip: value } ) }
					/>
					<Toggle
						label={ __( 'Playback speed', 'imagina-player' ) }
						checked={ video.show_speed }
						onChange={ ( value ) => set( { show_speed: value } ) }
					/>
				</div>
			</Card>

			<Card
				title={ __( 'Subtitles', 'imagina-player' ) }
				description={ __(
					'How captions look over the picture. They are added per video, in the block.',
					'imagina-player'
				) }
			>
				<Field label={ __( 'Size', 'imagina-player' ) }>
					<Select
						value={ video.caption_size }
						onChange={ ( value ) => set( { caption_size: value } ) }
						options={ [
							{
								value: 'small',
								label: __( 'Small', 'imagina-player' ),
							},
							{
								value: 'medium',
								label: __( 'Medium', 'imagina-player' ),
							},
							{
								value: 'large',
								label: __( 'Large', 'imagina-player' ),
							},
						] }
					/>
				</Field>

				<Field
					label={ __( 'Behind the text', 'imagina-player' ) }
					help={ __(
						'A solid band is the most readable over any footage. A shadow is lighter but fails over busy, bright shots.',
						'imagina-player'
					) }
				>
					<Select
						value={ video.caption_bg }
						onChange={ ( value ) => set( { caption_bg: value } ) }
						options={ [
							{
								value: 'solid',
								label: __( 'Solid band', 'imagina-player' ),
							},
							{
								value: 'shadow',
								label: __( 'Shadow only', 'imagina-player' ),
							},
							{
								value: 'none',
								label: __( 'Nothing', 'imagina-player' ),
							},
						] }
					/>
				</Field>
			</Card>

			<Card
				title={ __( 'Keeping the file', 'imagina-player' ) }
				description={ __(
					'What stops a visitor walking off with the video itself.',
					'imagina-player'
				) }
			>
				<div className="imgpa-toggles">
					<Toggle
						label={ __( 'Title on the bar', 'imagina-player' ) }
						checked={ Boolean( video.show_title ) }
						onChange={ ( value ) => set( { show_title: value } ) }
					/>
					<Toggle
						label={ __(
							'Elapsed and total time',
							'imagina-player'
						) }
						checked={ Boolean( video.show_time ) }
						onChange={ ( value ) => set( { show_time: value } ) }
					/>
					<Toggle
						label={ __(
							'Skip back and forward',
							'imagina-player'
						) }
						checked={ Boolean( video.show_skip ) }
						onChange={ ( value ) => set( { show_skip: value } ) }
					/>
					<Toggle
						label={ __( 'Volume', 'imagina-player' ) }
						checked={ Boolean( video.show_volume ) }
						onChange={ ( value ) => set( { show_volume: value } ) }
					/>
					<Toggle
						label={ __( 'Subtitles button', 'imagina-player' ) }
						help={ __(
							'Only appears on a video that actually carries subtitles.',
							'imagina-player'
						) }
						checked={ Boolean( video.show_captions ) }
						onChange={ ( value ) =>
							set( { show_captions: value } )
						}
					/>
					<Toggle
						label={ __( 'Chapters button', 'imagina-player' ) }
						checked={ Boolean( video.show_chapters ) }
						onChange={ ( value ) =>
							set( { show_chapters: value } )
						}
					/>
					<Toggle
						label={ __( 'Search what is said', 'imagina-player' ) }
						help={ __(
							'A box that finds the moment a word is spoken and jumps to it. Uses the subtitles the video already carries, so there is nothing to index and nothing extra to download.',
							'imagina-player'
						) }
						checked={ Boolean( video.show_search ) }
						onChange={ ( value ) => set( { show_search: value } ) }
					/>
					<Toggle
						label={ __(
							'Subtitles on from the start',
							'imagina-player'
						) }
						help={ __(
							'For an audience that mostly watches with the sound off. A viewer who turns them off is remembered, and this does not override that.',
							'imagina-player'
						) }
						checked={ Boolean( video.captions_on ) }
						onChange={ ( value ) => set( { captions_on: value } ) }
					/>
					<Toggle
						label={ __(
							'Stop when it leaves the screen',
							'imagina-player'
						) }
						help={ __(
							'Pauses when the tab goes to the background or the picture scrolls away. It does not start again by itself.',
							'imagina-player'
						) }
						checked={ Boolean( video.focus_mode ) }
						onChange={ ( value ) => set( { focus_mode: value } ) }
					/>
					<Toggle
						label={ __(
							'Take away the browser download button',
							'imagina-player'
						) }
						help={ __(
							'Also removes “Save video as” and casting the raw file to a device. It has no effect on a player that deliberately offers a download.',
							'imagina-player'
						) }
						checked={ video.block_download }
						onChange={ ( value ) =>
							set( { block_download: value } )
						}
					/>
				</div>

				<Notice tone="info">
					{ __(
						'None of this stops a screen recorder, and neither does DRM. What protects the file is that it lives outside the public folder and its link expires — see Protection.',
						'imagina-player'
					) }
				</Notice>
			</Card>

			<Card
				title={ __( 'Colours and subtitles', 'imagina-player' ) }
				description={ __(
					'How the bar over the picture and the subtitles are painted. Every block can answer differently; this is what one that says nothing uses.',
					'imagina-player'
				) }
			>
				<Field label={ __( 'Control bar', 'imagina-player' ) }>
					<ColorInput
						value={ String( video.chrome_color ?? '#000000' ) }
						onChange={ ( value: string ) =>
							set( { chrome_color: value } )
						}
					/>
				</Field>

				<Field
					label={ __( 'Buttons and times', 'imagina-player' ) }
					help={ __(
						'The icons and the clock on the bar, the rail of the seek bar, and the volume slider’s groove.',
						'imagina-player'
					) }
				>
					<ColorOrAuto
						value={ String( video.control_color ?? 'auto' ) }
						onChange={ ( value: string ) =>
							set( { control_color: value } )
						}
						fallback="#ffffff"
						autoLabel={ __(
							'Whichever of black or white reads on the control bar.',
							'imagina-player'
						) }
					/>
				</Field>

				<Field
					label={ __( 'Played portion', 'imagina-player' ) }
					help={ __(
						'The filled part of the seek bar as the video plays, and the volume knob beside it.',
						'imagina-player'
					) }
				>
					<ColorOrAuto
						value={ String( video.progress_color ?? 'auto' ) }
						onChange={ ( value: string ) =>
							set( { progress_color: value } )
						}
						fallback="#1f2937"
						autoLabel={ __(
							'The preset’s accent colour.',
							'imagina-player'
						) }
					/>
				</Field>

				<Field label={ __( 'Subtitles', 'imagina-player' ) }>
					<ColorInput
						value={ String( video.caption_color ?? '#ffffff' ) }
						onChange={ ( value: string ) =>
							set( { caption_color: value } )
						}
					/>
				</Field>

				<Field label={ __( 'Subtitle size', 'imagina-player' ) }>
					<Select
						value={ String( video.caption_size ?? 'medium' ) }
						onChange={ ( value ) => set( { caption_size: value } ) }
						options={ [
							{
								value: 'small',
								label: __( 'Small', 'imagina-player' ),
							},
							{
								value: 'medium',
								label: __( 'Medium', 'imagina-player' ),
							},
							{
								value: 'large',
								label: __( 'Large', 'imagina-player' ),
							},
							{
								value: 'xlarge',
								label: __( 'Very large', 'imagina-player' ),
							},
						] }
					/>
				</Field>

				<Field
					label={ __( 'Behind the subtitles', 'imagina-player' ) }
					help={ __(
						'A solid band reads over any footage. A shadow is lighter but struggles over a bright, busy shot.',
						'imagina-player'
					) }
				>
					<Select
						value={ String( video.caption_bg ?? 'solid' ) }
						onChange={ ( value ) => set( { caption_bg: value } ) }
						options={ [
							{
								value: 'solid',
								label: __( 'Solid band', 'imagina-player' ),
							},
							{
								value: 'shadow',
								label: __( 'Shadow only', 'imagina-player' ),
							},
							{
								value: 'none',
								label: __( 'Nothing', 'imagina-player' ),
							},
						] }
					/>
				</Field>
			</Card>

			<Card
				title={ __( 'YouTube and Vimeo', 'imagina-player' ) }
				description={ __(
					'Paste the address of a video into a Video block and it plays here, with your own controls and your own calls to action.',
					'imagina-player'
				) }
			>
				<div className="imgpa-toggles">
					<Toggle
						label={ __(
							'Use YouTube’s no-cookie domain',
							'imagina-player'
						) }
						help={ __(
							'Loads the video from youtube-nocookie.com, which sets nothing until a visitor presses play. Turn this off only if you need YouTube’s own analytics for these videos.',
							'imagina-player'
						) }
						checked={ Boolean( video.provider_privacy ) }
						onChange={ ( value ) =>
							set( { provider_privacy: value } )
						}
					/>
				</div>

				<Notice tone="info">
					{ __(
						'Nothing is requested from YouTube or Vimeo until somebody presses play — the page shows their still image until then, so a video nobody watches costs your visitors nothing. These videos are not files on your site, so the protection above does not apply to them.',
						'imagina-player'
					) }
				</Notice>
			</Card>
		</>
	);
}

/**
 * Site-wide brand defaults.
 *
 * These do not restyle existing presets — that would silently rewrite work
 * somebody already did. They are what a *new* preset starts from, plus the logo
 * every player carries.
 * @param root0
 * @param root0.settings
 * @param root0.onChange
 */
export function BrandingPanel( { settings, onChange }: PanelProps ) {
	const branding = settings.branding;
	const set = ( patch: Partial< SettingsPayload[ 'branding' ] > ): void =>
		onChange( { branding: { ...branding, ...patch } } );

	return (
		<>
			<Card
				title={ __( 'Brand colours', 'imagina-player' ) }
				description={ __(
					'What a new preset starts from. Existing presets keep the colours you already gave them.',
					'imagina-player'
				) }
			>
				<Field
					label={ __( 'Accent', 'imagina-player' ) }
					help={ __(
						'Play button and highlights.',
						'imagina-player'
					) }
				>
					<ColorInput
						value={ branding.accent }
						onChange={ ( accent ) => set( { accent } ) }
					/>
				</Field>
				<Field label={ __( 'Waveform', 'imagina-player' ) }>
					<ColorInput
						value={ branding.wave_color }
						onChange={ ( value ) => set( { wave_color: value } ) }
					/>
				</Field>
				<Field label={ __( 'Title', 'imagina-player' ) }>
					<ColorInput
						value={ branding.text_color }
						onChange={ ( value ) => set( { text_color: value } ) }
					/>
				</Field>
				<Field label={ __( 'Artist', 'imagina-player' ) }>
					<ColorInput
						value={ branding.meta_color }
						onChange={ ( value ) => set( { meta_color: value } ) }
					/>
				</Field>
				<Field
					label={ __( 'Buttons', 'imagina-player' ) }
					help={ __(
						'Mute, skip, speed and download, and the rail the volume slider runs along.',
						'imagina-player'
					) }
				>
					<ColorInput
						value={ branding.control_color }
						onChange={ ( value ) =>
							set( { control_color: value } )
						}
					/>
				</Field>
			</Card>

			<Card
				title={ __( 'Logo', 'imagina-player' ) }
				description={ __(
					'Shown at the end of the control row on every player. Leave empty for none.',
					'imagina-player'
				) }
			>
				<Field label={ __( 'Image', 'imagina-player' ) } wide>
					<MediaInput
						value={ branding.logo }
						placeholder="https://…/logo.svg"
						title={ __( 'Choose a logo', 'imagina-player' ) }
						onChange={ ( logo ) => set( { logo } ) }
					/>
				</Field>
				<Field label={ __( 'Links to', 'imagina-player' ) } wide>
					<TextInput
						value={ branding.logo_link }
						mono
						placeholder="https://…"
						onChange={ ( value ) => set( { logo_link: value } ) }
					/>
				</Field>
				<Field label={ __( 'Height', 'imagina-player' ) }>
					<NumberInput
						value={ branding.logo_height }
						min={ 8 }
						max={ 80 }
						suffix="px"
						onChange={ ( value ) => set( { logo_height: value } ) }
					/>
				</Field>
			</Card>
		</>
	);
}

export function AdvancedPanel( { settings, onChange }: PanelProps ) {
	const advanced = settings.advanced;
	const set = ( patch: Partial< SettingsPayload[ 'advanced' ] > ): void =>
		onChange( { advanced: { ...advanced, ...patch } } );

	return (
		<Card title={ __( 'Advanced', 'imagina-player' ) }>
			<div className="imgpa-toggles">
				<Toggle
					label={ __(
						'Load the bundled stylesheet',
						'imagina-player'
					) }
					help={ __(
						'Turn off only if your theme styles the player itself.',
						'imagina-player'
					) }
					checked={ advanced.load_frontend_css }
					onChange={ ( value ) =>
						set( { load_frontend_css: value } )
					}
				/>
				<Toggle
					label={ __(
						'Only start a player when it scrolls near the viewport',
						'imagina-player'
					) }
					checked={ advanced.lazy_init }
					onChange={ ( value ) => set( { lazy_init: value } ) }
				/>
			</div>

			<Field
				label={ __( 'Custom CSS', 'imagina-player' ) }
				help={ __(
					'Added after the player stylesheet, on pages that contain a player.',
					'imagina-player'
				) }
				wide
			>
				<textarea
					className="imgpa-textarea"
					rows={ 8 }
					spellCheck={ false }
					value={ advanced.custom_css }
					placeholder={
						'.imgp__play { box-shadow: 0 2px 12px rgb(0 0 0 / 20%); }'
					}
					onChange={ ( event ) =>
						set( { custom_css: event.target.value } )
					}
				/>
			</Field>

			<Field label={ __( 'Shortcode', 'imagina-player' ) } wide>
				<code className="imgpa-code">
					[imagina_player src=&quot;https://…/track.mp3&quot;
					title=&quot;…&quot; artist=&quot;…&quot;
					preset=&quot;default&quot;]
				</code>
			</Field>

			<Field label={ __( 'Version', 'imagina-player' ) }>
				<span className="imgpa-hint">{ settings.system.version }</span>
			</Field>
		</Card>
	);
}
