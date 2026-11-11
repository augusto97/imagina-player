<?php
/**
 * Subtitles and chapters from pasted text.
 *
 * What the editor makes here is uploaded as a file and handed to the browser's
 * own subtitle parser, which drops a cue it does not like without a word. So
 * the output is checked the way a browser reads it: the header, the timings,
 * no blank line or arrow inside a cue, times that only ever move forwards.
 * And the text is checked the way a viewer reads it: two lines at most, cut
 * at a word, on screen long enough to be read.
 */

require __DIR__ . '/bootstrap.php';

$root = dirname( __DIR__ );

$failures = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

if ( '' === trim( (string) shell_exec( 'command -v node 2>/dev/null' ) ) ) {
	echo 'SKIP  no node' . PHP_EOL;
	exit( 0 );
}

$out = $root . '/build/.transcripts';
shell_exec(
	sprintf(
		'cd %s && npx tsc assets/src/editor/transcript-tools.ts --outDir %s --module commonjs --target es2020 --lib es2020 2>&1',
		escapeshellarg( $root ),
		escapeshellarg( $out )
	)
);

$timed = "[00:00:00] Hola a todos, bienvenidos a la clase.\n"
	. "[00:00:04] Hoy vamos a ver cómo se escriben los subtítulos, que es algo que mucha gente deja para el final y luego no tiene tiempo de hacer bien.\n"
	. "y esta línea no tiene tiempo, así que sigue a la de arriba.\n"
	. "0:20 - Fin.\n";

$plain = 'Esta es una transcripción sin tiempos. Tiene varias frases, algunas cortas. Otras, en cambio, son bastante más largas de lo que cabe en una sola línea de subtítulos y hay que partirlas por algún sitio razonable. ¿Funciona? Debería.';

$srt = "1\r\n00:00:01,000 --> 00:00:03,500\r\nPrimera línea\r\n\r\n2\r\n00:00:04,000 --> 00:00:06,250\r\nSegunda --> línea\r\n\r\n\xEF\xBB\xBF";

$youtube = "Capítulos:\n0:00 Introducción\n1:30 - Segunda parte\nTercera parte 12:05\n(1:02:03) Al final del todo\nesto no es un capítulo\n";

$vtt_chapters = "WEBVTT\n\n00:00:00.000 --> 00:01:00.000\nUno\n\n00:01:00.000 --> 00:02:00.000\nDos\n";

$script = $out . '/run.js';
file_put_contents(
	$script,
	'const t = require( "./transcript-tools.js" );'
	. 'const timed = t.transcriptToCues( ' . wp_json_encode( $timed ) . ', 0 );'
	. 'const plain = t.transcriptToCues( ' . wp_json_encode( $plain ) . ', 60 );'
	. 'const guess = t.transcriptToCues( ' . wp_json_encode( $plain ) . ', 0 );'
	. 'const srt = t.transcriptToCues( ' . wp_json_encode( $srt ) . ', 0 );'
	. 'console.log( JSON.stringify( {'
	. ' clocks: [ t.clockToSeconds( "1:23" ), t.clockToSeconds( "01:02:03" ), t.clockToSeconds( "00:00:05,250" ), t.clockToSeconds( "x" ) ],'
	. ' vtt: [ t.vttClock( 83.5 ), t.vttClock( 59.9996 ), t.vttClock( 3723 ) ],'
	. ' timed, plain, guess, srt,'
	. ' timedVtt: t.toVtt( timed.cues ), plainVtt: t.toVtt( plain.cues ), srtVtt: t.toVtt( srt.cues ),'
	. ' roundTrip: t.parseSubtitles( t.toVtt( plain.cues ) ),'
	. ' notTimed: t.parseTimedLines( "Una línea\\n0:10 otra\\nY otra\\nY otra más" ),'
	. ' chapters: t.parseChapters( ' . wp_json_encode( $youtube ) . ' ),'
	. ' fromFile: t.parseChapters( ' . wp_json_encode( $vtt_chapters ) . ' ),'
	. ' wrapped: t.wrap( "Una frase que es bastante más larga que una línea de subtítulos" )'
	. ' } ) );'
);

$result = json_decode( (string) shell_exec( 'node ' . escapeshellarg( $script ) . ' 2>&1' ), true );
shell_exec( 'rm -rf ' . escapeshellarg( $out ) );

if ( ! is_array( $result ) ) {
	check( 'the editor’s text tools ran', false );
	exit( 1 );
}

$show = static fn( $value ): string => (string) wp_json_encode( $value );

/**
 * What a browser would refuse in a WebVTT file, or an empty string.
 */
function vtt_problem( string $vtt ): string {
	if ( ! str_starts_with( $vtt, "WEBVTT\n" ) ) {
		return 'no header';
	}

	$last = -1.0;

	foreach ( array_slice( preg_split( '/\n\n+/', trim( $vtt ) ), 1 ) as $block ) {
		$lines = explode( "\n", $block );

		if ( ! preg_match( '/^(\d{2}):(\d{2}):(\d{2})\.(\d{3}) --> (\d{2}):(\d{2}):(\d{2})\.(\d{3})$/', $lines[0], $m ) ) {
			return 'timing line: ' . $lines[0];
		}

		$start = $m[1] * 3600 + $m[2] * 60 + $m[3] + $m[4] / 1000;
		$end   = $m[5] * 3600 + $m[6] * 60 + $m[7] + $m[8] / 1000;

		if ( $end <= $start || $start < $last - 0.001 ) {
			return 'times out of order at ' . $lines[0];
		}

		if ( count( $lines ) > 3 || str_contains( implode( "\n", array_slice( $lines, 1 ) ), '-->' ) ) {
			return 'cue text: ' . $block;
		}

		$last = $start;
	}

	return '';
}

echo '# Times' . PHP_EOL;
check( 'read as a clock writes them', array( 83, 3723, 5.25 ) === array_slice( $result['clocks'], 0, 3 ) && null === $result['clocks'][3], $show( $result['clocks'] ) );
check( 'written as WebVTT writes them, a thousandth carried', array( '00:01:23.500', '00:01:00.000', '01:02:03.000' ) === $result['vtt'], $show( $result['vtt'] ) );

echo PHP_EOL . '# A transcript with its times' . PHP_EOL;
$cues = $result['timed']['cues'];
check( 'is not a guess', false === $result['timed']['approximate'] );
check( 'each line starts at its time', 0 === $cues[0]['start'] && 4 === $cues[1]['start'] && 20 === $cues[ count( $cues ) - 1 ]['start'], $show( array_column( $cues, 'start' ) ) );
check( 'and is over by the next one', $cues[0]['end'] <= 4 );
check( 'a long line is shared out over several cues before the next', count( $cues ) >= 4 && max( array_map( static fn( $c ) => $c['end'], array_slice( $cues, 1, -1 ) ) ) <= 20 );
check( 'a line without a time joins the one above', str_contains( implode( ' ', array_column( $cues, 'text' ) ), 'sigue a la de arriba' ) );
check( 'a cue holds two lines at most', max( array_map( static fn( $c ) => strlen( $c['text'] ), $cues ) ) <= 90 );
check( 'the last stays long enough to be read', $cues[ count( $cues ) - 1 ]['end'] - 20 >= 1.19 );
check( 'and the file is one a browser reads', '' === vtt_problem( $result['timedVtt'] ), vtt_problem( $result['timedVtt'] ) );
check( 'most lines need a time to count as timed', null === $result['notTimed'] );

echo PHP_EOL . '# A transcript without times' . PHP_EOL;
$cues = $result['plain']['cues'];
check( 'is said to be a guess', true === $result['plain']['approximate'] );
check( 'laid across the video, from its start to its end', 0 === $cues[0]['start'] && abs( $cues[ count( $cues ) - 1 ]['end'] - 60 ) < 0.01, $show( array( $cues[0]['start'], end( $cues )['end'] ) ) );
check( 'cut between words, never inside one, losing none', preg_split( '/\s+/', $plain ) === preg_split( '/\s+/', implode( ' ', array_column( $cues, 'text' ) ) ) );
check( 'at the end of a sentence where there is one', str_starts_with( $cues[1]['text'], 'Tiene varias frases' ) );
check( 'none longer than two lines', max( array_map( static fn( $c ) => mb_strlen( $c['text'] ), $cues ) ) <= 84 );
check( 'a browser reads it', '' === vtt_problem( $result['plainVtt'] ), vtt_problem( $result['plainVtt'] ) );
check( 'and reads it back as the same cues', count( $cues ) === count( $result['roundTrip'] ) && abs( $result['roundTrip'][1]['start'] - $cues[1]['start'] ) < 0.002 );
check( 'without the video’s length, at reading speed', end( $result['guess']['cues'] )['end'] > 10 );

echo PHP_EOL . '# Subtitles pasted whole' . PHP_EOL;
check( 'SubRip, as it comes from Windows', array( 1, 3.5, 4, 6.25 ) === array( $result['srt']['cues'][0]['start'], $result['srt']['cues'][0]['end'], $result['srt']['cues'][1]['start'], $result['srt']['cues'][1]['end'] ), $show( $result['srt']['cues'] ) );
check( 'become WebVTT', '' === vtt_problem( $result['srtVtt'] ) && str_contains( $result['srtVtt'], '00:00:01.000 --> 00:00:03.500' ) );
check( 'with an arrow in the text that cannot end the cue', str_contains( $result['srtVtt'], 'Segunda → línea' ) );

echo PHP_EOL . '# Chapters' . PHP_EOL;
check( 'as YouTube’s description writes them, time first or last', array( 'Introducción', 'Segunda parte', 'Tercera parte', 'Al final del todo' ) === array_column( $result['chapters'], 'title' ), $show( $result['chapters'] ) );
check( 'at their times, in order', array( 0, 90, 725, 3723 ) === array_column( $result['chapters'], 'start' ) );
check( 'or from a chapters file', array( array( 'start' => 0, 'title' => 'Uno' ), array( 'start' => 60, 'title' => 'Dos' ) ) === $result['fromFile'] );

echo PHP_EOL . '# A line on screen' . PHP_EOL;
$lines = explode( "\n", $result['wrapped'] );
check( 'broken in two near the middle, at a word', 2 === count( $lines ) && abs( strlen( $lines[0] ) - strlen( $lines[1] ) ) < 12 && ! str_ends_with( $lines[0], ' ' ) );

echo PHP_EOL . '# Transcribed by a service' . PHP_EOL;

// The parts of WordPress a transcription touches, recorded.
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['stub_posts_sent'][] = array( 'url' => $url, 'args' => $args );
	return array_shift( $GLOBALS['stub_post_answers'] ) ?? array( 'code' => 500, 'body' => '' );
}
function wp_upload_bits( $name, $deprecated, $bits ) {
	$file = sys_get_temp_dir() . '/imgp-up-' . $name;
	file_put_contents( $file, $bits );
	return array( 'file' => $file, 'url' => 'https://example.test/uploads/' . $name, 'error' => false );
}
function wp_insert_attachment( $post, $file, $parent = 0, $wp_error = false ) {
	$GLOBALS['stub_inserted'][] = array( 'post' => $post, 'file' => $file, 'parent' => $parent );
	return 70 + count( $GLOBALS['stub_inserted'] );
}
function sanitize_file_name( $name ) { return (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $name ); }
function spawn_cron() { $GLOBALS['stub_spawned'] = ( $GLOBALS['stub_spawned'] ?? 0 ) + 1; }

use ImaginaPlayer\Leads\Integrations;
use ImaginaPlayer\Media\Transcriber;
use ImaginaPlayer\Rest\TranscriptionController;

check( 'not offered until a service is set up', ! Transcriber::available() );
Integrations::save( array( 'transcription' => array( 'enabled' => true, 'endpoint' => 'ftp://nope', 'api_key' => 'sk-test-0123456789', 'language' => 'ES!' ) ) );
check( 'an address that is not http or https is not kept', '' === Integrations::get()['transcription']['endpoint'] && ! Transcriber::available() );
check( 'and a language is a code', 'es' === Integrations::get()['transcription']['language'] );
Integrations::save( array( 'transcription' => array( 'endpoint' => 'https://api.example.com/v1/audio/transcriptions' ) ) );
check( 'set up, it is offered', Transcriber::available() );
check( 'its key never reaches the screen', '••••6789' === Integrations::for_admin()['transcription']['api_key'] );

$media  = sys_get_temp_dir() . '/imgp-clase-' . getmypid() . '.wav';
$ffmpeg = trim( (string) shell_exec( 'command -v ffmpeg 2>/dev/null' ) );
if ( '' !== $ffmpeg ) {
	shell_exec( escapeshellarg( $ffmpeg ) . ' -loglevel error -y -f lavfi -i sine=frequency=440:duration=2 ' . escapeshellarg( $media ) );
} else {
	file_put_contents( $media, str_repeat( "\0", 2048 ) );
}
$GLOBALS['stub_posts'][90] = array( 'file' => $media, 'mime' => 'video/mp4', 'url' => 'https://example.test/clase.mp4' );

$vtt = "WEBVTT\n\n00:00:00.000 --> 00:00:02.000\nHola.\n";
$GLOBALS['stub_post_answers'] = array( array( 'code' => 200, 'body' => "\xEF\xBB\xBF" . $vtt ) );
$GLOBALS['stub_posts_sent']   = array();
$done = Transcriber::transcribe( 90, 'es' );
$sent = $GLOBALS['stub_posts_sent'][0] ?? array();
$body = (string) ( $sent['args']['body'] ?? '' );
check( 'sent to the address set up, with the key', 'https://api.example.com/v1/audio/transcriptions' === ( $sent['url'] ?? '' ) && 'Bearer sk-test-0123456789' === ( $sent['args']['headers']['Authorization'] ?? '' ) );
check( 'as a form, asking for WebVTT in the language given', str_contains( (string) ( $sent['args']['headers']['Content-Type'] ?? '' ), 'multipart/form-data; boundary=' ) && str_contains( $body, "name=\"response_format\"\r\n\r\nvtt" ) && str_contains( $body, "name=\"language\"\r\n\r\nes" ) && str_contains( $body, "name=\"model\"\r\n\r\nwhisper-1" ) );
check(
	'' !== $ffmpeg ? 'only the sound, made small by ffmpeg' : 'the file itself, there being no ffmpeg',
	'' !== $ffmpeg ? str_contains( $body, 'filename="' . pathinfo( $media, PATHINFO_FILENAME ) . '.mp3"' ) && str_contains( $body, 'Content-Type: audio/mpeg' ) : str_contains( $body, basename( $media ) )
);
check( 'the subtitles become a file in the library, beside the video', 'done' === ( $done['status'] ?? '' ) && 'text/vtt' === ( $GLOBALS['stub_inserted'][0]['post']['post_mime_type'] ?? '' ) && 90 === ( $GLOBALS['stub_inserted'][0]['parent'] ?? 0 ) );
check( 'named for the video and the language', str_ends_with( (string) ( $done['url'] ?? '' ), '-es.vtt' ) && $vtt === (string) file_get_contents( (string) $GLOBALS['stub_inserted'][0]['file'] ) );
check( 'and no copy of the sound is left behind', array() === glob( sys_get_temp_dir() . '/imgp-transcribe*' ) );

$GLOBALS['stub_post_answers'] = array( array( 'code' => 401, 'body' => '{"error":{"message":"Incorrect API key provided."}}' ) );
$refused = Transcriber::transcribe( 90, 'es' );
check( 'a refusal comes back in the service’s own words', 'failed' === $refused['status'] && 'Incorrect API key provided.' === $refused['message'] );
$GLOBALS['stub_post_answers'] = array( array( 'code' => 200, 'body' => '{"text":"Hola."}' ) );
check( 'and an answer that is not subtitles is not saved as subtitles', 'failed' === Transcriber::transcribe( 90, 'es' )['status'] );

// No ffmpeg: a path that is not there, which is now the only one tried.
$GLOBALS['stub_options']['imagina_player_settings']['peaks']['ffmpeg_path'] = '/nowhere/ffmpeg';
\ImaginaPlayer\Settings::flush_cache();
add_filter( 'imagina_player_transcribe_max_bytes', static fn() => 10 );
$GLOBALS['stub_posts_sent'] = array();
$big = Transcriber::transcribe( 90, 'es' );
check( 'without ffmpeg, a file too big for the service is not sent, and says why', 'failed' === $big['status'] && str_contains( $big['message'], 'ffmpeg' ) && array() === $GLOBALS['stub_posts_sent'] );
remove_all_filters( 'imagina_player_transcribe_max_bytes' );
unset( $GLOBALS['stub_options']['imagina_player_settings']['peaks'] );
\ImaginaPlayer\Settings::flush_cache();
check( 'nor is a file that is not on this server', 'failed' === Transcriber::transcribe( 91, 'es' )['status'] );

echo PHP_EOL . '# In the background' . PHP_EOL;

$GLOBALS['stub_scheduled'] = array();
$job   = Transcriber::start( 90, 'ES', 3 );
$state = Transcriber::status( $job );
check( 'a job is lined up, not done while the editor waits', 'queued' === ( $state['status'] ?? '' ) && Transcriber::HOOK === ( $GLOBALS['stub_scheduled'][0][0] ?? '' ) && array( $job ) === ( $GLOBALS['stub_scheduled'][0][1] ?? null ) );
check( 'and started at once rather than on the next visit', ( $GLOBALS['stub_spawned'] ?? 0 ) >= 1 && 'es' === $state['language'] );

$GLOBALS['stub_post_answers'] = array( array( 'code' => 200, 'body' => $vtt ) );
Transcriber::run( $job );
check( 'run, it is done, with where its file is', 'done' === ( Transcriber::status( $job )['status'] ?? '' ) && str_ends_with( (string) Transcriber::status( $job )['url'], '.vtt' ) );
$asked = count( (array) $GLOBALS['stub_posts_sent'] );
Transcriber::run( $job );
check( 'and running it again does nothing', $asked === count( (array) $GLOBALS['stub_posts_sent'] ) );

set_transient( 'imgp_tx_00000000000000aa', array( 'status' => 'working', 'started' => time() - 3600, 'attachment' => 90, 'language' => '', 'user' => 3 ), DAY_IN_SECONDS );
check( 'a job that died while working is not waited for for ever', 'failed' === Transcriber::status( '00000000000000aa' )['status'] );

echo PHP_EOL . '# Who may ask' . PHP_EOL;

$GLOBALS['stub_routes'] = array();
( new TranscriptionController() )->register_routes();
$start  = $GLOBALS['stub_routes'][0][2];
$status = $GLOBALS['stub_routes'][1][2];
$GLOBALS['stub_caps'] = array( 'edit_post' );
check( 'somebody who cannot upload files cannot start one', false === ( $start['permission_callback'] )( new WP_REST_Request( array( 'attachment' => 90 ) ) ) );
$GLOBALS['stub_caps'] = array( 'upload_files' );
check( 'nor somebody who cannot edit the file', false === ( $start['permission_callback'] )( new WP_REST_Request( array( 'attachment' => 90 ) ) ) );
$GLOBALS['stub_caps'] = array( 'upload_files', 'edit_post' );
check( 'somebody who can both, can', true === ( $start['permission_callback'] )( new WP_REST_Request( array( 'attachment' => 90 ) ) ) );

$GLOBALS['stub_posts'][92] = array( 'file' => $media, 'mime' => 'image/jpeg' );
$picture = ( new TranscriptionController() )->start( new WP_REST_Request( array( 'attachment' => 92 ) ) );
check( 'a picture is not something to transcribe', $picture instanceof WP_Error );

$GLOBALS['stub_current_user'] = 4;
check( 'a job is only told to whoever started it', ( new TranscriptionController() )->status( new WP_REST_Request( array( 'job' => $job ) ) ) instanceof WP_Error );
$GLOBALS['stub_current_user'] = 3;
$mine = ( new TranscriptionController() )->status( new WP_REST_Request( array( 'job' => $job ) ) );
check( 'who sees how it went, and nothing of the file', $mine instanceof WP_REST_Response && 'done' === $mine->get_data()['status'] && ! isset( $mine->get_data()['attachment'] ) && ! isset( $mine->get_data()['user'] ) );
unset( $GLOBALS['stub_caps'], $GLOBALS['stub_current_user'] );

Integrations::save( array( 'transcription' => array( 'enabled' => false ) ) );
check( 'switched off, it cannot be started', ( new TranscriptionController() )->start( new WP_REST_Request( array( 'attachment' => 90 ) ) ) instanceof WP_Error );

@unlink( $media );
foreach ( (array) ( $GLOBALS['stub_inserted'] ?? array() ) as $inserted ) {
	@unlink( (string) $inserted['file'] );
}

echo PHP_EOL . ( $failures ? "{$failures} FAILURE(S)" : 'All checks passed.' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
