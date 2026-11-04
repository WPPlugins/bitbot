<?php
/**
 * One H1 per page: does the active theme print a page's title itself?
 *
 * The plugin no longer hides the theme's title (removed in 2.1.338, together
 * with the `render_block_core/post-title` filter and the per-page flag that
 * drove it). It only REPORTS what the theme does, on the site read the agent
 * already makes; when the theme prints the title, the backend lowers a body
 * H1 to an H2 before a page is written, so the page keeps one H1. Flags left
 * on existing pages by older versions stay in the database and are ignored.
 *
 * - block themes: the template that renders a page (page, else singular,
 *   else index) carries a post-title block outside any post list
 * - classic themes: the template file that renders a page, or a part it
 *   loads, calls the_title(); when none does the title may still come from a
 *   theme hook, so the answer is "unknown", never "no"
 *
 * @since 2.1.338
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class BitBot_Page_Title {

    /**
     * Does this block template markup print the page's own title? A
     * post-title inside a Query Loop names the listed posts, not the page,
     * so post lists are cut out first. `$expand` (optional) returns a
     * registered pattern's markup for a slug, so a template that delegates
     * to a pattern is read as what it renders.
     *
     * @param string        $markup Template markup.
     * @param callable|null $expand fn( string $slug ): string|null.
     * @return bool
     */
    public static function markup_prints_title( $markup, $expand = null ) {
        $markup = (string) $markup;
        for ( $depth = 0; $depth < 5 && is_callable( $expand ) && false !== strpos( $markup, 'wp:pattern' ); $depth++ ) {
            $markup = preg_replace_callback(
                '#<!--\s+wp:(?:core/)?pattern\s+(\{.*?\})\s+/-->#s',
                function ( $m ) use ( $expand ) {
                    $attrs = json_decode( $m[1], true );
                    $slug  = is_array( $attrs ) && isset( $attrs['slug'] ) ? (string) $attrs['slug'] : '';
                    $inner = '' !== $slug ? call_user_func( $expand, $slug ) : null;
                    return is_string( $inner ) ? $inner : '';
                },
                $markup
            );
        }
        $markup = preg_replace( '#<!--\s+wp:(?:core/)?query\b.*?<!--\s+/wp:(?:core/)?query\s+-->#s', '', $markup );
        return 1 === preg_match( '#<!--\s+wp:(?:core/)?post-title[\s/]#', (string) $markup );
    }

    /**
     * Does this classic template source print the title?
     *
     * @param string $php Template file source.
     * @return bool
     */
    public static function php_prints_title( $php ) {
        return 1 === preg_match( '/\b(?:the_title|get_the_title|single_post_title)\s*\(/', (string) $php );
    }

    /**
     * The fact the site read reports: printed true / false / null (unknown),
     * and where it came from.
     *
     * @return array{printed: bool|null, basis: string}
     */
    public static function fact() {
        if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
            return self::block_fact();
        }
        return self::classic_fact();
    }

    private static function block_fact() {
        if ( ! function_exists( 'get_block_template' ) ) {
            return array( 'printed' => null, 'basis' => 'this WordPress cannot read block templates' );
        }
        $theme    = get_stylesheet();
        $registry = class_exists( 'WP_Block_Patterns_Registry' ) ? WP_Block_Patterns_Registry::get_instance() : null;
        $expand   = function ( $slug ) use ( $registry ) {
            if ( ! $registry || ! $registry->is_registered( $slug ) ) {
                return null;
            }
            $p = $registry->get_registered( $slug );
            return is_array( $p ) && isset( $p['content'] ) ? (string) $p['content'] : null;
        };
        foreach ( array( 'page', 'singular', 'index' ) as $slug ) {
            $tpl = get_block_template( $theme . '//' . $slug, 'wp_template' );
            if ( ! $tpl || '' === (string) $tpl->content ) {
                continue;
            }
            $printed = self::markup_prints_title( (string) $tpl->content, $expand );
            return array(
                'printed' => $printed,
                'basis'   => sprintf( 'the %s template %s a post-title block', $slug, $printed ? 'carries' : 'carries no' ),
            );
        }
        return array( 'printed' => null, 'basis' => 'no page, singular or index template could be read' );
    }

    private static function classic_fact() {
        if ( ! function_exists( 'locate_template' ) ) {
            return array( 'printed' => null, 'basis' => 'the page template file could not be found' );
        }
        $file = locate_template( array( 'page.php', 'singular.php', 'index.php' ) );
        if ( ! $file || ! is_readable( $file ) ) {
            return array( 'printed' => null, 'basis' => 'the page template file could not be found' );
        }
        $files  = array( $file );
        $source = (string) file_get_contents( $file );
        if ( preg_match_all( '/get_template_part\s*\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*[\'"]([^\'"]+)[\'"])?/', $source, $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $call ) {
                $names = ! empty( $call[2] ) ? array( $call[1] . '-' . $call[2] . '.php', $call[1] . '.php' ) : array( $call[1] . '.php' );
                $part  = locate_template( $names );
                if ( $part && is_readable( $part ) ) {
                    $files[] = $part;
                }
            }
        }
        foreach ( $files as $f ) {
            if ( self::php_prints_title( (string) file_get_contents( $f ) ) ) {
                return array( 'printed' => true, 'basis' => sprintf( '%s calls the_title()', basename( $f ) ) );
            }
        }
        return array( 'printed' => null, 'basis' => sprintf( '%s does not call the_title(); a theme hook may still print it', basename( $file ) ) );
    }
}
