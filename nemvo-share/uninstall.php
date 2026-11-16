<?php
/**
 * Removes all plugin data on uninstall, but only on sites where "delete on uninstall"
 * is switched on. On a multisite network every site is checked on its own.
 *
 * @package NemvoShare
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-crypto.php';
require_once __DIR__ . '/includes/class-install.php';
require_once __DIR__ . '/includes/class-storage.php';

/**
 * Cleans up the current site.
 */
function nemvo_share_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'nemvo_share_settings', array() );
	wp_clear_scheduled_hook( 'nemvo_share_cleanup' );
	delete_transient( 'nemvo_share_rewrite_checked' );

	if ( empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}

	// The storage folder: every shared file, encrypted.
	NemvoShare\Storage::remove_all();

	foreach ( array( 'shares', 'files', 'log' ) as $table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'nemvo_share_' . $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own tables on uninstall.
	}

	if ( ! empty( $settings['page_id'] ) ) {
		wp_delete_post( (int) $settings['page_id'], true );
	}

	foreach ( array( 'nemvo_share_settings', 'nemvo_share_db_version', 'nemvo_share_key', 'nemvo_share_storage_suffix', 'nemvo_share_probe', 'nemvo_share_flush_rewrite' ) as $option ) {
		delete_option( $option );
	}

	// "Shares per page" screen option, saved per user (and per site on a network).
	delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . 'nemvo_share_per_page', '', true );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'number' => 0,
			'fields' => 'ids',
		)
	) as $nemvo_share_site_id ) {
		switch_to_blog( (int) $nemvo_share_site_id );
		nemvo_share_uninstall_site();
		restore_current_blog();
	}
} else {
	nemvo_share_uninstall_site();
}
delete_metadata( 'user', 0, 'nemvo_share_per_page', '', true );
