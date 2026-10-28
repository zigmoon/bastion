<?php
/**
 * Minimal WordPress stubs used by the standalone test suites.
 *
 * The point of this file is to let the plugin's logic run on plain PHP: no
 * database, no web server, no WordPress installation. Where the real function
 * has a behaviour worth copying — slashing, transients, filters — the stub
 * models it faithfully, because a test that passes against a stub that lies is
 * worse than no test.
 *
 * @package Bastion
 */

define( 'ABSPATH', __DIR__ . '/site/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'BASTION_VERSION', '1.0.0' );
define( 'BASTION_BASENAME', 'Bastion/bastion.php' );
define( 'BASTION_URL', 'https://example.test/wp-content/plugins/Bastion/' );
define( 'BASTION_DIR', dirname( __DIR__ ) . '/Bastion/' );

if ( ! is_dir( ABSPATH ) ) {
	mkdir( ABSPATH, 0o777, true );
}

$GLOBALS['bas_options']    = array();
$GLOBALS['bas_transients'] = array();
$GLOBALS['bas_actions']    = array();
$GLOBALS['bas_filters']    = array();
$GLOBALS['bas_http']       = array();
$GLOBALS['bas_http_calls'] = array();
$GLOBALS['bas_user']       = 1;
$GLOBALS['bas_can']        = array( 'manage_options' => true );
$GLOBALS['bas_home']       = 'https://example.test';
$GLOBALS['bas_siteurl']    = 'https://example.test';
$GLOBALS['bas_plugins']    = array();
$GLOBALS['bas_die']        = null;
$GLOBALS['bas_redirect']   = null;
$GLOBALS['bas_headers']    = array();

/* -------------------------------------------------------------------------
 * Options and transients.
 * ---------------------------------------------------------------------- */

function get_option( $name, $default = false ) {
	if ( 'siteurl' === $name && isset( $GLOBALS['bas_siteurl'] ) ) {
		return $GLOBALS['bas_siteurl'];
	}

	return array_key_exists( $name, $GLOBALS['bas_options'] ) ? $GLOBALS['bas_options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['bas_options'][ $name ] = $value;

	return true;
}

function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( ! array_key_exists( $name, $GLOBALS['bas_options'] ) ) {
		$GLOBALS['bas_options'][ $name ] = $value;
	}

	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['bas_options'][ $name ] );

	return true;
}

function get_transient( $name ) {
	return array_key_exists( $name, $GLOBALS['bas_transients'] ) ? $GLOBALS['bas_transients'][ $name ] : false;
}

function set_transient( $name, $value, $ttl = 0 ) {
	$GLOBALS['bas_transients'][ $name ] = $value;

	return true;
}

function delete_transient( $name ) {
	unset( $GLOBALS['bas_transients'][ $name ] );

	return true;
}

function get_site_transient( $name ) {
	return get_transient( 'site_' . $name );
}

function set_site_transient( $name, $value, $ttl = 0 ) {
	return set_transient( 'site_' . $name, $value, $ttl );
}

function delete_site_transient( $name ) {
	return delete_transient( 'site_' . $name );
}

/* -------------------------------------------------------------------------
 * HTTP.
 * ---------------------------------------------------------------------- */

class WP_Error {
	private string $code;
	private string $message;

	public function __construct( string $code = '', string $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_remote_request( $url, $args = array() ) {
	$GLOBALS['bas_http_calls'][] = array( 'url' => $url, 'args' => $args );

	if ( isset( $GLOBALS['bas_http'][ $url ] ) ) {
		return $GLOBALS['bas_http'][ $url ];
	}

	return new WP_Error( 'http_request_failed', 'no stub response for ' . $url );
}

function wp_remote_get( $url, $args = array() ) {
	return wp_remote_request( $url, $args );
}

function wp_remote_head( $url, $args = array() ) {
	$args['method'] = 'HEAD';

	return wp_remote_request( $url, $args );
}

function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
}

function wp_remote_retrieve_headers( $response ) {
	return is_array( $response ) ? ( $response['headers'] ?? array() ) : array();
}

function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
}

function wp_http_validate_url( $url ) {
	$scheme = wp_parse_url( (string) $url, PHP_URL_SCHEME );

	return in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) ? $url : false;
}

/* -------------------------------------------------------------------------
 * URLs and strings.
 * ---------------------------------------------------------------------- */

function home_url( $path = '' ) {
	return rtrim( $GLOBALS['bas_home'], '/' ) . ( '' === $path ? '' : '/' . ltrim( (string) $path, '/' ) );
}

function admin_url( $path = '' ) {
	return home_url( '/wp-admin/' . ltrim( (string) $path, '/' ) );
}

function plugin_dir_path( $file ) {
	return rtrim( dirname( (string) $file ), '/\\' ) . '/';
}

function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/' . basename( dirname( (string) $file ) ) . '/';
}

function plugin_basename( $file ) {
	return basename( dirname( (string) $file ) ) . '/' . basename( (string) $file );
}

function set_url_scheme( $url, $scheme = null ) {
	$url = (string) $url;

	if ( null === $scheme ) {
		$scheme = is_ssl() ? 'https' : 'http';
	}

	return (string) preg_replace( '#^https?://#i', $scheme . '://', $url );
}

function is_ssl() {
	return ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] );
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, '/\\' );
}

function wp_parse_url( $url, $component = -1 ) {
	return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component );
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function wp_date( $format, $timestamp = null ) {
	return gmdate( (string) $format, (int) ( $timestamp ?? time() ) );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function wp_slash( $value ) {
	return is_string( $value ) ? addslashes( $value ) : $value;
}

function sanitize_text_field( $value ) {
	return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
}

function sanitize_key( $value ) {
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function sanitize_email( $value ) {
	$value = trim( (string) $value );

	return (bool) filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : '';
}

function is_email( $value ) {
	return (bool) filter_var( (string) $value, FILTER_VALIDATE_EMAIL );
}

function esc_url_raw( $value ) {
	return (string) $value;
}

function esc_url( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES );
}

function esc_attr( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES );
}

function esc_html( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES );
}

function esc_textarea( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES );
}

function wp_kses_post( $value ) {
	return (string) $value;
}

function wp_strip_all_tags( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	return substr( str_repeat( 'abcdefghijklmnopqrstuvwxyz', 3 ), 0, $length );
}

/* -------------------------------------------------------------------------
 * Translation.
 * ---------------------------------------------------------------------- */

function __( $text, $domain = null ) {
	return $text;
}

function esc_html__( $text, $domain = null ) {
	return esc_html( $text );
}

function esc_attr__( $text, $domain = null ) {
	return esc_attr( $text );
}

function esc_html_e( $text, $domain = null ) {
	echo esc_html( $text );
}

function esc_attr_e( $text, $domain = null ) {
	echo esc_attr( $text );
}

function _e( $text, $domain = null ) {
	echo $text;
}

function _x( $text, $context, $domain = null ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}

/* -------------------------------------------------------------------------
 * Hooks.
 * ---------------------------------------------------------------------- */

function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['bas_actions'][ $tag ][ $priority ][] = $callback;

	return true;
}

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['bas_filters'][ $tag ][ $priority ][] = $callback;

	return true;
}

function remove_action( $tag, $callback, $priority = 10 ) {
	if ( ! isset( $GLOBALS['bas_actions'][ $tag ][ $priority ] ) ) {
		return false;
	}

	$GLOBALS['bas_actions'][ $tag ][ $priority ] = array_values(
		array_filter(
			$GLOBALS['bas_actions'][ $tag ][ $priority ],
			static fn( $existing ) => $existing !== $callback
		)
	);

	return true;
}

function remove_filter( $tag, $callback, $priority = 10 ) {
	return remove_action( $tag, $callback, $priority );
}

function has_action( $tag, $callback = false ) {
	if ( ! isset( $GLOBALS['bas_actions'][ $tag ] ) ) {
		return false;
	}

	if ( false === $callback ) {
		return true;
	}

	foreach ( $GLOBALS['bas_actions'][ $tag ] as $callbacks ) {
		if ( in_array( $callback, $callbacks, true ) ) {
			return true;
		}
	}

	return false;
}

function has_filter( $tag, $callback = false ) {
	if ( ! isset( $GLOBALS['bas_filters'][ $tag ] ) ) {
		return false;
	}

	if ( false === $callback ) {
		return true;
	}

	foreach ( $GLOBALS['bas_filters'][ $tag ] as $callbacks ) {
		if ( in_array( $callback, $callbacks, true ) ) {
			return true;
		}
	}

	return false;
}

function apply_filters( $tag, $value, ...$args ) {
	if ( ! isset( $GLOBALS['bas_filters'][ $tag ] ) ) {
		return $value;
	}

	$priorities = $GLOBALS['bas_filters'][ $tag ];
	ksort( $priorities );

	foreach ( $priorities as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
		}
	}

	return $value;
}

function do_action( $tag, ...$args ) {
	if ( ! isset( $GLOBALS['bas_actions'][ $tag ] ) ) {
		return;
	}

	$priorities = $GLOBALS['bas_actions'][ $tag ];
	ksort( $priorities );

	foreach ( $priorities as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			call_user_func_array( $callback, $args );
		}
	}
}

function remove_all_filters( $tag ) {
	unset( $GLOBALS['bas_filters'][ $tag ] );
}

function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) {
	return true;
}

function register_activation_hook( $file, $callback ) {
	$GLOBALS['bas_activation'] = $callback;
}

function register_deactivation_hook( $file, $callback ) {
	$GLOBALS['bas_deactivation'] = $callback;
}

/* -------------------------------------------------------------------------
 * Capabilities and context.
 * ---------------------------------------------------------------------- */

function is_admin() {
	return true;
}

function get_current_user_id() {
	return (int) $GLOBALS['bas_user'];
}

function current_user_can( $capability, $object_id = null ) {
	return (bool) ( $GLOBALS['bas_can'][ $capability ] ?? false );
}

function get_current_screen() {
	return (object) array( 'id' => 'settings_page_bastion' );
}

function get_sites( $args = array() ) {
	return array( 1 );
}

function is_multisite() {
	return false;
}

function switch_to_blog( $id ) {
	return true;
}

function restore_current_blog() {
	return true;
}

/* -------------------------------------------------------------------------
 * Output helpers.
 * ---------------------------------------------------------------------- */

function status_header( $code ) {
	$GLOBALS['bas_status'] = (int) $code;
}

function nocache_headers() {
	$GLOBALS['bas_nocache'] = true;
}

function wp_die( $message = '', $title = '', $args = array() ) {
	$GLOBALS['bas_die'] = array( 'message' => $message, 'title' => $title, 'args' => $args );

	throw new RuntimeException( 'wp_die' );
}

function wp_safe_redirect( $url, $status = 302 ) {
	$GLOBALS['bas_redirect'] = $url;

	throw new RuntimeException( 'redirect' );
}

function wp_redirect( $url, $status = 302 ) {
	$GLOBALS['bas_redirect'] = $url;
	$GLOBALS['bas_redirect_status'] = $status;

	throw new RuntimeException( 'redirect' );
}

function wp_nonce_url( $actionurl, $action = -1, $name = '_wpnonce' ) {
	return $actionurl . ( str_contains( $actionurl, '?' ) ? '&' : '?' ) . $name . '=nonce';
}

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce">';

	if ( $echo ) {
		echo $field;
	}

	return $field;
}

function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	return true;
}

function checked( $checked, $current = true, $display = true ) {
	$result = (bool) $checked === (bool) $current ? ' checked="checked"' : '';

	if ( $display ) {
		echo $result;
	}

	return $result;
}

function selected( $selected, $current = true, $display = true ) {
	$result = (string) $selected === (string) $current ? ' selected="selected"' : '';

	if ( $display ) {
		echo $result;
	}

	return $result;
}

function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null ) {
	echo '<button type="submit" class="button">' . esc_html( (string) $text ) . '</button>';
}

function add_query_arg( ...$args ) {
	if ( is_array( $args[0] ) ) {
		$params = $args[0];
		$url    = $args[1] ?? '';
	} else {
		$params = array( (string) $args[0] => $args[1] ?? '' );
		$url    = $args[2] ?? '';
	}

	$separator = str_contains( (string) $url, '?' ) ? '&' : '?';

	return (string) $url . $separator . http_build_query( $params );
}

function get_bloginfo( $show = '' ) {
	return '7.1.3';
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_enqueue_style( ...$args ) {
	$GLOBALS['bas_styles'][] = $args;
}

function wp_enqueue_script( ...$args ) {
	$GLOBALS['bas_scripts'][] = $args;
}

function add_options_page( ...$args ) {
	$GLOBALS['bas_menu'] = $args;
}

/* -------------------------------------------------------------------------
 * Assertions, shared by both suites.
 * ---------------------------------------------------------------------- */

/**
 * Counts one assertion.
 *
 * @param bool   $condition Result.
 * @param string $message   What it proves.
 */
function bas_assert( bool $condition, string $message ): void {
	if ( $condition ) {
		++$GLOBALS['bas_checked'];

		return;
	}

	++$GLOBALS['bas_failed'];
	echo "FAIL: {$message}\n";
}

/**
 * Counts one strict comparison.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  What it proves.
 */
function bas_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected === $actual ) {
		++$GLOBALS['bas_checked'];

		return;
	}

	++$GLOBALS['bas_failed'];
	echo "FAIL: {$message}\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}

$GLOBALS['bas_checked'] = 0;
$GLOBALS['bas_failed']  = 0;

/* -------------------------------------------------------------------------
 * Reset helper, used between test blocks.
 * ---------------------------------------------------------------------- */

function bas_reset_state(): void {	$GLOBALS['bas_options']    = array();
	$GLOBALS['bas_transients'] = array();
	$GLOBALS['bas_http']       = array();
	$GLOBALS['bas_http_calls'] = array();
	$GLOBALS['bas_die']        = null;
	$GLOBALS['bas_redirect']   = null;
	$GLOBALS['bas_headers']    = array();
}
