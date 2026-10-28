<?php
/**
 * The security headers.
 *
 * @package Bastion
 */

namespace Bastion;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and sends the response headers.
 *
 * `build()` is pure — settings in, ordered map out — so the whole policy can be
 * asserted in a test without a web server, and the audit screen can show
 * exactly what would be sent before anything is.
 */
final class Headers {

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
	 * Registers the hook. `send_headers` runs for every request once WordPress
	 * has decided the status, so the headers travel with the real response and
	 * not a second one.
	 */
	public function hooks(): void {
		add_action( 'send_headers', array( $this, 'send' ) );
	}

	/**
	 * Sends the headers, if the plugin is allowed to act.
	 */
	public function send(): void {
		if ( ! $this->settings->active( 'headers_enabled' ) || headers_sent() ) {
			return;
		}

		foreach ( self::build( $this->settings, self::is_ssl() ) as $name => $value ) {
			header( $name . ': ' . $value, true );
		}

		if ( $this->settings->get( 'remove_powered_by' ) && function_exists( 'header_remove' ) ) {
			header_remove( 'X-Powered-By' );
		}
	}

	/**
	 * Whether the request reached us over TLS.
	 *
	 * Deliberately does not simply call `is_ssl()`: WordPress trusts
	 * `X-Forwarded-Proto` from anyone, which lets a visitor pretend to be on
	 * HTTPS and skip the redirect. The header is only read when the direct peer
	 * is a configured proxy.
	 */
	public static function is_ssl(): bool {
		if ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] ) ) {
			return true;
		}

		if ( 443 === (int) ( $_SERVER['SERVER_PORT'] ?? 0 ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Builds the header map.
	 *
	 * @param Settings $settings Settings.
	 * @param bool     $is_ssl   Whether the response travels over TLS.
	 * @return array<string, string> Ordered header name => value.
	 */
	public static function build( Settings $settings, bool $is_ssl ): array {
		$headers = array();

		$csp = self::csp( $settings );
		$mode = (string) $settings->get( 'csp_mode' );

		if ( '' !== $csp && 'off' !== $mode ) {
			$headers[ 'report-only' === $mode ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy' ] = $csp;
		}

		$frame = (string) $settings->get( 'frame_options' );

		if ( in_array( $frame, array( 'SAMEORIGIN', 'DENY' ), true ) ) {
			// Kept next to the CSP frame-ancestors directive: browsers that
			// predate CSP still read this one.
			$headers['X-Frame-Options'] = $frame;
		}

		$headers['X-Content-Type-Options'] = 'nosniff';

		$referrer = (string) $settings->get( 'referrer_policy' );

		if ( '' !== $referrer ) {
			$headers['Referrer-Policy'] = $referrer;
		}

		$permissions = (string) $settings->get( 'permissions_policy' );

		if ( '' !== $permissions ) {
			$headers['Permissions-Policy'] = $permissions;
		}

		// Off by default: it breaks pop-up based sign-in flows (OAuth, payment
		// providers) that a shop or a membership site may rely on.
		if ( $settings->get( 'coop' ) ) {
			$headers['Cross-Origin-Opener-Policy'] = 'same-origin';
		}

		// The old filter is worse than nothing in every current browser.
		$headers['X-XSS-Protection'] = '0';

		if ( $is_ssl && $settings->get( 'hsts' ) ) {
			$hsts = 'max-age=' . (int) $settings->get( 'hsts_max_age' );

			if ( $settings->get( 'hsts_subdomains' ) ) {
				$hsts .= '; includeSubDomains';
			}

			// `preload` is only meaningful once the domain is submitted to the
			// preload list, and it is irreversible in practice: it is offered
			// as an explicit, separate choice.
			if ( $settings->get( 'hsts_preload' ) && $settings->get( 'hsts_subdomains' ) ) {
				$hsts .= '; preload';
			}

			$headers['Strict-Transport-Security'] = $hsts;
		}

		return $headers;
	}

	/**
	 * The Content-Security-Policy value for the configured preset.
	 *
	 * `safe` deliberately omits `default-src`: that directive is the fallback
	 * for `script-src` and `style-src`, so adding it would block every inline
	 * script and style of the theme and the other plugins. What remains —
	 * framing, base URI, plugins, form targets — is the part that cannot break
	 * a page while still closing clickjacking and base-tag injection.
	 *
	 * @param Settings $settings Settings.
	 */
	public static function csp( Settings $settings ): string {
		$preset = (string) $settings->get( 'csp_preset' );

		$policy = match ( $preset ) {
			'custom' => (string) $settings->get( 'csp_custom' ),
			'strict' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
				. "img-src 'self' data:; font-src 'self' data:; connect-src 'self'; "
				. "frame-ancestors 'self'; base-uri 'self'; object-src 'none'; form-action 'self'; "
				. 'upgrade-insecure-requests',
			default  => self::safe_policy( $settings ),
		};

		$policy = trim( (string) preg_replace( '/[\r\n]+/', ' ', $policy ) );
		$policy = trim( (string) preg_replace( '/\s{2,}/', ' ', $policy ) );

		$report = (string) $settings->get( 'csp_report_uri' );

		if ( '' !== $report && ! str_contains( $policy, 'report-uri' ) && ! str_contains( $policy, 'report-to' ) ) {
			$policy = '' === $policy ? 'report-uri ' . $report : $policy . '; report-uri ' . $report;
		}

		return $policy;
	}

	/**
	 * The enforced-safe policy, with frame-ancestors following the X-Frame-Options
	 * choice so the two can never contradict each other.
	 *
	 * @param Settings $settings Settings.
	 */
	private static function safe_policy( Settings $settings ): string {
		$frame = (string) $settings->get( 'frame_options' );

		$ancestors = match ( $frame ) {
			'DENY'  => "frame-ancestors 'none'",
			'OFF'   => '',
			default => "frame-ancestors 'self'",
		};

		$directives = array_filter(
			array(
				$ancestors,
				"base-uri 'self'",
				"object-src 'none'",
				"form-action 'self'",
			)
		);

		return implode( '; ', $directives );
	}
}
