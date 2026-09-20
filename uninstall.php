<?php
/**
 * Cleanup on uninstall.
 *
 * Settings are kept by default so that deleting and reinstalling the plugin
 * does not lose the Site ID. They are only removed when the site has explicitly
 * opted in via "Ved afinstallation" on the settings page.
 *
 * @package E_Butik_Integration
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes the option for the current site, but only if opted in.
 */
function e_butik_integration_maybe_delete_settings() {
	$settings = get_option( 'e_butik_integration_settings' );

	if ( is_array( $settings ) && ! empty( $settings['delete_data'] ) ) {
		delete_option( 'e_butik_integration_settings' );

		// Pre-3.4.0 key, in case this site never loaded the settings after upgrading.
		delete_option( 'ebutik_integration_settings' );
	}
}

if ( is_multisite() ) {
	// The choice is stored per site, so each one is evaluated separately.
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		e_butik_integration_maybe_delete_settings();
		restore_current_blog();
	}
} else {
	e_butik_integration_maybe_delete_settings();
}
