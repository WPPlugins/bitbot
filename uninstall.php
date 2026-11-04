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
 * NO GLOBAL FUNCTION HERE. WordPress runs EVERY selected plugin's uninstall.php
 * inside ONE PHP process, so a global function declared here is redeclared the
 * moment a second copy of this plugin is deleted in the same request.
 *
 * That is not hypothetical. Re-uploading the same version forks the install to
 * `bitbot-1/`, and deleting both at once fataled the whole request:
 *
 *   Cannot redeclare function bitbot_uninstall_cleanup() (previously declared
 *   in .../bitbot-1/uninstall.php:22) in .../bitbot/uninstall.php on line 22
 *
 * The delete half-finished — one folder gone, one left behind, the site showing
 * a critical error. Running inline removes the collision class outright, which
 * `function_exists()` would only paper over (the second copy would then silently
 * skip its own cleanup and leave its rows behind).
 */
// List of options to delete
$options = array(
    'bitbot_api_key',
    'bitbot_enabled',
    'bitbot_widget_position',
    'bitbot_primary_color',
    'bitbot_welcome_message',
    'bitbot_plan_status_cached',
    'bitbot_db_version',
);

foreach ( $options as $option ) {
    delete_option( $option );
}

// Transients created by BitBot.
delete_transient( 'bitbot_plan_status' );
delete_transient( 'bitbot_new_leads_count' );
delete_transient( 'bitbot_live_chats_count' );

// Clear any scheduled events
wp_clear_scheduled_hook( 'bitbot_scheduled_posts' );
wp_clear_scheduled_hook( 'bitbot_refresh_plan_status' );
wp_clear_scheduled_hook( 'bitbot_retry_first_sync' );
wp_clear_scheduled_hook( 'bitbot_first_boot' );
wp_clear_scheduled_hook( 'bitbot_first_sync_run' );

// Delete post meta created by the plugin using WordPress function
delete_post_meta_by_key( '_bitbot_keywords' );

// The retired theme agent contract executor (removed in 2.1.291, brief
// "Plugin-Owned Agent Contract" section 6.3) kept OPERATIONAL data only under
// this prefix: the mutation lock, the recovery journal, the request ledger and
// the undo snapshots, blog-id scoped, so the prefix is swept rather than a
// fixed list. Sites that ran an earlier version still hold these rows, so the
// sweep stays after the code that wrote them is gone.
//
// WHAT THIS MUST NEVER TOUCH: the templates, template parts, Global Styles,
// site title/tagline/logo or any content the executor produced. Those are
// the owner's site, not our bookkeeping. Removing the plugin is not a
// request to undo their design.
global $wpdb;
$prefix = 'bitbot_tc_';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
wp_cache_delete( 'notoptions', 'options' );
wp_cache_delete( 'alloptions', 'options' );

// Flush rewrite rules
flush_rewrite_rules();
