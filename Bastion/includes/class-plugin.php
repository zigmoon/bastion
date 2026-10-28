<?php
/**
 * Plugin bootstrap.
 *
 * @package Bastion
 */

namespace Bastion;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin components together.
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Settings.
	 *
	 * @var Settings|null
	 */
	private ?Settings $settings = null;

	/**
	 * Returns the singleton.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers the runtime hooks once.
	 */
	public function boot(): void {
		if ( null !== $this->settings ) {
			return;
		}

		$this->settings = new Settings();

		// One constant, checked in one place: every component asks the settings,
		// and the settings are switched off here.
		if ( defined( 'BASTION_DISABLED' ) && BASTION_DISABLED ) {
			$this->settings->force_disabled();
		}

		( new Headers( $this->settings ) )->hooks();
		( new Https( $this->settings ) )->hooks();
		( new Disclosure( $this->settings ) )->hooks();
		( new Updater( $this->settings ) )->hooks();

		if ( function_exists( 'is_admin' ) && is_admin() ) {
			( new Admin( $this->settings, new Audit( $this->settings ) ) )->hooks();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli::register( $this->settings );
		}
	}

	/**
	 * Settings instance, for code that needs it after boot.
	 */
	public function settings(): ?Settings {
		return $this->settings;
	}

	/**
	 * Activation stores the version and arms the welcome notice.
	 *
	 * The mode is deliberately left at `audit`: installing a hardening plugin
	 * must not change a single response until someone has read the report.
	 */
	public static function activate(): void {
		update_option( Settings::VERSION_OPTION, BASTION_VERSION, false );
		update_option( Settings::WELCOME_OPTION, time(), false );
	}

	/**
	 * Deactivation removes the welcome flag and the measurements; the settings
	 * stay, so reactivating finds the site as it was left.
	 */
	public static function deactivate(): void {
		delete_option( Settings::WELCOME_OPTION );
		delete_site_transient( Audit::CACHE );
		delete_site_transient( Updater::TRANSIENT );

		( new Settings() )->purge_transients();
	}
}
