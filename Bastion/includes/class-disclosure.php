<?php
/**
 * Removing the version announcements.
 *
 * @package Bastion
 */

namespace Bastion;

defined( 'ABSPATH' ) || exit;

/**
 * Stops the site from telling the world which WordPress it runs.
 *
 * The readable `<meta name="generator">`, the `?ver=5.x` on every asset and the
 * `X-Powered-By` header are the cheapest reconnaissance an attacker can do:
 * they turn "a WordPress site" into "this exact WordPress, with these exact
 * plugins, and the vulnerabilities of that week".
 *
 * The `?ver=` query string is removed and replaced by a hash of the file, so
 * cache busting still works when a file changes — the usual "just drop ?ver"
 * advice breaks browser caches for a year.
 */
final class Disclosure {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers the hooks.
	 *
	 * The `remove_action()` calls happen here rather than inside a callback: the
	 * links are added by core in `default-filters.php`, which has already run by
	 * the time the plugin boots. They are still gated by the switches, so
	 * `audit` mode really leaves the front end untouched.
	 */
	public function hooks(): void {
		add_action( 'init', array( $this, 'maybe_block_docs' ), 1 );

		add_filter( 'the_generator', array( $this, 'generator' ) );
		add_filter( 'style_loader_src', array( $this, 'asset_src' ), 20 );
		add_filter( 'script_loader_src', array( $this, 'asset_src' ), 20 );
		add_filter( 'wp_headers', array( $this, 'headers' ) );

		if ( $this->settings->active( 'remove_generator' ) ) {
			remove_action( 'wp_head', 'wp_generator' );
		}

		if ( $this->settings->active( 'remove_rsd' ) ) {
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}

		if ( $this->settings->active( 'remove_shortlink' ) ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}
	}

	/**
	 * Empties the generator string.
	 *
	 * @param string $generator Existing value.
	 */
	public function generator( $generator ): string {
		return $this->settings->active( 'remove_generator' ) ? '' : (string) $generator;
	}

	/**
	 * Drops `X-Pingback` from the response.
	 *
	 * @param array<string, string> $headers Response headers.
	 * @return array<string, string>
	 */
	public function headers( $headers ): array {
		$headers = is_array( $headers ) ? $headers : array();

		if ( $this->settings->active( 'remove_pingback' ) ) {
			unset( $headers['X-Pingback'], $headers['x-pingback'] );
		}

		return $headers;
	}

	/**
	 * Replaces `?ver=<version>` with a hash of the file.
	 *
	 * Any local file, whoever enqueued it: a plugin version is as much a
	 * disclosure as the core version, and the hash busts the cache just as
	 * well. A URL that does not resolve to a local file — a CDN, a third
	 * party — is left exactly as it is, because its version may be part of
	 * the cache key on the other side.
	 *
	 * @param string $src Asset URL.
	 */
	public function asset_src( $src ): string {
		if ( ! is_string( $src ) || '' === $src ) {
			return (string) $src;
		}

		if ( ! $this->settings->active( 'strip_version' ) ) {
			return $src;
		}

		$parts = wp_parse_url( $src );

		if ( ! is_array( $parts ) || empty( $parts['query'] ) || empty( $parts['path'] ) ) {
			return $src;
		}

		parse_str( $parts['query'], $query );

		if ( ! isset( $query['ver'] ) ) {
			return $src;
		}

		$stamp = self::file_stamp( $src );

		if ( '' === $stamp ) {
			return $src;
		}

		$query['ver'] = $stamp;
		$rebuilt      = http_build_query( $query );

		return ( $parts['scheme'] ?? '' ) !== ''
			? self::rebuild( $parts, $rebuilt )
			: self::rebuild_relative( $parts, $rebuilt );
	}

	/**
	 * A short cache-busting stamp for a local asset, from its modification time.
	 *
	 * @param string $src Asset URL.
	 */
	public static function file_stamp( string $src ): string {
		$path = wp_parse_url( $src, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		$base = defined( 'ABSPATH' ) ? rtrim( ABSPATH, '/\\' ) : '';

		if ( '' === $base ) {
			return '';
		}

		$file = $base . $path;

		if ( ! is_file( $file ) ) {
			return '';
		}

		$time = filemtime( $file );

		return false === $time ? '' : substr( (string) dechex( $time ), -6 );
	}

	/**
	 * Rebuilds an absolute URL.
	 *
	 * @param array<string, mixed> $parts   Parsed URL.
	 * @param string               $query   New query string.
	 */
	private static function rebuild( array $parts, string $query ): string {
		$url = ( $parts['scheme'] ?? 'https' ) . '://';

		if ( ! empty( $parts['user'] ) ) {
			$url .= $parts['user'] . ( isset( $parts['pass'] ) ? ':' . $parts['pass'] : '' ) . '@';
		}

		$url .= ( $parts['host'] ?? '' );

		if ( ! empty( $parts['port'] ) ) {
			$url .= ':' . $parts['port'];
		}

		$url .= ( $parts['path'] ?? '/' );

		return '' === $query ? $url : $url . '?' . $query;
	}

	/**
	 * Rebuilds a path-only URL (some themes enqueue those).
	 *
	 * @param array<string, mixed> $parts Parsed URL.
	 * @param string               $query New query string.
	 */
	private static function rebuild_relative( array $parts, string $query ): string {
		$url = ( $parts['path'] ?? '/' );

		return '' === $query ? $url : $url . '?' . $query;
	}

	/**
	 * Answers 404 for the files that only serve to announce the version.
	 *
	 * Only the exact file names at the root are touched: `readme.html`,
	 * `license.txt` and `wp-config-sample.php` are never needed by a visitor
	 * and never by WordPress itself.
	 */
	public function maybe_block_docs(): void {
		if ( ! $this->settings->active( 'block_readme' ) ) {
			return;
		}

		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
		$file = strtolower( basename( $path ) );

		if ( ! in_array( $file, array( 'readme.html', 'license.txt', 'wp-config-sample.php' ), true ) ) {
			return;
		}

		$this->settings->log( 'doc_file_blocked', $file );

		status_header( 404 );
		nocache_headers();

		// Say nothing about what the file was.
		wp_die(
			esc_html__( 'Not found.', 'bastion' ),
			'',
			array( 'response' => 404 )
		);
	}
}
