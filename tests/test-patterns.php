<?php
/**
 * Block patterns and block variations.
 *
 * A pattern is markup the editor parses, and a variation is a set of
 * attributes it applies; neither is checked by anything else before an
 * author inserts it. A misspelt attribute is silently dropped, a skin that
 * does not exist falls back to the default, and a block comment left open
 * swallows the rest of the page — so each is checked here against the
 * schema it will be read with.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Blocks\BlockRegistrar;
use ImaginaPlayer\Blocks\Patterns;
use ImaginaPlayer\Blocks\Variations;
use ImaginaPlayer\Player\Attributes;
use ImaginaPlayer\Player\Skins;
use ImaginaPlayer\Render\PlaylistRenderer;

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

if ( ! function_exists( 'register_block_pattern' ) ) {
	function register_block_pattern( $name, $args ) { $GLOBALS['stub_patterns'][ $name ] = $args; return true; }
}
if ( ! function_exists( 'register_block_pattern_category' ) ) {
	function register_block_pattern_category( $name, $args ) { $GLOBALS['stub_pattern_categories'][ $name ] = $args; return true; }
}

$player_attributes   = array_keys( Attributes::schema() ) + array();
$player_attributes[] = 'align';
$playlist_attributes = array( 'items', 'layout', 'heading', 'preset', 'course', 'courseSequential', 'courseKey', 'align' );

/**
 * Whether a variation's or a pattern's attributes are the block's own, with
 * values the block keeps.
 *
 * @param array<string, mixed> $attributes
 * @param array<int, string>   $known
 * @return string Empty when they are; what is wrong otherwise.
 */
function wrong( string $block, array $attributes, array $known ): string {
	$unknown = array_diff( array_keys( $attributes ), $known );

	if ( array() !== $unknown ) {
		return 'unknown: ' . implode( ', ', $unknown );
	}

	if ( 'imagina/playlist' === $block ) {
		return isset( $attributes['layout'] ) && PlaylistRenderer::layout( (string) $attributes['layout'] ) !== $attributes['layout'] ? 'layout' : '';
	}

	if ( isset( $attributes['skin'] ) && ! Skins::exists( (string) $attributes['skin'] ) ) {
		return 'skin';
	}

	// What the sanitiser keeps is what the block will have.
	$kept = Attributes::sanitize( $attributes );

	foreach ( $attributes as $key => $value ) {
		if ( 'align' !== $key && $kept[ $key ] !== $value ) {
			return "{$key} becomes " . wp_json_encode( $kept[ $key ] );
		}
	}

	return '';
}

echo '# Variations' . PHP_EOL;

$sets = array(
	'imagina/audio-player' => Variations::audio(),
	'imagina/video-player' => Variations::video(),
	'imagina/playlist'     => Variations::playlist(),
);

foreach ( $sets as $block => $variations ) {
	$names = array_column( $variations, 'name' );
	check( "{$block}: each variation has a name of its own", count( $names ) === count( array_unique( $names ) ) && ! in_array( '', $names, true ) );

	foreach ( $variations as $variation ) {
		$known   = 'imagina/playlist' === $block ? $playlist_attributes : $player_attributes;
		$problem = wrong( $block, (array) $variation['attributes'], $known );

		check( "{$block}/{$variation['name']}: a title, a description and an icon", '' !== $variation['title'] && '' !== $variation['description'] && '' !== $variation['icon'] );
		check( "{$block}/{$variation['name']}: its settings are the block’s own, and kept", '' === $problem, $problem );
		check( "{$block}/{$variation['name']}: recognised by settings it sets", array() === array_diff( (array) $variation['isActive'], array_keys( (array) $variation['attributes'] ) ) );
	}
}

$registrar = (string) file_get_contents( dirname( __DIR__ ) . '/src/Blocks/BlockRegistrar.php' );
check( 'each block is given its variations', 3 === substr_count( $registrar, "'variation_callback' => array( Variations::class," ) );

echo PHP_EOL . '# Patterns' . PHP_EOL;

( new Patterns() )->register();
check( 'in a category of their own', 'Imagina Player' === ( $GLOBALS['stub_pattern_categories'][ Patterns::CATEGORY ]['label'] ?? '' ) );
check( 'all of them registered', count( Patterns::all() ) === count( (array) ( $GLOBALS['stub_patterns'] ?? array() ) ) && 4 === count( Patterns::all() ) );

foreach ( Patterns::all() as $name => $pattern ) {
	$content = (string) $pattern['content'];

	check( "{$name}: a title and a description", '' !== $pattern['title'] && '' !== $pattern['description'] );
	check( "{$name}: in the plugin’s category", in_array( Patterns::CATEGORY, (array) $pattern['categories'], true ) );

	// Every block opened is closed, in order.
	preg_match_all( '/<!-- (\/?)wp:([a-z0-9\/-]+)(?: (\{.*?\}))? (\/?)-->/', $content, $tags, PREG_SET_ORDER );
	$stack = array();
	$valid = true;

	foreach ( $tags as $tag ) {
		[ , $closing, $block, $json, $self ] = array_pad( $tag, 5, '' );

		if ( '' !== $json && null === json_decode( $json, true ) ) {
			$valid = false;
		}

		if ( '/' === $self ) {
			continue;
		}

		if ( '/' === $closing ) {
			$valid = $valid && array_pop( $stack ) === $block;
		} else {
			$stack[] = $block;
		}
	}

	check( "{$name}: every block it opens it closes", $valid && array() === $stack, implode( ',', $stack ) );

	// The players in it: known blocks, with settings they keep.
	preg_match_all( '/<!-- wp:(imagina\/[a-z-]+) (\{.*?\}) \/-->/', $content, $players, PREG_SET_ORDER );
	$problems = array();

	foreach ( $players as $player ) {
		$block      = $player[1];
		$attributes = (array) json_decode( $player[2], true );
		$known      = 'imagina/playlist' === $block ? $playlist_attributes : $player_attributes;
		$problem    = in_array( $block, array( 'imagina/audio-player', 'imagina/video-player', 'imagina/playlist' ), true )
			? wrong( $block, $attributes, $known )
			: 'no such block';

		if ( '' !== $problem ) {
			$problems[] = "{$block}: {$problem}";
		}
	}

	check( "{$name}: a player in it, with settings it keeps", array() !== $players && array() === $problems, implode( '; ', $problems ) );
	check( "{$name}: no file of anybody’s in it", ! preg_match( '/"src"\s*:\s*"[^"]/', $content ) );
}

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
