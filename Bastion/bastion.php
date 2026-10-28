<?php
/**
 * Plugin Name:       Bastion
 * Plugin URI:        https://github.com/zigmoon/bastion
 * Update URI:        https://github.com/zigmoon/bastion
 * Description:       Forces HTTPS, sets the security headers, closes the version leaks and audits the result on the live site — with nothing applied until you ask.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Tested up to:      7.1
 * Requires PHP:      8.2
 * Author:            zigmoon
 * Author URI:        https://github.com/zigmoon
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bastion
 * Domain Path:       /languages
 *
 * @package Bastion
 */

namespace Bastion;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'BASTION_VERSION' ) ) {
	define( 'BASTION_VERSION', '1.0.0' );
}
if ( ! defined( 'BASTION_FILE' ) ) {
	define( 'BASTION_FILE', __FILE__ );
}
if ( ! defined( 'BASTION_DIR' ) ) {
	define( 'BASTION_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'BASTION_URL' ) ) {
	define( 'BASTION_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'BASTION_BASENAME' ) ) {
	define( 'BASTION_BASENAME', plugin_basename( __FILE__ ) );
}

require_once BASTION_DIR . 'includes/class-settings.php';
require_once BASTION_DIR . 'includes/class-headers.php';
require_once BASTION_DIR . 'includes/class-https.php';
require_once BASTION_DIR . 'includes/class-disclosure.php';
require_once BASTION_DIR . 'includes/class-dns.php';
require_once BASTION_DIR . 'includes/class-audit.php';
require_once BASTION_DIR . 'includes/class-updater.php';
require_once BASTION_DIR . 'includes/class-admin.php';
require_once BASTION_DIR . 'includes/class-cli.php';
require_once BASTION_DIR . 'includes/class-plugin.php';

/**
 * Loads the translations once WordPress is ready for them.
 *
 * Priority 1 on `init`: `__()` must not run earlier, and WordPress 6.7 reports
 * a notice for a text domain loaded after `init` has started.
 */
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'bastion', false, dirname( BASTION_BASENAME ) . '/languages' );
	},
	1
);

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	},
	5
);

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );
