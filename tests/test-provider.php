<?php
/**
 * Video that lives on YouTube or Vimeo.
 *
 * This is here because of a report that was entirely fair: a YouTube address
 * pasted into the video block produced an audio player that showed nothing,
 * played nothing, and had no thumbnail. WordPress reports no MIME type for a
 * web page, so the track was not a video, so the renderer built a row of audio
 * controls around an `<audio>` element pointed at youtube.com.
 *
 * Two kinds of check below. The first is recognition, and it matters more than
 * it looks: whatever comes out of it ends up inside an iframe address, so a
 * host matched loosely is a way to put an arbitrary page inside this site's own
 * frame. The second is what reaches the page — in particular that it is a
 * picture and a link rather than an iframe, because an iframe in the markup is
 * a request to Google on every page view whether or not anybody watches.
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Media\Provider;
use ImaginaPlayer\Media\Track;
use ImaginaPlayer\Render\PlayerRenderer;

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

echo PHP_EOL . '# Addresses that are a video' . PHP_EOL;

$good = array(
	'https://www.youtube.com/watch?v=dQw4w9WgXcQ'            => array( 'youtube', 'dQw4w9WgXcQ' ),
	'https://youtube.com/watch?v=dQw4w9WgXcQ&t=42'           => array( 'youtube', 'dQw4w9WgXcQ' ),
	'https://m.youtube.com/watch?v=dQw4w9WgXcQ'              => array( 'youtube', 'dQw4w9WgXcQ' ),
	'https://youtu.be/dQw4w9WgXcQ'                           => array( 'youtube', 'dQw4w9WgXcQ' ),
	'https://youtu.be/dQw4w9WgXcQ?t=90'                      => array( 'youtube', 'dQw4w9WgXcQ' ),
	'https://www.youtube.com/embed/dQw4w9WgXcQ'              => array( 'youtube', 'dQw4w9WgXcQ' ),
	'https://www.youtube.com/shorts/dQw4w9WgXcQ'             => array( 'youtube', 'dQw4w9WgXcQ' ),
	'https://www.youtube.com/live/dQw4w9WgXcQ'               => array( 'youtube', 'dQw4w9WgXcQ' ),
	'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'     => array( 'youtube', 'dQw4w9WgXcQ' ),
	'https://vimeo.com/123456789'                            => array( 'vimeo', '123456789' ),
	'https://player.vimeo.com/video/123456789'               => array( 'vimeo', '123456789' ),
	'https://vimeo.com/123456789/abc123def4'                 => array( 'vimeo', '123456789' ),
);

foreach ( $good as $url => $expected ) {
	$provider = Provider::detect( $url );

	check(
		'recognises ' . $url,
		$provider->name === $expected[0] && $provider->id === $expected[1],
		$provider->name . '/' . $provider->id
	);
}

check(
	'and keeps an unlisted Vimeo hash, without which the embed will not load',
	'abc123def4' === Provider::detect( 'https://vimeo.com/123456789/abc123def4' )->hash
);

echo PHP_EOL . '# Addresses that are not' . PHP_EOL;

$bad = array(
	// The whole reason hosts are compared and not searched for.
	'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ' => 'a lookalike host',
	'https://evil.example/youtube.com/watch?v=dQw4w9WgXcQ' => 'the name in a path',
	'https://notyoutube.com/watch?v=dQw4w9WgXcQ'           => 'a host that merely ends the same way',
	'https://vimeo.com.evil.example/123456789'             => 'a Vimeo lookalike',
	'https://www.youtube.com/playlist?list=PLabcdefghij'   => 'a playlist',
	'https://www.youtube.com/@somechannel'                 => 'a channel',
	'https://www.youtube.com/results?search_query=hola'    => 'a search',
	'https://www.youtube.com/watch?v=short'                => 'an identifier of the wrong length',
	'https://www.youtube.com/watch?v=has+a+plus+in+it'     => 'an identifier with characters YouTube does not issue',
	'https://vimeo.com/channels/staffpicks'                => 'a Vimeo channel',
	'javascript:alert(1)'                                  => 'a javascript: URL',
	'//www.youtube.com/watch?v=dQw4w9WgXcQ'                => 'no scheme at all',
	'https://cdn.example.com/clip.mp4'                     => 'an ordinary file',
	''                                                     => 'nothing',
);

foreach ( $bad as $url => $why ) {
	check( 'refuses ' . $why, ! Provider::detect( $url )->exists(), $url );
}

echo PHP_EOL . '# What the track makes of it' . PHP_EOL;

$track = Track::from_attributes( array( 'src' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ) );

// The fault as reported: not a video, so an audio player.
check( 'a YouTube address is a video', $track->is_video() );
check( 'and is known to be somebody else\'s to serve', $track->is_provider() );
check(
	'and brings its own still image',
	'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg' === $track->poster,
	$track->poster
);
check( 'and is not given a title invented from the address', '' === $track->title, $track->title );

$file = Track::from_attributes( array( 'src' => 'https://cdn.example.com/clip.mp4' ) );
check( 'an ordinary file is still not a provider', ! $file->is_provider() );

echo PHP_EOL . '# What reaches the page' . PHP_EOL;

$renderer = new PlayerRenderer();
$html     = $renderer->render( array( 'src' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ) );

check( 'the player is laid out as a video', str_contains( $html, 'imgp--video' ) );
check( 'and not as audio', ! str_contains( $html, 'imgp--audio' ) );
check( 'with a stage that holds its shape before anything loads', str_contains( $html, 'imgp__stage' ) );
check( 'and no audio element pointed at a web page', ! str_contains( $html, '<audio' ) );
check( 'and the provider\'s own still as the poster', str_contains( $html, 'i.ytimg.com/vi/dQw4w9WgXcQ' ) );
check( 'and our own play button over it', str_contains( $html, 'imgp__bigplay' ) );

/*
 * The point of the facade. An iframe printed into the markup is a request to
 * Google from every visitor who loads the page, watching or not — half a
 * megabyte and a third-party cookie for a video nobody pressed play on.
 */
check( 'no iframe until somebody asks for one', ! str_contains( $html, '<iframe' ) );
check( 'but a box for it to go in', str_contains( $html, 'imgp__embed' ) );
check(
	'and a plain link for a reader with no JavaScript',
	str_contains( $html, '<noscript>' ) && str_contains( $html, 'imgp__embed-link' )
);

check(
	'the privacy-preserving domain is what the browser is told to load',
	str_contains( $html, 'youtube-nocookie.com\/embed\/dQw4w9WgXcQ' ),
	'embedUrl missing'
);

$vimeo = $renderer->render( array( 'src' => 'https://vimeo.com/123456789/abc123def4' ) );
check( 'a Vimeo video renders as a video too', str_contains( $vimeo, 'imgp--video' ) && str_contains( $vimeo, 'imgp__embed' ) );
check( 'and carries its unlisted hash to the browser', str_contains( $vimeo, 'abc123def4' ) );

echo PHP_EOL . '# What the editor loads to preview it' . PHP_EOL;

/*
 * The block preview builds an iframe by hand and points it at the real
 * front-end files, so it gets none of what WordPress does for an enqueued
 * asset — `?ver=` included. Without that the browser keeps serving whatever it
 * cached the first time, and after an update the editor went on drawing a video
 * with a stylesheet from before video existed: the picture had no shape, the
 * poster sat at its own size and the controls fell out underneath. Nothing was
 * wrong with the markup; the stylesheet was months old.
 */
$assets = \ImaginaPlayer\Assets::preview_assets();

foreach ( array( 'frontendCss', 'frontendJs', 'frameCss' ) as $name ) {
	check(
		"the preview's {$name} carries a version",
		isset( $assets[ $name ] ) && (bool) preg_match( '/[?&]ver=[^&]+/', (string) $assets[ $name ] ),
		(string) ( $assets[ $name ] ?? 'missing' )
	);
}

check(
	'and the stylesheet version tracks the build rather than the release',
	str_contains( (string) $assets['frontendCss'], 'ver=' )
		&& ! str_contains( (string) $assets['frontendCss'], 'ver=' . \ImaginaPlayer\VERSION ),
	(string) $assets['frontendCss']
);

echo PHP_EOL . '# The preview renders the same thing as the page' . PHP_EOL;

/*
 * The editor preview goes through the same renderer over REST. It is worth
 * asserting because the report that started this arrived as two complaints —
 * the front end and the editor — and only one of them was the renderer.
 */
$attributes  = array( 'src' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'title' => 'Prueba' );
$from_editor = ( new PlayerRenderer() )->render( $attributes );

check( 'the preview lays a provider video out as a video', str_contains( $from_editor, 'imgp--video' ) );
check( 'with the stage that gives it its shape', str_contains( $from_editor, 'imgp__stage' ) );
check( 'and the box the frame goes in', str_contains( $from_editor, 'imgp__embed' ) );

echo PHP_EOL . '# The two recognisers agree' . PHP_EOL;

/*
 * The editor has its own copy of this in TypeScript, so an author finds out
 * what they pasted before saving rather than after publishing. Two copies of a
 * rule is two chances to disagree, so both are run over the same list.
 */
$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );

if ( '' === $node ) {
	echo 'SKIP  no node; the editor\'s copy was not compared' . PHP_EOL;
} else {
	$cases = array_merge( array_keys( $good ), array_keys( $bad ) );
	$root  = dirname( __DIR__ );

	$script = $root . '/build/.source-check.mjs';
	$bundle = $root . '/build/.source-check.js';

	// Compiled with the same TypeScript the plugin builds with, so what is
	// tested is the file that ships rather than a transcription of it.
	shell_exec(
		sprintf(
			'cd %s && npx tsc assets/src/shared/source.ts --outDir build/.source-check --module es2020 --target es2020 --moduleResolution bundler 2>&1',
			escapeshellarg( $root )
		)
	);

	$compiled = $root . '/build/.source-check/source.js';

	if ( ! file_exists( $compiled ) ) {
		echo 'SKIP  the editor copy would not compile; not compared' . PHP_EOL;
	} else {
		file_put_contents(
			$script,
			"import { identify, placement } from '" . $compiled . "';\n"
			. 'const cases = ' . wp_json_encode( $cases ) . ";\n"
			. "const out = {};\n"
			. "const where = {};\n"
			. "for (const c of cases) { const s = identify(c); out[c] = ('youtube' === s.kind || 'vimeo' === s.kind) ? s.kind + '/' + s.id : ''; where[c] = placement(c); }\n"
			. "console.log(JSON.stringify({ kinds: out, placement: where, extra: {"
			. "  file: placement('https://cdn.example.com/clip.mp4'),"
			. "  hls: placement('https://cdn.example.com/live.m3u8'),"
			. "  audio: placement('https://cdn.example.com/track.mp3'),"
			. "  empty: placement('   ')"
			. "} }));\n"
		);

		$raw    = (string) shell_exec( 'node ' . escapeshellarg( $script ) . ' 2>/dev/null' );
		$parsed = json_decode( trim( $raw ), true );
		$js     = is_array( $parsed ) ? ( $parsed['kinds'] ?? null ) : null;

		if ( ! is_array( $js ) ) {
			check( 'the editor copy ran', false, trim( $raw ) );
		} else {
			echo PHP_EOL . '# Where the editor is allowed to say it' . PHP_EOL;

			/*
			 * The block canvas is where an author looks to see the post, so
			 * anything printed there reads as content about to be published. A
			 * line saying "this is a YouTube video" sat in it above every video
			 * block — telling the author something they already knew, in the one
			 * place where it could be mistaken for the page.
			 *
			 * So: remarks go to the sidebar, faults stay in the canvas, and the
			 * ordinary case says nothing anywhere.
			 */
			$where = (array) ( $parsed['placement'] ?? array() );
			$extra = (array) ( $parsed['extra'] ?? array() );

			check(
				'a YouTube video is reported in the sidebar, not in the block',
				'sidebar' === ( $where['https://www.youtube.com/watch?v=dQw4w9WgXcQ'] ?? '' ),
				(string) ( $where['https://www.youtube.com/watch?v=dQw4w9WgXcQ'] ?? '?' )
			);

			check(
				'and so is a Vimeo one',
				'sidebar' === ( $where['https://vimeo.com/123456789'] ?? '' ),
				(string) ( $where['https://vimeo.com/123456789'] ?? '?' )
			);

			check(
				'an address the player cannot play is reported in the block, where it will break',
				'canvas' === ( $where['https://www.youtube.com/playlist?list=PLabcdefghij'] ?? '' ),
				(string) ( $where['https://www.youtube.com/playlist?list=PLabcdefghij'] ?? '?' )
			);

			check(
				'a channel too',
				'canvas' === ( $where['https://www.youtube.com/@somechannel'] ?? '' ),
				(string) ( $where['https://www.youtube.com/@somechannel'] ?? '?' )
			);

			foreach ( array( 'file' => 'an MP4', 'hls' => 'a stream', 'audio' => 'an audio file', 'empty' => 'an empty block' ) as $key => $what ) {
				check(
					"{$what} is remarked on nowhere at all",
					'none' === ( $extra[ $key ] ?? '' ),
					(string) ( $extra[ $key ] ?? '?' )
				);
			}

			echo PHP_EOL . '# The two recognisers still agree' . PHP_EOL;

			$disagreements = array();

			foreach ( $cases as $url ) {
				$provider = Provider::detect( $url );
				$php      = $provider->exists() ? $provider->name . '/' . $provider->id : '';

				if ( $php !== ( $js[ $url ] ?? null ) ) {
					$disagreements[] = $url . ': php=' . ( '' === $php ? 'no' : $php )
						. ' js=' . ( '' === ( $js[ $url ] ?? '' ) ? 'no' : (string) ( $js[ $url ] ?? '?' ) );
				}
			}

			check(
				'the editor and the server read every address the same way',
				array() === $disagreements,
				implode( '; ', $disagreements )
			);
		}

		exec( 'rm -rf ' . escapeshellarg( $root . '/build/.source-check' ) . ' ' . escapeshellarg( $script ) );
	}
}

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
