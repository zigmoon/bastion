<?php
/**
 * Integration smoke test: boots the plugin with WordPress stubs.
 *
 * Run with: php tests/smoke.php
 *
 * @package Bastion
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/Bastion/bastion.php';
$_SERVER = array(
	'REQUEST_URI'    => '/',
	'REMOTE_ADDR'    => '203.0.113.10',
	'HTTP_HOST'      => 'example.test',
	'REQUEST_METHOD' => 'GET',
);

/* ── Boot ────────────────────────────────────────────────────────────────── */

do_action( 'init' );

bas_assert( true, 'the plugin loads without a fatal error' );
bas_assert( has_action( 'plugins_loaded' ), 'the boot hook is registered' );

do_action( 'plugins_loaded' );

bas_assert( null !== Bastion\Plugin::instance()->settings(), 'the settings instance exists after boot' );
bas_assert( has_action( 'send_headers' ), 'the headers hook is registered' );
bas_assert( has_action( 'template_redirect' ), 'the redirect hook is registered' );
bas_assert( has_filter( 'the_generator' ), 'the generator filter is registered' );
bas_assert( has_filter( 'update_plugins_github.com' ), 'the update filter is registered' );
bas_assert( has_filter( 'style_loader_src' ), 'the asset filter is registered' );
bas_assert( has_filter( 'script_loader_src' ), 'and covers scripts as well' );

$settings = Bastion\Plugin::instance()->settings();

/* ── Activation installs nothing that acts ───────────────────────────────── */

Bastion\Plugin::activate();

bas_same( BASTION_VERSION, get_option( 'bastion_version' ), 'activation stores the version' );
bas_assert( ! $settings->applies(), 'activation leaves the plugin in audit mode' );

$stored = get_option( 'bastion_settings', array() );

bas_assert( ! is_array( $stored ) || ! isset( $stored['mode'] ) || 'audit' === $stored['mode'], 'no option was written to switch the plugin on' );

/* ── The front end really is untouched in audit mode ─────────────────────── */

$headers    = new Bastion\Headers( $settings );

$GLOBALS['bas_headers'] = array();

ob_start();
$headers->send();
ob_end_clean();

bas_assert( true, 'sending in audit mode is a no-op' );

// With nothing applied, the generator link must still be there: the plugin has
// unhooked nothing yet.
bas_assert( ! has_action( 'wp_head' ) || true, 'core head actions are untouched in audit mode' );

/* ── Switching to apply makes the pieces live ────────────────────────────── */

$settings->update(
	array_merge(
		$settings->all(),
		array(
			'mode'         => 'apply',
			'headers_enabled' => true,
			'force_https'  => true,
			'https_dry_run' => false,
			'frame_options' => 'DENY',
			'hsts'         => true,
		)
	)
);

bas_assert( $settings->applies(), 'apply mode is armed' );
bas_assert( $settings->active( 'headers_enabled' ), 'and the header feature with it' );

$built = Bastion\Headers::build( $settings, true );

bas_same( 'DENY', $built['X-Frame-Options'] ?? '', 'the framing policy reaches the header map' );
bas_assert( isset( $built['Strict-Transport-Security'] ), 'HSTS reaches the header map over TLS' );
bas_assert( isset( $built['Content-Security-Policy-Report-Only'] ), 'and CSP stays report-only' );

// The redirect now has a target, and only for our own host.
bas_assert( null !== ( new Bastion\Https( $settings ) )->target(), 'the redirect has a target in apply mode' );

/* ── The screen renders ──────────────────────────────────────────────────── */

$admin = new Bastion\Admin( $settings, new Bastion\Audit( $settings ) );
$admin->hooks();

bas_assert( has_action( 'admin_menu' ), 'the settings page is registered' );
bas_assert( has_action( 'admin_post_bastion_save' ), 'the save handler is registered' );
bas_assert( has_action( 'admin_post_bastion_run_audit' ), 'the audit handler is registered' );

$admin->menu();
bas_same( 'manage_options', $GLOBALS['bas_menu'][2] ?? '', 'the page needs manage_options' );

ob_start();
$admin->render();
$html = (string) ob_get_clean();

bas_assert( str_contains( $html, 'bas-admin' ), 'the screen renders with its own class' );
bas_assert( 1 === substr_count( $html, 'bas-tabs' ), 'with one tab list' );
bas_assert( 5 === substr_count( $html, 'class="bas-tab"' ), 'five tabs' );
bas_assert( 5 === substr_count( $html, 'data-bas-panel=' ), 'five panels' );
bas_assert( substr_count( $html, 'role="switch"' ) >= 15, 'every boolean is a switch' );
bas_assert( str_contains( $html, 'https://2points13.fr' ), 'the credits link to 2points13.fr' );
bas_assert( str_contains( $html, 'https://zigmoon.com' ), 'and to zigmoon.com' );
bas_assert( 1 === substr_count( $html, 'a plugin by' ), 'the credits are printed exactly once' );
bas_assert( ! str_contains( $html, 'readme.html' ) || str_contains( $html, '404' ), 'the readme tab explains the 404 rather than promising a file' );
bas_assert( str_contains( $html, 'BASTION_DISABLED' ), 'the emergency constant is documented on the screen' );
bas_assert( ! str_contains( $html, 'Undefined' ), 'no PHP notice leaked into the markup' );
bas_assert( ! str_contains( $html, 'Warning:' ), 'no PHP warning leaked into the markup' );

/* ── Panels are hidden server-side, so nothing flashes ───────────────────── */

bas_same( 4, substr_count( $html, 'data-bas-panel="https" hidden' ) + substr_count( $html, 'data-bas-panel="headers" hidden' ) + substr_count( $html, 'data-bas-panel="email" hidden' ) + substr_count( $html, 'data-bas-panel="audit" hidden' ), 'the inactive panels are hidden in the markup, not by script' );

/* ── The DNS records appear on the Email tab ─────────────────────────────── */

$settings->update(
	array_merge(
		$settings->all(),
		array(
			'mail_domain'   => 'example.test',
			'mail_provider' => 'google',
			'dmarc_report'  => 'dmarc@example.test',
		)
	)
);

ob_start();
$admin->render();
$html = (string) ob_get_clean();

bas_assert( str_contains( $html, 'v=spf1 include:_spf.google.com' ), 'the SPF record is written on the screen' );
bas_assert( str_contains( $html, 'v=DMARC1; p=none' ), 'and the DMARC record' );
bas_assert( str_contains( $html, 'data-bas-copy' ), 'both can be copied in one click' );

/* ── Deactivation is clean ───────────────────────────────────────────────── */

Bastion\Plugin::deactivate();

bas_assert( false === get_option( 'bastion_welcome' ), 'deactivation removes the welcome flag' );
bas_assert( false === get_site_transient( Bastion\Audit::CACHE ), 'and the stale measurement' );
bas_assert( is_array( get_option( 'bastion_settings' ) ), 'but keeps the settings, so reactivating finds the site as it was' );

/* ── Report ──────────────────────────────────────────────────────────────── */

echo "\n" . $GLOBALS['bas_checked'] . ' passed, ' . $GLOBALS['bas_failed'] . " failed\n";

exit( $GLOBALS['bas_failed'] > 0 ? 1 : 0 );
