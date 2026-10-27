<?php
/**
 * Block patterns: whole sections of a page, built around a player, inserted
 * in one click and then filled in.
 *
 * The players in them have no file yet. Each shows the editor its own way of
 * choosing one, and nothing at all to a visitor until it has one, so a pattern
 * inserted and left half-done does not put a broken player on a live page.
 *
 * @package ImaginaPlayer
 */

declare( strict_types = 1 );

namespace ImaginaPlayer\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Patterns {

	public const CATEGORY = 'imagina-player';

	public function hooks(): void {
		add_action( 'init', array( $this, 'register' ), 21 );
	}

	public function register(): void {
		if ( ! function_exists( 'register_block_pattern' ) ) {
			return;
		}

		register_block_pattern_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Imagina Player', 'imagina-player' ),
				'description' => __( 'Sections of a page built around a player.', 'imagina-player' ),
			)
		);

		foreach ( self::all() as $name => $pattern ) {
			register_block_pattern( 'imagina-player/' . $name, $pattern );
		}
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		return array(
			'podcast-episode' => array(
				'title'       => __( 'Podcast episode with earlier episodes', 'imagina-player' ),
				'description' => __( 'The episode, its notes, and a list of the ones before it.', 'imagina-player' ),
				'categories'  => array( self::CATEGORY, 'media' ),
				'keywords'    => array( __( 'podcast', 'imagina-player' ), __( 'episode', 'imagina-player' ), __( 'audio', 'imagina-player' ) ),
				'blockTypes'  => array( 'imagina/audio-player', 'imagina/playlist' ),
				'content'     => self::group(
					self::heading( __( 'Episode 12: the title of this episode', 'imagina-player' ), 2 )
					. self::paragraph( __( 'What this episode is about, in two or three sentences, and who is talking.', 'imagina-player' ) )
					. self::block( 'imagina/audio-player', Variations::audio()[0]['attributes'] )
					. self::heading( __( 'In this episode', 'imagina-player' ), 3 )
					. self::paragraph( __( 'The points you cover, the links you mention, and a word of thanks to your guests.', 'imagina-player' ) )
					. self::heading( __( 'Earlier episodes', 'imagina-player' ), 3 )
					. self::block( 'imagina/playlist', array( 'layout' => 'list' ) )
				),
			),
			'lesson'          => array(
				'title'       => __( 'Lesson with transcript', 'imagina-player' ),
				'description' => __( 'A video lesson with its transcript and chapters, a summary, and what comes next.', 'imagina-player' ),
				'categories'  => array( self::CATEGORY, 'media' ),
				'keywords'    => array( __( 'course', 'imagina-player' ), __( 'lesson', 'imagina-player' ), __( 'video', 'imagina-player' ) ),
				'blockTypes'  => array( 'imagina/video-player' ),
				'content'     => self::group(
					self::heading( __( 'Lesson 1: getting started', 'imagina-player' ), 2 )
					. self::block( 'imagina/video-player', Variations::video()[0]['attributes'] )
					. self::heading( __( 'What you will learn', 'imagina-player' ), 3 )
					. self::paragraph( __( 'A short summary of the lesson. The transcript under the video lets a student find any moment by what is said in it.', 'imagina-player' ) )
					. self::heading( __( 'Before the next lesson', 'imagina-player' ), 3 )
					. self::paragraph( __( 'An exercise, a file to download, or a question to think about.', 'imagina-player' ) )
				),
			),
			'video-hero'      => array(
				'title'       => __( 'Silent video header', 'imagina-player' ),
				'description' => __( 'A video playing by itself, without sound, under a headline and a button.', 'imagina-player' ),
				'categories'  => array( self::CATEGORY, 'header' ),
				'keywords'    => array( __( 'hero', 'imagina-player' ), __( 'header', 'imagina-player' ), __( 'background', 'imagina-player' ) ),
				'blockTypes'  => array( 'imagina/video-player' ),
				'content'     => self::group(
					self::heading( __( 'A headline for what the video shows', 'imagina-player' ), 1, true )
					. self::paragraph( __( 'One sentence that says why the visitor should keep reading.', 'imagina-player' ), true )
					. self::button( __( 'Get started', 'imagina-player' ) )
					. self::block( 'imagina/video-player', Variations::video()[1]['attributes'] + array( 'align' => 'wide' ) ),
					'full'
				),
			),
			'course'          => array(
				'title'       => __( 'Course with its lessons', 'imagina-player' ),
				'description' => __( 'An introduction, then the lessons beside the player, each marked as it is watched.', 'imagina-player' ),
				'categories'  => array( self::CATEGORY, 'media' ),
				'keywords'    => array( __( 'course', 'imagina-player' ), __( 'lessons', 'imagina-player' ) ),
				'blockTypes'  => array( 'imagina/playlist' ),
				'content'     => self::group(
					self::heading( __( 'The name of the course', 'imagina-player' ), 2 )
					. self::paragraph( __( 'Who it is for, what they will be able to do at the end, and how long it takes.', 'imagina-player' ) )
					. self::block( 'imagina/playlist', Variations::playlist()[0]['attributes'] + array( 'heading' => __( 'Lessons', 'imagina-player' ) ) )
				),
			),
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	private static function block( string $name, array $attributes ): string {
		return sprintf( '<!-- wp:%s %s /-->', $name, wp_json_encode( $attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) ) . "\n\n";
	}

	private static function heading( string $text, int $level, bool $centred = false ): string {
		$attributes = array_filter(
			array(
				'level'     => 2 === $level ? null : $level,
				'textAlign' => $centred ? 'center' : null,
			)
		);

		return sprintf(
			"<!-- wp:heading%s -->\n<h%d class=\"wp-block-heading%s\">%s</h%d>\n<!-- /wp:heading -->\n\n",
			array() === $attributes ? '' : ' ' . wp_json_encode( $attributes ),
			$level,
			$centred ? ' has-text-align-center' : '',
			esc_html( $text ),
			$level
		);
	}

	private static function paragraph( string $text, bool $centred = false ): string {
		return sprintf(
			"<!-- wp:paragraph%s -->\n<p%s>%s</p>\n<!-- /wp:paragraph -->\n\n",
			$centred ? ' {"align":"center"}' : '',
			$centred ? ' class="has-text-align-center"' : '',
			esc_html( $text )
		);
	}

	private static function button( string $text ): string {
		return "<!-- wp:buttons {\"layout\":{\"type\":\"flex\",\"justifyContent\":\"center\"}} -->\n<div class=\"wp-block-buttons\">"
			. "<!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\">" . esc_html( $text ) . "</a></div>\n<!-- /wp:button -->"
			. "</div>\n<!-- /wp:buttons -->\n\n";
	}

	private static function group( string $inner, string $align = '' ): string {
		$attributes = array( 'layout' => array( 'type' => 'constrained' ) );

		if ( '' !== $align ) {
			$attributes = array( 'align' => $align ) + $attributes;
		}

		return sprintf(
			"<!-- wp:group %s -->\n<div class=\"wp-block-group%s\">%s</div>\n<!-- /wp:group -->",
			wp_json_encode( $attributes ),
			'' !== $align ? ' align' . $align : '',
			"\n" . $inner
		);
	}
}
