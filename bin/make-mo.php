<?php
/**
 * Compile a .po into the .mo WordPress actually reads, and the .json the
 * editor's JavaScript reads.
 *
 * `msgfmt` is not on this machine either. The MO format is small enough to
 * write: a header, two tables of offsets, and the strings — so this is that,
 * rather than a dependency somebody has to install before they can translate
 * the plugin.
 *
 * Usage: php bin/make-mo.php languages/imagina-player-es_ES.po
 */

declare( strict_types = 1 );

$source = $argv[1] ?? '';

if ( '' === $source || ! is_readable( $source ) ) {
	fwrite( STDERR, "Usage: php bin/make-mo.php <file.po>\n" );
	exit( 1 );
}

/**
 * Read a .po into `context\4msgid\0plural => [translations]`.
 *
 * Only what this plugin's own template produces: no obsolete entries, no
 * previous-msgid comments, no wrapped msgctxt.
 */
function parse_po( string $file ): array {
	$entries = array();
	$current = array( 'msgid' => null, 'plural' => '', 'msgstr' => array(), 'refs' => array() );
	$field   = '';

	/*
	 * `#:` lines sit above the `msgid` they describe, and reading a `msgid`
	 * flushes the entry before it — so they are held here until that flush has
	 * happened, rather than in the entry being closed.
	 */
	$pending = array();

	$flush = static function () use ( &$entries, &$current ): void {
		if ( null !== $current['msgid'] ) {
			$key = '' === $current['plural']
				? $current['msgid']
				: $current['msgid'] . "\0" . $current['plural'];

			$entries[ $key ] = array(
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
			$current['msgid'] = unescape_po( $m[1] );
			$current['refs']  = $pending;
			$pending          = array();
			$field            = 'msgid';
			continue;
		}

		if ( preg_match( '/^msgid_plural\s+"(.*)"$/', $line, $m ) ) {
			$current['plural'] = unescape_po( $m[1] );
			$field             = 'plural';
			continue;
		}

		if ( preg_match( '/^msgstr(?:\[(\d+)\])?\s+"(.*)"$/', $line, $m ) ) {
			$index                       = isset( $m[1] ) && '' !== $m[1] ? (int) $m[1] : 0;
			$current['msgstr'][ $index ] = unescape_po( $m[2] );
			$field                       = 'msgstr' . $index;
			continue;
		}

		// A continuation line: `"more text"` on its own.
		if ( preg_match( '/^"(.*)"$/', $line, $m ) && '' !== $field ) {
			$text = unescape_po( $m[1] );

			if ( 'msgid' === $field ) {
				$current['msgid'] .= $text;
			} elseif ( 'plural' === $field ) {
				$current['plural'] .= $text;
			} elseif ( str_starts_with( $field, 'msgstr' ) ) {
				$index                        = (int) substr( $field, 6 );
				$current['msgstr'][ $index ] .= $text;
			}
		}
	}

	$flush();

	return $entries;
}

function unescape_po( string $value ): string {
	return str_replace(
		array( '\\n', '\\t', '\\"', '\\\\' ),
		array( "\n", "\t", '"', '\\' ),
		$value
	);
}

$entries = parse_po( $source );

// An untranslated entry must not reach the .mo: gettext would return the empty
// string and the interface would go blank rather than fall back to English.
$translated = array();

$references = array();

foreach ( $entries as $key => $entry ) {
	$strings = array_values( array_filter( $entry['msgstr'], static fn( $s ) => '' !== $s ) );

	if ( array() === $strings ) {
		continue;
	}

	$translated[ $key ]  = implode( "\0", $strings );
	$references[ $key ] = $entry['refs'];
}

ksort( $translated );

/* --- The MO file ------------------------------------------------------- */

$keys   = array_keys( $translated );
$values = array_values( $translated );
$count  = count( $keys );

$offset      = 28 + ( $count * 16 );
$key_table   = '';
$value_table = '';
$key_blob    = '';
$value_blob  = '';

foreach ( $keys as $key ) {
	$key_table .= pack( 'VV', strlen( $key ), $offset + strlen( $key_blob ) );
	$key_blob  .= $key . "\0";
}

$value_start = $offset + strlen( $key_blob );

foreach ( $values as $value ) {
	$value_table .= pack( 'VV', strlen( $value ), $value_start + strlen( $value_blob ) );
	$value_blob  .= $value . "\0";
}

$mo = pack( 'V', 0x950412de )        // Magic, little-endian.
	. pack( 'V', 0 )                 // Format revision.
	. pack( 'V', $count )
	. pack( 'V', 28 )                // Where the table of originals starts.
	. pack( 'V', 28 + $count * 8 )   // Where the table of translations starts.
	. pack( 'V', 0 )                 // No hash table.
	. pack( 'V', 0 )
	. $key_table
	. $value_table
	. $key_blob
	. $value_blob;

$target = preg_replace( '/\.po$/', '.mo', $source );
file_put_contents( $target, $mo );

printf( "Wrote %s with %d translations\n", basename( (string) $target ), $count );

/* --- The JSON the scripts read ----------------------------------------- */

/*
 * WordPress looks for `<domain>-<locale>-<md5 of the script's relative path>.json`
 * and hands it to `wp.i18n`.
 *
 * A bundle only needs the strings its own sources ask for. Handing every script
 * the whole catalogue was 45 KB apiece for three files that between them use
 * two thirds of it once — so each bundle is filtered here by the `#:` lines the
 * template recorded. `build/frontend.js` is deliberately absent: nothing in the
 * front-end sources calls `__()`, so there is no file for it to fetch.
 */
$root   = dirname( __DIR__ );
$locale = '';

if ( preg_match( '/-([a-zA-Z_]+)\.po$/', $source, $m ) ) {
	$locale = $m[1];
}

$bundles = array(
	'build/editor.js' => array( 'assets/src/editor/', 'assets/src/shared/' ),
	'build/admin.js'  => array( 'assets/src/admin/', 'assets/src/shared/' ),
);

foreach ( $bundles as $script => $prefixes ) {
	if ( ! is_readable( $root . '/' . $script ) ) {
		continue;
	}

	$messages = array();

	foreach ( $translated as $key => $value ) {
		$wanted = false;

		foreach ( $references[ $key ] ?? array() as $ref ) {
			foreach ( $prefixes as $prefix ) {
				if ( str_starts_with( $ref, $prefix ) ) {
					$wanted = true;
					break 2;
				}
			}
		}

		if ( ! $wanted ) {
			continue;
		}

		/*
		 * Keyed by the singular alone, which is not how a .mo file keys it.
		 *
		 * The two formats disagree and this line used to run them together. A
		 * .mo entry is keyed `msgid \0 msgid_plural`, with `\4` separating a
		 * context from its msgid; the JSON that `wp.i18n` reads is keyed by the
		 * msgid alone, with `\4` for context and the plural forms living in the
		 * value. Turning the `\0` into a `\4` produced a key that says
		 * "singular in the context of plural", which nothing ever asks for.
		 *
		 * So every plural string in the editor and the settings screen stayed in
		 * English, in an otherwise fully translated interface, for as long as
		 * this file has existed. Singulars were fine, which is why it took a
		 * screenshot of one button to notice.
		 *
		 * A context prefix is already `\4` and is left exactly as it is.
		 */
		$messages[ explode( "\0", $key )[0] ] = explode( "\0", $value );
	}

	$json = array(
		'translation-revision-date' => gmdate( 'Y-m-d H:i:sO' ),
		'generator'                 => 'bin/make-mo.php',
		'domain'                    => 'messages',
		'locale_data'               => array(
			'messages' => array(
				'' => array(
					'domain'       => 'messages',
					'lang'         => $locale,
					'plural-forms' => 'nplurals=2; plural=(n != 1);',
				),
			) + $messages,
		),
	);

	$name = 'imagina-player-' . $locale . '-' . md5( $script ) . '.json';

	file_put_contents( $root . '/languages/' . $name, (string) wp_json_encode_compat( $json ) );

	printf( "Wrote languages/%s for %s — %d strings\n", $name, $script, count( $messages ) );
}

function wp_json_encode_compat( array $data ): string {
	return (string) json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}
