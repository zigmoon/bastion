<?php
/**
 * Uninstall: removes what the plugin wrote, and only that.
 *
 * @package Bastion
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes the plugin data for one site.
 */
function bastion_uninstall_site(): void {
	$settings = get_option( 'bastion_settings', array() );
	$settings = is_array( $settings ) ? $settings : array();

	// Off by default would leave rows behind on a site that wanted them gone;
	// on by default would surprise someone who only wanted to reinstall. The
	// plugin asks, and the answer is what is honoured.
	if ( empty( $settings['delete_data'] ) ) {
		return;
	}

	delete_option( 'bastion_settings' );
	delete_option( 'bastion_version' );
	delete_option( 'bastion_welcome' );
	delete_option( 'bastion_log' );

	delete_site_transient( 'bastion_probe' );
	delete_site_transient( 'bastion_release' );
}

if ( is_multisite() ) {
	$sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $sites as $site_id ) {
		switch_to_blog( (int) $site_id );
		bastion_uninstall_site();
		restore_current_blog();
	}
} else {
	bastion_uninstall_site();
}
