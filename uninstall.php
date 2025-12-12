<?php
/**
 * BitBot Uninstall
 *
 * Fired when the plugin is uninstalled.
 * Cleans up all plugin data from the database.
 *
 * @package    BitBot
 * @since      1.0.0
 * @author     youami.ai
 * @license    GPL-2.0-or-later
 */

// If uninstall not called from WordPress, exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Remove all plugin options from database
 */
function bitbot_uninstall_cleanup() {
    // List of options to delete
    $options = array(
        'bitbot_api_key',
        'bitbot_enabled',
        'bitbot_widget_position',
        'bitbot_primary_color',
        'bitbot_welcome_message',
    );

    foreach ( $options as $option ) {
        delete_option( $option );
    }

    // Clear any scheduled events
    wp_clear_scheduled_hook( 'bitbot_scheduled_posts' );

    // Delete post meta created by the plugin using WordPress function
    delete_post_meta_by_key( '_bitbot_keywords' );

    // Flush rewrite rules
    flush_rewrite_rules();
}

bitbot_uninstall_cleanup();
