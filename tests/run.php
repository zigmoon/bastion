<?php
/**
 * Unit tests: settings, headers, redirects, disclosures, DNS, audit.
 *
 * Run with: php tests/run.php
 *
 * @package Bastion
 */

require_once __DIR__ . '/bootstrap.php';

require_once BASTION_DIR . 'includes/class-settings.php';
require_once BASTION_DIR . 'includes/class-headers.php';
require_once BASTION_DIR . 'includes/class-https.php';
require_once BASTION_DIR . 'includes/class-disclosure.php';
require_once BASTION_DIR . 'includes/class-dns.php';
require_once BASTION_DIR . 'includes/class-audit.php';
require_once BASTION_DIR . 'includes/class-updater.php';

use Bastion\Audit;
use Bastion\Disclosure;
use Bastion\Dns;
use Bastion\Headers;
use Bastion\Https;
use Bastion\Settings;
use Bastion\Updater;

/**
 * A settings object with the given values, in apply mode by default.
 *
 * @param array<string, mixed> $values Overrides.
 */
function bas_settings( array $values = array() ): Settings {
	bas_reset_state();

	$GLOBALS['bas_options']['bastion_settings'] = array_merge(
		Settings::defaults(),
		array( 'mode' => 'apply' ),
		$values
	);

	return new Settings();
}

/* -------------------------------------------------------------------------
 * Defaults: nothing that can break a site is on.
 * ---------------------------------------------------------------------- */

$defaults = Settings::defaults();

bas_same( 'audit', $defaults['mode'], 'the plugin ships in audit mode' );
bas_same( false, $defaults['force_https'], 'the HTTPS redirect is off by default' );
bas_same( true, $defaults['https_dry_run'], 'and it would log first rather than redirect' );
bas_same( false, $defaults['hsts'], 'HSTS is off: a browser that saw it cannot be told to forget it' );
bas_same( 'report-only', $defaults['csp_mode'], 'CSP ships in report-only' );
bas_same( 'safe', $defaults['csp_preset'], 'and with the policy that cannot break a page' );
bas_same( true, $defaults['enabled'], 'the plugin itself is on' );
bas_same( false, $defaults['coop'], 'COOP is off: it breaks pop-up sign-in' );

$fresh = new Settings();
bas_assert( ! $fresh->applies(), 'a fresh install applies nothing' );
bas_assert( ! $fresh->active( 'force_https' ), 'audit mode gates every feature, even one switched on' );
bas_assert( $fresh->is_enabled(), 'the plugin is enabled while it audits' );

/* -------------------------------------------------------------------------
 * Sanitisation.
 * ---------------------------------------------------------------------- */

$clean = Settings::sanitize(
	array(
		'mode'          => 'APPLY',
		'http_status'   => '418',
		'frame_options' => 'deny',
		'csp_mode'      => 'nonsense',
		'hsts_max_age'  => '999999999',
		'dmarc_pct'     => '150',
		'trusted_proxies' => "10.0.0.0/8\nnot an ip\n192.168.1.1\n10.0.0.0/8",
		'mail_domain'   => 'https://Example.TEST/',
		'dmarc_report'  => 'not-an-email',
		'spf_include'   => 'include:_spf.example.com',
		'csp_custom'    => "default-src 'self';\r\nscript-src 'none';",
		'permissions_policy' => "camera=(),\n\nmicrophone=()",
	)
);

bas_same( 'audit', $clean['mode'], 'an unknown mode falls back to audit, never to apply' );
bas_same( 301, $clean['http_status'], 'an unknown status code falls back to 301' );
bas_same( 'DENY', $clean['frame_options'], 'the frame option is uppercased' );
bas_same( 'report-only', $clean['csp_mode'], 'an unknown CSP mode falls back to report-only' );
bas_same( 63072000, $clean['hsts_max_age'], 'the HSTS max-age is capped at two years' );
bas_same( 100, $clean['dmarc_pct'], 'the DMARC percentage is capped at 100' );
bas_same( "10.0.0.0/8\n192.168.1.1", $clean['trusted_proxies'], 'the proxy list keeps addresses and CIDR only, deduplicated' );
bas_same( 'example.test', $clean['mail_domain'], 'a pasted URL is reduced to its host' );
bas_same( '', $clean['dmarc_report'], 'an invalid report address is dropped' );
bas_same( '_spf.example.com', $clean['spf_include'], 'the include: prefix is removed from the SPF source' );
bas_same( "default-src 'self'; script-src 'none';", $clean['csp_custom'], 'a newline in a header value is removed, not sent' );
bas_same( 'camera=(), microphone=()', $clean['permissions_policy'], 'the permissions policy is flattened to one line' );

bas_same( 'apply', Settings::sanitize( array( 'mode' => 'apply' ) )['mode'], 'a valid mode is kept' );
bas_same( '-all', Settings::sanitize( array( 'spf_all' => '-all' ) )['spf_all'], 'a valid SPF ending is kept' );

$injection = Settings::sanitize( array( 'csp_custom' => "default-src 'self'\r\nX-Injected: 1" ) );
bas_assert( ! str_contains( $injection['csp_custom'], "\n" ), 'a CRLF injection attempt cannot survive into a header' );
bas_assert( ! str_contains( $injection['csp_custom'], 'X-Injected' ) || ! str_contains( $injection['csp_custom'], "\n" ), 'the injected header stays on one line' );

/* -------------------------------------------------------------------------
 * The gate: audit mode changes nothing.
 * ---------------------------------------------------------------------- */

$auditing = new Settings();

$settings = bas_settings( array( 'headers_enabled' => true ) );
bas_assert( $settings->active( 'headers_enabled' ), 'apply mode lets the headers through' );

$off = bas_settings( array( 'enabled' => false ) );
bas_assert( ! $off->active( 'headers_enabled' ), 'the master switch closes everything' );

$disabled = bas_settings();
$disabled->force_disabled();
bas_assert( ! $disabled->applies(), 'BASTION_DISABLED stops the plugin without writing to the database' );
bas_same( true, get_option( 'bastion_settings' )['enabled'] ?? null, 'and leaves the stored option exactly as it was' );

/* -------------------------------------------------------------------------
 * Headers.
 * ---------------------------------------------------------------------- */

$plain = Headers::build( bas_settings(), false );

bas_assert( isset( $plain['X-Content-Type-Options'] ), 'nosniff is always sent' );
bas_same( 'nosniff', $plain['X-Content-Type-Options'], 'with the only value that exists' );
bas_same( 'SAMEORIGIN', $plain['X-Frame-Options'] ?? '', 'the default framing policy is SAMEORIGIN' );
bas_assert( ! isset( $plain['Strict-Transport-Security'] ), 'HSTS is never sent over clear text' );
bas_assert( isset( $plain['Referrer-Policy'] ), 'the referrer policy is sent' );
bas_same( '0', $plain['X-XSS-Protection'] ?? '', 'the obsolete XSS filter is disabled' );
bas_assert( ! isset( $plain['Cross-Origin-Opener-Policy'] ), 'COOP stays off unless asked for' );

$secured = Headers::build( bas_settings( array( 'hsts' => true, 'headers_enabled' => true ) ), true );
bas_same( 'max-age=15552000', $secured['Strict-Transport-Security'] ?? '', 'HSTS is sent over TLS with its max-age' );

$subs = Headers::build( bas_settings( array( 'hsts' => true, 'hsts_subdomains' => true ) ), true );
bas_same( 'max-age=15552000; includeSubDomains', $subs['Strict-Transport-Security'] ?? '', 'includeSubDomains is appended when chosen' );

$preload = Headers::build( bas_settings( array( 'hsts' => true, 'hsts_subdomains' => true, 'hsts_preload' => true ) ), true );
bas_assert( str_ends_with( $preload['Strict-Transport-Security'] ?? '', 'preload' ), 'preload is only added with includeSubDomains' );

$no_preload = Headers::build( bas_settings( array( 'hsts' => true, 'hsts_preload' => true ) ), true );
bas_assert( ! str_contains( $no_preload['Strict-Transport-Security'] ?? '', 'preload' ), 'preload without subdomains would be invalid: it is dropped' );

$deny = Headers::build( bas_settings( array( 'frame_options' => 'DENY' ) ), true );
bas_same( 'DENY', $deny['X-Frame-Options'] ?? '', 'DENY is honoured' );
bas_assert( str_contains( Headers::csp( bas_settings( array( 'frame_options' => 'DENY' ) ) ), "frame-ancestors 'none'" ), 'CSP frame-ancestors follows X-Frame-Options: DENY' );

$off_frame = Headers::build( bas_settings( array( 'frame_options' => 'OFF' ) ), true );
bas_assert( ! isset( $off_frame['X-Frame-Options'] ), 'framing protection can be switched off' );
bas_assert( ! str_contains( Headers::csp( bas_settings( array( 'frame_options' => 'OFF' ) ) ), 'frame-ancestors' ), 'and the CSP directive goes with it' );

$coop = Headers::build( bas_settings( array( 'coop' => true ) ), true );
bas_same( 'same-origin', $coop['Cross-Origin-Opener-Policy'] ?? '', 'COOP is sent when asked for' );

$no_perms = Headers::build( bas_settings( array( 'permissions_policy' => '' ) ), true );
bas_assert( ! isset( $no_perms['Permissions-Policy'] ), 'an empty permissions policy sends nothing' );

// The default must not block a payment button.
$default_perms = (string) Settings::defaults()['permissions_policy'];
bas_assert( ! str_contains( $default_perms, 'payment=' ), 'the default permissions policy leaves the Payment Request API alone' );
bas_assert( str_contains( $default_perms, 'camera=()' ), 'but does disable the camera' );

/* -------------------------------------------------------------------------
 * Behind a TLS-terminating proxy: HSTS must still be sent.
 * ---------------------------------------------------------------------- */

$_SERVER = array(
	'REQUEST_METHOD'           => 'GET',
	'HTTP_HOST'                => 'example.test',
	'REQUEST_URI'              => '/',
	'REMOTE_ADDR'              => '10.0.0.5',
	'HTTP_X_FORWARDED_PROTO'   => 'https',
);

$behind_proxy = bas_settings(
	array(
		'headers_enabled' => true,
		'hsts'            => true,
		'trust_proxy'     => true,
		'trusted_proxies' => '10.0.0.0/8',
	)
);

$map = ( new Headers( $behind_proxy ) )->response_headers();
bas_assert( isset( $map['Strict-Transport-Security'] ), 'HSTS is sent when a trusted proxy terminates TLS' );

$stranger = bas_settings(
	array(
		'headers_enabled' => true,
		'hsts'            => true,
		'trust_proxy'     => true,
		'trusted_proxies' => '10.0.0.0/8',
	)
);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$map = ( new Headers( $stranger ) )->response_headers();
bas_assert( ! isset( $map['Strict-Transport-Security'] ), 'and never when the peer is not a declared proxy' );

// Removing X-Powered-By is a disclosure: it must not need the header switch.
$disclosure_only = bas_settings( array( 'headers_enabled' => false, 'remove_powered_by' => true ) );
bas_assert( ! $disclosure_only->active( 'headers_enabled' ), 'the header switch is off' );
bas_assert( $disclosure_only->active( 'remove_powered_by' ), 'yet the version can still be hidden on its own' );
bas_same( array(), ( new Headers( $disclosure_only ) )->response_headers(), 'and no header is published for it' );

$_SERVER = array();

/* -------------------------------------------------------------------------
 * CSP.
 * ---------------------------------------------------------------------- */

$safe = Headers::csp( bas_settings() );
bas_assert( str_contains( $safe, "frame-ancestors 'self'" ), 'the safe policy closes framing' );
bas_assert( str_contains( $safe, "base-uri 'self'" ), 'the safe policy blocks base-tag injection' );
bas_assert( ! str_contains( $safe, 'default-src' ), 'the safe policy says nothing about scripts: that is what makes it enforceable' );
bas_assert( ! str_contains( $safe, 'script-src' ), 'and nothing about script-src either' );
bas_assert( ! str_contains( $safe, 'object-src' ), 'the safe policy leaves <object> and <embed> alone: they show PDFs' );
bas_assert( ! str_contains( $safe, 'form-action' ), 'and leaves form targets alone: they post to payment gateways' );

$strict = Headers::csp( bas_settings( array( 'csp_preset' => 'strict' ) ) );
bas_assert( str_contains( $strict, "default-src 'self'" ), 'the strict policy falls back to the same origin' );
bas_assert( str_contains( $strict, 'upgrade-insecure-requests' ), 'the strict policy upgrades mixed content' );
bas_assert( str_contains( $strict, "object-src 'none'" ), 'the strict policy does refuse plugins' );
bas_assert( str_contains( $strict, "form-action 'self'" ), 'and does restrict form targets' );

$report = bas_settings( array( 'csp_report_uri' => 'https://example.test/csp' ) );
bas_assert( str_contains( Headers::csp( $report ), 'report-uri https://example.test/csp' ), 'the report endpoint is appended' );

$custom = Headers::csp( bas_settings( array( 'csp_preset' => 'custom', 'csp_custom' => "default-src 'self'" ) ) );
bas_same( "default-src 'self'", $custom, 'a custom policy is used verbatim' );

$headers_report = Headers::build( bas_settings( array( 'csp_mode' => 'report-only' ) ), true );
bas_assert( isset( $headers_report['Content-Security-Policy-Report-Only'] ), 'report-only uses its own header name' );
bas_assert( ! isset( $headers_report['Content-Security-Policy'] ), 'and never the enforcing one' );

$headers_enforce = Headers::build( bas_settings( array( 'csp_mode' => 'enforce' ) ), true );
bas_assert( isset( $headers_enforce['Content-Security-Policy'] ), 'enforce uses the enforcing header' );

$headers_off = Headers::build( bas_settings( array( 'csp_mode' => 'off' ) ), true );
bas_assert( ! isset( $headers_off['Content-Security-Policy'] ) && ! isset( $headers_off['Content-Security-Policy-Report-Only'] ), 'CSP off sends neither header' );

/* -------------------------------------------------------------------------
 * HTTPS: the pieces that must never loop or guess.
 * ---------------------------------------------------------------------- */

bas_same( 'https://example.test/atelier?a=1', Https::https_url( 'http://example.test/atelier?a=1', 'https://example.test' ), 'the query string survives the rewrite' );
bas_same( null, Https::https_url( 'http://evil.test/atelier', 'https://example.test' ), 'a request for another host is never redirected' );
bas_same( null, Https::https_url( 'http://example.test', '' ), 'a URL without a path is left alone' );
bas_same( 'https://example.test:8443/atelier', Https::https_url( 'http://example.test:8443/atelier', 'http://example.test:8443' ), 'a site on a non-standard port keeps it: 443 would answer nothing' );
bas_same( 'https://example.test/atelier', Https::https_url( 'http://example.test:80/atelier', 'http://example.test' ), 'port 80 is dropped, because HTTPS listens on 443' );

bas_assert( Https::in_range( '10.1.2.3', '10.0.0.0/8' ), 'an address inside its CIDR is recognised' );
bas_assert( ! Https::in_range( '11.1.2.3', '10.0.0.0/8' ), 'an address outside is not' );
bas_assert( Https::in_range( '192.168.1.7', '192.168.1.0/24' ), 'a /24 is handled' );
bas_assert( Https::in_range( '2001:db8::1', '2001:db8::/32' ), 'IPv6 works too' );
bas_assert( ! Https::in_range( '2001:db9::1', '2001:db8::/32' ), 'and an IPv6 miss is a miss' );
bas_assert( ! Https::in_range( '10.0.0.1', 'nonsense' ), 'a malformed block is refused rather than guessed' );

bas_assert( Https::is_trusted_proxy( '10.0.0.5', "10.0.0.0/8" ), 'a proxy inside the list is trusted' );
bas_assert( ! Https::is_trusted_proxy( '203.0.113.9', "10.0.0.0/8" ), 'a stranger is not' );
bas_assert( ! Https::is_trusted_proxy( '10.0.0.5', '' ), 'an empty list trusts nobody, not everybody' );

$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http, https';
bas_same( 'https', Https::forwarded_scheme(), 'the right-most forwarded scheme wins: the left one is visitor-controlled' );
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https, http';
bas_same( 'http', Https::forwarded_scheme(), 'and the other way round' );
unset( $_SERVER['HTTP_X_FORWARDED_PROTO'] );

// A proxy header from a stranger must be ignored.
$proxy = bas_settings( array( 'force_https' => true, 'https_dry_run' => false, 'trust_proxy' => true, 'trusted_proxies' => '10.0.0.0/8' ) );
$_SERVER['REMOTE_ADDR']            = '203.0.113.9';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
bas_assert( ! Https::is_secure( $proxy ), 'a forwarded scheme from an untrusted peer is ignored' );
$_SERVER['REMOTE_ADDR']            = '10.0.0.5';
bas_assert( Https::is_secure( $proxy ), 'and honoured from a trusted one' );
unset( $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['REMOTE_ADDR'] );

/* -------------------------------------------------------------------------
 * The redirect itself: off in audit, dry in apply, real only when asked.
 * ---------------------------------------------------------------------- */

$_SERVER = array( 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.test', 'REQUEST_URI' => '/atelier' );

bas_reset_state();
$never = new Https( new Settings() );
bas_same( null, $never->target(), 'audit mode does not even compute a target' );

$dry = new Https( bas_settings( array( 'force_https' => true, 'https_dry_run' => true ) ) );
bas_same( 'https://example.test/atelier', $dry->target(), 'the target is computed for the log' );
bas_same( null, $GLOBALS['bas_redirect'], 'but nothing is redirected in a dry run' );

$live = new Https( bas_settings( array( 'force_https' => true, 'https_dry_run' => false ) ) );
bas_same( 'https://example.test/atelier', $live->target(), 'and the live mode has the same target' );

$_SERVER['REQUEST_METHOD'] = 'POST';
bas_same( null, $live->target(), 'a POST is never redirected: it would lose its body' );
$_SERVER['REQUEST_METHOD'] = 'GET';

$_SERVER['HTTPS'] = 'on';
$now_secure = new Https( bas_settings( array( 'force_https' => true, 'https_dry_run' => false ) ) );
bas_same( null, $now_secure->target(), 'a request already over TLS is left alone' );
unset( $_SERVER['HTTPS'] );

/* -------------------------------------------------------------------------
 * Disclosures.
 * ---------------------------------------------------------------------- */

$file = ABSPATH . 'wp-content/test-asset.js';
$core_file = ABSPATH . 'wp-includes/js/jquery.js';
if ( ! is_dir( dirname( $file ) ) ) {
	mkdir( dirname( $file ), 0o777, true );
}
if ( ! is_dir( dirname( $core_file ) ) ) {
	mkdir( dirname( $core_file ), 0o777, true );
}
file_put_contents( $file, '/* asset */' );
file_put_contents( $core_file, '/* core asset */' );

$disclosure = new Disclosure( bas_settings( array( 'strip_version' => true ) ) );

bas_same(
	substr( (string) dechex( (int) filemtime( $file ) ), -6 ),
	Disclosure::file_stamp( 'https://example.test/wp-content/test-asset.js?ver=6.6' ),
	'the hash of the file replaces the version'
);

bas_same( '', Disclosure::file_stamp( 'https://example.test/wp-content/missing.js' ), 'a missing file has no stamp' );

$core = $disclosure->asset_src( 'https://example.test/wp-includes/js/jquery.js?ver=6.6.1' );
bas_assert( ! str_contains( $core, 'ver=6.6.1' ), 'the core version is gone from the asset URL' );
bas_assert( str_contains( $core, '?ver=' ), 'a cache-busting hash remains in its place' );

$plugin_existing = $disclosure->asset_src( 'https://example.test/wp-content/test-asset.js?ver=2.3.1' );
bas_assert( ! str_contains( $plugin_existing, 'ver=2.3.1' ), 'a plugin version is a disclosure too, and goes as well' );

$plugin = $disclosure->asset_src( 'https://example.test/wp-content/plugins/x/app.js?ver=2.3.1' );
bas_same( 'https://example.test/wp-content/plugins/x/app.js?ver=2.3.1', $plugin, 'a file outside this install is left untouched: its version may be its cache key' );

$kept = $disclosure->asset_src( 'https://example.test/wp-includes/js/a.js?ver=6.6&defer=1' );
bas_assert( str_contains( $kept, 'defer=1' ), 'the other query parameters survive' );

$off_strip = new Disclosure( bas_settings( array( 'strip_version' => false ) ) );
bas_same( 'https://example.test/x.js?ver=6.6', $off_strip->asset_src( 'https://example.test/x.js?ver=6.6' ), 'the switch turns the rewrite off' );

bas_reset_state();
$audit_disclosure = new Disclosure( new Settings() );
bas_assert( str_contains( $audit_disclosure->asset_src( 'https://example.test/wp-includes/js/jquery.js?ver=6.6' ), 'ver=6.6' ), 'audit mode does not rewrite a single URL' );

$generator = new Disclosure( bas_settings( array( 'remove_generator' => true ) ) );
bas_same( '', $generator->generator( 'WordPress 7.1.3' ), 'the generator string is emptied' );
bas_same( 'WordPress 7.1.3', $audit_disclosure->generator( 'WordPress 7.1.3' ), 'and kept in audit mode' );

$ping = $generator->headers( array( 'X-Pingback' => 'https://example.test/xmlrpc.php', 'Content-Type' => 'text/html' ) );
bas_assert( ! isset( $ping['X-Pingback'] ), 'X-Pingback is removed' );
bas_same( 'text/html', $ping['Content-Type'] ?? '', 'and the rest of the headers survive' );

/* -------------------------------------------------------------------------
 * DNS records.
 * ---------------------------------------------------------------------- */

$mail = bas_settings( array( 'mail_domain' => 'example.test', 'mail_provider' => 'google', 'spf_all' => '~all', 'dmarc_policy' => 'none', 'dmarc_report' => 'dmarc@example.test' ) );
$dns  = new Dns( $mail );

bas_same( 'v=spf1 include:_spf.google.com ~all', $dns->spf_record(), 'the SPF record matches the provider' );

$custom_spf = new Dns( bas_settings( array( 'mail_domain' => 'example.test', 'mail_provider' => 'google', 'spf_include' => '_spf.ovh.com' ) ) );
bas_same( 'v=spf1 include:_spf.ovh.com ~all', $custom_spf->spf_record(), 'a custom source overrides the provider' );

$no_source = new Dns( bas_settings( array( 'mail_domain' => 'example.test' ) ) );
bas_same( '', $no_source->spf_record(), 'without a source there is no record to publish, rather than a wrong one' );

bas_same(
	'v=DMARC1; p=none; rua=mailto:dmarc@example.test; ruf=mailto:dmarc@example.test; adkim=r; aspf=r; fo=1',
	$dns->dmarc_record(),
	'the DMARC record starts at p=none and carries the report address'
);

$reject = new Dns( bas_settings( array( 'mail_domain' => 'example.test', 'dmarc_policy' => 'reject', 'dmarc_pct' => 25 ) ) );
$record = $reject->dmarc_record();
bas_assert( str_contains( $record, 'p=reject' ), 'the policy is honoured' );
bas_assert( str_contains( $record, 'pct=25' ), 'a partial rollout is written' );
bas_assert( ! str_contains( $record, 'rua=' ), 'without an address, no rua is invented' );

bas_same( 'example.test', $dns->domain(), 'the domain comes from the settings' );

$fallback_domain = new Dns( bas_settings( array( 'mail_domain' => '' ) ) );
bas_same( 'example.test', $fallback_domain->domain(), 'and from the site host when it is left empty' );

// A published record is read back through the cached lookup, never the network.
set_site_transient( 'bastion_dns_' . md5( 'example.test|TXT' ), array( 'v=spf1 include:_spf.google.com ~all' ) );
set_site_transient( 'bastion_dns_' . md5( '_dmarc.example.test|TXT' ), array( 'v=DMARC1; p=quarantine' ) );

bas_same( 'v=spf1 include:_spf.google.com ~all', $dns->find( 'example.test', 'v=spf1' ), 'a published SPF record is found' );
bas_same( 'v=DMARC1; p=quarantine', $dns->find( '_dmarc.example.test', 'v=DMARC1' ), 'a published DMARC record is found' );
bas_same( '', $dns->find( 'nothing.test', 'v=spf1' ), 'an unpublished record is reported as missing, not guessed' );

bas_assert( isset( Dns::providers()['google'], Dns::providers()['microsoft'] ), 'the provider list is populated' );
bas_same( 'Other / self-hosted', Dns::providers()['custom']['label'], 'and always offers an escape hatch' );

/* -------------------------------------------------------------------------
 * Audit: the parsing, and a full run on a stubbed site.
 * ---------------------------------------------------------------------- */

bas_same( '', Audit::generator_of( '<html><body>nothing</body></html>' ), 'a page without a generator yields nothing' );
bas_assert( str_contains( Audit::generator_of( '<meta name="generator" content="WordPress 7.1.3">' ), 'WordPress' ), 'the generator meta is found' );

$mixed = Audit::plaintext_assets(
	'<img src="http://cdn.test/a.png"><script src="https://ok.test/b.js"></script>'
	. '<div style="background:url(http://bg.test/c.png)"></div>'
	. '<link rel="alternate" type="application/rss+xml" href="http://alt.test/feed">'
	. '<link rel="stylesheet" href="http://css.test/a.css">'
	. '<p>see http://not-a-resource.test/ for details</p>'
);
bas_same( 3, count( $mixed ), 'only fetched resources are counted: text and rel=alternate links are not mixed content' );
bas_assert( in_array( 'http://cdn.test/a.png', $mixed, true ), 'the image is caught' );
bas_assert( in_array( 'http://bg.test/c.png', $mixed, true ), 'the inline CSS url is caught' );
bas_assert( in_array( 'http://css.test/a.css', $mixed, true ), 'a stylesheet over http is caught' );
bas_assert( ! in_array( 'http://alt.test/feed', $mixed, true ), 'an http:// feed link is not' );

bas_same( '', Audit::version_query( '<link href="https://x.test/a.css?ver=hash123">' ), 'a content hash is not a version' );
bas_assert( str_contains( Audit::version_query( '<link href="https://x.test/a.css?ver=7.1.3">' ), '7.1.3' ), 'a core version is caught' );

// The header dictionary WordPress 6.2+ returns is an object: a cast to array
// exposes its private properties instead of its keys, which is how every
// header check once reported "absent".
class Bas_Dictionary {
	private array $data = array( 'Server' => 'nginx', 'X-Powered-By' => 'PHP/8.3' );

	public function getAll(): array {
		return $this->data;
	}
}

$normalised = Audit::normalise_headers( new Bas_Dictionary() );

bas_same( 'PHP/8.3', $normalised['x-powered-by'] ?? '', 'the dictionary object is read through getAll()' );
bas_same( 'nginx', $normalised['server'] ?? '', 'and every key is lowercased' );
bas_same( array( 'a' => '1, 2' ), Audit::normalise_headers( array( 'A' => array( '1', '2' ) ) ), 'a list value is joined, not lost' );
bas_same( array(), Audit::normalise_headers( null ), 'a missing header block is an empty map, not a warning' );

$stub_pages = static function ( array $overrides = array() ): void {
	$defaults = array(
		'https://example.test'             => array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '<html></html>' ),
		'http://example.test'              => array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '' ),
		'http://example.test/wp-login.php' => array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '' ),
		'https://example.test/readme.html' => array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '' ),
	);

	$GLOBALS['bas_http'] = array_merge( $defaults, $overrides );
};

$stub_pages();
$probe_settings = bas_settings( array( 'mail_domain' => 'example.test' ) );
$stub_pages();
$bad = ( new Audit( $probe_settings ) )->run( true );

bas_assert( isset( $bad['https'], $bad['headers'], $bad['mail'], $bad['disclosure'] ), 'the audit reports its four groups' );

$statuses = array();

foreach ( $bad as $rows ) {
	foreach ( $rows as $row ) {
		$statuses[ $row['id'] ] = $row['status'];
	}
}

bas_same( Audit::FAIL, $statuses['hsts'], 'a bare response fails the HSTS check' );
bas_same( Audit::FAIL, $statuses['http_redirect'], 'and the redirect check: plain HTTP answered 200' );
bas_same( Audit::FAIL, $statuses['login_redirect'], 'and the login screen check: wp-login.php answered 200 in clear text' );
bas_same( Audit::FAIL, $statuses['csp'], 'and the CSP check' );
bas_same( Audit::FAIL, $statuses['nosniff'], 'and nosniff' );
bas_same( Audit::FAIL, $statuses['readme'], 'a served readme.html is a failure' );
bas_same( Audit::PASS, $statuses['powered_by'], 'no X-Powered-By is a pass' );

$good_headers = array(
	'strict-transport-security'         => 'max-age=15552000; includeSubDomains',
	'content-security-policy'           => "default-src 'self'; frame-ancestors 'self'",
	'x-frame-options'                   => 'SAMEORIGIN',
	'x-content-type-options'            => 'nosniff',
	'referrer-policy'                   => 'strict-origin-when-cross-origin',
	'permissions-policy'                => 'camera=()',
);

$good_settings = bas_settings( array( 'mail_domain' => 'example.test' ) );

$stub_pages(
	array(
		'https://example.test'             => array( 'response' => array( 'code' => 200 ), 'headers' => $good_headers, 'body' => '<html><head></head><body></body></html>' ),
		'http://example.test'              => array( 'response' => array( 'code' => 301 ), 'headers' => array( 'location' => 'https://example.test/' ), 'body' => '' ),
		'http://example.test/wp-login.php' => array( 'response' => array( 'code' => 301 ), 'headers' => array( 'location' => 'https://example.test/wp-login.php' ), 'body' => '' ),
		'https://example.test/readme.html' => array( 'response' => array( 'code' => 404 ), 'headers' => array(), 'body' => '' ),
	)
);

set_site_transient( 'bastion_dns_' . md5( 'example.test|TXT' ), array( 'v=spf1 include:_spf.google.com -all' ) );
set_site_transient( 'bastion_dns_' . md5( '_dmarc.example.test|TXT' ), array( 'v=DMARC1; p=reject' ) );

$good  = ( new Audit( $good_settings ) )->run( true );
$green = array();

foreach ( $good as $rows ) {
	foreach ( $rows as $row ) {
		$green[ $row['id'] ] = $row['status'];
	}
}

foreach ( array( 'home_https', 'https_reachable', 'http_redirect', 'login_redirect', 'hsts', 'csp', 'xfo', 'nosniff', 'referrer', 'permissions', 'generator', 'mixed', 'readme', 'spf', 'dmarc' ) as $id ) {
	bas_same( Audit::PASS, $green[ $id ] ?? 'missing', 'the clean site passes: ' . $id );
}

$tally = Audit::tally( $good );
bas_same( 0, $tally[ Audit::FAIL ], 'a clean site has no failure' );
bas_assert( $tally[ Audit::PASS ] >= 14, 'and a good number of passes' );

// A report-only policy blocks nothing: it must not be reported as a pass.
$report_only_headers = $good_headers;
unset( $report_only_headers['content-security-policy'] );
$report_only_headers['content-security-policy-report-only'] = "frame-ancestors 'self'; base-uri 'self'";

$report_only_settings = bas_settings( array( 'mail_domain' => 'example.test' ) );
$stub_pages(
	array(
		'https://example.test'             => array( 'response' => array( 'code' => 200 ), 'headers' => $report_only_headers, 'body' => '<html><body></body></html>' ),
		'http://example.test'              => array( 'response' => array( 'code' => 301 ), 'headers' => array( 'location' => 'https://example.test/' ), 'body' => '' ),
		'https://example.test/readme.html' => array( 'response' => array( 'code' => 404 ), 'headers' => array(), 'body' => '' ),
	)
);
set_site_transient( 'bastion_dns_' . md5( 'example.test|TXT' ), array( 'v=spf1 -all' ) );
set_site_transient( 'bastion_dns_' . md5( '_dmarc.example.test|TXT' ), array( 'v=DMARC1; p=reject' ) );
delete_site_transient( Audit::CACHE );

$reporting = ( new Audit( $report_only_settings ) )->run( true );
$csp_status = '';

foreach ( $reporting['headers'] as $row ) {
	if ( 'csp' === $row['id'] ) {
		$csp_status = $row['status'];
	}
}

bas_same( Audit::WARN, $csp_status, 'a report-only policy is a warning, never a pass' );

// Mixed content cannot exist on a site that is not even on HTTPS.
$GLOBALS['bas_home']    = 'http://example.test';
$GLOBALS['bas_siteurl'] = 'http://example.test';
delete_site_transient( Audit::CACHE );

$plain_settings = bas_settings( array( 'mail_domain' => '' ) );
$GLOBALS['bas_home']    = 'http://example.test';
$GLOBALS['bas_siteurl'] = 'http://example.test';

$GLOBALS['bas_http'] = array(
	'http://example.test'              => array( 'response' => array( 'code' => 200 ), 'headers' => $good_headers, 'body' => '<html><body><img src="http://example.test/a.png"></body></html>' ),
	'https://example.test'             => new WP_Error( 'http_request_failed', 'no TLS here' ),
	'http://example.test/readme.html'  => array( 'response' => array( 'code' => 404 ), 'headers' => array(), 'body' => '' ),
);

$plain      = ( new Audit( $plain_settings ) )->run( true );
$mixed_status = '';

foreach ( $plain['disclosure'] as $row ) {
	if ( 'mixed' === $row['id'] ) {
		$mixed_status = $row['status'];
	}
}

bas_same( Audit::WARN, $mixed_status, 'mixed content is not a failure on a site that is served over HTTP' );

$GLOBALS['bas_home']    = 'https://example.test';
$GLOBALS['bas_siteurl'] = 'https://example.test';

// The probe is cached: a second run must not fetch again.
$calls = count( $GLOBALS['bas_http_calls'] );
( new Audit( $good_settings ) )->run( false );
bas_same( $calls, count( $GLOBALS['bas_http_calls'] ), 'the measurement is cached, so opening the screen does not hammer the site' );

// An unreachable site must warn, never pretend.
$offline_settings = bas_settings( array( 'mail_domain' => '' ) );
$GLOBALS['bas_http'] = array();
delete_site_transient( Audit::CACHE );
$offline = ( new Audit( $offline_settings ) )->run( true );
$flat    = array();

foreach ( $offline as $rows ) {
	foreach ( $rows as $row ) {
		$flat[ $row['id'] ] = $row['status'];
	}
}

bas_assert( in_array( $flat['https_reachable'], array( Audit::WARN ), true ), 'an unreachable site is a warning, not a pass' );
bas_assert( in_array( $flat['csp'], array( Audit::WARN, Audit::FAIL ), true ), 'and the header checks cannot pass without an answer' );

// The honesty rule: an unmeasurable check must never look green.
foreach ( array( 'powered_by', 'generator', 'ver', 'mixed', 'readme' ) as $id ) {
	bas_same( Audit::WARN, $flat[ $id ] ?? 'missing', 'an unreachable site cannot claim to pass: ' . $id );
}

$markdown = Audit::markdown( $good, 'https://example.test' );
bas_assert( str_contains( $markdown, '# ' ), 'the report is Markdown' );
bas_assert( str_contains( $markdown, '| PASS |' ), 'and carries the evidence' );
bas_assert( str_contains( $markdown, 'https://example.test' ), 'and names the site' );

/* -------------------------------------------------------------------------
 * Log.
 * ---------------------------------------------------------------------- */

$logger = bas_settings();
$logger->log( 'https_redirect_test', 'https://example.test/atelier' );

$logs = $logger->logs();
bas_same( 1, count( $logs ), 'the event is recorded' );
bas_same( 'https_redirect_test', $logs[0]['event'] ?? '', 'with its name' );
bas_assert( ! isset( $logs[0]['ip'] ), 'and no visitor address: a security plugin must not become a privacy problem' );

// A redirect event fires on every request: one row a minute is enough.
$logger->log( 'https_redirect_test', 'https://example.test/atelier' );
bas_same( 1, count( $logger->logs() ), 'a repeated redirect event is throttled: no database write per page view' );

$logger->log( 'https_redirect_test', 'https://example.test/autre' );
bas_same( 2, count( $logger->logs() ), 'but a different URL is still recorded' );

$logger->log( 'settings_saved', 'audit → apply' );
bas_same( 3, count( $logger->logs() ), 'an ordinary event is never throttled' );

for ( $i = 0; $i < 40; $i++ ) {
	$logger->log( 'filler_' . $i );
}

bas_same( 30, count( $logger->logs() ), 'the log is capped' );
bas_same( 'filler_39', $logger->logs()[0]['event'] ?? '', 'newest first' );

$logger->clear_log();
bas_same( array(), $logger->logs(), 'and can be emptied' );

$quiet = bas_settings( array( 'log_enabled' => false ) );
$quiet->log( 'should_not_appear' );
bas_same( array(), $quiet->logs(), 'the log switch is honoured' );

/* -------------------------------------------------------------------------
 * The updater: what a live site is told, and what it is never told.
 * ---------------------------------------------------------------------- */

bas_same( '1.0.1', Updater::normalise_version( 'v1.0.1' ), 'a tag with a leading v reads as the same version' );
bas_same( '2.3', Updater::normalise_version( 'release-2.3' ), 'digits are found wherever the tag puts them' );
bas_same( '', Updater::normalise_version( 'nightly' ), 'a tag with no digits is not a version' );

$assets = array(
	array( 'name' => 'Bastion-1.1.0-projet-complet.zip', 'browser_download_url' => 'https://example.test/complete.zip' ),
	array( 'name' => 'Bastion-1.1.0.zip', 'browser_download_url' => 'https://example.test/plugin.zip' ),
	array( 'name' => 'Bastion-1.0.0.zip', 'browser_download_url' => 'https://example.test/old.zip' ),
	array( 'name' => 'notes.txt', 'browser_download_url' => 'https://example.test/notes.txt' ),
	array( 'name' => 'Other-1.1.0.zip', 'browser_download_url' => 'https://example.test/other.zip' ),
);

bas_same( 'https://example.test/plugin.zip', Updater::pick_asset( $assets, '1.1.0' ), 'the archive of the release wins, not the whole-repository one' );
bas_same( 'https://example.test/plugin.zip', Updater::pick_asset( $assets ), 'and the first plugin archive is the fallback when no version is asked for' );
bas_same( '', Updater::pick_asset( array( $assets[0] ), '1.1.0' ), 'a whole-repository archive alone is not installable' );
bas_same( 'https://example.test/old.zip', Updater::pick_asset( array( $assets[0], $assets[2] ), '2.0.0' ), 'an older plugin archive is still usable as the fallback' );
bas_same( '', Updater::pick_asset( array(), '1.1.0' ), 'no asset means no update' );

$payload = array(
	'tag_name'     => 'v1.1.0',
	'html_url'     => 'https://github.com/zigmoon/bastion/releases/tag/v1.1.0',
	'published_at' => '2026-10-29T00:00:00Z',
	'body'         => "## [1.1.0] — 2026-10-29\n\nFixes found by re-reading the plugin.\n\n### Fixed\n\n- **The login screen** was not covered: `wp-login.php` never fires the hook.\n",
	'assets'       => $assets,
);

$release = Updater::parse_release( $payload );

bas_same( '1.1.0', $release['version'] ?? '', 'the release is read' );
bas_same( 'https://example.test/plugin.zip', $release['package'] ?? '', 'with an installable package' );
bas_same( 'Fixes found by re-reading the plugin.', $release['notice'] ?? '', 'the upgrade notice is the first prose line, not a heading' );

$markup = $release['changelog'] ?? '';
bas_assert( str_contains( $markup, '<h4>Fixed</h4>' ), 'a Markdown heading becomes a heading in the modal' );
bas_assert( ! str_contains( $markup, '1.1.0 — 2026' ), 'the version heading is dropped: the modal already prints it' );
bas_assert( str_contains( $markup, '<strong>The login screen</strong>' ), 'bold survives as bold' );
bas_assert( str_contains( $markup, '<code>wp-login.php</code>' ), 'and inline code as code' );
bas_assert( ! str_contains( $markup, '**' ) && ! str_contains( $markup, '`' ), 'no raw Markdown reaches the screen' );
bas_assert( str_contains( $markup, '<ul><li>' ), 'a run of bullets becomes a list' );
bas_assert( ! str_contains( $markup, '<script>' ), 'nothing is passed through unescaped' );

bas_same( null, Updater::parse_release( array_merge( $payload, array( 'draft' => true ) ) ), 'a draft is never offered' );
bas_same( null, Updater::parse_release( array_merge( $payload, array( 'prerelease' => true ) ) ), 'nor a pre-release' );
bas_same( null, Updater::parse_release( array_merge( $payload, array( 'assets' => array() ) ) ), 'nor a release with nothing to install' );
bas_same( null, Updater::parse_release( array( 'assets' => $assets ) ), 'and a payload without a tag is refused' );

bas_same( '<p>&lt;img src=x onerror=alert(1)&gt;</p>', Updater::changelog_markup( '<img src=x onerror=alert(1)>' ), 'a release body is escaped, not rendered' );

/* -------------------------------------------------------------------------
 * Report.
 * ---------------------------------------------------------------------- */

echo "\n" . $GLOBALS['bas_checked'] . ' passed, ' . $GLOBALS['bas_failed'] . " failed\n";

exit( $GLOBALS['bas_failed'] > 0 ? 1 : 0 );
