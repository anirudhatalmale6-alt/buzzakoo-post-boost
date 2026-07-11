<?php
/**
 * Removes the plugin's own data. Runs only on "Delete", never on deactivate,
 * so switching the plugin off does not throw away boost history.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bzk_boosts" ); // phpcs:ignore
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bzk_boost_log" ); // phpcs:ignore

delete_option( 'bzk_boost_settings' );
delete_option( 'bzk_boost_db_version' );

wp_clear_scheduled_hook( 'bzk_boost_cleanup' );
