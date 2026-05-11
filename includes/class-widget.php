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
        // Default greetings for common languages
        $greetings = array(
            'en' => 'Hi! How can I help you navigate our website today?',
            'hi' => 'नमस्ते! आज मैं आपकी वेबसाइट नेविगेट करने में कैसे मदद कर सकता हूँ?',
            'es' => '¡Hola! ¿Cómo puedo ayudarte a navegar por nuestro sitio web hoy?',
            'fr' => 'Bonjour ! Comment puis-je vous aider à naviguer sur notre site aujourd\'hui ?',
            'de' => 'Hallo! Wie kann ich Ihnen heute bei der Navigation auf unserer Website helfen?',
            'pt' => 'Olá! Como posso ajudá-lo a navegar em nosso site hoje?',
            'zh' => '您好！今天我能如何帮助您浏览我们的网站？',
            'ja' => 'こんにちは！本日はウェブサイトのナビゲーションをどのようにお手伝いできますか？',
            'ko' => '안녕하세요! 오늘 웹사이트 탐색에 어떻게 도움을 드릴까요?',
            'ar' => 'مرحباً! كيف يمكنني مساعدتك في تصفح موقعنا اليوم؟',
            'ru' => 'Привет! Как я могу помочь вам сегодня с навигацией по нашему сайту?',
        );

        // Get language code from WordPress locale
        $locale        = get_locale();
        $language_code = substr( $locale, 0, 2 );

        // Return greeting for the language, or default to English
        return isset( $greetings[ $language_code ] ) ? $greetings[ $language_code ] : $greetings['en'];
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
        $widget_style     = sprintf(
            '--bitbot-primary: %s; --bitbot-offset-x: %dpx; --bitbot-offset-y: %dpx;',
            esc_attr( $primary_color ),
            $offset_x,
            $offset_y
        );
        ?>
        <div id="bitbot-widget" class="bitbot-<?php echo esc_attr( $position ); ?>" data-preferred="<?php echo esc_attr( $preferred_corner ); ?>" data-base-offset-y="<?php echo esc_attr( $offset_y ); ?>" style="<?php echo esc_attr( $widget_style ); ?>"></div>
        <template id="bitbot-widget-template">
            <div class="bitbot-widget">
                <!-- Chat Toggle Button -->
                <button id="bitbot-toggle" class="bitbot-toggle" aria-label="<?php esc_attr_e( 'Open chat', 'bitbot' ); ?>">
                    <svg class="bitbot-icon-chat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                    </svg>
                    <svg class="bitbot-icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none;">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>

                <!-- Chat Window -->
                <div id="bitbot-chat" class="bitbot-chat" style="display:none;">
                    <!-- Header -->
                    <div class="bitbot-header">
                        <span class="bitbot-title"><?php esc_html_e( 'Chat with us', 'bitbot' ); ?></span>
                        <button id="bitbot-minimize" class="bitbot-minimize" aria-label="<?php esc_attr_e( 'Minimize chat', 'bitbot' ); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                            </svg>
                        </button>
                    </div>

                    <!-- Messages Container -->
                    <div id="bitbot-messages" class="bitbot-messages">
                        <!-- Welcome message -->
                        <div class="bitbot-message bitbot-bot">
                            <div class="bitbot-message-content">
                                <?php echo esc_html( $welcome_message ); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Input Area -->
                    <form id="bitbot-form" class="bitbot-input-area">
                        <input
                            type="text"
                            id="bitbot-input"
                            class="bitbot-input"
                            placeholder="<?php esc_attr_e( 'Type your message...', 'bitbot' ); ?>"
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

        $result = $this->api_client->chat( $message, $sanitized_history, $session_id, $visitor_ip, $site_language, $visitor_id, $visitor_timezone );

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
}
