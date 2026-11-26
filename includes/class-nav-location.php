<?php
/**
 * Which theme location a classic menu goes to.
 *
 * The menu used to land on EVERY registered location (Twenty Twenty-One put
 * it in primary and footer, Hello Elementor in menu-1 and menu-2). The API now
 * names ONE location (the active one, else primary). The decision is pure
 * (locations in, locations out), so it runs without WordPress.
 *
 * @since 2.1.297
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class BitBot_Nav_Location {

    /** Display name of the menu and wp_navigation post the plugin owns. */
    const MENU_NAME        = 'Youami Primary';
    /** The name installs made before the rename carry; found and renamed on next apply. */
    const LEGACY_MENU_NAME = 'BitBot Primary';

    /**
     * The plugin's own wp_navigation post, under the current name or the
     * legacy one, current name first.
     *
     * @return array WP_Post[] (at most one).
     */
    public static function find_navigation_posts() {
        foreach ( array( self::MENU_NAME, self::LEGACY_MENU_NAME ) as $title ) {
            $found = get_posts( array(
                'post_type'   => 'wp_navigation',
                'title'       => $title,
                'post_status' => array( 'publish' ),
                'numberposts' => 1,
            ) );
            if ( ! empty( $found ) ) {
                return $found;
            }
        }
        return array();
    }

    /**
     * Decide the nav_menu_locations theme mod after assigning a menu.
     *
     *  - $location null or '' (an older API): every registered location, as before.
     *  - $location registered: that location only; the others keep what they hold.
     *  - $location not registered: nothing changes, and the fact says why.
     *
     * @param array       $current    Current nav_menu_locations (slug => menu id).
     * @param array       $registered Registered location slugs.
     * @param int         $menu_id    The menu being assigned.
     * @param string|null $location   The one location the API named, if any.
     * @return array { locations, assignedTo, locationFact|null, changed }
     */
    public static function assign( $current, $registered, $menu_id, $location ) {
        $current    = is_array( $current ) ? $current : array();
        $registered = array_values( array_map( 'strval', is_array( $registered ) ? $registered : array() ) );
        $menu_id    = (int) $menu_id;
        $location   = is_string( $location ) ? trim( $location ) : '';
        $next       = $current;
        $fact       = null;

        if ( '' === $location ) {
            foreach ( $registered as $loc ) {
                $next[ $loc ] = $menu_id;
            }
        } elseif ( in_array( $location, $registered, true ) ) {
            $next[ $location ] = $menu_id;
        } else {
            $fact = 'theme registers no location ' . $location . '; registered: '
                . ( count( $registered ) ? implode( ', ', $registered ) : 'none' );
        }

        return array(
            'locations'    => $next,
            'assignedTo'   => self::holding( $next, $menu_id ),
            'locationFact' => $fact,
            'changed'      => $next !== $current,
        );
    }

    /**
     * Whether a nav_menu afterValue asks to keep the header's page list.
     *
     * Round 2 TT25 (2026-10-02): the header was `<!-- wp:page-list /-->` and
     * adding one link wrote a static copy of every page. The API now sends
     * only the links that are not pages, with `pageList: true`.
     *
     * @since 2.1.298
     * @param mixed $after_value
     * @return bool
     */
    public static function keeps_page_list( $after_value ) {
        return is_array( $after_value ) && ! empty( $after_value['pageList'] );
    }

    /**
     * A wp_navigation post's content: the page list first when kept, then the
     * navigation-link blocks, so the list keeps following published pages.
     *
     * @since 2.1.298
     * @param string $links          navigation-link block markup.
     * @param bool   $keep_page_list
     * @return string
     */
    public static function navigation_content( $links, $keep_page_list ) {
        return ( $keep_page_list ? "<!-- wp:page-list /-->\n" : '' ) . (string) $links;
    }

    /**
     * Which store a menu add writes on this theme: a classic theme its classic
     * menu ONLY, a block theme its navigation (wp_navigation) ONLY.
     *
     * Round 3 (2026-10-02, foretelllamp): on Twenty Twenty-One the add wrote
     * the classic menu AND a wp_navigation post "Youami Primary" (About only);
     * after the switch to Twenty Twenty-Five that post took over the header in
     * place of its page list. Each theme writes the store it renders.
     *
     * @since 2.1.300
     * @param bool $is_block_theme
     * @return array{classicMenu: bool, blockNavigation: bool}
     */
    public static function stores( $is_block_theme ) {
        $block = (bool) $is_block_theme;
        return array(
            'classicMenu'     => ! $block,
            'blockNavigation' => $block,
        );
    }

    /**
     * The location slugs that hold this menu.
     *
     * @param array $locations slug => menu id.
     * @param int   $menu_id
     * @return string[]
     */
    public static function holding( $locations, $menu_id ) {
        $out = array();
        foreach ( (array) $locations as $slug => $id ) {
            if ( (int) $id === (int) $menu_id && (int) $menu_id > 0 ) {
                $out[] = (string) $slug;
            }
        }
        return $out;
    }

    /**
     * Pin chosen links INTO a template part's first navigation block: its
     * `ref` dropped and its inner blocks set to the links, so the part renders
     * exactly them, whatever wp_navigation post is newest.
     *
     * Round 10 (2026-10-02, foretelllamp, Twenty Twenty-Five): "clean up the
     * menu" wrote wp_navigation 222 and the header showed Home and About only
     * because its navigation block has no ref and WordPress falls back to the
     * most recently created wp_navigation post; any newer one would take the
     * header over. The header part was never overridden: Twenty Twenty-Five's
     * parts/header.html is a single `wp:pattern` reference, so the parsed part
     * had no navigation block to pin. The caller expands patterns first; this
     * works on the parsed blocks (parse_blocks shape) and needs no WordPress.
     *
     * @since 2.1.307
     * @param array $blocks Parsed blocks of the part (patterns expanded).
     * @param array $links  Parsed core/navigation-link blocks.
     * @return array{blocks: array, pinned: bool}
     */
    public static function pin_links( $blocks, $links ) {
        $links  = array_values( (array) $links );
        $pinned = false;
        $walk   = function ( $list ) use ( &$walk, $links, &$pinned ) {
            $out = array();
            foreach ( (array) $list as $b ) {
                if ( ! $pinned && isset( $b['blockName'] ) && 'core/navigation' === $b['blockName'] ) {
                    // A ref makes WordPress render that post and ignore the
                    // inner links, so the pinned menu would never show.
                    $attrs = isset( $b['attrs'] ) && is_array( $b['attrs'] ) ? $b['attrs'] : array();
                    unset( $attrs['ref'] );
                    $b['attrs']        = $attrs;
                    $b['innerBlocks']  = $links;
                    $b['innerHTML']    = '';
                    $b['innerContent'] = array_fill( 0, count( $links ), null );
                    $pinned            = true;
                } elseif ( ! $pinned && ! empty( $b['innerBlocks'] ) ) {
                    $b['innerBlocks'] = $walk( $b['innerBlocks'] );
                }
                $out[] = $b;
            }
            return $out;
        };
        $next = $walk( $blocks );
        return array( 'blocks' => $next, 'pinned' => $pinned );
    }

    /**
     * Which store the chosen links render from, as the apply result reports
     * it: `header-part` when they are written inline into the header part,
     * `navigation-post` when the header still draws them from a wp_navigation
     * post (WordPress's newest-navigation fallback, or a ref).
     *
     * @since 2.1.307
     * @param int $header_part_id The header part override written, or 0.
     * @return string
     */
    public static function pinned_store( $header_part_id ) {
        return (int) $header_part_id > 0 ? 'header-part' : 'navigation-post';
    }

    /**
     * The template part a theme uses for an area: the one named for the area
     * when the theme has it, else the last listed (the old rule), else the
     * area's name.
     *
     * @since 2.1.307
     * @param string[] $slugs Part slugs registered for the area, as listed.
     * @param string   $area  'header' or 'footer'.
     * @return string
     */
    public static function area_part( $slugs, $area ) {
        $slugs = array_values( array_map( 'strval', (array) $slugs ) );
        if ( in_array( $area, $slugs, true ) ) {
            return $area;
        }
        return count( $slugs ) ? $slugs[ count( $slugs ) - 1 ] : $area;
    }
}
