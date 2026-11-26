<?php
/**
 * CSS the plugin handles.
 *
 * Two different things, kept apart:
 *
 *  - Design kit CSS the plugin itself produces for the pages it builds (stored in
 *    `_bitbot_page_css`, `bitbot_post_css`, `bitbot_site_css`). It is printed by the
 *    plugin, always through for_style_tag(), so no stored value can close the
 *    <style> element or open another tag.
 *
 *  - The site's Additional CSS. The plugin does NOT keep or print its own copy. It
 *    reads and writes WordPress's own Additional CSS for the active theme
 *    (wp_get_custom_css / wp_update_custom_css_post), which WordPress prints. The
 *    value is checked the way the Customizer checks it: CSS with markup in it is
 *    refused.
 *
 * @package BitBot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BitBot_Site_Css {

    /** Option an earlier version kept its own copy of the Additional CSS in. Moved once by migrate_legacy(), then deleted. */
    const LEGACY_OPTION = 'bitbot_custom_css';

    /** Autoloaded '1' once the legacy option has been dealt with, so the check costs nothing afterwards. */
    const MIGRATED_OPTION = 'bitbot_custom_css_moved';

    /**
     * Make a stored CSS value safe to print between <style> and </style>.
     *
     * CSS has no use for "<": removing every tag and then every remaining "<"
     * leaves nothing that can end the element or start a new one, whatever
     * spacing or casing the value uses. ">" is kept (child combinators).
     *
     * @param mixed $css Stored value.
     * @return string
     */
    public static function for_style_tag( $css ) {
        if ( ! is_string( $css ) || '' === $css ) {
            return '';
        }
        return str_replace( '<', '', wp_strip_all_tags( $css ) );
    }

    /**
     * Why a value is not acceptable as CSS, or null when it is.
     * Same rule as WP_Customize_Custom_CSS_Setting::validate(): no markup.
     *
     * @param mixed $css Candidate value.
     * @return string|null
     */
    public static function refusal( $css ) {
        if ( ! is_string( $css ) ) {
            return 'The CSS must be text.';
        }
        if ( preg_match( '#</?\w+#', $css ) ) {
            return 'Markup is not allowed in CSS.';
        }
        return null;
    }

    /**
     * The active theme's Additional CSS, as WordPress stores it.
     *
     * @return string
     */
    public static function read_additional_css() {
        return function_exists( 'wp_get_custom_css' ) ? (string) wp_get_custom_css() : '';
    }

    /**
     * Replace the active theme's Additional CSS in WordPress's own store.
     *
     * The Customizer saves Additional CSS without the post-content HTML filter
     * (that filter would turn every ">" into "&gt;" and break child combinators),
     * and it can, because the administrator saving it has `unfiltered_html` or
     * the save runs through the setting's own path. This write happens outside a
     * logged-in administrator's request, so for this ONE save the two
     * post-content filters are taken off and put back exactly as they were,
     * after refusal() has rejected any markup. Nothing else is changed.
     *
     * @param mixed $css New Additional CSS.
     * @return true|WP_Error
     */
    public static function write_additional_css( $css ) {
        $why = self::refusal( $css );
        if ( null !== $why ) {
            return new WP_Error( 'bitbot_css_refused', $why );
        }
        if ( ! function_exists( 'wp_update_custom_css_post' ) ) {
            return new WP_Error( 'bitbot_css_unavailable', 'This WordPress version has no Additional CSS.' );
        }
        $lifted = array();
        foreach ( array( 'content_save_pre', 'content_filtered_save_pre' ) as $hook ) {
            $priority = has_filter( $hook, 'wp_filter_post_kses' );
            if ( false !== $priority ) {
                remove_filter( $hook, 'wp_filter_post_kses', $priority );
                $lifted[ $hook ] = $priority;
            }
        }
        try {
            $result = wp_update_custom_css_post( $css );
        } finally {
            foreach ( $lifted as $hook => $priority ) {
                add_filter( $hook, 'wp_filter_post_kses', $priority );
            }
        }
        return is_wp_error( $result ) ? $result : true;
    }

    /**
     * Add CSS to the active theme's Additional CSS unless it is already in it.
     *
     * @param string $css CSS to add.
     * @return true|WP_Error
     */
    public static function append_additional_css( $css ) {
        $css     = trim( (string) $css );
        $current = trim( self::read_additional_css() );
        if ( '' === $css || false !== strpos( $current, $css ) ) {
            return true;
        }
        return self::write_additional_css( '' === $current ? $css : $current . "\n\n" . $css );
    }

    /**
     * One-time move of the CSS an earlier version kept in its own option into
     * WordPress's Additional CSS for the active theme. Cheap to call on every
     * load: an autoloaded marker says when it is done. The option is deleted
     * only once its value is in the theme's Additional CSS (or is not acceptable
     * CSS and is dropped); a failed write leaves it for the next load.
     *
     * @return void
     */
    public static function migrate_legacy() {
        if ( '1' === (string) get_option( self::MIGRATED_OPTION, '0' ) ) {
            return;
        }
        $legacy = get_option( self::LEGACY_OPTION, null );
        if ( null !== $legacy ) {
            $legacy = is_string( $legacy ) ? trim( $legacy ) : '';
            if ( '' !== $legacy && null === self::refusal( $legacy ) && true !== self::append_additional_css( $legacy ) ) {
                return;
            }
            delete_option( self::LEGACY_OPTION );
        }
        update_option( self::MIGRATED_OPTION, '1', true );
    }
}
