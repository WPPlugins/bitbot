<?php
/**
 * Gather v2, pass 1: the structured fields added to every page item.
 *
 * Only called when the backend has switched gather v2 on for this site.
 *
 * @package BitBot
 * @since   2.2.17
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BitBot_Gather_Fields {

    const GATHER_VERSION = 2;
    const ATTACHMENT_CAP = 50;

    /**
     * @param WP_Post $post
     * @param string  $the_content Already-filtered the_content for this post.
     * @param string  $built_with  Result of BitBot_API_Client::detect_built_with.
     * @return array Fields to merge into the page item.
     */
    public static function for_post( $post, $the_content, $built_with ) {
        $out = array(
            'gatherVersion' => self::GATHER_VERSION,
            'postType'      => (string) $post->post_type,
            'modifiedGmt'   => (string) $post->post_modified_gmt,
            'structured'    => array(
                'taxonomies'    => self::taxonomies( $post ),
                'featuredImage' => self::featured_image( $post ),
                'attachments'   => self::attachments( $post ),
                'pdfs'          => self::pdfs( $post, $the_content ),
                'product'       => self::product( $post ),
                'builder'       => self::builder_name( $built_with ),
                'builderText'   => self::builder_text( $post, $built_with ),
            ),
        );
        return $out;
    }

    /** `<theme>-<version>|<sha1 of sorted active plugin slugs>` for the backend's decision reset. */
    public static function site_fingerprint() {
        $theme   = wp_get_theme();
        $plugins = (array) get_option( 'active_plugins', array() );
        if ( is_multisite() ) {
            $plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
        }
        return BitBot_Gather_Fingerprint::build( (string) get_stylesheet(), (string) $theme->get( 'Version' ), $plugins );
    }

    /** Spec names: elementor | gutenberg | classic | others as detected. */
    public static function builder_name( $built_with ) {
        $b = (string) $built_with;
        return 'block' === $b ? 'gutenberg' : $b;
    }

    private static function taxonomies( $post ) {
        $out = array();
        foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
            if ( empty( $tax->public ) ) {
                continue;
            }
            $terms = get_the_terms( $post->ID, $tax->name );
            if ( is_array( $terms ) && $terms ) {
                $names = array();
                foreach ( $terms as $t ) {
                    $names[] = (string) $t->name;
                }
                $out[ $tax->name ] = $names;
            }
        }
        return $out;
    }

    /** Dimensions of the ORIGINAL attachment (not a resized file). */
    private static function dims( $attachment_id ) {
        $meta = wp_get_attachment_metadata( $attachment_id );
        return array(
            'width'  => is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : 0,
            'height' => is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : 0,
        );
    }

    private static function featured_image( $post ) {
        $id = (int) get_post_thumbnail_id( $post->ID );
        if ( ! $id ) {
            return null;
        }
        $url = (string) wp_get_attachment_url( $id );
        if ( '' === $url ) {
            return null;
        }
        return array_merge( array(
            'url' => $url,
            'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
        ), self::dims( $id ) );
    }

    /** Images and PDFs whose post_parent is this post (verified, not guessed). */
    private static function attachments( $post ) {
        $kids = get_children( array(
            'post_parent' => $post->ID,
            'post_type'   => 'attachment',
            'numberposts' => self::ATTACHMENT_CAP,
            'post_status' => 'inherit',
        ) );
        $out = array();
        foreach ( (array) $kids as $k ) {
            $mime = (string) $k->post_mime_type;
            if ( 0 !== strpos( $mime, 'image/' ) && 'application/pdf' !== $mime ) {
                continue;
            }
            $url = (string) wp_get_attachment_url( $k->ID );
            if ( '' === $url ) {
                continue;
            }
            $out[] = array_merge( array( 'url' => $url, 'mime' => $mime ), self::dims( $k->ID ) );
        }
        return $out;
    }

    private static function pdfs( $post, $the_content ) {
        $pdfs = array();
        $kids = get_children( array( 'post_parent' => $post->ID, 'post_type' => 'attachment', 'post_mime_type' => 'application/pdf', 'numberposts' => self::ATTACHMENT_CAP ) );
        foreach ( (array) $kids as $k ) {
            $u = (string) wp_get_attachment_url( $k->ID );
            if ( '' !== $u ) {
                $pdfs[ $u ] = true;
            }
        }
        foreach ( array( (string) $post->post_content, (string) $the_content ) as $src ) {
            if ( preg_match_all( '/href=["\']([^"\']+\.pdf(?:\?[^"\']*)?)["\']/i', $src, $mm ) ) {
                foreach ( $mm[1] as $u ) {
                    $pdfs[ $u ] = true;
                }
            }
        }
        return array_keys( $pdfs );
    }

    private static function product( $post ) {
        if ( 'product' !== $post->post_type || ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
            return null;
        }
        try {
            $p = wc_get_product( $post->ID );
            if ( ! $p ) {
                return null;
            }
            $attrs = array();
            foreach ( (array) $p->get_attributes() as $name => $attr ) {
                if ( is_object( $attr ) && method_exists( $attr, 'is_taxonomy' ) ) {
                    $label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $attr->get_name() ) : $attr->get_name();
                    $vals  = $attr->is_taxonomy() ? (array) wc_get_product_terms( $p->get_id(), $attr->get_name(), array( 'fields' => 'names' ) ) : (array) $attr->get_options();
                    $attrs[ $label ] = array_values( array_map( 'strval', $vals ) );
                } else {
                    $attrs[ (string) $name ] = array( (string) $attr );
                }
            }
            return array(
                'type'             => (string) $p->get_type(),
                'regularPrice'     => (string) $p->get_regular_price(),
                'salePrice'        => (string) $p->get_sale_price(),
                'priceHtml'        => (string) $p->get_price_html(),
                'stockStatus'      => (string) $p->get_stock_status(),
                'stockQuantity'    => $p->get_stock_quantity(),
                'sku'              => (string) $p->get_sku(),
                'attributes'       => $attrs,
                'shortDescription' => (string) $p->get_short_description(),
            );
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /** Elementor in-process (no HTTP). Other builders: null (the_content only). */
    private static function builder_text( $post, $built_with ) {
        if ( 'elementor' !== (string) $built_with || ! class_exists( '\Elementor\Plugin' ) ) {
            return null;
        }
        try {
            $plugin = \Elementor\Plugin::$instance;
            if ( ! $plugin || empty( $plugin->frontend ) || ! method_exists( $plugin->frontend, 'get_builder_content_for_display' ) ) {
                return null;
            }
            $html = (string) $plugin->frontend->get_builder_content_for_display( $post->ID );
            if ( '' === $html ) {
                return null;
            }
            $html = preg_replace( '#</(p|div|h[1-6]|li|tr|section|br)\s*>|<br\s*/?>#i', "\n", $html );
            $text = wp_strip_all_tags( $html );
            $text = trim( preg_replace( "/[ \t]+\n|\n{3,}/", "\n\n", $text ) );
            return '' === $text ? null : $text;
        } catch ( \Throwable $e ) {
            return null;
        }
    }
}
