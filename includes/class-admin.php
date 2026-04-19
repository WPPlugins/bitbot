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

        // Redirect Sync Now menu to dashboard before headers are sent
        add_action('admin_init', array($this, 'handle_sync_redirect'));

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

        // Leads AJAX handlers
        add_action('wp_ajax_bitbot_get_leads', array($this, 'ajax_get_leads'));
        add_action('wp_ajax_bitbot_get_lead', array($this, 'ajax_get_lead'));
        add_action('wp_ajax_bitbot_update_lead_stage', array($this, 'ajax_update_lead_stage'));
        add_action('wp_ajax_bitbot_add_lead_note', array($this, 'ajax_add_lead_note'));
        add_action('wp_ajax_bitbot_delete_lead', array($this, 'ajax_delete_lead'));
        add_action('wp_ajax_bitbot_run_lead_research', array($this, 'ajax_run_lead_research'));
        add_action('wp_ajax_bitbot_draft_lead_email', array($this, 'ajax_draft_lead_email'));

        // Form AJAX handlers
        add_action('wp_ajax_bitbot_get_forms', array($this, 'ajax_get_forms'));
        add_action('wp_ajax_bitbot_get_form', array($this, 'ajax_get_form'));
        add_action('wp_ajax_bitbot_create_form', array($this, 'ajax_create_form'));
        add_action('wp_ajax_bitbot_update_form', array($this, 'ajax_update_form'));
        add_action('wp_ajax_bitbot_delete_form', array($this, 'ajax_delete_form'));
        add_action('wp_ajax_bitbot_generate_form', array($this, 'ajax_generate_form'));

        // Reconnect handler
        add_action('wp_ajax_bitbot_reconnect', array($this, 'ajax_reconnect'));
    }

    /**
     * Cache key for the "new leads" count transient.
     *
     * @var string
     */
    const NEW_LEADS_COUNT_TRANSIENT = 'bitbot_new_leads_count';

    /**
     * Get count of unviewed leads — leads that haven't been opened in the
     * admin yet. Cached for 60s.
     *
     * Returns 0 on failure (e.g. API down, unregistered site) so the menu
     * still renders cleanly.
     *
     * @return int
     */
    private function get_new_leads_count() {
        $cached = get_transient( self::NEW_LEADS_COUNT_TRANSIENT );
        if ( $cached !== false ) {
            return (int) $cached;
        }

        $result = $this->api_client->get_unviewed_leads_count();
        $count  = 0;
        if ( ! empty( $result['success'] ) && isset( $result['data']['count'] ) ) {
            $count = (int) $result['data']['count'];
        }

        set_transient( self::NEW_LEADS_COUNT_TRANSIENT, $count, MINUTE_IN_SECONDS );
        return $count;
    }

    /**
     * Bust the new-leads count cache so the next admin page render refreshes it.
     */
    private function bust_new_leads_count_cache() {
        delete_transient( self::NEW_LEADS_COUNT_TRANSIENT );
    }

    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        $new_leads   = $this->get_new_leads_count();
        $bubble      = $new_leads > 0
            ? ' <span class="awaiting-mod update-plugins count-' . absint( $new_leads ) . '"><span class="pending-count">' . absint( $new_leads ) . '</span></span>'
            : '';
        $bitbot_label = __( 'BitBot', 'bitbot' ) . $bubble;
        $leads_label  = __( 'Leads', 'bitbot' ) . $bubble;

        // Main menu page
        add_menu_page(
            __('BitBot', 'bitbot'),
            $bitbot_label,
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

        // Sync Now submenu — redirects to dashboard with auto_sync flag
        add_submenu_page(
            'bitbot',
            __('Sync Now', 'bitbot'),
            __('Sync Now', 'bitbot'),
            'manage_options',
            'bitbot-sync',
            array($this, 'redirect_to_sync')
        );

        // Leads submenu
        add_submenu_page(
            'bitbot',
            __('Leads', 'bitbot'),
            $leads_label,
            'manage_options',
            'bitbot-leads',
            array($this, 'render_leads_page')
        );

        // Form Builder submenu
        add_submenu_page(
            'bitbot',
            __('Form Builder', 'bitbot'),
            __('Form Builder', 'bitbot'),
            'manage_options',
            'bitbot-forms',
            array($this, 'render_form_builder_page')
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
        register_setting('bitbot_settings', 'bitbot_notification_email', array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_notification_email'),
            'default'           => get_option('admin_email', ''),
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

        // Leads & Notifications section
        add_settings_section(
            'bitbot_leads_section',
            __('Leads & Notifications', 'bitbot'),
            array($this, 'render_leads_section'),
            'bitbot-settings'
        );

        add_settings_field(
            'bitbot_notification_email',
            __('Notification Email', 'bitbot'),
            array($this, 'render_notification_email_field'),
            'bitbot-settings',
            'bitbot_leads_section'
        );
    }

    /**
     * Sanitize notification email and push the change to the BitBot backend.
     *
     * @since 1.5.2
     * @param string $value Raw input.
     * @return string Sanitized email (or empty string to disable notifications).
     */
    public function sanitize_notification_email( $value ) {
        $clean = sanitize_email( (string) $value );
        if ( ! empty( $value ) && empty( $clean ) ) {
            add_settings_error(
                'bitbot_notification_email',
                'bitbot_notification_email_invalid',
                __( 'Notification email is not a valid address — leaving unchanged.', 'bitbot' )
            );
            return (string) get_option( 'bitbot_notification_email', '' );
        }

        // Push the change upstream — failures are non-fatal, local option still persists.
        $this->api_client->update_notification_email( $clean );

        return $clean;
    }

    /**
     * Handle Sync Now redirect (runs on admin_init, before headers)
     */
    public function handle_sync_redirect() {
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'bitbot-sync' ) {
            wp_safe_redirect( admin_url( 'admin.php?page=bitbot&auto_sync=1' ) );
            exit;
        }
    }

    /**
     * Fallback render for Sync Now page (should never be reached due to redirect)
     */
    public function redirect_to_sync() {
        echo '<p>Redirecting...</p>';
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

            <?php
            $auto_sync = isset($_GET['auto_sync']) && $_GET['auto_sync'] === '1';
            $pages_count = $sync_status['data']['pagesCount'] ?? 0;
            $last_sync = $sync_status['data']['lastSync'] ?? null;
            $needs_sync = ! $last_sync || $pages_count === 0;
            ?>

            <?php if ($needs_sync && ! $auto_sync): ?>
            <div class="notice notice-warning" style="padding: 12px; border-left-color: #0073aa;">
                <p style="font-size: 14px; margin: 0;">
                    <strong><?php esc_html_e('Your content is not synced yet.', 'bitbot'); ?></strong>
                    <?php esc_html_e('The chatbot needs your content indexed to answer visitor questions.', 'bitbot'); ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=bitbot&auto_sync=1' ) ); ?>" class="button button-primary" style="margin-left: 10px;">
                        <?php esc_html_e('Sync Now', 'bitbot'); ?>
                    </a>
                </p>
            </div>
            <?php endif; ?>

            <div class="bitbot-dashboard">
                <!-- Sync Status Card -->
                <div class="bitbot-card" style="<?php echo $needs_sync ? 'border: 2px solid #0073aa; box-shadow: 0 0 0 1px #0073aa;' : ''; ?>">
                    <h2><?php esc_html_e('Content Sync', 'bitbot'); ?></h2>
                    <?php if ($sync_status['success']): ?>
                        <p>
                            <strong><?php esc_html_e('Status:', 'bitbot'); ?></strong>
                            <?php echo esc_html($sync_status['data']['status'] ?? 'Unknown'); ?>
                        </p>
                        <p>
                            <strong><?php esc_html_e('Pages Indexed:', 'bitbot'); ?></strong>
                            <?php echo esc_html($pages_count); ?>
                        </p>
                        <p>
                            <strong><?php esc_html_e('Last Sync:', 'bitbot'); ?></strong>
                            <?php echo $last_sync ? esc_html(wp_date('M j, Y g:i A', strtotime($last_sync))) : '<span style="color: #dc3545; font-weight: bold;">Never</span>'; ?>
                        </p>
                    <?php else: ?>
                        <p class="error"><?php echo esc_html($sync_status['error'] ?? 'Unable to fetch status'); ?></p>
                    <?php endif; ?>
                    <button id="bitbot-sync-btn" class="button button-primary" style="font-size: 14px; padding: 6px 20px; height: auto;">
                        <?php esc_html_e('Sync Now', 'bitbot'); ?>
                    </button>
                    <span id="bitbot-sync-status" style="margin-left: 10px; display: none; color: #666;"></span>
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

    public function render_leads_section() {
        echo '<p>' . esc_html__( 'Where BitBot should send an email when a new lead comes in. Leave blank to disable notifications.', 'bitbot' ) . '</p>';
    }

    public function render_notification_email_field() {
        $email = get_option( 'bitbot_notification_email', get_option( 'admin_email', '' ) );
        ?>
        <input
            type="email"
            name="bitbot_notification_email"
            value="<?php echo esc_attr( $email ); ?>"
            class="regular-text"
            placeholder="you@example.com"
        >
        <p class="description">
            <?php esc_html_e( 'Defaults to the WordPress admin email. You can change it any time.', 'bitbot' ); ?>
        </p>
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

    public function ajax_run_lead_research() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(array('error' => 'Permission denied')); }
        $lead_id = isset($_POST['lead_id']) ? sanitize_text_field($_POST['lead_id']) : '';
        $result = $this->api_client->run_lead_research($lead_id);
        $result['success'] ? wp_send_json_success($result['data']) : wp_send_json_error(array('error' => $result['error']));
    }

    public function ajax_draft_lead_email() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(array('error' => 'Permission denied')); }
        $lead_id = isset($_POST['lead_id']) ? sanitize_text_field($_POST['lead_id']) : '';
        $result = $this->api_client->draft_lead_email($lead_id);
        $result['success'] ? wp_send_json_success($result['data']) : wp_send_json_error(array('error' => $result['error']));
    }

    // ============================================
    // Form Builder
    // ============================================

    /**
     * Render form builder page — routes to list, create, or edit
     */
    public function render_form_builder_page() {
        $form_id = isset($_GET['form_id']) ? sanitize_text_field($_GET['form_id']) : '';
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

        if ($action === 'new') {
            $this->render_form_create_page();
        } elseif ($form_id) {
            $this->render_form_edit_page($form_id);
        } else {
            $this->render_forms_list_page();
        }
    }

    /**
     * Render forms list page
     */
    private function render_forms_list_page() {
        $api_key = get_option('bitbot_api_key', '');
        ?>
        <div class="wrap">
            <h1 style="display: flex; justify-content: space-between; align-items: center;">
                <?php esc_html_e('Forms', 'bitbot'); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=bitbot-forms&action=new')); ?>" class="page-title-action"><?php esc_html_e('Create New Form', 'bitbot'); ?></a>
            </h1>

            <div id="bitbot-forms-list" style="margin-top: 10px;">
                <div class="spinner is-active" style="float: none;"></div>
                <span><?php esc_html_e('Loading forms...', 'bitbot'); ?></span>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
        (function($) {
            var config = window.bitbotAdmin || {};

            $.ajax({
                url: config.ajaxUrl, method: 'POST',
                data: { action: 'bitbot_get_forms', nonce: config.nonce },
                success: function(response) {
                    if (!response.success) { $('#bitbot-forms-list').html('<p class="error">Failed to load forms.</p>'); return; }
                    var forms = (response.data && response.data.forms) || [];
                    if (forms.length === 0) {
                        $('#bitbot-forms-list').html(
                            '<div class="bitbot-card" style="text-align: center; padding: 40px;">' +
                            '<h3>No forms yet</h3>' +
                            '<p>Create your first lead capture form to start collecting leads.</p>' +
                            '<a href="<?php echo esc_url(admin_url('admin.php?page=bitbot-forms&action=new')); ?>" class="button button-primary">Create New Form</a>' +
                            '</div>'
                        );
                        return;
                    }

                    var html = '<table class="wp-list-table widefat fixed striped">';
                    html += '<thead><tr><th style="width:25%;">Name</th><th style="width:10%;">Fields</th><th style="width:12%;">Status</th><th style="width:25%;">Shortcode</th><th style="width:15%;">Created</th><th style="width:13%;">Actions</th></tr></thead><tbody>';
                    forms.forEach(function(f) {
                        var editUrl = '<?php echo esc_url(admin_url('admin.php?page=bitbot-forms')); ?>&form_id=' + f.id;
                        var statusColor = f.enabled ? '#46b450' : '#999';
                        var statusText = f.enabled ? 'Active' : 'Inactive';
                        var date = new Date(f.createdAt).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                        html += '<tr>';
                        html += '<td><a href="' + editUrl + '" style="font-weight:600;">' + esc(f.name) + '</a></td>';
                        html += '<td>' + (f.fields ? f.fields.length : 0) + '</td>';
                        html += '<td><span class="bitbot-status" style="background:' + statusColor + ';color:#fff;">' + statusText + '</span></td>';
                        html += '<td><code style="font-size:12px;">[bitbot_form id=&quot;' + f.id + '&quot;]</code></td>';
                        html += '<td>' + date + '</td>';
                        html += '<td><a href="' + editUrl + '" class="button button-small">Edit</a> <button class="button button-small bitbot-delete-form" data-id="' + f.id + '" style="color:#dc3545;">Delete</button></td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table>';
                    $('#bitbot-forms-list').html(html);

                    $('.bitbot-delete-form').on('click', function() {
                        if (!confirm('Delete this form?')) return;
                        var $btn = $(this);
                        $.ajax({ url: config.ajaxUrl, method: 'POST', data: { action: 'bitbot_delete_form', nonce: config.nonce, form_id: $btn.data('id') },
                            success: function() { $btn.closest('tr').fadeOut(); }
                        });
                    });
                },
                error: function() { $('#bitbot-forms-list').html('<p class="error">Failed to load forms.</p>'); }
            });

            function esc(s) { if (!s) return ''; var d = document.createElement('div'); d.appendChild(document.createTextNode(s)); return d.innerHTML; }
        })(jQuery);
        });
        </script>
        <?php
    }

    /**
     * Render create form page — text box + Generate with AI
     */
    private function render_form_create_page() {
        ?>
        <div class="wrap">
            <h1>
                <a href="<?php echo esc_url(admin_url('admin.php?page=bitbot-forms')); ?>" style="text-decoration: none;">&larr; <?php esc_html_e('Back to Forms', 'bitbot'); ?></a>
            </h1>

            <div style="max-width: 600px; margin: 40px auto;">
                <div class="bitbot-card" style="padding: 30px;">
                    <h2 style="margin-top: 0; border: none; font-size: 20px;">Create a New Form</h2>
                    <p style="color: #666; margin-bottom: 20px;">Describe what this form is for and AI will generate the perfect fields. Or start with basics.</p>

                    <div style="margin-bottom: 16px;">
                        <label for="bitbot-form-description" style="display: block; font-weight: 500; margin-bottom: 6px;">What is this form for?</label>
                        <textarea id="bitbot-form-description" rows="3" class="large-text" placeholder="e.g., Tax consultation inquiry form for potential clients who want to book a free consultation..." style="font-size: 14px;"></textarea>
                    </div>

                    <div style="display: flex; gap: 10px; align-items: center;">
                        <button type="button" class="button button-primary" id="bitbot-generate-form" style="background: #7c3aed; border-color: #7c3aed;">
                            <span class="dashicons dashicons-superhero-alt" style="margin-top: 4px; font-size: 16px;"></span> Generate with AI
                        </button>
                        <span style="color: #999;">or</span>
                        <button type="button" class="button" id="bitbot-basic-form">Start with Basics</button>
                    </div>

                    <div id="bitbot-create-status" style="margin-top: 16px; display: none;"></div>
                </div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
        (function($) {
            var config = window.bitbotAdmin || {};

            function createForm(name, description, fields) {
                $.ajax({
                    url: config.ajaxUrl, method: 'POST',
                    data: { action: 'bitbot_create_form', nonce: config.nonce, name: name, description: description, fields: JSON.stringify(fields) },
                    success: function(response) {
                        if (response.success && response.data.id) {
                            window.location.href = '<?php echo esc_url(admin_url('admin.php?page=bitbot-forms')); ?>&form_id=' + response.data.id;
                        } else {
                            $('#bitbot-create-status').html('<p class="error">Failed to create form.</p>').show();
                        }
                    },
                    error: function() {
                        $('#bitbot-create-status').html('<p class="error">Failed to create form.</p>').show();
                    }
                });
            }

            // Generate with AI
            $('#bitbot-generate-form').on('click', function() {
                var desc = $('#bitbot-form-description').val().trim();
                if (!desc) { alert('Please describe what the form is for.'); return; }
                var $btn = $(this);
                $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span> Generating...');

                $.ajax({
                    url: config.ajaxUrl, method: 'POST',
                    data: { action: 'bitbot_generate_form', nonce: config.nonce, description: desc },
                    success: function(response) {
                        if (response.success && response.data.fields) {
                            var name = response.data.name || desc.substring(0, 50);
                            createForm(name, desc, response.data.fields);
                        } else {
                            $btn.prop('disabled', false).html('<span class="dashicons dashicons-superhero-alt" style="margin-top:4px;font-size:16px;"></span> Generate with AI');
                            alert('Failed to generate form.');
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-superhero-alt" style="margin-top:4px;font-size:16px;"></span> Generate with AI');
                        alert('Failed to generate form.');
                    }
                });
            });

            // Start with basics
            $('#bitbot-basic-form').on('click', function() {
                var desc = $('#bitbot-form-description').val().trim() || 'Contact Form';
                var fields = [
                    { name: 'name', label: 'Name', type: 'text', required: true, placeholder: 'Your name' },
                    { name: 'email', label: 'Email', type: 'email', required: true, placeholder: 'you@example.com' },
                    { name: 'phone', label: 'Phone', type: 'phone', required: false, placeholder: '+1 (555) 000-0000' },
                    { name: 'message', label: 'Message', type: 'textarea', required: false, placeholder: 'How can we help?' }
                ];
                createForm(desc, desc, fields);
            });
        })(jQuery);
        });
        </script>
        <?php
    }

    /**
     * Render form edit page — field editor + preview + embed codes
     */
    private function render_form_edit_page($form_id) {
        $api_key = get_option('bitbot_api_key', '');
        ?>
        <div class="wrap">
            <h1>
                <a href="<?php echo esc_url(admin_url('admin.php?page=bitbot-forms')); ?>" style="text-decoration: none;">&larr; <?php esc_html_e('Back to Forms', 'bitbot'); ?></a>
            </h1>

            <div id="bitbot-form-editor" style="margin-top: 20px;">
                <div class="spinner is-active" style="float: none;"></div>
                <span><?php esc_html_e('Loading form...', 'bitbot'); ?></span>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
        (function($) {
            var config = window.bitbotAdmin || {};
            var formId = '<?php echo esc_js($form_id); ?>';
            var formData = null;

            function loadForm() {
                $.ajax({
                    url: config.ajaxUrl, method: 'POST',
                    data: { action: 'bitbot_get_form', nonce: config.nonce, form_id: formId },
                    success: function(response) {
                        if (!response.success) { $('#bitbot-form-editor').html('<p class="error">Failed to load form.</p>'); return; }
                        formData = response.data;
                        renderEditor();
                    },
                    error: function() { $('#bitbot-form-editor').html('<p class="error">Failed to load form.</p>'); }
                });
            }

            function renderEditor() {
                var fields = formData.fields || [];
                var html = '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start;">';

                // Left: editor
                html += '<div><div class="bitbot-card">';
                html += '<h2 style="display:flex;justify-content:space-between;align-items:center;">';
                html += '<input type="text" id="bitbot-form-name" value="' + escAttr(formData.name) + '" style="font-size:16px;font-weight:600;border:1px solid transparent;padding:2px 6px;flex:1;" placeholder="Form name">';
                html += '</h2>';

                html += '<div style="margin-bottom:12px;"><label style="display:flex;align-items:center;gap:8px;font-weight:500;">';
                html += '<input type="checkbox" id="bitbot-form-enabled" ' + (formData.enabled ? 'checked' : '') + '> Active</label></div>';

                html += '<div id="bitbot-fields-list">';
                fields.forEach(function(f, i) { html += renderFieldRow(f, i); });
                html += '</div>';

                html += '<div style="margin-top:12px;display:flex;gap:8px;">';
                html += '<button type="button" class="button" id="bitbot-add-field">+ Add Field</button>';
                html += '<button type="button" class="button" id="bitbot-ai-regenerate" style="color:#7c3aed;border-color:#7c3aed;">Regenerate with AI</button>';
                html += '</div>';

                html += '<div style="margin-top:16px;padding-top:16px;border-top:1px solid #eee;">';
                html += '<button type="button" class="button button-primary" id="bitbot-save-form">Save Changes</button>';
                html += '<span id="bitbot-save-status" style="margin-left:10px;color:#46b450;display:none;">Saved!</span>';
                html += '</div>';
                html += '</div></div>';

                // Right: preview + embed
                html += '<div>';
                html += '<div class="bitbot-card" style="margin-bottom:20px;"><h2>Preview</h2>';
                html += '<div id="bitbot-form-preview" style="background:#f9f9f9;padding:20px;border-radius:6px;">' + renderFormPreview(fields) + '</div></div>';

                html += '<div class="bitbot-card"><h2>Embed</h2>';
                html += '<div style="margin-bottom:12px;"><h4 style="margin:0 0 4px;">Shortcode</h4>';
                html += '<div style="display:flex;gap:8px;"><code id="sc" style="flex:1;padding:8px 12px;background:#f0f0f1;border-radius:4px;font-size:13px;">[bitbot_form id=&quot;' + formId + '&quot;]</code>';
                html += '<button type="button" class="button bitbot-copy-btn" data-target="sc">Copy</button></div></div>';

                html += '<div><h4 style="margin:0 0 4px;">External Embed</h4>';
                var embedScript = '&lt;script src=&quot;' + escHtml('<?php echo esc_js(BITBOT_PLUGIN_URL); ?>') + 'assets/js/form-embed.js&quot; data-form-id=&quot;' + formId + '&quot; data-api-key=&quot;' + escHtml('<?php echo esc_js($api_key); ?>') + '&quot; data-api-url=&quot;' + escHtml('<?php echo esc_js(BITBOT_API_URL); ?>') + '&quot;&gt;&lt;/script&gt;';
                html += '<div style="display:flex;gap:8px;"><code id="ec" style="flex:1;padding:8px 12px;background:#f0f0f1;border-radius:4px;font-size:11px;word-break:break-all;line-height:1.4;">' + embedScript + '</code>';
                html += '<button type="button" class="button bitbot-copy-btn" data-target="ec">Copy</button></div></div>';
                html += '</div>';

                html += '</div></div>';
                $('#bitbot-form-editor').html(html);
                bindEvents();
            }

            function renderFieldRow(field, idx) {
                var types = ['text','email','phone','textarea','select','radio','checkbox'];
                var h = '<div class="bitbot-field-row" data-index="' + idx + '" style="background:#f9f9f9;border:1px solid #e0e0e0;border-radius:4px;padding:12px;margin-bottom:8px;">';
                h += '<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">';
                h += '<span class="dashicons dashicons-menu" style="cursor:grab;color:#999;margin-top:2px;"></span>';
                h += '<input type="text" class="regular-text field-label" value="' + escAttr(field.label) + '" placeholder="Field label" style="flex:1;">';
                h += '<select class="field-type" style="width:110px;">';
                types.forEach(function(t) { h += '<option value="' + t + '"' + (field.type===t?' selected':'') + '>' + t.charAt(0).toUpperCase()+t.slice(1) + '</option>'; });
                h += '</select>';
                h += '<label style="display:flex;align-items:center;gap:4px;white-space:nowrap;"><input type="checkbox" class="field-required"' + (field.required?' checked':'') + '> Req</label>';
                h += '<button type="button" class="button bitbot-remove-field" style="color:#dc3545;border-color:#dc3545;padding:0 8px;">&times;</button>';
                h += '</div>';
                if (field.type==='select'||field.type==='radio') {
                    h += '<div style="margin-left:32px;"><input type="text" class="regular-text field-options" value="' + escAttr((field.options||[]).join(', ')) + '" placeholder="Options (comma-separated)" style="width:100%;"></div>';
                }
                h += '<div style="margin-left:32px;margin-top:4px;"><input type="text" class="field-placeholder" value="' + escAttr(field.placeholder||'') + '" placeholder="Placeholder (optional)" style="width:100%;font-size:12px;color:#666;"></div>';
                h += '</div>';
                return h;
            }

            function renderFormPreview(fields) {
                if (!fields || !fields.length) return '<p style="color:#999;text-align:center;">No fields</p>';
                var h = '<form style="max-width:400px;" onsubmit="return false;">';
                fields.forEach(function(f) {
                    h += '<div style="margin-bottom:14px;">';
                    h += '<label style="display:block;font-weight:500;margin-bottom:4px;font-size:14px;">' + escHtml(f.label);
                    if (f.required) h += ' <span style="color:#dc3545;">*</span>';
                    h += '</label>';
                    if (f.type==='textarea') h += '<textarea style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-size:14px;" rows="3" placeholder="'+escAttr(f.placeholder||'')+'" disabled></textarea>';
                    else if (f.type==='select') { h += '<select style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;" disabled><option>'+escHtml(f.placeholder||'Select...')+'</option>'; (f.options||[]).forEach(function(o){h+='<option>'+escHtml(o)+'</option>';}); h+='</select>'; }
                    else if (f.type==='radio') (f.options||[]).forEach(function(o){h+='<label style="display:block;margin-bottom:4px;font-weight:normal;"><input type="radio" disabled> '+escHtml(o)+'</label>';});
                    else if (f.type==='checkbox') h += '<label style="font-weight:normal;"><input type="checkbox" disabled> '+escHtml(f.placeholder||f.label)+'</label>';
                    else { var t = f.type==='email'?'email':(f.type==='phone'?'tel':'text'); h += '<input type="'+t+'" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:4px;font-size:14px;" placeholder="'+escAttr(f.placeholder||'')+'" disabled>'; }
                    h += '</div>';
                });
                h += '<button type="button" style="background:#0073aa;color:#fff;border:none;padding:10px 24px;border-radius:4px;font-size:14px;cursor:pointer;width:100%;">Submit</button></form>';
                return h;
            }

            function bindEvents() {
                // Drag-to-reorder fields
                $('#bitbot-fields-list').sortable({
                    handle: '.dashicons-menu',
                    axis: 'y',
                    cursor: 'grabbing',
                    placeholder: 'bitbot-field-placeholder',
                    tolerance: 'pointer',
                    stop: function() {
                        // Re-index and update preview
                        $('#bitbot-fields-list .bitbot-field-row').each(function(i) { $(this).data('index', i); });
                        $('#bitbot-form-preview').html(renderFormPreview(collectFields()));
                    }
                });
                $('#bitbot-add-field').off('click').on('click', function() {
                    formData.fields = collectFields();
                    formData.fields.push({name:'field_'+(formData.fields.length+1),label:'',type:'text',required:false,placeholder:''});
                    renderEditor();
                });
                $(document).off('click','.bitbot-remove-field').on('click','.bitbot-remove-field', function() {
                    var i = $(this).closest('.bitbot-field-row').data('index');
                    formData.fields = collectFields(); formData.fields.splice(i,1); renderEditor();
                });
                $(document).off('change','.field-type').on('change','.field-type', function() {
                    formData.fields = collectFields(); renderEditor();
                });
                $(document).off('input','.field-label,.field-placeholder,.field-options').on('input','.field-label,.field-placeholder,.field-options', function() {
                    $('#bitbot-form-preview').html(renderFormPreview(collectFields()));
                });
                $(document).off('change','.field-required').on('change','.field-required', function() {
                    $('#bitbot-form-preview').html(renderFormPreview(collectFields()));
                });
                // AI regenerate
                $('#bitbot-ai-regenerate').off('click').on('click', function() {
                    var desc = prompt('Describe what this form is for:', formData.description || formData.name);
                    if (!desc) return;
                    var $btn = $(this); $btn.prop('disabled',true).text('Generating...');
                    $.ajax({ url:config.ajaxUrl, method:'POST', data:{action:'bitbot_generate_form',nonce:config.nonce,description:desc},
                        success: function(r) {
                            if (r.success && r.data.fields) { formData.fields = r.data.fields; if(r.data.name) formData.name = r.data.name; renderEditor(); }
                            else { alert('Failed.'); $btn.prop('disabled',false).text('Regenerate with AI'); }
                        }, error: function() { alert('Failed.'); $btn.prop('disabled',false).text('Regenerate with AI'); }
                    });
                });
                // Save
                $('#bitbot-save-form').off('click').on('click', function() {
                    var $btn = $(this); $btn.prop('disabled',true).text('Saving...');
                    var fields = collectFields();
                    var name = $('#bitbot-form-name').val();
                    var enabled = $('#bitbot-form-enabled').is(':checked');
                    $.ajax({ url:config.ajaxUrl, method:'POST', data:{action:'bitbot_update_form',nonce:config.nonce,form_id:formId,name:name,enabled:enabled?1:0,fields:JSON.stringify(fields)},
                        success: function(r) { $btn.prop('disabled',false).text('Save Changes'); if(r.success) { formData.fields=fields; formData.name=name; formData.enabled=enabled; $('#bitbot-save-status').fadeIn().delay(2000).fadeOut(); } else alert('Failed to save.'); },
                        error: function() { $btn.prop('disabled',false).text('Save Changes'); alert('Failed to save.'); }
                    });
                });
                // Copy
                $('.bitbot-copy-btn').off('click').on('click', function() {
                    var t = $('#'+$(this).data('target')).text();
                    if(navigator.clipboard){navigator.clipboard.writeText(t);$(this).text('Copied!');var b=$(this);setTimeout(function(){b.text('Copy');},1500);}
                });
            }

            function collectFields() {
                var fields = [];
                $('#bitbot-fields-list .bitbot-field-row').each(function(i) {
                    var $r=$(this), type=$r.find('.field-type').val(), label=$r.find('.field-label').val();
                    var name = label ? label.toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_|_$/g,'') : 'field_'+(i+1);
                    var f = {name:name,label:label,type:type,required:$r.find('.field-required').is(':checked'),placeholder:$r.find('.field-placeholder').val()||''};
                    if((type==='select'||type==='radio')&&$r.find('.field-options').length){var o=$r.find('.field-options').val();f.options=o?o.split(',').map(function(s){return s.trim();}).filter(Boolean):[];}
                    fields.push(f);
                });
                return fields;
            }

            function escHtml(s){if(!s)return '';var d=document.createElement('div');d.appendChild(document.createTextNode(s));return d.innerHTML;}
            function escAttr(s){if(!s)return '';return s.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

            loadForm();
        })(jQuery);
        });
        </script>
        <?php
    }

    // ============================================
    // Leads Pipeline
    // ============================================

    /**
     * Render leads page — list view or detail view based on ?lead_id param
     */
    public function render_leads_page() {
        $lead_id = isset($_GET['lead_id']) ? sanitize_text_field($_GET['lead_id']) : '';

        if ($lead_id) {
            $this->render_lead_detail_page($lead_id);
        } else {
            $this->render_leads_list_page();
        }
    }

    /**
     * Render leads list page
     */
    private function render_leads_list_page() {
        $stage_filter = isset($_GET['stage']) ? sanitize_text_field($_GET['stage']) : '';
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Leads', 'bitbot'); ?></h1>

            <!-- Stage filter tabs -->
            <ul class="subsubsub" style="margin-bottom: 15px;">
                <?php
                $stages = array(
                    ''          => __('All', 'bitbot'),
                    'new'       => __('New', 'bitbot'),
                    'contacted' => __('Contacted', 'bitbot'),
                    'qualified' => __('Qualified', 'bitbot'),
                    'won'       => __('Won', 'bitbot'),
                    'lost'      => __('Lost', 'bitbot'),
                );
                $i = 0;
                foreach ($stages as $key => $label):
                    $current = ($stage_filter === $key) ? ' class="current"' : '';
                    $url = admin_url('admin.php?page=bitbot-leads');
                    if ($key) $url = add_query_arg('stage', $key, $url);
                    if ($search) $url = add_query_arg('s', $search, $url);
                ?>
                    <li>
                        <a href="<?php echo esc_url($url); ?>"<?php echo $current; ?>><?php echo esc_html($label); ?></a>
                        <?php if ($i < count($stages) - 1) echo ' |'; ?>
                    </li>
                <?php $i++; endforeach; ?>
            </ul>

            <!-- Search -->
            <form method="get" style="float: right; margin-top: -30px;">
                <input type="hidden" name="page" value="bitbot-leads">
                <?php if ($stage_filter): ?>
                    <input type="hidden" name="stage" value="<?php echo esc_attr($stage_filter); ?>">
                <?php endif; ?>
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search leads...', 'bitbot'); ?>" class="regular-text">
                <button type="submit" class="button"><?php esc_html_e('Search', 'bitbot'); ?></button>
            </form>

            <div style="clear: both;"></div>

            <!-- Leads table — loaded via AJAX -->
            <div id="bitbot-leads-container" style="margin-top: 10px;">
                <div class="spinner is-active" style="float: none;"></div>
                <span><?php esc_html_e('Loading leads...', 'bitbot'); ?></span>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
        (function($) {
            var config = window.bitbotAdmin || {};
            var stageFilter = '<?php echo esc_js($stage_filter); ?>';
            var searchQuery = '<?php echo esc_js($search); ?>';

            function loadLeads() {
                $.ajax({
                    url: config.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'bitbot_get_leads',
                        nonce: config.nonce,
                        stage: stageFilter,
                        search: searchQuery
                    },
                    success: function(response) {
                        if (!response.success) {
                            $('#bitbot-leads-container').html('<p class="error">' + ((response.data && response.data.error) || 'Failed to load leads') + '</p>');
                            return;
                        }
                        var leads = response.data.leads || [];
                        var total = response.data.total || 0;

                        if (leads.length === 0) {
                            $('#bitbot-leads-container').html(
                                '<div class="bitbot-card" style="text-align: center; padding: 40px;">' +
                                '<h3>No leads yet</h3>' +
                                '<p>Leads will appear here when visitors submit forms or share contact info in chat.</p>' +
                                '</div>'
                            );
                            return;
                        }

                        var html = '<p>' + total + ' lead' + (total !== 1 ? 's' : '') + '</p>';
                        html += '<table class="wp-list-table widefat fixed striped">';
                        html += '<thead><tr>';
                        html += '<th style="width: 18%;">Name</th>';
                        html += '<th style="width: 18%;">Email</th>';
                        html += '<th style="width: 10%;">Source</th>';
                        html += '<th style="width: 10%;">Location</th>';
                        html += '<th style="width: 8%;">Stage</th>';
                        html += '<th style="width: 22%;">Suggested Action</th>';
                        html += '<th style="width: 14%;">Date</th>';
                        html += '</tr></thead><tbody>';

                        var stageColors = {
                            'new': '#0073aa',
                            'contacted': '#f0ad4e',
                            'qualified': '#5bc0de',
                            'won': '#46b450',
                            'lost': '#dc3545'
                        };

                        leads.forEach(function(lead) {
                            var detailUrl = '<?php echo esc_url(admin_url('admin.php?page=bitbot-leads')); ?>&lead_id=' + lead.id;
                            var color = stageColors[lead.stage] || '#666';
                            var date = new Date(lead.createdAt).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

                            html += '<tr>';
                            html += '<td><a href="' + detailUrl + '" style="font-weight: 600;">' + escHtml(lead.name) + '</a></td>';
                            html += '<td>' + escHtml(lead.email) + '</td>';
                            html += '<td><span class="bitbot-status" style="background: #f0f0f1; color: #50575e;">' + escHtml(lead.source) + '</span></td>';
                            var location = lead.city && lead.country ? lead.city + ', ' + lead.country : (lead.country || '—');
                            html += '<td style="font-size: 12px; color: #666;">' + escHtml(location) + '</td>';
                            html += '<td><span class="bitbot-status" style="background: ' + color + '; color: #fff;">' + escHtml(lead.stage) + '</span></td>';
                            html += '<td>' + escHtml(lead.suggestedAction || '—') + '</td>';
                            html += '<td>' + date + '</td>';
                            html += '</tr>';
                        });

                        html += '</tbody></table>';
                        $('#bitbot-leads-container').html(html);
                    },
                    error: function() {
                        $('#bitbot-leads-container').html('<p class="error">Failed to load leads.</p>');
                    }
                });
            }

            function escHtml(str) {
                if (!str) return '';
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(str));
                return div.innerHTML;
            }

            loadLeads();
        })(jQuery);
        });
        </script>
        <?php
    }

    /**
     * Render lead detail page
     */
    private function render_lead_detail_page($lead_id) {
        ?>
        <div class="wrap">
            <h1>
                <a href="<?php echo esc_url(admin_url('admin.php?page=bitbot-leads')); ?>" style="text-decoration: none;">&larr; <?php esc_html_e('Back to Leads', 'bitbot'); ?></a>
            </h1>

            <div id="bitbot-lead-detail" style="margin-top: 20px;">
                <div class="spinner is-active" style="float: none;"></div>
                <span><?php esc_html_e('Loading lead details...', 'bitbot'); ?></span>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
        (function($) {
            var config = window.bitbotAdmin || {};
            var leadId = '<?php echo esc_js($lead_id); ?>';

            var stageColors = {
                'new': '#0073aa',
                'contacted': '#f0ad4e',
                'qualified': '#5bc0de',
                'won': '#46b450',
                'lost': '#dc3545'
            };

            function loadLead() {
                $.ajax({
                    url: config.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'bitbot_get_lead',
                        nonce: config.nonce,
                        lead_id: leadId
                    },
                    success: function(response) {
                        if (!response.success) {
                            $('#bitbot-lead-detail').html('<p class="error">' + ((response.data && response.data.error) || 'Failed to load lead') + '</p>');
                            return;
                        }
                        renderLead(response.data);
                    },
                    error: function() {
                        $('#bitbot-lead-detail').html('<p class="error">Failed to load lead details.</p>');
                    }
                });
            }

            function renderLead(data) {
                var lead = data.lead;
                var activities = data.activities || [];
                var emails = data.emails || [];
                var color = stageColors[lead.stage] || '#666';
                var date = new Date(lead.createdAt).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });

                var html = '<div class="bitbot-dashboard" style="grid-template-columns: 1fr 1fr;">';

                // Left column — Lead info + stage
                html += '<div class="bitbot-card">';
                html += '<h2>' + escHtml(lead.name) + '</h2>';
                html += '<p><strong>Email:</strong> <a href="mailto:' + escHtml(lead.email) + '">' + escHtml(lead.email) + '</a></p>';
                if (lead.phone) html += '<p><strong>Phone:</strong> ' + escHtml(lead.phone) + '</p>';
                if (lead.message) html += '<p><strong>Message:</strong> ' + escHtml(lead.message) + '</p>';
                html += '<p><strong>Source:</strong> <span class="bitbot-status" style="background: #f0f0f1; color: #50575e;">' + escHtml(lead.source) + '</span>';
                if (lead.conversationId && data.conversationMessages && data.conversationMessages.length > 0) {
                    html += ' <button id="bitbot-view-conversation" class="button" style="margin-left: 8px; font-size: 11px; padding: 0 8px; min-height: 26px;">View Conversation</button>';
                }
                html += '</p>';
                var location = lead.city && lead.country ? lead.city + ', ' + lead.country : (lead.country || '');
                if (location) html += '<p><strong>Location:</strong> ' + escHtml(location) + '</p>';
                html += '<p><strong>Date:</strong> ' + date + '</p>';

                // Stage dropdown
                html += '<div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;">';
                html += '<label><strong>Stage:</strong></label> ';
                html += '<select id="bitbot-lead-stage" style="margin-left: 8px;">';
                ['new', 'contacted', 'qualified', 'won', 'lost'].forEach(function(s) {
                    var sel = (s === lead.stage) ? ' selected' : '';
                    html += '<option value="' + s + '"' + sel + '>' + s.charAt(0).toUpperCase() + s.slice(1) + '</option>';
                });
                html += '</select>';
                html += '</div>';

                // Action buttons
                html += '<div style="margin-top: 15px; display: flex; gap: 8px;">';
                html += '<button id="bitbot-run-research" class="button button-primary"' + (lead.researchSummary ? ' style="display:none;"' : '') + '>Run AI Research</button>';
                html += '<button id="bitbot-delete-lead" class="button" style="color: #a00;">Delete Lead</button>';
                html += '</div>';

                html += '</div>';

                // Right column — Research summary + Draft Email
                html += '<div class="bitbot-card">';
                html += '<h2 style="display: flex; justify-content: space-between; align-items: center;">AI Research';
                if (lead.researchSummary) {
                    html += '<button id="bitbot-draft-email" class="button" style="color: #7c3aed; border-color: #7c3aed; font-size: 12px;">Draft Email</button>';
                }
                html += '</h2>';
                html += '<div id="bitbot-research-content">';
                if (lead.researchSummary) {
                    html += '<p>' + escHtml(lead.researchSummary) + '</p>';
                    if (lead.suggestedAction) {
                        html += '<div style="margin-top: 15px; padding: 10px; background: #f0f6fc; border-left: 4px solid #0073aa; border-radius: 2px;">';
                        html += '<strong>Next Step:</strong> ' + escHtml(lead.suggestedAction);
                        html += '</div>';
                    }
                } else {
                    html += '<p style="color: #666;">No research yet. Click "Run AI Research" to analyze this lead.</p>';
                }
                html += '</div>';
                html += '</div>';

                // Full width — Activity timeline + Add note
                html += '<div class="bitbot-card bitbot-card-full">';
                html += '<h2>Activity</h2>';

                // Add note form
                html += '<div style="margin-bottom: 20px; padding: 15px; background: #f9f9f9; border-radius: 4px;">';
                html += '<textarea id="bitbot-note-content" rows="2" style="width: 100%; margin-bottom: 8px;" placeholder="Add a note..."></textarea>';
                html += '<button id="bitbot-add-note" class="button button-secondary">Add Note</button>';
                html += '</div>';

                if (activities.length > 0) {
                    html += '<div style="max-height: 300px; overflow-y: auto;">';
                    activities.forEach(function(activity) {
                        var actDate = new Date(activity.createdAt).toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
                        var icon = '📝';
                        if (activity.type === 'email_sent') icon = '✉️';
                        if (activity.type === 'stage_change') icon = '🔄';
                        if (activity.type === 'auto_research') icon = '🔍';

                        html += '<div style="padding: 10px 0; border-bottom: 1px solid #eee;">';
                        html += '<span>' + icon + '</span> ';
                        html += '<span style="color: #666; font-size: 12px;">' + actDate + '</span>';
                        html += '<span style="margin-left: 5px; text-transform: capitalize; font-size: 12px; color: #999;">' + escHtml(activity.type.replace(/_/g, ' ')) + '</span>';
                        if (activity.content) html += '<p style="margin: 4px 0 0 24px;">' + escHtml(activity.content) + '</p>';
                        html += '</div>';
                    });
                    html += '</div>';
                } else {
                    html += '<p style="color: #666;">No activity yet.</p>';
                }

                html += '</div>';

                // Email draft modal (hidden, shown on button click)
                html += '<div id="bitbot-email-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.6); z-index:100000; display:none; align-items:center; justify-content:center;">';
                html += '<div style="background:#fff; border-radius:8px; width:90%; max-width:650px; max-height:85vh; overflow:hidden; display:flex; flex-direction:column;">';
                html += '<div style="padding:16px 20px; border-bottom:1px solid #ddd; display:flex; justify-content:space-between; align-items:center;">';
                html += '<h3 style="margin:0;">Draft Email</h3>';
                html += '<button id="bitbot-email-close" style="background:none; border:none; font-size:24px; cursor:pointer; color:#666;">&times;</button>';
                html += '</div>';
                html += '<div id="bitbot-email-body" style="padding:20px; overflow-y:auto; flex:1;">';
                html += '<div style="text-align:center; padding:30px;"><div class="spinner is-active" style="float:none;"></div><p>Generating personalized email...</p></div>';
                html += '</div>';
                html += '<div style="padding:12px 20px; border-top:1px solid #ddd; display:flex; justify-content:flex-end; gap:8px;">';
                html += '<button id="bitbot-email-copy" class="button button-primary" style="display:none;">Copy to Clipboard</button>';
                html += '</div>';
                html += '</div></div>';

                // Conversation modal (for chat-sourced leads)
                if (data.conversationMessages && data.conversationMessages.length > 0) {
                    html += '<div id="bitbot-convo-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.6); z-index:100000; align-items:center; justify-content:center;">';
                    html += '<div style="background:#fff; border-radius:8px; width:90%; max-width:500px; max-height:85vh; overflow:hidden; display:flex; flex-direction:column;">';
                    html += '<div style="padding:16px 20px; border-bottom:1px solid #ddd; display:flex; justify-content:space-between; align-items:center;">';
                    html += '<h3 style="margin:0;">Chat Conversation</h3>';
                    html += '<button id="bitbot-convo-close" style="background:none; border:none; font-size:24px; cursor:pointer; color:#666;">&times;</button>';
                    html += '</div>';
                    html += '<div style="padding:20px; overflow-y:auto; flex:1;">';
                    data.conversationMessages.forEach(function(msg) {
                        var isUser = msg.role === 'user';
                        var align = isUser ? 'flex-end' : 'flex-start';
                        var bg = isUser ? '#0073aa' : '#f0f0f1';
                        var color = isUser ? '#fff' : '#333';
                        var label = isUser ? 'Visitor' : 'Bot';
                        var time = new Date(msg.createdAt).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
                        html += '<div style="display:flex; flex-direction:column; align-items:' + align + '; margin-bottom:12px;">';
                        html += '<div style="font-size:11px; color:#999; margin-bottom:2px;">' + label + ' &middot; ' + time + '</div>';
                        html += '<div style="background:' + bg + '; color:' + color + '; padding:8px 12px; border-radius:12px; max-width:80%; font-size:13px; line-height:1.4;">' + escHtml(msg.content) + '</div>';
                        html += '</div>';
                    });
                    html += '</div></div></div>';
                }

                html += '</div>'; // Close dashboard grid

                $('#bitbot-lead-detail').html(html);

                // Bind events
                $('#bitbot-lead-stage').on('change', function() {
                    var newStage = $(this).val();
                    $.ajax({
                        url: config.ajaxUrl,
                        method: 'POST',
                        data: { action: 'bitbot_update_lead_stage', nonce: config.nonce, lead_id: leadId, stage: newStage },
                        success: function(r) {
                            if (r.success) loadLead();
                        }
                    });
                });

                $('#bitbot-add-note').on('click', function() {
                    var content = $('#bitbot-note-content').val().trim();
                    if (!content) return;
                    $.ajax({
                        url: config.ajaxUrl,
                        method: 'POST',
                        data: { action: 'bitbot_add_lead_note', nonce: config.nonce, lead_id: leadId, content: content },
                        success: function(r) {
                            if (r.success) {
                                $('#bitbot-note-content').val('');
                                loadLead();
                            }
                        }
                    });
                });

                // Run Research button
                $('#bitbot-run-research').on('click', function() {
                    var $btn = $(this);
                    $btn.prop('disabled', true).text('Researching...');
                    $('#bitbot-research-content').html('<div style="text-align:center;padding:20px;"><div class="spinner is-active" style="float:none;"></div><p>Scraping website, searching the web, analyzing with AI...</p></div>');
                    $.ajax({
                        url: config.ajaxUrl,
                        method: 'POST',
                        data: { action: 'bitbot_run_lead_research', nonce: config.nonce, lead_id: leadId },
                        timeout: 30000,
                        success: function(r) {
                            if (r.success) {
                                loadLead(); // Reload the whole page to show updated data
                            } else {
                                $btn.prop('disabled', false).text('Run AI Research');
                                $('#bitbot-research-content').html('<p class="error">Research failed: ' + escHtml((r.data && r.data.error) || 'Unknown error') + '</p>');
                            }
                        },
                        error: function() {
                            $btn.prop('disabled', false).text('Run AI Research');
                            $('#bitbot-research-content').html('<p class="error">Research timed out. Please try again.</p>');
                        }
                    });
                });

                $('#bitbot-delete-lead').on('click', function() {
                    if (!confirm('Are you sure you want to delete this lead?')) return;
                    $.ajax({
                        url: config.ajaxUrl,
                        method: 'POST',
                        data: { action: 'bitbot_delete_lead', nonce: config.nonce, lead_id: leadId },
                        success: function(r) {
                            if (r.success) {
                                window.location.href = '<?php echo esc_url(admin_url('admin.php?page=bitbot-leads')); ?>';
                            }
                        }
                    });
                });

                // Draft Email button
                $('#bitbot-draft-email').on('click', function() {
                    var $modal = $('#bitbot-email-modal');
                    $modal.css('display', 'flex');
                    $('#bitbot-email-copy').hide();
                    $('#bitbot-email-body').html('<div style="text-align:center;padding:30px;"><div class="spinner is-active" style="float:none;"></div><p>Generating personalized email...</p></div>');

                    $.ajax({
                        url: config.ajaxUrl,
                        method: 'POST',
                        data: { action: 'bitbot_draft_lead_email', nonce: config.nonce, lead_id: leadId },
                        success: function(r) {
                            if (r.success) {
                                var h = '<div style="margin-bottom:16px;">';
                                h += '<label style="font-weight:600;display:block;margin-bottom:4px;">Subject</label>';
                                h += '<input type="text" id="bitbot-email-subject" class="large-text" value="' + escAttr(r.data.subject) + '" style="font-size:14px;">';
                                h += '</div>';
                                h += '<div>';
                                h += '<label style="font-weight:600;display:block;margin-bottom:4px;">Body</label>';
                                h += '<textarea id="bitbot-email-text" rows="12" class="large-text" style="font-size:14px;line-height:1.6;">' + escHtml(r.data.body) + '</textarea>';
                                h += '</div>';
                                $('#bitbot-email-body').html(h);
                                $('#bitbot-email-copy').show();
                            } else {
                                $('#bitbot-email-body').html('<p class="error">Failed to generate email: ' + escHtml((r.data && r.data.error) || 'Unknown error') + '</p>');
                            }
                        },
                        error: function() {
                            $('#bitbot-email-body').html('<p class="error">Failed to generate email draft.</p>');
                        }
                    });
                });

                // Close modal
                $('#bitbot-email-close').on('click', function() {
                    $('#bitbot-email-modal').css('display', 'none');
                });
                $('#bitbot-email-modal').on('click', function(e) {
                    if (e.target === this) $(this).css('display', 'none');
                });

                // Copy email to clipboard
                $('#bitbot-email-copy').on('click', function() {
                    var subject = $('#bitbot-email-subject').val();
                    var body = $('#bitbot-email-text').val();
                    var text = 'Subject: ' + subject + '\n\n' + body;
                    if (navigator.clipboard) {
                        navigator.clipboard.writeText(text);
                        $(this).text('Copied!');
                        var $b = $(this);
                        setTimeout(function() { $b.text('Copy to Clipboard'); }, 1500);
                    }
                });

                // View Conversation modal
                $('#bitbot-view-conversation').on('click', function() {
                    $('#bitbot-convo-modal').css('display', 'flex');
                });
                $('#bitbot-convo-close').on('click', function() {
                    $('#bitbot-convo-modal').css('display', 'none');
                });
                $('#bitbot-convo-modal').on('click', function(e) {
                    if (e.target === this) $(this).css('display', 'none');
                });
            }

            function escAttr(s) {
                if (!s) return '';
                return s.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            }

            function escHtml(str) {
                if (!str) return '';
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(str));
                return div.innerHTML;
            }

            loadLead();
        })(jQuery);
        });
        </script>
        <?php
    }

    // ============================================
    // Leads AJAX Handlers
    // ============================================

    public function ajax_get_leads() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $stage = isset($_POST['stage']) ? sanitize_text_field($_POST['stage']) : '';
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $result = $this->api_client->get_leads($stage, $search);

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    public function ajax_get_lead() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $lead_id = isset($_POST['lead_id']) ? sanitize_text_field($_POST['lead_id']) : '';
        if (empty($lead_id)) {
            wp_send_json_error(array('error' => 'Lead ID required'));
        }

        $result = $this->api_client->get_lead($lead_id);
        if ($result['success']) {
            // Opening a lead marks it viewed upstream — refresh the badge count.
            $this->bust_new_leads_count_cache();
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    public function ajax_update_lead_stage() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $lead_id = sanitize_text_field($_POST['lead_id'] ?? '');
        $stage = sanitize_text_field($_POST['stage'] ?? '');
        if (empty($lead_id) || empty($stage)) {
            wp_send_json_error(array('error' => 'Lead ID and stage required'));
        }

        $result = $this->api_client->update_lead_stage($lead_id, $stage);
        if ($result['success']) {
            $this->bust_new_leads_count_cache();
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    public function ajax_add_lead_note() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $lead_id = sanitize_text_field($_POST['lead_id'] ?? '');
        $content = sanitize_textarea_field($_POST['content'] ?? '');
        if (empty($lead_id) || empty($content)) {
            wp_send_json_error(array('error' => 'Lead ID and content required'));
        }

        $result = $this->api_client->add_lead_note($lead_id, $content);
        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    public function ajax_delete_lead() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $lead_id = sanitize_text_field($_POST['lead_id'] ?? '');
        if (empty($lead_id)) {
            wp_send_json_error(array('error' => 'Lead ID required'));
        }

        $result = $this->api_client->delete_lead($lead_id);
        if ($result['success']) {
            $this->bust_new_leads_count_cache();
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    // ============================================
    // Form Config AJAX Handlers
    // ============================================

    public function ajax_get_forms() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(array('error' => 'Permission denied')); }
        $result = $this->api_client->get_forms();
        $result['success'] ? wp_send_json_success($result['data']) : wp_send_json_error(array('error' => $result['error']));
    }

    public function ajax_get_form() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(array('error' => 'Permission denied')); }
        $form_id = isset($_POST['form_id']) ? sanitize_text_field($_POST['form_id']) : '';
        $result = $this->api_client->get_form($form_id);
        $result['success'] ? wp_send_json_success($result['data']) : wp_send_json_error(array('error' => $result['error']));
    }

    public function ajax_create_form() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(array('error' => 'Permission denied')); }
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $description = isset($_POST['description']) ? sanitize_text_field($_POST['description']) : '';
        $fields = isset($_POST['fields']) ? json_decode(stripslashes($_POST['fields']), true) : array();
        $result = $this->api_client->create_form($name, $description, $fields);
        $result['success'] ? wp_send_json_success($result['data']) : wp_send_json_error(array('error' => $result['error']));
    }

    public function ajax_update_form() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(array('error' => 'Permission denied')); }
        $form_id = isset($_POST['form_id']) ? sanitize_text_field($_POST['form_id']) : '';
        $data = array();
        if (isset($_POST['name'])) $data['name'] = sanitize_text_field($_POST['name']);
        if (isset($_POST['fields'])) $data['fields'] = json_decode(stripslashes($_POST['fields']), true);
        if (isset($_POST['enabled'])) $data['enabled'] = rest_sanitize_boolean($_POST['enabled']);
        $result = $this->api_client->update_form($form_id, $data);
        $result['success'] ? wp_send_json_success($result['data']) : wp_send_json_error(array('error' => $result['error']));
    }

    public function ajax_delete_form() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(array('error' => 'Permission denied')); }
        $form_id = isset($_POST['form_id']) ? sanitize_text_field($_POST['form_id']) : '';
        $result = $this->api_client->delete_form($form_id);
        $result['success'] ? wp_send_json_success($result['data']) : wp_send_json_error(array('error' => $result['error']));
    }

    public function ajax_generate_form() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(array('error' => 'Permission denied')); }
        $description = isset($_POST['description']) ? sanitize_text_field($_POST['description']) : '';
        $result = $this->api_client->generate_form($description);
        $result['success'] ? wp_send_json_success($result['data']) : wp_send_json_error(array('error' => $result['error']));
    }
}
