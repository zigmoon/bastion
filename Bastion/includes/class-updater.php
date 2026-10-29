<?php
/**
 * Update checks against the GitHub releases.
 *
 * @package Bastion
 */

namespace Bastion;

defined( 'ABSPATH' ) || exit;

/**
 * Teaches the Plugins and Updates screens about the GitHub releases.
 *
 * WordPress only ever asks api.wordpress.org for plugin updates. Since 5.8 it
 * also accepts plugins that bring their own answer: the `Update URI` header
 * makes core skip the directory for that plugin and call the filter
 * `update_plugins_{hostname}` instead, where we return the latest release.
 *
 * The answer is cached in a site transient so the dashboard does not call
 * github.com on every page load, and a failure is cached too — a site with no
 * outbound connection must not pay a 10 second timeout on every request.
 */
final class Updater {

	/**
	 * Plugin slug, as used by the Plugins screen and the transition keys.
	 */
	public const SLUG = 'bastion';

	/**
	 * Repository, as owner/name.
	 */
	public const REPO = 'zigmoon/bastion';

	/**
	 * Site transient holding the last answer.
	 */
	public const TRANSIENT = 'bastion_release';

	/**
	 * Seconds a successful answer stays fresh.
	 */
	private const TTL = 43200;

	/**
	 * Seconds a failure stays fresh.
	 */
	private const TTL_FAILURE = 3600;

	/**
	 * Settings, for the "check for updates" switch.
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
	 * Registers the hooks. Runs on every request, including cron: the update
	 * check is done by a scheduled event, not by the dashboard only.
	 */
	public function hooks(): void {
		add_filter( 'update_plugins_github.com', array( $this, 'inject' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'flush' ), 10, 2 );
	}

	/**
	 * Answers `update_plugins_github.com` for this plugin only.
	 *
	 * @param array<string, mixed>|false $update      Update data, false by default.
	 * @param array<string, string>      $plugin_data Plugin headers.
	 * @param string                     $plugin_file Plugin basename.
	 * @return array<string, mixed>|false
	 */
	public function inject( $update, $plugin_data, $plugin_file ) {
		if ( BASTION_BASENAME !== $plugin_file || ! is_array( $plugin_data ) ) {
			return $update;
		}

		if ( ! $this->settings->get( 'check_updates' ) ) {
			return $update;
		}

		$release = $this->release();

		if ( null === $release ) {
			return $update;
		}

		// `tested` and `requires_php` come from the headers WordPress already
		// read, so the plugin never states them twice.
		return array(
			'slug'           => self::SLUG,
			'version'        => $release['version'],
			'url'            => $release['url'],
			'package'        => $release['package'],
			'tested'         => (string) ( $plugin_data['Tested up to'] ?? '' ),
			'requires_php'   => (string) ( $plugin_data['Requires PHP'] ?? '' ),
			'icons'          => array(
				'1x'  => 'https://raw.githubusercontent.com/' . self::REPO . '/main/assets/icon-128.png',
				'2x'  => 'https://raw.githubusercontent.com/' . self::REPO . '/main/assets/icon-256.png',
				'svg' => 'https://raw.githubusercontent.com/' . self::REPO . '/main/assets/icon.svg',
			),
			'upgrade_notice' => $release['notice'],
		);
	}

	/**
	 * Fills the "View details" modal, which would otherwise answer for a plugin
	 * the directory does not know.
	 *
	 * @param object|array|false $result Existing answer.
	 * @param string             $action Requested action.
	 * @param object             $args   Request arguments.
	 * @return object|array|false
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		if ( ! $this->settings->get( 'check_updates' ) ) {
			return $result;
		}

		$release = $this->release();

		if ( null === $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Bastion',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://zigmoon.com/">zigmoon</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $release['package'],
			'requires'      => '6.5',
			'tested'        => '7.1',
			'requires_php'  => '8.2',
			'last_updated'  => $release['date'],
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Forces HTTPS, sets the security headers, closes the version leaks and audits the result on the live site — with nothing applied until you ask.', 'bastion' ) . '</p>',
				'changelog'   => $release['changelog'],
			),
		);
	}

	/**
	 * Forgets the cached answer once the plugin has been updated, so the next
	 * screen load reports the truth instead of the version we just replaced.
	 *
	 * @param object $upgrader   Upgrader instance, unused.
	 * @param array  $hook_extra Upgrade context.
	 */
	public function flush( $upgrader, $hook_extra ): void {
		if ( ! is_array( $hook_extra ) ) {
			return;
		}

		$plugins = array();

		if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			$plugins = $hook_extra['plugins'];
		} elseif ( isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
			$plugins = array( $hook_extra['plugin'] );
		}

		if ( in_array( BASTION_BASENAME, $plugins, true ) ) {
			delete_site_transient( self::TRANSIENT );
		}
	}

	/**
	 * The latest release, from the cache or from github.com.
	 *
	 * @return array<string, string>|null
	 */
	private function release(): ?array {
		$cached = get_site_transient( self::TRANSIENT );

		if ( is_array( $cached ) && array_key_exists( 'release', $cached ) ) {
			return is_array( $cached['release'] ) ? $cached['release'] : null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout'    => 10,
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
				'headers'    => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);

		$release = null;

		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

			if ( is_array( $body ) ) {
				$release = self::parse_release( $body );
			}
		}

		set_site_transient(
			self::TRANSIENT,
			array( 'release' => $release ),
			null === $release ? self::TTL_FAILURE : self::TTL
		);

		return $release;
	}

	/**
	 * Turns a GitHub release payload into the values the dashboard needs.
	 *
	 * Kept static and free of WordPress calls beyond escaping so it can be
	 * tested against a recorded payload.
	 *
	 * @param array<string, mixed> $json Decoded release.
	 * @return array<string, string>|null
	 */
	public static function parse_release( array $json ): ?array {
		if ( ! isset( $json['tag_name'] ) || ! is_string( $json['tag_name'] ) ) {
			return null;
		}

		// A draft or a pre-release is not something a live site should install.
		if ( ! empty( $json['draft'] ) || ! empty( $json['prerelease'] ) ) {
			return null;
		}

		$version = self::normalise_version( $json['tag_name'] );

		if ( '' === $version ) {
			return null;
		}

		$package = self::pick_asset( isset( $json['assets'] ) ? (array) $json['assets'] : array(), $version );

		// No installable archive means no update: a zipball would extract to
		// `repo-tag/` and leave a second, duplicate plugin behind.
		if ( '' === $package ) {
			return null;
		}

		$url = isset( $json['html_url'] ) && is_string( $json['html_url'] ) ? $json['html_url'] : 'https://github.com/' . self::REPO;

		return array(
			'version'   => $version,
			'package'   => $package,
			'url'       => $url,
			'date'      => isset( $json['published_at'] ) && is_string( $json['published_at'] ) ? $json['published_at'] : '',
			'notice'    => self::first_paragraph( isset( $json['body'] ) && is_string( $json['body'] ) ? $json['body'] : '' ),
			'changelog' => self::changelog_markup( isset( $json['body'] ) && is_string( $json['body'] ) ? $json['body'] : '' ),
		);
	}

	/**
	 * Keeps the digits of a tag: `v1.0.1` and `1.0.1` are the same release.
	 *
	 * @param string $tag Release tag.
	 */
	public static function normalise_version( string $tag ): string {
		return preg_match( '/(\d+(?:\.\d+)*)/', $tag, $match ) ? $match[1] : '';
	}

	/**
	 * Picks the installable archive among the release assets.
	 *
	 * @param array<int, mixed> $assets  Release assets.
	 * @param string            $version Version of the release, to prefer.
	 */
	public static function pick_asset( array $assets, string $version = '' ): string {
		$slug      = strtolower( self::SLUG );
		$preferred = '';
		$fallback  = '';

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || ! isset( $asset['name'], $asset['browser_download_url'] ) ) {
				continue;
			}

			$name = strtolower( (string) $asset['name'] );
			$url  = (string) $asset['browser_download_url'];

			if ( '.zip' !== substr( $name, -4 ) || '' === $url ) {
				continue;
			}

			// `bastion-1.0.0-projet-complet.zip` holds the repository, not the
			// plugin folder: installing it would break the site.
			if ( false !== strpos( $name, 'projet-complet' ) || false !== strpos( $name, 'complete' ) ) {
				continue;
			}

			if ( 0 !== strpos( $name, $slug . '-' ) && 0 !== strpos( $name, $slug . '_' ) ) {
				continue;
			}

			if ( ! wp_http_validate_url( $url ) ) {
				continue;
			}

			if ( '' !== $version && false !== strpos( $name, $version ) ) {
				$preferred = $url;
				break;
			}

			if ( '' === $fallback ) {
				$fallback = $url;
			}
		}

		return '' !== $preferred ? $preferred : $fallback;
	}

	/**
	 * The first prose line of a release body, for the upgrade notice.
	 *
	 * Headings, list items, the "first release" formula and any fragment too
	 * short to be a sentence are skipped: a notice made of a section heading
	 * reads worse than no notice at all.
	 *
	 * @param string $body Release body, Markdown.
	 */
	public static function first_paragraph( string $body ): string {
		foreach ( preg_split( '/\R/', trim( $body ) ) ?: array() as $line ) {
			$line = trim( $line );

			if ( '' === $line || preg_match( '/^(\#{1,6}\s|[-*+]\s|\d+\.\s|\||>)/', $line ) ) {
				continue;
			}

			$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $line ) ) );
			$text = trim( str_replace( array( '**', '__', '`' ), '', $text ) );

			if ( '' === $text || preg_match( '/^(first release\.?|initial release\.?)$/i', $text ) ) {
				continue;
			}

			if ( count( preg_split( '/\s+/u', $text ) ?: array() ) < 3 ) {
				continue;
			}

			return self::truncate( $text, 200 );
		}

		return '';
	}

	/**
	 * Minimal Markdown to the small HTML subset the modal accepts.
	 *
	 * A release body is a changelog written in Markdown. Passed through as-is
	 * it reads as a wall of asterisks and backticks in the "View details"
	 * modal, which is exactly where a visitor decides whether to update.
	 *
	 * @param string $body Release body.
	 */
	public static function changelog_markup( string $body ): string {
		$html = '';

		foreach ( preg_split( '/\R/', trim( $body ) ) ?: array() as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $line, $match ) ) {
				$title = trim( $match[2] );

				// The opening heading only repeats the version and the date the
				// modal already prints above the changelog.
				if ( preg_match( '/^\[?v?\d+(\.\d+)*/', $title ) ) {
					continue;
				}

				$html .= '<h4>' . esc_html( $title ) . '</h4>';
				continue;
			}

			if ( preg_match( '/^[-*+]\s+(.*)$/', $line, $match ) ) {
				$html .= '<li>' . self::inline_markup( $match[1] ) . '</li>';
				continue;
			}

			$html .= '<p>' . self::inline_markup( $line ) . '</p>';
		}

		if ( false !== strpos( $html, '<li>' ) ) {
			// Wrap the runs of list items, without a full parser.
			$html = (string) preg_replace( '/(?:<li>.*?<\/li>)+/s', '<ul>$0</ul>', $html );
		}

		return $html;
	}

	/**
	 * `**bold**` and `` `code` ``, escaped first.
	 *
	 * @param string $text One line of Markdown.
	 */
	private static function inline_markup( string $text ): string {
		$html = esc_html( $text );

		$html = (string) preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html );
		$html = (string) preg_replace( '/`([^`]+)`/s', '<code>$1</code>', $html );

		return $html;
	}

	/**
	 * Cuts a string without relying on mbstring.
	 *
	 * @param string $value  Input.
	 * @param int    $length Maximum length.
	 */
	private static function truncate( string $value, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_strlen( $value ) > $length ? mb_substr( $value, 0, $length - 1 ) . '…' : $value;
		}

		return strlen( $value ) > $length ? substr( $value, 0, $length - 1 ) . '…' : $value;
	}
}
