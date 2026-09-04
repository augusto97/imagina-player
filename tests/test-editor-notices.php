<?php
/**
 * Editing tools belong where the editing tools are.
 *
 * The waveform notice and the source warning rendered inside the block, above
 * the player. In the editor a block is a picture of the page, so anything drawn
 * there reads as part of the page: an author showed a client a post and the
 * client saw "Measure this waveform again" sitting above the audio and asked
 * whether visitors would see it. A reasonable question — nothing on screen said
 * otherwise.
 *
 * And the settings screen warned about ffmpeg in alarm colours on every visit
 * to any site without it, whether or not a single file was missing a waveform.
 * On a site where everything is measured and working, that is an alarm about a
 * capability the site is not using and cannot act on, which reads as "your
 * site is broken".
 *
 * @package ImaginaPlayer
 */

$root     = dirname( __DIR__ );
$failures = 0;

function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

/**
 * The part of a block's markup that is the block itself, not the sidebar.
 *
 * Everything inside `<InspectorControls>` is the sidebar; everything after it,
 * inside the returned markup, is drawn on the page the author is looking at.
 *
 * @param string $source The component file.
 */
function on_the_block( string $source ): string {
	$end = strrpos( $source, '</InspectorControls>' );

	if ( false === $end ) {
		return $source;
	}

	return substr( $source, $end );
}

echo PHP_EOL . '# Nothing that is only for the author is drawn on the block' . PHP_EOL;

foreach ( array(
	'the audio and video block' => '/assets/src/editor/edit.tsx',
	'the playlist block'        => '/assets/src/editor/playlist.tsx',
) as $what => $file ) {
	$source = (string) file_get_contents( $root . $file );

	check(
		"{$what} has a sidebar to put them in",
		str_contains( $source, '<InspectorControls>' ),
		$file
	);

	$block = on_the_block( $source );

	foreach ( array( 'WaveformNotice', 'SourceWarning' ) as $component ) {
		if ( ! str_contains( $source, '<' . $component ) ) {
			continue;
		}

		check(
			"{$what} keeps {$component} out of what the author sees as the page",
			! str_contains( $block, '<' . $component ),
			'drawn on the block, where it reads as content a visitor will see'
		);

		check(
			"and puts it in the sidebar instead",
			str_contains( substr( $source, 0, strrpos( $source, '</InspectorControls>' ) ?: 0 ), '<' . $component )
		);
	}
}

echo PHP_EOL . '# No alarm about a server doing something nobody asked it to do' . PHP_EOL;

$panels = (string) file_get_contents( $root . '/assets/src/admin/panels.tsx' );

/*
 * The condition is the whole point. Without a count of what is actually
 * missing, the only thing the screen can say is "this server cannot do X",
 * which on a working site is a complaint rather than information.
 */
check(
	'the settings screen finds out how many files are actually missing a waveform',
	str_contains( $panels, 'listPendingWaveforms()' )
		&& str_contains( $panels, 'setPending(' ),
	'without a count there is no way to tell a problem from a non-problem'
);

check(
	'and only mentions ffmpeg when something is waiting for it',
	(bool) preg_match(
		'/!\s*settings\.system\.ffmpeg\s*&&\s*null\s*!==\s*pending\s*&&\s*pending\s*>\s*0/',
		$panels
	),
	'it used to warn on every visit, whether or not anything was missing'
);

/*
 * And in a tone that matches. A site measuring waveforms in the browser is
 * working exactly as designed; the note tells somebody which button to press,
 * which is not a warning.
 */
$notice_block = (string) ( explode( 'pending > 0 && (', $panels )[1] ?? '' );

check(
	'and says it as a note rather than a warning',
	str_starts_with( ltrim( $notice_block ), '<Notice tone="info">' ),
	'red is for something being wrong'
);

check(
	'and the reason ffmpeg is unavailable moves beside the ffmpeg setting',
	(bool) preg_match( '/label=\{ __\( \x27ffmpeg path\x27.*?ffmpegProblem\( settings\.system\.ffmpegState \)/s', $panels ),
	'which is where somebody wondering about it is looking'
);

/*
 * The warning still exists for the case it was written for. Removing it
 * entirely would be the opposite mistake: a site with files that genuinely
 * cannot be measured needs to be told so.
 */
check(
	'a site with files that have no waveform is still told',
	str_contains( $panels, 'has no waveform yet.' )
		&& str_contains( $panels, 'Generate missing waveforms' ),
	'saying nothing at all would be the same mistake in reverse'
);

echo PHP_EOL;
echo 0 === $failures ? 'All editor notice checks passed.' . PHP_EOL : "{$failures} FAILURE(S)" . PHP_EOL;

exit( 0 === $failures ? 0 : 1 );
