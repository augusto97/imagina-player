/**
 * Who may play a player, and what everybody else is offered.
 *
 * One panel for the audio, video and playlist blocks: the rule is the same
 * for all three, and so is what a locked player shows.
 */

import {
	Notice,
	PanelBody,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

interface Rule {
	label: string;
	ids: string;
	available: boolean;
}

interface AccessPanelProps {
	attributes: Record< string, unknown >;
	setAttributes: ( next: Record< string, unknown > ) => void;
}

function rules(): Record< string, Rule > {
	return (
		(
			window as unknown as {
				imaginaPlayerEditor?: { access?: Record< string, Rule > };
			}
		 ).imaginaPlayerEditor?.access ?? {}
	);
}

export function AccessPanel( { attributes, setAttributes }: AccessPanelProps ) {
	const all = rules();
	const rule = String( attributes.accessRule ?? '' );
	const current = all[ rule ];

	return (
		<PanelBody
			title={ __( 'Who can play it', 'imagina-player' ) }
			initialOpen={ false }
		>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Plays for', 'imagina-player' ) }
				value={ rule }
				options={ [
					{ value: '', label: __( 'Everyone', 'imagina-player' ) },
					...Object.entries( all ).map( ( [ value, one ] ) => ( {
						value,
						// A rule whose plugin is not here is shown, named as
						// such, rather than hidden: an author who expected it
						// learns why it is not working.
						label: one.available
							? one.label
							: sprintf(
									/* translators: %s: the rule's name. */
									__( '%s — not active', 'imagina-player' ),
									one.label
							  ),
						disabled: ! one.available && value !== rule,
					} ) ),
				] }
				onChange={ ( value: string ) =>
					setAttributes( { accessRule: value } )
				}
			/>

			{ current && ! current.available && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The plugin this rule needs is not active, so nobody but editors can play this.',
						'imagina-player'
					) }
				</Notice>
			) }

			{ '' !== rule && (
				<>
					{ current?.ids && (
						<TextControl
							__nextHasNoMarginBottom
							label={
								'roles' === rule
									? __( 'Roles', 'imagina-player' )
									: __( 'IDs', 'imagina-player' )
							}
							help={ current.ids }
							value={ String( attributes.accessIds ?? '' ) }
							onChange={ ( value: string ) =>
								setAttributes( { accessIds: value } )
							}
						/>
					) }
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Message', 'imagina-player' ) }
						help={ __(
							'What everybody else sees where the player would be. Empty says it in a sentence that suits the rule.',
							'imagina-player'
						) }
						value={ String( attributes.accessMessage ?? '' ) }
						onChange={ ( value: string ) =>
							setAttributes( { accessMessage: value } )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Button label', 'imagina-player' ) }
						value={ String( attributes.accessButton ?? '' ) }
						onChange={ ( value: string ) =>
							setAttributes( { accessButton: value } )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Button links to', 'imagina-player' ) }
						help={ __(
							'The sales page, say. Empty goes to the product or the course the rule names, when it names one.',
							'imagina-player'
						) }
						placeholder="https://…"
						value={ String( attributes.accessUrl ?? '' ) }
						onChange={ ( value: string ) =>
							setAttributes( { accessUrl: value } )
						}
					/>
					<p className="imgp-editor__hint">
						{ __(
							'Whoever can edit this post always sees the player, so this preview shows it playing. Visitors who may not play it get no part of the file in the page: no address, no subtitles, no download.',
							'imagina-player'
						) }
					</p>
				</>
			) }
		</PanelBody>
	);
}
