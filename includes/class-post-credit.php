<?php
/**
 * Posts the plugin generated carry no credit.
 *
 * Versions 1.6.0 to 2.2.6 saved a "Draft by Youami" line, with a link, into the
 * content of posts sent to WordPress (on the Free plan, later only with the
 * "Powered by" setting on). A credit saved into a post stays visible after the
 * setting is switched off, so it is no longer written at all, and the copies
 * earlier versions saved are taken out of the displayed content on every request.
 * The one attribution left is the chat widget's "Powered by" link, shown only
 * while the administrator's opt-in is current.
 *
 * @package BitBot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BitBot_Post_Credit {

    /** Marker an earlier version wrote before its saved credit. */
    const LEGACY_MARKER = '<!-- bitbot-draft-footer -->';

    /**
     * Remove a credit an earlier version saved into the content.
     * Pure: no WordPress calls.
     *
     * @param string $content Post content.
     * @return string
     */
    public static function strip_stored( $content ) {
        if ( ! is_string( $content ) ) {
            return '';
        }
        if ( false === strpos( $content, 'draft_footer' ) && false === strpos( $content, self::LEGACY_MARKER ) ) {
            return $content;
        }
        // Exactly the paragraph those versions wrote: <p><em>LABEL <a ...draft_footer...>Youami</a> →</em></p>,
        // the label being a short translated phrase. A paragraph with any other
        // content is not touched, even when it links to that address.
        $paragraph = '#<p\b[^>]*>\s*<em>\s*[^<>.!?]{0,40}?\s<a\b[^>]*href=(["\'])https?://(?:www\.)?youami\.ai/[^"\']*utm_medium=draft_footer[^"\']*\1[^>]*>\s*(?:Youami|BitBot)\s*</a>\s*(?:→|&rarr;|&\#8594;)?\s*</em>\s*</p>#iu';
        $stripped  = preg_replace( $paragraph, '', $content );
        if ( null === $stripped ) {
            return $content;
        }
        return rtrim( str_replace( self::LEGACY_MARKER, '', $stripped ) );
    }

    /**
     * `the_content` filter: the displayed content never carries a saved credit.
     *
     * @param string $content Post content.
     * @return string
     */
    public static function filter_content( $content ) {
        return self::strip_stored( $content );
    }
}
