<?php
/**
 * Consent switches.
 *
 * Three things need the site administrator's explicit yes before they happen:
 *
 *  - connecting to youami.ai (registering the site and sending its published
 *    content for indexing),
 *  - the public "Powered by" link under the chat widget,
 *  - anonymous usage analytics in wp-admin.
 *
 * All three are OFF until the administrator switches them on.
 *
 * @package BitBot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BitBot_Consent {

    /** '1' once the administrator connected the site to youami.ai. */
    const OPT_CONNECTED = 'bitbot_connected';

    /** '1' when the administrator allows the public "Powered by" link. Default '0'. */
    const OPT_POWERED_BY = 'bitbot_powered_by';

    /** '1' when the administrator allows anonymous usage analytics in wp-admin. Default '0'. */
    const OPT_ANALYTICS = 'bitbot_analytics_optin';

    /**
     * Strict 0/1 flag, stored as the strings '1' / '0'. Used as the
     * sanitize_callback of the two display/analytics switches.
     *
     * @param mixed $value Raw value.
     * @return string '1' or '0'.
     */
    public static function sanitize_flag( $value ) {
        if ( true === $value || 1 === $value || '1' === $value || 'true' === $value || 'on' === $value ) {
            return '1';
        }
        return '0';
    }

    /**
     * Is this site connected to youami.ai?
     *
     * A fresh install is NOT connected until the administrator presses
     * Connect. An install that registered before this switch existed (it has
     * a registered flag, a backend site id or a finished first boot) is
     * treated as connected, and that is remembered, so nothing changes for it.
     *
     * @return bool
     */
    public static function is_connected() {
        $stored = get_option( self::OPT_CONNECTED, false );
        if ( '1' === $stored ) {
            return true;
        }
        if ( '0' === $stored ) {
            return false;
        }
        // Never decided: derive from the proof that this site already talks to
        // the backend.
        $already = get_option( 'bitbot_registered' ) || get_option( 'bitbot_site_id' ) || get_option( 'bitbot_first_boot_finished' );
        if ( $already ) {
            update_option( self::OPT_CONNECTED, '1', false );
            return true;
        }
        return false;
    }

    /**
     * The analytics choice made on the Connect screen. It applies ONLY to the
     * request that first connects the site, and only when the box was ticked.
     * On a site that is already connected it does nothing, so a repeated or
     * replayed Connect request can never undo a later opt-out in Account.
     * Call it before mark_connected().
     *
     * @param mixed $posted The raw `analytics` value, or null when absent.
     * @return bool Whether analytics was switched on by this call.
     */
    public static function apply_connect_analytics_choice( $posted ) {
        if ( self::is_connected() ) {
            return false;
        }
        if ( null === $posted || '1' !== self::sanitize_flag( $posted ) ) {
            return false;
        }
        update_option( self::OPT_ANALYTICS, '1' );
        return true;
    }

    /**
     * Record the administrator's consent to connect. Call only from an
     * explicit, capability-checked admin action.
     */
    public static function mark_connected() {
        update_option( self::OPT_CONNECTED, '1', false );
    }

    /**
     * May the public "Powered by" link show? Only when the administrator
     * switched it on. The plan never decides this.
     *
     * @return bool
     */
    public static function powered_by_enabled() {
        return '1' === get_option( self::OPT_POWERED_BY, '0' );
    }

    /**
     * May wp-admin load analytics and send usage events?
     *
     * @return bool
     */
    public static function analytics_enabled() {
        return '1' === get_option( self::OPT_ANALYTICS, '0' );
    }

    /**
     * Shared permission check for the routes youami.ai calls with this site's
     * own api key: constant-time compare, 401 on a missing, empty or wrong key.
     *
     * @param WP_REST_Request $request Request carrying `apiKey`.
     * @return true|WP_Error
     */
    public static function check_api_key( $request ) {
        $key    = (string) $request->get_param( 'apiKey' );
        $stored = (string) get_option( 'bitbot_api_key', '' );
        if ( ! self::is_connected() || '' === $stored || '' === $key || ! hash_equals( $stored, $key ) ) {
            return new WP_Error( 'unauthorized', 'unauthorized', array( 'status' => 401 ) );
        }
        return true;
    }
}
