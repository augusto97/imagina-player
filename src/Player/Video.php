<?php
/**
 * The video settings that apply to one player.
 *
 * Everything else about a player can be set on the block: colours, which
 * controls appear, what happens at the end. The video half could not, and the
 * reason was structural rather than an oversight — per-block overrides are
 * driven by a map from *preset* keys to attributes, and the video settings are
 * not in a preset. They are a separate group the renderer read straight out of
 * the options table.
 *
 * So one video on a page could not have its controls behave differently from
 * another, and the Video panel in the block had two fields in it: a shape and a
 * poster. A dozen real settings existed and none of them were reachable from
 * the thing they applied to.
 *
 * This is the missing half: the site-wide defaults with the block's own
 * answers laid over them. A block that says nothing inherits, which is what an
 * author who has set their site up once expects.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Player;

use ImaginaPlayer\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Video {

	/**
	 * Video setting => the block attribute that may override it.
	 *
	 * Deliberately not everything in the group. Caption size and background are
	 * a house style rather than a per-video decision, and the privacy domain is
	 * a policy for the whole site; putting those on the block would invite
	 * inconsistency without buying anything.
	 *
	 * @var array<string, string>
	 */
	private const OVERRIDE_MAP = array(
		'big_play'        => 'videoBigPlay',
		'show_fullscreen' => 'videoFullscreen',
		'show_pip'        => 'videoPip',
		'show_speed'      => 'videoSpeed',
		'block_download'  => 'videoBlockDownload',
		'poster_fit'      => 'videoPosterFit',
		'hide_after'      => 'videoHideAfter',
		'show_captions'   => 'videoCaptions',
		'show_chapters'   => 'videoChapters',
		'show_skip'       => 'videoSkip',
		'show_time'       => 'videoTime',
		'show_volume'     => 'videoVolume',
		'show_title'      => 'videoTitle',
		'focus_mode'      => 'videoFocusMode',
		'captions_on'     => 'videoCaptionsOn',
		'caption_size'    => 'videoCaptionSize',
		'caption_bg'      => 'videoCaptionBg',
		'chrome_color'    => 'videoChromeColor',
		'caption_color'   => 'videoCaptionColor',
	);

	/**
	 * @return array<string, string>
	 */
	public static function override_map(): array {
		return self::OVERRIDE_MAP;
	}

	/**
	 * The effective video settings for one player.
	 *
	 * @param array<string, mixed> $atts Sanitised attributes.
	 * @return array<string, mixed>
	 */
	public static function resolve( array $atts ): array {
		$settings = Settings::video();

		foreach ( self::OVERRIDE_MAP as $key => $attribute ) {
			$override = $atts[ $attribute ] ?? Attributes::INHERIT;

			if ( Attributes::INHERIT === $override || null === $override || '' === $override ) {
				continue;
			}

			$default = $settings[ $key ] ?? '';

			$settings[ $key ] = match ( true ) {
				is_bool( $default ) => 'yes' === $override,
				is_int( $default )  => max( 0, (int) $override ),
				default             => (string) $override,
			};

			/*
			 * A block's answer reaches a `style` attribute and a class name, so
			 * it is checked here rather than trusted. The site's own settings
			 * are sanitised on save; these are not saved anywhere.
			 */
			$settings[ $key ] = match ( $key ) {
				'caption_size'  => in_array( $settings[ $key ], array( 'small', 'medium', 'large', 'xlarge' ), true ) ? $settings[ $key ] : 'medium',
				'caption_bg'    => in_array( $settings[ $key ], array( 'solid', 'shadow', 'none' ), true ) ? $settings[ $key ] : 'solid',
				'poster_fit'    => 'contain' === $settings[ $key ] ? 'contain' : 'cover',
				'chrome_color'  => Settings::sanitize_color( (string) $settings[ $key ], '#000000' ),
				'caption_color' => Settings::sanitize_color( (string) $settings[ $key ], '#ffffff' ),
				default         => $settings[ $key ],
			};
		}

		/**
		 * Filter the effective video settings for one player.
		 *
		 * @param array<string, mixed> $settings Effective video settings.
		 * @param array<string, mixed> $atts     Sanitised attributes.
		 */
		return apply_filters( 'imagina_player_video_settings', $settings, $atts );
	}
}
