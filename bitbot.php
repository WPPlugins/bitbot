<?php
/**
 * BitBot - AI Chatbot & Content Generator
 *
 * @package           BitBot
 * @author            youami.ai
 * @copyright         2024 youami.ai
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       BitBot
 * Plugin URI:        https://youami.ai
 * Description:       AI-powered multilingual chatbot that helps visitors navigate your website in any language, plus AI content generation.
 * Version:           1.3.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            youami.ai
 * Text Domain:       bitbot
 * Domain Path:       /languages
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
 *
 * EXTERNAL SERVICES NOTICE:
 * This plugin connects to external services at youami.ai to provide AI functionality.
 * By using this plugin, you agree to the Terms of Service (https://youami.ai/terms)
 * and Privacy Policy (https://youami.ai/privacy).
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'BITBOT_VERSION', '1.3.0' );
define( 'BITBOT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BITBOT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
// define( 'BITBOT_API_URL', 'https://youami.ai/api' );
define( 'BITBOT_API_URL', 'https://dev.youami.ai/api' );

// Include required files
require_once BITBOT_PLUGIN_DIR . 'includes/class-api-client.php';
require_once BITBOT_PLUGIN_DIR . 'includes/class-admin.php';
require_once BITBOT_PLUGIN_DIR . 'includes/class-widget.php';

/**
 * Main plugin class
 *
 * @since 1.0.0
 */
class BitBot {

    /**
     * Singleton instance
     *
     * @var BitBot|null
     */
    private static $instance = null;

    /**
     * API client instance
     *
     * @var BitBot_API_Client
     */
    private $api_client;

    /**
     * Admin instance
     *
     * @var BitBot_Admin|null
     */
    private $admin;

    /**
     * Widget instance
     *
     * @var BitBot_Widget
     */
    private $widget;

    /**
     * Get singleton instance
     *
     * @since 1.0.0
     * @return BitBot
     */
    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    private function __construct() {
        $this->api_client = new BitBot_API_Client();

        // Initialize admin if in admin area
        if ( is_admin() ) {
            $this->admin = new BitBot_Admin( $this->api_client );
        }

        // Initialize widget on frontend
        $this->widget = new BitBot_Widget( $this->api_client );

        // Register hooks
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        // Enqueue scripts and styles
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        // Register REST API endpoints
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        // Auto-sync when a post is published
        add_action( 'transition_post_status', array( $this, 'on_post_published' ), 10, 3 );

        // Ensure API key exists (in case activation hook didn't run)
        $this->ensure_api_key();

        // Add settings link to plugins page
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );
    }

    /**
     * Add settings link to plugins page
     *
     * @since 1.0.0
     * @param array $links Existing plugin action links.
     * @return array Modified plugin action links.
     */
    public function add_settings_link( $links ) {
        $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=bitbot-settings' ) ) . '">' . __( 'Settings', 'bitbot' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Ensure API key exists
     *
     * @since 1.0.0
     */
    private function ensure_api_key() {
        if ( ! get_option( 'bitbot_api_key' ) ) {
            $api_key = 'bb_' . str_replace( '-', '', wp_generate_uuid4() );
            update_option( 'bitbot_api_key', $api_key );
        }
    }

    /**
     * Trigger sync when a post is published
     *
     * @since 1.0.0
     * @param string  $new_status New post status.
     * @param string  $old_status Old post status.
     * @param WP_Post $post       Post object.
     */
    public function on_post_published( $new_status, $old_status, $post ) {
        // Only trigger when transitioning TO 'publish' status
        if ( $new_status === 'publish' && $old_status !== 'publish' ) {
            // Only for posts (not pages, attachments, etc.) - or customize as needed
            if ( $post->post_type === 'post' ) {
                // Get the published post URL
                $post_url = get_permalink( $post->ID );

                // Sync only this single URL (much more efficient than full site sync)
                $this->api_client->sync_single_url( $post_url );
            }
        }
    }

    /**
     * Plugin activation
     *
     * @since 1.0.0
     */
    public function activate() {
        // Generate unique API key for this site
        if ( ! get_option( 'bitbot_api_key' ) ) {
            $api_key = 'bb_' . str_replace( '-', '', wp_generate_uuid4() );
            update_option( 'bitbot_api_key', $api_key );
        }

        // Set default options
        $defaults = array(
            'enabled'         => true,
            'widget_position' => 'bottom-right',
            'primary_color'   => '#0073aa',
            'welcome_message' => __( 'Hi! How can I help you navigate our website today?', 'bitbot' ),
        );

        foreach ( $defaults as $key => $value ) {
            if ( get_option( 'bitbot_' . $key ) === false ) {
                update_option( 'bitbot_' . $key, $value );
            }
        }

        // Flush rewrite rules
        flush_rewrite_rules();

        // Register site immediately on activation
        $api_client = new BitBot_API_Client();
        $api_client->register_site();
    }

    /**
     * Plugin deactivation
     *
     * @since 1.0.0
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook( 'bitbot_scheduled_posts' );
    }

    /**
     * Enqueue frontend assets
     *
     * @since 1.0.0
     */
    public function enqueue_frontend_assets() {
        if ( ! get_option( 'bitbot_enabled', true ) ) {
            return;
        }

        wp_enqueue_style(
            'bitbot-widget',
            BITBOT_PLUGIN_URL . 'assets/css/widget.css',
            array(),
            BITBOT_VERSION
        );

        wp_enqueue_script(
            'bitbot-widget',
            BITBOT_PLUGIN_URL . 'assets/js/widget.js',
            array(),
            BITBOT_VERSION,
            true
        );

        // Get site language code (e.g., 'en', 'hi', 'es')
        $locale = get_locale();
        $language_code = substr( $locale, 0, 2 ); // Extract first 2 chars (e.g., 'en' from 'en_US')

        wp_localize_script( 'bitbot-widget', 'bitbotConfig', array(
            'restUrl'        => esc_url_raw( rest_url( 'bitbot/v1' ) ),
            'nonce'          => wp_create_nonce( 'wp_rest' ),
            'position'       => sanitize_text_field( get_option( 'bitbot_widget_position', 'bottom-right' ) ),
            'primaryColor'   => sanitize_hex_color( get_option( 'bitbot_primary_color', '#0073aa' ) ),
            'welcomeMessage' => wp_kses_post( get_option( 'bitbot_welcome_message', __( 'Hi! How can I help you?', 'bitbot' ) ) ),
            'languageCode'   => sanitize_text_field( $language_code ),
        ) );
    }

    /**
     * Enqueue admin assets
     *
     * @since 1.0.0
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'bitbot' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'bitbot-admin',
            BITBOT_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            BITBOT_VERSION
        );

        wp_enqueue_script(
            'bitbot-admin',
            BITBOT_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            BITBOT_VERSION,
            true
        );

        wp_localize_script( 'bitbot-admin', 'bitbotAdmin', array(
            'ajaxUrl' => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
            'nonce'   => wp_create_nonce( 'bitbot_admin_nonce' ),
        ) );
    }

    /**
     * Register REST API routes
     *
     * @since 1.0.0
     */
    public function register_rest_routes() {
        // Chat endpoint (for the widget)
        register_rest_route( 'bitbot/v1', '/chat', array(
            'methods'             => 'POST',
            'callback'            => array( $this->widget, 'handle_chat_request' ),
            'permission_callback' => '__return_true', // Public endpoint
            'args'                => array(
                'message' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'history' => array(
                    'required' => false,
                    'type'     => 'array',
                    'default'  => array(),
                ),
                'sessionId' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ) );

        // Sync endpoint (admin only)
        register_rest_route( 'bitbot/v1', '/sync', array(
            'methods'             => 'POST',
            'callback'            => array( $this->admin, 'handle_sync_request' ),
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
        ) );

        // Generate post endpoint (admin only)
        register_rest_route( 'bitbot/v1', '/generate-post', array(
            'methods'             => 'POST',
            'callback'            => array( $this->admin, 'handle_generate_post_request' ),
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
        ) );
    }

    /**
     * Get API client instance
     *
     * @since 1.0.0
     * @return BitBot_API_Client
     */
    public function get_api_client() {
        return $this->api_client;
    }
}

// Initialize plugin
add_action( 'plugins_loaded', function() {
    BitBot::get_instance();
} );
