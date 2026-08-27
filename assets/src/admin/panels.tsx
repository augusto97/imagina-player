import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { generateWaveform, listPendingWaveforms } from './api';
import { Card, Field, Notice, NumberInput, Select, TextInput, Toggle } from './controls';
import type { SettingsPayload } from './types';

interface PanelProps {
	settings: SettingsPayload;
	onChange: ( patch: Partial< SettingsPayload > ) => void;
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
	const generateAll = async (): Promise< void > => {
		setBusy( true );
		setStatus( __( 'Looking for files without a waveform…', 'imagina-player' ) );

		try {
			const { pending } = await listPendingWaveforms();

			if ( ! pending.length ) {
				setStatus( __( 'Every file already has a waveform.', 'imagina-player' ) );

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
					await generateWaveform( pending[ i ].id );
					done++;
				} catch {
					// A file ffmpeg cannot read should not stop the rest.
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
			setStatus( __( 'The list of pending files could not be loaded.', 'imagina-player' ) );
		} finally {
			setBusy( false );
		}
	};

	return (
		<>
			<Card
				title={ __( 'Waveforms', 'imagina-player' ) }
				description={ __( 'How the shape of each track is measured and stored.', 'imagina-player' ) }
			>
				{ settings.system.ffmpeg ? (
					<Notice tone="good">
						{ __( 'ffmpeg was found on this server, so waveforms are generated here.', 'imagina-player' ) }
						{ settings.system.ffmpegBinary && (
							<code> { settings.system.ffmpegBinary }</code>
						) }
					</Notice>
				) : (
					<Notice tone="warn">
						{ __(
							'ffmpeg was not found. Only files small enough to analyse in the visitor’s browser get a waveform; longer recordings show a plain progress bar. Ask your host to install ffmpeg, or set its path below.',
							'imagina-player'
						) }
					</Notice>
				) }

				<Field
					label={ __( 'Bars per waveform', 'imagina-player' ) }
					help={ __( 'How many amplitude samples are stored per track. 400 suits players up to about 1200px wide.', 'imagina-player' ) }
				>
					<NumberInput
						value={ peaks.resolution }
						min={ 32 }
						max={ 2000 }
						onChange={ ( resolution ) => set( { resolution } ) }
					/>
				</Field>

				<Field label={ __( 'ffmpeg path', 'imagina-player' ) } help={ __( 'Leave empty to search the usual locations.', 'imagina-player' ) }>
					<TextInput
						value={ peaks.ffmpeg_path }
						mono
						placeholder="/usr/bin/ffmpeg"
						onChange={ ( ffmpeg_path ) => set( { ffmpeg_path } ) }
					/>
				</Field>

				<div className="imgpa-toggles">
					<Toggle
						label={ __( 'Generate on the server', 'imagina-player' ) }
						help={ __( 'Runs outside the page request, so nobody waits for it.', 'imagina-player' ) }
						checked={ peaks.server_generation }
						onChange={ ( server_generation ) => set( { server_generation } ) }
					/>
					<Toggle
						label={ __( 'Let the browser fill in the gaps', 'imagina-player' ) }
						help={ __( 'The first visitor analyses a missing waveform and the site stores it. Short files only.', 'imagina-player' ) }
						checked={ peaks.client_fallback }
						onChange={ ( client_fallback ) => set( { client_fallback } ) }
					/>
				</div>

				<Field
					label={ __( 'Browser size limit', 'imagina-player' ) }
					help={ __( 'Larger files are never analysed in the browser: decoding expands audio to raw samples in memory, and an hour of stereo is well over a gigabyte.', 'imagina-player' ) }
				>
					<NumberInput
						value={ peaks.max_client_mb }
						min={ 1 }
						max={ 200 }
						suffix="MB"
						onChange={ ( max_client_mb ) => set( { max_client_mb } ) }
					/>
				</Field>
			</Card>

			<Card
				title={ __( 'Generate now', 'imagina-player' ) }
				description={ __( 'Builds waveforms for every audio and video file that does not have one yet, without waiting for scheduled tasks.', 'imagina-player' ) }
			>
				<div className="imgpa-row">
					<button
						type="button"
						className="imgpa-btn"
						disabled={ busy || ! settings.system.ffmpeg }
						onClick={ generateAll }
					>
						{ busy
							? __( 'Working…', 'imagina-player' )
							: __( 'Generate missing waveforms', 'imagina-player' ) }
					</button>
					<span className="imgpa-hint" role="status" aria-live="polite">
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
				description={ __( 'Files you mark as protected move out of the public uploads folder and are served through a signed link that expires. Mark a file on its own screen in the media library.', 'imagina-player' ) }
			>
				<Notice tone="info">
					{ __(
						'This stops the file URL being copied, shared or hotlinked, and can require a login or a membership check. It cannot stop someone who is allowed to listen from recording what they hear — nothing short of DRM can.',
						'imagina-player'
					) }
				</Notice>

				<div className="imgpa-toggles">
					<Toggle
						label={ __( 'Serve protected files through signed links', 'imagina-player' ) }
						checked={ protection.enabled }
						onChange={ ( enabled ) => set( { enabled } ) }
					/>
				</div>

				<Field
					label={ __( 'Link lifetime', 'imagina-player' ) }
					help={ __( 'A shared link stops working after this. Players ask for a fresh one automatically, so page caching stays safe.', 'imagina-player' ) }
				>
					<Select
						value={ String( protection.ttl ) }
						onChange={ ( ttl ) => set( { ttl: Number( ttl ) } ) }
						options={ [
							{ value: String( hour ), label: __( '1 hour', 'imagina-player' ) },
							{ value: String( 6 * hour ), label: __( '6 hours', 'imagina-player' ) },
							{ value: String( 12 * hour ), label: __( '12 hours', 'imagina-player' ) },
							{ value: String( 24 * hour ), label: __( '24 hours', 'imagina-player' ) },
							{ value: String( 168 * hour ), label: __( '7 days', 'imagina-player' ) },
						] }
					/>
				</Field>

				<div className="imgpa-toggles">
					<Toggle
						label={ __( 'Require a logged-in user', 'imagina-player' ) }
						checked={ protection.require_login }
						onChange={ ( require_login ) => set( { require_login } ) }
					/>
					<Toggle
						label={ __( 'Tie each link to the user it was issued to', 'imagina-player' ) }
						checked={ protection.bind_to_user }
						onChange={ ( bind_to_user ) => set( { bind_to_user } ) }
					/>
					<Toggle
						label={ __( 'Tie each link to the visitor’s network', 'imagina-player' ) }
						help={ __( 'Adds a barrier to forwarding, but a listener moving from Wi-Fi to mobile needs a fresh link.', 'imagina-player' ) }
						checked={ protection.bind_to_ip }
						onChange={ ( bind_to_ip ) => set( { bind_to_ip } ) }
					/>
				</div>
			</Card>

			<Card
				title={ __( 'Delivery', 'imagina-player' ) }
				description={ __( 'Streaming through PHP keeps a worker busy for the whole playback. On a site with long tracks, hand the transfer to the web server.', 'imagina-player' ) }
			>
				<Field label={ __( 'Method', 'imagina-player' ) }>
					<Select
						value={ protection.delivery }
						onChange={ ( delivery ) => set( { delivery } ) }
						options={ [
							{ value: 'php', label: __( 'PHP (works everywhere)', 'imagina-player' ) },
							{ value: 'xaccel', label: __( 'X-Accel-Redirect (nginx)', 'imagina-player' ) },
							{ value: 'xsendfile', label: __( 'X-Sendfile (Apache/LiteSpeed)', 'imagina-player' ) },
						] }
					/>
				</Field>

				<Field label={ __( 'X-Accel location', 'imagina-player' ) }>
					<TextInput
						value={ protection.xaccel_prefix }
						mono
						onChange={ ( xaccel_prefix ) => set( { xaccel_prefix } ) }
					/>
				</Field>

				<Field label={ __( 'Protected files live in', 'imagina-player' ) } wide>
					<code className="imgpa-code">{ settings.system.vaultDir }</code>
				</Field>

				{ settings.system.htaccess ? (
					<Notice tone="good">
						{ __( 'This server reads .htaccess, and the plugin writes deny rules into that folder. Nothing else to do.', 'imagina-player' ) }
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
					label={ __( 'Load the bundled stylesheet', 'imagina-player' ) }
					help={ __( 'Turn off only if your theme styles the player itself.', 'imagina-player' ) }
					checked={ advanced.load_frontend_css }
					onChange={ ( load_frontend_css ) => set( { load_frontend_css } ) }
				/>
				<Toggle
					label={ __( 'Only start a player when it scrolls near the viewport', 'imagina-player' ) }
					checked={ advanced.lazy_init }
					onChange={ ( lazy_init ) => set( { lazy_init } ) }
				/>
			</div>

			<Field label={ __( 'Shortcode', 'imagina-player' ) } wide>
				<code className="imgpa-code">
					[imagina_player src=&quot;https://…/track.mp3&quot; title=&quot;…&quot; artist=&quot;…&quot;
					preset=&quot;default&quot;]
				</code>
			</Field>

			<Field label={ __( 'Version', 'imagina-player' ) }>
				<span className="imgpa-hint">{ settings.system.version }</span>
			</Field>
		</Card>
	);
}
