<?php
/**
 * Removes plugin data when the plugin is deleted from the Plugins screen.
 *
 * @package WP_Admin_Performance_Watcher
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$wapw_ts = wp_next_scheduled( 'wapw_cleanup_old_data' );
if ( $wapw_ts ) {
    wp_unschedule_event( $wapw_ts, 'wapw_cleanup_old_data' );
}
wp_clear_scheduled_hook( 'wapw_cleanup_old_data' );

delete_option( 'wapw_settings' );

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wapw_slow_queries" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wapw_samples" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
