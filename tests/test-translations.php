<?php
/**
 * The Spanish the plugin actually ships.
 *
 * A translation is the one feature that fails silently. Nothing throws, no
 * layout breaks; the interface simply carries on in English, or — worse — a
 * sentence comes back with its `%s` missing and a visitor is told
 * "%d de generadas". So the checks here are about the pipeline rather than
 * the prose:
 *
 * - the template has not drifted from the source (a string added in a panel
 *   last week is a string nobody can translate until it is extracted);
 * - every string in the template has Spanish;
 * - the .mo the server reads round-trips, and its plurals survived;
 * - the placeholders in a translation match the ones in the original, in kind
 *   and in number, so `sprintf` cannot be handed something it will refuse;
 * - each bundle's .json carries that bundle's strings and not the rest of the
 *   catalogue, and the front end — which translates nothing — has no file at
 *   all.
 */

require __DIR__ . '/bootstrap.php';

$root     = dirname( __DIR__ );
$failures = 0;

function check( string $label, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) { $failures++; }
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . ( $ok || '' === $detail ? '' : "  [{$detail}]" ) . PHP_EOL;
}

/**
 * Every msgid, msgid_plural and msgstr in a .po or .pot, keyed by msgid.
 *
 * @return array<string, array{plural: string, msgstr: array<int, string>, refs: array<int, string>}>
 */
function read_po( string $file ): array {
	$entries = array();
	$current = array( 'msgid' => null, 'plural' => '', 'msgstr' => array(), 'refs' => array() );
	$pending = array();
	$field   = '';

	$unescape = static function ( string $v ): string {
		return str_replace( array( '\\n', '\\t', '\\"', '\\\\' ), array( "\n", "\t", '"', '\\' ), $v );
	};

	$flush = static function () use ( &$entries, &$current ): void {
		if ( null !== $current['msgid'] && '' !== $current['msgid'] ) {
			$entries[ $current['msgid'] ] = array(
				'plural' => $current['plural'],
				'msgstr' => $current['msgstr'],
				'refs'   => $current['refs'],
			);
		}
		$current = array( 'msgid' => null, 'plural' => '', 'msgstr' => array(), 'refs' => array() );
	};

	foreach ( file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
		$line = trim( $line );

		if ( '' === $line ) {
			$flush();
			$field   = '';
			$pending = array();
			continue;
		}

		if ( '#' === $line[0] ) {
			if ( str_starts_with( $line, '#: ' ) ) {
				foreach ( preg_split( '/\s+/', trim( substr( $line, 3 ) ) ) as $ref ) {
					if ( '' !== $ref ) {
						$pending[] = preg_replace( '/:[0-9]+$/', '', $ref );
					}
				}
			}
			continue;
		}

		if ( preg_match( '/^msgid\s+"(.*)"$/', $line, $m ) ) {
			$flush();
			$current['msgid'] = $unescape( $m[1] );
			$current['refs']  = $pending;
			$pending          = array();
			$field            = 'msgid';
			continue;
		}

		if ( preg_match( '/^msgid_plural\s+"(.*)"$/', $line, $m ) ) {
			$current['plural'] = $unescape( $m[1] );
			$field             = 'plural';
			continue;
		}

		if ( preg_match( '/^msgstr(?:\[(\d+)\])?\s+"(.*)"$/', $line, $m ) ) {
			$index                       = isset( $m[1] ) && '' !== $m[1] ? (int) $m[1] : 0;
			$current['msgstr'][ $index ] = $unescape( $m[2] );
			$field                       = 'msgstr' . $index;
			continue;
		}

		if ( preg_match( '/^"(.*)"$/', $line, $m ) && '' !== $field ) {
			$text = $unescape( $m[1] );
			if ( 'msgid' === $field ) {
				$current['msgid'] .= $text;
			} elseif ( 'plural' === $field ) {
				$current['plural'] .= $text;
			} elseif ( str_starts_with( $field, 'msgstr' ) ) {
				$current['msgstr'][ (int) substr( $field, 6 ) ] .= $text;
			}
		}
	}

	$flush();

	return $entries;
}

/**
 * Read a compiled .mo back into `original => translation`.
 *
 * Written against the format rather than against `bin/make-mo.php`, so a bug
 * in the writer cannot hide behind a matching bug in the reader.
 */
function read_mo( string $file ): array {
	$blob = (string) file_get_contents( $file );

	if ( strlen( $blob ) < 28 ) {
		return array();
	}

	$magic = unpack( 'V', substr( $blob, 0, 4 ) )[1];

	if ( 0x950412de !== $magic ) {
		return array();
	}

	$header = unpack( 'Vrevision/Vcount/Voriginals/Vtranslations', substr( $blob, 4, 16 ) );
	$out    = array();

	for ( $i = 0; $i < $header['count']; $i++ ) {
		$o = unpack( 'Vlength/Voffset', substr( $blob, $header['originals'] + $i * 8, 8 ) );
		$t = unpack( 'Vlength/Voffset', substr( $blob, $header['translations'] + $i * 8, 8 ) );

		$out[ substr( $blob, $o['offset'], $o['length'] ) ] = substr( $blob, $t['offset'], $t['length'] );
	}

	return $out;
}

/** Every printf placeholder in a string, as a sorted list. */
function placeholders( string $text ): array {
	preg_match_all( '/%(?:[0-9]+\$)?[-+ 0#\']*[0-9]*(?:\.[0-9]+)?[bcdeEfFgGosuxX%]/', $text, $m );

	$found = array_filter( $m[0], static fn( $p ) => '%%' !== $p );
	sort( $found );

	return array_values( $found );
}

echo PHP_EOL . '# The template still matches the source' . PHP_EOL;

$pot_file = $root . '/languages/imagina-player.pot';

check( 'the template is committed', is_readable( $pot_file ) );

$tmp = sys_get_temp_dir() . '/imgp-pot-' . getmypid() . '.pot';
exec( 'php ' . escapeshellarg( $root . '/bin/make-pot.php' ) . ' ' . escapeshellarg( $tmp ) . ' 2>&1', $out, $code );

if ( 0 !== $code || ! is_readable( $tmp ) ) {
	// The extractor writes to its default location when given no argument.
	$tmp = $pot_file;
	$fresh = read_po( $pot_file );
	check( 'the extractor runs', false, 'php bin/make-pot.php exited ' . $code );
} else {
	$fresh = read_po( $tmp );
}

$template = read_po( $pot_file );

$missing_from_template = array_diff( array_keys( $fresh ), array_keys( $template ) );
$stale_in_template     = array_diff( array_keys( $template ), array_keys( $fresh ) );

check(
	'every translatable string in the source is in the template',
	array() === $missing_from_template,
	implode( ' | ', array_slice( $missing_from_template, 0, 3 ) )
);

check(
	'and the template has nothing the source no longer says',
	array() === $stale_in_template,
	implode( ' | ', array_slice( $stale_in_template, 0, 3 ) )
);

if ( $tmp !== $pot_file ) {
	@unlink( $tmp );
}

echo PHP_EOL . '# Spanish' . PHP_EOL;

$po_file = $root . '/languages/imagina-player-es_ES.po';

check( 'es_ES.po ships', is_readable( $po_file ) );

$po = read_po( $po_file );

check(
	'it covers the whole template',
	array() === array_diff( array_keys( $template ), array_keys( $po ) ),
	implode( ' | ', array_slice( array_diff( array_keys( $template ), array_keys( $po ) ), 0, 3 ) )
);

$empty = array();

foreach ( $po as $msgid => $entry ) {
	$wanted = '' === $entry['plural'] ? 1 : 2;

	for ( $i = 0; $i < $wanted; $i++ ) {
		if ( '' === trim( $entry['msgstr'][ $i ] ?? '' ) ) {
			$empty[] = $msgid;
			break;
		}
	}
}

check( 'and nothing is left untranslated', array() === $empty, implode( ' | ', array_slice( $empty, 0, 3 ) ) );

check(
	'the header declares the language and its plural rule',
	str_contains( (string) file_get_contents( $po_file ), 'Language: es_ES' )
		&& str_contains( (string) file_get_contents( $po_file ), 'nplurals=2' )
);

echo PHP_EOL . '# Placeholders survived the translation' . PHP_EOL;

$broken = array();

foreach ( $po as $msgid => $entry ) {
	$originals = array( $msgid );

	if ( '' !== $entry['plural'] ) {
		$originals[] = $entry['plural'];
	}

	foreach ( $originals as $i => $original ) {
		$expected = placeholders( $original );
		$actual   = placeholders( $entry['msgstr'][ $i ] ?? '' );

		if ( $expected !== $actual ) {
			$broken[] = $original . ' → ' . implode( ',', $actual ) . ' (wanted ' . implode( ',', $expected ) . ')';
		}
	}
}

check( 'every translation carries the same placeholders as its original', array() === $broken, implode( ' | ', array_slice( $broken, 0, 3 ) ) );

echo PHP_EOL . '# The compiled catalogue' . PHP_EOL;

$mo_file = $root . '/languages/imagina-player-es_ES.mo';

check( 'es_ES.mo ships', is_readable( $mo_file ) );

$mo = read_mo( $mo_file );

check( 'and it is a real MO a gettext reader can open', array() !== $mo );

check(
	'its header is present, which is where the plural rule is read from',
	isset( $mo[''] ) && str_contains( $mo[''], 'nplurals=2' )
);

$lost = array();

foreach ( $po as $msgid => $entry ) {
	$key = '' === $entry['plural'] ? $msgid : $msgid . "\0" . $entry['plural'];

	if ( ! isset( $mo[ $key ] ) ) {
		$lost[] = $msgid;
		continue;
	}

	$expected = '' === $entry['plural']
		? $entry['msgstr'][0]
		: $entry['msgstr'][0] . "\0" . $entry['msgstr'][1];

	if ( $mo[ $key ] !== $expected ) {
		$lost[] = $msgid . ' (wrong text)';
	}
}

check( 'every entry round-trips through the binary', array() === $lost, implode( ' | ', array_slice( $lost, 0, 3 ) ) );

$plural_key = null;

foreach ( $po as $msgid => $entry ) {
	if ( '' !== $entry['plural'] ) {
		$plural_key = $msgid . "\0" . $entry['plural'];
		break;
	}
}

check(
	'a plural entry keeps both forms, separated the way gettext expects',
	null !== $plural_key && isset( $mo[ $plural_key ] ) && 2 === count( explode( "\0", $mo[ $plural_key ] ) ),
	null === $plural_key ? 'no plural entry in the catalogue' : ''
);

echo PHP_EOL . '# What each bundle downloads' . PHP_EOL;

$bundles = array(
	'build/editor.js' => array( 'assets/src/editor/', 'assets/src/shared/' ),
	'build/admin.js'  => array( 'assets/src/admin/', 'assets/src/shared/' ),
);

foreach ( $bundles as $script => $prefixes ) {
	$name = $root . '/languages/imagina-player-es_ES-' . md5( $script ) . '.json';

	check( basename( $script ) . ' has a translation file', is_readable( $name ), $name );

	if ( ! is_readable( $name ) ) {
		continue;
	}

	$json     = json_decode( (string) file_get_contents( $name ), true );
	$messages = $json['locale_data']['messages'] ?? array();

	unset( $messages[''] );

	check( basename( $script ) . ' carries strings', array() !== $messages );

	$foreign = array();

	foreach ( array_keys( $messages ) as $key ) {
		$msgid = explode( "\0", str_replace( "\4", "\0", (string) $key ) )[0];
		$refs  = $po[ $msgid ]['refs'] ?? array();
		$mine  = false;

		foreach ( $refs as $ref ) {
			foreach ( $prefixes as $prefix ) {
				if ( str_starts_with( $ref, $prefix ) ) {
					$mine = true;
					break 2;
				}
			}
		}

		if ( ! $mine ) {
			$foreign[] = $msgid;
		}
	}

	check(
		basename( $script ) . ' carries nothing from anywhere else',
		array() === $foreign,
		implode( ' | ', array_slice( $foreign, 0, 3 ) )
	);
}

/*
 * A string only PHP says has no business in a browser download. `Settings` is
 * in both, so the check picks one that is not.
 */
$php_only = null;

foreach ( $po as $msgid => $entry ) {
	$js = false;

	foreach ( $entry['refs'] as $ref ) {
		if ( str_starts_with( $ref, 'assets/src/' ) ) {
			$js = true;
			break;
		}
	}

	if ( ! $js && array() !== $entry['refs'] ) {
		$php_only = $msgid;
		break;
	}
}

$editor_json = json_decode(
	(string) @file_get_contents( $root . '/languages/imagina-player-es_ES-' . md5( 'build/editor.js' ) . '.json' ),
	true
);

check(
	'a string only the server renders is not shipped to the editor',
	null !== $php_only && ! isset( $editor_json['locale_data']['messages'][ $php_only ] ),
	(string) $php_only
);

echo PHP_EOL . '# WordPress is told where the catalogue is' . PHP_EOL;

$plugin_php = (string) file_get_contents( $root . '/src/Plugin.php' );

check(
	'the plugin loads its own text domain',
	str_contains( $plugin_php, 'load_plugin_textdomain' ),
	'without this the .mo files ship and are never opened'
);

check(
	'on init, which is where WordPress 6.7 wants it',
	(bool) preg_match( "/add_action\\(\\s*'init',\\s*array\\(\\s*\\\$this,\\s*'load_translations'/", $plugin_php )
);

check(
	'and points at the folder the files are actually in',
	(bool) preg_match( "/plugin_basename\\(\\s*FILE\\s*\\)\\s*\\)\\s*\\.\\s*'\\/languages'/", $plugin_php )
);

/*
 * The filename is the whole contract: WordPress opens
 * `<domain>-<locale>.mo` and nothing else. A file named for the wrong locale
 * is a plugin that stays in English with no error anywhere.
 */
check(
	'the catalogue is named the way WordPress will look for it',
	is_readable( $root . '/languages/imagina-player-es_ES.mo' )
);

check(
	'the text domain in the plugin header matches the one the strings use',
	str_contains( (string) file_get_contents( $root . '/imagina-player.php' ), 'Text Domain:       imagina-player' )
		&& str_contains( (string) file_get_contents( $pot_file ), 'X-Domain: imagina-player' )
);

echo PHP_EOL . '# The strings the front end is handed' . PHP_EOL;

/*
 * Nothing in the front-end bundle calls `__()`; the few strings it needs are
 * handed to it by PHP as `runtime.i18n`, with an English literal beside each
 * one as a fallback. That fallback is the problem: a key PHP forgets to send
 * does not break anything, it just shows English on a Spanish site forever.
 * The caption search shipped that way — three strings the server never sent.
 */
$ts_keys = array();

foreach ( glob( $root . '/assets/src/frontend/*.ts' ) as $file ) {
	$code = (string) file_get_contents( $file );

	preg_match_all( "/i18n\\(\\s*'([A-Za-z0-9_]+)'/", $code, $a );
	preg_match_all( '/i18n\\.([A-Za-z0-9_]+)/', $code, $b );
	preg_match_all( "/i18n\\[\\s*'([A-Za-z0-9_]+)'\\s*\\]/", $code, $c );

	$ts_keys = array_merge( $ts_keys, $a[1], $b[1], $c[1] );
}

$ts_keys = array_values( array_unique( array_filter( $ts_keys, static fn( $k ) => 'i18n' !== $k ) ) );

check( 'the front end asks for some translated strings', array() !== $ts_keys );

$assets_php = (string) file_get_contents( $root . '/src/Assets.php' );
$payload    = '';

// The whole `'i18n' => array( … )` literal, up to the line that closes it.
if ( preg_match( "/'i18n'\\s*=>\\s*array\\((.*?)\\n\\t\\t\\t\\),/s", $assets_php, $m ) ) {
	$payload = $m[1];
}

check( 'the runtime payload could be read', '' !== $payload );

preg_match_all( "/'([A-Za-z0-9_]+)'\\s*=>\\s*__\\(/", $payload, $m );
$php_keys = $m[1];

$unsupplied = array_diff( $ts_keys, $php_keys );

check(
	'and the server sends every one of them',
	array() === $unsupplied,
	implode( ', ', $unsupplied ) . ' — these would stay English on a translated site'
);

$unused = array_diff( $php_keys, $ts_keys );

check(
	'without sending any the front end never reads',
	array() === $unused,
	implode( ', ', $unused )
);

echo PHP_EOL . '# The front end asks for nothing' . PHP_EOL;

$assets = (string) file_get_contents( $root . '/src/Assets.php' );

check(
	'no translation file is registered for the front-end bundle',
	! preg_match( '/wp_set_script_translations\(\s*self::FRONTEND_HANDLE/', $assets )
);

check(
	'and none is on disk for it to fetch',
	! file_exists( $root . '/languages/imagina-player-es_ES-' . md5( 'build/frontend.js' ) . '.json' )
);

$frontend_strings = array();

foreach ( $po as $msgid => $entry ) {
	foreach ( $entry['refs'] as $ref ) {
		if ( str_starts_with( $ref, 'assets/src/frontend/' ) ) {
			$frontend_strings[] = $msgid;
			break;
		}
	}
}

check(
	'because nothing in the front-end sources is translatable',
	array() === $frontend_strings,
	implode( ' | ', array_slice( $frontend_strings, 0, 3 ) ) . ' — if this is now false, the handle needs its translations back'
);

echo PHP_EOL . '# A page rendered in Spanish' . PHP_EOL;

/*
 * Everything above proves the files are right. This proves the site is: the
 * catalogue is loaded into the translation stubs and a real player is rendered
 * through the real renderer, then read back for Spanish. Without this the
 * suite could stay green while `__()` was never wired to anything and every
 * label on the page was still English.
 */
$GLOBALS['imgp_catalogue'] = $mo;

$rendered = ( new \ImaginaPlayer\Render\PlayerRenderer() )->render(
	array(
		'src'         => 'https://example.test/wp-content/uploads/lesson.mp4',
		'title'       => 'Clase 1',
		'poster'      => 'https://example.test/poster.jpg',
		'aspectRatio' => '16:9',
	)
);

check( 'a video still renders with a catalogue loaded', ! str_contains( $rendered, 'imgp--empty' ) );

check(
	'its play button is labelled in Spanish',
	str_contains( $rendered, 'Reproducir' ),
	'no Spanish label in the markup'
);

check(
	'and no English label survived beside it',
	! preg_match( '/aria-label="(Play|Pause|Mute|Unmute|Fullscreen)"/', $rendered ),
	'an English aria-label is still in the markup'
);

$runtime = \ImaginaPlayer\Assets::runtime_data();

check(
	'the strings handed to the front-end script are translated too',
	'Reproducir' === ( $runtime['i18n']['play'] ?? '' ) && 'Nada más' !== ( $runtime['i18n']['searchNone'] ?? '' )
		&& 'No se encontró nada.' === ( $runtime['i18n']['searchNone'] ?? '' ),
	wp_json_encode( $runtime['i18n'] ?? array() )
);

/*
 * A plural is the one shape that can be right in the file and wrong on the
 * page: gettext picks the form, and picking it from a catalogue that lost its
 * second string gives the singular to every number.
 */
check(
	'a plural picks the right form from the catalogue',
	'2 comprobaciones fallaron. Los archivos protegidos aún no están a salvo.' === sprintf(
		_n(
			'%d check failed. Protected files are not safe yet.',
			'%d checks failed. Protected files are not safe yet.',
			2,
			'imagina-player'
		),
		2
	),
	sprintf( _n( '%d check failed. Protected files are not safe yet.', '%d checks failed. Protected files are not safe yet.', 2, 'imagina-player' ), 2 )
);

check(
	'and the singular form for one',
	'1 comprobación falló. Los archivos protegidos aún no están a salvo.' === sprintf(
		_n(
			'%d check failed. Protected files are not safe yet.',
			'%d checks failed. Protected files are not safe yet.',
			1,
			'imagina-player'
		),
		1
	)
);

$GLOBALS['imgp_catalogue'] = array();

echo PHP_EOL . '# The JSON the scripts read, asked in the library that reads it' . PHP_EOL;

/*
 * Not inspected — asked.
 *
 * A .mo file and the JSON `wp.i18n` reads key their entries differently, and
 * the generator ran the two formats together: a .mo keys a plural as
 * `msgid \0 msgid_plural`, while the JSON keys it by the msgid alone with the
 * forms in the value. Converting that `\0` into the `\4` that separates a
 * context produced a key meaning "the singular, in the context of the plural",
 * which nothing ever asks for.
 *
 * So every plural string in the editor and the settings screen stayed in
 * English inside an otherwise fully translated interface, for as long as the
 * generator has existed. Singulars were unaffected, which is why it survived
 * this long and took a screenshot of one button to find.
 *
 * A test that reads the JSON and checks it looks right would have been written
 * by the same misunderstanding that produced the file. So this hands it to the
 * actual library the browser runs and asks what it says.
 */
$root = dirname( __DIR__ );

$editor_json = glob( $root . '/languages/imagina-player-es_ES-*.json' );

check( 'the editor and settings bundles have a catalogue each', count( $editor_json ) >= 2, count( $editor_json ) . ' files' );

$runner = sys_get_temp_dir() . '/imgp-i18n-' . getmypid() . '.cjs';

file_put_contents(
	$runner,
	<<<'JS'
const fs = require( 'fs' );
const { createI18n } = require( process.argv[ 2 ] );

const asked = JSON.parse( fs.readFileSync( process.argv[ 3 ], 'utf8' ) );
const said = {};

for ( const [ name, file ] of Object.entries( asked.files ) ) {
	const data = JSON.parse( fs.readFileSync( file, 'utf8' ) );
	const i18n = createI18n( data.locale_data.messages );

	said[ name ] = {};

	for ( const [ label, q ] of Object.entries( asked.questions ) ) {
		said[ name ][ label ] = q.plural
			? i18n._n( q.single, q.plural, q.count )
			: i18n.__( q.single );
	}
}

process.stdout.write( JSON.stringify( said ) );
JS
);

$questions = array(
	'a plain string'      => array( 'single' => 'Media' ),
	'one of something'    => array(
		'single' => 'Measure this waveform again',
		'plural' => 'Measure these waveforms again',
		'count'  => 1,
	),
	'several of them'     => array(
		'single' => 'Measure this waveform again',
		'plural' => 'Measure these waveforms again',
		'count'  => 3,
	),
	'a counted sentence'  => array(
		'single' => '%d file here has no waveform, so it will show a plain progress bar on your site.',
		'plural' => '%d files here have no waveform, so they will show a plain progress bar on your site.',
		'count'  => 2,
	),
);

$ask = sys_get_temp_dir() . '/imgp-i18n-ask-' . getmypid() . '.json';

file_put_contents(
	$ask,
	(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- no WordPress here.
		array(
			'files'     => array(
				'editor' => $root . '/languages/imagina-player-es_ES-' . md5( 'build/editor.js' ) . '.json',
				'admin'  => $root . '/languages/imagina-player-es_ES-' . md5( 'build/admin.js' ) . '.json',
			),
			'questions' => $questions,
		)
	)
);

exec(
	sprintf(
		'node %s %s %s 2>&1',
		escapeshellarg( $runner ),
		escapeshellarg( $root . '/node_modules/@wordpress/i18n/build/index.cjs' ),
		escapeshellarg( $ask )
	),
	$output,
	$code
);

@unlink( $runner ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best effort.
@unlink( $ask ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best effort.

$said = json_decode( implode( '', $output ), true );

check( 'wp.i18n reads them', 0 === $code && is_array( $said ), implode( ' / ', array_slice( $output, 0, 2 ) ) );

if ( is_array( $said ) ) {
	$editor = (array) ( $said['editor'] ?? array() );

	check(
		'a plain string comes back in Spanish',
		'Media' !== ( $editor['a plain string'] ?? 'Media' ),
		(string) ( $editor['a plain string'] ?? 'nothing' )
	);

	/*
	 * The one that was broken. Asked for one and for several, because a key
	 * that is wrong fails both and a plural rule that is wrong fails only one.
	 */
	check(
		'and so does a string with a plural, asked for one',
		'Measure this waveform again' !== ( $editor['one of something'] ?? '' ),
		(string) ( $editor['one of something'] ?? 'nothing' )
	);

	check(
		'and asked for several',
		'Measure these waveforms again' !== ( $editor['several of them'] ?? '' ),
		(string) ( $editor['several of them'] ?? 'nothing' )
	);

	check(
		'with the right form for each',
		( $editor['one of something'] ?? '' ) !== ( $editor['several of them'] ?? '' ),
		'one and several came back identical'
	);

	check(
		'and a counted sentence too',
		! str_contains( (string) ( $editor['a counted sentence'] ?? '' ), 'no waveform, so' ),
		(string) ( $editor['a counted sentence'] ?? 'nothing' )
	);
}

echo PHP_EOL;
echo 0 === $failures ? "All translation checks passed." . PHP_EOL : "{$failures} failed." . PHP_EOL;

exit( 0 === $failures ? 0 : 1 );
