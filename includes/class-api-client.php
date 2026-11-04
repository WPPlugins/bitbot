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

        // Lead notifications stay opt-in. adminEmail is sent as METADATA only
        // (stored in sites.admin_email as an owner contact for ops/outreach).
        // It does NOT seed notification_email — the owner still enables lead
        // notifications explicitly in the Leads tab. Two separate fields.
        $payload = array(
            'domain'     => $this->get_site_domain(),
            'sitemapUrl' => $this->get_sitemap_url(),
            // WordPress site locale (e.g. "el", "de_DE"). Lets the backend
            // resolve the content language without an LLM call when it's a
            // trustworthy non-English value.
            'wpLocale'   => get_locale(),
            // WP admin email — metadata/contact only (see note above).
            'adminEmail' => $admin_email,
            // The backend gates request encodings and features on this.
            'pluginVersion' => BITBOT_VERSION,
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
            if ( isset( $result['data']['siteId'] ) ) {
                self::remember_site_id( $result['data']['siteId'] );
            }
        }

        return $result;
    }

    /**
     * Keep the backend's id for this site (RegisterSite and GetPlanStatus both
     * return it). Only a UUID is stored.
     *
     * @since 2.1.371
     * @param mixed $site_id Candidate id.
     */
    public static function remember_site_id( $site_id ) {
        $site_id = is_string( $site_id ) ? strtolower( trim( $site_id ) ) : '';
        if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $site_id ) && get_option( 'bitbot_site_id' ) !== $site_id ) {
            update_option( 'bitbot_site_id', $site_id, false );
        }
    }

    /**
     * Ticket for the widget answer stream: base64url(JSON{"s": siteId, "e": expiry})
     * dot hex(HMAC-SHA256(api key, that base64url)). The backend checks it against
     * the site's api key, so the key itself never reaches the browser.
     *
     * @since 2.1.371
     * @param string $site_id Backend site id.
     * @param int    $expires Unix expiry.
     * @return string Ticket, or '' when there is no api key.
     */
    public function mint_stream_ticket( $site_id, $expires ) {
        if ( '' === $this->api_key || '' === $site_id ) {
            return '';
        }
        $payload = rtrim( strtr( base64_encode( wp_json_encode( array( 's' => $site_id, 'e' => (int) $expires ) ) ), '+/', '-_' ), '=' );
        return $payload . '.' . hash_hmac( 'sha256', $payload, $this->api_key );
    }

    /**
     * Widget config for the answer stream: where to POST and the 15 minute ticket.
     * Empty until the backend's site id is known (the widget then uses the REST path).
     *
     * @since 2.1.371
     * @return array { streamUrl, streamTicket } or empty array.
     */
    public function widget_stream_config() {
        $site_id = (string) get_option( 'bitbot_site_id', '' );
        $ticket  = $this->mint_stream_ticket( $site_id, time() + 15 * MINUTE_IN_SECONDS );
        if ( '' === $ticket ) {
            return array();
        }
        return array(
            'streamUrl'    => esc_url_raw( rtrim( BITBOT_API_URL, '/' ) . '/public-chat/' . $site_id . '?mode=widget' ),
            'streamTicket' => $ticket,
        );
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
     * Voice calls (Habitat). One partner key lives in the backend only
     * (env HABITAT_VOICE_KEY); no site and no plugin ever holds a Habitat key.
     *
     * @since 2.2.0
     */
    public function get_voice_calls( $timeout = 90 ) {
        return $this->rpc( 'GetVoiceCalls', array(), (int) $timeout );
    }

    public function set_voice_calls( $enabled ) {
        return $this->rpc( 'SetVoiceCalls', array( 'enabled' => (bool) $enabled ) );
    }

    /** Languages the site offers (hints for the voice session). An empty list clears them. */
    public function set_voice_languages( $codes ) {
        return $this->rpc( 'SetVoiceCallOptions', array( 'languageHints' => array_values( array_map( 'sanitize_text_field', (array) $codes ) ) ) );
    }

    /**
     * Machine translations of the widget title and welcome message.
     * Returns the usual result array; data['copy'] is code => array( title, welcome ).
     */
    public function translate_widget_copy( $title, $welcome, $from, array $to, array $questions = array(), $placeholder = '' ) {
        return $this->rpc( 'TranslateWidgetCopy', array(
            'title'         => (string) $title,
            'welcome'       => (string) $welcome,
            'questions'     => array_values( array_map( 'strval', $questions ) ),
            'placeholder'   => (string) $placeholder,
            'fromLanguage'  => sanitize_text_field( $from ),
            'toLanguages'   => array_values( array_map( 'sanitize_text_field', $to ) ),
        ), 60 );
    }

    public function start_voice_call( $visitor_id, $language = '', $greeting = '', $greeting_language = '' ) {
        $payload = array( 'visitorId' => sanitize_text_field( $visitor_id ) );
        if ( '' !== $language ) {
            $payload['language'] = sanitize_text_field( $language );
        }
        // The owner's welcome message opens the call (Habitat speaks it as the
        // visitor joins); blank keeps Habitat's own opening line.
        $greeting = trim( (string) $greeting );
        if ( '' !== $greeting ) {
            $payload['greeting'] = sanitize_textarea_field( $greeting );
            if ( '' !== $greeting_language ) {
                $payload['greetingLanguage'] = sanitize_text_field( $greeting_language );
            }
        }
        return $this->rpc( 'StartVoiceCall', $payload, 20 );
    }

    /** Links of the newest finished spoken answer of a call (null data when none). */
    public function voice_turn_links( $session_id ) {
        return $this->rpc( 'GetVoiceTurnLinks', array( 'sessionId' => sanitize_text_field( $session_id ) ), 10 );
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
    public function get_syncable_post_types() {
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
                $post_id = get_the_ID();
                $pages[] = array_merge(
                    array(
                        'url'     => get_permalink(),
                        'title'   => get_the_title(),
                        'content' => $content,
                    ),
                    // Phase 2 (Resident AI): editor-agnostic edit metadata so
                    // the backend agent can see each real post and propose SEO
                    // edits against it. Cheap per-post reads; no extra queries.
                    $this->gather_editable_meta( $post_id )
                );
            }
            wp_reset_postdata();
        }

        return $pages;
    }

    /**
     * Collect the editor-agnostic metadata the agent needs to propose edits:
     * the WP post id, which editor authored the body, current SEO meta
     * (Yoast/RankMath), status, excerpt, and tags. Sent alongside the crawled
     * content in the sync payload and stored on the `pages` row.
     *
     * @since 2.1.47
     * @param int $post_id
     * @return array{wpPostId:int, builtWith:string, postStatus:string, excerpt:string, tags:string[], seoTitle:string, seoDescription:string, seoFocusKeyphrase:string, seoPlugin:string}
     */
    private function gather_editable_meta( $post_id ) {
        $post_id = (int) $post_id;
        $post    = get_post( $post_id );

        $seo = $this->read_seo_meta( $post_id );

        // Tags (post_tag taxonomy). Non-post types simply return an empty set.
        $tag_terms = get_the_terms( $post_id, 'post_tag' );
        $tags      = array();
        if ( is_array( $tag_terms ) ) {
            foreach ( $tag_terms as $term ) {
                $tags[] = $term->name;
            }
        }

        // Raw editable body source (pre-`the_content` filter) — the exact text a
        // body edit would rewrite. Only surfaced for editors we can safely edit
        // (Classic HTML / Gutenberg blocks). Page-builder posts keep their body
        // in postmeta, so their post_content is not the real source and must
        // never be edited through the body — we send null + bodyEditable=false.
        $built_with    = $this->detect_built_with( $post_id, $post );
        $body_editable = in_array( $built_with, array( 'classic', 'block' ), true );
        $raw_content   = $body_editable && $post ? (string) $post->post_content : null;

        return array(
            'wpPostId'          => $post_id,
            // Stored on the pages row (editor_type column); roles are computed
            // live, never stored (brief section 4).
            'builtWith'         => $built_with,
            'bodyEditable'      => $body_editable,
            'rawContent'        => $raw_content,
            'postStatus'        => $post ? $post->post_status : 'publish',
            'excerpt'           => $post ? (string) $post->post_excerpt : '',
            'tags'              => $tags,
            'seoTitle'          => $seo['title'],
            'seoDescription'    => $seo['description'],
            'seoFocusKeyphrase' => $seo['focusKeyphrase'],
            'seoPlugin'         => $seo['plugin'],
        );
    }

    /**
     * What BUILT a post: which system owns what a visitor sees on it. The ONE
     * detector (brief "Plugin-Owned Agent Contract" section 4, the awareness
     * layer). It grew out of the editor-type check, so the old names moved:
     * `gutenberg` is `block`, and `other` split into `template` and `empty`
     * because "other" read as unknown when the truth was known.
     *
     * Decides whether a body edit is safe (block/classic) vs owned elsewhere
     * (never touch the body). SEO meta / title / tags / status edits are safe
     * regardless.
     *
     * @since 2.1.47 (as detect_editor_type)
     * @param int          $post_id
     * @param WP_Post|null $post
     * @return string elementor|divi|beaver|other-builder:<name>|block|classic|template|empty
     */
    public function detect_built_with( $post_id, $post = null ) {
        $post    = $post ? $post : get_post( $post_id );
        $content = $post ? (string) $post->post_content : (string) get_post_field( 'post_content', $post_id );
        $meta    = array();
        foreach ( self::BUILDER_META_KEYS as $key ) {
            $meta[ $key ] = get_post_meta( $post_id, $key, true );
        }
        $has_blocks = function_exists( 'has_blocks' ) && has_blocks( $content );
        // Only an empty body asks where the look comes from instead.
        $designed = '' === trim( $content ) && $this->template_renders_design( $post_id, $post );
        return self::built_with_from( $meta, $content, $has_blocks, $designed );
    }

    /** The post meta the builder signals below read. */
    const BUILDER_META_KEYS = array(
        '_elementor_edit_mode',
        '_et_pb_use_builder',
        '_fl_builder_enabled',
        '_wpb_vc_js_status',
        '_bricks_editor_mode',
        'ct_builder_shortcodes',
        'panels_data',
    );

    /**
     * The decision itself, from signals already read. Pure so it can be tested
     * without WordPress (tests/test-built-with.php).
     *
     * @param array  $meta             BUILDER_META_KEYS => stored value.
     * @param string $content          post_content.
     * @param bool   $has_blocks       has_blocks( $content ).
     * @param bool   $template_designs The body is empty and the effective template renders the design.
     * @return string
     */
    public static function built_with_from( $meta, $content, $has_blocks, $template_designs ) {
        $m = function ( $key ) use ( $meta ) {
            return isset( $meta[ $key ] ) ? $meta[ $key ] : '';
        };
        // `_elementor_data` alone (mode not builder) is leftover data: WordPress
        // renders post_content then, so the content decides below.
        if ( 'builder' === (string) $m( '_elementor_edit_mode' ) ) {
            return 'elementor';
        }
        if ( 'on' === (string) $m( '_et_pb_use_builder' ) ) {
            return 'divi';
        }
        if ( ! empty( $m( '_fl_builder_enabled' ) ) ) {
            return 'beaver';
        }
        // WPBakery: 'true' while its editor owns the post, 'false' once the
        // owner switched it back. Confirm key at port time.
        if ( 'true' === (string) $m( '_wpb_vc_js_status' ) ) {
            return 'other-builder:wpbakery';
        }
        // Bricks. Confirm key at port time.
        if ( 'bricks' === (string) $m( '_bricks_editor_mode' ) ) {
            return 'other-builder:bricks';
        }
        // Oxygen. Confirm key at port time.
        if ( ! empty( $m( 'ct_builder_shortcodes' ) ) ) {
            return 'other-builder:oxygen';
        }
        // SiteOrigin Page Builder. Confirm key at port time.
        if ( ! empty( $m( 'panels_data' ) ) ) {
            return 'other-builder:siteorigin';
        }
        if ( $has_blocks ) {
            return 'block';
        }
        if ( '' !== trim( (string) $content ) ) {
            return 'classic';
        }
        // An empty body: the visitor sees the template. `template` only when
        // that template carries a design of its own; an empty page on the
        // generic page template shows a title and nothing, which is `empty`.
        return $template_designs ? 'template' : 'empty';
    }

    /**
     * Storage keys the page builders keep their layout and content under. A raw
     * REST write into one of these on a builder page leaves the builder's data,
     * post_content and the rendered page disagreeing (round 3, 2026-10-02).
     */
    const BUILDER_WRITE_PREFIXES = array( '_elementor', '_et_', '_fl_', '_wpb', '_bricks', 'ct_builder', 'panels_data' );

    /** Owner-facing builder names, keyed by builtWith. */
    const BUILDER_DISPLAY_NAMES = array(
        'elementor'                 => 'Elementor',
        'divi'                      => 'Divi',
        'beaver'                    => 'Beaver Builder',
        'other-builder:wpbakery'    => 'WPBakery',
        'other-builder:bricks'      => 'Bricks',
        'other-builder:oxygen'      => 'Oxygen',
        'other-builder:siteorigin'  => 'SiteOrigin Page Builder',
    );

    /**
     * Is this builtWith a page builder (its content is not in post_content)?
     *
     * @param string $built_with
     * @return bool
     */
    public static function is_page_builder( $built_with ) {
        $b = (string) $built_with;
        return in_array( $b, array( 'elementor', 'divi', 'beaver' ), true ) || 0 === strpos( $b, 'other-builder:' );
    }

    /**
     * The fields a raw write may not carry on a builder page: content, builder
     * storage keys (top level or under meta), and status when it changes.
     * Pure, so tests/test-builder-write.php runs it without WordPress.
     *
     * @param array       $params         The REST body.
     * @param string|null $current_status The post's status now; null when unknown (counts as a change).
     * @return string[]
     */
    public static function builder_write_fields( $params, $current_status ) {
        $params = is_array( $params ) ? $params : array();
        $is_builder_key = function ( $key ) {
            foreach ( self::BUILDER_WRITE_PREFIXES as $prefix ) {
                if ( 0 === strpos( (string) $key, $prefix ) ) {
                    return true;
                }
            }
            return false;
        };
        $out = array();
        if ( array_key_exists( 'content', $params ) ) {
            $out[] = 'content';
        }
        foreach ( array_keys( $params ) as $key ) {
            if ( $is_builder_key( $key ) ) {
                $out[] = (string) $key;
            }
        }
        if ( isset( $params['meta'] ) && is_array( $params['meta'] ) ) {
            foreach ( array_keys( $params['meta'] ) as $key ) {
                if ( $is_builder_key( $key ) ) {
                    $out[] = 'meta.' . $key;
                }
            }
        }
        if ( array_key_exists( 'status', $params ) && ( null === $current_status || (string) $params['status'] !== (string) $current_status ) ) {
            $out[] = 'status';
        }
        return $out;
    }

    /**
     * The refusal for a raw write into a page builder's page, or null. The
     * API refuses the same write first (lib/builderWrites.ts); this is the
     * backstop at the write itself.
     *
     * @param string      $built_with
     * @param array       $params
     * @param string|null $current_status
     * @return string|null
     */
    public static function builder_write_refusal( $built_with, $params, $current_status ) {
        if ( ! self::is_page_builder( $built_with ) ) {
            return null;
        }
        $fields = self::builder_write_fields( $params, $current_status );
        if ( empty( $fields ) ) {
            return null;
        }
        $b    = (string) $built_with;
        $name = isset( self::BUILDER_DISPLAY_NAMES[ $b ] ) ? self::BUILDER_DISPLAY_NAMES[ $b ] : preg_replace( '/^other-builder:/', '', $b );
        $text = 'elementor' === $b
            ? sprintf( 'Text in its heading and text widgets is changed with proposeReplaceText; everything else is changed in %s by the owner.', $name )
            : sprintf( 'Its content is changed in %s by the owner.', $name );
        return sprintf(
            'This page is built with %1$s; its content lives in %1$s\'s data, so %2$s cannot be written here. %3$s Title, slug, featured image, SEO meta and template can still be written. No change made.',
            $name,
            implode( ', ', $fields ),
            $text
        );
    }

    /**
     * Does the template WordPress renders this post through carry a design of
     * its own, rather than the generic page/single template that only prints
     * the body? Read from WordPress's own hierarchy: an assigned template, the
     * posts page (always the posts listing, never its body), the front page's
     * front-page template, or a page-{slug} / page-{id} template.
     *
     * @param int          $post_id
     * @param WP_Post|null $post
     * @return bool
     */
    private function template_renders_design( $post_id, $post ) {
        if ( ! $post ) {
            return false;
        }
        $assigned = (string) get_post_meta( $post_id, '_wp_page_template', true );
        if ( '' !== $assigned && 'default' !== $assigned ) {
            return true;
        }
        if ( 'page' !== $post->post_type ) {
            return false;
        }
        $static = 'page' === get_option( 'show_on_front' );
        if ( $static && (int) get_option( 'page_for_posts' ) === (int) $post_id ) {
            return true;
        }
        $slugs = array( 'page-' . $post->post_name, 'page-' . (int) $post_id );
        if ( $static && (int) get_option( 'page_on_front' ) === (int) $post_id ) {
            array_unshift( $slugs, 'front-page' );
        }
        $block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
        foreach ( $slugs as $slug ) {
            $ships = $block_theme
                ? ( function_exists( 'get_block_template' ) && null !== get_block_template( get_stylesheet() . '//' . $slug, 'wp_template' ) )
                : '' !== locate_template( $slug . '.php' );
            if ( $ships ) {
                return true;
            }
        }
        return false;
    }

    /**
     * A post's roles on the site: the front page, the posts page, in the
     * navigation a visitor sees. Pure so it can be tested without WordPress.
     *
     * inNav matches by post id first (menu items of type post_type carry
     * object_id, navigation links carry id). A URL counts only as the fallback
     * for custom links, compared as the FULL key including the query, and only
     * for a published post: a draft's permalink is `?page_id=N`, which the old
     * host+path key reduced to the site root, so on demonicfeather (2026-09-30)
     * draft page 3 matched the "Home" link and read inNav true. The bare root
     * matches only the page whose permalink IS the root, the front page.
     *
     * @param int    $post_id
     * @param string $post_type
     * @param string $post_status
     * @param string $url_key           nav_url_key() of the post's permalink.
     * @param array  $reading           show_on_front, page_on_front, page_for_posts.
     * @param array  $nav               ids (id => true), urls (key => true), allPages, unresolved.
     * @param string $root_key          nav_url_key() of the home URL.
     * @return array{frontPage:bool, postsPage:bool, inNav:bool|null}
     */
    public static function roles_from( $post_id, $post_type, $post_status, $url_key, $reading, $nav, $root_key ) {
        $static     = isset( $reading['show_on_front'] ) && 'page' === $reading['show_on_front'];
        $front      = $static && (int) $reading['page_on_front'] === (int) $post_id;
        $published  = 'publish' === $post_status;
        $by_url     = $published && '' !== $url_key && isset( $nav['urls'][ $url_key ] )
            && ( $url_key !== $root_key || $front );
        $in_nav     = isset( $nav['ids'][ (int) $post_id ] )
            || $by_url
            || ( ! empty( $nav['allPages'] ) && $published && 'page' === $post_type );
        return array(
            'frontPage' => $front,
            'postsPage' => $static && (int) $reading['page_for_posts'] === (int) $post_id,
            // null: a navigation block renders WordPress's own fallback, which
            // could not be read, so membership is not known either way.
            'inNav'     => $in_nav ? true : ( empty( $nav['unresolved'] ) ? false : null ),
        );
    }

    /**
     * One spelling per link target, so a custom nav URL and a permalink that
     * name the same page compare equal: host + path + query, no scheme, no
     * trailing slash, no fragment. The query stays: on plain permalinks it is
     * the whole address (`/?page_id=3`), and dropping it made every such page
     * key to the site root. Root-relative links resolve against the site. ''
     * for anchors and anything without a host.
     *
     * @param string $url
     * @param string $home_url The site's home URL.
     * @return string
     */
    public static function nav_url_key( $url, $home_url ) {
        $url = trim( (string) $url );
        if ( '' === $url || '#' === $url[0] ) {
            return '';
        }
        if ( '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) {
            $url = rtrim( (string) $home_url, '/' ) . $url;
        }
        $host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
        if ( '' === $host ) {
            return '';
        }
        $query = (string) parse_url( $url, PHP_URL_QUERY );
        return $host . rtrim( (string) parse_url( $url, PHP_URL_PATH ), '/' ) . ( '' !== $query ? '?' . $query : '' );
    }

    /**
     * Read the current SEO meta from whichever SEO plugin is active. Yoast and
     * RankMath cover the overwhelming majority of installs; neither present
     * returns empty strings + plugin='none'.
     *
     * Public since 2.1.182: the front-end SEO renderer (BitBot::render_seo_head)
     * reads the same fields, so there is exactly ONE key map.
     *
     * @since 2.1.47
     * @param int $post_id
     * @return array{title:string, description:string, focusKeyphrase:string, plugin:string}
     */
    public function read_seo_meta( $post_id ) {
        $maps    = $this->seo_key_maps();
        $primary = $maps['primary'];
        $legacy  = $maps['legacy'];

        // Primary family wins per FIELD; a field the primary never held falls
        // back to the legacy family so pre-migration values stay visible.
        // (The old whole-family precedence — Yoast wins if ANY Yoast key has
        // a value — let one stale legacy field shadow an entire post's fresh
        // meta: reads/renders served one family while writes filled the
        // other. Per-field, deterministic, same decision as the writer.)
        $read = function ( $field ) use ( $post_id, $primary, $legacy ) {
            $v = (string) get_post_meta( $post_id, $primary[ $field ], true );
            if ( '' === $v ) {
                $v = (string) get_post_meta( $post_id, $legacy[ $field ], true );
            }
            return $v;
        };

        $plugin = 'none';
        if ( defined( 'WPSEO_VERSION' ) ) {
            $plugin = 'yoast';
        } elseif ( defined( 'RANK_MATH_VERSION' ) ) {
            $plugin = 'rankmath';
        }

        return array(
            'title'          => $read( 'title' ),
            'description'    => $read( 'description' ),
            'focusKeyphrase' => $read( 'focusKeyphrase' ),
            'plugin'         => $plugin,
        );
    }

    /**
     * The ONE decision for which SEO key family this site uses — shared by
     * the writer, the reader, the sync gather and the front-end renderer so
     * they can never diverge. Yoast's family when Yoast is active; the
     * Rank Math family otherwise (also the storage format for plugin-less
     * sites, so a later-installed suite adopts the fields).
     *
     * @since 2.1.184
     * @return array{primary: array<string,string>, legacy: array<string,string>}
     */
    public function seo_key_maps() {
        $yoast    = array( 'title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc', 'focusKeyphrase' => '_yoast_wpseo_focuskw' );
        $rankmath = array( 'title' => 'rank_math_title', 'description' => 'rank_math_description', 'focusKeyphrase' => 'rank_math_focus_keyword' );
        return defined( 'WPSEO_VERSION' )
            ? array( 'primary' => $yoast, 'legacy' => $rankmath )
            : array( 'primary' => $rankmath, 'legacy' => $yoast );
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
            // Phase 3: site-level look-and-feel snapshot (theme + current custom
            // CSS) so the agent can see what it's styling. Small; sent each batch.
            'styleSnapshot' => $this->get_style_snapshot(),
            // This sender is reached ONLY via start_background_sync, which only
            // the "Sync now" button calls. Flagging the run manual tells the
            // backend to force a full re-probe of sitemap + manual URLs (refresh
            // existing, not just new) on batch 0. wp-cron senders (first-sync
            // retry, save_post) never set it, so automated syncs stay incremental.
            'manual'       => true,
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
    public function chat( $message, $history = array(), $session_id = '', $visitor_ip = '', $site_language = 'en', $visitor_id = '', $visitor_timezone = '', $preferred_language = '' ) {
        return $this->rpc( 'Chat', array(
            'preferredLanguage' => sanitize_text_field( $preferred_language ),
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
     * Sync ONE post to the backend through the SAME enriched gather the bulk
     * sync uses — wpPostId, editor type, post status, SEO meta, raw editable
     * source, the lot. One gather, every path: the old hand-rolled
     * url/title/content variant ingested rows WITHOUT their WordPress
     * identity (wp_post_id null), leaving every post published between bulk
     * syncs invisible to the agent's editing and inventory surfaces.
     *
     * @since 2.1.173
     * @param int $post_id Post ID (must be a published, syncable post).
     * @return array API response.
     */
    public function sync_post( $post_id ) {
        $pages = $this->gather_content_for_ids( array( (int) $post_id ) );
        if ( empty( $pages ) ) {
            return array(
                'success' => false,
                'error'   => __( 'Post is not published or not a syncable type', 'bitbot' ),
            );
        }
        return $this->rpc( 'SyncContent', array(
            'pages'        => $pages,
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
    public function submit_form( $form_id, $name, $email, $phone = '', $message = '', $ip_address = '', $session_id = '', $answers = array(), $submission_key = '' ) {
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
        // Idempotency key: the API stores one submission per key.
        if ( '' !== $submission_key ) {
            $payload['submissionKey'] = sanitize_text_field( $submission_key );
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

    /**
     * Phase 3 — current look-and-feel snapshot for the agent's stylist.
     * Theme name, whether it's a block theme, current Additional CSS, the site
     * identity (title + tagline), and — for block themes — the current global
     * styles JSON (colours + typography), so the agent can see exactly what it
     * would change.
     *
     * @since 2.1.49
     * @return array
     */
    /**
     * Push a fresh look-and-feel snapshot to the backend on its own — used on
     * theme switch so the agent never styles a theme that's no longer active.
     * (Normally the snapshot rides along with content syncs.)
     *
     * @since 2.1.80
     * @return array RPC response.
     */
    public function push_style_snapshot() {
        return $this->rpc( 'PushStyleSnapshot', array(
            'styleSnapshot' => $this->get_style_snapshot(),
        ) );
    }

    /**
     * Tell the backend the theme changed so the agent can proactively give
     * the site a once-over on the new theme (Pro+ only; the backend no-ops
     * on lower plans). Returns the RPC response.
     *
     * @since 2.1.87
     * @param string $from Previous theme stylesheet/name.
     * @param string $to   New theme stylesheet/name.
     * @return array
     */
    public function notify_theme_switch( $from, $to ) {
        return $this->rpc( 'NotifyThemeSwitch', array(
            'from' => sanitize_text_field( (string) $from ),
            'to'   => sanitize_text_field( (string) $to ),
        ) );
    }

    public function get_style_snapshot() {
        $theme = wp_get_theme();
        $is_block = function_exists( 'wp_is_block_theme' ) ? (bool) wp_is_block_theme() : false;
        // Is the STORE hidden from the public right now? WooCommerce's
        // coming-soon mode serves a placeholder instead of the real page, so a
        // product the agent just rewrote correctly is invisible to visitors —
        // and nothing told the agent that. Observed live: it rewrote a product,
        // reported honestly that the change was "saved but not visible", then
        // GUESSED at the cause and blamed the theme's product template.
        //
        // Not readable from the Woo REST API — `wc/v3/settings/site-visibility`
        // returns an empty array because the flag lives in options, not a
        // settings field. So the plugin is the only thing that can see it.
        $coming_soon = false;
        $coming_soon_scope = '';
        if ( class_exists( 'WooCommerce' ) && 'yes' === get_option( 'woocommerce_coming_soon', 'no' ) ) {
            $coming_soon = true;
            // "Store pages only" hides the shop, products, cart and checkout
            // while the rest of the site stays public — which is why the blog
            // rendered fine and the product did not.
            $coming_soon_scope = ( 'yes' === get_option( 'woocommerce_store_pages_only', 'no' ) )
                ? 'store-pages-only'
                : 'whole-site';
        }

        return array(
            'themeName'    => $theme ? (string) $theme->get( 'Name' ) : '',
            'stylesheet'   => get_stylesheet(),
            'isBlockTheme' => $is_block,
            'storeComingSoon'      => $coming_soon,
            'storeComingSoonScope' => $coming_soon_scope,
            // The agent's own CSS channel (bitbot_custom_css option, printed by
            // render_site_css). Proposals REPLACE this document, so the snapshot
            // must read the same channel the apply writes — reading the theme's
            // Customizer CSS here would make the agent copy the owner's own CSS
            // into our option on its next edit.
            'customCss'    => (string) get_option( 'bitbot_custom_css', '' ),
            'siteTitle'    => (string) get_option( 'blogname', '' ),
            'tagline'      => (string) get_option( 'blogdescription', '' ),
            // Only block themes have editable global styles; classic themes get ''.
            'globalStyles' => $is_block ? $this->get_user_global_styles_json() : '',
            // The overlay manifest: per-theme styling layers + the full trace
            // (theme switches, layers archived/restored, overrides created).
            // This is the agent's styling MEMORY — it always knows which theme
            // it is dressing, what it applied before, and what is suspended
            // where, so on a theme change it re-fits instead of assuming.
            'overlay'      => class_exists( 'BitBot' ) && method_exists( BitBot::get_instance(), 'get_overlay_manifest' )
                ? BitBot::get_instance()->get_overlay_manifest()
                : array(),
        );
    }

    /**
     * Read the active block theme's USER global styles (the Site Editor's
     * customisations) as a JSON string. Empty string when the site hasn't
     * customised yet (no wp_global_styles post) or on a classic theme.
     *
     * @since 2.1.51
     * @return string
     */
    private function get_user_global_styles_json() {
        $post = $this->get_user_global_styles_post();
        if ( ! $post || '' === trim( (string) $post->post_content ) ) {
            return '';
        }
        return (string) $post->post_content;
    }

    /**
     * Find the wp_global_styles CPT post that stores the active theme's user
     * global styles. WordPress names it `wp-global-styles-<stylesheet>` and
     * associates it with the theme via the wp_theme taxonomy. Returns null if
     * it doesn't exist yet (theme never customised).
     *
     * @since 2.1.51
     * @return WP_Post|null
     */
    public function get_user_global_styles_post() {
        $stylesheet = get_stylesheet();
        // Query by the wp_theme TERM — the same way WordPress's own resolver
        // looks up the user global styles the front end applies. (Querying by
        // slug can find an orphan post that the front end ignores.) We do NOT
        // create one here: reads/snapshots must be side-effect free.
        $posts = get_posts( array(
            'post_type'        => 'wp_global_styles',
            'post_status'      => array( 'publish', 'draft', 'auto-draft' ),
            'numberposts'      => 1,
            'no_found_rows'    => true,
            'suppress_filters' => false,
            'tax_query'        => array(
                array(
                    'taxonomy' => 'wp_theme',
                    'field'    => 'name',
                    'terms'    => $stylesheet,
                ),
            ),
        ) );
        return $posts ? $posts[0] : null;
    }

    /**
     * Phase 2 (Resident AI) — content edit proposals (apply channel).
     *
     * Fetch pending/history proposals, or a single proposal by id (the apply
     * handler pulls one by id before writing to WP).
     *
     * @since 2.1.47
     * @param string|null $status Filter: pending|applied|rejected|reverted|failed.
     * @param string|null $id     Fetch one proposal by id.
     * @return array RPC response { proposals: [...], total }.
     */
    public function get_content_edit_proposals( $status = null, $id = null, $auto = false ) {
        $payload = array();
        if ( $status ) {
            $payload['status'] = sanitize_text_field( $status );
        }
        if ( $id ) {
            $payload['id'] = sanitize_text_field( $id );
        }
        if ( $auto ) {
            $payload['auto'] = true;
        }
        return $this->rpc( 'GetContentEditProposals', $payload );
    }

    /**
     * Report a proposal's new status back to the backend after the plugin has
     * applied / reverted it in WordPress (or the owner rejected it).
     *
     * @since 2.1.47
     * @param string   $proposal_id
     * @param string   $status         applied|rejected|reverted|failed.
     * @param int|null $wp_revision_id Revision created on apply (revert target).
     * @param string|null $apply_error For status='failed'.
     * @return array RPC response.
     */
    public function update_content_edit_status( $proposal_id, $status, $wp_revision_id = null, $apply_error = null, $before_value = null ) {
        $payload = array(
            'proposalId' => sanitize_text_field( $proposal_id ),
            'status'     => sanitize_text_field( $status ),
        );
        if ( null !== $wp_revision_id ) {
            $payload['wpRevisionId'] = (int) $wp_revision_id;
        }
        if ( null !== $apply_error ) {
            $payload['applyError'] = sanitize_text_field( $apply_error );
        }
        // Prior value captured at apply time (e.g. previous theme), so revert
        // is reliable regardless of snapshot freshness.
        if ( null !== $before_value ) {
            $payload['beforeValue'] = $before_value;
        }
        return $this->rpc( 'UpdateContentEditStatus', $payload );
    }

    public function get_form( $form_id ) {
        return $this->rpc( 'GetForm', array( 'formId' => sanitize_text_field( $form_id ) ) );
    }

    public function create_form( $name, $description, $fields, $success_message = '' ) {
        $payload = array(
            'name'        => sanitize_text_field( $name ),
            'description' => sanitize_text_field( $description ),
            'fields'      => $fields,
        );
        if ( '' !== trim( (string) $success_message ) ) {
            $payload['successMessage'] = sanitize_text_field( $success_message );
        }
        return $this->rpc( 'CreateForm', $payload );
    }

    public function update_form( $form_id, $data ) {
        $payload = array( 'formId' => sanitize_text_field( $form_id ) );
        if ( isset( $data['name'] ) )    $payload['name']    = sanitize_text_field( $data['name'] );
        if ( isset( $data['fields'] ) )  $payload['fields']  = $data['fields'];
        if ( isset( $data['enabled'] ) ) $payload['enabled'] = (bool) $data['enabled'];
        if ( isset( $data['success_message'] ) ) $payload['successMessage'] = sanitize_text_field( $data['success_message'] );
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
    public function create_wp_post_from_draft( $draft_id, $post_status = 'draft' ) {
        $post_status = in_array( $post_status, array( 'draft', 'publish', 'private' ), true ) ? $post_status : 'draft';
        $result = $this->get_draft( $draft_id );

        if ( ! $result['success'] ) {
            return $result;
        }

        $draft = $result['data'];
        $seo   = $draft['seoMeta'] ?? array();

        // Single source of truth: once a draft is linked to WordPress its body
        // is nulled on the backend — WordPress owns it from then on. Publishing
        // such a row again would insert an EMPTY post; refuse with the pointer.
        $content = (string) ( $draft['content'] ?? '' );
        if ( '' === trim( $content ) ) {
            $wp_id = (int) ( $draft['wpPostId'] ?? 0 );
            return array(
                'success' => false,
                'error'   => $wp_id > 0
                    ? sprintf( 'This post is already in WordPress (post %d) — edit it there; its body no longer lives in the drafts bench.', $wp_id )
                    : 'This draft has no content to publish.',
            );
        }

        // Free plan: append a subtle "Draft by BitBot →" footer to the post body.
        // Any paying tier (Pro or Pro+) leaves the content untouched.
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
            'post_status'  => $post_status,
            'post_type'    => 'post',
            'post_name'    => sanitize_title( $draft['slug'] ?? $draft['title'] ),
        );
        // The auto-apply poller publishes via an UNAUTHENTICATED server-to-
        // server REST call (current user 0) — without an explicit author the
        // post lands authorless (post_author=0): no byline, no author archive,
        // and machine-read as anonymous content. Attribute to the site's first
        // administrator.
        if ( 0 === get_current_user_id() ) {
            $admins = get_users( array( 'role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1, 'fields' => 'ID' ) );
            if ( ! empty( $admins ) ) {
                $post_data['post_author'] = (int) $admins[0];
            }
        }

        $post_id = wp_insert_post( $post_data, true );

        if ( is_wp_error( $post_id ) ) {
            return array(
                'success' => false,
                'error'   => $post_id->get_error_message(),
            );
        }

        // SEO meta via the ONE key-family decision (seo_key_maps) — this
        // function carried its own duplicated map, the exact drift class that
        // produced the read/write split-brain elsewhere.
        $seo_keys = $this->seo_key_maps();

        if ( ! empty( $seo['metaTitle'] ) ) {
            update_post_meta( $post_id, $seo_keys['primary']['title'], sanitize_text_field( $seo['metaTitle'] ) );
        }
        if ( ! empty( $seo['metaDescription'] ) ) {
            update_post_meta( $post_id, $seo_keys['primary']['description'], sanitize_text_field( $seo['metaDescription'] ) );
        }
        if ( ! empty( $seo['focusKeyphrase'] ) ) {
            update_post_meta( $post_id, $seo_keys['primary']['focusKeyphrase'], sanitize_text_field( $seo['focusKeyphrase'] ) );
        }

        if ( ! empty( $seo['keywords'] ) && is_array( $seo['keywords'] ) ) {
            $keywords = array_map( 'sanitize_text_field', $seo['keywords'] );
            update_post_meta( $post_id, '_bitbot_keywords', implode( ', ', $keywords ) );
        }

        // Store the BitBot draft ID on the WP post so the save_post
        // hook can later round-trip status changes (publish, schedule,
        // trash) back to the dashboard without polling.
        update_post_meta( $post_id, '_bitbot_draft_id', sanitize_text_field( $draft_id ) );

        // READ-BACK, never echo: the reported status/title come from fetching
        // the post we just created, so a claim of "published" downstream is
        // grounded in what WordPress actually holds — not in what was asked.
        $created = get_post( $post_id );

        return array(
            'success' => true,
            'data'    => array(
                'post_id'        => (int) $post_id,
                'status'         => $created ? $created->post_status : 'missing',
                'verified_title' => $created ? $created->post_title : '',
                'edit_url'       => get_edit_post_link( $post_id, 'raw' ),
                'view_url'       => get_permalink( $post_id ),
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
