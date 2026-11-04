<?php
/**
 * WhatsApp click-to-chat: the owner's number, cleaned, and what the widget gets.
 *
 * Click-to-chat only: the widget opens https://wa.me/<number>. There is no
 * WhatsApp API, no account, no key. Pure functions so tests run without WordPress.
 *
 * @package BitBot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BitBot_WhatsApp {

    /**
     * An owner-typed number as E.164 ("+61412345678"), or '' when it is not one.
     * Spaces, dashes, dots and brackets are dropped; the number must start with
     * "+", a non-zero digit, then 7 to 14 more digits (8 to 15 in all).
     *
     * @param mixed $raw Whatever the owner typed.
     */
    public static function sanitize_number( $raw ) {
        if ( ! is_string( $raw ) ) {
            return '';
        }
        $s = preg_replace( '/[\s\-\.\(\)]/', '', trim( $raw ) );
        return is_string( $s ) && preg_match( '/^\+[1-9][0-9]{7,14}$/', $s ) ? $s : '';
    }

    /** The switch as '1' or '0'. */
    public static function sanitize_enabled( $raw ) {
        return ( true === $raw || 1 === $raw || '1' === $raw || 'true' === $raw ) ? '1' : '0';
    }

    /**
     * What the widget config carries: the digits wa.me wants (no plus), or ''
     * when the switch is off or the number is not valid. The widget shows its
     * button only for a non-empty value.
     *
     * @param mixed $number  Saved number.
     * @param mixed $enabled Saved switch.
     */
    public static function widget_number( $number, $enabled ) {
        if ( '1' !== self::sanitize_enabled( $enabled ) ) {
            return '';
        }
        $e164 = self::sanitize_number( $number );
        return '' === $e164 ? '' : substr( $e164, 1 );
    }
}
