<?php
/**
 * Bring a translation up to date with the template.
 *
 * `msgmerge` is not on this machine, and this is the piece of the pot/po/mo
 * cycle that is easy to leave out and expensive to leave out: without it,
 * adding one string to a panel means hand-editing every `.po`, and the usual
 * outcome is that nobody does, so the new string is quietly English forever.
 *
 * Existing translations are kept, new strings arrive empty, and strings the
 * source no longer says are dropped rather than left behind as clutter. The
 * comments, references and line numbers all come from the template, so they
 * are right for the code as it stands now.
 *
 *   php bin/make-pot.php
 *   php bin/merge-po.php languages/imagina-player-es_ES.po
 *   # fill in the empty msgstrs
 *   php bin/make-mo.php languages/imagina-player-es_ES.po
 *
 * Usage: php bin/merge-po.php <file.po> [template.pot]
 */

declare( strict_types = 1 );

$target   = $argv[1] ?? '';
$root     = dirname( __DIR__ );
$template = $argv[2] ?? ( $root . '/languages/imagina-player.pot' );

if ( '' === $target ) {
	fwrite( STDERR, "Usage: php bin/merge-po.php <file.po> [template.pot]\n" );
	exit( 1 );
}

if ( ! is_readable( $template ) ) {
	fwrite( STDERR, "No template at {$template} — run bin/make-pot.php first.\n" );
	exit( 1 );
}

/**
 * The translations already in a .po, keyed by msgid.
 *
 * Only the msgstrs are read. Everything else about the entry — its comments,
 * its references, whether it is a plural at all — is taken from the template,
 * because the template is what matches the code.
 *
 * @return array<string, array<int, string>>
 */
function existing_translations( string $file ): array {
	if ( ! is_readable( $file ) ) {
		return array();
	}

	$out     = array();
	$msgid   = null;
	$msgstr  = array();
	$field   = '';

	$flush = static function () use ( &$out, &$msgid, &$msgstr ): void {
		if ( null !== $msgid && '' !== $msgid ) {
			$kept = array_filter( $msgstr, static fn( $s ) => '' !== $s );

			if ( array() !== $kept ) {
				$out[ $msgid ] = $msgstr;
			}
		}

		$msgid  = null;
		$msgstr = array();
	};

	foreach ( file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
		$line = trim( $line );

		if ( '' === $line || '#' === ( $line[0] ?? '' ) ) {
			if ( '' === $line ) {
				$flush();
				$field = '';
			}
			continue;
		}

		if ( preg_match( '/^msgid\s+"(.*)"$/', $line, $m ) ) {
			$flush();
			$msgid = $m[1];
			$field = 'msgid';
			continue;
		}

		if ( preg_match( '/^msgid_plural\s+"(.*)"$/', $line, $m ) ) {
			$field = 'plural';
			continue;
		}

		if ( preg_match( '/^msgstr(?:\[(\d+)\])?\s+"(.*)"$/', $line, $m ) ) {
			$index            = isset( $m[1] ) && '' !== $m[1] ? (int) $m[1] : 0;
			$msgstr[ $index ] = $m[2];
			$field            = 'msgstr' . $index;
			continue;
		}

		if ( preg_match( '/^"(.*)"$/', $line, $m ) && str_starts_with( $field, 'msgstr' ) ) {
			$index            = (int) substr( $field, 6 );
			$msgstr[ $index ] = ( $msgstr[ $index ] ?? '' ) . $m[1];
		}
	}

	$flush();

	return $out;
}

$known = existing_translations( $target );

// The header of the file being updated, if it has one: it carries the
// language, the plural rule and the translator, none of which the template has.
$header = '';

if ( is_readable( $target ) ) {
	$current = (string) file_get_contents( $target );

	if ( preg_match( '/^msgid ""\R((?:msgstr ""\R)(?:".*"\R)*)/m', $current, $m ) ) {
		$header = $m[1];
	}
}

$lines   = preg_split( '/\R/', (string) file_get_contents( $template ) );
$out     = array();
$msgid   = null;
$plural  = false;
$new     = 0;
$kept    = 0;
$in_head = true;
$swallow = false;

foreach ( $lines as $line ) {
	if ( preg_match( '/^msgid "(.*)"$/', $line, $m ) ) {
		$msgid  = $m[1];
		$plural = false;
		$out[]  = $line;
		continue;
	}

	if ( preg_match( '/^msgid_plural "(.*)"$/', $line, $m ) ) {
		$plural = true;
		$out[]  = $line;
		continue;
	}

	if ( 'msgstr ""' === $line && '' === (string) $msgid && $in_head ) {
		$in_head = false;

		if ( '' !== $header ) {
			/*
			 * The translation's own header wins: it carries the language, the
			 * plural rule and the translator, none of which the template knows.
			 * The template's header lines that follow are swallowed rather than
			 * appended, or the file ends up with two of everything.
			 */
			$out[]   = rtrim( $header, "\r\n" );
			$swallow = true;
			continue;
		}

		$out[] = $line;
		continue;
	}

	if ( $swallow ) {
		if ( preg_match( '/^".*"$/', $line ) ) {
			continue;
		}

		$swallow = false;
	}

	if ( preg_match( '/^msgstr(?:\[(\d+)\])? ""$/', $line, $m ) ) {
		$index = isset( $m[1] ) && '' !== $m[1] ? (int) $m[1] : 0;
		$have  = $known[ (string) $msgid ][ $index ] ?? '';

		if ( '' === $have ) {
			$new++;
			$out[] = $line;
			continue;
		}

		$kept++;
		$out[] = $plural
			? 'msgstr[' . $index . '] "' . $have . '"'
			: 'msgstr "' . $have . '"';
		continue;
	}

	$out[] = $line;
}

$obsolete = array_diff( array_keys( $known ), array_map(
	static fn( $l ) => preg_match( '/^msgid "(.*)"$/', $l, $m ) ? $m[1] : "\0",
	$lines
) );

file_put_contents( $target, implode( "\n", $out ) );

printf(
	"Merged %s — %d kept, %d new and untranslated, %d dropped\n",
	basename( $target ),
	$kept,
	$new,
	count( $obsolete )
);

if ( $new > 0 ) {
	echo "Fill in the empty msgstr entries, then run bin/make-mo.php.\n";
}
