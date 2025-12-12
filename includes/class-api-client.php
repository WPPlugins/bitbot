<?php
/**
 * BitBot API Client
 *
 * Handles all communication with the youami.ai API service.
 *
 * @package    BitBot
 * @subpackage BitBot/includes
 * @since      1.0.0
 * @author     youami.ai
 * @license    GPL-2.0-or-later
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * API Client class for communicating with youami.ai backend
 *
 * @since 1.0.0
 */
class BitBot_API_Client {

    /**
     * API endpoint URL
     *
     * @var string
     */
    private $api_url;

    /**
     * Site API key
     *
     * @var string
     */
    private $api_key;

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct() {
        $this->api_url = rtrim( BITBOT_API_URL, '/' ) . '/rpc';
        $this->api_key = get_option( 'bitbot_api_key', '' );
    }

    /**
     * Make RPC request to the API
     *
     * @since 1.0.0
     * @param string $request_tag The RPC request tag.
     * @param array  $payload     Request payload data.
     * @param int    $timeout     Request timeout in seconds (default 60, max 900 for sync).
     * @return array Response with 'success' and 'data' or 'error' keys.
     */
    private function rpc( $request_tag, $payload = array(), $timeout = 60 ) {
        // Add API key to all payloads
        $payload['apiKey'] = $this->api_key;

        // Merge _tag with payload (flat structure)
        $body = array_merge(
            array( '_tag' => $request_tag ),
            $payload
        );

        $args = array(
            'method'  => 'POST',
            'timeout' => $timeout,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
        );

        $response = wp_remote_request( $this->api_url, $args );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'error'   => $response->get_error_message(),
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $result      = json_decode( wp_remote_retrieve_body( $response ), true );

        // Effect RPC returns { _tag: "Success", value: ... } or { _tag: "Failure", error: ... }
        if ( isset( $result['_tag'] ) && $result['_tag'] === 'Failure' ) {
            return array(
                'success' => false,
                'error'   => $result['error'] ?? __( 'Unknown error', 'bitbot' ),
            );
        }

        if ( $status_code >= 400 ) {
            return array(
                'success' => false,
                'error'   => $result['error'] ?? __( 'Request failed', 'bitbot' ),
                'status'  => $status_code,
            );
        }

        return array(
            'success' => true,
            'data'    => $result['value'] ?? $result,
        );
    }

    /**
     * Get site domain
     *
     * @since 1.0.0
     * @return string Site domain.
     */
    private function get_site_domain() {
        $url    = get_site_url();
        $parsed = wp_parse_url( $url );
        return $parsed['host'] ?? '';
    }

    /**
     * Get sitemap URL
     *
     * @since 1.0.0
     * @return string Sitemap URL.
     */
    private function get_sitemap_url() {
        $sitemap_urls = array(
            get_site_url() . '/sitemap.xml',
            get_site_url() . '/sitemap_index.xml',
            get_site_url() . '/wp-sitemap.xml',
        );

        foreach ( $sitemap_urls as $url ) {
            $response = wp_remote_head( $url );
            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                return $url;
            }
        }

        return $sitemap_urls[0];
    }

    /**
     * Register this site with the backend
     *
     * @since 1.0.0
     * @return array API response.
     */
    public function register_site() {
        return $this->rpc( 'RegisterSite', array(
            'domain'     => $this->get_site_domain(),
            'sitemapUrl' => $this->get_sitemap_url(),
        ) );
    }

    /**
     * Regenerate API key and re-register site
     *
     * @since 1.0.0
     * @return array Result with success/error.
     */
    public function regenerate_and_reconnect() {
        // Generate new API key
        $new_api_key = 'bb_' . str_replace( '-', '', wp_generate_uuid4() );
        update_option( 'bitbot_api_key', $new_api_key );

        // Update this instance's key so RPC calls use the new key
        $this->api_key = $new_api_key;

        // Re-register site with new key
        return $this->register_site();
    }

    /**
     * Gather all published content from WordPress
     *
     * @since 1.0.0
     * @return array Array of pages with url, title, and content.
     */
    private function gather_content() {
        $pages = array();

        // Get all published posts and pages
        $post_types = array( 'post', 'page' );
        $args = array(
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        );

        $query = new WP_Query( $args );

        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();

                // Get the full rendered content (with shortcodes processed)
                $content = apply_filters( 'the_content', get_the_content() );

                $pages[] = array(
                    'url'     => get_permalink(),
                    'title'   => get_the_title(),
                    'content' => $content,
                );
            }
            wp_reset_postdata();
        }

        return $pages;
    }

    /**
     * Trigger content sync by gathering local content and sending to API
     *
     * @since 1.0.0
     * @return array API response with sync results.
     */
    public function trigger_sync() {
        // Gather all content locally
        $all_pages = $this->gather_content();

        if ( empty( $all_pages ) ) {
            return array(
                'success' => true,
                'data'    => array(
                    'message' => 'No published content found to sync.',
                    'pagesSaved' => 0,
                ),
            );
        }

        // Batch pages to avoid hitting payload limits (~50 pages per batch)
        $batch_size    = 50;
        $batches       = array_chunk( $all_pages, $batch_size );
        $total_batches = count( $batches );

        // Collect all URLs for cleanup of deleted pages
        $all_urls = array_map( function( $page ) {
            return $page['url'];
        }, $all_pages );

        $total_saved   = 0;
        $total_skipped = 0;
        $total_deleted = 0;
        $chunks_embedded = 0;

        // Send each batch to the API
        foreach ( $batches as $index => $batch ) {
            $payload = array(
                'pages'        => $batch,
                'batchIndex'   => $index,
                'totalBatches' => $total_batches,
            );

            // Include all URLs in the final batch for cleanup of deleted pages
            if ( $index === $total_batches - 1 ) {
                $payload['allUrls'] = $all_urls;
            }

            $result = $this->rpc( 'SyncContent', $payload, 300 ); // 5 min timeout per batch

            if ( ! $result['success'] ) {
                return $result;
            }

            $total_saved   += $result['data']['pagesSaved'] ?? 0;
            $total_skipped += $result['data']['pagesSkipped'] ?? 0;
            $total_deleted += $result['data']['pagesDeleted'] ?? 0;

            if ( isset( $result['data']['chunksEmbedded'] ) ) {
                $chunks_embedded = $result['data']['chunksEmbedded'];
            }
        }

        // Build message
        $message_parts = array();
        if ( $total_saved > 0 ) {
            $message_parts[] = sprintf( '%d pages synced', $total_saved );
        }
        if ( $total_skipped > 0 ) {
            $message_parts[] = sprintf( '%d unchanged', $total_skipped );
        }
        if ( $total_deleted > 0 ) {
            $message_parts[] = sprintf( '%d removed', $total_deleted );
        }
        return array(
            'success' => true,
            'data'    => array(
                'status'          => 'completed',
                'pagesSaved'      => $total_saved,
                'pagesSkipped'    => $total_skipped,
                'pagesDeleted'    => $total_deleted,
                'chunksEmbedded'  => $chunks_embedded,
                'message'         => 'Sync complete! ' . implode( ', ', $message_parts ) . '.',
            ),
        );
    }

    /**
     * Get sync status
     *
     * @since 1.0.0
     * @return array API response.
     */
    public function get_sync_status() {
        return $this->rpc( 'GetSyncStatus' );
    }

    /**
     * Send chat message
     *
     * @since 1.0.0
     * @param string $message    Chat message.
     * @param array  $history    Chat history.
     * @param string $session_id Session identifier.
     * @param string $visitor_ip Visitor IP address.
     * @return array API response.
     */
    public function chat( $message, $history = array(), $session_id = '', $visitor_ip = '', $site_language = 'en' ) {
        return $this->rpc( 'Chat', array(
            'message'      => sanitize_text_field( $message ),
            'history'      => array_map( function( $msg ) {
                return array(
                    'role'    => sanitize_text_field( $msg['role'] ),
                    'content' => sanitize_text_field( $msg['content'] ),
                );
            }, $history ),
            'sessionId'    => sanitize_text_field( $session_id ),
            'visitorIp'    => sanitize_text_field( $visitor_ip ),
            'siteLanguage' => sanitize_text_field( $site_language ),
        ) );
    }

    /**
     * Generate blog post
     *
     * @since 1.0.0
     * @param string      $source_type  Source type (prompt, url, upload).
     * @param string      $source_value Source content.
     * @param string      $instructions Additional instructions.
     * @param string|null $file_name    File name for uploads.
     * @return array API response.
     */
    public function generate_post( $source_type, $source_value, $instructions = '', $file_name = null ) {
        $payload = array(
            'sourceType'  => sanitize_text_field( $source_type ),
            'sourceValue' => $source_value, // May be base64 for uploads
        );

        if ( ! empty( $instructions ) ) {
            $payload['instructions'] = sanitize_textarea_field( $instructions );
        }

        if ( ! empty( $file_name ) ) {
            $payload['fileName'] = sanitize_file_name( $file_name );
        }

        return $this->rpc( 'GeneratePost', $payload );
    }

    /**
     * Discover topic suggestions for new content
     *
     * @since 1.0.0
     * @return array API response.
     */
    public function discover_topics() {
        return $this->rpc( 'DiscoverTopics' );
    }

    /**
     * Analyze the site's writing style
     *
     * @since 1.0.0
     * @return array API response.
     */
    public function analyze_style() {
        return $this->rpc( 'AnalyzeStyle' );
    }

    /**
     * Get generated posts (drafts)
     *
     * @since 1.0.0
     * @return array API response.
     */
    public function get_drafts() {
        return $this->rpc( 'GetDrafts' );
    }

    /**
     * Get a specific draft
     *
     * @since 1.0.0
     * @param string $draft_id Draft ID.
     * @return array API response.
     */
    public function get_draft( $draft_id ) {
        return $this->rpc( 'GetDraft', array(
            'draftId' => sanitize_text_field( $draft_id ),
        ) );
    }

    /**
     * Schedule a draft for publishing
     *
     * @since 1.0.0
     * @param string $draft_id     Draft ID.
     * @param string $scheduled_at Scheduled timestamp.
     * @return array API response.
     */
    public function schedule_draft( $draft_id, $scheduled_at ) {
        return $this->rpc( 'ScheduleDraft', array(
            'draftId'     => sanitize_text_field( $draft_id ),
            'scheduledAt' => sanitize_text_field( $scheduled_at ),
        ) );
    }

    /**
     * Delete a draft
     *
     * @since 1.0.0
     * @param string $draft_id Draft ID.
     * @return array API response.
     */
    public function delete_draft( $draft_id ) {
        return $this->rpc( 'DeleteDraft', array(
            'draftId' => sanitize_text_field( $draft_id ),
        ) );
    }

    /**
     * Update draft status
     *
     * @since 1.0.0
     * @param string $draft_id Draft ID.
     * @param string $status   New status.
     * @return array API response.
     */
    public function update_draft_status( $draft_id, $status ) {
        return $this->rpc( 'UpdateDraftStatus', array(
            'draftId' => sanitize_text_field( $draft_id ),
            'status'  => sanitize_text_field( $status ),
        ) );
    }

    /**
     * Get usage stats
     *
     * @since 1.0.0
     * @return array API response.
     */
    public function get_usage() {
        return $this->rpc( 'GetUsage' );
    }

    /**
     * Check subscription status
     *
     * @since 1.0.0
     * @return array API response.
     */
    public function get_subscription() {
        return $this->rpc( 'GetSubscription' );
    }

    /**
     * Create Stripe checkout session
     *
     * @since 1.0.0
     * @return array API response with checkoutUrl.
     */
    public function create_checkout() {
        $admin_url = admin_url( 'admin.php?page=bitbot' );
        return $this->rpc( 'CreateCheckout', array(
            'successUrl' => esc_url_raw( $admin_url . '&subscription=success' ),
            'cancelUrl'  => esc_url_raw( $admin_url . '&subscription=cancelled' ),
        ) );
    }

    /**
     * Cancel subscription
     *
     * @since 1.0.0
     * @return array API response.
     */
    public function cancel_subscription() {
        return $this->rpc( 'CancelSubscription' );
    }

    /**
     * Reactivate a cancelled subscription
     *
     * @since 1.0.0
     * @return array API response.
     */
    public function reactivate_subscription() {
        return $this->rpc( 'ReactivateSubscription' );
    }

    /**
     * Get billing portal URL
     *
     * @since 1.0.0
     * @return array API response with portal URL.
     */
    public function get_billing_portal() {
        $return_url = admin_url( 'admin.php?page=bitbot' );
        return $this->rpc( 'GetBillingPortal', array(
            'returnUrl' => esc_url_raw( $return_url ),
        ) );
    }

    /**
     * Get conversations for this site
     *
     * @since 1.0.0
     * @param int|null $limit Maximum number of conversations.
     * @return array API response.
     */
    public function get_conversations( $limit = null ) {
        $payload = array();
        if ( $limit !== null ) {
            $payload['limit'] = absint( $limit );
        }
        return $this->rpc( 'GetConversations', $payload );
    }

    /**
     * Get messages for a specific conversation
     *
     * @since 1.0.0
     * @param string $conversation_id Conversation ID.
     * @return array API response.
     */
    public function get_conversation_messages( $conversation_id ) {
        return $this->rpc( 'GetConversationMessages', array(
            'conversationId' => sanitize_text_field( $conversation_id ),
        ) );
    }

    /**
     * Get indexed pages
     *
     * @since 1.0.0
     * @return array API response with pages list.
     */
    public function get_pages() {
        return $this->rpc( 'GetPages' );
    }

    /**
     * Add a page to index
     *
     * @since 1.0.0
     * @param string $url URL to add.
     * @return array API response.
     */
    public function add_page( $url ) {
        return $this->rpc( 'AddPage', array(
            'url' => esc_url_raw( $url ),
        ) );
    }

    /**
     * Remove a page from index
     *
     * @since 1.0.0
     * @param string $page_id Page ID.
     * @return array API response.
     */
    public function remove_page( $page_id ) {
        return $this->rpc( 'RemovePage', array(
            'pageId' => sanitize_text_field( $page_id ),
        ) );
    }

    /**
     * Enable/disable a page for chat
     *
     * @since 1.0.0
     * @param string $page_id Page ID.
     * @param bool   $enabled Whether to enable or disable.
     * @return array API response.
     */
    public function toggle_page( $page_id, $enabled ) {
        return $this->rpc( 'TogglePage', array(
            'pageId'  => sanitize_text_field( $page_id ),
            'enabled' => (bool) $enabled,
        ) );
    }

    /**
     * Resync a specific page by gathering its content from WordPress
     *
     * @since 1.0.0
     * @param string $page_url URL of the page to resync.
     * @return array API response.
     */
    public function resync_page( $page_url ) {
        // Find the WordPress post by URL
        $post_id = url_to_postid( $page_url );

        if ( ! $post_id ) {
            return array(
                'success' => false,
                'error'   => __( 'Could not find WordPress post for this URL', 'bitbot' ),
            );
        }

        $post = get_post( $post_id );

        if ( ! $post || $post->post_status !== 'publish' ) {
            return array(
                'success' => false,
                'error'   => __( 'Post not found or not published', 'bitbot' ),
            );
        }

        // Get the rendered content
        $content = apply_filters( 'the_content', $post->post_content );

        return $this->rpc( 'ResyncPage', array(
            'url'     => esc_url_raw( $page_url ),
            'title'   => get_the_title( $post_id ),
            'content' => $content,
        ), 120 ); // 2 min timeout for resync
    }

    /**
     * Create WordPress post from draft
     *
     * @since 1.0.0
     * @param string $draft_id Draft ID.
     * @return array Response with post_id or error.
     */
    public function create_wp_post_from_draft( $draft_id ) {
        $result = $this->get_draft( $draft_id );

        if ( ! $result['success'] ) {
            return $result;
        }

        $draft = $result['data'];
        $seo   = $draft['seoMeta'] ?? array();

        // Create WordPress post
        $post_data = array(
            'post_title'   => sanitize_text_field( $draft['title'] ),
            'post_content' => wp_kses_post( $draft['content'] ),
            'post_status'  => 'draft',
            'post_type'    => 'post',
            'post_name'    => sanitize_title( $draft['slug'] ?? $draft['title'] ),
        );

        $post_id = wp_insert_post( $post_data );

        if ( is_wp_error( $post_id ) ) {
            return array(
                'success' => false,
                'error'   => $post_id->get_error_message(),
            );
        }

        // Add SEO meta if Yoast or similar is available
        if ( ! empty( $seo['metaTitle'] ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( $seo['metaTitle'] ) );
        }
        if ( ! empty( $seo['metaDescription'] ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_text_field( $seo['metaDescription'] ) );
        }
        if ( ! empty( $seo['focusKeyphrase'] ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_focuskw', sanitize_text_field( $seo['focusKeyphrase'] ) );
        }

        // Store keywords as custom field
        if ( ! empty( $seo['keywords'] ) && is_array( $seo['keywords'] ) ) {
            $keywords = array_map( 'sanitize_text_field', $seo['keywords'] );
            update_post_meta( $post_id, '_bitbot_keywords', implode( ', ', $keywords ) );
        }

        return array(
            'success' => true,
            'data'    => array(
                'post_id'  => $post_id,
                'edit_url' => get_edit_post_link( $post_id, 'raw' ),
            ),
        );
    }
}
