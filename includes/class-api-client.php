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
     * @param int    $timeout     Request timeout in seconds (default 90, max 900 for sync).
     *                            Default bumped from 60 to 90 to accommodate the
     *                            BAML retry + 3-tier fallback chain (Haiku → Sonnet →
     *                            GPT-4o-mini). Worst case across 3 providers with 2
     *                            retries each is ~21s of retry overhead plus call time;
     *                            90s gives comfortable margin.
     * @return array Response with 'success' and 'data' or 'error' keys.
     */
    private function rpc( $request_tag, $payload = array(), $timeout = 90, $blocking = true ) {
        // Add API key to all payloads. Re-read from options when the cached
        // value is empty: the API client is built once per request and
        // captures bitbot_api_key at construction. On a fresh install the
        // option is written DURING activation — after this object exists —
        // so $this->api_key stays empty for the rest of that request. A
        // RegisterSite POST with an empty key made the backend persist a
        // sites row with no api_key, and every authenticated RPC then 400s
        // ("Invalid API key") forever, leaving the admin SPA blank.
        if ( empty( $this->api_key ) ) {
            $this->api_key = get_option( 'bitbot_api_key', '' );
        }
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
            $error_msg = $result['error'] ?? __( 'Request failed', 'bitbot' );

            // Self-heal: a 400 "Invalid API key" means the backend has no
            // sites row matching our key (registration never persisted it —
            // e.g. the empty-key poison-row bug). Clear the registered flag
            // and retry throttle so Bitbot::maybe_register_site re-registers
            // on the next admin_init, this time with a populated key. Skip
            // RegisterSite itself so we never clobber its own flow.
            if ( 400 === $status_code
                && 'RegisterSite' !== $request_tag
                && is_string( $error_msg )
                && false !== stripos( $error_msg, 'Invalid API key' ) ) {
                delete_option( 'bitbot_registered' );
                delete_transient( 'bitbot_register_retry' );
            }

            return array(
                'success' => false,
                'error'   => $error_msg,
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

        // Lead notifications are opt-in: we do NOT send the WP admin email as a
        // notification seed. The owner enables them explicitly in the Leads tab.
        $payload = array(
            'domain'     => $this->get_site_domain(),
            'sitemapUrl' => $this->get_sitemap_url(),
        );

        $result = $this->rpc( 'RegisterSite', $payload );

        // 2.0.0: stash the PostHog telemetry config when the backend
        // returns it (env-sourced so the key isn't shipped in the
        // public plugin source). Idempotent — only writes when present
        // and non-empty so a missing env var doesn't blank-out a
        // previously-stored key.
        if ( $result['success'] && isset( $result['data'] ) && is_array( $result['data'] ) ) {
            $this->maybe_store_telemetry_config( $result['data'] );
        }

        // Lead notifications are opt-in — we no longer auto-mirror admin_email
        // into bitbot_notification_email. The option stays empty until the owner
        // sets it in the Leads tab, so "Off" in the UI means genuinely off (the
        // backend reads the same value). No surprise emails to the admin.

        // 2.0.181: record that registration actually reached the backend.
        // The activation-time register_site() can be killed (TasteWP,
        // multisite network-activate, hosting timeouts), leaving a local
        // api key the backend never received — no sites row, no signup
        // notification, every RPC failing. This flag lets the admin_init
        // self-heal (Bitbot::maybe_register_site) stop retrying once it
        // genuinely succeeds.
        if ( $result['success'] ) {
            update_option( 'bitbot_registered', BITBOT_VERSION );
        }

        return $result;
    }

    /**
     * Fetch + persist the PostHog telemetry config from the backend.
     * Used as a backfill for installs that registered BEFORE RegisterSite
     * started returning posthogKey, so they still get telemetry on next
     * admin load.
     *
     * @since 2.0.0
     * @return array Telemetry config { posthogKey, posthogHost } or empty on failure.
     */
    public function fetch_telemetry_config() {
        $result = $this->rpc( 'GetTelemetryConfig', array() );
        if ( $result['success'] && isset( $result['data'] ) && is_array( $result['data'] ) ) {
            $this->maybe_store_telemetry_config( $result['data'] );
            return $result['data'];
        }
        return array();
    }

    /**
     * Store posthogKey + posthogHost in wp_options when present in a
     * backend response. Non-empty values only — don't overwrite an
     * existing stored value with an empty one (defends against an env
     * var being unset mid-rollout).
     *
     * @param array $data Response data from RegisterSite or GetTelemetryConfig.
     */
    private function maybe_store_telemetry_config( $data ) {
        if ( ! empty( $data['posthogKey'] ) ) {
            update_option( 'bitbot_posthog_key', sanitize_text_field( $data['posthogKey'] ) );
        }
        if ( ! empty( $data['posthogHost'] ) ) {
            update_option( 'bitbot_posthog_host', esc_url_raw( $data['posthogHost'] ) );
        }
    }

    /**
     * Update the email address that receives new-lead notifications.
     *
     * @since 1.5.2
     * @param string $email Email address (empty string disables notifications).
     * @return array API response.
     */
    public function update_notification_email( $email ) {
        // 2.0.2: non-blocking — admin types the email char-by-char in
        // the new React SPA, and a blocking RPC per keystroke froze the
        // input. Fire-and-forget; backend picks up the latest on its
        // next read.
        return $this->rpc(
            'UpdateNotificationEmail',
            array( 'notificationEmail' => sanitize_email( $email ) ),
            5,
            false
        );
    }

    /**
     * Push the admin's site-specific custom instructions to the backend.
     * Backend caps to the site's tier (Free 200 / Pro 1000 / Pro+ 4000)
     * and stores in sites.custom_instructions. The Chat agent reads it
     * on every chat call and injects into the system prompt.
     *
     * @since 1.9.0 Feature 8b
     * @param string $instructions Free-text site instructions.
     * @return array API response.
     */
    public function update_instructions( $instructions ) {
        // 2.0.2: non-blocking. Fires from sanitize_custom_instructions on
        // every save (which itself fires on every React-side keystroke
        // because the new admin SPA saves as-you-type). A blocking
        // round-trip per character froze typing on the admin form; now
        // we dispatch fire-and-forget. Backend still picks up the value
        // because update_option also writes the local row, and the
        // backend reads the canonical source on its next chat turn.
        return $this->rpc(
            'UpdateInstructions',
            array( 'instructions' => (string) $instructions ),
            5,     // short timeout
            false  // blocking=false (fire-and-forget)
        );
    }

    /**
     * Push the admin's off-topic guardrails to the backend (Pro / Pro+).
     * For Free sites the backend returns success:false silently —
     * sanitize_off_topic_guardrails already zeroes the value on Free
     * so this should rarely fire on Free.
     *
     * @since 1.9.0 Feature 8l
     * @param string $guardrails Newline-separated topics to refuse.
     * @return array API response.
     */
    public function update_guardrails( $guardrails ) {
        // 2.0.2: non-blocking — same rationale as update_instructions.
        return $this->rpc(
            'UpdateGuardrails',
            array( 'guardrails' => (string) $guardrails ),
            5,     // short timeout
            false  // blocking=false (fire-and-forget)
        );
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
     * Beacon: visitor clicked a cited page link in a bot reply.
     * Drives the Overview Top Cited Pages CTR. Fire-and-forget timing
     * — we use a short RPC timeout because the widget doesn't wait.
     */
    public function record_citation_click( $session_id, $url, $title = '' ) {
        return $this->rpc( 'RecordCitationClick', array(
            'sessionId' => (string) $session_id,
            'url'       => (string) $url,
            'title'     => (string) $title,
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
     * Resolve the set of post types we sync.
     *
     * Extracted from the old gather_content() so both the lightweight ID
     * collector and the per-batch content fetcher share one definition.
     *
     * @since 2.0.3
     * @return array Post type slugs.
     */
    private function get_syncable_post_types() {
        // Every publicly-visible post type the site declares — covers core
        // post/page, WooCommerce 'product', LearnDash courses, Tribe Events,
        // Jetpack Portfolio, BetterDocs, and any CPT a theme/plugin registers
        // with public => true.
        //
        // NOTE: do NOT add 'publicly_queryable' => true to the filter — core's
        // 'page' CPT is registered without that flag explicitly set to true,
        // so it gets silently filtered out and every site loses page content.
        $post_types = array_keys(
            get_post_types( array( 'public' => true ), 'names' )
        );

        // Drop template/internal fragments that are never visitor narrative
        // content. 'attachment' = media files; the rest are page-builder
        // libraries and block-theme internals that public=true accepts but
        // shouldn't be in chat context.
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

        return array_values( array_diff( $post_types, $exclude ) );
    }

    /**
     * Collect the IDs of every published post we sync, newest-modified first.
     *
     * IDs only (no content) so this stays cheap even on 5,000+ page sites —
     * the memory blow-up that fataled large syncs lived in loading all that
     * rendered content at once, not in the ID list.
     *
     * @since 2.0.3
     * @return int[] Post IDs.
     */
    public function gather_post_ids() {
        $query = new WP_Query( array(
            'post_type'              => $this->get_syncable_post_types(),
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'orderby'                => 'modified',
            'order'                  => 'DESC',
            'fields'                 => 'ids',     // IDs only — light
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ) );

        return array_map( 'intval', (array) $query->posts );
    }

    /**
     * Gather rendered content for a specific set of post IDs (one batch).
     *
     * @since 2.0.3
     * @param int[] $ids Post IDs to fetch (a single batch's worth).
     * @return array Array of pages with url, title, and content.
     */
    private function gather_content_for_ids( $ids ) {
        $pages = array();
        if ( empty( $ids ) ) {
            return $pages;
        }

        $query = new WP_Query( array(
            'post_type'              => 'any',
            'post_status'            => 'publish',
            'post__in'               => $ids,
            'orderby'                => 'post__in',
            'posts_per_page'         => count( $ids ),
            'ignore_sticky_posts'    => true,
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
        ) );

        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
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
     * Gather all published content from WordPress
     *
     * @deprecated 2.0.3 Loads every page's content into memory at once, which
     *             fatals on large sites (penteli.gov.gr, ~6k pages). Retained
     *             only for callers that genuinely need the whole set; the sync
     *             path now paginates via gather_post_ids() +
     *             gather_content_for_ids().
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
        // Collect post IDs only (cheap) — then fetch + send content ONE batch
        // at a time, freeing each batch before the next. This caps peak memory
        // at ~50 pages' worth of rendered HTML regardless of total site size.
        // The old path called gather_content() and held every page's content
        // in memory at once, which fataled large sites (penteli.gov.gr ~6k
        // pages → "There has been a critical error" → sync never completed).
        $all_ids = $this->gather_post_ids();

        if ( empty( $all_ids ) ) {
            return array(
                'success' => true,
                'data'    => array(
                    'message' => 'No published content found to sync.',
                    'pagesSaved' => 0,
                ),
            );
        }

        // Batch by ID (~50 posts per batch) — content is fetched per batch.
        $batch_size    = 50;
        $id_batches    = array_chunk( $all_ids, $batch_size );
        $total_batches = count( $id_batches );
        $total_pages   = count( $all_ids );

        // allUrls (for deleted-page cleanup) is accumulated as we walk the
        // batches and attached to the final one — no second full pass.
        $all_urls = array();

        $total_saved   = 0;
        $total_skipped = 0;
        $total_deleted = 0;
        $chunks_embedded = 0;

        // Send each batch to the API
        foreach ( $id_batches as $index => $id_batch ) {
            // Some hosts kill long syncs on max_execution_time. Re-arm the
            // clock per batch (best-effort; safe-mode hosts ignore it).
            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 300 );
            }

            // Fetch THIS batch's content just-in-time.
            $batch = $this->gather_content_for_ids( $id_batch );
            foreach ( $batch as $page ) {
                $all_urls[] = $page['url'];
            }

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

            // Free this batch's content before the next iteration.
            unset( $batch, $payload );

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
                    'message'      => sprintf( '%d pages queued for sync', $total_pages ),
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
     * Send ONE batch of pages to the backend (used by the resumable
     * background-sync cron chain). Content is gathered just-in-time for the
     * given post IDs, so peak memory stays at ~one batch regardless of total
     * site size. Blocking — the cron tick waits for the backend so the next
     * tick only fires after this batch is persisted.
     *
     * @since 2.0.3
     * @param int[]      $batch_ids     Post IDs in this batch.
     * @param int        $batch_index   Zero-based batch index.
     * @param int        $total_batches Total batch count.
     * @param array|null $all_urls      Full URL list — pass on the FINAL batch
     *                                  only, so the backend can clean up pages
     *                                  deleted in WordPress.
     * @return array RPC response.
     */
    public function sync_batch( $batch_ids, $batch_index, $total_batches, $all_urls = null ) {
        $pages = $this->gather_content_for_ids( $batch_ids );

        $payload = array(
            'pages'        => $pages,
            'batchIndex'   => (int) $batch_index,
            'totalBatches' => (int) $total_batches,
        );

        if ( is_array( $all_urls ) ) {
            $payload['allUrls'] = $all_urls;
        }

        return $this->rpc( 'SyncContent', $payload, 300 );
    }

    /**
     * Prune pages deleted in WordPress — WITHOUT re-syncing content.
     *
     * Used by the post-delete hook. Gathers only the current published-URL
     * list (no rendered content, no embeddings) and sends a single empty
     * SyncContent whose `allUrls` tells the backend to drop any plugin-owned
     * page no longer present. Cheap and fast, vs the old behaviour of a full
     * blocking re-sync of the entire site on every deletion.
     *
     * @since 2.0.3
     * @return array RPC response.
     */
    public function prune_deleted_pages() {
        $ids = $this->gather_post_ids();
        $all_urls = array_values( array_filter( array_map( 'get_permalink', $ids ) ) );

        return $this->rpc( 'SyncContent', array(
            'pages'        => array(),
            'batchIndex'   => 0,
            'totalBatches' => 1,
            'allUrls'      => $all_urls,
        ), 60 );
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
    public function submit_form( $form_id, $name, $email, $phone = '', $message = '', $ip_address = '', $session_id = '', $answers = array() ) {
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
        if ( ! empty( $session_id ) ) {
            $payload['sessionId'] = sanitize_text_field( $session_id );
        }
        // Custom-field answers, keyed by field name. Already sanitized by the
        // REST handler; pass through as an object so the RPC stores them.
        if ( ! empty( $answers ) && is_array( $answers ) ) {
            $payload['answers'] = $answers;
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
     * One aggregate for the top-level menu badge: unviewed leads + form
     * responses + conversations + upcoming appointments in a single
     * round-trip. Replaces three separate count calls per admin page load.
     *
     * @return array API response with { leads, forms, conversations,
     *               appointments, total }.
     */
    public function get_menu_badge_counts() {
        return $this->rpc( 'GetMenuBadgeCounts' );
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

    /**
     * Toggle the chat-gate on the default pre-chat form for this site.
     * Lazily provisions the default form server-side if missing.
     */
    public function set_chat_gate( $enabled ) {
        return $this->rpc( 'SetChatGate', array( 'enabled' => (bool) $enabled ) );
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
     * Create a WordPress draft post from a BitBot-generated draft.
     *
     * Pushes the draft into wp_posts via wp_insert_post() with
     * post_status='draft' — publish/schedule lifecycle then lives in
     * WordPress itself. On success the caller is expected to
     * round-trip the wp_post_id to the BitBot backend via
     * link_draft_to_wp() so the dashboard can deep-link to the WP
     * edit screen on subsequent loads.
     *
     * @since 1.0.0
     * @param string $draft_id Draft ID.
     * @return array Response with post_id/edit_url/view_url or error.
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

        $post_data = array(
            'post_title'   => sanitize_text_field( $draft['title'] ),
            'post_content' => wp_kses_post( $content ),
            'post_status'  => 'draft',
            'post_type'    => 'post',
            'post_name'    => sanitize_title( $draft['slug'] ?? $draft['title'] ),
        );

        $post_id = wp_insert_post( $post_data, true );

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

        if ( ! empty( $seo['keywords'] ) && is_array( $seo['keywords'] ) ) {
            $keywords = array_map( 'sanitize_text_field', $seo['keywords'] );
            update_post_meta( $post_id, '_bitbot_keywords', implode( ', ', $keywords ) );
        }

        // Store the BitBot draft ID on the WP post so the save_post
        // hook can later round-trip status changes (publish, schedule,
        // trash) back to the dashboard without polling.
        update_post_meta( $post_id, '_bitbot_draft_id', sanitize_text_field( $draft_id ) );

        return array(
            'success' => true,
            'data'    => array(
                'post_id'  => (int) $post_id,
                'status'   => 'draft',
                'edit_url' => get_edit_post_link( $post_id, 'raw' ),
                'view_url' => get_permalink( $post_id ),
            ),
        );
    }

    /**
     * Round-trip the wp_post_id back to the BitBot backend so the
     * dashboard's Drafts list can deep-link to the WP edit screen on
     * subsequent loads.
     *
     * @since 2.0.0
     * @param string $draft_id   Draft ID.
     * @param int    $wp_post_id The WP_Post ID returned by wp_insert_post.
     * @param string $status     Dashboard-side status; currently always 'draft'.
     * @return array API response.
     */
    public function link_draft_to_wp( $draft_id, $wp_post_id, $status ) {
        return $this->rpc( 'LinkDraftToWp', array(
            'draftId'  => sanitize_text_field( $draft_id ),
            'wpPostId' => (int) $wp_post_id,
            'status'   => sanitize_text_field( $status ),
        ) );
    }

    /**
     * Tell the backend to forget the WP_Post association for a draft.
     * Called by the deleted_post hook after the WP post is permanently
     * removed so the dashboard reverts to the "create new WP post"
     * action on the next Edit-in-WordPress click.
     *
     * @since 2.0.0
     * @param string $draft_id Draft ID.
     * @return array API response.
     */
    public function unlink_draft_from_wp( $draft_id ) {
        return $this->rpc( 'UnlinkDraftFromWp', array(
            'draftId' => sanitize_text_field( $draft_id ),
        ) );
    }
}
