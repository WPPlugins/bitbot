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
    private function rpc( $request_tag, $payload = array(), $timeout = 60, $blocking = true ) {
        // Add API key to all payloads
        $payload['apiKey'] = $this->api_key;

        // Merge _tag with payload (flat structure)
        $body = array_merge(
            array( '_tag' => $request_tag ),
            $payload
        );

        $args = array(
            'method'    => 'POST',
            'timeout'   => $timeout,
            'blocking'  => (bool) $blocking,
            'headers'   => array(
                'Content-Type' => 'application/json',
            ),
            'body'      => wp_json_encode( $body ),
        );

        $response = wp_remote_request( $this->api_url, $args );

        // Non-blocking: wp_remote_request returns immediately with an empty
        // response. The backend still receives and processes the POST — we
        // just don't wait around for the result. Used for background sync
        // from wp-cron, where a 15s-per-batch blocking POST would routinely
        // get killed by tight cron execution budgets on shared hosts.
        if ( ! $blocking ) {
            return array(
                'success' => true,
                'data'    => array( 'async' => true ),
            );
        }

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
        $admin_email = sanitize_email( get_option( 'admin_email', '' ) );

        $payload = array(
            'domain'     => $this->get_site_domain(),
            'sitemapUrl' => $this->get_sitemap_url(),
        );
        if ( ! empty( $admin_email ) ) {
            $payload['notificationEmail'] = $admin_email;
        }

        return $this->rpc( 'RegisterSite', $payload );
    }

    /**
     * Update the email address that receives new-lead notifications.
     *
     * @since 1.5.2
     * @param string $email Email address (empty string disables notifications).
     * @return array API response.
     */
    public function update_notification_email( $email ) {
        return $this->rpc( 'UpdateNotificationEmail', array(
            'notificationEmail' => sanitize_email( $email ),
        ) );
    }

    /**
     * Push WooCommerce REST API credentials to the backend for order-enquiry skill.
     *
     * @since 1.7.0
     * @param string $consumer_key    WooCommerce REST consumer key.
     * @param string $consumer_secret WooCommerce REST consumer secret.
     * @return array API response.
     */
    public function update_wc_credentials( $consumer_key, $consumer_secret ) {
        return $this->rpc( 'UpdateWcCredentials', array(
            'consumerKey'    => (string) $consumer_key,
            'consumerSecret' => (string) $consumer_secret,
        ) );
    }

    /**
     * Push appointment availability config + weekly hours to backend.
     *
     * @since 1.7.0
     * @param array $config Keys: enabled (bool), serviceName (string),
     *        durationMinutes (int), bufferMinutes (int), timezone (string),
     *        leadTimeHours (int), maxDaysAhead (int), weeklyHours (array of
     *        { dayOfWeek:int, startTime:string, endTime:string }).
     * @return array API response.
     */
    public function update_availability( $config ) {
        return $this->rpc( 'UpdateAvailability', $config );
    }

    /**
     * Push cart-recovery discount config to backend.
     *
     * @since 1.7.0
     * @param bool   $enabled
     * @param string $code
     * @param string $description
     * @return array
     */
    public function update_recovery_config( $enabled, $code, $description ) {
        return $this->rpc( 'UpdateRecoveryConfig', array(
            'enabled'     => (bool) $enabled,
            'code'        => (string) $code,
            'description' => (string) $description,
        ) );
    }

    /**
     * Admin presence heartbeat. Kept short-timeout since latency matters
     * here — the agent uses this to decide whether to offer live handover.
     *
     * @since 1.7.0
     * @return array
     */
    public function admin_heartbeat() {
        return $this->rpc( 'AdminHeartbeat', array(), 10 );
    }

    /**
     * @since 1.7.0
     * @return array { chats: [...] }
     */
    public function get_live_chats() {
        return $this->rpc( 'GetLiveChats' );
    }

    /**
     * @since 1.7.0
     * @param string $conversation_id
     * @return array { messages: [...] }
     */
    public function get_live_chat_messages( $conversation_id ) {
        return $this->rpc( 'GetLiveChatMessages', array(
            'conversationId' => (string) $conversation_id,
        ) );
    }

    /**
     * @since 1.7.0
     * @param string $conversation_id
     * @param string $message
     * @return array
     */
    public function admin_reply_live( $conversation_id, $message ) {
        return $this->rpc( 'AdminReplyLive', array(
            'conversationId' => (string) $conversation_id,
            'message'        => (string) $message,
        ) );
    }

    /**
     * @since 1.7.0
     * @param string $conversation_id
     * @return array
     */
    public function close_live_thread( $conversation_id ) {
        return $this->rpc( 'CloseLiveThread', array(
            'conversationId' => (string) $conversation_id,
        ) );
    }

    /**
     * Count of live-and-fresh threads for the WP admin submenu badge.
     * Cheap — single COUNT query after lazy stale-thread expiry.
     *
     * @since 1.7.0
     * @return array { count: int }
     */
    public function get_live_badge_count() {
        return $this->rpc( 'GetLiveBadgeCount', array(), 5 );
    }

    /**
     * Widget poll endpoint — gets new admin messages + current live_mode
     * state. No API key exposed to the browser; called from the plugin's
     * REST bridge.
     *
     * @since 1.7.0
     * @param string $session_id
     * @param string $since_message_id
     * @return array
     */
    public function get_live_chat_updates( $session_id, $since_message_id = '' ) {
        return $this->rpc( 'GetLiveChatUpdates', array(
            'sessionId'      => (string) $session_id,
            'sinceMessageId' => (string) $since_message_id,
        ), 10 );
    }

    /**
     * Heartbeat — called every 30s by the widget while it's open and
     * the page is visible. Stamps visitor_last_seen_at so the admin
     * dashboard can render presence.
     *
     * @since 1.7.0
     * @param string $session_id
     * @return array
     */
    public function visitor_heartbeat( $session_id ) {
        return $this->rpc( 'VisitorHeartbeat', array(
            'sessionId' => (string) $session_id,
        ), 5 );
    }

    /**
     * Visitor-left — fired from beforeunload via sendBeacon. Stamps
     * visitor_left_at so the admin sees "left" immediately.
     *
     * @since 1.7.0
     * @param string $session_id
     * @return array
     */
    public function visitor_left( $session_id ) {
        return $this->rpc( 'VisitorLeft', array(
            'sessionId' => (string) $session_id,
        ), 5 );
    }

    /**
     * Admin-initiated takeover. Flips the conversation into live_mode
     * server-side so the agent stops replying and the widget switches
     * to poll-for-admin-replies mode on its next heartbeat (or on the
     * visitor's next message, whichever fires first).
     *
     * @since 1.7.0
     * @param string $conversation_id
     * @param string $opener Optional first admin message so the visitor
     *                      sees "A teammate just joined" instantly.
     * @return array
     */
    public function admin_take_over_chat( $conversation_id, $opener = '' ) {
        return $this->rpc( 'AdminTakeOverChat', array(
            'conversationId' => (string) $conversation_id,
            'opener'         => (string) $opener,
        ), 10 );
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

        // Every publicly-visible post type the site declares — covers core
        // post/page, WooCommerce 'product', LearnDash courses, Tribe Events,
        // Jetpack Portfolio, BetterDocs, and any CPT a theme/plugin registers
        // with public => true. Without this, WooCommerce shops sync zero
        // products and the chatbot has nothing real to suggest.
        //
        // NOTE: do NOT add 'publicly_queryable' => true to the filter — core's
        // 'page' CPT is registered without that flag explicitly set to true,
        // so it gets silently filtered out and every site loses page content.
        $post_types = array_keys(
            get_post_types(
                array( 'public' => true ),
                'names'
            )
        );

        // Drop post types that are template/internal fragments, never visitor
        // narrative content. 'attachment' = media files. The rest are page-
        // builder libraries and block-theme internals that public=true accepts
        // but shouldn't be in chat context.
        $exclude = array(
            'attachment',
            'elementor_library',
            'e-floating-buttons',
            'ae_global_templates',
            'oxy_template',
            'bricks_template',
            'et_pb_layout',
            'et_template',
            'wp_block',
            'wp_template',
            'wp_template_part',
            'wp_global_styles',
            'wp_navigation',
        );
        $post_types = array_values( array_diff( $post_types, $exclude ) );

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
     * Trigger content sync by gathering local content and sending to API.
     *
     * @since 1.0.0
     * @param bool $async When true, fire each batch POST non-blocking
     *                    (fire-and-forget). Used by wp-cron-driven first/
     *                    retry syncs where tight execution budgets kill
     *                    blocking POSTs mid-flight. Backend still receives
     *                    and processes the payload; we just don't wait for
     *                    pagesSaved/chunksEmbedded totals to come back.
     * @return array API response with sync results (blocking) or
     *               { success: true, data: { async: true } } (async).
     */
    public function trigger_sync( $async = false ) {
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

            $result = $this->rpc( 'SyncContent', $payload, 300, ! $async );

            if ( $async ) {
                // Non-blocking: no per-batch totals to aggregate. Backend
                // still embeds + sets last_sync_at when the final batch
                // arrives. Move to the next batch immediately.
                continue;
            }

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

        if ( $async ) {
            return array(
                'success' => true,
                'data'    => array(
                    'message'      => sprintf( '%d pages queued for sync', count( $all_pages ) ),
                    'async'        => true,
                    'totalBatches' => $total_batches,
                ),
            );
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
    public function chat( $message, $history = array(), $session_id = '', $visitor_ip = '', $site_language = 'en', $visitor_id = '', $visitor_timezone = '' ) {
        return $this->rpc( 'Chat', array(
            'message'         => sanitize_text_field( $message ),
            'history'         => array_map( function( $msg ) {
                return array(
                    'role'    => sanitize_text_field( $msg['role'] ),
                    'content' => sanitize_text_field( $msg['content'] ),
                );
            }, $history ),
            'sessionId'       => sanitize_text_field( $session_id ),
            'visitorIp'       => sanitize_text_field( $visitor_ip ),
            'siteLanguage'    => sanitize_text_field( $site_language ),
            'visitorId'       => sanitize_text_field( $visitor_id ),
            'visitorTimezone' => sanitize_text_field( $visitor_timezone ),
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
     * Get presigned upload URL for direct file upload to Supabase Storage
     *
     * @since 1.0.0
     * @param string $file_name Original file name (used for extension validation).
     * @return array API response with uploadUrl, storagePath, token.
     */
    public function get_upload_url( $file_name ) {
        return $this->rpc( 'GetUploadUrl', array(
            'fileName' => sanitize_file_name( $file_name ),
        ) );
    }

    /**
     * Upload file directly to Supabase Storage using presigned URL
     *
     * @since 1.0.0
     * @param string $upload_url  Presigned upload URL from get_upload_url().
     * @param string $file_path   Local file path.
     * @param string $mime_type   File MIME type.
     * @return array Result with success/error.
     */
    public function upload_file_to_storage( $upload_url, $file_path, $mime_type ) {
        if ( ! file_exists( $file_path ) ) {
            return array(
                'success' => false,
                'error'   => __( 'File not found', 'bitbot' ),
            );
        }

        $file_contents = file_get_contents( $file_path );
        if ( $file_contents === false ) {
            return array(
                'success' => false,
                'error'   => __( 'Could not read file', 'bitbot' ),
            );
        }

        $response = wp_remote_request( $upload_url, array(
            'method'  => 'PUT',
            'timeout' => 120,
            'headers' => array(
                'Content-Type' => $mime_type,
            ),
            'body'    => $file_contents,
        ) );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'error'   => $response->get_error_message(),
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code >= 400 ) {
            return array(
                'success' => false,
                'error'   => sprintf( __( 'Upload failed with status %d', 'bitbot' ), $status_code ),
            );
        }

        return array( 'success' => true );
    }

    /**
     * Generate blog post from file uploaded to Supabase Storage
     *
     * @since 1.0.0
     * @param string $storage_path Path in Supabase Storage.
     * @param string $file_name    Original file name.
     * @param string $instructions Additional instructions.
     * @return array API response.
     */
    public function generate_post_from_storage( $storage_path, $file_name, $instructions = '' ) {
        $payload = array(
            'storagePath' => sanitize_text_field( $storage_path ),
            'fileName'    => sanitize_file_name( $file_name ),
        );

        if ( ! empty( $instructions ) ) {
            $payload['instructions'] = sanitize_textarea_field( $instructions );
        }

        return $this->rpc( 'GeneratePostFromStorage', $payload, 300 ); // 5 min timeout for generation
    }

    /**
     * Generate blog post from uploaded file (handles full flow)
     *
     * This method handles the complete upload flow:
     * 1. Get presigned upload URL from API
     * 2. Upload file directly to Supabase Storage
     * 3. Generate post from the uploaded file
     *
     * @since 1.0.0
     * @param string $file_path    Local file path.
     * @param string $file_name    Original file name.
     * @param string $mime_type    File MIME type.
     * @param string $instructions Additional instructions.
     * @return array API response.
     */
    public function generate_post_from_file( $file_path, $file_name, $mime_type, $instructions = '' ) {
        // Step 1: Get presigned upload URL
        $url_result = $this->get_upload_url( $file_name );
        if ( ! $url_result['success'] ) {
            return $url_result;
        }

        $upload_url   = $url_result['data']['uploadUrl'];
        $storage_path = $url_result['data']['storagePath'];

        // Step 2: Upload file to Supabase Storage
        $upload_result = $this->upload_file_to_storage( $upload_url, $file_path, $mime_type );
        if ( ! $upload_result['success'] ) {
            return $upload_result;
        }

        // Step 3: Generate post from the uploaded file
        return $this->generate_post_from_storage( $storage_path, $file_name, $instructions );
    }

    /**
     * Generate blog post from a public document URL
     *
     * @since 1.0.0
     * @param string $doc_url      Public URL to a PDF or image file.
     * @param string $file_name    File name (for type detection).
     * @param string $instructions Additional instructions.
     * @return array API response.
     */
    public function generate_post_from_doc_url( $doc_url, $file_name, $instructions = '' ) {
        $payload = array(
            'docUrl'   => esc_url_raw( $doc_url ),
            'fileName' => sanitize_file_name( $file_name ),
        );

        if ( ! empty( $instructions ) ) {
            $payload['instructions'] = sanitize_textarea_field( $instructions );
        }

        return $this->rpc( 'GeneratePostFromDocUrl', $payload, 300 ); // 5 min timeout for generation
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
    public function create_checkout( $tier = 'pro' ) {
        $admin_url = admin_url( 'admin.php?page=bitbot' );
        $tier      = ( $tier === 'pro_plus' ) ? 'pro_plus' : 'pro';
        return $this->rpc( 'CreateCheckout', array(
            'successUrl' => esc_url_raw( $admin_url . '&subscription=success' ),
            'cancelUrl'  => esc_url_raw( $admin_url . '&subscription=cancelled' ),
            'tier'       => $tier,
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
    public function get_conversations( $page = 1, $page_size = null ) {
        $payload = array(
            'page' => max( 1, absint( $page ) ),
        );
        if ( $page_size !== null ) {
            $payload['pageSize'] = absint( $page_size );
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
     * Sync a single page's content to the backend (used by save_post hook)
     *
     * @since 1.5.2
     * @param string $url     Page URL.
     * @param string $title   Page title.
     * @param string $content Rendered page content.
     * @return array API response.
     */
    public function sync_single_page( $url, $title, $content ) {
        return $this->rpc( 'SyncContent', array(
            'pages'        => array( array(
                'url'     => esc_url_raw( $url ),
                'title'   => sanitize_text_field( $title ),
                'content' => $content,
            ) ),
            'batchIndex'   => 0,
            'totalBatches' => 1,
        ), 120 );
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

    // ============================================
    // Leads & Forms API Methods
    // ============================================

    /**
     * Submit a form (creates a lead)
     *
     * @since 1.1.0
     * @param string $name    Lead name.
     * @param string $email   Lead email.
     * @param string $phone   Lead phone (optional).
     * @param string $message Lead message (optional).
     * @return array API response.
     */
    public function submit_form( $form_id, $name, $email, $phone = '', $message = '', $ip_address = '' ) {
        $payload = array(
            'formId' => sanitize_text_field( $form_id ),
            'name'   => sanitize_text_field( $name ),
            'email'  => sanitize_email( $email ),
        );
        if ( ! empty( $phone ) ) {
            $payload['phone'] = sanitize_text_field( $phone );
        }
        if ( ! empty( $message ) ) {
            $payload['message'] = sanitize_textarea_field( $message );
        }
        if ( ! empty( $ip_address ) ) {
            $payload['ipAddress'] = sanitize_text_field( $ip_address );
        }
        return $this->rpc( 'SubmitForm', $payload );
    }

    /**
     * Get leads list
     *
     * @since 1.1.0
     * @param string $stage  Filter by stage (optional).
     * @param string $search Search query (optional).
     * @return array API response.
     */
    /**
     * Get the count of leads that haven't been opened yet.
     *
     * @since 1.5.2
     * @return array API response with { count: int }.
     */
    public function get_unviewed_leads_count() {
        return $this->rpc( 'GetUnviewedLeadsCount' );
    }

    /**
     * Get the site's plan + current-period usage + limits.
     * Powers UI gating: watermarks, upgrade CTAs, disabled buttons.
     *
     * @since 1.6.0
     * @return array API response with { plan, limits, usage }.
     */
    public function get_plan_status() {
        return $this->rpc( 'GetPlanStatus' );
    }

    public function get_leads( $stage = '', $search = '', $page = 1, $page_size = 50 ) {
        $payload = array(
            'page'     => max( 1, absint( $page ) ),
            'pageSize' => max( 1, min( 200, absint( $page_size ) ) ),
        );
        if ( ! empty( $stage ) ) {
            $payload['stage'] = sanitize_text_field( $stage );
        }
        if ( ! empty( $search ) ) {
            $payload['search'] = sanitize_text_field( $search );
        }
        return $this->rpc( 'GetLeads', $payload );
    }

    /**
     * Get single lead with activities and emails
     *
     * @since 1.1.0
     * @param string $lead_id Lead ID.
     * @return array API response.
     */
    public function get_lead( $lead_id ) {
        return $this->rpc( 'GetLead', array(
            'leadId' => sanitize_text_field( $lead_id ),
        ) );
    }

    /**
     * Update lead stage
     *
     * @since 1.1.0
     * @param string $lead_id Lead ID.
     * @param string $stage   New stage.
     * @return array API response.
     */
    public function update_lead_stage( $lead_id, $stage ) {
        return $this->rpc( 'UpdateLeadStage', array(
            'leadId' => sanitize_text_field( $lead_id ),
            'stage'  => sanitize_text_field( $stage ),
        ) );
    }

    /**
     * Add note to a lead
     *
     * @since 1.1.0
     * @param string $lead_id Lead ID.
     * @param string $content Note content.
     * @return array API response.
     */
    public function add_lead_note( $lead_id, $content ) {
        return $this->rpc( 'AddLeadNote', array(
            'leadId'  => sanitize_text_field( $lead_id ),
            'content' => sanitize_textarea_field( $content ),
        ) );
    }

    /**
     * Delete a lead
     *
     * @since 1.1.0
     * @param string $lead_id Lead ID.
     * @return array API response.
     */
    public function delete_lead( $lead_id ) {
        return $this->rpc( 'DeleteLead', array(
            'leadId' => sanitize_text_field( $lead_id ),
        ) );
    }

    public function run_lead_research( $lead_id ) {
        return $this->rpc( 'RunLeadResearch', array(
            'leadId' => sanitize_text_field( $lead_id ),
        ) );
    }

    public function draft_lead_email( $lead_id ) {
        return $this->rpc( 'DraftLeadEmail', array(
            'leadId' => sanitize_text_field( $lead_id ),
        ) );
    }

    public function get_forms() {
        return $this->rpc( 'GetForms' );
    }

    public function get_form( $form_id ) {
        return $this->rpc( 'GetForm', array( 'formId' => sanitize_text_field( $form_id ) ) );
    }

    public function create_form( $name, $description, $fields ) {
        return $this->rpc( 'CreateForm', array(
            'name'        => sanitize_text_field( $name ),
            'description' => sanitize_text_field( $description ),
            'fields'      => $fields,
        ) );
    }

    public function update_form( $form_id, $data ) {
        $payload = array( 'formId' => sanitize_text_field( $form_id ) );
        if ( isset( $data['name'] ) )    $payload['name']    = sanitize_text_field( $data['name'] );
        if ( isset( $data['fields'] ) )  $payload['fields']  = $data['fields'];
        if ( isset( $data['enabled'] ) ) $payload['enabled'] = (bool) $data['enabled'];
        return $this->rpc( 'UpdateForm', $payload );
    }

    public function delete_form( $form_id ) {
        return $this->rpc( 'DeleteForm', array( 'formId' => sanitize_text_field( $form_id ) ) );
    }

    public function generate_form( $description ) {
        return $this->rpc( 'GenerateForm', array( 'description' => sanitize_text_field( $description ) ) );
    }

    /**
     * Fetch every appointment for the site for the calendar admin view.
     *
     * Returns up to 500 rows, newest first. The caller is expected to
     * cache the result on the page for FullCalendar to render across
     * month / week / day views without re-hitting the API.
     *
     * @since 1.7.0
     * @return array { appointments: array<object>, siteTimezone: string } | error envelope
     */
    public function get_appointments() {
        return $this->rpc( 'GetAppointments' );
    }

    /**
     * Lightweight count of future-dated, non-cancelled appointments.
     * Used to drive the Calendar menu badge — never the calendar UI
     * itself (that one needs the full payload).
     *
     * @since 1.7.0
     * @return array { count: int } | error envelope
     */
    public function get_upcoming_appointments_count() {
        return $this->rpc( 'GetUpcomingAppointmentsCount' );
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

        // Free plan: append a subtle "Draft by BitBot →" footer to the post body.
        // Any paying tier (Pro or Pro+) leaves the content untouched.
        $content     = $draft['content'];
        $plan_status = $this->get_plan_status();
        $plan        = ! empty( $plan_status['success'] ) ? ( $plan_status['data']['plan'] ?? null ) : null;
        $is_pro      = BitBot_Plan::is_paid( $plan );

        if ( ! $is_pro ) {
            $content .= "\n\n<!-- bitbot-draft-footer -->\n";
            $content .= '<p style="font-size: 12px; color: #888; text-align: center; margin-top: 32px;">';
            $content .= '<em>' . esc_html__( 'Draft by', 'bitbot' ) . ' ';
            $content .= '<a href="https://youami.ai/?utm_source=post&utm_medium=draft_footer&utm_campaign=free_tier" ';
            $content .= 'target="_blank" rel="noopener">BitBot</a> →</em></p>';
        }

        // Create WordPress post
        $post_data = array(
            'post_title'   => sanitize_text_field( $draft['title'] ),
            'post_content' => wp_kses_post( $content ),
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
