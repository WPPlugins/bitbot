<?php
/**
 * BitBot Admin
 *
 * Handles all admin dashboard functionality.
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
 * Admin class for BitBot
 *
 * @since 1.0.0
 */
class BitBot_Admin {

    private $api_client;

    public function __construct($api_client) {
        $this->api_client = $api_client;

        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));

        // Register AJAX handlers
        add_action('wp_ajax_bitbot_sync', array($this, 'ajax_sync'));
        add_action('wp_ajax_bitbot_generate', array($this, 'ajax_generate'));
        add_action('wp_ajax_bitbot_get_upload_url', array($this, 'ajax_get_upload_url'));
        add_action('wp_ajax_bitbot_generate_from_storage', array($this, 'ajax_generate_from_storage'));
        add_action('wp_ajax_bitbot_generate_from_doc_url', array($this, 'ajax_generate_from_doc_url'));
        add_action('wp_ajax_bitbot_discover_topics', array($this, 'ajax_discover_topics'));
        add_action('wp_ajax_bitbot_get_draft', array($this, 'ajax_get_draft'));
        add_action('wp_ajax_bitbot_publish_draft', array($this, 'ajax_publish_draft'));
        add_action('wp_ajax_bitbot_delete_draft', array($this, 'ajax_delete_draft'));
        add_action('wp_ajax_bitbot_subscribe', array($this, 'ajax_subscribe'));
        add_action('wp_ajax_bitbot_cancel_subscription', array($this, 'ajax_cancel_subscription'));
        add_action('wp_ajax_bitbot_reactivate_subscription', array($this, 'ajax_reactivate_subscription'));
        add_action('wp_ajax_bitbot_billing_portal', array($this, 'ajax_billing_portal'));
        add_action('wp_ajax_bitbot_get_conversations', array($this, 'ajax_get_conversations'));
        add_action('wp_ajax_bitbot_get_conversation_messages', array($this, 'ajax_get_conversation_messages'));

        // Page management AJAX handlers
        add_action('wp_ajax_bitbot_get_pages', array($this, 'ajax_get_pages'));
        add_action('wp_ajax_bitbot_add_page', array($this, 'ajax_add_page'));
        add_action('wp_ajax_bitbot_remove_page', array($this, 'ajax_remove_page'));
        add_action('wp_ajax_bitbot_toggle_page', array($this, 'ajax_toggle_page'));
        add_action('wp_ajax_bitbot_resync_page', array($this, 'ajax_resync_page'));

        // Reconnect handler
        add_action('wp_ajax_bitbot_reconnect', array($this, 'ajax_reconnect'));
    }

    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        // Main menu page
        add_menu_page(
            __('BitBot', 'bitbot'),
            __('BitBot', 'bitbot'),
            'manage_options',
            'bitbot',
            array($this, 'render_dashboard_page'),
            'dashicons-format-chat',
            30
        );

        // Dashboard submenu
        add_submenu_page(
            'bitbot',
            __('Dashboard', 'bitbot'),
            __('Dashboard', 'bitbot'),
            'manage_options',
            'bitbot',
            array($this, 'render_dashboard_page')
        );

        // Content Generator submenu
        add_submenu_page(
            'bitbot',
            __('Content Generator', 'bitbot'),
            __('Content Generator', 'bitbot'),
            'edit_posts',
            'bitbot-content',
            array($this, 'render_content_page')
        );

        // Settings submenu
        add_submenu_page(
            'bitbot',
            __('Settings', 'bitbot'),
            __('Settings', 'bitbot'),
            'manage_options',
            'bitbot-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        // Chatbot settings with sanitize callbacks
        register_setting('bitbot_settings', 'bitbot_enabled', array(
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => true,
        ));
        register_setting('bitbot_settings', 'bitbot_widget_position', array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_widget_position'),
            'default'           => 'bottom-right',
        ));
        register_setting('bitbot_settings', 'bitbot_primary_color', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#0073aa',
        ));
        register_setting('bitbot_settings', 'bitbot_welcome_message', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default'           => 'Hi! How can I help you navigate our website today?',
        ));

        // Add settings sections
        add_settings_section(
            'bitbot_chatbot_section',
            __('Chatbot Settings', 'bitbot'),
            array($this, 'render_chatbot_section'),
            'bitbot-settings'
        );

        // Add settings fields
        add_settings_field(
            'bitbot_enabled',
            __('Enable Chatbot', 'bitbot'),
            array($this, 'render_enabled_field'),
            'bitbot-settings',
            'bitbot_chatbot_section'
        );

        add_settings_field(
            'bitbot_widget_position',
            __('Widget Position', 'bitbot'),
            array($this, 'render_position_field'),
            'bitbot-settings',
            'bitbot_chatbot_section'
        );

        add_settings_field(
            'bitbot_primary_color',
            __('Primary Color', 'bitbot'),
            array($this, 'render_color_field'),
            'bitbot-settings',
            'bitbot_chatbot_section'
        );

        add_settings_field(
            'bitbot_welcome_message',
            __('Welcome Message', 'bitbot'),
            array($this, 'render_welcome_field'),
            'bitbot-settings',
            'bitbot_chatbot_section'
        );
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        $reconnected = false;

        // Check if API key is valid by testing subscription endpoint
        $subscription = $this->api_client->get_subscription();

        // Auto-reconnect if API key is invalid (just re-register with existing key)
        if ( ! $subscription['success'] && strpos( $subscription['error'] ?? '', 'Invalid API key' ) !== false ) {
            $reconnect_result = $this->api_client->register_site();
            if ( $reconnect_result['success'] ) {
                $reconnected = true;
                // Re-fetch subscription with updated key in DB
                $subscription = $this->api_client->get_subscription();
            }
        }

        $sync_status = $this->api_client->get_sync_status();
        $usage = $this->api_client->get_usage();
        $api_key = get_option('bitbot_api_key', '');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('BitBot Dashboard', 'bitbot'); ?></h1>

            <p style="color: #666; font-size: 12px;">
                <?php esc_html_e('API Key:', 'bitbot'); ?> <code><?php echo esc_html($api_key ?: 'NOT SET'); ?></code>
                <button id="bitbot-reconnect-btn" class="button button-small" style="margin-left: 10px;">
                    <?php esc_html_e('Reconnect', 'bitbot'); ?>
                </button>
            </p>

            <?php if ($reconnected): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Site reconnected successfully!', 'bitbot'); ?></p>
            </div>
            <?php endif; ?>

            <div class="bitbot-dashboard">
                <!-- Sync Status Card -->
                <div class="bitbot-card">
                    <h2><?php esc_html_e('Content Sync', 'bitbot'); ?></h2>
                    <?php if ($sync_status['success']): ?>
                        <p>
                            <strong><?php esc_html_e('Status:', 'bitbot'); ?></strong>
                            <?php echo esc_html($sync_status['data']['status'] ?? 'Unknown'); ?>
                        </p>
                        <p>
                            <strong><?php esc_html_e('Pages Indexed:', 'bitbot'); ?></strong>
                            <?php echo esc_html($sync_status['data']['pagesCount'] ?? 0); ?>
                        </p>
                        <p>
                            <strong><?php esc_html_e('Last Sync:', 'bitbot'); ?></strong>
                            <?php
                            $last_sync = $sync_status['data']['lastSync'] ?? null;
                            echo $last_sync ? esc_html(wp_date('M j, Y g:i A', strtotime($last_sync))) : 'Never';
                            ?>
                        </p>
                    <?php else: ?>
                        <p class="error"><?php echo esc_html($sync_status['error'] ?? 'Unable to fetch status'); ?></p>
                    <?php endif; ?>
                    <button id="bitbot-sync-btn" class="button button-primary">
                        <?php esc_html_e('Sync Now', 'bitbot'); ?>
                    </button>
                </div>

                <!-- Usage Card -->
                <div class="bitbot-card">
                    <h2><?php esc_html_e('Usage (Last 30 Days)', 'bitbot'); ?></h2>
                    <?php if ($usage['success']): ?>
                        <p>
                            <strong><?php esc_html_e('Chat Queries:', 'bitbot'); ?></strong>
                            <?php echo esc_html($usage['data']['chatQueries'] ?? 0); ?>
                        </p>
                        <p>
                            <strong><?php esc_html_e('Posts Generated:', 'bitbot'); ?></strong>
                            <?php echo esc_html($usage['data']['postsGenerated'] ?? 0); ?>
                        </p>
                    <?php else: ?>
                        <p class="error"><?php echo esc_html($usage['error'] ?? 'Unable to fetch usage'); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Subscription Card -->
                <div class="bitbot-card">
                    <h2><?php esc_html_e('Subscription', 'bitbot'); ?></h2>
                    <?php if ($subscription['success']):
                        $sub_data = $subscription['data'];
                        $status = $sub_data['subscriptionStatus'] ?? 'trialing';
                        $is_active = $sub_data['isActive'] ?? false;
                        $days_remaining = $sub_data['daysRemaining'] ?? null;
                        $trial_ends_at = $sub_data['trialEndsAt'] ?? null;
                        $current_period_end = $sub_data['currentPeriodEnd'] ?? null;

                        // Status badge styles
                        $status_styles = array(
                            'trialing' => 'background: #0073aa; color: white;',
                            'active' => 'background: #46b450; color: white;',
                            'cancelled' => 'background: #f0ad4e; color: white;',
                            'expired' => 'background: #dc3545; color: white;',
                        );
                        $status_style = $status_styles[$status] ?? $status_styles['trialing'];
                        $status_label = ucfirst($status);
                    ?>
                        <p>
                            <strong><?php esc_html_e('Status:', 'bitbot'); ?></strong>
                            <span style="<?php echo esc_attr($status_style); ?> padding: 3px 10px; border-radius: 3px; font-size: 12px;">
                                <?php echo esc_html($status_label); ?>
                            </span>
                        </p>

                        <?php if ($status === 'trialing' && $trial_ends_at): ?>
                            <p>
                                <strong><?php esc_html_e('Trial Ends:', 'bitbot'); ?></strong>
                                <?php echo esc_html(gmdate('F j, Y', strtotime($trial_ends_at))); ?>
                                <?php if ($days_remaining !== null): ?>
                                    <span style="color: #666;">(<?php echo esc_html($days_remaining); ?> days remaining)</span>
                                <?php endif; ?>
                                <?php if ($days_remaining !== null && $days_remaining <= 7): ?>
                                    <br><span style="color: #dc3545; font-weight: bold;">
                                        <?php esc_html_e('Subscribe now to avoid interruption!', 'bitbot'); ?>
                                    </span>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>

                        <?php if ($status === 'active' && $current_period_end): ?>
                            <p>
                                <strong><?php esc_html_e('Next Renewal:', 'bitbot'); ?></strong>
                                <?php echo esc_html(gmdate('F j, Y', strtotime($current_period_end))); ?>
                            </p>
                        <?php endif; ?>

                        <?php if ($status === 'cancelled' && $is_active && $current_period_end): ?>
                            <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 10px; border-radius: 4px; margin: 10px 0;">
                                <strong><?php esc_html_e('Subscription Cancelled', 'bitbot'); ?></strong>
                                <p style="margin: 5px 0 0;">
                                    <?php
                                    /* translators: %s: subscription end date */
                                    printf(esc_html__('Your subscription is cancelled but you still have access until %s.', 'bitbot'), esc_html(gmdate('F j, Y', strtotime($current_period_end))));
                                    ?>
                                </p>
                            </div>
                        <?php endif; ?>

                        <?php if ($status === 'expired'): ?>
                            <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 10px; border-radius: 4px; margin: 10px 0;">
                                <strong><?php esc_html_e('Your trial has expired!', 'bitbot'); ?></strong>
                                <p style="margin: 5px 0 0;"><?php esc_html_e('Subscribe now for just $10/month to continue using BitBot.', 'bitbot'); ?></p>
                            </div>
                        <?php endif; ?>

                        <p style="margin-top: 15px;">
                            <strong><?php esc_html_e('Price:', 'bitbot'); ?></strong> $10/month
                        </p>

                        <?php if ($status === 'active'): ?>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <button id="bitbot-billing-btn" class="button button-secondary">
                                    <?php esc_html_e('Manage Billing / Invoices', 'bitbot'); ?>
                                </button>
                                <button id="bitbot-cancel-btn" class="button" style="color: #a00;">
                                    <?php esc_html_e('Cancel Subscription', 'bitbot'); ?>
                                </button>
                            </div>
                        <?php elseif ($status === 'cancelled' && $is_active): ?>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <button id="bitbot-billing-btn" class="button button-secondary">
                                    <?php esc_html_e('Manage Billing / Invoices', 'bitbot'); ?>
                                </button>
                                <button id="bitbot-reactivate-btn" class="button button-primary">
                                    <?php esc_html_e('Reactivate Subscription', 'bitbot'); ?>
                                </button>
                            </div>
                        <?php elseif ($status === 'expired' || ($status === 'cancelled' && !$is_active)): ?>
                            <button id="bitbot-subscribe-btn" class="button button-primary button-hero" style="margin-top: 10px;">
                                <?php esc_html_e('Subscribe Now - $10/mo', 'bitbot'); ?>
                            </button>
                        <?php elseif ($status === 'trialing'): ?>
                            <button id="bitbot-subscribe-btn" class="button button-primary button-hero" style="margin-top: 10px;">
                                <?php esc_html_e('Subscribe - $10/mo', 'bitbot'); ?>
                            </button>
                        <?php endif; ?>

                    <?php else: ?>
                        <p class="error"><?php echo esc_html($subscription['error'] ?? 'Unable to fetch subscription'); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Indexed Pages Card -->
                <div class="bitbot-card bitbot-card-full">
                    <h2><?php esc_html_e('Indexed Pages', 'bitbot'); ?></h2>
                    <p class="description" style="margin-bottom: 15px;">
                        <?php esc_html_e('Manage which pages are indexed and available for the chatbot. You can add, remove, or toggle pages.', 'bitbot'); ?>
                    </p>

                    <!-- Add URL Form -->
                    <div style="margin-bottom: 20px; padding: 15px; background: #f9f9f9; border-radius: 4px;">
                        <form id="bitbot-add-page-form" style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                            <div>
                                <label for="bitbot-new-url" style="display: block; margin-bottom: 5px; font-weight: 500;">
                                    <?php esc_html_e('Add URL to Index', 'bitbot'); ?>
                                </label>
                                <input type="url" id="bitbot-new-url" placeholder="https://yoursite.com/page" style="width: 350px;" class="regular-text">
                            </div>
                            <button type="submit" class="button button-secondary">
                                <?php esc_html_e('Add Page', 'bitbot'); ?>
                            </button>
                        </form>
                    </div>

                    <div id="bitbot-pages-container">
                        <button id="bitbot-load-pages-btn" class="button button-secondary">
                            <?php esc_html_e('Load Pages', 'bitbot'); ?>
                        </button>
                        <div id="bitbot-pages-list" style="display: none; margin-top: 15px;">
                            <div style="max-height: 350px; overflow-y: auto; border: 1px solid #c3c4c7; border-radius: 4px;">
                                <table class="wp-list-table widefat fixed striped bitbot-pages-table" style="margin: 0; border: none;">
                                    <thead style="position: sticky; top: 0; background: #f6f7f7; z-index: 1;">
                                        <tr>
                                            <th style="width: 5%;"><?php esc_html_e('Enabled', 'bitbot'); ?></th>
                                            <th style="width: 35%;"><?php esc_html_e('URL', 'bitbot'); ?></th>
                                            <th style="width: 20%;"><?php esc_html_e('Title', 'bitbot'); ?></th>
                                            <th style="width: 25%;"><?php esc_html_e('Summary', 'bitbot'); ?></th>
                                            <th style="width: 15%;"><?php esc_html_e('Actions', 'bitbot'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody id="bitbot-pages-tbody">
                                    </tbody>
                                </table>
                            </div>
                            <p id="bitbot-pages-total" style="margin-top: 10px; color: #666;"></p>
                        </div>
                    </div>
                </div>

                <!-- Conversations Card -->
                <div class="bitbot-card bitbot-card-full">
                    <h2><?php esc_html_e('Recent Conversations', 'bitbot'); ?></h2>
                    <p class="description" style="margin-bottom: 15px;">
                        <?php esc_html_e('View chat conversations from your website visitors.', 'bitbot'); ?>
                        <?php if ($subscription['success'] && $subscription['data']['subscriptionStatus'] !== 'active'): ?>
                            <span style="color: #888; font-style: italic;"><?php esc_html_e('(Trial: limited to 10)', 'bitbot'); ?></span>
                        <?php endif; ?>
                    </p>
                    <div id="bitbot-conversations-container">
                        <button id="bitbot-load-conversations-btn" class="button button-secondary">
                            <?php esc_html_e('Load Conversations', 'bitbot'); ?>
                        </button>
                        <div id="bitbot-conversations-list" style="display: none; margin-top: 15px;">
                            <table class="wp-list-table widefat fixed striped bitbot-conversations-table">
                                <thead>
                                    <tr>
                                        <th style="width: 12%;"><?php esc_html_e('Session', 'bitbot'); ?></th>
                                        <th style="width: 20%;"><?php esc_html_e('IP Address', 'bitbot'); ?></th>
                                        <th style="width: 10%;"><?php esc_html_e('Messages', 'bitbot'); ?></th>
                                        <th style="width: 20%;"><?php esc_html_e('Last Active', 'bitbot'); ?></th>
                                        <th style="width: 10%;"><?php esc_html_e('Actions', 'bitbot'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="bitbot-conversations-tbody">
                                </tbody>
                            </table>
                            <div id="bitbot-conversations-pagination"></div>
                        </div>
                    </div>
                </div>

                <!-- Conversation Messages Modal -->
                <div id="bitbot-conversation-modal" class="bitbot-modal-overlay" style="display: none;">
                    <div class="bitbot-modal" style="max-width: 600px;">
                        <div class="bitbot-modal-header">
                            <h3><?php esc_html_e('Conversation', 'bitbot'); ?></h3>
                            <button id="bitbot-close-modal" class="bitbot-modal-close">&times;</button>
                        </div>
                        <div class="bitbot-modal-content">
                            <div id="bitbot-conversation-messages"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render content generator page
     */
    public function render_content_page() {
        $drafts = $this->api_client->get_drafts();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Content Generator', 'bitbot'); ?></h1>

            <!-- Discover Topics Card -->
            <div class="bitbot-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <h2 style="color: white; margin-top: 0;"><?php esc_html_e('Discover Topics', 'bitbot'); ?></h2>
                <p><?php esc_html_e('Discover trending topics in your niche. Click a topic to generate SEO-optimized content.', 'bitbot'); ?></p>
                <p>
                    <button id="bitbot-discover-topics-btn" class="button button-hero" style="background: white; color: #667eea; border: none; font-weight: bold;">
                        <?php esc_html_e('Discover Topics', 'bitbot'); ?>
                    </button>
                    <span id="discover-spinner" class="spinner" style="float: none;"></span>
                </p>
                <div id="bitbot-topics-container" style="display: none; margin-top: 20px; background: rgba(255,255,255,0.1); padding: 15px; border-radius: 8px;">
                    <div id="bitbot-content-gaps"></div>
                    <h3 style="color: white; margin-top: 0;"><?php esc_html_e('Suggested Topics', 'bitbot'); ?></h3>
                    <div id="bitbot-topics-list"></div>
                </div>
            </div>

            <!-- Generate New Post Form -->
            <div class="bitbot-card">
                <h2><?php esc_html_e('Generate from Prompt', 'bitbot'); ?></h2>
                <p class="description"><?php esc_html_e('Or manually specify what you want to write about.', 'bitbot'); ?></p>
                <form id="bitbot-generate-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="source_type"><?php esc_html_e('Source Type', 'bitbot'); ?></label>
                            </th>
                            <td>
                                <select id="source_type" name="source_type">
                                    <option value="prompt"><?php esc_html_e('Write from Prompt', 'bitbot'); ?></option>
                                    <option value="upload"><?php esc_html_e('From Document (File or URL)', 'bitbot'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr id="prompt-row">
                            <th scope="row">
                                <label for="prompt_input"><?php esc_html_e('Topic/Prompt', 'bitbot'); ?></label>
                            </th>
                            <td>
                                <textarea id="prompt_input" name="prompt_input" rows="4" class="large-text"></textarea>
                                <p class="description"><?php esc_html_e('Describe what you want the post to be about.', 'bitbot'); ?></p>
                            </td>
                        </tr>
                        <tr id="upload-row" style="display:none;">
                            <th scope="row">
                                <label><?php esc_html_e('Document Source', 'bitbot'); ?></label>
                            </th>
                            <td>
                                <div style="margin-bottom: 10px;">
                                    <label style="margin-right: 15px;">
                                        <input type="radio" name="upload_method" value="file" checked> <?php esc_html_e('Upload File', 'bitbot'); ?>
                                    </label>
                                    <label>
                                        <input type="radio" name="upload_method" value="doc_url"> <?php esc_html_e('From URL', 'bitbot'); ?>
                                    </label>
                                </div>
                                <div id="file-upload-section">
                                    <input type="file" id="file_input" name="file_input" accept=".pdf,.png,.jpg,.jpeg,.gif,.webp">
                                    <p class="description"><?php esc_html_e('Upload a PDF or image file (PNG, JPG, GIF, WEBP). Max 10MB.', 'bitbot'); ?></p>
                                </div>
                                <div id="doc-url-section" style="display:none;">
                                    <input type="url" id="doc_url_input" name="doc_url_input" class="regular-text" placeholder="https://example.com/article...">
                                    <p class="description"><?php esc_html_e('Enter any URL (web page, PDF, etc.). Content is automatically extracted.', 'bitbot'); ?></p>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="instructions"><?php esc_html_e('Additional Instructions', 'bitbot'); ?></label>
                            </th>
                            <td>
                                <textarea id="instructions" name="instructions" rows="2" class="large-text"></textarea>
                                <p class="description"><?php esc_html_e('Optional: specific instructions for tone, style, keywords, etc.', 'bitbot'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Generate Post', 'bitbot'); ?></button>
                        <span id="generate-spinner" class="spinner"></span>
                    </p>
                </form>
            </div>

            <!-- Drafts List -->
            <div class="bitbot-card">
                <h2><?php esc_html_e('Generated Drafts', 'bitbot'); ?></h2>
                <?php if ($drafts['success'] && !empty($drafts['data'])): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Title', 'bitbot'); ?></th>
                                <th><?php esc_html_e('Status', 'bitbot'); ?></th>
                                <th><?php esc_html_e('Created', 'bitbot'); ?></th>
                                <th><?php esc_html_e('Actions', 'bitbot'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($drafts['data'] as $draft):
                                $status = $draft['status'] ?? 'draft';
                                $status_class = '';
                                $status_label = ucfirst($status);
                                if ($status === 'published') {
                                    $status_class = 'background: #46b450; color: white; padding: 2px 8px; border-radius: 3px;';
                                } elseif ($status === 'draft') {
                                    $status_class = 'background: #0073aa; color: white; padding: 2px 8px; border-radius: 3px;';
                                } elseif ($status === 'scheduled') {
                                    $status_class = 'background: #f0ad4e; color: white; padding: 2px 8px; border-radius: 3px;';
                                }
                            ?>
                                <tr data-draft-id="<?php echo esc_attr($draft['id']); ?>">
                                    <td><?php echo esc_html($draft['title']); ?></td>
                                    <td><span style="<?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span></td>
                                    <td><?php echo esc_html(gmdate('M j, Y g:i A', strtotime($draft['createdAt'] ?? $draft['created_at'] ?? 'now'))); ?></td>
                                    <td>
                                        <button class="button preview-draft" data-id="<?php echo esc_attr($draft['id']); ?>">
                                            <?php esc_html_e('Preview', 'bitbot'); ?>
                                        </button>
                                        <?php if ($status !== 'published'): ?>
                                        <button class="button button-primary publish-draft" data-id="<?php echo esc_attr($draft['id']); ?>">
                                            <?php esc_html_e('Create WP Draft', 'bitbot'); ?>
                                        </button>
                                        <?php endif; ?>
                                        <button class="button delete-draft" data-id="<?php echo esc_attr($draft['id']); ?>" style="color: #a00;">
                                            <?php esc_html_e('Delete', 'bitbot'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><?php esc_html_e('No drafts yet. Generate your first post above!', 'bitbot'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('BitBot Settings', 'bitbot'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('bitbot_settings');
                do_settings_sections('bitbot-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Settings field renderers
     */
    public function render_chatbot_section() {
        echo '<p>' . esc_html__('Configure how the chatbot appears and behaves on your site.', 'bitbot') . '</p>';
    }

    public function render_enabled_field() {
        $enabled = get_option('bitbot_enabled', true);
        ?>
        <label>
            <input type="checkbox" name="bitbot_enabled" value="1" <?php checked($enabled, true); ?>>
            <?php esc_html_e('Show chatbot widget on frontend', 'bitbot'); ?>
        </label>
        <?php
    }

    public function render_position_field() {
        $position = get_option('bitbot_widget_position', 'bottom-right');
        ?>
        <select name="bitbot_widget_position">
            <option value="bottom-right" <?php selected($position, 'bottom-right'); ?>><?php esc_html_e('Bottom Right', 'bitbot'); ?></option>
            <option value="bottom-left" <?php selected($position, 'bottom-left'); ?>><?php esc_html_e('Bottom Left', 'bitbot'); ?></option>
        </select>
        <?php
    }

    public function render_color_field() {
        $color = get_option('bitbot_primary_color', '#0073aa');
        ?>
        <input type="color" name="bitbot_primary_color" value="<?php echo esc_attr($color); ?>">
        <?php
    }

    public function render_welcome_field() {
        $message = get_option('bitbot_welcome_message', 'Hi! How can I help you navigate our website today?');
        ?>
        <textarea name="bitbot_welcome_message" rows="2" class="large-text"><?php echo esc_textarea($message); ?></textarea>
        <?php
    }

    /**
     * Sanitize widget position setting
     *
     * @param string $value The position value to sanitize.
     * @return string Sanitized position value.
     */
    public function sanitize_widget_position( $value ) {
        $allowed_positions = array( 'bottom-right', 'bottom-left' );
        $value = sanitize_text_field( $value );
        return in_array( $value, $allowed_positions, true ) ? $value : 'bottom-right';
    }

    /**
     * Handle sync request (REST API)
     */
    public function handle_sync_request($request) {
        $result = $this->api_client->trigger_sync();

        if ($result['success']) {
            return new WP_REST_Response(array(
                'success' => true,
                'message' => __('Sync started successfully', 'bitbot'),
            ), 200);
        }

        return new WP_REST_Response(array(
            'success' => false,
            'error' => $result['error'],
        ), 400);
    }

    /**
     * Handle generate post request (REST API)
     */
    public function handle_generate_post_request($request) {
        $source_type = $request->get_param('source_type');
        $source_value = $request->get_param('source_value');
        $instructions = $request->get_param('instructions');

        $result = $this->api_client->generate_post($source_type, $source_value, $instructions);

        if ($result['success']) {
            return new WP_REST_Response(array(
                'success' => true,
                'data' => $result['data'],
            ), 200);
        }

        return new WP_REST_Response(array(
            'success' => false,
            'error' => $result['error'],
        ), 400);
    }

    /**
     * AJAX handler for sync
     */
    public function ajax_sync() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $result = $this->api_client->trigger_sync();

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * AJAX handler for generate
     */
    public function ajax_generate() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        // Validate source_type against allowed values
        $allowed_source_types = array( 'prompt', 'upload' );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on next line
        $source_type = isset( $_POST['source_type'] ) ? sanitize_text_field( wp_unslash( $_POST['source_type'] ) ) : '';
        if ( ! in_array( $source_type, $allowed_source_types, true ) ) {
            wp_send_json_error(array('error' => 'Invalid source type'));
        }

        // Sanitize source_value based on source_type
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in switch below
        $raw_source_value = isset( $_POST['source_value'] ) ? wp_unslash( $_POST['source_value'] ) : '';
        switch ( $source_type ) {
            case 'upload':
                $source_value = sanitize_file_name( $raw_source_value );
                break;
            case 'prompt':
            default:
                $source_value = sanitize_textarea_field( $raw_source_value );
                break;
        }

        $instructions = isset( $_POST['instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instructions'] ) ) : '';

        $result = $this->api_client->generate_post($source_type, $source_value, $instructions);

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * AJAX handler for getting presigned upload URL
     */
    public function ajax_get_upload_url() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on next line
        $file_name = isset( $_POST['file_name'] ) ? sanitize_file_name( wp_unslash( $_POST['file_name'] ) ) : '';

        if ( empty( $file_name ) ) {
            wp_send_json_error(array('error' => 'File name is required'));
        }

        $result = $this->api_client->get_upload_url( $file_name );

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * AJAX handler for generating post from uploaded storage file
     */
    public function ajax_generate_from_storage() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on next line
        $storage_path = isset( $_POST['storage_path'] ) ? sanitize_text_field( wp_unslash( $_POST['storage_path'] ) ) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on next line
        $file_name = isset( $_POST['file_name'] ) ? sanitize_file_name( wp_unslash( $_POST['file_name'] ) ) : '';
        $instructions = isset( $_POST['instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instructions'] ) ) : '';

        if ( empty( $storage_path ) || empty( $file_name ) ) {
            wp_send_json_error(array('error' => 'Storage path and file name are required'));
        }

        $result = $this->api_client->generate_post_from_storage( $storage_path, $file_name, $instructions );

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * AJAX handler for generating post from a document URL
     */
    public function ajax_generate_from_doc_url() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on next line
        $doc_url = isset( $_POST['doc_url'] ) ? esc_url_raw( wp_unslash( $_POST['doc_url'] ) ) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on next line
        $file_name = isset( $_POST['file_name'] ) ? sanitize_file_name( wp_unslash( $_POST['file_name'] ) ) : '';
        $instructions = isset( $_POST['instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instructions'] ) ) : '';

        if ( empty( $doc_url ) ) {
            wp_send_json_error(array('error' => 'Document URL is required'));
        }

        $result = $this->api_client->generate_post_from_doc_url( $doc_url, $file_name, $instructions );

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * AJAX handler for discover topics
     */
    public function ajax_discover_topics() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $result = $this->api_client->discover_topics();

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * AJAX handler for get draft
     */
    public function ajax_get_draft() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on same line
        $draft_id = isset( $_GET['draft_id'] ) ? sanitize_text_field( wp_unslash( $_GET['draft_id'] ) ) : ( isset( $_POST['draft_id'] ) ? sanitize_text_field( wp_unslash( $_POST['draft_id'] ) ) : '' );

        $result = $this->api_client->get_draft($draft_id);

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * AJAX handler for publish draft
     */
    public function ajax_publish_draft() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $draft_id = isset( $_POST['draft_id'] ) ? sanitize_text_field( wp_unslash( $_POST['draft_id'] ) ) : '';

        $result = $this->api_client->create_wp_post_from_draft($draft_id);

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * AJAX handler for delete draft
     */
    public function ajax_delete_draft() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $draft_id = isset( $_POST['draft_id'] ) ? sanitize_text_field( wp_unslash( $_POST['draft_id'] ) ) : '';

        $result = $this->api_client->delete_draft($draft_id);

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * AJAX handler for subscribe - redirects to Stripe checkout
     */
    public function ajax_subscribe() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $result = $this->api_client->create_checkout();

        if ($result['success']) {
            wp_send_json_success(array(
                'checkoutUrl' => $result['data']['checkoutUrl'],
            ));
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * AJAX handler for cancel subscription
     */
    public function ajax_cancel_subscription() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $result = $this->api_client->cancel_subscription();

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * AJAX handler for reactivate subscription (undo cancellation)
     */
    public function ajax_reactivate_subscription() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $result = $this->api_client->reactivate_subscription();

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * AJAX handler for billing portal
     */
    public function ajax_billing_portal() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $result = $this->api_client->get_billing_portal();

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * AJAX handler for get conversations
     */
    public function ajax_get_conversations() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $result = $this->api_client->get_conversations();

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * AJAX handler for get conversation messages
     */
    public function ajax_get_conversation_messages() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $conversation_id = isset( $_POST['conversation_id'] ) ? sanitize_text_field( wp_unslash( $_POST['conversation_id'] ) ) : '';

        if (empty($conversation_id)) {
            wp_send_json_error(array('error' => 'Conversation ID required'));
        }

        $result = $this->api_client->get_conversation_messages($conversation_id);

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * AJAX handler for get pages
     */
    public function ajax_get_pages() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $result = $this->api_client->get_pages();

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * AJAX handler for add page
     */
    public function ajax_add_page() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

        if (empty($url)) {
            wp_send_json_error(array('error' => 'URL is required'));
        }

        $result = $this->api_client->add_page($url);

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * AJAX handler for remove page
     */
    public function ajax_remove_page() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $page_id = isset( $_POST['page_id'] ) ? sanitize_text_field( wp_unslash( $_POST['page_id'] ) ) : '';

        if (empty($page_id)) {
            wp_send_json_error(array('error' => 'Page ID is required'));
        }

        $result = $this->api_client->remove_page($page_id);

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * AJAX handler for toggle page
     */
    public function ajax_toggle_page() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $page_id = isset( $_POST['page_id'] ) ? sanitize_text_field( wp_unslash( $_POST['page_id'] ) ) : '';
        $enabled = isset( $_POST['enabled'] ) ? rest_sanitize_boolean( wp_unslash( $_POST['enabled'] ) ) : true;

        if (empty($page_id)) {
            wp_send_json_error(array('error' => 'Page ID is required'));
        }

        $result = $this->api_client->toggle_page($page_id, $enabled);

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * AJAX handler for resync page
     */
    public function ajax_resync_page() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $page_url = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';

        if (empty($page_url)) {
            wp_send_json_error(array('error' => 'Page URL is required'));
        }

        $result = $this->api_client->resync_page($page_url);

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * AJAX handler for manual reconnect
     */
    public function ajax_reconnect() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        // Regenerate API key with new bb_ format and reconnect
        $result = $this->api_client->regenerate_and_reconnect();

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }
}
