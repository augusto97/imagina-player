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
function get_transient( $k ) { if ( isset( $GLOBALS['counts'] ) ) { $GLOBALS['counts']['transient']++; } return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function delete_transient( $k ) { return true; }
function wp_cache_get( $k, $g = '' ) { return false; }
function wp_cache_set( $k, $v, $g = '', $e = 0 ) { return true; }
function wp_cache_delete( $k, $g = '' ) { return true; }
function wp_schedule_single_event( $t, $h, $a = array() ) { return true; }
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_attr__( $text, $domain = '' ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES ); }
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
function esc_html_e( $text, $domain = '' ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = '' ) { echo esc_attr( $text ); }
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
function wp_get_attachment_url( $id ) { return false; }
function wp_get_attachment_metadata( $id ) { return false; }
function wp_get_attachment_image_url( $id, $size = '' ) { return false; }
function get_post_mime_type( $id ) { return $GLOBALS['stub_posts'][ $id ]['mime'] ?? ''; }
function get_the_title( $id ) { return ''; }
function get_post_meta( $id, $key, $single = false ) { if ( isset( $GLOBALS['counts'] ) ) { $GLOBALS['counts']['get_post_meta']++; } return $GLOBALS['stub_meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['stub_meta'][ $id ][ $key ] = $value; return true; }
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
	public function get_row( $query, $output = null ) { if ( isset( $GLOBALS['counts'] ) ) { $GLOBALS['counts']['db_query']++; } return null; }
	public function replace( $table, $data, $format = null ) { return 1; }
	public function delete( $table, $where, $format = null ) { return 1; }
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

	$limit = (int) ( $args['posts_per_page'] ?? -1 );

	return $limit > 0 ? array_slice( $found, 0, $limit ) : $found;
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

function wp_remote_retrieve_response_code( $response ) { return is_array( $response ) ? (int) ( $response['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $response ) { return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : ''; }
