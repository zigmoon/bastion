<?php
/**
 * WP-CLI commands.
 *
 * @package Bastion
 */

namespace Bastion;

defined( 'ABSPATH' ) || exit;

/**
 * `wp bastion` — the audit and the switches, for a terminal.
 *
 * The audit is the interesting one: it prints the same evidence as the screen,
 * which is what makes it usable from a deploy script or a monitoring job.
 */
final class Cli {

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
	 * Registers the commands.
	 *
	 * Explicit methods, no `__invoke()`: WP-CLI registers an object with
	 * `__invoke()` as a single command and ignores every other public method.
	 *
	 * @param Settings $settings Settings.
	 */
	public static function register( Settings $settings ): void {
		\WP_CLI::add_command( 'bastion', new self( $settings ) );
	}

	/**
	 * Prints the audit.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : `table`, `json` or `markdown`. Default: table.
	 *
	 * [--fresh]
	 * : Ignore the cached measurement and fetch the site again.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bastion audit
	 *     wp bastion audit --format=markdown > audit.md
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function audit( array $args, array $assoc_args ): void {
		$audit = new Audit( $this->settings );
		$rows  = $audit->run( ! empty( $assoc_args['fresh'] ) );
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		if ( 'markdown' === $format ) {
			\WP_CLI::line( Audit::markdown( $rows, (string) home_url() ) );
		} elseif ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $rows ) );
		} else {
			$flat = array();

			foreach ( $rows as $group => $group_rows ) {
				foreach ( $group_rows as $row ) {
					$flat[] = array(
						'group'    => $group,
						'check'    => $row['id'],
						'status'   => $row['status'],
						'evidence' => $row['value'],
					);
				}
			}

			\WP_CLI\Utils\format_items( 'table', $flat, array( 'group', 'check', 'status', 'evidence' ) );
		}

		$tally = Audit::tally( $rows );

		\WP_CLI::line();
		\WP_CLI::line(
			sprintf(
				'%d passed, %d warnings, %d failed',
				$tally[ Audit::PASS ],
				$tally[ Audit::WARN ],
				$tally[ Audit::FAIL ]
			)
		);

		if ( $tally[ Audit::FAIL ] > 0 ) {
			\WP_CLI::warning( 'Some checks failed: see the evidence above.' );
		}
	}

	/**
	 * Prints the settings.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bastion settings
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function settings( array $args, array $assoc_args ): void {
		$rows = array();

		foreach ( $this->settings->all() as $key => $value ) {
			$rows[] = array(
				'setting' => $key,
				'value'   => is_array( $value ) ? implode( ', ', $value ) : ( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value ),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'setting', 'value' ) );
	}

	/**
	 * Switches to apply mode.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bastion enable
	 */
	public function enable(): void {
		$this->set( array( 'enabled' => true, 'mode' => 'apply' ) );
	}

	/**
	 * Turns every change off, keeping the settings.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bastion disable
	 */
	public function disable(): void {
		$this->set( array( 'enabled' => false ) );
	}

	/**
	 * Returns to read-only mode.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bastion audit-only
	 */
	public function audit_only(): void {
		$this->set( array( 'enabled' => true, 'mode' => 'audit' ) );
	}

	/**
	 * Writes a partial update.
	 *
	 * @param array<string, mixed> $values Values.
	 */
	private function set( array $values ): void {
		$this->settings->update( array_merge( $this->settings->all(), $values ) );
		$this->settings->log( 'cli_change', (string) wp_json_encode( $values ) );

		delete_site_transient( Audit::CACHE );

		\WP_CLI::success( 'Settings updated. Run `wp bastion audit` to measure the result.' );
	}
}
