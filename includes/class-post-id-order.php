<?php
/**
 * Order of post IDs for a sync: the pages that matter most first.
 *
 * The backend enforces the page limit and gives out the slots in the order the
 * pages arrive, so on a plan with a cap this order decides which pages the
 * assistant reads when a site has more than fit. Same set of IDs, only the
 * order changes:
 *   1. the static front page,
 *   2. what the owner linked from the menus visitors can see (menus assigned to a
 *      theme location, navigation blocks the templates use), in menu order,
 *   3. pages, top level first, then their children, then grandchildren,
 *   4. products,
 *   5. everything else (posts and other content types).
 * Inside a group the caller's order is kept (newest-modified first).
 *
 * Pure: no WordPress calls, so the rule can be tested on its own.
 *
 * @since 2.2.48
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class BitBot_Post_Id_Order {

    /**
     * Join the groups in priority order, keeping the first sighting of an ID.
     *
     * @param int   $front_id  Static front page ID, or 0 when none is set.
     * @param int[] $pages     Page IDs.
     * @param int[] $products  Product IDs.
     * @param int[] $others    Every other post ID.
     * @param int[] $menu      IDs linked from the site's menus, in menu order. An ID that is not
     *                         in one of the other groups is ignored: a menu never adds a page.
     * @return int[]
     */
    public static function combine( $front_id, array $pages, array $products, array $others, array $menu = array() ) {
        $known = array();
        foreach ( array( $pages, $products, $others ) as $group ) {
            foreach ( $group as $id ) {
                $known[ (int) $id ] = true;
            }
        }
        $front_id = (int) $front_id;
        $in_menu  = array();
        foreach ( $menu as $id ) {
            if ( isset( $known[ (int) $id ] ) ) {
                $in_menu[] = (int) $id;
            }
        }
        $out    = array();
        $seen   = array();
        $groups = array( $front_id > 0 ? array( $front_id ) : array(), $in_menu, $pages, $products, $others );
        foreach ( $groups as $group ) {
            foreach ( $group as $id ) {
                $id = (int) $id;
                if ( isset( $seen[ $id ] ) ) {
                    continue;
                }
                $seen[ $id ] = true;
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * Pages with their ancestors counted: top-level pages first, then children,
     * then grandchildren. The given order is kept inside each level.
     *
     * @param int[] $ids       Page IDs, in the order to keep within a level.
     * @param array $parent_of Map of page ID to parent ID (0 or missing = top level).
     * @return int[]
     */
    public static function parents_first( array $ids, array $parent_of ) {
        $depth_of = array();
        $levels   = array();
        foreach ( $ids as $id ) {
            $id = (int) $id;
            // Walk up until a page whose depth is already known (each page is walked once),
            // a top-level page, or a parent that is missing, unpublished or part of a loop.
            $path = array();
            $on   = array();
            $at   = $id;
            while ( ! isset( $depth_of[ $at ] ) && ! isset( $on[ $at ] ) ) {
                $on[ $at ] = true;
                $path[]    = $at;
                $parent    = isset( $parent_of[ $at ] ) ? (int) $parent_of[ $at ] : 0;
                if ( $parent <= 0 ) {
                    break;
                }
                $at = $parent;
            }
            // Depth of the page the walk stopped on: known, or 0 when it is the top of the chain
            // (it is then the last entry of the path) or the closing page of a loop.
            $base = isset( $depth_of[ $at ] ) ? $depth_of[ $at ] + 1 : 0;
            for ( $i = count( $path ) - 1, $d = $base; $i >= 0; $i--, $d++ ) {
                $depth_of[ $path[ $i ] ] = $d;
            }
            $levels[ $depth_of[ $id ] ][] = $id;
        }
        ksort( $levels );
        $out = array();
        foreach ( $levels as $level ) {
            foreach ( $level as $id ) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * The classic menus a visitor can actually see: the ones assigned to one of the
     * theme's menu locations, in the order the theme registers its locations. A saved
     * menu that is assigned nowhere (an old or draft menu) is never returned.
     *
     * @param array $assigned   get_nav_menu_locations(): location slug => menu term ID.
     * @param array $registered Location slugs in the theme's order (keys of get_registered_nav_menus()).
     * @return int[] Menu term IDs.
     */
    public static function menus_in_use( array $assigned, array $registered ) {
        $out = array();
        foreach ( $registered as $location ) {
            $menu = isset( $assigned[ $location ] ) ? (int) $assigned[ $location ] : 0;
            if ( $menu > 0 && ! in_array( $menu, $out, true ) ) {
                $out[] = $menu;
            }
        }
        return $out;
    }

    /**
     * Post links of the classic menus in use, in menu order, within a shared work budget.
     * The budget counts every menu item READ (custom links and category links included),
     * not only the ones kept, so two huge menus of custom links cannot be read in full.
     *
     * @param int[]    $menus    menus_in_use().
     * @param callable $items_of function( int $menu_id, int $limit ): array of array( type, object_id ),
     *                           at most $limit rows, in menu order.
     * @param int      $budget   In/out: menu items that may still be read.
     * @return int[]
     */
    public static function classic_menu_ids( array $menus, $items_of, &$budget ) {
        $out = array();
        foreach ( $menus as $menu_id ) {
            if ( $budget <= 0 ) {
                break;
            }
            $rows    = array_slice( (array) call_user_func( $items_of, (int) $menu_id, (int) $budget ), 0, (int) $budget );
            $budget -= count( $rows );
            foreach ( $rows as $row ) {
                if ( is_array( $row ) && isset( $row[0], $row[1] ) && 'post_type' === $row[0] && (int) $row[1] > 0 ) {
                    $out[] = (int) $row[1];
                }
            }
        }
        return $out;
    }

    /**
     * The template each kind of visit uses, as fallback chains (first that exists wins),
     * following WordPress's template hierarchy and the site's Reading settings:
     *   - the front of the site: with a static front page, front-page, then the template
     *     assigned to that page, then page / singular / index; with the latest posts as
     *     front (or a static front that is not set), front-page, home, index;
     *   - the separate posts page, when one is assigned: home, index;
     *   - any page: page, singular, index;   - any post: single, singular, index.
     *
     * @param string $show_on_front  The show_on_front option ('page' or 'posts').
     * @param bool   $has_front_page A published static front page is set.
     * @param bool   $has_posts_page A separate posts page is set.
     * @param string $front_template The front page's assigned template slug, or ''.
     * @return array[] Chains of template slugs.
     */
    public static function template_chains( $show_on_front, $has_front_page, $has_posts_page, $front_template = '' ) {
        $chains = array();
        if ( 'page' === $show_on_front && $has_front_page ) {
            $front = array( 'front-page' );
            if ( '' !== (string) $front_template && 'default' !== (string) $front_template ) {
                $front[] = (string) $front_template;
            }
            $chains[] = array_merge( $front, array( 'page', 'singular', 'index' ) );
            if ( $has_posts_page ) {
                $chains[] = array( 'home', 'index' );
            }
        } else {
            $chains[] = array( 'front-page', 'home', 'index' );
        }
        $chains[] = array( 'page', 'singular', 'index' );
        $chains[] = array( 'single', 'singular', 'index' );
        return $chains;
    }

    /**
     * The whole ranking of menu links, from readings the caller supplies. Classic
     * menus in use first, then (block themes) the navigation reached from the
     * templates visitors get. One budget of items read covers both.
     *
     * @param array $env {
     *     @type array    assigned        get_nav_menu_locations().
     *     @type array    registered      Location slugs in the theme's order.
     *     @type callable items_of        See classic_menu_ids().
     *     @type bool     block_theme     The active theme is a block theme.
     *     @type string   show_on_front, bool has_front_page, bool has_posts_page, string front_template
     *     @type callable template        function( string $slug ): array|null  parse_blocks() of a template, null when it does not exist.
     *     @type array    loaders         See block_menu_ids().
     *     @type int      newest_navigation
     * }
     * @param int   $budget Items that may be read in all.
     * @return int[]
     */
    public static function ranked_menu_ids( array $env, $budget ) {
        $budget = (int) $budget;
        $ids    = self::classic_menu_ids(
            self::menus_in_use( isset( $env['assigned'] ) ? (array) $env['assigned'] : array(), isset( $env['registered'] ) ? (array) $env['registered'] : array() ),
            isset( $env['items_of'] ) ? $env['items_of'] : function () { return array(); },
            $budget
        );
        if ( $budget <= 0 || empty( $env['block_theme'] ) || ! isset( $env['template'] ) ) {
            return $ids;
        }
        $blocks = array();
        $used   = array();
        $chains = self::template_chains(
            isset( $env['show_on_front'] ) ? (string) $env['show_on_front'] : 'posts',
            ! empty( $env['has_front_page'] ),
            ! empty( $env['has_posts_page'] ),
            isset( $env['front_template'] ) ? (string) $env['front_template'] : ''
        );
        foreach ( $chains as $chain ) {
            foreach ( $chain as $slug ) {
                if ( isset( $used[ $slug ] ) ) {
                    break;                                            // this visit resolves to a template already taken
                }
                $template = call_user_func( $env['template'], $slug );
                if ( is_array( $template ) ) {
                    $used[ $slug ] = true;
                    foreach ( $template as $block ) {
                        $blocks[] = $block;
                    }
                    break;
                }
            }
        }
        $found = self::block_menu_ids( $blocks, isset( $env['loaders'] ) ? (array) $env['loaders'] : array(), isset( $env['newest_navigation'] ) ? (int) $env['newest_navigation'] : 0, $budget );
        foreach ( $found as $id ) {
            $ids[] = $id;
        }
        return $ids;
    }

    /** Parts, patterns and navigation posts one walk may open: a bound for a theme that nests without end. */
    const WALK_DOCUMENTS = 60;

    /**
     * Post links of the menus a block theme really shows, in the order they appear.
     *
     * Walks the blocks of the templates given, and from there only what they reach:
     * a template part or a pattern is opened when a template (or a part it uses)
     * includes it, so a part that nothing includes is never read. For each
     * navigation block: the menu it points at (`ref`), or its own inline links, or,
     * when it has neither, the newest published menu (what WordPress falls back to).
     * Every part, pattern and menu is opened once (loops end), and every link block
     * looked at is taken from the shared budget, kept or not.
     *
     * @param array $blocks    parse_blocks() output of the templates a visitor gets.
     * @param array $loaders   'part' => function( string $slug ): array blocks,
     *                         'pattern' => function( string $slug ): array blocks,
     *                         'navigation' => function( int $id ): array blocks. Each returns array() when missing.
     * @param int   $newest_id The newest published wp_navigation post, or 0.
     * @param int   $budget    In/out: link blocks that may still be looked at.
     * @return int[]
     */
    public static function block_menu_ids( array $blocks, array $loaders, $newest_id, &$budget ) {
        $state = array( 'opened' => array(), 'documents' => 0, 'out' => array() );
        self::walk_template_blocks( $blocks, $loaders, (int) $newest_id, $budget, $state );
        return $state['out'];
    }

    /** Open a part, pattern or menu once, within the document bound. Returns its blocks, or null when it may not be opened. */
    private static function open_document( $kind, $key, array $loaders, array &$state ) {
        $id = $kind . ':' . $key;
        if ( isset( $state['opened'][ $id ] ) || $state['documents'] >= self::WALK_DOCUMENTS || ! isset( $loaders[ $kind ] ) ) {
            return null;
        }
        $state['opened'][ $id ] = true;
        $state['documents']++;
        $blocks = call_user_func( $loaders[ $kind ], $key );
        return is_array( $blocks ) ? $blocks : array();
    }

    private static function walk_template_blocks( array $blocks, array $loaders, $newest_id, &$budget, array &$state ) {
        foreach ( $blocks as $block ) {
            if ( $budget <= 0 ) {
                return;
            }
            if ( ! is_array( $block ) ) {
                continue;
            }
            $name  = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
            $attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
            $inner = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();

            if ( 'core/navigation' === $name ) {
                $ref = isset( $attrs['ref'] ) ? (int) $attrs['ref'] : 0;
                if ( $ref > 0 ) {
                    $menu = self::open_document( 'navigation', $ref, $loaders, $state );
                } elseif ( ! empty( $inner ) ) {
                    $menu = $inner;                                   // the block carries its own links
                } else {
                    $menu = $newest_id > 0 ? self::open_document( 'navigation', $newest_id, $loaders, $state ) : null;
                }
                if ( is_array( $menu ) ) {
                    self::collect_links( $menu, $budget, $state );
                }
                continue;
            }
            if ( 'core/template-part' === $name && isset( $attrs['slug'] ) && '' !== (string) $attrs['slug'] ) {
                $part = self::open_document( 'part', (string) $attrs['slug'], $loaders, $state );
                if ( is_array( $part ) ) {
                    self::walk_template_blocks( $part, $loaders, $newest_id, $budget, $state );
                }
                continue;
            }
            if ( 'core/pattern' === $name && isset( $attrs['slug'] ) && '' !== (string) $attrs['slug'] ) {
                $pattern = self::open_document( 'pattern', (string) $attrs['slug'], $loaders, $state );
                if ( is_array( $pattern ) ) {
                    self::walk_template_blocks( $pattern, $loaders, $newest_id, $budget, $state );
                }
                continue;
            }
            if ( ! empty( $inner ) ) {
                self::walk_template_blocks( $inner, $loaders, $newest_id, $budget, $state );
            }
        }
    }

    /** The post links inside a menu's blocks (submenus included). Each link block looked at costs one from the budget. */
    private static function collect_links( array $blocks, &$budget, array &$state ) {
        foreach ( $blocks as $block ) {
            if ( $budget <= 0 ) {
                return;
            }
            if ( ! is_array( $block ) ) {
                continue;
            }
            $name  = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
            $attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
            if ( in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
                $budget--;
                if ( isset( $attrs['id'] ) && (int) $attrs['id'] > 0 && ( ! isset( $attrs['kind'] ) || 'post-type' === $attrs['kind'] ) ) {
                    $state['out'][] = (int) $attrs['id'];
                }
            }
            if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                self::collect_links( $block['innerBlocks'], $budget, $state );
            }
        }
    }
}
