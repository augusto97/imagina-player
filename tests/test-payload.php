<?php
/**
 * What a page actually downloads.
 *
 * The claim this plugin makes over its competitors is weight: 20 KB against
 * Presto's 374 KB of hls.js alone and FluentPlayer's 1.5 MB. A claim like that
 * is worth nothing unless something fails when it stops being true, so this
 * pins it.
 *
 * The subtle failure it exists to catch: turning the video module's dynamic
 * `import()` into a plain one. Nothing breaks, no test of behaviour notices,
 * and every audio-only page in the world silently starts paying for video code
 * it will never run. Verified by doing exactly that — the bundle grew and the
 * separate chunk disappeared.
 */

$plugin = dirname( __DIR__ ) . '/';

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

function kb( string $file ): float {
	return round( (int) filesize( $file ) / 1024, 1 );
}

$core  = $plugin . 'build/frontend.js';
$video = $plugin . 'build/imagina-video.js';
$glue  = $plugin . 'build/imagina-hls-glue.js';
$layers   = $plugin . 'build/imagina-layers.js';
$playlist = $plugin . 'build/imagina-playlist.js';
$hls   = $plugin . 'build/imagina-hls.js';
$css   = $plugin . 'build/style-frontend.css';

check( 'the front-end bundle is built', is_readable( $core ) );

/*
 * Budgets, not measurements. Each is comfortably above today's size so that
 * ordinary work does not trip it, and far enough below the competition that
 * the claim survives. Raise one deliberately, in a commit that says why.
 */
$budgets = array(
	'the core bundle' => array( $core, 26.0 ),
	'the video chunk' => array( $video, 14.0 ),
	'the HLS glue'    => array( $glue, 6.0 ),
	'the layer chunk' => array( $layers, 8.0 ),
	'the playlist'    => array( $playlist, 6.0 ),
	/*
	 * Raised from 24.0 in 1.15.0, deliberately. The stylesheet gained a block
	 * that restates, for the elements this player owns, the handful of
	 * properties a theme has any business setting on a button or a frame of its
	 * own — a page of near-identical `!important` declarations that exists
	 * because a theme was painting the video. It is about two kilobytes of
	 * source and almost nothing on the wire, which is why the compressed budget
	 * below is the one that matters.
	 *
	 * Raised again to 30.0 in 1.19.0, when the control colours stopped being
	 * literals — `rgb(255 255 255 / 30%)` became a `color-mix()` on a custom
	 * property, four times over, which is longer to write and identical to
	 * send. A budget raised every release is not a budget, so the compressed
	 * figure below was tightened in the same commit: the loose proxy loosens,
	 * the number a visitor actually pays gets stricter.
	 */
	'the stylesheet'  => array( $css, 30.0 ),
);

foreach ( $budgets as $label => $budget ) {
	[ $file, $limit ] = $budget;

	if ( ! is_readable( $file ) ) {
		check( $label . ' exists', false, $file );
		continue;
	}

	$size = kb( $file );

	check(
		sprintf( '%s is within its budget (%.1f KB of %.1f KB)', $label, $size, $limit ),
		$size <= $limit
	);
}

/*
 * And the numbers a visitor actually pays. Every server sends these compressed,
 * so the source size above is a proxy — a useful one, because it catches code
 * being added, but a misleading one for anything repetitive. These are the
 * figures the readme claims, pinned so the claim cannot quietly stop being
 * true.
 */
$compressed = array(
	'the core bundle' => array( $core, 8.0 ),
	// Tightened from 6.5 in 1.19.0. Today's stylesheet compresses to 5.7 KB,
	// and the raw budget above has now been raised twice; this is the half of
	// the pair that has to hold.
	'the stylesheet'  => array( $css, 6.0 ),
);

foreach ( $compressed as $label => $budget ) {
	[ $file, $limit ] = $budget;
	$size = round( strlen( (string) gzencode( (string) file_get_contents( $file ), 9 ) ) / 1024, 1 );

	check(
		sprintf( '%s is within its budget compressed (%.1f KB of %.1f KB)', $label, $size, $limit ),
		$size <= $limit
	);
}

/*
 * hls.js gets no byte budget, because its size is not ours to choose and there
 * is no smaller way to play adaptive streaming. What it gets instead is a hard
 * rule about who pays for it, checked below.
 */
echo PHP_EOL . '# Audio pages must not pay for video' . PHP_EOL;

check(
	'the video chrome is a chunk of its own',
	is_readable( $video ),
	'a static import merges it into the core bundle and no behaviour test notices'
);

$core_source = (string) file_get_contents( $core );

// Strings that only exist in the video module. Finding them in the core bundle
// means the chunk was inlined back in.
$video_only = array( 'requestPictureInPicture', 'webkitEnterFullscreen', 'is-chrome-idle' );

// Every optional feature is its own chunk, and none of them may leak into the
// bundle every page loads.
$optional = array(
	'imagina-player-layers'   => $layers,
	'imagina-player-playlist' => $playlist,
);

foreach ( $video_only as $needle ) {
	check(
		sprintf( '"%s" is not in the core bundle', $needle ),
		! str_contains( $core_source, $needle )
	);
}

// And the same strings must be somewhere, or the module was gutted rather than
// split — a check that passes for the wrong reason otherwise.
if ( is_readable( $video ) ) {
	$video_source = (string) file_get_contents( $video );

	foreach ( $video_only as $needle ) {
		check(
			sprintf( '"%s" is in the video chunk, where it belongs', $needle ),
			str_contains( $video_source, $needle )
		);
	}
}

foreach ( $optional as $marker => $file ) {
	check(
		sprintf( '"%s" is not in the core bundle', $marker ),
		! str_contains( $core_source, $marker )
	);

	// And it has to be somewhere, or the check above passes because the feature
	// was deleted rather than deferred.
	check(
		sprintf( '"%s" is in its own chunk', $marker ),
		is_readable( $file ) && str_contains( (string) file_get_contents( $file ), $marker )
	);
}

check(
	'the core still knows how to fetch the chunk',
	str_contains( $core_source, 'imagina-video' ),
	'without this the import target is unresolvable at runtime'
);

echo PHP_EOL . '# Only streams pay for the streaming library' . PHP_EOL;

check( 'hls.js is its own chunk', is_readable( $hls ) );

// Internals, not the public event names our own glue legitimately mentions:
// these appear only if the library itself has been inlined.
$hls_only = array( 'levelController', 'fragmentLoader', 'bufferController', 'abrController' );
$others   = array( $core => 'the core bundle', $video => 'the video chunk', $glue => 'the HLS glue' );

foreach ( $others as $file => $label ) {
	if ( ! is_readable( $file ) ) {
		continue;
	}

	$source = (string) file_get_contents( $file );

	foreach ( $hls_only as $needle ) {
		check(
			sprintf( '%s does not contain "%s"', $label, $needle ),
			! str_contains( $source, $needle ),
			'400 KB on every page that has a video is the whole cost of the feature paid by everyone'
		);
	}
}

// Webpack keeps the chunk *name* map in the runtime, which lives in the core
// bundle. That is a handful of bytes, and it is what makes the deferral work.
check(
	'the core runtime knows the chunk names',
	str_contains( $core_source, 'imagina-hls' ) && str_contains( $core_source, 'imagina-video' ),
	'without the map the imports are unresolvable at runtime'
);

echo PHP_EOL . '# No third-party player library crept in' . PHP_EOL;

// The whole architectural position is: our own chrome, no runtime dependency.
// If one of these ever appears it should be a decision, not a diff nobody read.
// Package names, not "video.js": that string is a substring of our own chunk
// filename, and a check that fires on `imagina-video.js` reports a library we
// do not have.
foreach ( array( 'plyr', 'vidstack', 'videojs', 'media-chrome' ) as $library ) {
	check(
		sprintf( 'no %s in the front-end bundle', $library ),
		! str_contains( strtolower( $core_source ), $library )
	);
}

$package = json_decode( (string) file_get_contents( $plugin . 'package.json' ), true );

check(
	'and none in the runtime dependencies',
	empty( $package['dependencies'] ),
	'every byte here ships to every visitor'
);

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "{$failures} check(s) failed." . PHP_EOL;
	exit( 1 );
}
echo 'All checks passed.' . PHP_EOL;
