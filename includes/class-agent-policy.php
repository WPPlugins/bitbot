<?php
/**
 * What the remote agent may and may not do on this site.
 *
 * The agent is limited to non-administrative work: content, media, taxonomies,
 * comments, navigation, templates and a short list of non-sensitive settings.
 * It can never install, activate, update or remove plugins or themes, switch
 * the theme, manage users, or change critical settings (site URL, admin email,
 * permalinks, registration). This file is the single place those limits live:
 * the agent role's capabilities, the proposal kinds that are refused, and the
 * core REST routes the agent may dispatch. It is pure (no WordPress calls) so
 * it runs under a plain `php` test harness.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class BitBot_Agent_Policy {

    const ROLE_KEY     = 'bitbot_agent';
    const ROLE_VERSION = 5;
    const ROLE_NAME    = 'Youami Agent';
    const AGENT_DESCRIPTION = 'Youami AI agent — applies content and design changes.';
    const ROLE_OPTION  = 'bitbot_agent_role_version';

    /**
     * The ONLY capabilities the agent role holds. Editor-level content caps
     * plus edit_theme_options, which core requires for nav menus, block
     * templates, template parts and global styles (the agent's design work).
     * edit_theme_options does NOT allow switching or installing themes.
     */
    public static function role_caps() {
        return array(
            'read'                   => true,
            'edit_posts'             => true,
            'edit_others_posts'      => true,
            'edit_published_posts'   => true,
            'edit_private_posts'     => true,
            'publish_posts'          => true,
            'delete_posts'           => true,
            'delete_others_posts'    => true,
            'delete_published_posts' => true,
            'read_private_posts'     => true,
            'edit_pages'             => true,
            'edit_others_pages'      => true,
            'edit_published_pages'   => true,
            'edit_private_pages'     => true,
            'publish_pages'          => true,
            'delete_pages'           => true,
            'delete_others_pages'    => true,
            'delete_published_pages' => true,
            'read_private_pages'     => true,
            'upload_files'           => true,
            'manage_categories'      => true,
            'moderate_comments'      => true,
            'edit_theme_options'     => true,
        );
    }

    /**
     * Capabilities an existing role holds that it must no longer hold: every
     * capability not in role_caps(). Used by the upgrade step so installs that
     * created the role with the old caps (manage_options) are brought in line.
     *
     * @param array $current Capabilities currently on the stored role (cap => bool).
     * @return string[]
     */
    public static function caps_to_remove( $current ) {
        $allowed = self::role_caps();
        $remove  = array();
        foreach ( (array) $current as $cap => $granted ) {
            if ( ! isset( $allowed[ $cap ] ) ) {
                $remove[] = (string) $cap;
            }
        }
        return $remove;
    }

    /**
     * Entries on the agent user's stored `caps` array (extra roles and direct
     * capabilities) that must go so the user holds exactly the agent role.
     *
     * @param array  $user_caps The user's `caps` array (role or cap => bool).
     * @param string $role_key  The one role the user keeps.
     * @return string[]
     */
    public static function stray_user_entries( $user_caps, $role_key = self::ROLE_KEY ) {
        $stray = array();
        foreach ( array_keys( (array) $user_caps ) as $key ) {
            if ( (string) $key !== $role_key ) {
                $stray[] = (string) $key;
            }
        }
        return $stray;
    }

    /** Proposal kinds the plugin refuses outright (administrative). */
    public static function refused_kinds() {
        return array( 'install_plugin', 'install_theme', 'permalink_structure' );
    }

    /**
     * The reason a proposal kind is refused, or null when it is not one of the
     * administrative kinds.
     *
     * @param string $kind
     * @return string|null
     */
    public static function kind_refusal( $kind ) {
        if ( in_array( (string) $kind, self::refused_kinds(), true ) ) {
            return 'Refused: "' . $kind . '" is an administrative change (plugins, themes or site-wide settings). Youami does not perform it on this site; the site owner does that in wp-admin.';
        }
        return null;
    }

    /**
     * The site settings the agent can read and write, as REST key => WordPress
     * option. The list is fixed here in plugin code: the agent never reaches
     * core's settings controller (which needs manage_options and exposes the
     * site URL, admin email, language and timezone). Reads and writes both go
     * through BitBot_Admin::agent_settings(), after the apiKey check.
     */
    public static function settings_map() {
        return array(
            'title'          => 'blogname',
            'description'    => 'blogdescription',
            'show_on_front'  => 'show_on_front',
            'page_on_front'  => 'page_on_front',
            'page_for_posts' => 'page_for_posts',
            'posts_per_page' => 'posts_per_page',
            'site_logo'      => 'site_logo',
            'site_icon'      => 'site_icon',
        );
    }

    /**
     * Validate a settings write and turn it into option values. Pure: the
     * caller checks that a listed page or attachment id really exists.
     *
     * @param array $params REST-style keys (title, show_on_front, ...).
     * @return array { ok, error?, options?: option => value, pages?: int[], media?: int[] }
     */
    public static function settings_write_plan( $params ) {
        $map    = self::settings_map();
        $accept = 'Fields this route actually accepts: ' . implode( ', ', array_keys( $map ) ) . '.';
        if ( ! is_array( $params ) || empty( $params ) ) {
            return array( 'ok' => false, 'error' => 'No settings given. ' . $accept );
        }
        $options = array();
        $pages   = array();
        $media   = array();
        foreach ( $params as $key => $value ) {
            $k = strtolower( (string) $key );
            if ( ! isset( $map[ $k ] ) ) {
                return array( 'ok' => false, 'error' => 'Setting "' . $k . '" is not writable here. ' . $accept );
            }
            if ( 'title' === $k || 'description' === $k ) {
                if ( ! is_string( $value ) ) {
                    return array( 'ok' => false, 'error' => $k . ' must be text.' );
                }
                $options[ $map[ $k ] ] = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) );
            } elseif ( 'show_on_front' === $k ) {
                if ( ! in_array( $value, array( 'page', 'posts' ), true ) ) {
                    return array( 'ok' => false, 'error' => 'show_on_front must be "page" or "posts".' );
                }
                $options[ $map[ $k ] ] = $value;
            } elseif ( 'posts_per_page' === $k ) {
                if ( ! is_numeric( $value ) || (int) $value < 1 || (int) $value > 100 ) {
                    return array( 'ok' => false, 'error' => 'posts_per_page must be between 1 and 100.' );
                }
                $options[ $map[ $k ] ] = (int) $value;
            } else {
                // page_on_front, page_for_posts, site_logo, site_icon: an id, 0 = none.
                if ( ! is_numeric( $value ) || (int) $value < 0 || (float) $value !== (float) (int) $value ) {
                    return array( 'ok' => false, 'error' => $k . ' must be a whole number id (0 for none).' );
                }
                $options[ $map[ $k ] ] = (int) $value;
                if ( (int) $value > 0 ) {
                    if ( 'page_on_front' === $k || 'page_for_posts' === $k ) {
                        $pages[] = (int) $value;
                    } else {
                        $media[] = (int) $value;
                    }
                }
            }
        }
        return array( 'ok' => true, 'options' => $options, 'pages' => $pages, 'media' => $media );
    }

    /** True when the route is the core settings endpoint. */
    public static function is_settings_route( $route ) {
        return 1 === preg_match( '#^/wp/v2/settings/?$#', self::normalise( $route ) );
    }

    /** Lowercased path with no query string and exactly one leading slash. */
    public static function normalise( $route ) {
        // WordPress matches routes case-INSENSITIVELY, so '/wp/v2/Settings'
        // reaches the real controller. Match everything on the lowercase form.
        return strtolower( '/' . trim( (string) strtok( (string) $route, '?' ), '/' ) );
    }

    /**
     * Whether the agent may dispatch this core REST call.
     *
     * @param string $method
     * @param string $route
     * @param array  $params
     * @return bool
     */
    public static function route_allowed( $method, $route, $params = array() ) {
        $method = strtoupper( (string) $method );
        if ( ! in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
            return false;
        }
        $params = is_array( $params ) ? $params : array();
        // WordPress reads the exact key 'force', but a differently cased duplicate
        // (force=true&FORCE=false) must not decide the check: refuse any request
        // that carries more than one case variant of it.
        $force_values = array();
        foreach ( $params as $key => $value ) {
            if ( 0 === strcasecmp( (string) $key, 'force' ) ) {
                $force_values[] = $value;
            }
        }
        if ( count( $force_values ) > 1 ) {
            return false;
        }
        $params = array_change_key_case( $params, CASE_LOWER );
        $path   = self::normalise( $route );

        // Core namespace only (this also keeps /wp-site-health/, /wp-block-editor/,
        // /oembed/ and every third-party namespace out).
        if ( '/wp/v2' !== $path && 0 !== strpos( $path, '/wp/v2/' ) ) {
            return false;
        }
        // Administrative sub-trees, every method, reads included:
        //   users (and their application-passwords), plugins, the block
        //   directory (installs plugins), site health, and the plugin/theme
        //   update surfaces.
        $denied = array(
            '#^/wp/v2/users(/|$)#',
            '#^/wp/v2/plugins(/|$)#',
            '#^/wp/v2/block-directory(/|$)#',
            '#^/wp/v2/site-health(/|$)#',
            '#^/wp/v2/application-passwords(/|$)#',
        );
        foreach ( $denied as $re ) {
            if ( preg_match( $re, $path ) ) {
                return false;
            }
        }
        // Themes: READ is allowed (how the agent learns what the site runs),
        // WRITE never (switching or deleting a theme).
        if ( preg_match( '#^/wp/v2/themes(/|$)#', $path ) && 'GET' !== $method ) {
            return false;
        }
        // Settings: never through core's settings controller, on any method.
        // The plugin serves a fixed key list itself (settings_map), before this
        // guard is consulted, so the agent never needs manage_options.
        if ( self::is_settings_route( $path ) ) {
            return false;
        }
        if ( 'GET' === $method ) {
            return true;
        }
        // Permanent force-delete only where there is no trash bin (terms,
        // media). Posts/pages/CPTs stay on the reversible trash path.
        if ( 'DELETE' === $method ) {
            $force    = array_key_exists( 'force', $params ) ? filter_var( $params['force'], FILTER_VALIDATE_BOOLEAN ) : false;
            $force_ok = 1 === preg_match( '#^/wp/v2/(categories|tags|media)(/|$)#', $path );
            if ( $force && ! $force_ok ) {
                return false;
            }
        }
        return true;
    }

}
