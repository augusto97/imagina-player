/**
 * Where captured addresses are sent besides this site's own table.
 *
 * Its own save, apart from the rest of the settings: the keys are kept in an
 * option of their own and never sent to this screen whole, only their last
 * four characters. Typing a new key replaces the old one; emptying the field
 * removes it.
 */

import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { dateI18n } from '@wordpress/date';

import { loadIntegrations, saveIntegrations, testIntegration } from './api';
import type { IntegrationsPayload, ServiceName } from './api';
import { Card, Field, Notice, TextInput, Toggle } from './controls';

interface FieldDef {
	key: string;
	label: string;
	help?: string;
	kind: 'text' | 'secret' | 'toggle';
	placeholder?: string;
}

interface ServiceDef {
	id: ServiceName;
	title: string;
	description: string;
	fields: FieldDef[];
}

function services(): ServiceDef[] {
	return [
		{
			id: 'mailchimp',
			title: 'Mailchimp',
			description: __(
				'Adds each address to an audience, or updates it if it is already there.',
				'imagina-player'
			),
			fields: [
				{
					key: 'api_key',
					kind: 'secret',
					label: __( 'API key', 'imagina-player' ),
					help: __(
						'Account → Extras → API keys. It ends in the data centre, such as -us21.',
						'imagina-player'
					),
				},
				{
					key: 'list_id',
					kind: 'text',
					label: __( 'Audience ID', 'imagina-player' ),
					help: __(
						'Audience → Settings → Audience name and defaults.',
						'imagina-player'
					),
				},
				{
					key: 'double_optin',
					kind: 'toggle',
					label: __(
						'Double opt-in: they confirm by email before they are added',
						'imagina-player'
					),
				},
				{
					key: 'tag_with_list',
					kind: 'toggle',
					label: __(
						'Tag each address with the list name set on its email gate',
						'imagina-player'
					),
				},
			],
		},
		{
			id: 'brevo',
			title: 'Brevo',
			description: __(
				'Creates the contact, or updates it, and adds it to the lists given.',
				'imagina-player'
			),
			fields: [
				{
					key: 'api_key',
					kind: 'secret',
					label: __( 'API key', 'imagina-player' ),
					help: __( 'SMTP & API → API keys.', 'imagina-player' ),
				},
				{
					key: 'list_ids',
					kind: 'text',
					label: __( 'List IDs', 'imagina-player' ),
					placeholder: '3, 7',
					help: __(
						'Numbers, separated by commas.',
						'imagina-player'
					),
				},
			],
		},
		{
			id: 'mailerlite',
			title: 'MailerLite',
			description: __(
				'Adds the subscriber, or updates it, and puts it in the groups given.',
				'imagina-player'
			),
			fields: [
				{
					key: 'api_key',
					kind: 'secret',
					label: __( 'API token', 'imagina-player' ),
					help: __( 'Integrations → API.', 'imagina-player' ),
				},
				{
					key: 'group_ids',
					kind: 'text',
					label: __( 'Group IDs', 'imagina-player' ),
					placeholder: '117458921364260810',
					help: __(
						'Numbers, separated by commas.',
						'imagina-player'
					),
				},
			],
		},
		{
			id: 'fluentcrm',
			title: 'FluentCRM',
			description: __(
				'On this same site: creates or updates the contact, with its lists and tags.',
				'imagina-player'
			),
			fields: [
				{
					key: 'list_ids',
					kind: 'text',
					label: __( 'List IDs', 'imagina-player' ),
					placeholder: '1, 4',
					help: __(
						'Numbers, separated by commas.',
						'imagina-player'
					),
				},
				{
					key: 'tag_ids',
					kind: 'text',
					label: __( 'Tag IDs', 'imagina-player' ),
					placeholder: '2',
					help: __(
						'Numbers, separated by commas.',
						'imagina-player'
					),
				},
				{
					key: 'double_optin',
					kind: 'toggle',
					label: __(
						'Double opt-in: they confirm by email before they are added',
						'imagina-player'
					),
				},
			],
		},
		{
			id: 'webhook',
			title: __( 'Webhook', 'imagina-player' ),
			description: __(
				'Posts each address as JSON to any address: Zapier, Make, n8n or your own.',
				'imagina-player'
			),
			fields: [
				{
					key: 'url',
					kind: 'text',
					label: __( 'Address', 'imagina-player' ),
					placeholder: 'https://hooks.zapier.com/…',
				},
				{
					key: 'secret',
					kind: 'secret',
					label: __( 'Secret', 'imagina-player' ),
					help: __(
						'Optional. Each request then carries X-Imagina-Signature: sha256= and the HMAC of its body with this secret, so the receiver can tell it came from this site.',
						'imagina-player'
					),
				},
			],
		},
		{
			id: 'transcription',
			title: __( 'Automatic transcription', 'imagina-player' ),
			description: __(
				'For the video block’s “Transcribe automatically”: any service that answers OpenAI’s transcription API — OpenAI’s Whisper, Groq, or a Whisper server of your own. The sound is sent to it, and the subtitles it writes are saved to the media library.',
				'imagina-player'
			),
			fields: [
				{
					key: 'endpoint',
					kind: 'text',
					label: __( 'Address', 'imagina-player' ),
					placeholder:
						'https://api.openai.com/v1/audio/transcriptions',
				},
				{
					key: 'api_key',
					kind: 'secret',
					label: __( 'API key', 'imagina-player' ),
				},
				{
					key: 'model',
					kind: 'text',
					label: __( 'Model', 'imagina-player' ),
					placeholder: 'whisper-1',
				},
				{
					key: 'language',
					kind: 'text',
					label: __( 'Usual language', 'imagina-player' ),
					placeholder: 'es',
					help: __(
						'A two-letter code. Empty lets the service tell; one given in the block wins.',
						'imagina-player'
					),
				},
			],
		},
	];
}

export function IntegrationsPanel() {
	const [ data, setData ] = useState< IntegrationsPayload | null >( null );
	const [ dirty, setDirty ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ saved, setSaved ] = useState( false );
	const [ results, setResults ] = useState<
		Partial< Record< ServiceName, { ok: boolean; message: string } > >
	>( {} );
	const [ testing, setTesting ] = useState< ServiceName | '' >( '' );

	useEffect( () => {
		loadIntegrations()
			.then( setData )
			.catch( () =>
				setError( __( 'These could not be loaded.', 'imagina-player' ) )
			);
	}, [] );

	if ( ! data ) {
		return error ? <Notice tone="warn">{ error }</Notice> : null;
	}

	const set = (
		service: ServiceName,
		key: string,
		value: string | boolean
	): void => {
		setData( {
			...data,
			services: {
				...data.services,
				[ service ]: { ...data.services[ service ], [ key ]: value },
			},
		} );
		setDirty( true );
		setSaved( false );
	};

	const save = async (): Promise< boolean > => {
		setSaving( true );
		setError( '' );

		try {
			setData( await saveIntegrations( data.services ) );
			setDirty( false );
			setSaved( true );

			return true;
		} catch {
			setError(
				__( 'These could not be saved. Try again.', 'imagina-player' )
			);

			return false;
		} finally {
			setSaving( false );
		}
	};

	// What is tried is what is saved, so unsaved changes are saved first.
	const test = async ( service: ServiceName ): Promise< void > => {
		if ( dirty && ! ( await save() ) ) {
			return;
		}

		setTesting( service );

		try {
			const result = await testIntegration( service );

			setResults( ( current ) => ( {
				...current,
				[ service ]: result,
			} ) );
		} catch {
			setResults( ( current ) => ( {
				...current,
				[ service ]: {
					ok: false,
					message: __(
						'The test could not be run.',
						'imagina-player'
					),
				},
			} ) );
		} finally {
			setTesting( '' );
		}
	};

	return (
		<>
			<Card
				title={ __( 'Send captured emails to', 'imagina-player' ) }
				description={ __(
					'Every address is kept here first. Then it is sent to each service switched on below, in the background, and tried again three times if a service does not answer.',
					'imagina-player'
				) }
			>
				<div className="imgpa-row">
					<button
						type="button"
						className="imgpa-btn imgpa-btn--primary"
						onClick={ () => void save() }
						disabled={ saving || ! dirty }
					>
						{ saving
							? __( 'Saving…', 'imagina-player' )
							: __( 'Save connections', 'imagina-player' ) }
					</button>
					{ saved && (
						<span className="imgpa-saved">
							{ __( 'Saved.', 'imagina-player' ) }
						</span>
					) }
					{ error && <span className="imgpa-error">{ error }</span> }
				</div>
			</Card>

			{ services().map( ( service ) => {
				const values = data.services[ service.id ] ?? {};
				const log = data.log[ service.id ];
				const result = results[ service.id ];
				const missing = 'fluentcrm' === service.id && ! data.fluentcrm;

				return (
					<Card
						key={ service.id }
						title={ service.title }
						description={ service.description }
					>
						{ missing && (
							<Notice tone="info">
								{ __(
									'FluentCRM is not active on this site.',
									'imagina-player'
								) }
							</Notice>
						) }

						<Toggle
							checked={ Boolean( values.enabled ) }
							onChange={ ( value ) =>
								set( service.id, 'enabled', value )
							}
							label={
								'transcription' === service.id
									? __(
											'Offer it in the video block',
											'imagina-player'
									  )
									: __(
											'Send new addresses here',
											'imagina-player'
									  )
							}
						/>

						{ service.fields.map( ( field ) =>
							'toggle' === field.kind ? (
								<Toggle
									key={ field.key }
									checked={ Boolean( values[ field.key ] ) }
									onChange={ ( value ) =>
										set( service.id, field.key, value )
									}
									label={ field.label }
								/>
							) : (
								<Field
									key={ field.key }
									label={ field.label }
									help={ field.help }
								>
									<TextInput
										value={ String(
											values[ field.key ] ?? ''
										) }
										placeholder={ field.placeholder }
										mono={ 'secret' === field.kind }
										onChange={ ( value ) =>
											set( service.id, field.key, value )
										}
									/>
								</Field>
							)
						) }

						<div className="imgpa-row">
							<button
								type="button"
								className="imgpa-btn"
								onClick={ () => void test( service.id ) }
								disabled={ '' !== testing }
							>
								{ testing === service.id
									? __( 'Trying…', 'imagina-player' )
									: __(
											'Test the connection',
											'imagina-player'
									  ) }
							</button>
						</div>

						{ result && (
							<Notice tone={ result.ok ? 'good' : 'warn' }>
								{ result.message }
							</Notice>
						) }

						{ log && (
							<p className="imgpa-hint">
								{ log.ok
									? sprintf(
											/* translators: 1: when, 2: a shortened email address, 3: what the service answered. */
											__(
												'Last sent %1$s, %2$s: %3$s',
												'imagina-player'
											),
											dateI18n(
												'j M Y, H:i',
												log.time * 1000,
												undefined
											),
											log.email,
											log.message
									  )
									: sprintf(
											/* translators: 1: when, 2: a shortened email address, 3: why it failed. */
											__(
												'Last attempt %1$s, %2$s, failed: %3$s',
												'imagina-player'
											),
											dateI18n(
												'j M Y, H:i',
												log.time * 1000,
												undefined
											),
											log.email,
											log.message
									  ) }
							</p>
						) }
					</Card>
				);
			} ) }
		</>
	);
}
