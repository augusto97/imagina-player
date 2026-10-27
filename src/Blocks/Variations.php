<?php
/**
 * Block variations: the same three blocks, set up for what they are most
 * often used for.
 *
 * A variation is a block with some of its settings already chosen, offered
 * in the inserter under its own name. Registered here rather than in
 * `block.json` so that its title and description are translated like every
 * other string in the plugin, and given to WordPress only when it asks for
 * them.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Variations {

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function audio(): array {
		return array(
			array(
				'name'        => 'podcast',
				'title'       => __( 'Podcast episode', 'imagina-player' ),
				'description' => __( 'With its cover, the speed and skip buttons, and picking up where the listener stopped.', 'imagina-player' ),
				'icon'        => 'microphone',
				'keywords'    => array( __( 'podcast', 'imagina-player' ), __( 'episode', 'imagina-player' ) ),
				'attributes'  => array(
					'skin'             => 'card',
					'showSpeed'        => 'yes',
					'showSkip'         => 'yes',
					'rememberPosition' => 'yes',
				),
				'isActive'    => array( 'skin', 'rememberPosition' ),
				'scope'       => array( 'inserter', 'transform' ),
			),
			array(
				'name'        => 'clip',
				'title'       => __( 'Short clip', 'imagina-player' ),
				'description' => __( 'One line: a play button, the title and a slim bar. For a sample or a voice note in the text.', 'imagina-player' ),
				'icon'        => 'format-audio',
				'attributes'  => array(
					'skin'         => 'compact',
					'showMore'     => 'no',
					'showDownload' => 'no',
				),
				'isActive'    => array( 'skin' ),
				'scope'       => array( 'inserter', 'transform' ),
			),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function video(): array {
		return array(
			array(
				'name'        => 'lesson',
				'title'       => __( 'Lesson', 'imagina-player' ),
				'description' => __( 'With its transcript and chapters, a speed list, and picking up where the student stopped.', 'imagina-player' ),
				'icon'        => 'welcome-learn-more',
				'keywords'    => array( __( 'course', 'imagina-player' ), __( 'class', 'imagina-player' ) ),
				'attributes'  => array(
					'videoTranscript'  => 'yes',
					'videoChapters'    => 'yes',
					'videoSpeed'       => 'yes',
					'rememberPosition' => 'yes',
				),
				'isActive'    => array( 'videoTranscript', 'rememberPosition' ),
				'scope'       => array( 'inserter', 'transform' ),
			),
			array(
				'name'        => 'silent-loop',
				'title'       => __( 'Silent loop', 'imagina-player' ),
				'description' => __( 'Plays by itself, without sound, round and round, with no button over the picture. For a header or a product shot.', 'imagina-player' ),
				'icon'        => 'controls-repeat',
				'keywords'    => array( __( 'background', 'imagina-player' ), __( 'hero', 'imagina-player' ) ),
				'attributes'  => array(
					'autoplay'       => true,
					'muted'          => true,
					'loop'           => true,
					'videoBigPlay'   => 'no',
					'videoTitle'     => 'no',
					'videoHideAfter' => '1500',
				),
				'isActive'    => array( 'autoplay', 'muted', 'loop' ),
				'scope'       => array( 'inserter', 'transform' ),
			),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function playlist(): array {
		return array(
			array(
				'name'        => 'course',
				'title'       => __( 'Course', 'imagina-player' ),
				'description' => __( 'Lessons beside the picture, each marked once watched, and carrying on where the student stopped.', 'imagina-player' ),
				'icon'        => 'welcome-learn-more',
				'keywords'    => array( __( 'lessons', 'imagina-player' ), __( 'class', 'imagina-player' ) ),
				'attributes'  => array(
					'course' => true,
					'layout' => 'side',
				),
				'isActive'    => array( 'course' ),
				'scope'       => array( 'inserter', 'transform' ),
			),
			array(
				'name'        => 'series',
				'title'       => __( 'Video series', 'imagina-player' ),
				'description' => __( 'Episodes as a row of pictures under the player.', 'imagina-player' ),
				'icon'        => 'playlist-video',
				'attributes'  => array( 'layout' => 'rail' ),
				'isActive'    => array( 'layout' ),
				'scope'       => array( 'inserter', 'transform' ),
			),
		);
	}
}
