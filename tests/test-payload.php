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
$course   = $plugin . 'build/imagina-course.js';
$extras   = $plugin . 'build/imagina-extras.js';
$sources  = $plugin . 'build/imagina-sources.js';
$hls   = $plugin . 'build/imagina-hls.js';
$css   = $plugin . 'build/style-frontend.css';

check( 'the front-end bundle is built', is_readable( $core ) );

/*
 * Budgets, not measurements. Each is comfortably above today's size so that
 * ordinary work does not trip it, and far enough below the competition that
 * the claim survives. Raise one deliberately, in a commit that says why.
 */
$budgets = array(
	/*
	 * Raised from 26.0 in 1.51.0 by six hundred bytes: the listener's speed
	 * remembered across the site, the "more" button that fetches its menu
	 * on the first press, and the two new chunks' entries in the loader's
	 * map. The compressed budget below, the readme's figure, did not move.
	 */
	'the core bundle' => array( $core, 27.0 ),
	/*
	 * Raised from 14.0 in 1.43.0, deliberately: the bar gained a gear with a
	 * speed list and a link to the current moment, and the chapter segments
	 * gained a tip naming the one under the pointer. Only a page with a
	 * video pays for this chunk, and the core it is kept out of is the
	 * figure the readme claims.
	 */
	/*
	 * And to 18.0 in 1.46.0, for keyboard access to the bar's menus — the
	 * arrows walk a list, Escape hands focus back, a second button swaps
	 * its list in — and the controls that a tap shows and hides on a phone.
	 * The sticky player and the visitor-side waveform measurement left the
	 * core for chunks of their own in the same release.
	 */
	/*
	 * And to 19.0 in 1.48.0, for the one-row bar on a phone: the bar's and
	 * the picture's heights measured so subtitles, menus and the toast clear
	 * the controls, the gear offering whatever the narrow bar cannot show,
	 * and a tap on a playing picture that brings the controls back instead
	 * of pausing.
	 */
	/*
	 * And to 20.5 in 1.52.0: the gear's second lists share one way back, and
	 * gained the soundtrack — a dubbing, or a stream's own languages — and
	 * the loader for the chunk that switches qualities and keeps a
	 * soundtrack in step with the picture, which is not in here.
	 */
	'the video chunk' => array( $video, 20.5 ),
	'the HLS glue'    => array( $glue, 6.0 ),
	'the layer chunk' => array( $layers, 8.0 ),
	'the playlist'    => array( $playlist, 6.0 ),
	// Fetched only by a playlist marked as a course, and only by the "more"
	// button of an audio player the first time it is pressed.
	'the course chunk' => array( $course, 6.0 ),
	'the more menu'    => array( $extras, 7.0 ),
	// Fetched only by a video with other qualities or soundtracks.
	'the qualities and soundtracks' => array( $sources, 6.0 ),
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
	 *
	 * Raised to 33.0 in 1.43.0 with five new pieces of interface — the
	 * segmented bar and its tip, the resume chip, the toast and the
	 * timestamp pill — and the compressed figure below raised with it,
	 * by the same half kilobyte they weigh on the wire.
	 *
	 * And to 38.0 in 1.44.0: decorations over the picture at nine places, a
	 * hotspot with its label, and the transcript panel with its list. Two
	 * features' worth of interface, on the wire under a kilobyte.
	 *
	 * And to 42.0 in 1.45.0, for the three video playlist layouts. The
	 * stylesheet is one file for every player on purpose — the block
	 * editor's preview inlines it, and a chunk's own stylesheet would be
	 * fetched from the plugin's address, which some hosts refuse to a
	 * sandboxed frame — so it carries the playlist even where there is
	 * none. Splitting it is the next saving worth making, once the preview
	 * can inline a chunk's stylesheet as it inlines its script.
	 *
	 * And to 54.0 in 1.48.0, the largest single step, for the visual audit:
	 * the one-row video bar with its title over the picture and its menus
	 * fitted to the picture, the factory colours' dark set, logical
	 * properties for right-to-left pages, forty-four-pixel touch targets,
	 * the theme defence extended to the playlist and the timestamp buttons,
	 * numbered tracks, and print styles. About one and a half kilobytes on
	 * the wire.
	 */
	/*
	 * And to 56.0 in 1.50.0 for two features: the locked player's offer and
	 * the viewer's name over the picture. Under half a kilobyte on the wire.
	 */
	/*
	 * And to 58.0 in 1.51.0 for a course's progress, its ticks and its locked
	 * lessons, and the audio player's "more" menu. Written to borrow what was
	 * there — the screen-reader class, the menu of the video's gear, the
	 * audio buttons' own rule — and still four tenths of a kilobyte on the
	 * wire, which is why the compressed budget below moves with it.
	 */
	'the stylesheet'  => array( $css, 58.0 ),
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
	/*
	 * Raised from 8.0 in 1.43.0, and the readme's figure with it. The core
	 * gained the hooks for links to a moment, timestamp buttons and the
	 * "resume from" chip — half a kilobyte of glue that decides whether to
	 * fetch each of those, which live in chunks of their own and cost a
	 * page nothing until it uses them.
	 */
	'the core bundle' => array( $core, 9.0 ),
	// Tightened from 6.5 in 1.19.0. Today's stylesheet compresses to 5.7 KB,
	// and the raw budget above has now been raised twice; this is the half of
	// the pair that has to hold.
	// Raised to 6.5 in 1.43.0, 7.5 in 1.44.0, 8.0 in 1.45.0 and 10.0 in
	// 1.48.0, with the raw budget above; see there for what to do about it.
	// And to 10.5 in 1.51.0, with the raw one.
	'the stylesheet'  => array( $css, 10.5 ),
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
	'imagina-player-course:'  => $course,
	'imagina-player:bookmarks:' => $extras,
	'imagina-player:quality'    => $sources,
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
