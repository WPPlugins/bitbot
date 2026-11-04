<?php
/**
 * The edit token on a plain WordPress read.
 *
 * replace_content_text refuses a write unless it carries the `revision` this
 * plugin computes (post_revision: `wp:` + a hash of the modified stamp and the
 * body, or `el:` for Elementor). getPostSource returns it. A single-item read
 * through wp-read (`/wp/v2/pages/46?context=edit`) did not, and that read is
 * the one the agent is told to make before editing a body. Live 2026-10-02
 * (foretelllamp, page 46): getPostSource never ran, the agent read the page
 * through wp-read, took the WordPress revision id from `_links`
 * (predecessor-version 47) and sent "47" as the token. The page had not
 * changed; the write was refused as stale anyway.
 *
 * So a single post read through wp-read carries the same token, under the
 * same name, at the top of the item. Only for an item that IS a post of the
 * type it claims: a revision, a menu item or a template is left alone.
 *
 * @package BitBot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BitBot_Read_Revision {

    /**
     * @param mixed    $data        The REST response data of a wp-read.
     * @param callable $revision_of fn( int $post_id, WP_Post $post ): string
     * @return mixed $data, with `revision` first when it is a single post item.
     */
    public static function attach( $data, $revision_of ) {
        if ( ! is_array( $data ) || array_key_exists( 'revision', $data ) ) {
            return $data;
        }
        if ( ! isset( $data['id'], $data['type'] ) || ! is_int( $data['id'] ) || ! is_string( $data['type'] ) ) {
            return $data;
        }
        $post = get_post( $data['id'] );
        if ( ! $post || $post->post_type !== $data['type'] ) {
            return $data;
        }
        $revision = (string) call_user_func( $revision_of, (int) $data['id'], $post );
        if ( '' === $revision ) {
            return $data;
        }
        return array( 'revision' => $revision ) + $data;
    }
}
