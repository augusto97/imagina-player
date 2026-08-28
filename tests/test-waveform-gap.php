<?php
/**
 * The two ways a waveform silently failed to exist.
 *
 * Both were reported from a live site, and neither was visible from any test
 * that existed at the time — which is the point of this file.
 *
 * The first: the block preview drew a *synthetic* waveform whenever a track
 * had none stored. It was meant kindly — a flat bar looks broken — and the
 * effect was that the editor told the author their waveform worked while the
 * front end showed a plain bar. The only place anybody found out was the live
 * site.
 *
 * The second: on a host with no ffmpeg, a recording longer than the visitor
 * size cap gets no waveform from the server and none from the browser either,
 * so it never gets one at all. A 77-minute lecture on a host without ffmpeg
 * was, before this, a flat bar for ever with nothing anywhere to say why.
 */

$plugin = dirname( __DIR__ ) . '/';

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

require $plugin . 'tests/bootstrap.php';

use ImaginaPlayer\Rest\SettingsController;

echo PHP_EOL . '# The block preview must not invent a waveform' . PHP_EOL;

$controller = new SettingsController();

// A block previewing a real track that has no peaks stored.
$block = $controller->preview(
	new WP_REST_Request(
		array(
			'attributes' => array(
				'src'   => 'https://example.test/wp-content/uploads/clase.mp3',
				'title' => 'La historia de un quiste',
			),
		)
	)
);

$data = $block->get_data();

check( 'the preview renders', ! empty( $data['html'] ) );
check(
	'it is marked as the real thing, not a demo',
	true === ( $data['real'] ?? null ),
	'the two callers want different things and the response has to say which it is'
);
check(
	'a track with no stored waveform gets no waveform',
	'' === ( $data['peaks'] ?? 'x' ),
	'this is the bug: a synthetic waveform here means the editor lies to the author'
);
check(
	'and says so, so the editor can offer to fix it',
	false === ( $data['hasPeaks'] ?? null ),
	wp_json_encode( $data['hasPeaks'] ?? null )
);

// The settings screen is the other caller, and it *should* get a demo: its
// "track" is a file that does not exist.
$preset = $controller->preview(
	new WP_REST_Request( array( 'preset' => array( 'skin' => 'wave' ) ) )
);

$preset_data = $preset->get_data();

check(
	'the settings preview still gets its demo waveform',
	'' !== ( $preset_data['peaks'] ?? '' ) && false === ( $preset_data['real'] ?? null ),
	'that one has no real file to measure, so a demo is the honest answer there'
);

echo PHP_EOL . '# The editor no longer paints over the gap' . PHP_EOL;

$preview_src = (string) file_get_contents( $plugin . 'assets/src/editor/preview.tsx' );

check(
	'the editor does not inject peaks into the markup any more',
	! str_contains( $preview_src, 'data-peaks="${ peaks }"' ),
	'injecting them is exactly what hid the problem'
);
// The dependency array specifically, not just the word somewhere in the file:
// the prop declaration alone satisfies a plain substring check, and did.
preg_match( '/\}, \[(.*?)\] \);/s', $preview_src, $deps );

check(
	'and it re-renders when a waveform is measured',
	isset( $deps[1] ) && str_contains( $deps[1], 'refresh' ),
	'measuring stores against the file, not the block, so nothing in the attributes changes to trigger a re-render: '
		. trim( (string) ( $deps[1] ?? 'no dependency array found' ) )
);

$editor = (string) file_get_contents( $plugin . 'build/editor.js' );

check(
	'the editor bundle can tell the author their waveform is missing',
	str_contains( $editor, 'has no waveform' ),
	'the notice is the only place an author would ever find out'
);
check(
	'and offers to make it there',
	str_contains( $editor, 'Generate it now' )
);
check(
	'writing to the store route',
	str_contains( $editor, 'peaks/store' )
);

echo PHP_EOL . '# The fix is where the file is, not somewhere else' . PHP_EOL;

$editor_src = (string) file_get_contents( $plugin . 'assets/src/editor/edit.tsx' );
$list_src   = (string) file_get_contents( $plugin . 'assets/src/editor/playlist.tsx' );

check(
	'the audio and video block carries the notice',
	str_contains( $editor_src, '<WaveformNotice' )
);
check(
	'and so does the playlist, which is where several files arrive at once',
	str_contains( $list_src, '<WaveformNotice' ),
	'a playlist is exactly the case where nobody wants to press a button elsewhere five times'
);
check(
	'the playlist checks every one of its items',
	str_contains( $list_src, 'items.map( ( item ) => item.id ?? 0 )' )
);
check(
	'the notice asks the server itself rather than waiting for a preview',
	str_contains( (string) file_get_contents( $plugin . 'assets/src/editor/waveform-notice.tsx' ), 'peaks/status' ),
	'so it appears the moment a file is chosen, not once a preview has come back'
);

$peaks_src = (string) file_get_contents( $plugin . 'src/Rest/PeaksController.php' );

check( 'there is a route that reports several files at once', str_contains( $peaks_src, "'/peaks/status'" ) );
check(
	'and it needs the right to upload, since it names files',
	str_contains( $peaks_src, "current_user_can( 'upload_files' )" )
);

$editor_bundle = (string) file_get_contents( $plugin . 'build/editor.js' );

check( 'the bundle carries the status request', str_contains( $editor_bundle, 'peaks/status' ) );
check(
	'and measures more than one file in a run',
	str_contains( $editor_bundle, 'Generate them now' ),
	'a playlist of eight needs one button, not eight'
);

echo PHP_EOL . '# A host with no ffmpeg has a way out' . PHP_EOL;

$peaks_controller = (string) file_get_contents( $plugin . 'src/Rest/PeaksController.php' );

check( 'there is a route to store a waveform measured in a browser', str_contains( $peaks_controller, "'/peaks/store'" ) );
check(
	'it is gated on rights over the file, not on a public token',
	str_contains( $peaks_controller, "current_user_can( 'edit_post', (int) \$request->get_param( 'attachmentId' ) )" ),
	'the public write path is token-gated and write-once; this one is neither, so it must be authenticated'
);
check(
	'the pending list carries what a browser needs to fetch a file',
	str_contains( $peaks_controller, "'url'" ) && str_contains( $peaks_controller, "'bytes'" )
);

$admin = (string) file_get_contents( $plugin . 'build/admin.js' );

check(
	'the settings screen measures in the browser when ffmpeg is absent',
	str_contains( $admin, 'peaks/store' ),
	'without this the Generate button is permanently disabled on those hosts'
);
check(
	'and the button is no longer disabled just because ffmpeg is missing',
	! str_contains( (string) file_get_contents( $plugin . 'assets/src/admin/panels.tsx' ), 'busy || ! settings.system.ffmpeg' )
);
check(
	'the notice says what can be done rather than only what is wrong',
	str_contains( $admin, 'measure them here' ) || str_contains( $admin, 'Generate missing waveforms' )
);

$measure = (string) file_get_contents( $plugin . 'assets/src/shared/measure.ts' );

check(
	'the measuring decodes at a low rate, not the hardware one',
	str_contains( $measure, 'OfflineAudioContext' ) && str_contains( $measure, '8000' ),
	'a 77-minute file at 48 kHz is about 900 MB of samples, which is how a tab dies'
);
check(
	'and reports progress, because the download is large',
	str_contains( $measure, 'content-length' ) && str_contains( $measure, 'downloading' )
);
check(
	'the visitor-side cap is untouched',
	str_contains( (string) file_get_contents( $plugin . 'assets/src/frontend/peaks.ts' ), 'too-large' ),
	'nobody browsing a page should download ninety megabytes for a picture'
);

echo PHP_EOL;
if ( $failures > 0 ) {
	echo "{$failures} check(s) failed." . PHP_EOL;
	exit( 1 );
}
echo 'All checks passed.' . PHP_EOL;
