<?php
/**
 * SPF and DMARC: the records, and the check that they exist.
 *
 * @package Bastion
 */

namespace Bastion;

defined( 'ABSPATH' ) || exit;

/**
 * Produces the DNS records that stop a domain from being spoofed, and reads
 * back what is actually published.
 *
 * A WordPress plugin cannot write DNS. Anyone claiming otherwise is selling
 * something. What it can do — and what this class does — is write the exact
 * records for the domain, ready to paste, and then verify the published answer
 * through a real DNS query so the audit shows a fact rather than an intention.
 */
final class Dns {

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
	 * Known providers, with the SPF source each one needs.
	 *
	 * Detection from the MX record is a hint, never a certainty: a domain can
	 * send through a relay that has nothing to do with its MX.
	 *
	 * @return array<string, array{label: string, spf: string, mx: array<int, string>}>
	 */
	public static function providers(): array {
		return array(
			'google'   => array(
				'label' => 'Google Workspace / Gmail',
				'spf'   => '_spf.google.com',
				'mx'    => array( 'google.com', 'googlemail.com' ),
			),
			'microsoft' => array(
				'label' => 'Microsoft 365 / Outlook',
				'spf'   => 'spf.protection.outlook.com',
				'mx'    => array( 'outlook.com', 'protection.outlook.com' ),
			),
			'ovh'      => array(
				'label' => 'OVH',
				'spf'   => 'mx.ovh.com',
				'mx'    => array( 'ovh.net', 'ovh.com' ),
			),
			'o2switch' => array(
				'label' => 'o2switch',
				'spf'   => 'o2switch.net',
				'mx'    => array( 'o2switch.net' ),
			),
			'gandi'    => array(
				'label' => 'Gandi',
				'spf'   => 'spool.mail.gandi.net',
				'mx'    => array( 'gandi.net' ),
			),
			'zoho'     => array(
				'label' => 'Zoho',
				'spf'   => 'zoho.com',
				'mx'    => array( 'zoho.com', 'zoho.eu' ),
			),
			'proton'   => array(
				'label' => 'Proton Mail',
				'spf'   => 'protonmail.ch',
				'mx'    => array( 'protonmail.ch', 'proton.me' ),
			),
			'infomaniak' => array(
				'label' => 'Infomaniak',
				'spf'   => 'spf.infomaniak.ch',
				'mx'    => array( 'infomaniak.ch' ),
			),
			'custom'   => array(
				'label' => 'Other / self-hosted',
				'spf'   => '',
				'mx'    => array(),
			),
		);
	}

	/**
	 * The SPF TXT record for the domain.
	 *
	 * @return string Empty when no source is configured.
	 */
	public function spf_record(): string {
		$source = $this->source();

		if ( '' === $source ) {
			return '';
		}

		return 'v=spf1 include:' . $source . ' ' . $this->settings->get( 'spf_all' );
	}

	/**
	 * The DMARC TXT record, at `_dmarc.<domain>`.
	 */
	public function dmarc_record(): string {
		$record = 'v=DMARC1; p=' . $this->settings->get( 'dmarc_policy' );

		$pct = (int) $this->settings->get( 'dmarc_pct' );

		if ( 100 !== $pct ) {
			$record .= '; pct=' . $pct;
		}

		$report = (string) $this->settings->get( 'dmarc_report' );

		if ( '' !== $report ) {
			$record .= '; rua=mailto:' . $report;
			$record .= '; ruf=mailto:' . $report;
		}

		// `adkim`/`aspf` relaxed are the defaults; spelling them out makes the
		// record readable to whoever has to edit it later.
		return $record . '; adkim=r; aspf=r; fo=1';
	}

	/**
	 * The SPF source for the configured provider, or the custom one.
	 */
	public function source(): string {
		$custom = (string) $this->settings->get( 'spf_include' );

		if ( '' !== $custom ) {
			return $custom;
		}

		$provider = (string) $this->settings->get( 'mail_provider' );
		$known    = self::providers();

		return isset( $known[ $provider ] ) ? (string) $known[ $provider ]['spf'] : '';
	}

	/**
	 * The domain the records are written for.
	 */
	public function domain(): string {
		return $this->settings->domain();
	}

	/**
	 * The TXT records published for a name.
	 *
	 * Uses `dns_get_record()` when the host allows it, then falls back to the
	 * resolver behind `checkdnsrr()` — shared hosting often disables one and
	 * not the other.
	 *
	 * @param string $name Fully qualified name.
	 * @param string $type Record type.
	 * @return array<int, string>
	 */
	public function lookup( string $name, string $type = 'TXT' ): array {
		$name = rtrim( $name, '.' );

		if ( '' === $name ) {
			return array();
		}

		$cache = get_site_transient( 'bastion_dns_' . md5( $name . '|' . $type ) );

		if ( is_array( $cache ) ) {
			return $cache;
		}

		$values = array();

		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $name, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a resolver miss is an expected answer.

			foreach ( is_array( $records ) ? $records : array() as $record ) {
				if ( isset( $record['txt'] ) && is_string( $record['txt'] ) ) {
					$values[] = $record['txt'];
				} elseif ( isset( $record['entries'] ) && is_array( $record['entries'] ) ) {
					$values[] = implode( '', $record['entries'] );
				}
			}
		}

		if ( array() === $values && function_exists( 'checkdnsrr' ) ) {
			// Only tells presence, not content: good enough to say "a TXT record
			// exists here" when the content cannot be read.
			if ( @checkdnsrr( $name, 'TXT' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$values[] = '?';
			}
		}

		$values = array_values( array_unique( array_filter( $values, 'is_string' ) ) );

		set_site_transient( 'bastion_dns_' . md5( $name . '|' . $type ), $values, 6 * HOUR_IN_SECONDS );

		return $values;
	}

	/**
	 * The TXT record starting with a marker, if any.
	 *
	 * @param string $name   Name.
	 * @param string $prefix Marker, e.g. `v=spf1`.
	 */
	public function find( string $name, string $prefix ): string {
		foreach ( $this->lookup( $name ) as $value ) {
			if ( 0 === stripos( trim( $value ), $prefix ) ) {
				return trim( $value );
			}
		}

		return '';
	}

	/**
	 * The mail exchangers of the domain, used to guess the provider.
	 *
	 * @return array<int, string>
	 */
	public function mx_hosts(): array {
		$domain = $this->domain();

		if ( '' === $domain || ! function_exists( 'dns_get_record' ) ) {
			return array();
		}

		$cache = get_site_transient( 'bastion_mx_' . md5( $domain ) );

		if ( is_array( $cache ) ) {
			return $cache;
		}

		$hosts   = array();
		$records = @dns_get_record( $domain, DNS_MX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		foreach ( is_array( $records ) ? $records : array() as $record ) {
			if ( isset( $record['target'] ) && is_string( $record['target'] ) ) {
				$hosts[] = strtolower( rtrim( $record['target'], '.' ) );
			}
		}

		set_site_transient( 'bastion_mx_' . md5( $domain ), $hosts, 6 * HOUR_IN_SECONDS );

		return $hosts;
	}

	/**
	 * The provider that matches the MX records, or an empty string.
	 */
	public function detect_provider(): string {
		$hosts = $this->mx_hosts();

		foreach ( self::providers() as $key => $provider ) {
			foreach ( $provider['mx'] as $needle ) {
				foreach ( $hosts as $host ) {
					if ( str_contains( $host, $needle ) ) {
						return $key;
					}
				}
			}
		}

		return '';
	}
}
