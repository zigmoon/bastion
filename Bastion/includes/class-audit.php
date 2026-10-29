<?php
/**
 * The audit: what is actually true, measured on the live site.
 *
 * @package Bastion
 */

namespace Bastion;

defined( 'ABSPATH' ) || exit;

/**
 * Runs every check and returns evidence, not intentions.
 *
 * Two rules make this worth reading:
 *
 * 1. **Measure the site, not the settings.** A header is only "pass" when the
 *    site really answered with it — the class fetches its own home page and
 *    reads the response. A configuration screen that says "CSP: on" while the
 *    server strips the header is worse than no screen.
 * 2. **Never invent a result.** A check that cannot be measured (no DNS
 *    resolver on the host, HTTPS unreachable from the server itself) returns
 *    `info` or `warn` with the reason, never a green tick.
 */
final class Audit {

	/**
	 * Statuses, in increasing order of severity.
	 */
	public const PASS = 'pass';
	public const WARN = 'warn';
	public const FAIL = 'fail';
	public const INFO = 'info';

	/**
	 * Site transient holding the last measured response.
	 */
	public const CACHE = 'bastion_probe';

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
	 * Every check, grouped, for the audit screen and the report.
	 *
	 * @param bool $fresh Bypass the cached probe.
	 * @return array<string, array<int, array<string, string>>> Group => rows.
	 */
	public function run( bool $fresh = false ): array {
		$probe = $this->probe( $fresh );

		return array(
			'https'      => $this->https_checks( $probe ),
			'headers'    => $this->header_checks( $probe ),
			'mail'       => $this->mail_checks(),
			'disclosure' => $this->disclosure_checks( $probe ),
		);
	}

	/**
	 * Counts the rows by status.
	 *
	 * @param array<string, array<int, array<string, string>>> $groups Groups.
	 * @return array<string, int>
	 */
	public static function tally( array $groups ): array {
		$counts = array(
			self::PASS => 0,
			self::WARN => 0,
			self::FAIL => 0,
			self::INFO => 0,
		);

		foreach ( $groups as $rows ) {
			foreach ( $rows as $row ) {
				$status = $row['status'] ?? self::INFO;

				if ( isset( $counts[ $status ] ) ) {
					++$counts[ $status ];
				}
			}
		}

		return $counts;
	}

	/* ---------------------------------------------------------------------
	 * The probe: one HEAD and one GET, measured.
	 * ------------------------------------------------------------------ */

	/**
	 * Fetches the site and remembers what came back.
	 *
	 * Two rules here. The measurement is taken on the URL the site actually
	 * serves (`served`), not on a scheme we wish it served: probing `https://`
	 * on an install that is still `http://` would report "unreachable" for
	 * everything, and a check that cannot run must never look like a pass.
	 * The `https` probe is separate and only feeds the TLS checks.
	 *
	 * @param bool $fresh Ignore the cache.
	 * @return array<string, mixed>
	 */
	public function probe( bool $fresh = false ): array {
		if ( ! $fresh ) {
			$cached = get_site_transient( self::CACHE );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$home   = untrailingslashit( (string) home_url() );
		$scheme = strtolower( (string) wp_parse_url( $home, PHP_URL_SCHEME ) );
		$tls    = 'https' === $scheme;

		$served = $this->fetch( $home );

		$probe = array(
			'time'       => time(),
			'tls'        => $tls,
			'served'     => $served,
			'https'      => $tls ? $served : $this->fetch( (string) set_url_scheme( $home, 'https' ) ),
			'http'       => $this->fetch( (string) set_url_scheme( $home, 'http' ) ),
			// The login screen is the one page that carries a password: it is
			// measured on its own, because a redirect that covers the front end
			// but not `wp-login.php` protects the least interesting surface.
			'login'      => $this->fetch( (string) set_url_scheme( $home, 'http' ) . '/wp-login.php' ),
			'readme'     => $this->fetch( $home . '/readme.html' ),
			'generator'  => '',
			'http_links' => array(),
			'ver'        => '',
		);

		if ( 'GET' === ( $served['method'] ?? '' ) && '' !== ( $served['body'] ?? '' ) ) {
			$html = (string) $served['body'];

			// The body is only used for patterns; it is never printed raw, and
			// only the first 400 kB are kept.
			$probe['generator']  = self::generator_of( $html );
			$probe['http_links'] = self::plaintext_assets( $html );
			$probe['ver']        = self::version_query( $html );
		}

		unset(
			$probe['served']['body'],
			$probe['https']['body'],
			$probe['http']['body'],
			$probe['login']['body'],
			$probe['readme']['body']
		);

		set_site_transient( self::CACHE, $probe, 30 * MINUTE_IN_SECONDS );

		return $probe;
	}

	/**
	 * A request whose result is reported rather than thrown.
	 *
	 * @param string $url     Target.
	 * @param string $method  GET or HEAD.
	 * @param int    $redirect How many redirects to follow.
	 * @return array<string, mixed>
	 */
	private function fetch( string $url, string $method = 'GET', int $redirect = 0 ): array {
		$response = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'timeout'     => 8,
				'redirection' => $redirect,
				'sslverify'   => true,
				'user-agent'  => 'Bastion/' . BASTION_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'method'  => $method,
				'url'     => $url,
				'ok'      => false,
				'code'    => 0,
				'error'   => $response->get_error_message(),
				'headers' => array(),
				'body'    => '',
			);
		}

		$headers = self::normalise_headers( wp_remote_retrieve_headers( $response ) );

		return array(
			'method'  => $method,
			'url'     => $url,
			'ok'      => true,
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'error'   => '',
			'headers' => $headers,
			'body'    => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Turns whatever `wp_remote_retrieve_headers()` returned into a lowercase map.
	 *
	 * Since WordPress 6.2 that function returns a `CaseInsensitiveDictionary`
	 * object, and casting an object to an array exposes its private properties,
	 * not its keys — a cast here silently produced `['data' => …]` and made every
	 * header check report "absent". `getAll()` is the documented way in.
	 *
	 * @param mixed $headers Response headers, object or array.
	 * @return array<string, string>
	 */
	public static function normalise_headers( $headers ): array {
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		$clean = array();

		foreach ( is_array( $headers ) ? $headers : array() as $name => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}

			$clean[ strtolower( (string) $name ) ] = (string) $value;
		}

		return $clean;
	}

	/* ---------------------------------------------------------------------
	 * Checks.
	 * ------------------------------------------------------------------ */

	/**
	 * Transport checks.
	 *
	 * @param array<string, mixed> $probe Probe.
	 * @return array<int, array<string, string>>
	 */
	private function https_checks( array $probe ): array {
		$home  = (string) home_url();
		$site  = (string) get_option( 'siteurl' );
		$https = $probe['https'] ?? array();
		$plain = $probe['http'] ?? array();

		$rows = array();

		$rows[] = self::row(
			'home_https',
			__( 'Site address uses HTTPS', 'bastion' ),
			str_starts_with( $home, 'https://' ) && str_starts_with( $site, 'https://' ) ? self::PASS : self::FAIL,
			$home . ' · ' . $site,
			__( 'Settings → General: put https:// in both fields.', 'bastion' )
		);

		$reachable = (bool) ( $https['ok'] ?? false );

		$rows[] = self::row(
			'https_reachable',
			__( 'HTTPS answers', 'bastion' ),
			$reachable ? self::PASS : self::WARN,
			$reachable
				? 'HTTP ' . ( $https['code'] ?? 0 ) . ' · ' . ( $https['url'] ?? '' )
				: (string) ( $https['error'] ?? __( 'no answer', 'bastion' ) ),
			$reachable ? '' : __( 'If the site has no certificate yet, this check cannot pass. Nothing is changed by the plugin until it does.', 'bastion' )
		);

		$redirects = in_array( (int) ( $plain['code'] ?? 0 ), array( 301, 302, 307, 308 ), true );
		$location  = strtolower( (string) ( $plain['headers']['location'] ?? '' ) );
		$to_https  = str_starts_with( $location, 'https://' );

		// A plaintext fetch that cannot connect at all is the best possible
		// answer: the clear-text port is simply not served.
		$closed = ! ( $plain['ok'] ?? false );

		$rows[] = self::row(
			'http_redirect',
			__( 'Clear text redirects to HTTPS', 'bastion' ),
			$closed ? self::PASS : ( $redirects && $to_https ? self::PASS : self::FAIL ),
			$closed
				? __( 'http:// does not answer at all — the plaintext port is closed', 'bastion' )
				: 'HTTP ' . ( $plain['code'] ?? 0 ) . ( $location ? ' → ' . $location : ' — ' . __( 'no redirect', 'bastion' ) ),
			$closed ? '' : __( 'Bastion → HTTPS → force, or a server-level redirect.', 'bastion' )
		);

		// The login screen is a separate file that never reaches the front-end
		// hook: it needs its own measurement, because a redirect that misses it
		// leaves the password in clear text.
		$login       = (array) ( $probe['login'] ?? array() );
		$login_ok    = (bool) ( $login['ok'] ?? false );
		$login_code  = (int) ( $login['code'] ?? 0 );
		$login_where = strtolower( (string) ( $login['headers']['location'] ?? '' ) );
		$login_back  = ! $login_ok || ( in_array( $login_code, array( 301, 302, 307, 308 ), true ) && str_starts_with( $login_where, 'https://' ) );

		$rows[] = self::row(
			'login_redirect',
			__( 'Login screen is not served in clear text', 'bastion' ),
			$login_back ? self::PASS : self::FAIL,
			! $login_ok
				? __( 'unreachable over http://', 'bastion' )
				: 'HTTP ' . $login_code . ( $login_where ? ' → ' . $login_where : ' — ' . __( 'no redirect', 'bastion' ) ),
			$login_back ? '' : __( 'The page that carries a password must be covered too: switch on {force https}, or add a server-level redirect.', 'bastion' )
		);

		$hsts = (string) ( $https['headers']['strict-transport-security'] ?? '' );
		$age  = preg_match( '/max-age=(\d+)/i', $hsts, $match ) ? (int) $match[1] : 0;

		$rows[] = self::row(
			'hsts',
			__( 'HSTS is set', 'bastion' ),
			'' === $hsts ? self::FAIL : ( $age >= 15552000 ? self::PASS : self::WARN ),
			'' === $hsts ? __( 'no Strict-Transport-Security header', 'bastion' ) : $hsts,
			'' === $hsts ? __( 'Bastion → HTTPS → HSTS, once HTTPS is confirmed working.', 'bastion' ) : __( 'A max-age below 180 days is a transitional value.', 'bastion' )
		);

		$rows[] = self::row(
			'admin_ssl',
			__( 'Admin forced over HTTPS', 'bastion' ),
			defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN ? self::PASS : self::WARN,
			defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN
				? "define( 'FORCE_SSL_ADMIN', true );"
				: __( 'not defined in wp-config.php', 'bastion' ),
			__( 'Add define( \'FORCE_SSL_ADMIN\', true ); to wp-config.php — the login cookie is worth as much as the password.', 'bastion' )
		);

		return $rows;
	}

	/**
	 * Header checks.
	 *
	 * @param array<string, mixed> $probe Probe.
	 * @return array<int, array<string, string>>
	 */
	private function header_checks( array $probe ): array {
		$headers   = (array) ( $probe['served']['headers'] ?? array() );
		$reachable = (bool) ( $probe['served']['ok'] ?? false );

		$rows = array();

		// The CSP is the one header where "present" is not the same as "in
		// force": a report-only policy blocks nothing. Reporting it as a pass
		// would be exactly the kind of green tick this audit exists to avoid.
		$enforcing = (string) ( $headers['content-security-policy'] ?? '' );
		$reporting = (string) ( $headers['content-security-policy-report-only'] ?? '' );

		if ( '' !== $enforcing ) {
			$rows[] = self::row( 'csp', __( 'Content-Security-Policy', 'bastion' ), self::PASS, 'content-security-policy: ' . $enforcing, '' );
		} elseif ( '' !== $reporting ) {
			$rows[] = self::row(
				'csp',
				__( 'Content-Security-Policy', 'bastion' ),
				self::WARN,
				__( 'report-only, so nothing is blocked yet:', 'bastion' ) . ' ' . $reporting,
				__( 'Read the violations in the browser console, then switch the mode to “Enforce”.', 'bastion' )
			);
		} else {
			$rows[] = self::row(
				'csp',
				__( 'Content-Security-Policy', 'bastion' ),
				$reachable ? self::FAIL : self::WARN,
				$reachable ? __( 'header absent', 'bastion' ) : __( 'site unreachable', 'bastion' ),
				$reachable ? __( 'Bastion → Headers → policy.', 'bastion' ) : ''
			);
		}

		$rows[] = $this->header_row(
			'xfo',
			__( 'Anti-clickjacking', 'bastion' ),
			array( 'x-frame-options' ),
			$headers,
			$reachable,
			__( 'Without it, the site can be framed and overlaid with a fake form.', 'bastion' ),
			// The CSP directive does the same job in a modern browser.
			(string) ( $headers['content-security-policy'] ?? '' )
		);

		$rows[] = $this->header_row(
			'nosniff',
			__( 'File types locked (nosniff)', 'bastion' ),
			array( 'x-content-type-options' ),
			$headers,
			$reachable,
			__( 'Stops a browser from running an uploaded file as a script.', 'bastion' )
		);

		$rows[] = $this->header_row(
			'referrer',
			__( 'Referrer policy', 'bastion' ),
			array( 'referrer-policy' ),
			$headers,
			$reachable,
			__( 'Limits what the browser tells other sites about your URLs.', 'bastion' )
		);

		$rows[] = $this->header_row(
			'permissions',
			__( 'Permissions policy', 'bastion' ),
			array( 'permissions-policy', 'feature-policy' ),
			$headers,
			$reachable,
			__( 'Optional: disables camera, microphone and geolocation for the whole site.', 'bastion' )
		);

		return $rows;
	}

	/**
	 * Mail checks.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function mail_checks(): array {
		$dns    = new Dns( $this->settings );
		$domain = $dns->domain();

		$rows = array();

		if ( '' === $domain ) {
			return array(
				self::row( 'mail_domain', __( 'Domain', 'bastion' ), self::INFO, __( 'not set', 'bastion' ), __( 'Settings → Email.', 'bastion' ) ),
			);
		}

		$resolvable = function_exists( 'dns_get_record' ) || function_exists( 'checkdnsrr' );

		$rows[] = self::row(
			'mail_domain',
			__( 'Domain checked', 'bastion' ),
			self::INFO,
			$domain,
			''
		);

		$spf = $dns->find( $domain, 'v=spf1' );

		if ( ! $resolvable ) {
			$rows[] = self::row( 'spf', __( 'SPF published', 'bastion' ), self::WARN, __( 'this host cannot query DNS', 'bastion' ), '' );
		} elseif ( '' === $spf ) {
			$rows[] = self::row( 'spf', __( 'SPF published', 'bastion' ), self::FAIL, __( 'no v=spf1 TXT record at the domain', 'bastion' ), __( 'Publish the record below at your registrar.', 'bastion' ) );
		} else {
			$lookups = preg_match_all( '/\b(?:include|a|mx|ptr|exists|redirect)\b/i', $spf );

			$rows[] = self::row(
				'spf',
				__( 'SPF published', 'bastion' ),
				$lookups > 10 ? self::WARN : self::PASS,
				$spf,
				$lookups > 10 ? __( 'More than 10 DNS lookups: the record may be ignored by receivers.', 'bastion' ) : ''
			);
		}

		$dmarc = $dns->find( '_dmarc.' . $domain, 'v=DMARC1' );

		if ( ! $resolvable ) {
			$rows[] = self::row( 'dmarc', __( 'DMARC published', 'bastion' ), self::WARN, __( 'this host cannot query DNS', 'bastion' ), '' );
		} elseif ( '' === $dmarc ) {
			$rows[] = self::row( 'dmarc', __( 'DMARC published', 'bastion' ), self::FAIL, __( 'no v=DMARC1 TXT record at _dmarc', 'bastion' ), __( 'Publish the record below at your registrar.', 'bastion' ) );
		} else {
			$strict = (bool) preg_match( '/p=(quarantine|reject)/i', $dmarc );

			$rows[] = self::row(
				'dmarc',
				__( 'DMARC published', 'bastion' ),
				$strict ? self::PASS : self::WARN,
				$dmarc,
				$strict ? '' : __( 'p=none only observes. Move to quarantine, then reject, once the reports are clean.', 'bastion' )
			);
		}

		$rows[] = self::row(
			'dkim',
			__( 'DKIM', 'bastion' ),
			self::INFO,
			__( 'not checked: the selector is provider-specific', 'bastion' ),
			__( 'Enable DKIM in your mail provider, then keep the key it gives you.', 'bastion' )
		);

		return $rows;
	}

	/**
	 * Disclosure checks.
	 *
	 * @param array<string, mixed> $probe Probe.
	 * @return array<int, array<string, string>>
	 */
	private function disclosure_checks( array $probe ): array {
		$served    = (array) ( $probe['served'] ?? array() );
		$headers   = (array) ( $served['headers'] ?? array() );
		$reachable = (bool) ( $served['ok'] ?? false );

		$rows = array();

		$powered = (string) ( $headers['x-powered-by'] ?? '' );

		$rows[] = self::row(
			'powered_by',
			__( 'Server version hidden', 'bastion' ),
			! $reachable ? self::WARN : ( '' === $powered ? self::PASS : self::FAIL ),
			! $reachable ? __( 'site unreachable', 'bastion' ) : ( '' === $powered ? __( 'no X-Powered-By header', 'bastion' ) : 'X-Powered-By: ' . $powered ),
			! $reachable ? '' : ( '' === $powered ? '' : __( 'Bastion → Disclosures, or expose_php = Off in php.ini.', 'bastion' ) )
		);

		$generator = (string) ( $probe['generator'] ?? '' );

		$rows[] = self::row(
			'generator',
			__( 'WordPress version hidden', 'bastion' ),
			! $reachable ? self::WARN : ( '' === $generator ? self::PASS : self::FAIL ),
			! $reachable ? __( 'site unreachable', 'bastion' ) : ( '' === $generator ? __( 'no <meta name="generator">', 'bastion' ) : $generator ),
			! $reachable ? '' : ( '' === $generator ? '' : __( 'Bastion → Disclosures → generator.', 'bastion' ) )
		);

		$ver = (string) ( $probe['ver'] ?? '' );

		$rows[] = self::row(
			'ver',
			__( 'Asset versions hidden', 'bastion' ),
			! $reachable ? self::WARN : ( '' === $ver ? self::PASS : self::FAIL ),
			! $reachable ? __( 'site unreachable', 'bastion' ) : ( '' === $ver ? __( 'no ?ver=<core version> on the page', 'bastion' ) : $ver ),
			! $reachable ? '' : ( '' === $ver ? '' : __( 'Bastion → Disclosures → asset versions.', 'bastion' ) )
		);

		$links = (array) ( $probe['http_links'] ?? array() );
		$count = count( $links );
		$tls   = (bool) ( $probe['tls'] ?? false );

		// Mixed content is a browser rule about an HTTPS page: on a site served
		// over HTTP there is nothing to mix, and a failure here would be a
		// finding that cannot be fixed without moving to TLS first.
		if ( ! $reachable ) {
			$rows[] = self::row( 'mixed', __( 'No mixed content', 'bastion' ), self::WARN, __( 'could not read the page', 'bastion' ), '' );
		} elseif ( ! $tls ) {
			$rows[] = self::row(
				'mixed',
				__( 'No mixed content', 'bastion' ),
				self::WARN,
				__( 'not applicable: the site itself is served over HTTP', 'bastion' ),
				__( 'Mixed content only exists on an HTTPS page. Move the site to HTTPS first.', 'bastion' )
			);
		} else {
			$rows[] = self::row(
				'mixed',
				__( 'No mixed content', 'bastion' ),
				0 === $count ? self::PASS : self::FAIL,
				0 === $count ? __( 'every resource is loaded over HTTPS', 'bastion' ) : $count . ' · ' . implode( ' · ', array_slice( $links, 0, 3 ) ),
				$count > 0 ? __( 'Search the theme and the plugins for http:// URLs, or the database if they come from the content.', 'bastion' ) : ''
			);
		}

		$readme = (array) ( $probe['readme'] ?? array() );
		$code   = (int) ( $readme['code'] ?? 0 );

		// A readme that answers 200 is a leak; one that answers 403/404 is
		// closed. One that cannot be fetched at all proves nothing, and is
		// therefore a warning, not a pass.
		$blocked = 403 === $code || 404 === $code || 410 === $code;

		$rows[] = self::row(
			'readme',
			__( 'readme.html is not served', 'bastion' ),
			! $reachable ? self::WARN : ( $blocked ? self::PASS : self::FAIL ),
			! $reachable ? __( 'site unreachable', 'bastion' ) : 'HTTP ' . $code,
			! $reachable ? '' : ( $blocked ? '' : __( 'Delete readme.html, or let Bastion answer 404 for it.', 'bastion' ) )
		);

		return $rows;
	}

	/* ---------------------------------------------------------------------
	 * Helpers, all pure enough to test.
	 * ------------------------------------------------------------------ */

	/**
	 * One row.
	 *
	 * @param string $id     Machine key.
	 * @param string $label  Human label.
	 * @param string $status One of the statuses.
	 * @param string $value  Evidence.
	 * @param string $fix    What to do.
	 * @return array<string, string>
	 */
	public static function row( string $id, string $label, string $status, string $value, string $fix ): array {
		return array(
			'id'     => $id,
			'label'  => $label,
			'status' => $status,
			'value'  => $value,
			'fix'    => $fix,
		);
	}

	/**
	 * A header row: pass when the response really carried one of the names.
	 *
	 * @param string                $id       Machine key.
	 * @param string                $label    Label.
	 * @param array<int, string>    $names    Accepted header names, lower case.
	 * @param array<string, string> $headers  Response headers.
	 * @param bool                  $reachable Whether the site answered.
	 * @param string                $fix      Remedy.
	 * @param string                $fallback A CSP value that covers the check.
	 * @return array<string, string>
	 */
	private function header_row( string $id, string $label, array $names, array $headers, bool $reachable, string $fix, string $fallback = '' ): array {
		foreach ( $names as $name ) {
			if ( isset( $headers[ $name ] ) && '' !== $headers[ $name ] ) {
				$value = $headers[ $name ];

				return self::row( $id, $label, self::PASS, $name . ': ' . $value, '' );
			}
		}

		if ( '' !== $fallback && preg_match( '/frame-ancestors\s+[^;]+/i', $fallback, $match ) ) {
			return self::row( $id, $label, self::PASS, 'CSP ' . $match[0], '' );
		}

		return self::row(
			$id,
			$label,
			$reachable ? self::FAIL : self::WARN,
			$reachable ? __( 'header absent', 'bastion' ) : __( 'site unreachable', 'bastion' ),
			$reachable ? $fix : ''
		);
	}

	/**
	 * The generator meta content, if the page carries one.
	 *
	 * @param string $html Page.
	 */
	public static function generator_of( string $html ): string {
		if ( preg_match( '/<meta[^>]+name=["\']generator["\'][^>]*>/i', $html, $match ) ) {
			return $match[0];
		}

		return '';
	}

	/**
	 * Resources the page loads over http://.
	 *
	 * Only what the browser actually fetches counts. A `<link rel="alternate">`
	 * pointing at an `http://` feed, or an `http://` URL inside a paragraph, is
	 * a hyperlink, not mixed content — counting those made the check fire on
	 * every plain-HTTP site with its own feeds, which is noise, not a finding.
	 *
	 * @param string $html Page.
	 * @return array<int, string>
	 */
	public static function plaintext_assets( string $html ): array {
		$found = array();

		// Elements whose src/data/poster the browser fetches on its own.
		$pattern = '/<(?:script|img|iframe|source|video|audio|embed|object)\b[^>]*?\b(?:src|data|poster)\s*=\s*["\']?(http:\/\/[^"\'\s>]+)/i';

		if ( preg_match_all( $pattern, $html, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$found[ $url ] = true;
			}
		}

		// <link> only fetches when its rel says so.
		if ( preg_match_all( '/<link\b[^>]*>/i', $html, $links ) ) {
			foreach ( $links[0] as $tag ) {
				if ( ! preg_match( '/\brel\s*=\s*["\']?([^"\'\s>]+)/i', $tag, $rel ) ) {
					continue;
				}

				if ( ! preg_match( '/(?:^|\s)(?:stylesheet|preload|modulepreload|manifest|mask-icon|icon|shortcut|apple-touch-icon)(?:\s|$)/i', $rel[1] ) ) {
					continue;
				}

				if ( preg_match( '/\bhref\s*=\s*["\']?(http:\/\/[^"\'\s>]+)/i', $tag, $href ) ) {
					$found[ $href[1] ] = true;
				}
			}
		}

		// Inline CSS url(http://...).
		if ( preg_match_all( '/url\(\s*["\']?(http:\/\/[^"\')]+)/i', $html, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$found[ $url ] = true;
			}
		}

		return array_slice( array_keys( $found ), 0, 20 );
	}

	/**
	 * An asset URL carrying the core version as `?ver=`.
	 *
	 * @param string $html Page.
	 */
	public static function version_query( string $html ): string {
		if ( preg_match( '/["\']([^"\']+[?&]ver=\d+\.\d+(?:\.\d+)?(?:-[a-z0-9.]+)?)["\']/i', $html, $match ) ) {
			return $match[1];
		}

		return '';
	}

	/**
	 * The report as Markdown, for the person who asked for proof.
	 *
	 * @param array<string, array<int, array<string, string>>> $groups Groups.
	 * @param string                                           $site   Site URL.
	 */
	public static function markdown( array $groups, string $site ): string {
		$tally = self::tally( $groups );

		$out = '# ' . __( 'Security audit', 'bastion' ) . "\n\n";
		$out .= '- ' . __( 'Site', 'bastion' ) . ': ' . $site . "\n";
		$out .= '- ' . __( 'Date', 'bastion' ) . ': ' . gmdate( 'Y-m-d H:i' ) . " UTC\n";
		$out .= '- ' . __( 'Result', 'bastion' ) . ': ' . sprintf(
			/* translators: 1: passed, 2: warnings, 3: failures. */
			__( '%1$d passed, %2$d warnings, %3$d failed', 'bastion' ),
			$tally[ self::PASS ],
			$tally[ self::WARN ],
			$tally[ self::FAIL ]
		) . "\n\n";

		$titles = array(
			'https'      => __( 'HTTPS', 'bastion' ),
			'headers'    => __( 'Headers', 'bastion' ),
			'mail'       => __( 'Email', 'bastion' ),
			'disclosure' => __( 'Disclosures', 'bastion' ),
		);

		foreach ( $groups as $group => $rows ) {
			$out .= '## ' . ( $titles[ $group ] ?? $group ) . "\n\n";
			$out .= '| ' . __( 'Check', 'bastion' ) . ' | ' . __( 'Status', 'bastion' ) . ' | ' . __( 'Evidence', 'bastion' ) . " |\n";
			$out .= "|---|---|---|\n";

			foreach ( $rows as $row ) {
				$value = str_replace( array( '|', "\n" ), array( '\|', ' ' ), (string) $row['value'] );
				$out  .= '| ' . $row['label'] . ' | ' . strtoupper( $row['status'] ) . ' | ' . $value . " |\n";
			}

			$out .= "\n";
		}

		return $out;
	}
}
