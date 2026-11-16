<?php
/**
 * Removes all plugin data on uninstall, but only when enabled in the settings.
 *
 * @package NemvoShare
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$nemvo_share_settings = get_option( 'nemvo_share_settings', array() );
wp_clear_scheduled_hook( 'nemvo_share_cleanup' );

if ( empty( $nemvo_share_settings['delete_on_uninstall'] ) ) {
	return;
}

// The storage folder: every shared file, encrypted.
require_once __DIR__ . '/includes/class-crypto.php';
require_once __DIR__ . '/includes/class-install.php';
require_once __DIR__ . '/includes/class-storage.php';
NemvoShare\Storage::remove_all();

global $wpdb;
foreach ( array( 'shares', 'files', 'log' ) as $nemvo_share_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'nemvo_share_' . $nemvo_share_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- removing the plugin's own tables on uninstall.
}

if ( ! empty( $nemvo_share_settings['page_id'] ) ) {
	wp_delete_post( (int) $nemvo_share_settings['page_id'], true );
}

foreach ( array( 'nemvo_share_settings', 'nemvo_share_db_version', 'nemvo_share_key', 'nemvo_share_storage_suffix', 'nemvo_share_probe', 'nemvo_share_flush_rewrite' ) as $nemvo_share_option ) {
	delete_option( $nemvo_share_option );
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%nemvo\\_share\\_%' OR option_name LIKE '\\_transient\\_timeout\\_nemvo\\_share\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- transients of this plugin.
