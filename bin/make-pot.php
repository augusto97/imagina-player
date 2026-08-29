<?php
/**
 * Build the translation template.
 *
 * There is no `wp i18n make-pot` on this machine and no `xgettext`, so this is
 * the extractor. It is deliberately small and only understands the calls this
 * plugin actually makes — if a new one appears it will be missed, which is why
 * `tests/test-translations.php` counts what is in the source against what is in
 * the template and fails when they drift.
 *
 * Usage: php bin/make-pot.php
 */

declare( strict_types = 1 );

$root   = dirname( __DIR__ );
$domain = 'imagina-player';

/** Files worth scanning: the plugin's own source, not its build output. */
function sources( string $root ): array {
	$files = array();

	foreach ( array( 'src', 'assets/src', 'blocks' ) as $dir ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( preg_match( '/\.(php|ts|tsx|js|jsx)$/', $file->getFilename() ) ) {
				$files[] = $file->getPathname();
			}
		}
	}

	$files[] = $root . '/imagina-player.php';

	sort( $files );

	return $files;
}

/**
 * Every translatable string in one file, with where it came from.
 *
 * The single-quoted form is the only one this plugin uses, which keeps the
 * pattern honest: a double-quoted string with an escape in it would need real
 * parsing, and pretending to handle it would be worse than not matching it.
 */
function strings( string $file, string $domain ): array {
	$code  = (string) file_get_contents( $file );
	$found = array();

	// __( 'x', 'domain' ) and its escaping and echoing variants.
	$single = '/\b(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*,\s*\'' . preg_quote( $domain, '/' ) . '\'\s*\)/';

	if ( preg_match_all( $single, $code, $matches, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $matches[1] as $match ) {
			$found[] = array(
				'msgid'   => stripcslashes( $match[0] ),
				'plural'  => '',
				'line'    => substr_count( substr( $code, 0, (int) $match[1] ), "\n" ) + 1,
				'comment' => translator_note( $code, (int) $match[1] ),
			);
		}
	}

	// _n( 'one', 'many', $count, 'domain' )
	$plural = '/\b_n\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*,\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*,/';

	if ( preg_match_all( $plural, $code, $matches, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $matches[1] as $index => $match ) {
			$found[] = array(
				'msgid'   => stripcslashes( $match[0] ),
				'plural'  => stripcslashes( $matches[2][ $index ][0] ),
				'line'    => substr_count( substr( $code, 0, (int) $match[1] ), "\n" ) + 1,
				'comment' => translator_note( $code, (int) $match[1] ),
			);
		}
	}

	return $found;
}

/**
 * The `translators:` note above a call, if there is one.
 *
 * These are the difference between a translator guessing what `%1$s` is and
 * knowing, so they are worth carrying into the template.
 */
function translator_note( string $code, int $offset ): string {
	$before = substr( $code, max( 0, $offset - 400 ), min( 400, $offset ) );

	if ( preg_match_all( '/translators:\s*(.+?)\s*(?:\*\/|\n)/s', $before, $matches ) ) {
		return trim( (string) end( $matches[1] ) );
	}

	return '';
}

function po_escape( string $value ): string {
	return str_replace(
		array( '\\', '"', "\n", "\t" ),
		array( '\\\\', '\\"', '\\n', '\\t' ),
		$value
	);
}

$entries = array();

foreach ( sources( $root ) as $file ) {
	$relative = ltrim( str_replace( $root, '', $file ), '/' );

	foreach ( strings( $file, $domain ) as $entry ) {
		$key = $entry['msgid'] . "\x04" . $entry['plural'];

		if ( ! isset( $entries[ $key ] ) ) {
			$entries[ $key ] = array(
				'msgid'    => $entry['msgid'],
				'plural'   => $entry['plural'],
				'comment'  => $entry['comment'],
				'places'   => array(),
			);
		}

		$entries[ $key ]['places'][] = $relative . ':' . $entry['line'];

		if ( '' === $entries[ $key ]['comment'] && '' !== $entry['comment'] ) {
			$entries[ $key ]['comment'] = $entry['comment'];
		}
	}
}

ksort( $entries );

$version = '1.0.0';

if ( preg_match( '/Version:\s*([0-9.]+)/', (string) file_get_contents( $root . '/imagina-player.php' ), $m ) ) {
	$version = $m[1];
}

$out  = "# Copyright (C) Imagina\n";
$out .= "# This file is distributed under the same licence as the Imagina Player plugin.\n";
$out .= "msgid \"\"\nmsgstr \"\"\n";
$out .= "\"Project-Id-Version: Imagina Player {$version}\\n\"\n";
$out .= "\"Report-Msgid-Bugs-To: https://github.com/augusto97/imagina-player\\n\"\n";
$out .= "\"MIME-Version: 1.0\\n\"\n";
$out .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n";
$out .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
$out .= "\"X-Generator: bin/make-pot.php\\n\"\n";
$out .= "\"X-Domain: {$domain}\\n\"\n";

foreach ( $entries as $entry ) {
	$out .= "\n";

	if ( '' !== $entry['comment'] ) {
		$out .= '#. translators: ' . $entry['comment'] . "\n";
	}

	foreach ( array_unique( $entry['places'] ) as $place ) {
		$out .= '#: ' . $place . "\n";
	}

	$out .= 'msgid "' . po_escape( $entry['msgid'] ) . "\"\n";

	if ( '' !== $entry['plural'] ) {
		$out .= 'msgid_plural "' . po_escape( $entry['plural'] ) . "\"\n";
		$out .= "msgstr[0] \"\"\n";
		$out .= "msgstr[1] \"\"\n";
	} else {
		$out .= "msgstr \"\"\n";
	}
}

/*
 * A target can be given, which is how the test suite writes a throwaway
 * template and compares it with the committed one. Without it the drift check
 * has nothing to compare against and passes on an empty diff of the file with
 * itself.
 */
$target = $argv[1] ?? ( $root . '/languages/' . $domain . '.pot' );

if ( ! is_dir( dirname( $target ) ) ) {
	mkdir( dirname( $target ), 0755, true );
}

file_put_contents( $target, $out );

printf( "Wrote %s with %d strings\n", $target, count( $entries ) );
