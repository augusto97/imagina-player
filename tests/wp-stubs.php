<?php
/**
 * Just enough WordPress to render a player outside of WordPress, so the markup
 * and the option plumbing can be smoke-tested from the CLI.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'MONTH_IN_SECONDS', 2592000 );
define( 'MB_IN_BYTES', 1048576 );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['stub_options'] = array();
$GLOBALS['stub_actions'] = array();
$GLOBALS['stub_filters'] = array();

function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['stub_actions'][ $hook ][] = $callback; }
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['stub_actions'][ $hook ] ?? array() as $callback ) {
		$callback( ...$args );
	}
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['stub_filters'][ $hook ][ $priority ][] = $callback; }
function remove_all_filters( $hook ) { unset( $GLOBALS['stub_filters'][ $hook ] ); }
function remove_filter( $hook, $callback, $priority = 10 ) {
	foreach ( $GLOBALS['stub_filters'][ $hook ][ $priority ] ?? array() as $index => $registered ) {
		if ( $registered === $callback ) {
			unset( $GLOBALS['stub_filters'][ $hook ][ $priority ][ $index ] );
			return true;
		}
	}
	return false;
}
/**
 * Real dispatch, not a pass-through: the plugin's own filters are part of what
 * the tests are checking.
 */
function apply_filters( $hook, $value, ...$args ) {
	if ( empty( $GLOBALS['stub_filters'][ $hook ] ) ) {
		return $value;
	}

	$by_priority = $GLOBALS['stub_filters'][ $hook ];
	ksort( $by_priority );

	foreach ( $by_priority as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$value = $callback( $value, ...$args );
		}
	}

	return $value;
}
function add_shortcode( $tag, $callback ) {}
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$out = array();
	foreach ( $pairs as $name => $default ) { $out[ $name ] = array_key_exists( $name, (array) $atts ) ? $atts[ $name ] : $default; }
	return $out;
}
function get_option( $name, $default = false ) { if ( isset( $GLOBALS['counts'] ) ) { $GLOBALS['counts']['get_option']++; } return $GLOBALS['stub_options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['stub_options'][ $name ] = $value; return true; }
function add_option( $name, $value, $d = '', $autoload = 'yes' ) { $GLOBALS['stub_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['stub_options'][ $name ] ); return true; }
// Real, in-memory: a rate limit that is never remembered cannot be tested.
$GLOBALS['stub_transients'] = array();
function get_transient( $k ) {
	if ( isset( $GLOBALS['counts'] ) ) { $GLOBALS['counts']['transient']++; }
	$row = $GLOBALS['stub_transients'][ $k ] ?? null;
	if ( null === $row ) { return false; }
	if ( 0 !== $row['expires'] && $row['expires'] < time() ) { unset( $GLOBALS['stub_transients'][ $k ] ); return false; }
	return $row['value'];
}
function set_transient( $k, $v, $t = 0 ) {
	$GLOBALS['stub_transients'][ $k ] = array( 'value' => $v, 'expires' => $t > 0 ? time() + $t : 0 );
	return true;
}
function delete_transient( $k ) { unset( $GLOBALS['stub_transients'][ $k ] ); return true; }
function wp_cache_get( $k, $g = '' ) { return false; }
function wp_cache_set( $k, $v, $g = '', $e = 0 ) { return true; }
function wp_cache_delete( $k, $g = '' ) { return true; }
function wp_schedule_single_event( $t, $h, $a = array() ) { return true; }
/*
 * Translation is normally the identity, because almost every test is about
 * what the code does rather than what language it says it in. A test that is
 * about the language sets `$GLOBALS['imgp_catalogue']` to `original =>
 * translation` first, and then these behave like a loaded text domain — which
 * is the only way to prove a rendered page actually comes back in Spanish
 * rather than merely that a .mo file parses.
 */
function imgp_translate( $text ) {
	return $GLOBALS['imgp_catalogue'][ $text ] ?? $text;
}
function __( $text, $domain = '' ) { return imgp_translate( $text ); }
function esc_html__( $text, $domain = '' ) { return htmlspecialchars( imgp_translate( $text ), ENT_QUOTES ); }
function esc_attr__( $text, $domain = '' ) { return htmlspecialchars( imgp_translate( $text ), ENT_QUOTES ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
/*
 * Mirrors WordPress: a scheme that is not on the allowed list makes the whole
 * URL empty, and only then is what is left escaped. This stub used to be
 * `htmlspecialchars` alone, which escapes quotes and passes `javascript:`
 * straight through — so every test that leaned on `esc_url` for safety was
 * testing nothing, and passing.
 */
function esc_url( $url, $protocols = null ) {
	return htmlspecialchars( esc_url_raw( (string) $url, $protocols ), ENT_QUOTES );
}
function esc_url_raw( $url, $protocols = null ) {
	$url = (string) $url;
	if ( '' === $url ) { return ''; }
	// Reject anything carrying a scheme that is not allowed, mirroring esc_url().
	if ( preg_match( '#^([a-z][a-z0-9+.-]*):#i', $url, $m ) ) {
		$allowed = $protocols ?: array( 'http', 'https' );
		return in_array( strtolower( $m[1] ), $allowed, true ) ? $url : '';
	}
	// Scheme-less: relative paths are kept, as WordPress keeps them.
	return $url;
}
function esc_js( $text ) { return $text; }
function esc_html_e( $text, $domain = '' ) { echo esc_html( imgp_translate( $text ) ); }
function esc_attr_e( $text, $domain = '' ) { echo esc_attr( imgp_translate( $text ) ); }
function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function sanitize_hex_color( $color ) { return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $color ) ? $color : null; }
function rest_sanitize_boolean( $value ) { return filter_var( $value, FILTER_VALIDATE_BOOLEAN ); }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function wp_rand( $min = 0, $max = 0 ) { return random_int( $min, $max ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_check_filetype( $filename, $mimes = null ) {
	$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	$map = array( 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'mp4' => 'video/mp4' );
	return array( 'ext' => $ext, 'type' => $map[ $ext ] ?? '' );
}
function wp_get_attachment_url( $id ) { return $GLOBALS['stub_posts'][ $id ]['url'] ?? false; }
function wp_get_attachment_metadata( $id ) { return $GLOBALS['stub_attachment_meta'][ $id ] ?? false; }
function wp_get_attachment_image_url( $id, $size = '' ) { return $GLOBALS['stub_covers'][ $id ] ?? false; }
function get_post_mime_type( $id ) { return $GLOBALS['stub_posts'][ $id ]['mime'] ?? ''; }
function get_the_title( $id ) { return ''; }
function get_post_meta( $id, $key, $single = false ) { if ( isset( $GLOBALS['counts'] ) ) { $GLOBALS['counts']['get_post_meta']++; } return $GLOBALS['stub_meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) {
	// False for an unchanged value, as core does — a stub that always said
	// true hid a 500 on the second measurement of the same file.
	if ( isset( $GLOBALS['stub_meta'][ $id ][ $key ] ) && $GLOBALS['stub_meta'][ $id ][ $key ] === $value ) {
		return false;
	}

	$GLOBALS['stub_meta'][ $id ][ $key ] = $value;

	return true;
}
function delete_post_meta( $id, $key ) { unset( $GLOBALS['stub_meta'][ $id ][ $key ] ); return true; }
function get_post_type( $id ) { return $GLOBALS['stub_posts'][ $id ]['type'] ?? ''; }
function get_attached_file( $id ) { return $GLOBALS['stub_posts'][ $id ]['file'] ?? false; }
function current_user_can( $cap ) { return true; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( $path, '/' ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/imagina-player/'; }
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }

/*
 * Records the call rather than reading the catalogue. What the tests need to
 * know is that the domain is loaded at all and where it is pointed — the .mo
 * itself is read back byte for byte in test-translations.php.
 */
$GLOBALS['imgp_textdomains'] = array();
function load_plugin_textdomain( $domain, $deprecated = false, $path = '' ) {
	$GLOBALS['imgp_textdomains'][ $domain ] = $path;
	return true;
}
function add_menu_page( ...$a ) { return 'toplevel_page_' . ( $a[3] ?? '' ); }
function wp_add_inline_style( ...$a ) {}
function wp_create_nonce( $action = -1 ) { return 'stub-nonce'; }
function register_activation_hook( $file, $cb ) {}
function register_deactivation_hook( $file, $cb ) {}
function wp_salt( $scheme = 'auth' ) { return 'stub-salt-value'; }
function wp_register_script( ...$a ) {}
function wp_register_style( ...$a ) {}
function wp_enqueue_script( ...$a ) {}
function wp_enqueue_style( ...$a ) {}
function wp_add_inline_script( ...$a ) {}
function wp_set_script_translations( ...$a ) {}
function register_block_type( ...$a ) {}
function get_block_wrapper_attributes( $extra = array() ) { return 'class="wp-block-imagina-audio-player ' . ( $extra['class'] ?? '' ) . '"'; }
function add_options_page( ...$a ) {}
function checked( $a, $b = true, $echo = true ) { $r = ( $a == $b ) ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; }
function selected( $a, $b = true, $echo = true ) { $r = ( $a == $b ) ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; }
function submit_button( ...$a ) {}
function wp_nonce_field( ...$a ) {}
function check_admin_referer( ...$a ) { return true; }
function wp_unslash( $v ) { return $v; }
function wp_safe_redirect( $l, $status = 302 ) { $GLOBALS['stub_redirect'] = $l; }
function wp_die( $m = '' ) { die( $m ); }
function flush_rewrite_rules() {}
function is_admin() { return false; }

/**
 * Minimal $wpdb: enough for the peaks table probe to answer "no table here".
 */
class Stub_WPDB {
	public $prefix = 'wp_';
	public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
	public function prepare( $query, ...$args ) { return vsprintf( str_replace( array( '%s', '%d', '%f' ), array( "'%s'", '%d', '%f' ), $query ), $args ); }
	public function get_var( $query ) { if ( isset( $GLOBALS['counts'] ) ) { $GLOBALS['counts']['db_query']++; } return $GLOBALS['stub_table_exists'] ?? null; }
	/**
	 * Remembers what was written, and hands it back.
	 *
	 * It used to answer every read with null and throw every write away, which
	 * meant no test could ever store something and read it back — and that is
	 * exactly where the bug was: the reader was filling in a waveform's format
	 * version from a constant instead of from the row, so every stored waveform
	 * claimed to have been measured whichever way was current. A round trip
	 * would have caught it in a line. Nothing could do a round trip.
	 */
	public function get_row( $query, $output = null ) {
		if ( isset( $GLOBALS['counts'] ) ) { $GLOBALS['counts']['db_query']++; }

		if ( preg_match( "/peaks_key = '([^']*)'/", (string) $query, $m ) ) {
			return $GLOBALS['stub_table_rows'][ $m[1] ] ?? null;
		}

		return null;
	}
	public function replace( $table, $data, $format = null ) {
		if ( isset( $data['peaks_key'] ) ) {
			$GLOBALS['stub_table_rows'][ $data['peaks_key'] ] = $data;
		}

		return 1;
	}
	/**
	 * Records rather than executes. Enough to assert that a write was attempted
	 * and that its SQL was built through prepare().
	 */
	public function query( $sql ) {
		$GLOBALS['stub_queries'][] = $sql;
		return $GLOBALS['stub_query_result'] ?? 1;
	}
	public function get_results( $query, $output = null ) { return $GLOBALS['stub_rows'] ?? array(); }
	public function get_col( $query ) { return $GLOBALS['stub_cols'] ?? array(); }
	public function delete( $table, $where, $format = null ) {
		unset( $GLOBALS['stub_table_rows'][ $where['peaks_key'] ?? '' ] );

		return 1;
	}
	public function esc_like( $text ) { return $text; }
	// When $GLOBALS['stub_table_exists'] is set, SHOW TABLES reports the table.
	public function show_tables_result() { return $GLOBALS['stub_table_exists'] ?? null; }
}

$GLOBALS['wpdb'] = new Stub_WPDB();

// --- Stubs added for the protected-media tests -----------------------------

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function trailingslashit( $string ) { return rtrim( (string) $string, '/\\' ) . '/'; }
function untrailingslashit( $string ) { return rtrim( (string) $string, '/\\' ); }
function wp_get_upload_dir() {
	$base = $GLOBALS['stub_uploads_dir'] ?? sys_get_temp_dir() . '/imgp-uploads';
	return array(
		'basedir' => $base,
		'baseurl' => $GLOBALS['stub_uploads_url'] ?? 'https://example.test/wp-content/uploads',
	);
}
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0777, true ); }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function add_query_arg( $args, $url = '' ) {
	$separator = str_contains( $url, '?' ) ? '&' : '?';
	return $url . $separator . http_build_query( $args );
}
function is_user_logged_in() { return ! empty( $GLOBALS['stub_current_user'] ); }
function get_current_user_id() { return (int) ( $GLOBALS['stub_current_user'] ?? 0 ); }
function wp_update_attachment_metadata( $id, $meta ) { return true; }
function status_header( $code ) { $GLOBALS['stub_status'] = $code; if ( ! headers_sent() ) { http_response_code( $code ); } }
function nocache_headers() {}
function esc_textarea( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }

function wp_generate_password( $length = 12, $special = true ) {
	return substr( bin2hex( random_bytes( (int) ceil( $length / 2 ) + 1 ) ), 0, (int) $length );
}

function wp_list_pluck( $list, $field ) {
	return array_map( static fn( $row ) => is_array( $row ) ? ( $row[ $field ] ?? null ) : ( $row->$field ?? null ), $list );
}

function _n( $single, $plural, $number, $domain = 'default' ) {
	$key = $single . "\0" . $plural;

	if ( isset( $GLOBALS['imgp_catalogue'][ $key ] ) ) {
		$forms = explode( "\0", $GLOBALS['imgp_catalogue'][ $key ] );

		return 1 === (int) $number ? $forms[0] : ( $forms[1] ?? $forms[0] );
	}

	return 1 === (int) $number ? $single : $plural;
}

/**
 * Attachments carrying a given meta value.
 *
 * Only the shape the self-check asks for: a meta_key/meta_value lookup
 * returning ids.
 */
function get_posts( $args = array() ) {
	$key   = $args['meta_key'] ?? '';
	$value = $args['meta_value'] ?? '';
	$found = array();

	foreach ( (array) ( $GLOBALS['stub_meta'] ?? array() ) as $id => $meta ) {
		if ( '' !== $key && (string) ( $meta[ $key ] ?? '' ) === (string) $value ) {
			$found[] = (int) $id;
		}
	}

	/*
	 * Posts with content, for the search that finds tracks played from an
	 * address. Those live nowhere but inside the posts that play them, so a
	 * stub with no posts in it cannot exercise the code that goes looking.
	 */
	if ( '' === $key ) {
		$needle = (string) ( $args['s'] ?? '' );

		foreach ( (array) ( $GLOBALS['stub_posts'] ?? array() ) as $id => $post ) {
			// The search term is honoured, because a stub that returned every
			// post let a search for the wrong word pass.
			if ( '' === $needle || str_contains( (string) ( $post['post_content'] ?? '' ), $needle ) ) {
				$found[] = (int) $id;
			}
		}
	}

	$limit = (int) ( $args['posts_per_page'] ?? -1 );

	return $limit > 0 ? array_slice( $found, 0, $limit ) : $found;
}

/**
 * Enough of WP_Query for the attachment sweep the waveform tools do.
 *
 * Ids out of `$GLOBALS['stub_attachments']`, and nothing else — the filtering
 * that matters moved out of the query and into the loop, which is the part
 * worth testing.
 */
class WP_Query {
	/** @var list<int> */
	public array $posts = array();

	public int $found_posts = 0;

	public function __construct( $args = array() ) {
		$this->posts = array_keys( (array) ( $GLOBALS['stub_attachments'] ?? array() ) );

		$limit = (int) ( $args['posts_per_page'] ?? -1 );

		if ( $limit > 0 ) {
			$this->posts = array_slice( $this->posts, 0, $limit );
		}

		$this->found_posts = count( $this->posts );
	}
}

function _prime_post_caches( $ids, $update_term_cache = true, $update_meta_cache = true ) { $GLOBALS['stub_primed'] = array_map( 'intval', (array) $ids ); }

function get_post_field( $field, $id ) {
	return (string) ( $GLOBALS['stub_posts'][ (int) $id ][ $field ] ?? '' );
}

function has_blocks( $content ) {
	return str_contains( (string) $content, '<!-- wp:' );
}

/**
 * Enough of the block parser for the attributes this plugin reads.
 *
 * Only the shape the real one produces — blockName, attrs, innerBlocks — and
 * only for self-closing and paired block comments, which is what a player and
 * the containers it sits in are written as.
 *
 * @param string $content Post content.
 */
function parse_blocks( $content ) {
	$blocks = array();

	preg_match_all(
		'/<!--\s+wp:([a-z0-9-]+\/[a-z0-9-]+)\s*(\{.*?\})?\s*(\/)?-->/s',
		(string) $content,
		$matches,
		PREG_SET_ORDER
	);

	foreach ( $matches as $match ) {
		$blocks[] = array(
			'blockName'   => $match[1],
			'attrs'       => isset( $match[2] ) && '' !== $match[2]
				? (array) json_decode( $match[2], true )
				: array(),
			'innerBlocks' => array(),
		);
	}

	return $blocks;
}

/**
 * A real HTTP request, because the self-check's whole value is that it makes
 * one. Stubbing it out with a canned answer would test nothing.
 */
function wp_remote_get( $url, $args = array() ) {
	$headers = '';

	foreach ( (array) ( $args['headers'] ?? array() ) as $name => $value ) {
		$headers .= $name . ': ' . $value . "\r\n";
	}

	$context = stream_context_create(
		array(
			'http' => array(
				'method'          => 'GET',
				'header'          => $headers,
				'timeout'         => (float) ( $args['timeout'] ?? 5 ),
				'follow_location' => 0,
				'ignore_errors'   => true,
			),
		)
	);

	$body = @file_get_contents( $url, false, $context );

	if ( false === $body ) {
		return new WP_Error( 'http_request_failed', 'could not connect' );
	}

	$status = 0;

	foreach ( $http_response_header ?? array() as $line ) {
		if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $matches ) ) {
			$status = (int) $matches[1];
		}
	}

	return array( 'code' => $status, 'body' => $body );
}

/*
 * The safe variant. Under a real WordPress this is the one that refuses to
 * fetch a private address; here it is the same fetch, because these tests point
 * it at a local server on purpose and the SSRF rules are exercised by
 * `wp_http_validate_url` in the protection tests instead.
 */
/*
 * The doorway fetches with `stream` and `filename`, so a stub that hands back a
 * body and writes nothing leaves the code under test reading an empty file and
 * concluding the fetch failed — which is a test that exercises the error path
 * whatever the code does. This writes the body where the caller asked for it,
 * the way the real client does.
 */
function wp_safe_remote_get( $url, $args = array() ) {
	$GLOBALS['stub_remote_gets'] = ( $GLOBALS['stub_remote_gets'] ?? 0 ) + 1;

	/*
	 * A different answer each time, for the cases where that is the point.
	 *
	 * The doorway asks again when a request does not come back, because a host
	 * that drops one request in a dozen is ordinary and there was no retry
	 * anywhere. A stub that can only give one answer cannot tell a retry that
	 * works from no retry at all: both look like whatever the single answer
	 * was. So a list is served one entry per call, and the last entry repeats
	 * once the list runs out.
	 */
	$queue = $GLOBALS['stub_remote_queue'] ?? null;

	if ( is_array( $queue ) && array() !== $queue ) {
		$response = 1 === count( $queue ) ? $queue[0] : array_shift( $GLOBALS['stub_remote_queue'] );
	} else {
		$response = $GLOBALS['stub_remote'] ?? null;
	}

	if ( is_array( $response ) && isset( $response['error'] ) ) {
		$response = new WP_Error( 'http_request_failed', (string) $response['error'] );
	}

	if ( null === $response ) {
		return wp_remote_get( $url, $args );
	}

	if ( ! is_wp_error( $response ) && ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
		file_put_contents( $args['filename'], (string) ( $response['body'] ?? '' ) );
	}

	return $response;
}

/*
 * Enough of the HTTP client for the doorway that fetches a file from another
 * domain. A test sets `$GLOBALS['stub_remote']` to what the far end should say
 * — the point being the cases where it says no, since those are what the
 * editor has to explain.
 */
function wp_safe_remote_head( $url, $args = array() ) {
	/*
	 * Separately settable from the GET, because a server can answer the two
	 * differently — a signed URL, or one that treats HEAD as cheap and the
	 * download as metered — and the doorway checks both. A test that could
	 * only set one answer left the second check unexercised.
	 */
	return $GLOBALS['stub_remote_head']
		?? $GLOBALS['stub_remote']
		?? array( 'code' => 200, 'headers' => array( 'content-type' => 'audio/mpeg', 'content-length' => '1024' ) );
}
function wp_remote_retrieve_header( $response, $name ) {
	if ( ! is_array( $response ) ) { return ''; }
	$headers = array_change_key_case( (array) ( $response['headers'] ?? array() ) );
	return (string) ( $headers[ strtolower( $name ) ] ?? '' );
}
function wp_http_validate_url( $url ) {
	$parts = wp_parse_url( (string) $url );
	if ( empty( $parts['scheme'] ) || ! in_array( $parts['scheme'], array( 'http', 'https' ), true ) ) { return false; }
	if ( empty( $parts['host'] ) || 'localhost' === $parts['host'] || str_starts_with( (string) $parts['host'], '127.' ) ) { return false; }
	return $url;
}
function wp_tempnam( $prefix = '' ) { return tempnam( sys_get_temp_dir(), $prefix ); }

/*
 * One request, answered from whatever a test put in `$GLOBALS['stub_remote']`.
 * The diagnostic walks several of these and reports each, so a test can hand it
 * a different answer per step by making that a list.
 */
function wp_safe_remote_request( $url, $args = array() ) {
	$queue = $GLOBALS['stub_remote_steps'] ?? null;

	if ( is_array( $queue ) && array() !== $queue ) {
		$next = array_shift( $GLOBALS['stub_remote_steps'] );
		return $next;
	}

	return $GLOBALS['stub_remote'] ?? array( 'code' => 200, 'headers' => array( 'content-type' => 'audio/mpeg' ), 'body' => '' );
}

function wp_remote_retrieve_response_code( $response ) { return is_array( $response ) ? (int) ( $response['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $response ) { return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : ''; }

function sanitize_html_class( $class, $fallback = '' ) {
	$clean = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class );

	return '' === (string) $clean ? (string) $fallback : (string) $clean;
}

$GLOBALS['stub_queries'] = array();

function sanitize_email( $email ) { return trim( (string) filter_var( (string) $email, FILTER_SANITIZE_EMAIL ) ); }
function is_email( $email ) { return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL ); }

/**
 * Just enough of the REST classes to call a controller method directly.
 */
class WP_REST_Request {
	private array $params;
	private array $headers;

	public function __construct( array $params = array(), array $headers = array() ) {
		$this->params  = $params;
		$this->headers = $headers;
	}

	public function get_param( $name ) { return $this->params[ $name ] ?? null; }
	public function get_json_params() { return $this->params; }
	public function get_params() { return $this->params; }
	public function set_param( $name, $value ) { $this->params[ $name ] = $value; }
	public function get_header( $name ) { return $this->headers[ strtolower( $name ) ] ?? ''; }
}

class WP_REST_Response {
	private $data;
	private int $status;

	public function __construct( $data = null, int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	public function get_data() { return $this->data; }
	public function get_status(): int { return $this->status; }
	/** @var array<string, string> */
	public array $headers = array();
	public function header( $key, $value ) { $this->headers[ $key ] = $value; }
}

class WP_REST_Server {
	const READABLE  = 'GET';
	const CREATABLE = 'POST';
	const EDITABLE  = 'POST, PUT, PATCH';
	const DELETABLE = 'DELETE';
}

function register_rest_route( ...$args ) { return true; }
