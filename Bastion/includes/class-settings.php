<?php
/**
 * Options, defaults and sanitisation.
 *
 * @package Bastion
 */

namespace Bastion;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, validates and stores the single option array.
 *
 * Two ideas shape this class:
 *
 * 1. **Nothing is active until asked.** The plugin boots in `audit` mode, where
 *    it observes and reports but changes nothing on the front end. A security
 *    plugin that hardens a live site the moment it is activated is a liability.
 * 2. **Every default is the reversible one.** HSTS is off (a browser remembers
 *    it and a bad header can make a site unreachable), 301 redirects are off,
 *    and Content-Security-Policy ships in report-only.
 */
final class Settings {

	/**
	 * Option holding every setting.
	 */
	public const OPTION = 'bastion_settings';

	/**
	 * Option holding the plugin version, used by the upgrade routine.
	 */
	public const VERSION_OPTION = 'bastion_version';

	/**
	 * Option holding the "first run" flag for the welcome notice.
	 */
	public const WELCOME_OPTION = 'bastion_welcome';

	/**
	 * Runtime cache of the merged settings.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Defaults.
	 *
	 * Deliberately free of `__()`: a translated default would be written to the
	 * database in whichever language the administrator happened to use, and
	 * WordPress 6.7+ reports a notice for a text domain used too early.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'            => true,

			// `audit` changes nothing on the front end; `apply` lets the
			// switches below do their work.
			'mode'               => 'audit',

			// Transport.
			'force_https'        => false,
			'https_dry_run'      => true,
			'trust_proxy'        => false,
			'trusted_proxies'    => '',
			'http_status'        => 301,

			// HSTS: off, because a browser that has seen it cannot be told to
			// forget it quickly.
			'hsts'               => false,
			'hsts_max_age'       => 15552000,
			'hsts_subdomains'    => false,
			'hsts_preload'       => false,

			// Headers.
			'headers_enabled'    => true,
			'frame_options'      => 'SAMEORIGIN',
			'referrer_policy'    => 'strict-origin-when-cross-origin',
			'permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
			'coop'               => false,
			'csp_mode'           => 'report-only',
			'csp_preset'         => 'safe',
			'csp_custom'         => '',
			'csp_report_uri'     => '',

			// Disclosures.
			'strip_version'      => true,
			'remove_generator'   => true,
			'remove_rsd'         => true,
			'remove_shortlink'   => true,
			'remove_pingback'    => true,
			'remove_powered_by'  => true,
			'block_readme'       => true,

			// Mail.
			'mail_domain'        => '',
			'mail_provider'      => '',
			'spf_include'        => '',
			'spf_all'            => '~all',
			'dmarc_policy'       => 'none',
			'dmarc_report'       => '',
			'dmarc_pct'          => 100,

			// Housekeeping.
			'check_updates'      => true,
			'log_enabled'        => true,
			'delete_data'        => true,
		);
	}

	/**
	 * The two modes.
	 *
	 * @return array<int, string>
	 */
	public static function modes(): array {
		return array( 'audit', 'apply' );
	}

	/**
	 * Every setting merged with the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();

			$this->cache = array_merge( self::defaults(), $stored );

			if ( ! in_array( $this->cache['mode'], self::modes(), true ) ) {
				$this->cache['mode'] = 'audit';
			}
		}

		return $this->cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is absent.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed {
		$all = $this->all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Turns everything off for this request, in memory only.
	 *
	 * Used by the `BASTION_DISABLED` constant: a site that cannot load its own
	 * dashboard must be recoverable by editing one file, without the plugin
	 * writing to the database to do it.
	 */
	public function force_disabled(): void {
		$all           = $this->all();
		$all['enabled'] = false;

		$this->cache = $all;
	}

	/**
	 * Whether the plugin is switched on.
	 */
	public function is_enabled(): bool {
		return (bool) $this->get( 'enabled' );
	}

	/**
	 * Whether the plugin may change anything on the front end.
	 *
	 * This is the single gate every feature asks before acting, so `audit`
	 * really means read-only.
	 */
	public function applies(): bool {
		return $this->is_enabled() && 'apply' === $this->get( 'mode' );
	}

	/**
	 * Whether one feature is both switched on and allowed to act.
	 *
	 * @param string $key Feature switch.
	 */
	public function active( string $key ): bool {
		return $this->applies() && (bool) $this->get( $key );
	}

	/**
	 * Host of the site, without scheme, for the mail records.
	 */
	public function domain(): string {
		$configured = trim( (string) $this->get( 'mail_domain' ) );
		$source     = '' !== $configured ? $configured : (string) home_url();

		$host = wp_parse_url( false === strpos( $source, '//' ) ? 'https://' . $source : $source, PHP_URL_HOST );

		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Stores a partial update and returns the merged result.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>
	 */
	public function update( array $input ): array {
		$clean = self::sanitize( $input, $this->all() );

		update_option( self::OPTION, $clean, false );

		$this->cache = $clean;

		return $clean;
	}

	/**
	 * Validates raw input.
	 *
	 * @param array<string, mixed> $input    Raw input.
	 * @param array<string, mixed> $fallback Values to keep when a field is absent.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input, array $fallback = array() ): array {
		$defaults = array_merge( self::defaults(), $fallback );
		$clean    = array();

		$flags = array(
			'enabled',
			'force_https',
			'https_dry_run',
			'trust_proxy',
			'hsts',
			'hsts_subdomains',
			'hsts_preload',
			'headers_enabled',
			'coop',
			'strip_version',
			'remove_generator',
			'remove_rsd',
			'remove_shortlink',
			'remove_pingback',
			'remove_powered_by',
			'block_readme',
			'check_updates',
			'log_enabled',
			'delete_data',
		);

		foreach ( $flags as $flag ) {
			$value          = $input[ $flag ] ?? null;
			$clean[ $flag ] = is_scalar( $value ) ? ! empty( $value ) : (bool) $defaults[ $flag ];
		}

		$mode          = self::scalar( $input, 'mode' );
		$clean['mode'] = in_array( $mode, self::modes(), true ) ? $mode : (string) $defaults['mode'];

		$status            = (int) self::scalar( $input, 'http_status' );
		$clean['http_status'] = in_array( $status, array( 301, 302, 307, 308 ), true ) ? $status : (int) $defaults['http_status'];

		$frame                 = strtoupper( self::scalar( $input, 'frame_options' ) );
		$clean['frame_options'] = in_array( $frame, array( 'SAMEORIGIN', 'DENY', 'OFF' ), true ) ? $frame : (string) $defaults['frame_options'];

		$referrer              = self::scalar( $input, 'referrer_policy' );
		$clean['referrer_policy'] = in_array(
			$referrer,
			array( 'no-referrer', 'same-origin', 'strict-origin', 'strict-origin-when-cross-origin', 'no-referrer-when-downgrade' ),
			true
		) ? $referrer : (string) $defaults['referrer_policy'];

		$csp_mode          = self::scalar( $input, 'csp_mode' );
		$clean['csp_mode'] = in_array( $csp_mode, array( 'off', 'report-only', 'enforce' ), true ) ? $csp_mode : (string) $defaults['csp_mode'];

		$preset          = self::scalar( $input, 'csp_preset' );
		$clean['csp_preset'] = in_array( $preset, array( 'safe', 'strict', 'custom' ), true ) ? $preset : (string) $defaults['csp_preset'];

		$dmarc          = self::scalar( $input, 'dmarc_policy' );
		$clean['dmarc_policy'] = in_array( $dmarc, array( 'none', 'quarantine', 'reject' ), true ) ? $dmarc : (string) $defaults['dmarc_policy'];

		$spf_all          = self::scalar( $input, 'spf_all' );
		$clean['spf_all'] = in_array( $spf_all, array( '~all', '-all', '?all' ), true ) ? $spf_all : (string) $defaults['spf_all'];

		$clean['permissions_policy'] = self::header_value( self::scalar( $input, 'permissions_policy' ), 400 );
		$clean['csp_custom']         = self::header_value( self::scalar( $input, 'csp_custom' ), 2000 );

		$clean['trusted_proxies'] = self::proxy_list( self::scalar( $input, 'trusted_proxies' ) );

		$clean['hsts_max_age'] = self::bounded( self::scalar( $input, 'hsts_max_age' ), 0, 63072000, (int) $defaults['hsts_max_age'] );
		$clean['dmarc_pct']    = self::bounded( self::scalar( $input, 'dmarc_pct' ), 0, 100, (int) $defaults['dmarc_pct'] );

		$clean['mail_domain']   = self::domain_name( self::scalar( $input, 'mail_domain' ) );
		$clean['mail_provider'] = sanitize_key( self::scalar( $input, 'mail_provider' ) );
		$clean['spf_include']   = self::spf_source( self::scalar( $input, 'spf_include' ) );
		$clean['dmarc_report']  = self::email( self::scalar( $input, 'dmarc_report' ) );
		$clean['csp_report_uri'] = self::report_uri( self::scalar( $input, 'csp_report_uri' ) );

		return $clean;
	}

	/**
	 * A scalar input, or an empty string.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @param string               $key   Field.
	 */
	private static function scalar( array $input, string $key ): string {
		return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
	}

	/**
	 * A header value: one line, no control characters, capped.
	 *
	 * A newline in a header is a header-injection bug, so it is removed here
	 * rather than trusted at send time.
	 *
	 * @param string $value  Raw value.
	 * @param int    $length Maximum length.
	 */
	private static function header_value( string $value, int $length ): string {
		$value = (string) preg_replace( '/[\r\n\t]+/', ' ', $value );
		$value = (string) preg_replace( '/[^\x20-\x7E]/', '', $value );
		$value = trim( (string) preg_replace( '/\s{2,}/', ' ', $value ) );

		return self::truncate( $value, $length );
	}

	/**
	 * A proxy list: one address or CIDR per line.
	 *
	 * @param string $value Raw list.
	 */
	private static function proxy_list( string $value ): string {
		$kept = array();

		foreach ( preg_split( '/\R/', $value ) ?: array() as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			if ( preg_match( '#^[0-9a-fA-F:.]+(?:/\d{1,3})?$#', $line ) ) {
				$kept[] = $line;
			}
		}

		return implode( "\n", array_slice( array_unique( $kept ), 0, 50 ) );
	}

	/**
	 * A bare domain name.
	 *
	 * @param string $value Raw value.
	 */
	private static function domain_name( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$host = wp_parse_url( false === strpos( $value, '//' ) ? 'https://' . $value : $value, PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( trim( $host, '. ' ) ) : '';

		// An underscore is legal in a DNS label and required by the service
		// records an SPF source uses: `_spf.example.com` is a valid include.
		return preg_match( '/^[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?(?:\.[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?)+$/', $host ) ? $host : '';
	}

	/**
	 * An SPF source: a bare domain, or `_spf.example.com`.
	 *
	 * @param string $value Raw value.
	 */
	private static function spf_source( string $value ): string {
		$value = preg_replace( '#^include:#i', '', trim( $value ) ) ?? '';

		return self::domain_name( $value );
	}

	/**
	 * An e-mail address, or an empty string.
	 *
	 * @param string $value Raw value.
	 */
	private static function email( string $value ): string {
		$email = sanitize_email( $value );

		return is_email( $email ) ? $email : '';
	}

	/**
	 * An absolute https URL for CSP reports.
	 *
	 * @param string $value Raw value.
	 */
	private static function report_uri( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$url = esc_url_raw( $value );
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		return 'https' === $scheme ? $url : '';
	}

	/**
	 * An integer inside a range.
	 *
	 * @param string $value    Raw value.
	 * @param int    $min      Minimum.
	 * @param int    $max      Maximum.
	 * @param int    $fallback Value when the input is not a number.
	 */
	private static function bounded( string $value, int $min, int $max, int $fallback ): int {
		$number = '' === $value ? $fallback : (int) $value;

		return max( $min, min( $max, $number ) );
	}

	/**
	 * Cuts a string without relying on mbstring.
	 *
	 * @param string $value  Input.
	 * @param int    $length Maximum length.
	 */
	private static function truncate( string $value, int $length ): string {
		if ( strlen( $value ) <= $length ) {
			return $value;
		}

		return substr( $value, 0, $length );
	}

	/**
	 * Option holding the capped activity log.
	 */
	public const LOG_OPTION = 'bastion_log';

	/**
	 * How many events the log keeps.
	 */
	private const LOG_LIMIT = 30;

	/**
	 * Appends an entry to the capped activity log.
	 *
	 * The log holds no visitor data beyond the event name and a short detail:
	 * a security plugin must not become a privacy problem of its own.
	 *
	 * @param string $event  Short event key.
	 * @param string $detail Optional detail, already safe to store.
	 */
	public function log( string $event, string $detail = '' ): void {
		if ( ! $this->get( 'log_enabled' ) ) {
			return;
		}

		$log = get_option( self::LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();

		array_unshift(
			$log,
			array(
				'time'   => time(),
				'event'  => substr( $event, 0, 40 ),
				'detail' => $this->truncate( (string) preg_replace( '/[\r\n]+/', ' ', $detail ), 160 ),
				'user'   => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
			)
		);

		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_LIMIT ), false );
	}

	/**
	 * The log, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function logs(): array {
		$log = get_option( self::LOG_OPTION, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Empties the log.
	 */
	public function clear_log(): void {
		delete_option( self::LOG_OPTION );
	}

	/**
	 * Removes the short-lived transients this plugin creates.
	 */
	public function purge_transients(): void {
		global $wpdb;

		if ( ! isset( $wpdb->options ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			return;
		}

		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_bastion\_%' OR option_name LIKE '\_transient\_timeout\_bastion\_%'"
		);
	}
}
