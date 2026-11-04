<?php
/**
 * BitBot Chat Widget
 *
 * Handles the frontend chat widget display and API interactions.
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
 * Chat widget class for frontend
 *
 * @since 1.0.0
 */
class BitBot_Widget {

    /**
     * API client instance
     *
     * @var BitBot_API_Client
     */
    private $api_client;

    /**
     * Constructor
     *
     * @since 1.0.0
     * @param BitBot_API_Client $api_client API client instance.
     */
    public function __construct( $api_client ) {
        $this->api_client = $api_client;

        // Render widget in footer
        add_action( 'wp_footer', array( $this, 'render_widget' ) );
    }

    /**
     * Get localized greeting based on site language
     *
     * @since 1.0.0
     * @return string Greeting message in the site's language.
     */
    private function get_localized_greeting() {
        return BitBot_Widget_Copy::default_welcome( substr( get_locale(), 0, 2 ) );
    }

    /**
     * Render the chat widget HTML
     *
     * @since 1.0.0
     */
    public function render_widget() {
        if ( ! get_option( 'bitbot_enabled', true ) ) {
            return;
        }

        // 2.0.16: skip the host div on excluded URLs too. The script
        // enqueue path already bails on these, but the host markup
        // was rendered unconditionally on wp_footer, leaving a dead
        // <div id="bitbot-widget"> on /checkout, /cart, etc. that
        // confused diagnostics ("widget IS present but script isn't").
        // Cheap call — the regex compile is bounded by the admin's
        // own pattern list.
        if ( apply_filters( 'bitbot_should_hide_widget', false ) ) {
            return;
        }

        $position        = sanitize_text_field( get_option( 'bitbot_widget_position', 'bottom-right' ) );
        $primary_color   = sanitize_hex_color( get_option( 'bitbot_primary_color', '#0073aa' ) );
        // Owner-configurable pixel offsets from the chosen corner. JS
        // auto-flip can override --bitbot-offset-y at runtime when it
        // detects collision in the corner (stack on top of the
        // occupant) without touching the saved option.
        $offset_x = max( 0, min( 200, (int) get_option( 'bitbot_widget_offset_x', 20 ) ) );
        $offset_y = max( 0, min( 200, (int) get_option( 'bitbot_widget_offset_y', 20 ) ) );

        // Check if user has set a custom welcome message, otherwise use localized default
        $custom_message  = get_option( 'bitbot_welcome_message', '' );
        $welcome_message = ! empty( $custom_message ) ? wp_kses_post( $custom_message ) : $this->get_localized_greeting();

        // 1.9.0: custom widget title. Empty option string falls back to
        // the localized "Chat with us" default so existing installs see no
        // change. Sanitized at save time (sanitize_widget_title); we still
        // esc_html on render as defense in depth.
        $custom_title  = get_option( 'bitbot_widget_title', '' );
        $widget_title  = BitBot_Widget_Copy::base_title( $custom_title );
        ?>
        <?php
        // 1.8.2: widget is mounted in a shadow root so theme CSS targeting
        // bare button/svg/input selectors cannot reach inside. The HOST
        // (`#bitbot-widget`) carries:
        //   - position class (drives `:host()` selectors for left/right)
        //   - CSS custom properties (inherited into shadow root)
        //   - data-* attributes (read by auto-flip JS, e.g. data-preferred)
        // The widget markup lives in a `<template>` that JS clones into
        // the host's shadow root on init. Light DOM stays empty after that
        // — nothing for a theme to override.
        $preferred_corner = ( strpos( $position, 'left' ) !== false ) ? 'left' : 'right';

        // 1.9.0: appearance — corner radius (Free) + shape (Pro). Both are
        // passed as CSS variables so widget.css can read them inside the
        // shadow root. Shape is also stamped as a data attribute so CSS
        // can apply a clip-path preset selectively.
        // 2.0.2: default bumped to 32 (max) so a fresh install renders
        // the FAB as a full circle out of the box — that's the most
        // recognisable launcher shape. Cap also raised from 24 → 32
        // to match the React slider's range and the CSS we wire it
        // into (.bitbot-toggle border-radius reads this directly).
        $corner_radius = max( 0, min( 32, (int) get_option( 'bitbot_corner_radius', 32 ) ) );
        $pill_height   = max( 28, min( 56, (int) get_option( 'bitbot_pill_height', 28 ) ) );
        $widget_shape  = sanitize_text_field( get_option( 'bitbot_widget_shape', 'rounded' ) );

        $input_placeholder = trim( (string) get_option( 'bitbot_placeholder_text', '' ) );
        if ( '' === $input_placeholder ) {
            $input_placeholder = BitBot_Widget_Copy::default_placeholder();
        }

        $widget_style     = sprintf(
            '--bitbot-primary: %s; --bitbot-offset-x: %dpx; --bitbot-offset-y: %dpx; --bitbot-radius: %dpx; --bitbot-pill-height: %dpx;',
            esc_attr( $primary_color ),
            $offset_x,
            $offset_y,
            $corner_radius,
            $pill_height
        );
        ?>
        <div id="bitbot-widget" class="bitbot-<?php echo esc_attr( $position ); ?>" data-preferred="<?php echo esc_attr( $preferred_corner ); ?>" data-base-offset-y="<?php echo esc_attr( $offset_y ); ?>" data-shape="<?php echo esc_attr( $widget_shape ); ?>" style="<?php echo esc_attr( $widget_style ); ?>"></div>
        <template id="bitbot-widget-template">
            <div class="bitbot-widget">
                <!-- Chat Toggle Button -->
                <button id="bitbot-toggle" class="bitbot-toggle" aria-label="<?php echo esc_attr__( 'Open chat', 'bitbot' ); ?>">
                    <svg class="bitbot-icon-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                    </svg>
                    <span class="bitbot-pill-text">
                        <span class="bitbot-pill-label"><?php echo esc_html( $widget_title ); ?></span>
                        <svg class="bitbot-icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none;">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </span>
                </button>

                <!-- Chat Window -->
                <div id="bitbot-chat" class="bitbot-chat" style="display:none;">
                    <!-- Header -->
                    <div class="bitbot-header">
                        <!-- 2.0.2: avatar slot. Populated by widget.js when
                             config.avatarUrl is set; otherwise stays empty
                             (CSS hides via :empty). Apes Intercom/Drift —
                             gives visitors a face to associate with the bot. -->
                        <span id="bitbot-header-avatar" class="bitbot-header-avatar"></span>
                        <span class="bitbot-title"><?php echo esc_html( $widget_title ); ?></span>
                        <?php /* Language picker: a pill showing the current pick; hidden until widget.js sees more than one language in config.languages. */ ?>
                        <span id="bitbot-lang" class="bitbot-lang" hidden>
                            <button id="bitbot-lang-btn" type="button" class="bitbot-minimize bitbot-lang-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?php esc_attr_e( 'Choose language', 'bitbot' ); ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="2" y1="12" x2="22" y2="12"></line>
                                    <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                                </svg>
                                <span id="bitbot-lang-code" class="bitbot-lang-code" aria-hidden="true"></span>
                            </button>
                            <ul id="bitbot-lang-menu" class="bitbot-lang-menu" role="listbox" hidden></ul>
                        </span>
                        <?php /* Voice calls: hidden until widget.js sees config.voiceCallsEnabled. */ ?>
                        <button id="bitbot-call" class="bitbot-minimize bitbot-call" hidden aria-label="<?php esc_attr_e( 'Start a voice call', 'bitbot' ); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"></path>
                            </svg>
                        </button>
                        <?php /* WhatsApp click-to-chat: hidden until widget.js sees config.whatsappNumber. Opens wa.me in a new tab. */ ?>
                        <a id="bitbot-whatsapp" class="bitbot-minimize bitbot-whatsapp" hidden target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'Chat on WhatsApp', 'bitbot' ); ?>">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2zm0 18.15h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.2 8.2 0 0 1-1.26-4.38c0-4.54 3.7-8.23 8.25-8.23 2.2 0 4.27.86 5.82 2.42a8.18 8.18 0 0 1 2.41 5.82c0 4.54-3.7 8.23-8.23 8.23zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.12-.17.25-.64.81-.78.97-.14.17-.29.19-.54.06-.25-.12-1.05-.39-2-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.02-.38.11-.5.11-.11.25-.29.37-.43.12-.14.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.41-.42-.56-.43h-.48c-.17 0-.43.06-.66.31-.23.25-.87.85-.87 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.48-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.14-1.18-.06-.1-.23-.16-.48-.29z"></path>
                            </svg>
                        </a>
                        <button id="bitbot-minimize" class="bitbot-minimize" aria-label="<?php esc_attr_e( 'Minimize chat', 'bitbot' ); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                            </svg>
                        </button>
                    </div>

                    <!-- Messages Container -->
                    <div id="bitbot-messages" class="bitbot-messages">
                        <!-- Welcome message -->
                        <div class="bitbot-message bitbot-bot bitbot-welcome">
                            <div class="bitbot-message-content">
                                <?php echo esc_html( $welcome_message ); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Voice call bar: shown only while a call is live -->
                    <div id="bitbot-call-bar" class="bitbot-call-bar" hidden>
                        <span id="bitbot-call-status" class="bitbot-call-status" role="status" aria-live="polite"></span>
                        <button type="button" id="bitbot-hangup" class="bitbot-hangup"><?php esc_html_e( 'Hang up', 'bitbot' ); ?></button>
                    </div>

                    <!-- Input Area -->
                    <form id="bitbot-form" class="bitbot-input-area">
                        <input
                            type="text"
                            id="bitbot-input"
                            class="bitbot-input"
                            placeholder="<?php echo esc_attr( $input_placeholder ); ?>"
                            autocomplete="off"
                        >
                        <button type="submit" class="bitbot-send" aria-label="<?php esc_attr_e( 'Send message', 'bitbot' ); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="22" y1="2" x2="11" y2="13"></line>
                                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                            </svg>
                        </button>
                    </form>

                    <?php if ( ! $this->is_pro() ) : ?>
                        <div class="bitbot-branding">
                            <?php esc_html_e( 'Powered by', 'bitbot' ); ?>
                            <a href="https://youami.ai/?utm_source=widget&utm_medium=branding&utm_campaign=free_tier"
                               target="_blank"
                               rel="noopener">
                               BitBot
                            </a>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </template>
        <?php
    }

    /**
     * Read the persisted plan snapshot. Written by admin-side refreshes
     * and the scheduled refresh cron, so the widget sees a reasonably
     * fresh plan even on sites where an admin rarely opens the dashboard.
     *
     * Front-end code MUST NOT hit the API on every page view, so we
     * default to Free (show branding) when the cache is missing — a
     * conservative default that favours paying customers briefly seeing
     * branding during a first-visit race, not losing them forever.
     *
     * @since 1.6.0 (persistent-cache rewrite in 1.7.0)
     * @return bool True if the site is on a paid plan (pro or pro_plus).
     */
    private function is_pro() {
        $status = BitBot_Plan::get_cached_status();
        return BitBot_Plan::is_paid( $status['plan'] ?? null );
    }

    /**
     * Get visitor IP address
     *
     * @since 1.0.0
     * @return string Visitor IP address.
     */
    private function get_visitor_ip() {
        $ip = '';

        // Check for proxied IP first (GDPR consideration: document this in privacy policy)
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
            $ip  = trim( $ips[0] );
        } elseif ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }

        // Validate IP format
        if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return $ip;
        }

        return '';
    }

    /**
     * Handle chat request (REST API)
     *
     * @since 1.0.0
     * @param WP_REST_Request $request REST request object.
     * @return WP_REST_Response REST response.
     */
    public function handle_chat_request( $request ) {
        $message          = sanitize_text_field( $request->get_param( 'message' ) );
        $history          = $request->get_param( 'history' ) ?? array();
        $session_id       = sanitize_text_field( $request->get_param( 'sessionId' ) ?? '' );
        $visitor_id       = sanitize_text_field( $request->get_param( 'visitorId' ) ?? '' );
        $visitor_timezone = sanitize_text_field( $request->get_param( 'visitorTimezone' ) ?? '' );
        $site_language    = sanitize_text_field( $request->get_param( 'siteLanguage' ) ?? substr( get_locale(), 0, 2 ) );
        $visitor_ip       = $this->get_visitor_ip();
        $preferred_lang   = $this->visitor_language_pick( $request );

        if ( empty( $message ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'error'   => __( 'Message is required', 'bitbot' ),
            ), 400 );
        }

        // Sanitize history
        $sanitized_history = array();
        if ( is_array( $history ) ) {
            foreach ( $history as $msg ) {
                if ( isset( $msg['role'] ) && isset( $msg['content'] ) ) {
                    $role = sanitize_text_field( $msg['role'] );
                    $sanitized_history[] = array(
                        'role'    => in_array( $role, array( 'user', 'assistant' ), true ) ? $role : 'user',
                        'content' => sanitize_text_field( $msg['content'] ),
                    );
                }
            }
        }

        $result = $this->api_client->chat( $message, $sanitized_history, $session_id, $visitor_ip, $site_language, $visitor_id, $visitor_timezone, $preferred_lang );

        if ( $result['success'] ) {
            return new WP_REST_Response( array(
                'success' => true,
                'data'    => array(
                    'message'         => $result['data']['message'],
                    'citations'       => $result['data']['citations'] ?? array(),
                    'suggest_contact' => $result['data']['suggestContact'] ?? false,
                    'lead_captured'   => $result['data']['leadCaptured'] ?? false,
                    // 1.7.0: surfaced by the Pro+ agent when the conversation
                    // is in live-mode (a human is handling it). Widget uses
                    // this to switch to poll mode.
                    'live_mode'       => $result['data']['liveMode'] ?? false,
                    // 1.7.0: structured product cards from `searchProducts`.
                    // Widget renders these as visual cards with image +
                    // price + CTA instead of relying on the agent's inline
                    // markdown links.
                    'products'        => $result['data']['products'] ?? array(),
                ),
            ), 200 );
        }

        return new WP_REST_Response( array(
            'success' => false,
            'error'   => $result['error'] ?? __( 'Failed to get response', 'bitbot' ),
        ), 500 );
    }

    /**
     * The language the visitor picked (`language` param), kept only when it is
     * one of the languages the owner offers. '' means no pick.
     */
    private function visitor_language_pick( $request ) {
        return BitBot_Languages::sanitize_pick(
            $request->get_param( 'language' ),
            get_option( BitBot_Languages::OPTION, array() ),
            BitBot_Languages::site_code( get_locale() ),
            BitBot_Languages::all(),
            BitBot_Languages::detected()
        );
    }

    /**
     * Start a visitor voice call. Public route; the backend enforces the
     * per-site switch and rate limits (the Habitat partner key lives only there). On failure the
     * backend error carries a "VOICE_<CODE>: " prefix which we turn into a
     * lowercase `code` the widget maps to plain words.
     *
     * @since 2.2.0
     */
    public function handle_voice_call_start( $request ) {
        if ( get_option( 'bitbot_voice_calls_enabled' ) !== '1' ) {
            return new WP_REST_Response( array( 'success' => false, 'code' => 'disabled', 'error' => 'Voice calls are off.' ), 403 );
        }

        $visitor_id = sanitize_text_field( $request->get_param( 'visitorId' ) ?? '' );
        if ( '' === $visitor_id ) {
            return new WP_REST_Response( array( 'success' => false, 'code' => 'failed', 'error' => 'visitorId required' ), 400 );
        }

        // The welcome message of the picked language opens the call (the owner's
        // translated row, or the base text when there is none); site language when
        // the visitor has not picked.
        $site_code = BitBot_Languages::site_code( get_locale() );
        $pick      = $this->visitor_language_pick( $request );
        $greeting  = BitBot_Widget_Copy::for_language(
            '' !== $pick ? $pick : $site_code,
            $site_code,
            BitBot_Widget_Copy::normalize( get_option( BitBot_Widget_Copy::OPTION, array() ) ),
            '',
            (string) get_option( 'bitbot_welcome_message', '' )
        );
        $result = $this->api_client->start_voice_call(
            $visitor_id,
            $pick,
            $greeting['welcome'],
            '' !== $pick ? $pick : $site_code
        );
        if ( ! empty( $result['success'] ) && ! empty( $result['data'] ) ) {
            return new WP_REST_Response( array( 'success' => true, 'data' => $result['data'] ), 200 );
        }

        $error = isset( $result['error'] ) ? (string) $result['error'] : '';
        $code  = 'failed';
        if ( preg_match( '/^VOICE_([A-Z_]+):/', $error, $m ) ) {
            $code = strtolower( $m[1] );
        }
        $status = ( 'busy' === $code ) ? 429 : 502;
        return new WP_REST_Response( array( 'success' => false, 'code' => $code, 'error' => $error ), $status );
    }

    /**
     * Citation links of the spoken answer just finished. Public, read only;
     * the backend scopes the lookup to this site and rate limits it. `data`
     * is null when nothing is newer than `after`.
     */
    public function handle_voice_call_links( $request ) {
        $session_id = sanitize_text_field( $request->get_param( 'session' ) ?? '' );
        $after      = sanitize_text_field( $request->get_param( 'after' ) ?? '' );
        if ( '' === $session_id ) {
            return $this->uncached_links_response( array( 'success' => false, 'error' => 'session required' ), 400 );
        }

        $result = $this->api_client->voice_turn_links( $session_id );
        if ( empty( $result['success'] ) ) {
            return $this->uncached_links_response( array( 'success' => false, 'error' => 'links unavailable' ), 502 );
        }
        $turn = $result['data'] ?? null;
        if ( empty( $turn['turnId'] ) || ( '' !== $after && $after === $turn['turnId'] ) ) {
            return $this->uncached_links_response( array( 'success' => true, 'data' => null ), 200 );
        }

        $citations = array();
        foreach ( (array) ( $turn['links'] ?? array() ) as $link ) {
            if ( empty( $link['url'] ) ) {
                continue;
            }
            $citations[] = array(
                'url'      => esc_url_raw( $link['url'] ),
                'title'    => sanitize_text_field( $link['title'] ?? '' ),
                'anchorId' => null,
            );
        }
        return $this->uncached_links_response( array(
            'success' => true,
            'data'    => array( 'turnId' => (string) $turn['turnId'], 'citations' => $citations ),
        ), 200 );
    }

    /**
     * The links route answers differently as a call goes on (empty until the
     * answer is done), so no cache may replay an earlier reply. Page caches
     * such as LiteSpeed store REST GETs by URL and would pin the first empty
     * answer for the rest of the call.
     */
    private function uncached_links_response( $data, $status ) {
        $response = new WP_REST_Response( $data, $status );
        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
        $response->header( 'X-LiteSpeed-Cache-Control', 'no-cache, no-store' );
        $response->header( 'Pragma', 'no-cache' );
        return $response;
    }

    /**
     * Poll endpoint used by the widget while a conversation is in live
     * mode to pick up new admin messages + detect thread closure.
     *
     * @since 1.7.0
     */
    public function handle_live_updates_request( $request ) {
        $session_id      = sanitize_text_field( $request->get_param( 'sessionId' ) ?? '' );
        $since_message_id = sanitize_text_field( $request->get_param( 'sinceMessageId' ) ?? '' );

        if ( empty( $session_id ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'error'   => 'sessionId required',
            ), 400 );
        }

        $result = $this->api_client->get_live_chat_updates( $session_id, $since_message_id );
        if ( $result['success'] ) {
            return new WP_REST_Response( array(
                'success' => true,
                'data'    => $result['data'],
            ), 200 );
        }
        return new WP_REST_Response( array( 'success' => false, 'error' => $result['error'] ?? 'poll failed' ), 500 );
    }

    /**
     * Visitor heartbeat — fired every 30s while the widget is open and
     * the page is visible. Stamps conversations.visitor_last_seen_at so
     * the admin dashboard can render an accurate "present / idle / left"
     * pill. Best-effort: failures are silent, the visitor doesn't need
     * to know.
     *
     * @since 1.7.0
     */
    public function handle_visitor_heartbeat( $request ) {
        $session_id = sanitize_text_field( $request->get_param( 'sessionId' ) ?? '' );
        if ( empty( $session_id ) ) {
            return new WP_REST_Response( array( 'success' => false ), 400 );
        }
        $result = $this->api_client->visitor_heartbeat( $session_id );
        // Forward the liveMode flag so the widget can detect admin-initiated
        // takeover on its next tick (see assets/js/widget.js startHeartbeat).
        $live_mode = ! empty( $result['success'] ) && ! empty( $result['data']['liveMode'] );
        return new WP_REST_Response( array(
            'success' => true,
            'data'    => array( 'liveMode' => (bool) $live_mode ),
        ), 200 );
    }

    /**
     * Visitor-left beacon — fired from beforeunload via sendBeacon so
     * the tab can close without waiting for our ack. Stamps
     * conversations.visitor_left_at so the admin sees "left" immediately
     * instead of waiting for the heartbeat to go stale.
     *
     * @since 1.7.0
     */
    public function handle_visitor_left( $request ) {
        $session_id = sanitize_text_field( $request->get_param( 'sessionId' ) ?? '' );
        if ( empty( $session_id ) ) {
            return new WP_REST_Response( array( 'success' => false ), 400 );
        }
        $this->api_client->visitor_left( $session_id );
        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    /**
     * Citation click beacon. Records that a visitor opened a link the
     * bot served in a chat reply. Drives the Overview "Top Cited Pages"
     * CTR. Best-effort — we always return success even if the upstream
     * call fails, so the widget's beacon doesn't retry.
     */
    public function handle_citation_click( $request ) {
        $session_id = sanitize_text_field( $request->get_param( 'sessionId' ) ?? '' );
        $url        = esc_url_raw( $request->get_param( 'url' ) ?? '' );
        $title      = sanitize_text_field( $request->get_param( 'title' ) ?? '' );
        if ( empty( $url ) ) {
            return new WP_REST_Response( array( 'success' => false ), 400 );
        }
        $this->api_client->record_citation_click( $session_id, $url, $title );
        return new WP_REST_Response( array( 'success' => true ), 200 );
    }
}
