<?php
/**
 * Point a created page's image blocks at the images we sideloaded.
 *
 * A sideloaded image used to change only the `src`, so WordPress saw a plain
 * URL (no srcset, no library link). Now the block carries the attachment the
 * way core saves it: `"id":N` in the block attributes and `wp-image-N` on the
 * <img>; a cover also gets its `url` attribute. A sideload that FAILED leaves
 * no remote URL behind: an image block keeps an <img> with no src (the
 * library's filters hide it), a cover drops its background image the way
 * core saves a cover with none.
 *
 * URLs are matched after normalising both sides, so `&amp;amp;` in a src and
 * `&amp;` in the request list name the same image.
 *
 * Pure: markup in, markup out. Tested in tests/test-page-images.php.
 *
 * @since 2.1.297
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class BitBot_Page_Images {

    const BLOCK_RE = '/<!--\s+wp:(?:core\/)?(image|cover)(?:\s+(\{.*?\}))?\s+(\/?)-->/s';

    /**
     * One URL, every encoding stripped: HTML entities (repeatedly, so a
     * double-encoded `&amp;amp;` decodes fully) and JSON escapes.
     *
     * @param string $url
     * @return string
     */
    public static function normalise_url( $url ) {
        $u = str_replace( array( '\\u0026', '\\/' ), array( '&', '/' ), trim( (string) $url ) );
        for ( $i = 0; $i < 5; $i++ ) {
            $d = html_entity_decode( $u, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            if ( $d === $u ) {
                break;
            }
            $u = $d;
        }
        return $u;
    }

    /**
     * Rewrite image and cover blocks.
     *
     * @param string $content  Block markup.
     * @param array  $swaps    [{ from, to, id }] sideloaded images.
     * @param array  $failures [{ from }] images whose sideload failed.
     * @return array { content, swapped: [normalised from => count], emptied: [normalised from => count] }
     */
    public static function rewrite( $content, $swaps, $failures ) {
        $content  = (string) $content;
        $by_from  = array();
        $failed   = array();
        $swapped  = array();
        $emptied  = array();
        foreach ( (array) $swaps as $s ) {
            if ( ! empty( $s['from'] ) && ! empty( $s['to'] ) ) {
                $key             = self::normalise_url( $s['from'] );
                $by_from[ $key ] = array( 'to' => (string) $s['to'], 'id' => (int) ( $s['id'] ?? 0 ) );
                $swapped[ $key ] = 0;
            }
        }
        foreach ( (array) $failures as $f ) {
            if ( ! empty( $f['from'] ) ) {
                $key             = self::normalise_url( $f['from'] );
                $failed[ $key ]  = true;
                $emptied[ $key ] = 0;
            }
        }
        if ( ! $by_from && ! $failed ) {
            return array( 'content' => $content, 'swapped' => $swapped, 'emptied' => $emptied );
        }

        if ( preg_match_all( self::BLOCK_RE, $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            // Back to front, so earlier offsets stay true as the markup changes.
            foreach ( array_reverse( $m ) as $hit ) {
                if ( '/' === $hit[3][0] ) {
                    continue;
                }
                $name     = $hit[1][0];
                $start    = $hit[0][1];
                $open_end = $start + strlen( $hit[0][0] );
                // Objects, not arrays: an empty {} attribute must stay {}.
                $attrs    = new stdClass();
                if ( isset( $hit[2] ) && -1 !== $hit[2][1] && '' !== $hit[2][0] ) {
                    $attrs = json_decode( $hit[2][0] );
                    if ( ! is_object( $attrs ) ) {
                        continue;
                    }
                }
                // The block's own markup runs up to the next comment: an image
                // block has no inner blocks, and a cover's background <img>
                // comes before its inner container's blocks.
                $next    = strpos( $content, '<!--', $open_end );
                $seg_end = false === $next ? strlen( $content ) : $next;
                $seg     = substr( $content, $open_end, $seg_end - $open_end );
                $img     = self::own_img( $name, $seg );
                $url     = ( 'cover' === $name && ! empty( $attrs->url ) ) ? (string) $attrs->url : ( $img ? self::attr( $img, 'src' ) : '' );
                if ( '' === $url ) {
                    continue;
                }
                $key = self::normalise_url( $url );
                if ( isset( $by_from[ $key ] ) ) {
                    $to          = $by_from[ $key ]['to'];
                    $id          = $by_from[ $key ]['id'];
                    if ( $id > 0 ) {
                        $attrs->id = $id;
                    }
                    if ( 'cover' === $name ) {
                        $attrs->url = $to;
                    }
                    if ( $img ) {
                        $new_img = self::set_attr( $img, 'src', $to );
                        if ( $id > 0 ) {
                            $new_img = self::set_image_class( $new_img, $id );
                        }
                        $seg = str_replace( $img, $new_img, $seg );
                    }
                    $swapped[ $key ]++;
                } elseif ( isset( $failed[ $key ] ) ) {
                    unset( $attrs->id );
                    if ( 'cover' === $name ) {
                        unset( $attrs->url );
                        if ( $img ) {
                            $seg = str_replace( $img, '', $seg );
                        }
                        // A fixed or repeated cover paints the URL as a style.
                        $seg = preg_replace( '/background-image:\s*url\([^)]*\);?/i', '', $seg );
                    } elseif ( $img ) {
                        $seg = str_replace( $img, self::remove_attr( $img, 'src' ), $seg );
                    }
                    $emptied[ $key ]++;
                } else {
                    continue;
                }
                $open    = '<!-- wp:' . $name . ( get_object_vars( $attrs ) ? ' ' . self::encode_attrs( $attrs ) : '' ) . ' -->';
                $content = substr( $content, 0, $start ) . $open . $seg . substr( $content, $seg_end );
            }
        }

        // Anything left outside an image or cover block (a background-image
        // style, a srcset, a link) still names the remote URL: swap it as
        // the plain replace always did, in each encoding it may appear in.
        foreach ( $by_from as $key => $s ) {
            foreach ( self::encodings( $key ) as $variant ) {
                if ( false !== strpos( $content, $variant ) ) {
                    $content = str_replace( $variant, $s['to'], $content );
                }
            }
        }
        return array( 'content' => $content, 'swapped' => $swapped, 'emptied' => $emptied );
    }

    /** The <img> a block owns: the first one, or a cover's background image. */
    private static function own_img( $name, $seg ) {
        if ( ! preg_match_all( '/<img\b[^>]*>/i', $seg, $imgs ) ) {
            return '';
        }
        foreach ( $imgs[0] as $tag ) {
            if ( 'image' === $name || false !== strpos( self::attr( $tag, 'class' ), 'wp-block-cover__image-background' ) ) {
                return $tag;
            }
        }
        return '';
    }

    private static function attr( $tag, $attr ) {
        if ( preg_match( '/\s' . $attr . '\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $a ) ) {
            return isset( $a[3] ) && '' !== $a[3] ? $a[3] : $a[2];
        }
        return '';
    }

    private static function set_attr( $tag, $attr, $value ) {
        $esc = htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8', false );
        $re  = '/(\s' . $attr . '\s*=\s*)("[^"]*"|\'[^\']*\')/i';
        if ( preg_match( $re, $tag ) ) {
            return preg_replace_callback( $re, function ( $mm ) use ( $esc ) {
                return $mm[1] . '"' . $esc . '"';
            }, $tag, 1 );
        }
        // Appended last, where core's save puts the class.
        return preg_replace_callback( '/\s*(\/?>)$/', function ( $mm ) use ( $attr, $esc ) {
            return ' ' . $attr . '="' . $esc . '"' . $mm[1];
        }, $tag, 1 );
    }

    private static function remove_attr( $tag, $attr ) {
        return preg_replace( '/\s' . $attr . '\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $tag, 1 );
    }

    /** `wp-image-N` on the <img>, replacing any earlier wp-image-*. */
    private static function set_image_class( $tag, $id ) {
        $class = trim( preg_replace( '/\bwp-image-\d+\b/', '', self::attr( $tag, 'class' ) ) );
        $class = trim( preg_replace( '/\s+/', ' ', $class . ' wp-image-' . (int) $id ) );
        return self::set_attr( $tag, 'class', $class );
    }

    /** Block attributes serialised the way core's serialize_block_attributes() does. */
    public static function encode_attrs( $attrs ) {
        $json = json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $json = preg_replace( '/--/', '\\u002d\\u002d', $json );
        $json = preg_replace( '/</', '\\u003c', $json );
        $json = preg_replace( '/>/', '\\u003e', $json );
        $json = preg_replace( '/&/', '\\u0026', $json );
        $json = preg_replace( '/\\\\"/', '\\u0022', $json );
        return $json;
    }

    /** The forms one URL takes in saved markup. */
    private static function encodings( $url ) {
        $once = htmlspecialchars( $url, ENT_QUOTES, 'UTF-8', false );
        return array_values( array_unique( array(
            str_replace( '&', '&amp;amp;', $url ),
            $once,
            str_replace( '&', '\\u0026', $url ),
            $url,
        ) ) );
    }
}
