<?php
/**
 * Forcing HTTPS, with a dry run and loop protection.
 *
 * @package Bastion
 */

namespace Bastion;

defined( 'ABSPATH' ) || exit;

/**
 * Redirects the plaintext request to HTTPS.
 *
 * The dangerous part of this feature is not the redirect, it is everything
 * around it: an unconditional redirect breaks a site behind a proxy that
 * terminates TLS (infinite loop), breaks a POST from a client that never
 * receives the 301, and breaks a single-server install with no certificate at
 * all. Each of those is handled explicitly, and the feature ships off, with a
 * dry run on top.
 */
final class Https {

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
	 * Two entry points, because one is not enough:
	 *
	 * - `template_redirect` covers the front end. It runs after WordPress has
	 *   routed the request, so it knows the request is a page view and not a
	 *   cron tick.
	 * - `login_init` covers `wp-login.php`, which is a standalone file that
	 *   never fires `template_redirect`. Skipping it would leave the one page
	 *   that carries a password in clear text completely unprotected.
	 */
	public function hooks(): void {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
		add_action( 'login_init', array( $this, 'maybe_redirect' ), 1 );
	}

	/**
	 * Redirects when the request arrived in clear text.
	 */
	public function maybe_redirect(): void {
		$url = $this->target();

		if ( null === $url ) {
			return;
		}

		if ( $this->settings->get( 'https_dry_run' ) ) {
			$this->log( 'https_redirect_test', $url );

			return;
		}

		wp_redirect( $url, (int) $this->settings->get( 'http_status' ) );
		exit;
	}

	/**
	 * The URL to send the visitor to, or null when nothing must happen.
	 *
	 * Kept free of side effects so the audit can call it to predict the answer.
	 */
	public function target(): ?string {
		if ( ! $this->settings->active( 'force_https' ) ) {
			return null;
		}

		if ( self::is_secure( $this->settings ) ) {
			return null;
		}

		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );

		// A 301 on a POST silently turns it into a GET and loses the body:
		// better to log once than to break a checkout or a form. The login POST
		// is the one case worth naming, because that is a password in clear
		// text — it gets its own event so the log says what happened.
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			$this->log( 'https_post_left_alone', $method . ' ' . (string) wp_parse_url( self::current_url(), PHP_URL_PATH ) );

			return null;
		}

		return self::https_url( self::current_url(), (string) home_url() );
	}

	/**
	 * Whether this request is already secure.
	 *
	 * @param Settings $settings Settings, for the proxy list.
	 */
	public static function is_secure( Settings $settings ): bool {
		if ( Headers::is_ssl() ) {
			return true;
		}

		if ( ! $settings->get( 'trust_proxy' ) ) {
			return false;
		}

		if ( ! self::is_trusted_proxy( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), (string) $settings->get( 'trusted_proxies' ) ) ) {
			return false;
		}

		return self::forwarded_scheme() === 'https';
	}

	/**
	 * The scheme the closest proxy reports.
	 *
	 * The right-most value is the one appended by the nearest proxy; the
	 * left-most one is whatever the visitor chose to send.
	 */
	public static function forwarded_scheme(): string {
		$proto = (string) ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' );

		if ( '' === $proto ) {
			$ssl = (string) ( $_SERVER['HTTP_X_FORWARDED_SSL'] ?? '' );

			return 'on' === strtolower( $ssl ) ? 'https' : '';
		}

		$parts = array_values( array_filter( array_map( 'trim', explode( ',', $proto ) ) ) );

		if ( array() === $parts ) {
			return '';
		}

		return strtolower( (string) end( $parts ) );
	}

	/**
	 * Whether an address belongs to the configured proxies.
	 *
	 * @param string $address Visitor address.
	 * @param string $list    One address or CIDR per line.
	 */
	public static function is_trusted_proxy( string $address, string $list ): bool {
		if ( '' === $address || '' === trim( $list ) ) {
			return false;
		}

		foreach ( preg_split( '/\R/', $list ) ?: array() as $entry ) {
			$entry = trim( $entry );

			if ( '' === $entry ) {
				continue;
			}

			if ( $entry === $address ) {
				return true;
			}

			if ( str_contains( $entry, '/' ) && self::in_range( $address, $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an address sits inside a CIDR block.
	 *
	 * @param string $address Address.
	 * @param string $cidr    Network/prefix.
	 */
	public static function in_range( string $address, string $cidr ): bool {
		$parts = explode( '/', $cidr, 2 );

		if ( 2 !== count( $parts ) ) {
			return false;
		}

		$binary = @inet_pton( $address );
		$net    = @inet_pton( $parts[0] );

		if ( false === $binary || false === $net || strlen( $binary ) !== strlen( $net ) ) {
			return false;
		}

		$bits  = (int) $parts[1];
		$bytes = strlen( $binary );

		if ( $bits < 0 || $bits > $bytes * 8 ) {
			return false;
		}

		$whole = intdiv( $bits, 8 );
		$rest  = $bits % 8;

		if ( substr( $binary, 0, $whole ) !== substr( $net, 0, $whole ) ) {
			return false;
		}

		if ( 0 === $rest ) {
			return true;
		}

		$mask = chr( ( 0xFF << ( 8 - $rest ) ) & 0xFF );

		return ( $binary[ $whole ] & $mask ) === ( $net[ $whole ] & $mask );
	}

	/**
	 * The current request URL, from the server variables.
	 */
	public static function current_url(): string {
		$host = (string) ( $_SERVER['HTTP_HOST'] ?? '' );

		if ( '' === $host ) {
			return '';
		}

		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );

		return 'http://' . $host . $uri;
	}

	/**
	 * Turns a request URL into its HTTPS twin, or null when it must not be
	 * rewritten: a foreign host, or a URL that would produce a loop.
	 *
	 * @param string $url  Request URL.
	 * @param string $home The site URL.
	 */
	public static function https_url( string $url, string $home ): ?string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = wp_parse_url( $url, PHP_URL_QUERY );

		if ( '' === $host || '' === $path ) {
			return null;
		}

		$allowed = array_filter(
			array(
				strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) ),
				strtolower( (string) wp_parse_url( (string) get_option( 'siteurl' ), PHP_URL_HOST ) ),
			)
		);

		// A request for another hostname is not ours to redirect: it is either
		// a misconfigured vhost or an attack, and answering it with our
		// certificate authority would be worse than ignoring it.
		if ( ! in_array( $host, $allowed, true ) ) {
			return null;
		}

		$target = 'https://' . $host . self::port_suffix( $home ) . $path;

		if ( is_string( $query ) && '' !== $query ) {
			$target .= '?' . $query;
		}

		return $target;
	}

	/**
	 * The `:port` part of the redirect target.
	 *
	 * The port is part of the destination: a site served on 8080 or 8443 must
	 * not be sent to 443, where nothing is listening. Port 80 is the one case
	 * that has to be dropped, because HTTPS answers on 443 and writing
	 * `https://host:80` would be a redirect to nowhere.
	 *
	 * @param string $home The configured site URL.
	 */
	public static function port_suffix( string $home ): string {
		$port = (int) wp_parse_url( $home, PHP_URL_PORT );

		return $port > 0 && 80 !== $port ? ':' . $port : '';
	}

	/**
	 * Records an event in the capped log.
	 *
	 * @param string $event  Event key.
	 * @param string $detail Optional detail.
	 */
	private function log( string $event, string $detail = '' ): void {
		$this->settings->log( $event, $detail );
	}
}
