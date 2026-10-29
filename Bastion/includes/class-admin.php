<?php
/**
 * Admin: the settings screen, the audit table and the notices.
 *
 * Visual language: the same white Swiss system as the rest of the suite —
 * hairline rules, 2 px corners, one accent, Space Grotesk headings and
 * JetBrains Mono micro-labels, all embedded locally.
 *
 * @package Bastion
 */

namespace Bastion;

defined( 'ABSPATH' ) || exit;

/**
 * Everything that runs inside wp-admin.
 */
final class Admin {

	/**
	 * Where the plugin comes from.
	 */
	private const AUTHOR_SITE = 'https://2points13.fr';
	private const DEV_SITE    = 'https://zigmoon.com';
	private const DOCS_SITE   = 'https://github.com/zigmoon/bastion#readme';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Audit.
	 *
	 * @var Audit
	 */
	private Audit $audit;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param Audit    $audit    Audit.
	 */
	public function __construct( Settings $settings, Audit $audit ) {
		$this->settings = $settings;
		$this->audit    = $audit;
	}

	/**
	 * Registers the hooks.
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_bastion_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_bastion_run_audit', array( $this, 'handle_audit' ) );
		add_action( 'admin_post_bastion_report', array( $this, 'handle_report' ) );
		add_action( 'admin_post_bastion_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_post_bastion_dismiss_welcome', array( $this, 'handle_dismiss_welcome' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'plugin_action_links_' . BASTION_BASENAME, array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
	}

	/**
	 * Settings page under Settings.
	 */
	public function menu(): void {
		add_options_page(
			__( 'Bastion', 'bastion' ),
			__( 'Bastion', 'bastion' ),
			'manage_options',
			'bastion',
			array( $this, 'render' )
		);
	}

	/**
	 * Assets, on our screen only.
	 *
	 * @param string $hook Current screen hook.
	 */
	public function enqueue( string $hook ): void {
		if ( 'settings_page_bastion' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'bastion-admin', BASTION_URL . 'assets/css/admin.css', array(), BASTION_VERSION );
		wp_enqueue_script( 'bastion-admin', BASTION_URL . 'assets/js/admin.js', array(), BASTION_VERSION, true );
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array<int, string> $links Existing links.
	 * @return array<int, string>
	 */
	public function action_links( array $links ): array {
		$links[] = '<a href="' . esc_url( admin_url( 'options-general.php?page=bastion' ) ) . '">' . esc_html__( 'Settings', 'bastion' ) . '</a>';

		return $links;
	}

	/**
	 * Origin links on the Plugins row.
	 *
	 * @param array<int, string> $meta Existing meta.
	 * @param string             $file Plugin basename.
	 * @return array<int, string>
	 */
	public function row_meta( array $meta, string $file = '' ): array {
		if ( '' !== $file && BASTION_BASENAME !== $file ) {
			return $meta;
		}

		$meta[] = $this->external_link( self::AUTHOR_SITE, '2.13' );
		$meta[] = $this->external_link( self::DEV_SITE, __( 'dev by zigmoon', 'bastion' ) );
		$meta[] = $this->external_link( self::DOCS_SITE, __( 'Documentation', 'bastion' ) );

		return $meta;
	}

	/**
	 * Saves the settings.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'bastion' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'bastion_save' );

		$input = isset( $_POST['bastion'] ) && is_array( $_POST['bastion'] )
			? wp_unslash( $_POST['bastion'] )
			: array();

		$before = $this->settings->all();
		$clean  = $this->settings->update( $input );

		$this->settings->log( 'settings_saved', $before['mode'] . ' → ' . $clean['mode'] );

		// The probe is a measurement of the site; changing the policy makes it
		// stale, and a stale measurement is a lie.
		delete_site_transient( Audit::CACHE );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'bastion',
					'bastion_saved' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Runs the audit for real and comes back to the report.
	 */
	public function handle_audit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to run the audit.', 'bastion' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'bastion_run_audit' );

		$this->audit->probe( true );
		$this->settings->log( 'audit_run' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'bastion',
					'bastion_ran' => '1#bas-panel-audit',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Streams the Markdown report as a file.
	 */
	public function handle_report(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to download the report.', 'bastion' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'bastion_report' );

		$groups = $this->audit->run( true );

		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="bastion-audit-' . gmdate( 'Y-m-d' ) . '.md"' );

		echo Audit::markdown( $groups, (string) home_url() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a Markdown file, not HTML.

		exit;
	}

	/**
	 * Empties the activity log.
	 */
	public function handle_clear_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'bastion' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'bastion_clear_log' );

		$this->settings->clear_log();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'bastion',
					'bastion_saved' => 'log_cleared',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Forgets the welcome notice.
	 *
	 * WordPress's own "dismiss" only hides the notice in the browser: without a
	 * handler the same panel came back on every page load, which is the fastest
	 * way to teach someone to ignore every notice on the screen.
	 */
	public function handle_dismiss_welcome(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'bastion' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'bastion_dismiss_welcome' );

		delete_option( Settings::WELCOME_OPTION );

		$target = wp_get_referer();

		if ( ! is_string( $target ) || '' === $target ) {
			$target = admin_url( 'options-general.php?page=bastion' );
		}

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Notices.
	 */
	public function notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$on_us  = $screen && 'settings_page_bastion' === $screen->id;

		if ( isset( $_GET['bastion_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			$value = sanitize_key( wp_unslash( (string) $_GET['bastion_saved'] ) );

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				'log_cleared' === $value
					? esc_html__( 'Activity log cleared.', 'bastion' )
					: esc_html__( 'Settings saved.', 'bastion' )
			);
		}

		if ( isset( $_GET['bastion_ran'] ) && $on_us ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Audit complete: the report below was measured on the live site just now.', 'bastion' )
			);
		}

		if ( ! $on_us || ! get_option( Settings::WELCOME_OPTION ) ) {
			return;
		}
		?>
		<div class="notice notice-info bastion-welcome">
			<p>
				<strong><?php esc_html_e( 'Bastion is in audit mode.', 'bastion' ); ?></strong>
				<?php esc_html_e( 'It measures and reports; nothing on the front end has been changed. Read the report, then switch to “Apply” when the failures make sense to you.', 'bastion' ); ?>
			</p>
			<p class="bastion-welcome-actions">
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'options-general.php?page=bastion' ) ); ?>"><?php esc_html_e( 'Open the report', 'bastion' ); ?></a>
				<a class="button-link" href="<?php echo esc_url( $this->dismiss_url() ); ?>"><?php esc_html_e( 'Dismiss', 'bastion' ); ?></a>
				<span class="bastion-welcome-credit">
					<?php
					printf(
						/* translators: 1: author link, 2: developer link. */
						esc_html__( 'a plugin by %1$s, dev by %2$s', 'bastion' ),
						$this->external_link( self::AUTHOR_SITE, '2.13' ),
						$this->external_link( self::DEV_SITE, 'zigmoon' )
					);
					?>
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * The credits line: version, author, developer.
	 */
	private function credit_line(): string {
		return sprintf(
			/* translators: 1: plugin version, 2: author link, 3: developer link. */
			esc_html__( 'Bastion %1$s — a plugin by %2$s, dev by %3$s.', 'bastion' ),
			esc_html( BASTION_VERSION ),
			$this->external_link( self::AUTHOR_SITE, '2.13' ),
			$this->external_link( self::DEV_SITE, 'zigmoon' )
		);
	}

	/**
	 * A link that leaves the dashboard.
	 *
	 * @param string $url   Target.
	 * @param string $label Visible label.
	 */
	private function external_link( string $url, string $label ): string {
		return sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Renders the settings screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->settings->all();
		$applying = $this->settings->applies();
		$groups   = get_site_transient( Audit::CACHE ) ? $this->audit->run() : null;
		$dns      = new Dns( $this->settings );
		$provider = (string) $settings['mail_provider'];

		if ( '' === $provider ) {
			$detected = $dns->detect_provider();

			if ( '' !== $detected ) {
				$provider = $detected;
			}
		}

		$providers = array( '' => __( 'Choose…', 'bastion' ) );

		foreach ( Dns::providers() as $key => $data ) {
			$providers[ $key ] = $data['label'];
		}
		?>
		<div class="wrap bas-admin" data-bas-admin>
			<header class="bas-admin-head">
				<div class="bas-brand">
					<span class="bas-brand-mark" aria-hidden="true">
						<svg viewBox="0 0 64 64" width="34" height="34" focusable="false" aria-hidden="true">
							<rect width="64" height="64" rx="8" fill="#f7f8fa"/>
							<g fill="none" stroke="#0b0d10" stroke-width="4.2" stroke-linejoin="miter">
								<path d="M14 26 32 14l18 12v22H14z"/>
								<path d="M24 48V34h16v14"/>
							</g>
							<path d="M32 24v6" fill="none" stroke="#0b0d10" stroke-width="4.2"/>
						</svg>
					</span>
					<div>
						<h1>
							Bastion
							<span class="bas-state <?php echo $settings['enabled'] ? 'is-on' : 'is-off'; ?>">
								<?php echo $settings['enabled'] ? esc_html__( 'Active', 'bastion' ) : esc_html__( 'Off', 'bastion' ); ?>
							</span>
						</h1>
						<p class="bas-lead">
							<?php esc_html_e( 'HTTPS, security headers, a domain that cannot be spoofed, and a report that proves it — measured on the live site, and reversible at every step.', 'bastion' ); ?>
						</p>
					</div>
				</div>
				<p class="bas-meta">
					<?php
					printf(
						/* translators: 1: version, 2: PHP version, 3: WordPress version. */
						esc_html__( 'v%s · PHP %s+ · WordPress %s+', 'bastion' ),
						esc_html( BASTION_VERSION ),
						'8.2',
						'6.5'
					);
					?>
				</p>
			</header>

			<nav class="bas-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Bastion sections', 'bastion' ); ?>">
				<button type="button" class="bas-tab" role="tab" id="bas-tab-overview" aria-controls="bas-panel-overview" aria-selected="true" data-bas-tab="overview"><?php esc_html_e( 'Overview', 'bastion' ); ?></button>
				<button type="button" class="bas-tab" role="tab" id="bas-tab-https" aria-controls="bas-panel-https" aria-selected="false" tabindex="-1" data-bas-tab="https"><?php esc_html_e( 'HTTPS', 'bastion' ); ?></button>
				<button type="button" class="bas-tab" role="tab" id="bas-tab-headers" aria-controls="bas-panel-headers" aria-selected="false" tabindex="-1" data-bas-tab="headers"><?php esc_html_e( 'Headers', 'bastion' ); ?></button>
				<button type="button" class="bas-tab" role="tab" id="bas-tab-email" aria-controls="bas-panel-email" aria-selected="false" tabindex="-1" data-bas-tab="email"><?php esc_html_e( 'Email', 'bastion' ); ?></button>
				<button type="button" class="bas-tab" role="tab" id="bas-tab-audit" aria-controls="bas-panel-audit" aria-selected="false" tabindex="-1" data-bas-tab="audit"><?php esc_html_e( 'Audit', 'bastion' ); ?></button>
			</nav>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bas-form">
				<input type="hidden" name="action" value="bastion_save">
				<?php wp_nonce_field( 'bastion_save' ); ?>

				<div class="bas-panel" id="bas-panel-overview" role="tabpanel" aria-labelledby="bas-tab-overview" data-bas-panel="overview">
					<div class="bas-status <?php echo $applying ? 'is-on' : 'is-off'; ?>">
						<span class="bas-status-dot" aria-hidden="true"></span>
						<div>
							<strong>
								<?php echo $applying ? esc_html__( 'Applying', 'bastion' ) : esc_html__( 'Audit only', 'bastion' ); ?>
							</strong>
							<p>
								<?php
								echo $applying
									? esc_html__( 'The switches below are live on the front end.', 'bastion' )
									: esc_html__( 'Nothing is being changed: Bastion measures the site and reports. Switch to “Apply” when you are ready.', 'bastion' );
								?>
							</p>
						</div>
					</div>

					<?php
					$this->field_switch( 'enabled', __( 'Enable Bastion', 'bastion' ), (bool) $settings['enabled'], __( 'Off removes every header, every redirect and every change, without touching a single option.', 'bastion' ) );

					$this->section( __( 'Mode', 'bastion' ) );

					$this->field_select(
						'mode',
						__( 'What Bastion may do', 'bastion' ),
						(string) $settings['mode'],
						array(
							'audit' => __( 'Audit only — measure, change nothing', 'bastion' ),
							'apply' => __( 'Apply — the switches below take effect', 'bastion' ),
						),
						__( 'A hardening plugin that acts the moment it is activated is a liability. Audit mode is the default, and the audit screen shows exactly what apply would change.', 'bastion' )
					);
					?>

					<div class="bas-note">
						<strong><?php esc_html_e( 'What this plugin never does', 'bastion' ); ?></strong>
						<p><?php esc_html_e( 'It never edits wp-config.php, .htaccess or the theme. It never touches the database schema. It writes no file. Everything it does is a response header, a redirect, or a filter — and deactivating restores the previous behaviour instantly.', 'bastion' ); ?></p>
					</div>

					<?php $this->section( __( 'Last measurement', 'bastion' ) ); ?>

					<?php if ( is_array( $groups ) ) : ?>
						<?php $tally = Audit::tally( $groups ); ?>
						<div class="bas-audit-summary">
							<strong><?php echo (int) $tally[ Audit::PASS ]; ?></strong>
							<span><?php esc_html_e( 'passed', 'bastion' ); ?></span>
							<strong><?php echo (int) $tally[ Audit::FAIL ]; ?></strong>
							<span><?php esc_html_e( 'failed', 'bastion' ); ?></span>
							<strong><?php echo (int) $tally[ Audit::WARN ]; ?></strong>
							<span><?php esc_html_e( 'to review', 'bastion' ); ?></span>
						</div>
						<p class="bas-help">
							<?php esc_html_e( 'The full evidence is on the Audit tab.', 'bastion' ); ?>
						</p>
					<?php else : ?>
						<p class="bas-help"><?php esc_html_e( 'No audit has run yet. It is a read-only measurement: run it now, nothing will be changed.', 'bastion' ); ?></p>
					<?php endif; ?>

					<p>
						<a class="button" href="<?php echo esc_url( $this->audit_url() ); ?>"><?php esc_html_e( 'Run the audit now', 'bastion' ); ?></a>
					</p>

					<?php $this->section( __( 'Housekeeping', 'bastion' ) ); ?>

					<?php
					$this->field_switch( 'check_updates', __( 'Check GitHub for updates', 'bastion' ), (bool) $settings['check_updates'], __( 'Asks github.com for the latest release once every 12 hours, and shows it on the Plugins and Updates screens. It is the only outbound request the plugin makes: switch it off to keep the plugin fully offline and update by hand.', 'bastion' ) );
					$this->field_switch( 'log_enabled', __( 'Keep a small activity log', 'bastion' ), (bool) $settings['log_enabled'], __( 'The last 30 events, with no visitor address.', 'bastion' ) );
					$this->field_switch( 'delete_data', __( 'Delete all plugin data when the plugin is deleted', 'bastion' ), (bool) $settings['delete_data'], __( 'Options, log and transients are removed. The plugin does nothing until you delete it.', 'bastion' ) );
					?>

					<div class="bas-note bas-note--warn">
						<strong><?php esc_html_e( 'Emergency disable', 'bastion' ); ?></strong>
						<p><?php esc_html_e( 'Add this line to wp-config.php to stop every change without touching the database:', 'bastion' ); ?></p>
						<pre><code>define( 'BASTION_DISABLED', true );</code></pre>
						<p><?php esc_html_e( 'Or use WP-CLI:', 'bastion' ); ?></p>
						<pre><code>wp bastion disable
wp bastion audit</code></pre>
					</div>
				</div>

				<div class="bas-panel" id="bas-panel-https" role="tabpanel" aria-labelledby="bas-tab-https" data-bas-panel="https" hidden>
					<?php
					$this->section( __( 'Redirect', 'bastion' ) );

					$this->field_switch( 'force_https', __( 'Send clear-text requests to HTTPS', 'bastion' ), (bool) $settings['force_https'], __( 'Off by default. Covers every front-end page and the login screen, including the password reset. Only GET and HEAD are redirected — a POST is never touched, because a 301 would turn it into a GET and silently lose the body of the form. The REST API and admin-ajax.php answer before this hook runs, so a JSON client is never bounced through a redirect. The port is kept, so a site on 8443 stays on 8443.', 'bastion' ) );
					$this->field_switch( 'https_dry_run', __( 'Dry run: log instead of redirect', 'bastion' ), (bool) $settings['https_dry_run'], __( 'Keep this on for a day: the log shows what would have been redirected, and nothing breaks if the certificate is not ready.', 'bastion' ) );

					$this->field_select(
						'http_status',
						__( 'Status code', 'bastion' ),
						(string) $settings['http_status'],
						array(
							'301' => '301 — ' . __( 'permanent', 'bastion' ),
							'302' => '302 — ' . __( 'temporary', 'bastion' ),
							'307' => '307 — ' . __( 'permanent, keeps the method', 'bastion' ),
							'308' => '308 — ' . __( 'temporary, keeps the method', 'bastion' ),
						),
						__( '301 is what search engines expect for a definitive move to HTTPS.', 'bastion' )
					);

					$this->section( __( 'Behind a proxy', 'bastion' ) );

					$this->field_switch( 'trust_proxy', __( 'Trust reverse-proxy headers', 'bastion' ), (bool) $settings['trust_proxy'], __( 'Only when a CDN or a load balancer terminates TLS in front of the site. Without it, redirecting every request would loop for ever.', 'bastion' ) );
					?>
					<div class="bas-field bas-field--wide">
						<label for="bas-trusted_proxies"><?php esc_html_e( 'Trusted proxy addresses', 'bastion' ); ?></label>
						<textarea id="bas-trusted_proxies" name="bastion[trusted_proxies]" rows="3" aria-describedby="bas-trusted_proxies-desc"><?php echo esc_textarea( (string) $settings['trusted_proxies'] ); ?></textarea>
						<p class="bas-help" id="bas-trusted_proxies-desc"><?php esc_html_e( 'One address or CIDR range per line. The forwarded scheme is only read when the direct peer is on this list — otherwise any visitor could claim to be on HTTPS.', 'bastion' ); ?></p>
					</div>

					<?php $this->section( __( 'HSTS', 'bastion' ) ); ?>

					<div class="bas-note bas-note--warn">
						<strong><?php esc_html_e( 'Read this before switching HSTS on', 'bastion' ); ?></strong>
						<p><?php esc_html_e( 'A browser that has seen the header refuses plain HTTP for the whole max-age, even if you remove the header afterwards. Start with a short max-age, confirm the site is stable for a week, then raise it.', 'bastion' ); ?></p>
					</div>

					<?php
					$this->field_switch( 'hsts', __( 'Send Strict-Transport-Security', 'bastion' ), (bool) $settings['hsts'], __( 'Only sent over HTTPS. Off by default, and it cannot be undone quickly once browsers have seen it.', 'bastion' ) );
					$this->field_number( 'hsts_max_age', __( 'Max-age (seconds)', 'bastion' ), (int) $settings['hsts_max_age'], 0, 63072000, __( '180 days is the usual target (15552000). A first deployment can use 3600 for an hour, or 86400 for a day.', 'bastion' ) );
					$this->field_switch( 'hsts_subdomains', __( 'Include subdomains', 'bastion' ), (bool) $settings['hsts_subdomains'], __( 'Every subdomain must then serve HTTPS — including the ones you have forgotten.', 'bastion' ) );
					$this->field_switch( 'hsts_preload', __( 'Add “preload”', 'bastion' ), (bool) $settings['hsts_preload'], __( 'Only meaningful once the domain is submitted to the browser preload list. It requires “include subdomains”.', 'bastion' ) );
					?>
				</div>

				<div class="bas-panel" id="bas-panel-headers" role="tabpanel" aria-labelledby="bas-tab-headers" data-bas-panel="headers" hidden>
					<?php
					$this->field_switch( 'headers_enabled', __( 'Send security headers', 'bastion' ), (bool) $settings['headers_enabled'], __( 'Framing, MIME sniffing, referrer, permissions and the policy below.', 'bastion' ) );

					$this->section( __( 'Content-Security-Policy', 'bastion' ) );

					$this->field_select(
						'csp_mode',
						__( 'Mode', 'bastion' ),
						(string) $settings['csp_mode'],
						array(
							'off'         => __( 'Off', 'bastion' ),
							'report-only' => __( 'Report only — nothing is blocked', 'bastion' ),
							'enforce'     => __( 'Enforce', 'bastion' ),
						),
						__( 'Report-only makes the browser report what it would have blocked, without blocking it: it is the only honest way to build a policy on a site you did not write. The policy covers wp-admin too, so test it in report-only before enforcing anything strict.', 'bastion' )
					);

					$this->field_select(
						'csp_preset',
						__( 'Policy', 'bastion' ),
						(string) $settings['csp_preset'],
						array(
							'safe'   => __( 'Safe — framing, base URI, plugins, forms', 'bastion' ),
							'strict' => __( 'Strict — everything from the same origin', 'bastion' ),
							'custom' => __( 'Custom', 'bastion' ),
						),
						__( '“Safe” deliberately says nothing about scripts: it cannot break a page. “Strict” blocks every inline script and style, so keep it in report-only until the console is clean.', 'bastion' )
					);
					?>
					<div class="bas-field bas-field--wide">
						<label for="bas-csp_custom"><?php esc_html_e( 'Custom policy', 'bastion' ); ?></label>
						<textarea id="bas-csp_custom" name="bastion[csp_custom]" rows="3" aria-describedby="bas-csp_custom-desc"><?php echo esc_textarea( (string) $settings['csp_custom'] ); ?></textarea>
						<p class="bas-help" id="bas-csp_custom-desc"><?php esc_html_e( 'Used when “Custom” is chosen above. One line; report-uri is appended automatically if you set one below.', 'bastion' ); ?></p>
					</div>
					<?php
					$this->field_text( 'csp_report_uri', __( 'Report endpoint (optional)', 'bastion' ), (string) $settings['csp_report_uri'], __( 'An https URL that receives the violation reports. Leave empty to keep the reports in the browser console only.', 'bastion' ), 'https://' );

					$this->section( __( 'Framing and the rest', 'bastion' ) );

					$this->field_select(
						'frame_options',
						__( 'Anti-clickjacking', 'bastion' ),
						(string) $settings['frame_options'],
						array(
							'SAMEORIGIN' => 'X-Frame-Options: SAMEORIGIN',
							'DENY'       => 'X-Frame-Options: DENY',
							'OFF'        => __( 'Off', 'bastion' ),
						),
						__( 'The CSP frame-ancestors directive is kept consistent with this choice, so the two can never contradict each other.', 'bastion' )
					);

					$this->field_select(
						'referrer_policy',
						__( 'Referrer policy', 'bastion' ),
						(string) $settings['referrer_policy'],
						array(
							'strict-origin-when-cross-origin' => 'strict-origin-when-cross-origin',
							'same-origin'                     => 'same-origin',
							'strict-origin'                   => 'strict-origin',
							'no-referrer'                     => 'no-referrer',
							'no-referrer-when-downgrade'      => 'no-referrer-when-downgrade',
						),
						__( 'Stops a URL that contains a token from leaking to an external site through the Referer header.', 'bastion' )
					);

					$this->field_text( 'permissions_policy', __( 'Permissions-Policy', 'bastion' ), (string) $settings['permissions_policy'], __( 'Disables a browser feature for the whole site. Empty to send nothing.', 'bastion' ) );
					$this->field_switch( 'coop', __( 'Cross-Origin-Opener-Policy', 'bastion' ), (bool) $settings['coop'], __( 'Isolates the page from the windows it opens. Off by default: it breaks pop-up based sign-in (OAuth, some payment providers).', 'bastion' ) );

					$this->section( __( 'Disclosures', 'bastion' ) );

					$this->field_switch( 'remove_generator', __( 'Remove the generator meta', 'bastion' ), (bool) $settings['remove_generator'], __( 'Stops the page from announcing the WordPress version.', 'bastion' ) );
					$this->field_switch( 'strip_version', __( 'Remove the ?ver= version', 'bastion' ), (bool) $settings['strip_version'], __( 'The version is replaced by a hash of the file, so caches still refresh when a file changes.', 'bastion' ) );
					$this->field_switch( 'remove_rsd', __( 'Remove RSD and WLW manifest links', 'bastion' ), (bool) $settings['remove_rsd'], __( 'Two legacy discovery links that no current client uses.', 'bastion' ) );
					$this->field_switch( 'remove_shortlink', __( 'Remove shortlink', 'bastion' ), (bool) $settings['remove_shortlink'], __( 'Removes the shortlink tag and header.', 'bastion' ) );
					$this->field_switch( 'remove_pingback', __( 'Remove X-Pingback', 'bastion' ), (bool) $settings['remove_pingback'], __( 'Announces the XML-RPC endpoint on every response.', 'bastion' ) );
					$this->field_switch( 'remove_powered_by', __( 'Remove X-Powered-By', 'bastion' ), (bool) $settings['remove_powered_by'], __( 'Announces the PHP version. Best removed in php.ini (expose_php = Off); this covers the rest.', 'bastion' ) );
					$this->field_switch( 'block_readme', __( 'Answer 404 for readme.html', 'bastion' ), (bool) $settings['block_readme'], __( 'Works when the request reaches WordPress. A web server that serves the file directly must have it deleted or blocked in its configuration — the audit reports what actually happens. Only readme.html, license.txt and wp-config-sample.php at the root are touched.', 'bastion' ) );
					?>
				</div>

				<div class="bas-panel" id="bas-panel-email" role="tabpanel" aria-labelledby="bas-tab-email" data-bas-panel="email" hidden>
					<div class="bas-note">
						<strong><?php esc_html_e( 'A plugin cannot write DNS', 'bastion' ); ?></strong>
						<p><?php esc_html_e( 'SPF and DMARC are TXT records published at your registrar. Bastion writes the exact records for your domain, and reads back what is actually published — but you (or your host) have to paste them.', 'bastion' ); ?></p>
					</div>

					<?php
					$this->section( __( 'Domain', 'bastion' ) );

					$this->field_text( 'mail_domain', __( 'Domain', 'bastion' ), (string) $settings['mail_domain'], __( 'Leave empty to use the site host.', 'bastion' ), $dns->domain() );

					$this->field_select( 'mail_provider', __( 'Mail provider', 'bastion' ), $provider, $providers, __( 'Determines the SPF include. Pre-selected when the MX record matches a known provider.', 'bastion' ) );

					$this->field_text( 'spf_include', __( 'Custom SPF source', 'bastion' ), (string) $settings['spf_include'], __( 'Overrides the provider, for a relay that is not listed.', 'bastion' ), '_spf.example.com' );

					$this->field_select(
						'spf_all',
						__( 'End of the SPF record', 'bastion' ),
						(string) $settings['spf_all'],
						array(
							'~all' => __( '~all — soft fail (recommended to start)', 'bastion' ),
							'-all' => __( '-all — hard fail, only the listed servers may send', 'bastion' ),
							'?all' => __( '?all — neutral, no position taken', 'bastion' ),
						),
						__( 'Start with ~all, read a week of reports, then move to -all.', 'bastion' )
					);

					$this->section( __( 'DMARC', 'bastion' ) );

					$this->field_select(
						'dmarc_policy',
						__( 'Policy', 'bastion' ),
						(string) $settings['dmarc_policy'],
						array(
							'none'       => __( 'p=none — observe only', 'bastion' ),
							'quarantine' => __( 'p=quarantine — send to spam', 'bastion' ),
							'reject'     => __( 'p=reject — refuse', 'bastion' ),
						),
						__( 'p=none first, always: it collects the reports without affecting a single message. Move on once the reports show only legitimate senders.', 'bastion' )
					);

					$this->field_text( 'dmarc_report', __( 'Report address', 'bastion' ), (string) $settings['dmarc_report'], __( 'Receives the aggregate reports. A dedicated address is cleaner than a mailbox you read.', 'bastion' ), 'dmarc@' . $dns->domain() );
					$this->field_number( 'dmarc_pct', __( 'Percentage', 'bastion' ), (int) $settings['dmarc_pct'], 0, 100, __( 'Share of the messages the policy applies to. Raise it once the reports are clean.', 'bastion' ) );
					?>

					<?php $this->section( __( 'The records to publish', 'bastion' ) ); ?>

					<?php
					$spf   = $dns->spf_record();
					$dmarc = $dns->dmarc_record();
					?>
					<div class="bas-field bas-field--wide">
						<label><?php esc_html_e( 'SPF — TXT record at the domain', 'bastion' ); ?></label>
						<?php if ( '' === $spf ) : ?>
							<p class="bas-help"><?php esc_html_e( 'Choose a mail provider, or type a custom source, to generate the record.', 'bastion' ); ?></p>
						<?php else : ?>
							<code class="bas-record" data-bas-copy><?php echo esc_html( $spf ); ?></code>
							<p class="bas-help">
								<?php esc_html_e( 'Name: @ — Type: TXT — Value above. One SPF record per domain, never two.', 'bastion' ); ?>
							</p>
						<?php endif; ?>
					</div>

					<div class="bas-field bas-field--wide">
						<label>
							<?php
							printf(
								/* translators: %s: domain name. */
								esc_html__( 'DMARC — TXT record at _dmarc.%s', 'bastion' ),
								esc_html( $dns->domain() )
							);
							?>
						</label>
						<code class="bas-record" data-bas-copy><?php echo esc_html( $dmarc ); ?></code>
						<p class="bas-help"><?php esc_html_e( 'Start with p=none, read the reports for a fortnight, then move to quarantine and finally reject.', 'bastion' ); ?></p>
					</div>

					<div class="bas-note">
						<strong><?php esc_html_e( 'DKIM', 'bastion' ); ?></strong>
						<p><?php esc_html_e( 'DKIM is provider-specific: enable it in the mail provider’s console, publish the TXT record it gives you, and keep the private key where it is. No plugin can generate it for you.', 'bastion' ); ?></p>
					</div>
				</div>

				<div class="bas-panel" id="bas-panel-audit" role="tabpanel" aria-labelledby="bas-tab-audit" data-bas-panel="audit" hidden>
					<?php if ( ! is_array( $groups ) ) : ?>
						<?php $this->section( __( 'No measurement yet', 'bastion' ) ); ?>
						<p class="bas-help">
							<?php esc_html_e( 'The audit fetches the site’s own pages and reads the real response headers, then queries DNS. It changes nothing. Run it to get the evidence.', 'bastion' ); ?>
						</p>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( $this->audit_url() ); ?>"><?php esc_html_e( 'Run the audit', 'bastion' ); ?></a>
						</p>
					<?php else : ?>
						<?php
						$tally = Audit::tally( $groups );
						$titles = array(
							'https'      => __( 'HTTPS', 'bastion' ),
							'headers'    => __( 'Headers', 'bastion' ),
							'mail'       => __( 'Email', 'bastion' ),
							'disclosure' => __( 'Disclosures', 'bastion' ),
						);
						?>
						<div class="bas-audit-summary">
							<strong><?php echo (int) $tally[ Audit::PASS ]; ?></strong>
							<span><?php esc_html_e( 'passed', 'bastion' ); ?></span>
							<strong><?php echo (int) $tally[ Audit::FAIL ]; ?></strong>
							<span><?php esc_html_e( 'failed', 'bastion' ); ?></span>
							<strong><?php echo (int) $tally[ Audit::WARN ]; ?></strong>
							<span><?php esc_html_e( 'to review', 'bastion' ); ?></span>
							<span>
								<?php
								printf(
									/* translators: %s: date and time. */
									esc_html__( 'measured %s UTC', 'bastion' ),
									esc_html( gmdate( 'Y-m-d H:i', (int) ( get_site_transient( Audit::CACHE )['time'] ?? time() ) ) )
								);
								?>
							</span>
						</div>

						<p>
							<a class="button" href="<?php echo esc_url( $this->audit_url() ); ?>"><?php esc_html_e( 'Run the audit again', 'bastion' ); ?></a>
							<a class="button" href="<?php echo esc_url( $this->markdown_url() ); ?>"><?php esc_html_e( 'Download the report', 'bastion' ); ?></a>
						</p>

						<?php foreach ( $groups as $group => $rows ) : ?>
							<?php $this->section( $titles[ $group ] ?? $group ); ?>
							<table class="bas-audit">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'Check', 'bastion' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Status', 'bastion' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Evidence', 'bastion' ); ?></th>
										<th scope="col"><?php esc_html_e( 'What to do', 'bastion' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $rows as $row ) : ?>
										<tr>
											<th scope="row" class="bas-audit-check"><?php echo esc_html( $row['label'] ); ?></th>
											<td><span class="bas-pill bas-pill--<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( strtoupper( $row['status'] ) ); ?></span></td>
											<td class="bas-audit-value"><code class="bas-proof"><?php echo esc_html( $row['value'] ); ?></code></td>
											<td class="bas-audit-fix"><?php echo esc_html( $row['fix'] ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endforeach; ?>

						<?php $this->section( __( 'The same report, as text', 'bastion' ) ); ?>
						<div class="bas-field bas-field--wide">
							<label for="bas-markdown"><?php esc_html_e( 'Markdown — copy it into an e-mail, a ticket or a client report', 'bastion' ); ?></label>
							<textarea id="bas-markdown" rows="10" readonly data-bas-markdown><?php echo esc_textarea( Audit::markdown( $groups, (string) home_url() ) ); ?></textarea>
						</div>
					<?php endif; ?>

					<?php $this->section( __( 'Activity', 'bastion' ) ); ?>

					<?php $logs = $this->settings->logs(); ?>

					<?php if ( array() === $logs ) : ?>
						<p class="bas-help"><?php esc_html_e( 'Nothing logged yet. Bastion records what it did — a redirect it made, a file it blocked, a setting you saved.', 'bastion' ); ?></p>
					<?php else : ?>
						<table class="bas-audit">
							<tbody>
								<?php foreach ( array_slice( $logs, 0, 15 ) as $entry ) : ?>
									<tr>
										<td class="bas-audit-value"><code class="bas-proof"><?php echo esc_html( (string) wp_date( 'Y-m-d H:i', (int) ( $entry['time'] ?? 0 ) ) ); ?></code></td>
										<td><?php echo esc_html( (string) ( $entry['event'] ?? '' ) ); ?></td>
										<td class="bas-audit-fix"><?php echo esc_html( (string) ( $entry['detail'] ?? '' ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>

					<div class="bas-note">
						<strong><?php esc_html_e( 'Housekeeping', 'bastion' ); ?></strong>
						<p><?php esc_html_e( 'The log keeps the last 30 events, with no visitor address and no personal data.', 'bastion' ); ?></p>
					</div>
				</div>

				<div class="bas-savebar">
					<?php submit_button( __( 'Save changes', 'bastion' ), 'primary', 'submit', false ); ?>
					<span class="bas-savebar-hint"><?php esc_html_e( 'Changes apply immediately.', 'bastion' ); ?></span>
				</div>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bas-inline-form">
				<input type="hidden" name="action" value="bastion_clear_log">
				<?php wp_nonce_field( 'bastion_clear_log' ); ?>
				<?php submit_button( __( 'Clear the log', 'bastion' ), 'secondary', 'submit', false ); ?>
			</form>

			<footer class="bas-credits">
				<p><?php echo wp_kses_post( $this->credit_line() ); ?></p>
				<p class="bas-credits-links">
					<span><?php echo wp_kses_post( $this->external_link( self::AUTHOR_SITE, '2points13.fr' ) ); ?></span>
					<span><?php echo wp_kses_post( $this->external_link( self::DEV_SITE, 'zigmoon.com' ) ); ?></span>
					<span><?php echo wp_kses_post( $this->external_link( self::DOCS_SITE, __( 'Documentation', 'bastion' ) ) ); ?></span>
				</p>
			</footer>
		</div>
		<?php
	}

	/**
	 * URL that runs the audit.
	 */
	private function audit_url(): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=bastion_run_audit' ),
			'bastion_run_audit'
		);
	}

	/**
	 * URL that forgets the welcome notice.
	 */
	private function dismiss_url(): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=bastion_dismiss_welcome' ),
			'bastion_dismiss_welcome'
		);
	}

	/**
	 * URL that streams the Markdown report.
	 */
	private function markdown_url(): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=bastion_report' ),
			'bastion_report'
		);
	}

	/**
	 * A section heading with a hairline rule.
	 *
	 * @param string $title Heading.
	 */
	private function section( string $title ): void {
		printf(
			'<h2 class="bas-section"><span>%s</span></h2>',
			esc_html( $title )
		);
	}

	/**
	 * Switch field.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Label.
	 * @param bool   $checked     State.
	 * @param string $description Help text.
	 */
	private function field_switch( string $key, string $label, bool $checked, string $description = '' ): void {
		$id = 'bas-' . $key;
		?>
		<div class="bas-switch-field">
			<input type="hidden" name="bastion[<?php echo esc_attr( $key ); ?>]" value="0">
			<?php // Nested input: the label needs no `for`, or a click would toggle twice. ?>
			<label>
				<input type="checkbox" role="switch" id="<?php echo esc_attr( $id ); ?>" name="bastion[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $checked ); ?><?php echo '' !== $description ? ' aria-describedby="' . esc_attr( $id . '-desc' ) . '"' : ''; ?>>
				<span><?php echo esc_html( $label ); ?></span>
			</label>
			<?php if ( '' !== $description ) : ?>
				<p class="bas-help" id="<?php echo esc_attr( $id . '-desc' ); ?>"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Text field.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Label.
	 * @param string $value       Current value.
	 * @param string $description Help text.
	 * @param string $placeholder Placeholder.
	 */
	private function field_text( string $key, string $label, string $value, string $description = '', string $placeholder = '' ): void {
		$id = 'bas-' . $key;
		?>
		<div class="bas-field">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="text" id="<?php echo esc_attr( $id ); ?>" name="bastion[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>"<?php echo '' !== $description ? ' aria-describedby="' . esc_attr( $id . '-desc' ) . '"' : ''; ?>>
			<?php if ( '' !== $description ) : ?>
				<p class="bas-help" id="<?php echo esc_attr( $id . '-desc' ); ?>"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Number field.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Label.
	 * @param int    $value       Current value.
	 * @param int    $min         Minimum.
	 * @param int    $max         Maximum.
	 * @param string $description Help text.
	 */
	private function field_number( string $key, string $label, int $value, int $min, int $max, string $description = '' ): void {
		$id = 'bas-' . $key;
		?>
		<div class="bas-field bas-field--narrow">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="number" id="<?php echo esc_attr( $id ); ?>" name="bastion[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $value ); ?>" min="<?php echo esc_attr( (string) $min ); ?>" max="<?php echo esc_attr( (string) $max ); ?>" step="1"<?php echo '' !== $description ? ' aria-describedby="' . esc_attr( $id . '-desc' ) . '"' : ''; ?>>
			<?php if ( '' !== $description ) : ?>
				<p class="bas-help" id="<?php echo esc_attr( $id . '-desc' ); ?>"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Select field.
	 *
	 * @param string                $key         Setting key.
	 * @param string                $label       Label.
	 * @param string                $value       Current value.
	 * @param array<string, string> $options     Options.
	 * @param string                $description Help text.
	 */
	private function field_select( string $key, string $label, string $value, array $options, string $description = '' ): void {
		$id = 'bas-' . $key;
		?>
		<div class="bas-field">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<select id="<?php echo esc_attr( $id ); ?>" name="bastion[<?php echo esc_attr( $key ); ?>]"<?php echo '' !== $description ? ' aria-describedby="' . esc_attr( $id . '-desc' ) . '"' : ''; ?>>
				<?php foreach ( $options as $option => $option_label ) : ?>
					<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( (string) $value, (string) $option ); ?>><?php echo esc_html( $option_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( '' !== $description ) : ?>
				<p class="bas-help" id="<?php echo esc_attr( $id . '-desc' ); ?>"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
