<?php
/**
 * "Generate missing waveforms" — does it, and does it say what happened?
 *
 * Two questions, and until now the honest answer to both was no.
 *
 * It asked the media library for attachments with no stored waveform, which
 * quietly excluded two whole groups. A file measured by an older version has a
 * stored waveform, so it was skipped — and those are exactly the ones worth
 * doing again. And a track played from an address rather than an upload has no
 * row in the media library at all, so on a site whose audio lives on a media
 * host the button found nothing and reported success, which reads as "all
 * done" when nothing was done.
 *
 * And when a file did fail it said "the rest failed — the first: <raw tag>".
 * Not which files, not why each one, and the tag rather than the sentence.
 *
 * @package ImaginaPlayer
 */

require __DIR__ . '/bootstrap.php';

use ImaginaPlayer\Peaks\PeaksRepository;
use ImaginaPlayer\Rest\PeaksController;

$root     = dirname( __DIR__ );
$failures = 0;

function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

$GLOBALS['stub_table_exists'] = 'wp_imagina_player_peaks';
$GLOBALS['stub_table_rows']   = array();
$GLOBALS['stub_meta']         = array();
$GLOBALS['stub_attachments']  = array();
$GLOBALS['stub_posts']        = array();

$repository = new PeaksRepository();
$controller = new PeaksController();

/**
 * What the button would work through.
 *
 * @return array<string, array<string, mixed>> Address or id to its entry.
 */
function pending_now( PeaksController $controller ): array {
	$data = $controller->list_pending( new WP_REST_Request( array( 'limit' => 100 ) ) )->get_data();

	$out = array();

	/*
	 * Keyed by address, and by id for an upload — an upload's address comes
	 * from the media library and two of them can perfectly well be blank in a
	 * test, which silently collapsed two entries into one and reported the
	 * first as missing.
	 */
	foreach ( (array) ( $data['pending'] ?? array() ) as $item ) {
		$out[ $item['id'] > 0 ? 'att_' . $item['id'] : $item['url'] ] = $item;
	}

	return $out;
}

echo PHP_EOL . '# Files in the media library' . PHP_EOL;

$GLOBALS['stub_attachments'] = array( 11 => true, 12 => true, 13 => true );

foreach ( array( 11, 12, 13 ) as $id ) {
	$GLOBALS['stub_posts'][ $id ]['url'] = 'https://example.com/wp-content/uploads/' . $id . '.mp3';
}

// 11 has nothing. 12 was measured the old way. 13 was measured this way.
$repository->save( 'att_12', array( 0.2, 0.8 ), 60.0 );
$GLOBALS['stub_meta'][12][ PeaksRepository::META_KEY ]['version'] = 1;

$repository->save( 'att_13', array( 0.3, 0.7 ), 60.0 );

$pending = pending_now( $controller );
$ids     = array_column( $pending, 'id' );

check( 'a file with no waveform is included', in_array( 11, $ids, true ), implode( ', ', $ids ) );

/*
 * The group that was silently excluded. It has a stored waveform, so the old
 * query skipped it — and it is the one that most needs doing, because the way
 * a bar is worked out changed.
 */
check( 'a file measured the old way is included too', in_array( 12, $ids, true ), implode( ', ', $ids ) );

check( 'and one measured this way is left alone', ! in_array( 13, $ids, true ), implode( ', ', $ids ) );

echo PHP_EOL . '# Tracks played from an address' . PHP_EOL;

/*
 * The group that could never be reached. A waveform is stored under a hash of
 * the address and a hash cannot be turned back into one, so the only place
 * these exist is inside the posts that play them.
 */
$lesson  = 'https://media.publit.io/file/lesson-one.mp3';
$second  = 'https://media.publit.io/file/lesson-two.mp3';
$already = 'https://media.publit.io/file/lesson-three.mp3';

$GLOBALS['stub_posts'] = array(
	101 => array(
		'post_content' => '<!-- wp:imagina-player/audio {"src":"' . $lesson . '","title":"Lesson one"} /-->',
	),
	102 => array(
		'post_content' => '<!-- wp:imagina-player/playlist {"tracks":[{"src":"' . $second . '","title":"Lesson two"}]} /-->'
			. "\n" . '<!-- wp:imagina-player/audio {"src":"' . $already . '"} /-->',
	),
	103 => array(
		// An upload, which is found through the media library instead.
		'post_content' => '<!-- wp:imagina-player/audio {"attachmentId":11,"src":"https://example.com/wp-content/uploads/a.mp3"} /-->',
	),
);

$repository->save( 'url_' . md5( $already ), array( 0.4, 0.6 ), 120.0 );

$pending = pending_now( $controller );

check(
	'a track played from an address is found, in the post that plays it',
	isset( $pending[ $lesson ] ),
	implode( ', ', array_keys( $pending ) )
);

check(
	'and it is named, so a failure can say which one',
	'Lesson one' === ( $pending[ $lesson ]['title'] ?? '' ),
	(string) ( $pending[ $lesson ]['title'] ?? 'nothing' )
);

check(
	'and it carries no attachment, which is what says to store it against the address',
	0 === ( $pending[ $lesson ]['id'] ?? -1 )
);

check(
	'a track inside a playlist is found as well',
	isset( $pending[ $second ] ),
	'a playlist holds several, and none of them were reachable'
);

check(
	'one that already has a current waveform is left alone',
	! isset( $pending[ $already ] ),
	'otherwise every run does all the work again'
);

/*
 * And an upload embedded by id is not listed twice — it is found through the
 * media library, which knows its file size and can be measured on the server.
 */
$uploads = array_filter(
	$pending,
	static fn( array $item ): bool => 0 === $item['id']
		&& str_contains( $item['url'], '/wp-content/uploads/' )
);

check(
	'an uploaded track is not listed a second time for its address',
	array() === $uploads,
	'it is already in the list by its attachment id'
);

echo PHP_EOL . '# What the browser does with the list' . PHP_EOL;

$panels = (string) file_get_contents( $root . '/assets/src/admin/panels.tsx' );

check(
	'a track with no attachment is stored against its address',
	str_contains( $panels, 'storeWaveform( item.id, result.peaks, result.duration, item.url )' ),
	'without the address it would be stored against attachment zero, which is nothing'
);

check(
	'and the endpoint accepts that',
	str_contains(
		(string) file_get_contents( $root . '/src/Rest/PeaksController.php' ),
		"'url_' . md5( \$src )"
	)
);

echo PHP_EOL . '# What it says when something fails' . PHP_EOL;

check(
	'every failure is kept, not just the first',
	str_contains( $panels, 'const failures: Array< { title: string; why: string } > = [];' )
		&& str_contains( $panels, 'failures.push( {' ),
	'a count with one message says neither which files nor what to do'
);

check(
	'each one is named',
	str_contains( $panels, 'title: waiting[ i ].title || waiting[ i ].url' )
);

check(
	'and given the sentence the editor uses, not the raw tag',
	str_contains( $panels, 'why: reason( failure )' ),
	'"proxy-upstream-403|slice 13 of 13" is not something to act on'
);

check(
	'and they are all put on screen',
	str_contains( $panels, 'report.map( ( failure ) => (' )
		&& str_contains( $panels, 'could not be measured:' )
);

echo PHP_EOL;
echo 0 === $failures ? 'All generate-all checks passed.' . PHP_EOL : "{$failures} FAILURE(S)" . PHP_EOL;

exit( 0 === $failures ? 0 : 1 );
