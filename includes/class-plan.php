<?php
/**
 * Plan descriptor helpers.
 *
 * Single source of truth for plan codes, paid/pro-plus checks, labels,
 * prices, and badge colours. Never compare `$plan === 'pro'` inline —
 * call BitBot_Plan methods so adding a new tier (or renaming one) is a
 * single-file change.
 *
 * @package    BitBot
 * @subpackage BitBot/includes
 * @since      1.7.0
 * @license    GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plan helper.
 *
 * Matches the plan codes returned by the backend `GetPlanStatus` RPC.
 *
 * @since 1.7.0
 */
class BitBot_Plan {

    const FREE     = 'free';
    const PRO      = 'pro';
    const PRO_PLUS = 'pro_plus';

    /**
     * Every paying tier. Anything in this set hides the "Powered by"
     * branding, disables upsell CTAs, and unlocks gated UI.
     */
    const PAID = array( self::PRO, self::PRO_PLUS );

    /**
     * Persistent option that holds the last-known plan snapshot
     * (plan + usage + limits). Read by both the admin UI and the
     * front-end widget — the widget has no business hitting the API
     * on every page view, so it relies on this cache being kept
     * reasonably fresh by admin-side refreshes + the scheduled cron.
     */
    const STATUS_OPTION = 'bitbot_plan_status_cached';

    /**
     * Short-lived throttle transient. While present, admin-side
     * callers skip the API round-trip and reuse the persisted
     * option. Expiring the transient doesn't invalidate the option —
     * it only signals "you may re-fetch now".
     */
    const STATUS_THROTTLE_TRANSIENT = 'bitbot_plan_status';

    // 60s throttle: short enough that plan changes (Stripe checkout,
    // cancellation, manual DB flip) reflect on the next admin page load
    // within ~1 minute, long enough to avoid hammering our backend on
    // rapid-fire admin navigation. Webhook-driven plan changes also
    // bust the cache directly on the ?subscription=success / cancelled
    // landing URL, so 60s is the worst case, not the typical case.
    const STATUS_THROTTLE_SECONDS = 60;

    /**
     * Cron hook name used by the plugin activation hook to keep the
     * persisted status fresh even on sites where an admin rarely
     * opens the dashboard.
     */
    const REFRESH_CRON_HOOK = 'bitbot_refresh_plan_status';

    /**
     * Coerce an unknown value into a recognised plan code.
     * Anything we don't know about is treated as Free.
     *
     * @param mixed $plan
     * @return string One of self::FREE | self::PRO | self::PRO_PLUS.
     */
    public static function normalize( $plan ) {
        if ( $plan === self::PRO || $plan === self::PRO_PLUS ) {
            return $plan;
        }
        return self::FREE;
    }

    /**
     * True for any paying tier (Pro or Pro+).
     *
     * @param mixed $plan
     * @return bool
     */
    public static function is_paid( $plan ) {
        return in_array( self::normalize( $plan ), self::PAID, true );
    }

    /**
     * True only for the top Pro+ tier.
     *
     * @param mixed $plan
     * @return bool
     */
    public static function is_pro_plus( $plan ) {
        return self::normalize( $plan ) === self::PRO_PLUS;
    }

    /**
     * Short human label: "Free", "Pro", "Pro+".
     *
     * @param mixed $plan
     * @return string
     */
    public static function label( $plan ) {
        switch ( self::normalize( $plan ) ) {
            case self::PRO_PLUS:
                return 'Pro+';
            case self::PRO:
                return 'Pro';
            default:
                return 'Free';
        }
    }

    /**
     * Display price for the Plan card.
     *
     * @param mixed $plan
     * @return string
     */
    public static function price( $plan ) {
        switch ( self::normalize( $plan ) ) {
            case self::PRO_PLUS:
                return '$49/month';
            case self::PRO:
                return '$20/month';
            default:
                return __( 'Free forever', 'bitbot' );
        }
    }

    /**
     * Badge background colour for the plan pill.
     *
     * @param mixed $plan
     * @return string Hex colour.
     */
    public static function badge_color( $plan ) {
        switch ( self::normalize( $plan ) ) {
            case self::PRO_PLUS:
                return '#8b5cf6';
            case self::PRO:
                return '#46b450';
            default:
                return '#6b6b6b';
        }
    }

    /**
     * Read the persisted plan snapshot, or null if no cache exists.
     *
     * Front-end callers (widget) should use this directly — they
     * must not hit the API on every page view.
     *
     * @return array|null { plan: string, limits: array, usage: array } or null if uncached.
     */
    public static function get_cached_status() {
        $status = get_option( self::STATUS_OPTION, null );
        return is_array( $status ) ? $status : null;
    }

    /**
     * Persist a plan snapshot for both the admin UI and the widget
     * to read. Also re-arms the admin-side refresh throttle so we
     * don't re-hit the API within STATUS_THROTTLE_SECONDS.
     *
     * @param array $status Must at minimum contain a 'plan' key.
     */
    public static function store_cached_status( array $status ) {
        // autoload=no — the option is only needed when bitbot code runs,
        // no reason to load it on every WP page boot.
        update_option( self::STATUS_OPTION, $status, false );
        set_transient(
            self::STATUS_THROTTLE_TRANSIENT,
            true,
            self::STATUS_THROTTLE_SECONDS
        );
    }

    /**
     * True while the throttle transient is live — admin-side code
     * should skip the API call and reuse the persisted option.
     *
     * @return bool
     */
    public static function is_refresh_throttled() {
        return (bool) get_transient( self::STATUS_THROTTLE_TRANSIENT );
    }

    /**
     * Clear both caches. Called on successful checkout, cancel, or
     * any other point where the plan may have changed server-side.
     */
    public static function bust_cache() {
        delete_option( self::STATUS_OPTION );
        delete_transient( self::STATUS_THROTTLE_TRANSIENT );
    }
}
