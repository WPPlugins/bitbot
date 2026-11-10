<?php
/**
 * Atomic key store over the options table, for gather v2's lease and state.
 *
 * Single SQL statements, never served from the options cache, so two PHP
 * processes cannot both win a create, a steal or a commit. Rows are
 * non-autoloaded and never public.
 *
 * @package BitBot
 * @since   2.2.17
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BitBot_Gather_WP_Store implements BitBot_Gather_Store {

    public function add( $key, $value ) {
        global $wpdb;
        $n = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $key,
            $value
        ) );
        return 1 === (int) $n;
    }

    public function get( $key ) {
        global $wpdb;
        $v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
        return null === $v ? null : (string) $v;
    }

    public function cas( $key, $old, $new ) {
        global $wpdb;
        $n = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
            $new,
            $key,
            $old
        ) );
        return 1 === (int) $n;
    }

    public function delete_if( $key, $value ) {
        global $wpdb;
        $n = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = BINARY %s",
            $key,
            $value
        ) );
        return 1 === (int) $n;
    }

    public function delete( $key ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $key ) );
    }

    /** Unconditional write (run data keys; the caller fences it). */
    public function put( $key, $value ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            $key,
            $value
        ) );
    }

    /** Delete every row whose name starts with $prefix. */
    public function delete_prefix( $prefix ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( $prefix ) . '%'
        ) );
    }
}
