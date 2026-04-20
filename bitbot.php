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
 * Version:           1.6.1
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
define( 'BITBOT_VERSION', '1.6.1' );
define( 'BITBOT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BITBOT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BITBOT_API_URL', 'https://youami.ai/api' );
// define( 'BITBOT_API_URL', 'https://dev.youami.ai/api' );

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

        // Auto-sync on content changes
        add_action( 'save_post', array( $this, 'on_post_saved' ), 10, 2 );
        add_action( 'trashed_post', array( $this, 'on_post_deleted' ) );
        add_action( 'before_delete_post', array( $this, 'on_post_deleted' ) );

        // Deferred sync handler (runs via WP-Cron, not inside the REST save request)
        add_action( 'bitbot_sync_post', array( $this, 'run_deferred_post_sync' ) );

        // Redirect to dashboard after activation
        add_action( 'admin_init', array( $this, 'activation_redirect' ) );

        // One-time upgrade tasks (e.g., backfill notification_email on 1.5.2 -> 1.6.0)
        add_action( 'admin_init', array( $this, 'maybe_run_upgrade_tasks' ) );

        // Ensure API key exists (in case activation hook didn't run)
        $this->ensure_api_key();

        // Register shortcodes
        add_shortcode( 'bitbot_form', array( $this, 'render_form_shortcode' ) );

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
     * One-time upgrade tasks. Runs on every admin page load; the stored
     * `bitbot_db_version` option ensures each task fires at most once per install.
     *
     * @since 1.6.0
     */
    public function maybe_run_upgrade_tasks() {
        $stored = get_option( 'bitbot_db_version', '0' );
        if ( version_compare( $stored, BITBOT_VERSION, '>=' ) ) {
            return;
        }

        // 1.6.0: backfill notification_email on the backend for sites that installed
        // pre-1.5.2 (migration 009 added the column as NULL for existing rows).
        if ( version_compare( $stored, '1.6.0', '<' ) ) {
            $local_notification = get_option( 'bitbot_notification_email', '' );
            $admin_email        = get_option( 'admin_email', '' );
            $email              = ! empty( $local_notification ) ? $local_notification : $admin_email;

            if ( ! empty( $email ) && is_email( $email ) ) {
                // Fire and forget — backend update. Failure is non-fatal; next
                // time the user opens Settings the sanitize callback will retry.
                $this->api_client->update_notification_email( $email );

                // Mirror into the local option so the Settings UI shows the correct value.
                if ( empty( $local_notification ) ) {
                    update_option( 'bitbot_notification_email', $email );
                }
            }
        }

        update_option( 'bitbot_db_version', BITBOT_VERSION );
    }

    /**
     * Redirect to dashboard after plugin activation
     *
     * @since 1.5.0
     */
    public function activation_redirect() {
        if ( get_transient( 'bitbot_activation_redirect' ) ) {
            delete_transient( 'bitbot_activation_redirect' );

            // Don't redirect on bulk activations or network admin
            if ( is_network_admin() || isset( $_GET['activate-multi'] ) ) {
                return;
            }

            wp_safe_redirect( admin_url( 'admin.php?page=bitbot&auto_sync=1' ) );
            exit;
        }
    }

    /**
     * Sync when a post or page is saved (published or updated)
     *
     * @since 1.5.0
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     */
    public function on_post_saved( $post_id, $post ) {
        // Skip autosaves, revisions, and non-public post types
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) ) return;
        if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) return;

        // Only sync published content
        if ( $post->post_status !== 'publish' ) return;

        // Debounce: if a sync for this post is already pending, cancel it and re-queue.
        // Latest save wins — no duplicate runs.
        $args = array( (int) $post_id );
        $existing = wp_next_scheduled( 'bitbot_sync_post', $args );
        if ( $existing ) {
            wp_unschedule_event( $existing, 'bitbot_sync_post', $args );
        }

        wp_schedule_single_event( time() + 10, 'bitbot_sync_post', $args );
    }

    /**
     * Deferred sync handler — runs via WP-Cron outside the REST save request.
     *
     * @since 1.5.2
     * @param int $post_id Post ID.
     */
    public function run_deferred_post_sync( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) return;
        if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) return;
        if ( $post->post_status !== 'publish' ) return;

        $this->api_client->sync_single_page(
            get_permalink( $post_id ),
            get_the_title( $post_id ),
            apply_filters( 'the_content', $post->post_content )
        );
    }

    /**
     * Remove from index when a post or page is trashed/deleted
     *
     * @since 1.5.0
     * @param int $post_id Post ID.
     */
    public function on_post_deleted( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) return;

        // Full sync sends allUrls — backend removes pages not in that list
        $this->api_client->trigger_sync();
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

        // Flag to redirect to dashboard and auto-sync on first load
        set_transient( 'bitbot_activation_redirect', true, 60 );
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
            array( 'jquery', 'jquery-ui-sortable' ),
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

        // Form submission endpoint (public)
        register_rest_route( 'bitbot/v1', '/form-submit', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_form_submit' ),
            'permission_callback' => '__return_true',
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
     * Handle form submission from shortcode
     *
     * @since 1.4.0
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_form_submit( $request ) {
        $params = $request->get_json_params();

        $form_id = isset( $params['formId'] ) ? sanitize_text_field( $params['formId'] ) : '';
        $name = isset( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '';
        $email = isset( $params['email'] ) ? sanitize_email( $params['email'] ) : '';
        $phone = isset( $params['phone'] ) ? sanitize_text_field( $params['phone'] ) : '';
        $message = isset( $params['message'] ) ? sanitize_textarea_field( $params['message'] ) : '';

        if ( empty( $name ) || empty( $email ) ) {
            return new WP_REST_Response( array( 'error' => 'Name and email are required' ), 400 );
        }

        // Get visitor IP for geolocation
        $ip_address = $request->get_header( 'X-Forwarded-For' );
        if ( $ip_address ) {
            $ip_address = explode( ',', $ip_address )[0]; // First IP in chain
        } else {
            $ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
        }
        $ip_address = sanitize_text_field( trim( $ip_address ) );

        $result = $this->api_client->submit_form( $form_id, $name, $email, $phone, $message, $ip_address );

        if ( $result['success'] ) {
            return new WP_REST_Response( array( 'success' => true ), 200 );
        } else {
            return new WP_REST_Response( array( 'error' => $result['error'] ), 500 );
        }
    }

    /**
     * Render [bitbot_form] shortcode
     *
     * @since 1.4.0
     * @return string
     */
    public function render_form_shortcode( $atts = array() ) {
        $atts = shortcode_atts( array( 'id' => '' ), $atts, 'bitbot_form' );
        $form_id = sanitize_text_field( $atts['id'] );

        $fields = array();
        if ( $form_id ) {
            $form_data = $this->api_client->get_form( $form_id );
            if ( $form_data['success'] && ! empty( $form_data['data']['fields'] ) ) {
                $fields = $form_data['data']['fields'];
            }
        }

        // Fallback: if no form_id or fetch failed, try first available form
        if ( empty( $fields ) && empty( $form_id ) ) {
            $forms_result = $this->api_client->get_forms();
            if ( $forms_result['success'] && ! empty( $forms_result['data']['forms'] ) ) {
                $first_form = $forms_result['data']['forms'][0];
                $form_id = $first_form['id'];
                $fields = $first_form['fields'];
            }
        }

        if ( empty( $fields ) ) {
            // Fallback default fields
            $fields = array(
                array( 'name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'placeholder' => 'Your name' ),
                array( 'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'placeholder' => 'you@example.com' ),
                array( 'name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => false, 'placeholder' => 'How can we help?' ),
            );
        }

        $primary_color = sanitize_hex_color( get_option( 'bitbot_primary_color', '#0073aa' ) );

        ob_start();
        ?>
        <div class="bitbot-lead-form" style="max-width: 500px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
            <form id="bitbot-lead-form" novalidate>
                <?php foreach ( $fields as $field ) :
                    $name = esc_attr( $field['name'] );
                    $label = esc_html( $field['label'] );
                    $type = esc_attr( $field['type'] );
                    $required = ! empty( $field['required'] );
                    $placeholder = isset( $field['placeholder'] ) ? esc_attr( $field['placeholder'] ) : '';
                    $options = isset( $field['options'] ) ? $field['options'] : array();
                ?>
                <div style="margin-bottom: 16px;">
                    <label for="bitbot-field-<?php echo $name; ?>" style="display: block; font-weight: 500; margin-bottom: 4px; font-size: 14px;">
                        <?php echo $label; ?>
                        <?php if ( $required ) : ?><span style="color: #dc3545;">*</span><?php endif; ?>
                    </label>
                    <?php if ( $type === 'textarea' ) : ?>
                        <textarea id="bitbot-field-<?php echo $name; ?>" name="<?php echo $name; ?>" placeholder="<?php echo $placeholder; ?>" rows="4" <?php echo $required ? 'required' : ''; ?>
                            style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; resize: vertical;"></textarea>
                    <?php elseif ( $type === 'select' ) : ?>
                        <select id="bitbot-field-<?php echo $name; ?>" name="<?php echo $name; ?>" <?php echo $required ? 'required' : ''; ?>
                            style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px;">
                            <option value=""><?php echo $placeholder ? $placeholder : 'Select...'; ?></option>
                            <?php foreach ( $options as $opt ) : ?>
                                <option value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ( $type === 'radio' ) : ?>
                        <?php foreach ( $options as $opt ) : ?>
                            <label style="display: block; margin-bottom: 4px; font-weight: normal; font-size: 14px;">
                                <input type="radio" name="<?php echo $name; ?>" value="<?php echo esc_attr( $opt ); ?>" <?php echo $required ? 'required' : ''; ?>>
                                <?php echo esc_html( $opt ); ?>
                            </label>
                        <?php endforeach; ?>
                    <?php elseif ( $type === 'checkbox' ) : ?>
                        <label style="font-weight: normal; font-size: 14px;">
                            <input type="checkbox" id="bitbot-field-<?php echo $name; ?>" name="<?php echo $name; ?>" value="1" <?php echo $required ? 'required' : ''; ?>>
                            <?php echo $placeholder ? esc_html( $placeholder ) : $label; ?>
                        </label>
                    <?php else :
                        $input_type = ( $type === 'email' ) ? 'email' : ( ( $type === 'phone' ) ? 'tel' : 'text' );
                    ?>
                        <input type="<?php echo $input_type; ?>" id="bitbot-field-<?php echo $name; ?>" name="<?php echo $name; ?>" placeholder="<?php echo $placeholder; ?>" <?php echo $required ? 'required' : ''; ?>
                            style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px;">
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <div id="bitbot-form-message" style="display: none; padding: 10px; border-radius: 6px; margin-bottom: 12px; font-size: 14px;"></div>

                <button type="submit" id="bitbot-form-submit"
                    style="background: <?php echo $primary_color; ?>; color: #fff; border: none; padding: 12px 24px; border-radius: 6px; font-size: 15px; cursor: pointer; width: 100%; font-weight: 500;">
                    <?php esc_html_e( 'Submit', 'bitbot' ); ?>
                </button>
            </form>

            <script>
            (function() {
                var form = document.getElementById('bitbot-lead-form');
                if (!form) return;

                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var btn = document.getElementById('bitbot-form-submit');
                    var msg = document.getElementById('bitbot-form-message');
                    btn.disabled = true;
                    btn.textContent = 'Submitting...';
                    msg.style.display = 'none';

                    var formData = new FormData(form);
                    var data = { formId: '<?php echo esc_js( $form_id ); ?>' };
                    formData.forEach(function(value, key) { data[key] = value; });

                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '<?php echo esc_url( rest_url( 'bitbot/v1/form-submit' ) ); ?>');
                    xhr.setRequestHeader('Content-Type', 'application/json');
                    xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce( 'wp_rest' ); ?>');
                    xhr.onload = function() {
                        btn.disabled = false;
                        btn.textContent = 'Submit';
                        if (xhr.status >= 200 && xhr.status < 300) {
                            msg.style.display = 'block';
                            msg.style.background = '#edfaef';
                            msg.style.color = '#00a32a';
                            msg.textContent = 'Thank you! We\'ll be in touch soon.';
                            form.reset();
                        } else {
                            var errMsg = 'Something went wrong. Please try again.';
                            try { var resp = JSON.parse(xhr.responseText); if (resp.error) errMsg = resp.error; } catch(e) {}
                            msg.style.display = 'block';
                            msg.style.background = '#fcf0f1';
                            msg.style.color = '#d63638';
                            msg.textContent = errMsg;
                        }
                    };
                    xhr.onerror = function() {
                        btn.disabled = false;
                        btn.textContent = 'Submit';
                        msg.style.display = 'block';
                        msg.style.background = '#fcf0f1';
                        msg.style.color = '#d63638';
                        msg.textContent = 'Could not connect. Please check your internet and try again.';
                    };
                    xhr.send(JSON.stringify(data));
                });
            })();
            </script>
        </div>
        <?php
        return ob_get_clean();
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
