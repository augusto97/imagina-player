<?php
/**
 * The columns the code uses, and the columns the table has.
 *
 * A column was added to the waveforms table and nothing ever created it on a
 * site that updates by uploading the plugin — which is how this plugin reaches
 * nearly every site it runs on. The upgrade routine exists for exactly that
 * case, says so in its own comment, and did not touch the tables.
 *
 * So the code asked for a column that was not there. Every read failed, every
 * write failed, and it was invisible: the editor draws the waveform it has just
 * measured, so it looked perfect, while nothing reached the database and every
 * visitor downloaded fifty megabytes to work the same waveform out again on
 * every page view.
 *
 * Two checks, because there are two ways to get this wrong. The columns the
 * code names have to exist in the schema, and the schema has to actually be
 * applied when a site's stored version is behind the code.
 *
 * @package ImaginaPlayer
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
 * Column names out of a `CREATE TABLE` body.
 *
 * @param string $source The file to read it from.
 * @param string $table  Which table's statement.
 * @return list<string>
 */
function columns_in( string $source, string $table ): array {
	if ( ! preg_match( '/CREATE TABLE \{\$table\} \((.*?)\) \{\$collate\}/s', $source, $found ) ) {
		return array();
	}

	$columns = array();

	foreach ( explode( "\n", $found[1] ) as $line ) {
		$line = trim( $line );

		// Keys and constraints are not columns.
		if ( '' === $line || preg_match( '/^(PRIMARY KEY|KEY|UNIQUE|INDEX)\b/i', $line ) ) {
			continue;
		}

		if ( preg_match( '/^([a-z_][a-z0-9_]*)\s/i', $line, $name ) ) {
			$columns[] = $name[1];
		}
	}

	return $columns;
}

echo PHP_EOL . '# Every column the code writes and reads exists' . PHP_EOL;

$repository = (string) file_get_contents( $root . '/src/Peaks/PeaksRepository.php' );
$columns    = columns_in( $repository, 'peaks' );

check(
	'the waveforms table declares its columns where this test can read them',
	count( $columns ) >= 5,
	implode( ', ', $columns )
);

/*
 * What is written. Read out of the array handed to `replace()` rather than
 * listed here, so adding a field to that array without adding it to the table
 * is what fails.
 */
preg_match( '/\$row = array\((.*?)\);/s', $repository, $written );

preg_match_all( "/'([a-z_]+)'\s*=>/", (string) ( $written[1] ?? '' ), $writes );

check(
	'the write names columns this test can read',
	count( $writes[1] ) >= 5,
	implode( ', ', $writes[1] )
);

foreach ( $writes[1] as $column ) {
	check(
		'the table has a column called ' . $column . ', which is written to it',
		in_array( $column, $columns, true ),
		'the write fails outright, and silently — nothing is stored'
	);
}

/*
 * And what is read. A SELECT naming a column that does not exist is not a
 * missing value; it is an error, and the row comes back as nothing at all.
 */
preg_match( "/'SELECT (.*?) FROM '/", $repository, $selected );

foreach ( array_map( 'trim', explode( ',', (string) ( $selected[1] ?? '' ) ) ) as $column ) {
	if ( '' === $column ) {
		continue;
	}

	check(
		'the table has a column called ' . $column . ', which is read from it',
		in_array( $column, $columns, true ),
		'the whole row comes back as nothing, which reads as "no waveform"'
	);
}

echo PHP_EOL . '# And the schema is applied when the code moves ahead of the site' . PHP_EOL;

/*
 * The half that actually bit. The columns agreed with each other perfectly —
 * in the source. On the site the table was still the old one, because the only
 * thing that builds it runs on activation, and updating a plugin by uploading
 * it does not activate anything.
 */
$plugin = (string) file_get_contents( $root . '/src/Plugin.php' );

preg_match( '/public function maybe_upgrade\(\): void \{(.*?)\n\t\}/s', $plugin, $upgrade );

$body = (string) ( $upgrade[1] ?? '' );

check(
	'there is a routine for a site whose stored version is behind the code',
	'' !== $body
);

check(
	'and it builds the waveforms table',
	str_contains( $body, 'PeaksRepository::install_table()' ),
	'without this a schema change never reaches a site updated by uploading the plugin'
);

check(
	'and the leads table, which has the same problem',
	str_contains( $body, 'LeadRepository::install_table()' )
);

/*
 * Order matters. The stored version is what stops this running on every
 * request, so writing it before the tables are built would leave a site that
 * failed halfway marked as up to date for ever.
 */
check(
	'building the tables comes before marking the site up to date',
	strpos( $body, 'install_table' ) < strpos( $body, "update_option( 'imagina_player_version'" ),
	'otherwise a failure halfway through is never retried'
);

echo PHP_EOL . '# A write that fails is not accepted quietly' . PHP_EOL;

/*
 * The reason this was invisible for a release. The editor draws the waveform it
 * has just measured, so a store that failed looked exactly like one that
 * worked, and the only symptom was on the front end — where it showed up as
 * every visitor downloading the file again.
 */
check(
	'a failed write looks at the table before giving up',
	(bool) preg_match( '/if \( false === \$result \) \{\s*self::install_table\(\);/', $repository ),
	'a schema that has drifted is the likeliest reason a write to our own table fails'
);

echo PHP_EOL;
echo 0 === $failures ? 'All schema checks passed.' . PHP_EOL : "{$failures} FAILURE(S)" . PHP_EOL;

exit( 0 === $failures ? 0 : 1 );
