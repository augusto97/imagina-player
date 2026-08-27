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

$GLOBALS['stub_options'] = array();
$GLOBALS['stub_actions'] = array();
$GLOBALS['stub_filters'] = array();

function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['stub_actions'][ $hook ][] = $callback; }
function do_action( $hook, ...$args ) {}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['stub_filters'][ $hook ][] = $callback; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function add_shortcode( $tag, $callback ) {}
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$out = array();
	foreach ( $pairs as $name => $default ) { $out[ $name ] = array_key_exists( $name, (array) $atts ) ? $atts[ $name ] : $default; }
	return $out;
}
function get_option( $name, $default = false ) { return $GLOBALS['stub_options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['stub_options'][ $name ] = $value; return true; }
function add_option( $name, $value, $d = '', $autoload = 'yes' ) { $GLOBALS['stub_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['stub_options'][ $name ] ); return true; }
function get_transient( $k ) { return false; }
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
function get_post_mime_type( $id ) { return ''; }
function get_the_title( $id ) { return ''; }
function get_post_meta( $id, $key, $single = false ) { return ''; }
function update_post_meta( $id, $key, $value ) { return true; }
function delete_post_meta( $id, $key ) { return true; }
function get_post_type( $id ) { return ''; }
function get_attached_file( $id ) { return false; }
function current_user_can( $cap ) { return true; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( $path, '/' ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/imagina-player/'; }
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
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
function wp_safe_redirect( $l ) {}
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
	public function get_var( $query ) { return null; }
	public function get_row( $query, $output = null ) { return null; }
	public function replace( $table, $data, $format = null ) { return 1; }
	public function delete( $table, $where, $format = null ) { return 1; }
	public function esc_like( $text ) { return $text; }
}

$GLOBALS['wpdb'] = new Stub_WPDB();
