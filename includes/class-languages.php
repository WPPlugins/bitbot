<?php
/**
 * Languages the owner offers on the site (voice calls and chat).
 *
 * The curated list lives once, in assets/languages.json, read by the admin
 * SPA, this class and (as a pinned copy) the backend. The site's own language
 * (the base tag of the WP locale) is always offered and is the default.
 *
 * @package BitBot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BitBot_Languages {

    /** Option holding the saved list of codes (site language first), or empty. */
    const OPTION = 'bitbot_voice_languages';

    /**
     * The curated list: array of array( code, name, native ).
     *
     * @param string|null $path Override for tests.
     */
    public static function all( $path = null ) {
        $file = $path ? $path : dirname( __DIR__ ) . '/assets/languages.json';
        $json = is_readable( $file ) ? file_get_contents( $file ) : false;
        $list = is_string( $json ) ? json_decode( $json, true ) : null;
        return is_array( $list ) ? $list : array();
    }

    /**
     * Pure merge behind detected(): site language first, then installed
     * packs, then multilingual-plugin codes, deduped, base two-letter codes,
     * only codes present in the catalogue.
     *
     * @param string $locale       Site locale ("en_US").
     * @param array  $packs        Installed translation locales.
     * @param array  $plugin_codes Codes or locales from Polylang, WPML, TranslatePress.
     * @param array  $curated      Catalogue (see all()).
     */
    public static function merge_detected( $locale, $packs, $plugin_codes, $curated ) {
        $known = array();
        foreach ( $curated as $row ) {
            $known[ $row['code'] ] = true;
        }
        $out = array();
        $all = array_merge( array( $locale ), (array) $packs, (array) $plugin_codes );
        foreach ( $all as $raw ) {
            if ( ! is_string( $raw ) || '' === $raw ) {
                continue;
            }
            $code = self::site_code( $raw );
            if ( isset( $known[ $code ] ) && ! in_array( $code, $out, true ) ) {
                $out[] = $code;
            }
        }
        return $out;
    }

    /**
     * The languages this WordPress site speaks: the site language, installed
     * translation packs, and Polylang, WPML or TranslatePress languages.
     *
     * @param string|null $locale Override for tests; defaults to get_locale().
     */
    public static function detected( $locale = null ) {
        $locale = null === $locale ? get_locale() : $locale;
        $packs  = function_exists( 'get_available_languages' ) ? get_available_languages() : array();
        $plugin = array();
        if ( function_exists( 'pll_languages_list' ) ) {
            $pll = pll_languages_list( array( 'fields' => 'slug' ) );
            if ( is_array( $pll ) ) {
                $plugin = array_merge( $plugin, $pll );
            }
        }
        $wpml = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
        if ( is_array( $wpml ) ) {
            $plugin = array_merge( $plugin, array_keys( $wpml ) );
        }
        $trp = get_option( 'trp_settings' );
        if ( is_array( $trp ) && isset( $trp['translation-languages'] ) && is_array( $trp['translation-languages'] ) ) {
            $plugin = array_merge( $plugin, array_values( $trp['translation-languages'] ) );
        }
        return self::merge_detected( $locale, is_array( $packs ) ? $packs : array(), $plugin, self::all() );
    }

    /**
     * The list in force: the detected languages until the owner has saved a
     * list, then the saved list (site language first).
     *
     * @param mixed $saved    Saved option value.
     * @param array $detected See detected().
     * @param array $curated  Catalogue.
     */
    public static function effective_selection( $saved, $detected, $curated ) {
        $site = isset( $detected[0] ) ? $detected[0] : 'en';
        if ( ! is_array( $saved ) || empty( $saved ) ) {
            return self::sanitize_selection( $detected, $site, $curated );
        }
        return self::sanitize_selection( $saved, $site, $curated );
    }

    /** The site language: the two-letter base tag of a WP locale ("en_US" gives "en"). */
    public static function site_code( $locale ) {
        return strtolower( substr( (string) $locale, 0, 2 ) );
    }

    /**
     * What the owner may save: curated codes only, the site language first and
     * always present, no duplicates. Anything else in the input is dropped.
     *
     * @param mixed  $raw       Posted list.
     * @param string $site_code Site language code.
     * @param array  $curated   Curated list (see all()).
     */
    public static function sanitize_selection( $raw, $site_code, $curated ) {
        $allowed = array();
        foreach ( $curated as $row ) {
            $allowed[ $row['code'] ] = true;
        }
        $out = array( $site_code );
        if ( is_array( $raw ) ) {
            foreach ( $raw as $code ) {
                if ( is_string( $code ) && isset( $allowed[ $code ] ) && ! in_array( $code, $out, true ) ) {
                    $out[] = $code;
                }
            }
        }
        return $out;
    }

    /**
     * What the widget gets: rows of array( code, name, native ) for the saved
     * list, or an empty array when the list is just the site language (the
     * visitor has no choice, so no control). A site language outside the
     * curated list is shown by its code.
     *
     * @param mixed  $saved     Saved option value.
     * @param string $site_code Site language code.
     * @param array  $curated   Curated list.
     */
    public static function widget_list( $saved, $site_code, $curated, $detected = null ) {
        $codes = self::effective_codes( $saved, $site_code, $curated, $detected );
        if ( count( $codes ) < 2 ) {
            return array();
        }
        $by_code = array();
        foreach ( $curated as $row ) {
            $by_code[ $row['code'] ] = $row;
        }
        $rows = array();
        foreach ( $codes as $code ) {
            $rows[] = isset( $by_code[ $code ] )
                ? array(
                    'code'   => $code,
                    'name'   => $by_code[ $code ]['name'],
                    'native' => $by_code[ $code ]['native'],
                )
                : array(
                    'code'   => $code,
                    'name'   => $code,
                    'native' => $code,
                );
        }
        return $rows;
    }

    /**
     * A visitor's pick: a code from the saved list, or '' (no pick, so no
     * language is forced anywhere).
     *
     * @param mixed $raw   Posted value.
     * @param mixed $saved Saved option value.
     */
    public static function sanitize_pick( $raw, $saved, $site_code, $curated, $detected = null ) {
        if ( ! is_string( $raw ) ) {
            return '';
        }
        $codes = self::effective_codes( $saved, $site_code, $curated, $detected );
        return ( count( $codes ) > 1 && in_array( $raw, $codes, true ) ) ? $raw : '';
    }

    /** Effective codes with the site language forced first (detected may be null: saved list only). */
    private static function effective_codes( $saved, $site_code, $curated, $detected ) {
        if ( null === $detected ) {
            return self::sanitize_selection( is_array( $saved ) ? $saved : array(), $site_code, $curated );
        }
        $list = ( is_array( $saved ) && ! empty( $saved ) ) ? $saved : $detected;
        return self::sanitize_selection( $list, $site_code, $curated );
    }
}
