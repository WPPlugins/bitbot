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
        // Run on EVERY admin request, as early as possible. WP's permission
        // check for unregistered admin pages happens in admin-header.php
        // which is required AFTER admin_init fires — but to be safe we
        // hook on both `init` (front-of-queue) and `admin_init` priority 1
        // so the redirect always fires before any wp_die() landmines.
        add_action('init',       array($this, 'maybe_redirect_legacy_admin_url'), 1);
        add_action('admin_init', array($this, 'maybe_redirect_legacy_admin_url'), 1);

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));

        // Redirect Sync Now menu to dashboard before headers are sent
        add_action('admin_init', array($this, 'handle_sync_redirect'));

        // Round-trip WP post-status changes back to BitBot whenever a
        // post that originated from a BitBot draft is saved/updated.
        // Fires on every wp_insert_post + every Gutenberg save, so the
        // dashboard stays in sync without polling.
        add_action('save_post', array($this, 'sync_bitbot_post_state'), 20, 3);

        // Companion hook for permanent deletes (which don't fire save_post).
        // Reads the meta off the post BEFORE it's gone, then tells the
        // backend to drop the wp_post_id association on the matching draft.
        add_action('before_delete_post', array($this, 'unlink_bitbot_post_on_delete'), 20, 1);

        // Register AJAX handlers
        add_action('wp_ajax_bitbot_sync', array($this, 'ajax_sync'));
        add_action('wp_ajax_bitbot_sync_progress', array($this, 'ajax_sync_progress'));
        add_action('wp_ajax_bitbot_sync_stop', array($this, 'ajax_sync_stop'));
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

        // Live chat handlers (Pro+ live-handover)
        add_action( 'wp_ajax_bitbot_heartbeat',              array( $this, 'ajax_heartbeat' ) );
        add_action( 'wp_ajax_bitbot_live_chats_thread',      array( $this, 'ajax_live_chats_thread' ) );

        // 2.0.12: public widget render heartbeat. Public-facing, no
        // nonce — widget.js fires once per visitor session when it
        // successfully boots in the shadow DOM. Stamps a wp_option the
        // Overview banner reads to detect themes that don't call
        // wp_footer() (enqueue_at was set server-side but visitors
        // never executed our JS).
        add_action( 'wp_ajax_nopriv_bitbot_widget_seen', array( $this, 'ajax_widget_seen' ) );
        add_action( 'wp_ajax_bitbot_widget_seen',        array( $this, 'ajax_widget_seen' ) );

        // 2.0.12: rate-us dismissible admin notice. The notice itself
        // is rendered via admin_notices in bitbot.php; this handler
        // persists the three possible verdicts (rated / remind later /
        // dismiss forever) so we don't pester admins who already acted.
        add_action( 'wp_ajax_bitbot_rate_us_action', array( $this, 'ajax_rate_us_action' ) );
        add_action( 'admin_post_bitbot_live_chats_reply',    array( $this, 'handle_live_chats_reply' ) );
        add_action( 'admin_post_bitbot_live_chats_close',    array( $this, 'handle_live_chats_close' ) );
        add_action( 'admin_post_bitbot_live_chats_takeover', array( $this, 'handle_live_chats_takeover' ) );

        // Form AJAX handlers
        add_action('wp_ajax_bitbot_get_forms', array($this, 'ajax_get_forms'));
        add_action('wp_ajax_bitbot_get_form', array($this, 'ajax_get_form'));
        add_action('wp_ajax_bitbot_create_form', array($this, 'ajax_create_form'));
        add_action('wp_ajax_bitbot_update_form', array($this, 'ajax_update_form'));
        add_action('wp_ajax_bitbot_delete_form', array($this, 'ajax_delete_form'));
        add_action('wp_ajax_bitbot_generate_form', array($this, 'ajax_generate_form'));
        add_action('wp_ajax_bitbot_set_chat_gate', array($this, 'ajax_set_chat_gate'));

        // 2.0.15: kill-switch for the public widget. Surfaces in the
        // Chatbot tab so admins can hide the bot during a relaunch /
        // maintenance window without uninstalling the plugin. Banner
        // on Overview ("Chat is turned off…") points here when off.
        add_action('wp_ajax_bitbot_set_widget_enabled', array($this, 'ajax_set_widget_enabled'));

        // Reconnect handler
        add_action('wp_ajax_bitbot_reconnect', array($this, 'ajax_reconnect'));
    }

    /**
     * Cache key for the "new leads" count transient.
     *
     * @var string
     */
    const NEW_LEADS_COUNT_TRANSIENT = 'bitbot_new_leads_count';
    const LIVE_CHATS_COUNT_TRANSIENT = 'bitbot_live_chats_count';
    const UPCOMING_APTS_COUNT_TRANSIENT = 'bitbot_upcoming_apts_count';
    // Single cached total for the top-level menu badge (leads + forms +
    // conversations + appointments). One round-trip instead of three.
    const MENU_BADGES_TRANSIENT = 'bitbot_menu_badges';

    /**
     * Get the site's plan status (plan + usage + limits).
     *
     * Caching strategy:
     *   1. Persistent option (written by store_cached_status) — survives
     *      transient expiry, plugin reinstall-with-deactivate, and is the
     *      authoritative read path for the widget.
     *   2. Short-lived throttle transient — while live, we don't re-hit
     *      the API and just return the cached option.
     *
     * If we have no cache AND the API call fails, we return a conservative
     * "free" fallback but do NOT persist it — so the next refresh can
     * still populate the real plan.
     *
     * @since 1.6.0
     * @return array { plan: string, limits: array, usage: array }
     */
    private function get_plan_status() {
        $cached = BitBot_Plan::get_cached_status();

        if ( $cached !== null && BitBot_Plan::is_refresh_throttled() ) {
            return $cached;
        }

        $result = $this->api_client->get_plan_status();
        if ( empty( $result['success'] ) || empty( $result['data'] ) ) {
            // API unreachable. Prefer the last-known good cache over the
            // generic fallback so a paying customer never sees "Free plan"
            // during a transient upstream outage.
            if ( $cached !== null ) {
                return $cached;
            }
            return array(
                'plan'   => BitBot_Plan::FREE,
                'limits' => array(
                    'chatMessagesPerMonth' => 100,
                    'postsPerMonth'        => 3,
                    'postCooldownHours'    => 24,
                    'maxLeads'             => 10,
                    'maxForms'             => 1,
                ),
                'usage'  => array(
                    'chatMessagesThisMonth' => 0,
                    'postsThisMonth'        => 0,
                    'leadsCount'            => 0,
                    'formsCount'            => 0,
                    'lastPostGeneratedAt'   => null,
                ),
            );
        }

        BitBot_Plan::store_cached_status( $result['data'] );
        return $result['data'];
    }

    /**
     * Convenience: true if site is on a paid plan (pro or pro_plus).
     *
     * 1.7.0: pro_plus is paid too — treat both the same here so branding +
     * paywalled UI surfaces (upgrade cards, quota bars, etc.) flip to the
     * paid variant.
     *
     * @return bool
     */
    public function is_pro() {
        $status = $this->get_plan_status();
        return BitBot_Plan::is_paid( $status['plan'] ?? null );
    }

    /**
     * Pro+ specifically (not Pro). Used to gate Settings sections that are
     * Pro+-only (Appointments, Cart Recovery, WooCommerce Integration).
     *
     * @return bool
     */
    public function is_pro_plus() {
        $status = $this->get_plan_status();
        return BitBot_Plan::is_pro_plus( $status['plan'] ?? null );
    }

    /**
     * Get the current plan string ('free' / 'pro' / 'pro_plus'). Used by
     * Settings UI to gate field caps + previews. Defaults to 'free' when
     * the plan-status cache isn't populated yet.
     *
     * @since 1.9.0
     * @return string
     */
    public function get_current_plan() {
        $status = $this->get_plan_status();
        $plan   = $status['plan'] ?? 'free';
        if ( ! in_array( $plan, array( 'free', 'pro', 'pro_plus' ), true ) ) {
            return 'free';
        }
        return $plan;
    }

    /**
     * Render the standard yellow "Upgrade to Agent" card used inside settings
     * sections that the current plan can't unlock yet. Keep one renderer so
     * the visual treatment stays consistent across Appointments, Cart
     * Recovery and WooCommerce Integration on Free / Assistant.
     *
     * (Method name keeps `proplus` for code-stability — renaming would touch
     * 3+ call sites for no functional gain. Customer-facing strings have
     * already been switched to Agent.)
     *
     * @param string $headline Short feature name.
     * @param string $blurb    One-sentence value prop.
     * @return void
     */
    private function render_proplus_gate_card( $headline, $blurb ) {
        ?>
        <div style="padding: 12px 14px; background: #fff8e5; border: 1px solid #f0c33c; border-radius: 6px; margin-bottom: 10px;">
            <p style="margin: 0 0 6px; font-weight: 600; color: #3c2f00;">
                <?php echo esc_html( $headline ); ?>
            </p>
            <p style="margin: 0; color: #5c4a00; font-size: 13px;">
                <?php echo esc_html( $blurb ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Bust plan-status cache (e.g. after a successful Stripe checkout
     * or a plan downgrade so we don't keep showing stale gated UI).
     */
    private function bust_plan_status_cache() {
        BitBot_Plan::bust_cache();
    }

    /**
     * Render the plan banner at the top of BitBot admin pages.
     * Shows plan name + current usage vs limits + upgrade CTA for Free users.
     *
     * @since 1.6.0
     */
    private function render_plan_banner() {
        $status = $this->get_plan_status();
        $plan   = $status['plan'] ?? BitBot_Plan::FREE;
        $u      = $status['usage'] ?? array();
        $l      = $status['limits'] ?? array();

        if ( BitBot_Plan::is_paid( $plan ) ) {
            /* translators: %s: plan label e.g. "Pro" or "Pro+" */
            $label = sprintf( __( '%s plan', 'bitbot' ), BitBot_Plan::label( $plan ) );
            ?>
            <div class="notice" style="border-left-color: #22863a; padding: 10px 14px;">
                <p style="margin: 0; font-size: 14px;">
                    <strong style="color: #22863a;"><?php echo esc_html( $label ); ?></strong>
                    — <?php esc_html_e( 'all features unlocked. Thanks for supporting BitBot!', 'bitbot' ); ?>
                </p>
            </div>
            <?php
            return;
        }

        $chat_cap  = absint( $l['chatMessagesPerMonth'] ?? 100 );
        $chat_used = absint( $u['chatMessagesThisMonth'] ?? 0 );
        $post_cap  = absint( $l['postsPerMonth'] ?? 3 );
        $post_used = absint( $u['postsThisMonth'] ?? 0 );
        $leads_cap = absint( $l['maxLeads'] ?? 10 );
        $leads_used = absint( $u['leadsCount'] ?? 0 );

        $upgrade_url = esc_url( admin_url( 'admin.php?page=bitbot-upgrade' ) );
        ?>
        <div class="notice" style="border-left-color: #0073aa; padding: 12px 16px; background: #fff;">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap;">
                <div>
                    <strong><?php esc_html_e( 'Free plan', 'bitbot' ); ?></strong>
                    — <?php
                    printf(
                        /* translators: 1: chat used, 2: chat cap, 3: posts used, 4: posts cap, 5: leads used, 6: leads cap */
                        esc_html__( '%1$d / %2$d chat messages · %3$d / %4$d posts · %5$d / %6$d leads this month.', 'bitbot' ),
                        $chat_used, $chat_cap, $post_used, $post_cap, $leads_used, $leads_cap
                    );
                    ?>
                </div>
                <div>
                    <a href="<?php echo $upgrade_url; ?>" class="button button-primary">
                        <?php esc_html_e( 'Upgrade plan', 'bitbot' ); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

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
     * Total attention count for the top-level menu badge — one aggregate
     * backend call (leads + forms + conversations + appointments), cached
     * 60s in a single transient. This is the only backend call the menu
     * makes per render, and only on a cold cache. Returns 0 on any failure
     * so the menu always renders.
     *
     * @return int
     */
    private function get_menu_badge_total() {
        $cached = get_transient( self::MENU_BADGES_TRANSIENT );
        if ( $cached !== false ) {
            return (int) $cached;
        }

        $result = $this->api_client->get_menu_badge_counts();
        $total  = 0;
        if ( ! empty( $result['success'] ) && isset( $result['data']['total'] ) ) {
            $total = (int) $result['data']['total'];
        }

        set_transient( self::MENU_BADGES_TRANSIENT, $total, MINUTE_IN_SECONDS );
        return $total;
    }

    /**
     * Count of live chat threads currently in live_mode. Cached 30s — short
     * enough that admin closing a thread or a new handover starting shows up
     * on the next page load. Failure → 0 so the menu still renders cleanly.
     *
     * @since 1.7.0
     * @return int
     */
    private function get_live_chats_count() {
        $cached = get_transient( self::LIVE_CHATS_COUNT_TRANSIENT );
        if ( $cached !== false ) {
            return (int) $cached;
        }

        $result = $this->api_client->get_live_badge_count();
        $count  = 0;
        if ( ! empty( $result['success'] ) && isset( $result['data']['count'] ) ) {
            $count = (int) $result['data']['count'];
        }

        set_transient( self::LIVE_CHATS_COUNT_TRANSIENT, $count, 30 );
        return $count;
    }

    /**
     * Count of future-dated, non-cancelled appointments. Cached 60s.
     * Drives the Calendar menu-bubble + folds into the top-level
     * BitBot attention badge so owners notice pending bookings even
     * when they're parked on Posts / Pages / etc. Returns 0 on
     * transient API failures so the menu still renders.
     *
     * @since 1.7.0
     * @return int
     */
    private function get_upcoming_appointments_count() {
        $cached = get_transient( self::UPCOMING_APTS_COUNT_TRANSIENT );
        if ( $cached !== false ) {
            return (int) $cached;
        }

        $result = $this->api_client->get_upcoming_appointments_count();
        $count  = 0;
        if ( ! empty( $result['success'] ) && isset( $result['data']['count'] ) ) {
            $count = (int) $result['data']['count'];
        }

        set_transient( self::UPCOMING_APTS_COUNT_TRANSIENT, $count, MINUTE_IN_SECONDS );
        return $count;
    }

    /**
     * Add admin menu — Feature 9 hard cutover (v2.0.0).
     *
     * Pre-2.0 we had a 9-item submenu: Dashboard · Sync Now · Leads ·
     * Form Builder · Live Chats · Calendar · Content Generator · Settings
     * · Upgrade. Each was a separate PHP page renderer.
     *
     * 2.0 collapses all of that into a single "BitBot" entry that loads
     * the React SPA. Tabs live inside the SPA, navigation is URL-driven
     * (?tab=leads etc), and the old per-page PHP renderers are deleted.
     *
     * Legacy URLs (?page=bitbot-leads etc) are redirected to the new
     * tabbed URL via maybe_redirect_legacy_admin_url() below so any
     * external link, support doc, or screenshot still lands somewhere
     * sensible.
     */
    public function add_admin_menu() {
        // Combined attention badge on the top-level menu item — sum of
        // un-viewed leads + form responses + conversations + upcoming
        // appointments, fetched in ONE aggregate round-trip (cached 60s) so
        // unrelated wp-admin pages don't pay three serial backend calls.
        $attention     = $this->get_menu_badge_total();
        $top_bubble    = $attention > 0
            ? ' <span class="awaiting-mod update-plugins count-' . absint( $attention ) . '"><span class="pending-count">' . absint( $attention ) . '</span></span>'
            : '';
        $bitbot_label  = __( 'BitBot', 'bitbot' ) . $top_bubble;

        add_menu_page(
            __( 'BitBot', 'bitbot' ),
            $bitbot_label,
            'manage_options',
            'bitbot',
            array( $this, 'render_dashboard_page' ),
            'dashicons-format-chat',
            30
        );
    }

    /**
     * Redirect legacy admin URLs to the new tabbed React SPA URLs.
     * Hooked on admin_init so it runs before any menu callback.
     *
     * Mapping:
     *   bitbot-leads      → bitbot&tab=leads
     *   bitbot-forms      → bitbot&tab=forms
     *   bitbot-live-chats → bitbot&tab=conversations
     *   bitbot-calendar   → bitbot&tab=calendar
     *   bitbot-content    → bitbot&tab=content
     *   bitbot-settings   → bitbot&tab=chatbot
     *   bitbot-upgrade    → bitbot (opens account drawer on first paint)
     *   bitbot-sync       → bitbot&auto_sync=1
     */
    public function maybe_redirect_legacy_admin_url() {
        if ( headers_sent() ) return;
        if ( ! isset( $_GET['page'] ) ) return;
        $page = sanitize_key( $_GET['page'] );
        $map = array(
            'bitbot-leads'      => 'admin.php?page=bitbot&tab=leads',
            'bitbot-forms'      => 'admin.php?page=bitbot&tab=forms',
            'bitbot-live-chats' => 'admin.php?page=bitbot&tab=conversations',
            'bitbot-calendar'   => 'admin.php?page=bitbot&tab=calendar',
            'bitbot-content'    => 'admin.php?page=bitbot&tab=content',
            'bitbot-settings'   => 'admin.php?page=bitbot&tab=chatbot',
            'bitbot-upgrade'    => 'admin.php?page=bitbot',
        );
        if ( ! isset( $map[ $page ] ) ) return;
        // Use plain wp_redirect — the destination is built via admin_url()
        // so the host is always this site; wp_safe_redirect's allowlist
        // check is unnecessary and has caused silent fall-throughs in
        // edge cases (multisite, redirect filters).
        wp_redirect( admin_url( $map[ $page ] ) );
        exit;
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
        // Widget header title. Hardcoded as "Chat with us" pre-1.9 — customers
        // who wanted their own branding had to inject client-side JS to
        // overwrite `.bitbot-title` (Mais Ação canary, 2026-05-14). This
        // surfaces the same knob as a first-class admin field.
        register_setting('bitbot_settings', 'bitbot_widget_title', array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_widget_title'),
            'default'           => '',
        ));
        register_setting('bitbot_settings', 'bitbot_widget_position', array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_widget_position'),
            'default'           => 'bottom-right',
        ));
        // Pixel offsets from the chosen corner. Owner can dial them in
        // when there's a fixed-position third-party widget they don't
        // want to overlap; the auto-flip JS also bumps the vertical
        // offset at runtime when both corners are taken (stack on top
        // instead of overlap). Clamped to a sane range so a typo can't
        // hide the widget off-screen.
        register_setting('bitbot_settings', 'bitbot_widget_offset_x', array(
            'type'              => 'integer',
            'sanitize_callback' => array($this, 'sanitize_widget_offset'),
            'default'           => 20,
        ));
        register_setting('bitbot_settings', 'bitbot_widget_offset_y', array(
            'type'              => 'integer',
            'sanitize_callback' => array($this, 'sanitize_widget_offset'),
            'default'           => 20,
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

        // 1.9.0: custom copy bundle — four free-text fields that let the
        // admin replace generic visitor-facing copy with their own voice.
        // Empty string for any of these falls back to the localized default
        // at render time, so legacy installs see no change.
        register_setting('bitbot_settings', 'bitbot_placeholder_text', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_short_copy' ),
            'default'           => '',
        ));
        register_setting('bitbot_settings', 'bitbot_fallback_message', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default'           => '',
        ));
        register_setting('bitbot_settings', 'bitbot_lead_capture_prompt', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default'           => '',
        ));
        register_setting('bitbot_settings', 'bitbot_typing_indicator_text', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_short_copy' ),
            'default'           => '',
        ));

        // 1.9.0: hide-on-pages — newline-separated glob patterns. Empty
        // string means "show on every page" (default behaviour). See
        // BitBot::is_current_url_hidden() for matching semantics.
        register_setting('bitbot_settings', 'bitbot_hide_on_pages', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_hide_on_pages' ),
            'default'           => '',
        ));

        // 1.9.0: mobile behaviour override. Three modes:
        //   'inherit' — use desktop position config (default)
        //   'hide'    — never show widget below the mobile breakpoint
        //   'bottom-right' / 'bottom-left' — force the opposite corner on mobile
        register_setting('bitbot_settings', 'bitbot_mobile_behaviour', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_mobile_behaviour' ),
            'default'           => 'inherit',
        ));

        // 1.9.0: bubble corner radius (Free, 0-24px slider) + shape preset
        // (Pro and above; for now a single string value, Free is locked to
        // 'rounded' which respects the radius slider). Two settings so the
        // free path doesn't accidentally inherit a Pro shape selection.
        register_setting('bitbot_settings', 'bitbot_corner_radius', array(
            'type'              => 'integer',
            'sanitize_callback' => array( $this, 'sanitize_corner_radius' ),
            'default'           => 16,
        ));
        register_setting('bitbot_settings', 'bitbot_widget_shape', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_widget_shape' ),
            'default'           => 'rounded',
        ));

        // 1.9.0 Feature 8b: custom system prompt. Sanitize callback also
        // pushes the value to the backend via UpdateInstructions RPC so
        // the chat agent picks it up on the next message.
        register_setting('bitbot_settings', 'bitbot_custom_instructions', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_custom_instructions' ),
            'default'           => '',
        ));

        // 1.9.0 Feature 8l: off-topic guardrails. Structured refusal rules
        // stored as newline-delimited topics. Pushed to backend on save and
        // injected into the agent system prompt as hard "REFUSE these"
        // directives. Pro / Pro+ only — Free customers get the field but
        // it's read-only-disabled at render time.
        register_setting('bitbot_settings', 'bitbot_off_topic_guardrails', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_off_topic_guardrails' ),
            'default'           => '',
        ));

        // 1.9.0 Feature 8d: custom avatar. Single URL field — admins paste
        // a URL or use the WP media library "Select Image" button (handled
        // client-side via wp.media). Static raster on Free; animated SVG /
        // Lottie unlocked on Pro / Pro+ via stricter MIME validation at
        // sanitize time.
        register_setting('bitbot_settings', 'bitbot_avatar_url', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_avatar_url' ),
            'default'           => '',
        ));
        // 2.0.2: avatar crop transform — JSON `{ xPercent, yPercent, zoom }`.
        // Drives the widget's pan/zoom of the avatar image inside its
        // circular container, so the admin can frame the part of the
        // image the visitor sees.
        register_setting('bitbot_settings', 'bitbot_avatar_transform', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_avatar_transform' ),
            'default'           => '',
        ));

        // 1.9.0 Feature 8e: suggested questions / quick-reply chips.
        // Stored as a JSON string in a single option (simpler than a
        // repeater of registered settings). Free 3 chips / Pro unlimited.
        register_setting('bitbot_settings', 'bitbot_suggested_questions', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_suggested_questions' ),
            'default'           => '',
        ));

        // 1.9.0 Feature 8g: auto-open trigger. JSON-encoded config — the
        // admin picks ANY/ALL of: URL patterns, time-on-page seconds,
        // scroll percent, exit-intent. Pro / Pro+ only — Free saves are
        // silently zeroed by sanitize_auto_open.
        register_setting('bitbot_settings', 'bitbot_auto_open', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_auto_open' ),
            'default'           => '',
        ));

        // 1.9.0 Feature 8f: proactive nudge tooltip. JSON config with three
        // variants:
        //   - 'simple' (Pro): single static line
        //   - 'page-aware' (Pro): URL-pattern → custom text map
        //   - 'rag' (Pro+ stretch): widget asks backend for a contextual
        //     nudge based on current page; backend reuses RAG retrieval
        register_setting('bitbot_settings', 'bitbot_nudge', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_nudge' ),
            'default'           => '',
        ));

        // 1.9.0 Feature 8h: pinned "Get started" message (Pro+).
        // Persistent banner at the top of the chat panel with optional
        // CTA button. JSON: { text, ctaLabel, ctaUrl }.
        register_setting('bitbot_settings', 'bitbot_pinned_message', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_pinned_message' ),
            'default'           => '',
        ));
        register_setting('bitbot_settings', 'bitbot_notification_email', array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_notification_email'),
            'default'           => get_option('admin_email', ''),
        ));
        // WooCommerce REST credentials moved to the React Integrations
        // panel — no more wp_options + sanitize-on-save path. Consumer
        // key + secret are entered in BitBot → Integrations → WooCommerce
        // and pushed straight to the backend via UpdateWcCredentials.

        // Appointments — one consolidated array option holds all booking
        // config (enabled, service, duration, timezone, weekly hours, etc.).
        // Sanitize validates + pushes to backend in one shot.
        register_setting('bitbot_settings', 'bitbot_appointments_config', array(
            'type'              => 'array',
            'sanitize_callback' => array($this, 'sanitize_appointments_config'),
            'default'           => array(),
        ));

        // Cart recovery — bundled into one setting array for the same
        // reason (single sanitize callback pushes to backend atomically).
        register_setting('bitbot_settings', 'bitbot_recovery_config', array(
            'type'              => 'array',
            'sanitize_callback' => array($this, 'sanitize_recovery_config'),
            'default'           => array(),
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
            'bitbot_widget_title',
            __('Widget Title', 'bitbot'),
            array($this, 'render_title_field'),
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
            'bitbot_widget_offset',
            __('Widget Offset', 'bitbot'),
            array($this, 'render_offset_field'),
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

        // 1.9.0: custom copy bundle — single grouped settings field that
        // renders four free-text inputs as one card. Keeps the Settings
        // page from sprouting four near-identical rows.
        add_settings_field(
            'bitbot_custom_copy_bundle',
            __( 'Custom Copy', 'bitbot' ),
            array( $this, 'render_custom_copy_fields' ),
            'bitbot-settings',
            'bitbot_chatbot_section'
        );

        // 1.9.0: hide-on-pages
        add_settings_field(
            'bitbot_hide_on_pages',
            __( 'Hide on Pages', 'bitbot' ),
            array( $this, 'render_hide_on_pages_field' ),
            'bitbot-settings',
            'bitbot_chatbot_section'
        );

        // 1.9.0: mobile-specific behaviour
        add_settings_field(
            'bitbot_mobile_behaviour',
            __( 'Mobile Behaviour', 'bitbot' ),
            array( $this, 'render_mobile_behaviour_field' ),
            'bitbot-settings',
            'bitbot_chatbot_section'
        );

        // 1.9.0: corner radius + shape (single grouped field)
        add_settings_field(
            'bitbot_appearance',
            __( 'Appearance', 'bitbot' ),
            array( $this, 'render_appearance_field' ),
            'bitbot-settings',
            'bitbot_chatbot_section'
        );

        // 1.9.0 Feature 8b: custom system prompt / site instructions.
        // Sanitize callback pushes the value to the backend.
        add_settings_field(
            'bitbot_custom_instructions',
            __( 'Bot Instructions', 'bitbot' ),
            array( $this, 'render_custom_instructions_field' ),
            'bitbot-settings',
            'bitbot_chatbot_section'
        );

        // 1.9.0 Feature 8l: off-topic guardrails (Pro / Pro+)
        add_settings_field(
            'bitbot_off_topic_guardrails',
            __( 'Off-topic Guardrails', 'bitbot' ),
            array( $this, 'render_off_topic_guardrails_field' ),
            'bitbot-settings',
            'bitbot_chatbot_section'
        );

        // 1.9.0 Feature 8d: custom avatar
        add_settings_field(
            'bitbot_avatar_url',
            __( 'Bot Avatar', 'bitbot' ),
            array( $this, 'render_avatar_field' ),
            'bitbot-settings',
            'bitbot_chatbot_section'
        );

        // 1.9.0 Feature 8e: suggested questions / quick-reply chips
        add_settings_field(
            'bitbot_suggested_questions',
            __( 'Suggested Questions', 'bitbot' ),
            array( $this, 'render_suggested_questions_field' ),
            'bitbot-settings',
            'bitbot_chatbot_section'
        );

        // 1.9.0 Feature 8g: auto-open trigger (Pro)
        add_settings_field(
            'bitbot_auto_open',
            __( 'Auto-open Trigger', 'bitbot' ),
            array( $this, 'render_auto_open_field' ),
            'bitbot-settings',
            'bitbot_chatbot_section'
        );

        // 1.9.0 Feature 8f: proactive nudge (Pro / Pro+)
        add_settings_field(
            'bitbot_nudge',
            __( 'Proactive Nudge', 'bitbot' ),
            array( $this, 'render_nudge_field' ),
            'bitbot-settings',
            'bitbot_chatbot_section'
        );

        // 1.9.0 Feature 8h: pinned Get-started message (Pro+)
        add_settings_field(
            'bitbot_pinned_message',
            __( 'Pinned Message', 'bitbot' ),
            array( $this, 'render_pinned_message_field' ),
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

        // Appointments section — booking in-house, no third-party integration.
        // The field renderer shows all config inputs under a single setting.
        add_settings_section(
            'bitbot_appointments_section',
            __( 'Appointments (Agent)', 'bitbot' ),
            array( $this, 'render_appointments_section' ),
            'bitbot-settings'
        );

        add_settings_field(
            'bitbot_appointments_config',
            __( 'Booking configuration', 'bitbot' ),
            array( $this, 'render_appointments_config_field' ),
            'bitbot-settings',
            'bitbot_appointments_section'
        );

        // Cart recovery — discount code the Agent can surface to
        // hesitating buyers.
        add_settings_section(
            'bitbot_recovery_section',
            __( 'Cart Recovery (Agent)', 'bitbot' ),
            array( $this, 'render_recovery_section' ),
            'bitbot-settings'
        );

        add_settings_field(
            'bitbot_recovery_config',
            __( 'Discount offer', 'bitbot' ),
            array( $this, 'render_recovery_config_field' ),
            'bitbot-settings',
            'bitbot_recovery_section'
        );

        // WooCommerce section — only shown if WooCommerce is active.
        // These credentials let the order-enquiry skill (Pro+) look up a
        // visitor's order via the WooCommerce REST API. The secret is sensitive;
        // we mask it in the UI and only push to the backend on save.
        // WooCommerce REST credentials moved to BitBot → Integrations →
        // WooCommerce in the React admin. No section / field
        // registration here anymore.
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
     * Sanitize + push appointment config bundle. Accepts the raw $_POST-shaped
     * nested array, validates, stores locally, and pushes to the backend via
     * the UpdateAvailability RPC in a single call.
     *
     * @since 1.7.0
     * @param mixed $value Raw input from the settings form.
     * @return array Normalized + stored config.
     */
    public function sanitize_appointments_config( $value ) {
        if ( ! is_array( $value ) ) {
            return (array) get_option( 'bitbot_appointments_config', array() );
        }

        $days = array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' );
        $weekly_hours = array();
        foreach ( $days as $idx => $day_key ) {
            $row = isset( $value['hours'][ $day_key ] ) && is_array( $value['hours'][ $day_key ] ) ? $value['hours'][ $day_key ] : array();
            $enabled_day = ! empty( $row['enabled'] );
            $start = isset( $row['start'] ) ? sanitize_text_field( (string) $row['start'] ) : '';
            $end   = isset( $row['end'] ) ? sanitize_text_field( (string) $row['end'] ) : '';
            if ( $enabled_day && preg_match( '/^\d{2}:\d{2}$/', $start ) && preg_match( '/^\d{2}:\d{2}$/', $end ) && $start < $end ) {
                $weekly_hours[] = array(
                    'dayOfWeek' => $idx,
                    'startTime' => $start,
                    'endTime'   => $end,
                );
            }
        }

        $config = array(
            'enabled'          => ! empty( $value['enabled'] ),
            'service_name'     => isset( $value['service_name'] ) ? sanitize_text_field( (string) $value['service_name'] ) : '',
            'duration_minutes' => isset( $value['duration_minutes'] ) ? max( 5, min( 240, intval( $value['duration_minutes'] ) ) ) : 30,
            'buffer_minutes'   => isset( $value['buffer_minutes'] ) ? max( 0, min( 120, intval( $value['buffer_minutes'] ) ) ) : 15,
            'timezone'         => isset( $value['timezone'] ) ? sanitize_text_field( (string) $value['timezone'] ) : 'UTC',
            'lead_time_hours'  => isset( $value['lead_time_hours'] ) ? max( 0, min( 168, intval( $value['lead_time_hours'] ) ) ) : 2,
            'max_days_ahead'   => isset( $value['max_days_ahead'] ) ? max( 1, min( 60, intval( $value['max_days_ahead'] ) ) ) : 14,
            'hours'            => isset( $value['hours'] ) && is_array( $value['hours'] ) ? $value['hours'] : array(),
        );

        // Push to backend. Failures are non-fatal — the local option still
        // persists so the admin UI reflects the user's intent, and the next
        // save will retry the push.
        $this->api_client->update_availability( array(
            'enabled'         => $config['enabled'],
            'serviceName'     => $config['service_name'],
            'durationMinutes' => $config['duration_minutes'],
            'bufferMinutes'   => $config['buffer_minutes'],
            'timezone'        => $config['timezone'],
            'leadTimeHours'   => $config['lead_time_hours'],
            'maxDaysAhead'    => $config['max_days_ahead'],
            'weeklyHours'     => $weekly_hours,
        ) );

        return $config;
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
     * Render the Upgrade page — three-card pricing layout (Free /
     * Assistant / Agent) with a "Get Assistant" / "Get Agent" CTA on
     * each upgradeable tier. The current plan's card is highlighted as
     * "Your plan" and its CTA is replaced with a non-interactive badge.
     *
     * Backend wiring:
     *   - "Get X" buttons fire the existing `bitbot_subscribe` AJAX
     *     handler with `tier=pro` (Assistant) or `tier=pro_plus` (Agent).
     *     Internal DB values stay unchanged; only labels change.
     *
     * @since 1.7.x (renamed to Assistant / Agent in 1.9.1)
     */
    public function render_upgrade_page() {
        $status      = $this->get_plan_status();
        $current     = $status['plan'] ?? BitBot_Plan::FREE;
        $is_free     = ( $current === BitBot_Plan::FREE );
        $is_pro      = ( $current === BitBot_Plan::PRO );
        $is_pro_plus = ( $current === BitBot_Plan::PRO_PLUS );
        ?>
        <div class="wrap" style="max-width: 1200px;">
            <h1 style="margin-bottom: 8px;"><?php esc_html_e( 'Choose your plan', 'bitbot' ); ?></h1>
            <p style="color: #555; margin-top: 0; margin-bottom: 32px;">
                <?php esc_html_e( 'Pick the tier that matches how you use BitBot. Upgrade or downgrade any time — billing prorates automatically.', 'bitbot' ); ?>
            </p>

            <div id="bitbot-pricing-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">

                <?php // ===== FREE ===== ?>
                <div class="bitbot-pricing-card" data-tier="free" style="background: #fff; border: 1px solid <?php echo $is_free ? '#0073aa' : '#e0e0e0'; ?>; border-radius: 8px; padding: 24px; box-shadow: <?php echo $is_free ? '0 0 0 2px #0073aa' : 'none'; ?>; display: flex; flex-direction: column;">
                    <h2 style="margin: 0 0 4px;"><?php esc_html_e( 'Free', 'bitbot' ); ?></h2>
                    <p style="font-size: 24px; font-weight: 600; margin: 0 0 4px;"><?php esc_html_e( 'Free', 'bitbot' ); ?> <span style="font-size: 14px; font-weight: 400; color: #666;"><?php esc_html_e( 'forever', 'bitbot' ); ?></span></p>
                    <p style="color: #666; margin: 0 0 18px; min-height: 36px;"><?php esc_html_e( 'Try the chatbot, capture your first leads, and see how visitors engage.', 'bitbot' ); ?></p>
                    <ul style="list-style: none; padding: 0; margin: 0 0 18px; flex: 1;">
                        <li style="margin: 6px 0;">✓ <?php esc_html_e( '100 chat messages per month', 'bitbot' ); ?></li>
                        <li style="margin: 6px 0;">✓ <?php esc_html_e( '3 AI-generated posts per month', 'bitbot' ); ?></li>
                        <li style="margin: 6px 0;">✓ <?php esc_html_e( '1 form, up to 10 leads', 'bitbot' ); ?></li>
                        <li style="margin: 6px 0;">✓ <?php esc_html_e( 'Multilingual chatbot', 'bitbot' ); ?></li>
                        <li style="margin: 6px 0; color: #999;">✗ <?php esc_html_e( 'Lead notification emails', 'bitbot' ); ?></li>
                        <li style="margin: 6px 0; color: #999;">✗ <?php esc_html_e( 'AI Lead Research', 'bitbot' ); ?></li>
                    </ul>
                    <?php if ( $is_free ) : ?>
                        <span class="button button-disabled" disabled style="background: #f0f0f0; color: #666; border-color: #ddd; text-align: center; pointer-events: none;"><?php esc_html_e( 'Your current plan', 'bitbot' ); ?></span>
                    <?php else : ?>
                        <button class="button bitbot-downgrade-btn" data-tier="free" style="background: #fff; color: #555; border: 1px solid #ccc;"><?php esc_html_e( 'Downgrade to Free', 'bitbot' ); ?></button>
                    <?php endif; ?>
                </div>

                <?php // ===== ASSISTANT (DB tier: pro) ===== ?>
                <div class="bitbot-pricing-card" data-tier="pro" style="background: #fff; border: 1px solid <?php echo $is_pro ? '#0073aa' : '#e0e0e0'; ?>; border-radius: 8px; padding: 24px; box-shadow: <?php echo $is_pro ? '0 0 0 2px #0073aa' : 'none'; ?>; display: flex; flex-direction: column;">
                    <h2 style="margin: 0 0 4px;"><?php esc_html_e( 'Assistant', 'bitbot' ); ?></h2>
                    <p style="font-size: 24px; font-weight: 600; margin: 0 0 4px;">$20 <span style="font-size: 14px; font-weight: 400; color: #666;"><?php esc_html_e( '/ month', 'bitbot' ); ?></span></p>
                    <p style="color: #666; margin: 0 0 18px; min-height: 36px;"><?php esc_html_e( 'Your bot, your voice — no limits. Custom title, custom instructions, suggested questions, proactive nudge, and everything else.', 'bitbot' ); ?></p>
                    <ul style="list-style: none; padding: 0; margin: 0 0 18px; flex: 1;">
                        <li style="margin: 6px 0; font-weight: 500;"><?php esc_html_e( 'Everything in Free, plus:', 'bitbot' ); ?></li>
                        <li style="margin: 6px 0;">✓ <?php esc_html_e( 'Unlimited chat messages', 'bitbot' ); ?> <span style="color: #888; font-size: 12px;"><?php esc_html_e( '(fair use)', 'bitbot' ); ?></span></li>
                        <li style="margin: 6px 0;">✓ <?php esc_html_e( 'Unlimited AI posts', 'bitbot' ); ?></li>
                        <li style="margin: 6px 0;">✓ <?php esc_html_e( 'Unlimited forms + leads', 'bitbot' ); ?></li>
                        <li style="margin: 6px 0;">✓ <?php esc_html_e( 'Lead notification emails + AI Lead Research', 'bitbot' ); ?></li>
                        <li style="margin: 6px 0;">✓ <?php esc_html_e( 'Full customisation: custom title, system prompt, chips, nudge, pinned banner, avatar, shapes', 'bitbot' ); ?></li>
                        <li style="margin: 6px 0; color: #999;">✗ <?php esc_html_e( 'Agent — skill harness + integrations', 'bitbot' ); ?></li>
                    </ul>
                    <?php if ( $is_pro ) : ?>
                        <span class="button button-disabled" disabled style="background: #f0f0f0; color: #666; border-color: #ddd; text-align: center; pointer-events: none;"><?php esc_html_e( 'Your current plan', 'bitbot' ); ?></span>
                    <?php else : ?>
                        <button class="button button-primary bitbot-choose-tier-btn" data-tier="pro"><?php esc_html_e( 'Get Assistant', 'bitbot' ); ?></button>
                    <?php endif; ?>
                </div>

                <?php // ===== AGENT (DB tier: pro_plus) ===== ?>
                <div class="bitbot-pricing-card" data-tier="pro_plus" style="background: linear-gradient(180deg, #fff 0%, #faf6ff 100%); border: 1px solid <?php echo $is_pro_plus ? '#0073aa' : '#7c4dff'; ?>; border-radius: 8px; padding: 24px; box-shadow: <?php echo $is_pro_plus ? '0 0 0 2px #0073aa' : '0 4px 12px rgba(124, 77, 255, 0.12)'; ?>; display: flex; flex-direction: column; position: relative;">
                    <span style="position: absolute; top: -10px; right: 16px; background: #7c4dff; color: #fff; font-size: 11px; font-weight: 600; padding: 4px 10px; border-radius: 12px; letter-spacing: 0.4px;">RECOMMENDED</span>
                    <h2 style="margin: 0 0 4px;"><?php esc_html_e( 'Agent', 'bitbot' ); ?></h2>
                    <p style="font-size: 24px; font-weight: 600; margin: 0 0 4px;">$49 <span style="font-size: 14px; font-weight: 400; color: #666;"><?php esc_html_e( '/ month', 'bitbot' ); ?></span></p>
                    <p style="color: #666; margin: 0 0 18px; min-height: 36px;"><?php esc_html_e( "Doesn't just talk — acts. Books appointments, takes orders, hands over to humans, pushes leads to your CRM, replies on WhatsApp.", 'bitbot' ); ?></p>
                    <ul style="list-style: none; padding: 0; margin: 0 0 18px; flex: 1;">
                        <li style="margin: 6px 0; font-weight: 500;"><?php esc_html_e( 'Everything in Assistant, plus:', 'bitbot' ); ?></li>
                        <li style="margin: 6px 0;">✓ <?php esc_html_e( 'Multi-step AI agent — tool-using, decisive', 'bitbot' ); ?></li>
                        <li style="margin: 6px 0;">✓ <?php esc_html_e( 'Appointment booking', 'bitbot' ); ?></li>
                        <li style="margin: 6px 0;">✓ <?php esc_html_e( 'WooCommerce: orders, drafts, refunds', 'bitbot' ); ?></li>
                        <li style="margin: 6px 0;">✓ <?php esc_html_e( 'Live chat takeover + Cart recovery', 'bitbot' ); ?></li>
                        <li style="margin: 6px 0;">✓ <?php esc_html_e( 'Integrations: WhatsApp, email, webhooks, Slack, CRM (rolling out)', 'bitbot' ); ?></li>
                    </ul>
                    <?php if ( $is_pro_plus ) : ?>
                        <span class="button button-disabled" disabled style="background: #f0f0f0; color: #666; border-color: #ddd; text-align: center; pointer-events: none;"><?php esc_html_e( 'Your current plan', 'bitbot' ); ?></span>
                    <?php else : ?>
                        <button class="button button-primary bitbot-choose-tier-btn" data-tier="pro_plus" style="background: #7c4dff; border-color: #7c4dff;"><?php esc_html_e( 'Get Agent', 'bitbot' ); ?></button>
                    <?php endif; ?>
                </div>
            </div>

            <p style="margin-top: 32px; color: #666; font-size: 13px;">
                <?php esc_html_e( 'Cancel any time from BitBot → Dashboard. We don\'t charge for support, setup, or onboarding. All paid plans include fair-use limits — see', 'bitbot' ); ?>
                <a href="https://youami.ai/fair-use" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'fair-use policy', 'bitbot' ); ?></a>.
            </p>
        </div>

        <script>
        (function($) {
            // Resolve config lazily inside each handler — the inline script
            // order races wp_localize_script's `bitbotAdmin` definition; if we
            // capture window.bitbotAdmin at IIFE time it can be undefined,
            // which makes jQuery POST to the current page URL (HTML response,
            // 'Unknown error' alert).
            function getConfig() { return window.bitbotAdmin || {}; }
            // Tier-aware checkout: clicking "Choose Pro" / "Choose Pro+" hits
            // the existing bitbot_subscribe AJAX with the tier; backend
            // resolves the matching Stripe price.
            $(document).on('click', '.bitbot-choose-tier-btn', function(e) {
                e.preventDefault();
                var config = getConfig();
                var $btn = $(this);
                var tier = $btn.data('tier');
                var originalText = $btn.text();
                $btn.text('Please wait...').prop('disabled', true);
                $.ajax({
                    url: config.ajaxUrl,
                    method: 'POST',
                    data: { action: 'bitbot_subscribe', nonce: config.nonce, tier: tier },
                    success: function(response) {
                        if (response.success && response.data) {
                            // Mid-tier swap path: existing customer just had
                            // their subscription's price item swapped server-
                            // side, no Stripe checkout round-trip needed.
                            if (response.data.tierUpdated) {
                                var dashUrl = '<?php echo esc_js( esc_url_raw( admin_url( "admin.php?page=bitbot&subscription=success&swap=1" ) ) ); ?>';
                                window.location.href = dashUrl;
                                return;
                            }
                            // Fresh checkout path
                            if (response.data.checkoutUrl) {
                                window.location.href = response.data.checkoutUrl;
                                return;
                            }
                        }
                        alert('Failed to start checkout: ' + ((response.data && response.data.error) || 'Unknown error'));
                        $btn.text(originalText).prop('disabled', false);
                    },
                    error: function() {
                        alert('Failed to connect to server');
                        $btn.text(originalText).prop('disabled', false);
                    }
                });
            });
            // Free downgrade is just Cancel Subscription with a confirm.
            $(document).on('click', '.bitbot-downgrade-btn', function(e) {
                e.preventDefault();
                if (!confirm('Cancel your paid subscription and downgrade to Free at the end of the current billing period?')) return;
                var config = getConfig();
                var $btn = $(this);
                var originalText = $btn.text();
                $btn.text('Cancelling...').prop('disabled', true);
                $.ajax({
                    url: config.ajaxUrl,
                    method: 'POST',
                    data: { action: 'bitbot_cancel_subscription', nonce: config.nonce },
                    success: function(response) {
                        if (response.success) {
                            alert('Subscription cancelled. You\'ll keep paid access until the end of the current billing period, then drop to Free.');
                            location.reload();
                        } else {
                            alert('Failed to cancel: ' + ((response.data && response.data.error) || 'Unknown error'));
                            $btn.text(originalText).prop('disabled', false);
                        }
                    },
                    error: function() {
                        alert('Failed to connect to server');
                        $btn.text(originalText).prop('disabled', false);
                    }
                });
            });
        })(jQuery);
        </script>

        <style>
        @media (max-width: 1024px) {
            #bitbot-pricing-grid { grid-template-columns: 1fr !important; }
        }
        </style>
        <?php
    }

    /**
     * Render the BitBot admin surface.
     *
     * Feature 9 hard cutover (v2.0.0): always renders the React SPA
     * mount-point. The actual UI — overview, chatbot settings, leads,
     * conversations, content generator, forms, calendar, integrations —
     * lives entirely in React/TypeScript under packages/wordpress-plugin/
     * admin-react/. JS bundle and CSS are enqueued by enqueue_admin_assets
     * in bitbot.php.
     *
     * The legacy PHP renderers below in this file (render_leads_page,
     * render_form_builder_page, etc.) are no longer wired to any menu
     * entry — they're kept as dead code for one release cycle in case
     * a follow-up release needs to roll back. Will be deleted in a
     * separate cleanup pass once 2.0 has been live for a week.
     */
    public function render_dashboard_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'bitbot' ) );
        }
        echo '<div id="bitbot-admin-root" data-page="bitbot-dashboard"></div>';
        return;
        // (Legacy PHP renderer kept below for one cycle — unreachable.)

        $reconnected = false;

        // Plan status on the dashboard is the headline visible thing and the
        // page where stale state hurts most (Plan card, Free banner, usage
        // bars, upgrade CTAs all depend on it). Bust the cache on every
        // render so this page always reflects the current backend state.
        // Other admin pages keep using the throttled cache (60s) for perf.
        $this->bust_plan_status_cache();

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

            <?php $this->render_plan_banner(); ?>

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

                <!-- Plan Card -->
                <div class="bitbot-card">
                    <h2><?php esc_html_e('Plan', 'bitbot'); ?></h2>
                    <?php
                    $plan_status_card       = $this->get_plan_status();
                    $plan                   = $plan_status_card['plan'] ?? BitBot_Plan::FREE;
                    $is_pro                 = BitBot_Plan::is_paid( $plan );
                    $plan_label             = BitBot_Plan::label( $plan );
                    $plan_price             = BitBot_Plan::price( $plan );
                    $plan_badge_color       = BitBot_Plan::badge_color( $plan );
                    $sub_data               = $subscription['success'] ? $subscription['data'] : array();
                    $sub_status             = $sub_data['subscriptionStatus'] ?? '';
                    $current_period_end     = $sub_data['currentPeriodEnd'] ?? null;
                    $is_cancelled_but_paid  = $sub_status === 'cancelled' && ! empty( $sub_data['isActive'] );
                    ?>
                        <p>
                            <strong><?php esc_html_e('Status:', 'bitbot'); ?></strong>
                            <span style="background: <?php echo esc_attr( $plan_badge_color ); ?>; color: white; padding: 3px 10px; border-radius: 3px; font-size: 12px;"><?php echo esc_html( $plan_label ); ?></span>
                        </p>

                        <?php if ( $is_pro && $current_period_end ) : ?>
                            <p>
                                <strong><?php esc_html_e( 'Next renewal:', 'bitbot' ); ?></strong>
                                <?php echo esc_html( gmdate( 'F j, Y', strtotime( $current_period_end ) ) ); ?>
                            </p>
                        <?php endif; ?>

                        <?php if ( $is_cancelled_but_paid && $current_period_end ) : ?>
                            <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 10px; border-radius: 4px; margin: 10px 0;">
                                <strong><?php esc_html_e( 'Subscription cancelled', 'bitbot' ); ?></strong>
                                <p style="margin: 5px 0 0;">
                                    <?php
                                    /* translators: %s: subscription end date */
                                    printf( esc_html__( 'Your Pro access continues until %s, then the site drops to the Free plan.', 'bitbot' ), esc_html( gmdate( 'F j, Y', strtotime( $current_period_end ) ) ) );
                                    ?>
                                </p>
                            </div>
                        <?php endif; ?>

                        <p style="margin-top: 15px;">
                            <strong><?php esc_html_e( 'Price:', 'bitbot' ); ?></strong>
                            <?php echo esc_html( $plan_price ); ?>
                        </p>
                        <?php if ( $is_pro ) : ?>
                            <p style="margin-top: -6px; font-size: 11px; color: #666;">
                                <?php
                                /* translators: %s: fair-use policy link */
                                printf(
                                    esc_html__( 'Fair use applies — %s.', 'bitbot' ),
                                    '<a href="https://youami.ai/fair-use" target="_blank" rel="noopener noreferrer">' . esc_html__( 'see policy', 'bitbot' ) . '</a>'
                                );
                                ?>
                            </p>

                            <?php
                            // Usage bars for Pro. Gives paying users visibility
                            // into where they sit against the fair-use caps so
                            // they can see a slowdown coming before hitting a
                            // 429 on the agent.
                            $pro_usage  = $plan_status_card['usage'] ?? array();
                            $pro_limits = $plan_status_card['limits'] ?? array();
                            $bars = array(
                                array(
                                    'label'   => __( 'Chat messages', 'bitbot' ),
                                    'used'    => (int) ( $pro_usage['chatMessagesThisMonth'] ?? 0 ),
                                    'cap'     => (int) ( $pro_limits['chatMessagesPerMonth'] ?? 0 ),
                                ),
                                array(
                                    'label'   => __( 'Posts generated', 'bitbot' ),
                                    'used'    => (int) ( $pro_usage['postsThisMonth'] ?? 0 ),
                                    'cap'     => (int) ( $pro_limits['postsPerMonth'] ?? 0 ),
                                ),
                                array(
                                    'label'   => __( 'Leads captured', 'bitbot' ),
                                    'used'    => (int) ( $pro_usage['leadsCount'] ?? 0 ),
                                    'cap'     => (int) ( $pro_limits['maxLeads'] ?? 0 ),
                                ),
                            );
                            ?>
                            <div style="margin-top: 14px;">
                                <?php foreach ( $bars as $bar ) :
                                    if ( $bar['cap'] <= 0 ) {
                                        // Skip rows the backend doesn't cap.
                                        continue;
                                    }
                                    $pct = max( 0, min( 100, (int) round( ( $bar['used'] / $bar['cap'] ) * 100 ) ) );
                                    // Bar colour warms up as usage approaches the cap so
                                    // heavy users get a visual heads-up before throttling.
                                    $color = '#46b450';
                                    if ( $pct >= 80 ) { $color = '#d63638'; }
                                    elseif ( $pct >= 60 ) { $color = '#f0b849'; }
                                ?>
                                    <div style="margin: 6px 0;">
                                        <div style="display: flex; justify-content: space-between; font-size: 11px; color: #555; margin-bottom: 2px;">
                                            <span><?php echo esc_html( $bar['label'] ); ?></span>
                                            <span><?php echo (int) $bar['used']; ?> / <?php echo (int) $bar['cap']; ?></span>
                                        </div>
                                        <div style="height: 4px; background: #e6e6e6; border-radius: 2px; overflow: hidden;">
                                            <div style="height: 100%; width: <?php echo (int) $pct; ?>%; background: <?php echo esc_attr( $color ); ?>; transition: width 200ms ease;"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ( $is_pro && ! $is_cancelled_but_paid ) : ?>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <button id="bitbot-billing-btn" class="button button-secondary">
                                    <?php esc_html_e( 'Manage Billing / Invoices', 'bitbot' ); ?>
                                </button>
                                <button id="bitbot-cancel-btn" class="button" style="color: #a00;">
                                    <?php esc_html_e( 'Cancel Subscription', 'bitbot' ); ?>
                                </button>
                            </div>
                        <?php elseif ( $is_cancelled_but_paid ) : ?>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <button id="bitbot-billing-btn" class="button button-secondary">
                                    <?php esc_html_e( 'Manage Billing / Invoices', 'bitbot' ); ?>
                                </button>
                                <button id="bitbot-reactivate-btn" class="button button-primary">
                                    <?php esc_html_e( 'Reactivate Subscription', 'bitbot' ); ?>
                                </button>
                            </div>
                        <?php else : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=bitbot-upgrade' ) ); ?>" class="button button-primary button-hero" style="margin-top: 10px;">
                                <?php esc_html_e( 'Upgrade plan', 'bitbot' ); ?>
                            </a>
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
            <?php $this->render_plan_banner(); ?>

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
                <?php
                $draft_rows = ( $drafts['success'] && isset( $drafts['data']['drafts'] ) )
                    ? $drafts['data']['drafts']
                    : array();
                $draft_total = ( $drafts['success'] && isset( $drafts['data']['total'] ) )
                    ? (int) $drafts['data']['total']
                    : count( $draft_rows );
                ?>
                <?php if ( $drafts['success'] && ! empty( $draft_rows ) ): ?>
                    <?php if ( $draft_total > count( $draft_rows ) ): ?>
                        <p style="color: #666;">
                            <?php
                            printf(
                                /* translators: 1: visible count, 2: total */
                                esc_html__( 'Showing %1$d of %2$d drafts (most recent first).', 'bitbot' ),
                                count( $draft_rows ),
                                $draft_total
                            );
                            ?>
                        </p>
                    <?php endif; ?>
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
                            <?php foreach ($draft_rows as $draft):
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
        // WordPress auto-renders the "Settings saved." notice on options-general.php
        // but not on custom admin pages like ours. Surface it manually.
        if ( isset( $_GET['settings-updated'] ) ) {
            add_settings_error(
                'bitbot_messages',
                'bitbot_settings_saved',
                __( 'Settings saved.', 'bitbot' ),
                'updated'
            );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('BitBot Settings', 'bitbot'); ?></h1>
            <?php settings_errors( 'bitbot_messages' ); ?>
            <?php $this->render_plan_banner(); ?>
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

    /**
     * Single-line text input for the widget header title. Empty string
     * falls back to the localized "Chat with us" default at render time
     * (see BitBot_Widget::WIDGET_TITLE_DEFAULT).
     *
     * @since 1.9.0
     */
    public function render_title_field() {
        $title = get_option( 'bitbot_widget_title', '' );
        ?>
        <input
            type="text"
            name="bitbot_widget_title"
            value="<?php echo esc_attr( $title ); ?>"
            class="regular-text"
            maxlength="40"
            placeholder="<?php esc_attr_e( 'Chat with us', 'bitbot' ); ?>"
        >
        <p class="description" style="margin-top:6px;">
            <?php esc_html_e( 'Shown at the top of the chat widget. Leave empty to use the default.', 'bitbot' ); ?>
        </p>
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

    /**
     * Two number inputs for vertical + horizontal pixel offset from the
     * configured corner. Defaults to 20px each. Tweaking these lets the
     * owner sidestep a stubborn third-party widget without us having to
     * detect it.
     *
     * The auto-flip JS layers on top: if both corners are taken, it
     * stacks BitBot on top of the corner occupant by bumping the
     * vertical offset at runtime (without touching the saved option).
     */
    public function render_offset_field() {
        $x = (int) get_option( 'bitbot_widget_offset_x', 20 );
        $y = (int) get_option( 'bitbot_widget_offset_y', 20 );
        ?>
        <div style="display:flex; gap:24px; flex-wrap:wrap;">
            <label style="display:flex; flex-direction:column; gap:4px;">
                <span style="font-weight:500;"><?php esc_html_e( 'Horizontal (px)', 'bitbot' ); ?></span>
                <input type="number" name="bitbot_widget_offset_x" value="<?php echo esc_attr( $x ); ?>" min="0" max="200" step="1" style="width:90px;">
            </label>
            <label style="display:flex; flex-direction:column; gap:4px;">
                <span style="font-weight:500;"><?php esc_html_e( 'Vertical (px)', 'bitbot' ); ?></span>
                <input type="number" name="bitbot_widget_offset_y" value="<?php echo esc_attr( $y ); ?>" min="0" max="200" step="1" style="width:90px;">
            </label>
        </div>
        <p class="description" style="margin-top:8px;">
            <?php esc_html_e( 'Distance from the chosen corner. If another floating widget is already there, BitBot will automatically stack on top of it — adjust manually if you want a different gap.', 'bitbot' ); ?>
        </p>
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
     * Pinned "Get started" message — any paid tier (Assistant or Agent).
     * Persistent banner at the top of the chat panel with optional CTA.
     *
     * @since 1.9.0 Feature 8h
     */
    public function render_pinned_message_field() {
        $raw = (string) get_option( 'bitbot_pinned_message', '' );
        $cfg = json_decode( $raw, true );
        if ( ! is_array( $cfg ) ) $cfg = array();
        $text     = isset( $cfg['text'] ) ? (string) $cfg['text'] : '';
        $cta_lbl  = isset( $cfg['ctaLabel'] ) ? (string) $cfg['ctaLabel'] : '';
        $cta_url  = isset( $cfg['ctaUrl'] ) ? (string) $cfg['ctaUrl'] : '';
        $is_paid       = $this->is_pro();
        $disabled_attr = $is_paid ? '' : 'disabled';
        ?>
        <div style="display:flex; flex-direction:column; gap:14px; max-width:640px;">
            <label style="display:flex; flex-direction:column; gap:4px;">
                <span style="font-weight:500;">
                    <?php esc_html_e( 'Banner text', 'bitbot' ); ?>
                    <?php if ( ! $is_paid ) : ?>
                        <span style="background:#e9e3ff; color:#4b2bcc; padding:1px 8px; border-radius:10px; font-size:11px; font-weight:600; margin-left:6px;"><?php esc_html_e( 'Assistant', 'bitbot' ); ?></span>
                    <?php endif; ?>
                </span>
                <input
                    type="text"
                    id="bitbot-pinned-text"
                    value="<?php echo esc_attr( $text ); ?>"
                    class="regular-text"
                    maxlength="200"
                    placeholder="<?php esc_attr_e( "📋 Want a custom quote?", 'bitbot' ); ?>"
                    <?php echo $disabled_attr; ?>
                >
            </label>

            <div style="display:flex; gap:12px;">
                <label style="display:flex; flex-direction:column; gap:4px; flex:1;">
                    <span style="font-weight:500;"><?php esc_html_e( 'CTA button label (optional)', 'bitbot' ); ?></span>
                    <input
                        type="text"
                        id="bitbot-pinned-cta-label"
                        value="<?php echo esc_attr( $cta_lbl ); ?>"
                        maxlength="40"
                        placeholder="<?php esc_attr_e( 'Get a Quote', 'bitbot' ); ?>"
                        <?php echo $disabled_attr; ?>
                    >
                </label>
                <label style="display:flex; flex-direction:column; gap:4px; flex:2;">
                    <span style="font-weight:500;"><?php esc_html_e( 'CTA button URL (optional)', 'bitbot' ); ?></span>
                    <input
                        type="url"
                        id="bitbot-pinned-cta-url"
                        value="<?php echo esc_attr( $cta_url ); ?>"
                        placeholder="https://yoursite.com/contact"
                        <?php echo $disabled_attr; ?>
                    >
                </label>
            </div>

            <input type="hidden" name="bitbot_pinned_message" id="bitbot-pinned-json" value="<?php echo esc_attr( $raw ); ?>">
            <p class="description">
                <?php esc_html_e( 'Shown at the top of the chat panel, above the conversation. Persists across the session. Visitor can dismiss with an X.', 'bitbot' ); ?>
            </p>
        </div>
        <script>
        (function(){
            var json = document.getElementById('bitbot-pinned-json');
            function serialize() {
                json.value = JSON.stringify({
                    text:     document.getElementById('bitbot-pinned-text').value,
                    ctaLabel: document.getElementById('bitbot-pinned-cta-label').value,
                    ctaUrl:   document.getElementById('bitbot-pinned-cta-url').value,
                });
            }
            ['bitbot-pinned-text','bitbot-pinned-cta-label','bitbot-pinned-cta-url'].forEach(function(id){
                document.getElementById(id).addEventListener('input', serialize);
            });
            serialize();
        })();
        </script>
        <?php
    }

    /**
     * Proactive nudge — speech-bubble that pops out of the FAB after N
     * seconds with a configurable line. Three modes:
     *   - simple: one static line
     *   - page-aware: list of {pattern, text} pairs
     *   - rag (Pro+): widget asks backend for a contextual nudge using
     *     RAG retrieval based on the current page URL
     *
     * @since 1.9.0 Feature 8f
     */
    public function render_nudge_field() {
        $raw = (string) get_option( 'bitbot_nudge', '' );
        $cfg = json_decode( $raw, true );
        if ( ! is_array( $cfg ) ) $cfg = array();
        $enabled    = ! empty( $cfg['enabled'] );
        $mode       = isset( $cfg['mode'] ) ? (string) $cfg['mode'] : 'simple';
        $simple     = isset( $cfg['simpleText'] ) ? (string) $cfg['simpleText'] : '';
        $delay      = isset( $cfg['delaySeconds'] ) ? (int) $cfg['delaySeconds'] : 8;
        $page_rules = isset( $cfg['pageRules'] ) ? (string) $cfg['pageRules'] : '';
        $is_paid       = $this->is_pro();
        $disabled_attr = $is_paid ? '' : 'disabled';
        ?>
        <div style="display:flex; flex-direction:column; gap:14px; max-width:640px;">
            <label style="display:flex; align-items:center; gap:8px;">
                <input type="checkbox" id="bitbot-nudge-enabled" value="1" <?php checked( $enabled ); ?> <?php echo $disabled_attr; ?>>
                <span style="font-weight:500;"><?php esc_html_e( 'Enable proactive nudge', 'bitbot' ); ?></span>
                <?php if ( ! $is_paid ) : ?>
                    <span style="background:#e9e3ff; color:#4b2bcc; padding:1px 8px; border-radius:10px; font-size:11px; font-weight:600;"><?php esc_html_e( 'Assistant', 'bitbot' ); ?></span>
                <?php endif; ?>
            </label>

            <label style="display:flex; align-items:center; gap:8px;">
                <span style="font-weight:500;"><?php esc_html_e( 'Show after N seconds:', 'bitbot' ); ?></span>
                <input type="number" id="bitbot-nudge-delay" min="2" max="60" step="1" value="<?php echo esc_attr( $delay ); ?>" style="width:80px;" <?php echo $disabled_attr; ?>>
            </label>

            <fieldset style="border:1px solid #ddd; padding:12px; border-radius:6px;">
                <legend style="padding:0 8px; font-weight:500;"><?php esc_html_e( 'Variant', 'bitbot' ); ?></legend>
                <label style="display:flex; flex-direction:column; gap:6px;">
                    <span><input type="radio" name="bitbot-nudge-mode" value="simple" <?php checked( $mode, 'simple' ); ?> <?php echo $disabled_attr; ?>> <?php esc_html_e( 'Simple — one static line', 'bitbot' ); ?></span>
                    <input type="text" id="bitbot-nudge-simple" maxlength="80" class="regular-text" value="<?php echo esc_attr( $simple ); ?>" placeholder="<?php esc_attr_e( "Need help finding cottages?", 'bitbot' ); ?>" <?php echo $disabled_attr; ?>>
                </label>

                <label style="display:flex; flex-direction:column; gap:6px; margin-top:12px;">
                    <span><input type="radio" name="bitbot-nudge-mode" value="page-aware" <?php checked( $mode, 'page-aware' ); ?> <?php echo $disabled_attr; ?>> <?php esc_html_e( 'Page-aware — different text per URL pattern', 'bitbot' ); ?></span>
                    <textarea id="bitbot-nudge-pages" rows="4" class="large-text code" placeholder="<?php esc_attr_e( "/pricing | Got a question about pricing?\n/contact | Want a quick reply?\n/products/* | Looking for something specific?", 'bitbot' ); ?>" <?php echo $disabled_attr; ?>><?php echo esc_textarea( $page_rules ); ?></textarea>
                    <span class="description"><?php esc_html_e( 'One rule per line. Format: `URL pattern | nudge text`. First matching rule wins.', 'bitbot' ); ?></span>
                </label>

                <label style="display:flex; align-items:flex-start; gap:6px; margin-top:12px;">
                    <input type="radio" name="bitbot-nudge-mode" value="rag" <?php checked( $mode, 'rag' ); ?> <?php echo ( $is_paid ? '' : 'disabled' ); ?>>
                    <span>
                        <?php esc_html_e( 'Smart (recommendation engine) — generate a contextual nudge for each page using your indexed content', 'bitbot' ); ?>
                        <?php if ( ! $is_paid ) : ?>
                            <span style="background:#e9e3ff; color:#4b2bcc; padding:1px 8px; border-radius:10px; font-size:11px; font-weight:600; margin-left:4px;"><?php esc_html_e( 'Assistant', 'bitbot' ); ?></span>
                        <?php endif; ?>
                    </span>
                </label>
            </fieldset>

            <input type="hidden" name="bitbot_nudge" id="bitbot-nudge-json" value="<?php echo esc_attr( $raw ); ?>">
        </div>
        <script>
        (function(){
            var json = document.getElementById('bitbot-nudge-json');
            function modeValue() {
                var radios = document.getElementsByName('bitbot-nudge-mode');
                for (var i=0; i<radios.length; i++) if (radios[i].checked) return radios[i].value;
                return 'simple';
            }
            function serialize() {
                json.value = JSON.stringify({
                    enabled:       !!document.getElementById('bitbot-nudge-enabled').checked,
                    delaySeconds:  parseInt(document.getElementById('bitbot-nudge-delay').value, 10) || 8,
                    mode:          modeValue(),
                    simpleText:    document.getElementById('bitbot-nudge-simple').value,
                    pageRules:     document.getElementById('bitbot-nudge-pages').value,
                });
            }
            ['bitbot-nudge-enabled','bitbot-nudge-delay','bitbot-nudge-simple','bitbot-nudge-pages'].forEach(function(id){
                var el = document.getElementById(id);
                if (!el) return;
                el.addEventListener('input',  serialize);
                el.addEventListener('change', serialize);
            });
            document.getElementsByName('bitbot-nudge-mode').forEach(function(el){
                el.addEventListener('change', serialize);
            });
            serialize();
        })();
        </script>
        <?php
    }

    /**
     * Auto-open trigger config. Pro feature — Free customers see the
     * fields but they're disabled. Layout: 4 toggles + numeric inputs,
     * stored as a JSON object.
     *
     * @since 1.9.0 Feature 8g
     */
    public function render_auto_open_field() {
        $raw     = (string) get_option( 'bitbot_auto_open', '' );
        $cfg     = json_decode( $raw, true );
        if ( ! is_array( $cfg ) ) $cfg = array();
        $url_patterns = isset( $cfg['urlPatterns'] ) ? (string) $cfg['urlPatterns'] : '';
        $delay_secs   = isset( $cfg['delaySeconds'] ) ? (int) $cfg['delaySeconds'] : 0;
        $scroll_pct   = isset( $cfg['scrollPercent'] ) ? (int) $cfg['scrollPercent'] : 0;
        $exit_intent  = ! empty( $cfg['exitIntent'] );
        $is_paid      = $this->is_pro();
        $disabled_attr = $is_paid ? '' : 'disabled';
        ?>
        <div style="display:flex; flex-direction:column; gap:14px; max-width:640px;">
            <label style="display:flex; flex-direction:column; gap:4px;">
                <span style="font-weight:500;"><?php esc_html_e( 'Open on these URL patterns', 'bitbot' ); ?></span>
                <textarea
                    id="bitbot-auto-open-urls"
                    rows="3"
                    class="large-text code"
                    placeholder="<?php esc_attr_e( "/pricing\n/contact\n/get-quote*", 'bitbot' ); ?>"
                    <?php echo $disabled_attr; ?>
                ><?php echo esc_textarea( $url_patterns ); ?></textarea>
                <span class="description"><?php esc_html_e( 'One pattern per line. `*` wildcards supported. Empty = no URL-based trigger.', 'bitbot' ); ?></span>
            </label>

            <label style="display:flex; align-items:center; gap:8px;">
                <span style="font-weight:500;"><?php esc_html_e( 'Open after N seconds on page:', 'bitbot' ); ?></span>
                <input
                    type="number"
                    id="bitbot-auto-open-delay"
                    min="0"
                    max="300"
                    step="1"
                    value="<?php echo esc_attr( $delay_secs ); ?>"
                    style="width:80px;"
                    <?php echo $disabled_attr; ?>
                >
                <span class="description"><?php esc_html_e( '0 = disabled', 'bitbot' ); ?></span>
            </label>

            <label style="display:flex; align-items:center; gap:8px;">
                <span style="font-weight:500;"><?php esc_html_e( 'Open after scrolling N% of the page:', 'bitbot' ); ?></span>
                <input
                    type="number"
                    id="bitbot-auto-open-scroll"
                    min="0"
                    max="100"
                    step="5"
                    value="<?php echo esc_attr( $scroll_pct ); ?>"
                    style="width:80px;"
                    <?php echo $disabled_attr; ?>
                >
                <span class="description"><?php esc_html_e( '0 = disabled', 'bitbot' ); ?></span>
            </label>

            <label style="display:flex; align-items:center; gap:8px;">
                <input
                    type="checkbox"
                    id="bitbot-auto-open-exit"
                    value="1"
                    <?php checked( $exit_intent ); ?>
                    <?php echo $disabled_attr; ?>
                >
                <span style="font-weight:500;"><?php esc_html_e( 'Open on exit-intent (mouse leaves toward browser top)', 'bitbot' ); ?></span>
            </label>

            <input type="hidden" name="bitbot_auto_open" id="bitbot-auto-open-json" value="<?php echo esc_attr( $raw ); ?>">
            <p class="description" style="margin-top:4px;">
                <?php
                if ( $is_paid ) {
                    esc_html_e( 'Triggers fire once per session. Use sparingly — multiple aggressive triggers can hurt conversion.', 'bitbot' );
                } else {
                    esc_html_e( 'Auto-open is a Pro feature. Upgrade to enable any of the triggers above.', 'bitbot' );
                }
                ?>
            </p>
        </div>
        <script>
        (function(){
            var json = document.getElementById('bitbot-auto-open-json');
            function serialize() {
                json.value = JSON.stringify({
                    urlPatterns:   document.getElementById('bitbot-auto-open-urls').value.trim(),
                    delaySeconds:  parseInt(document.getElementById('bitbot-auto-open-delay').value, 10) || 0,
                    scrollPercent: parseInt(document.getElementById('bitbot-auto-open-scroll').value, 10) || 0,
                    exitIntent:    !!document.getElementById('bitbot-auto-open-exit').checked,
                });
            }
            ['bitbot-auto-open-urls','bitbot-auto-open-delay','bitbot-auto-open-scroll','bitbot-auto-open-exit'].forEach(function(id){
                var el = document.getElementById(id);
                if (!el) return;
                el.addEventListener('input',  serialize);
                el.addEventListener('change', serialize);
            });
            serialize();
        })();
        </script>
        <?php
    }

    /**
     * Render the suggested-questions repeater. Each row is a label (chip
     * text the visitor sees) + message (what gets sent if they click).
     * Stored as a JSON string in one option for simplicity; rendered to
     * the visitor by widget.js above the welcome message.
     *
     * @since 1.9.0 Feature 8e
     */
    public function render_suggested_questions_field() {
        $raw     = (string) get_option( 'bitbot_suggested_questions', '' );
        $items   = array();
        if ( $raw !== '' ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $items = $decoded;
            }
        }
        $is_paid = $this->is_pro();
        $cap     = $is_paid ? 8 : 3;
        // Pad with one empty row if there's no content yet.
        if ( empty( $items ) ) {
            $items = array( array( 'label' => '', 'message' => '' ) );
        }
        ?>
        <div id="bitbot-chips-repeater" style="display:flex; flex-direction:column; gap:10px; max-width:640px;">
            <?php foreach ( $items as $i => $item ) :
                $label   = isset( $item['label'] ) ? (string) $item['label'] : '';
                $message = isset( $item['message'] ) ? (string) $item['message'] : '';
                ?>
                <div class="bitbot-chip-row" style="display:flex; gap:8px; align-items:flex-start;">
                    <input
                        type="text"
                        class="bitbot-chip-label regular-text"
                        value="<?php echo esc_attr( $label ); ?>"
                        placeholder="<?php esc_attr_e( 'Chip label e.g. \"How much?\"', 'bitbot' ); ?>"
                        maxlength="40"
                        style="flex:1;"
                    >
                    <input
                        type="text"
                        class="bitbot-chip-message regular-text"
                        value="<?php echo esc_attr( $message ); ?>"
                        placeholder="<?php esc_attr_e( 'What gets sent (defaults to label)', 'bitbot' ); ?>"
                        maxlength="200"
                        style="flex:2;"
                    >
                    <button type="button" class="button bitbot-chip-remove" style="color:#b32d2e;">&times;</button>
                </div>
            <?php endforeach; ?>
        </div>
        <input type="hidden" name="bitbot_suggested_questions" id="bitbot-chips-json" value="<?php echo esc_attr( $raw ); ?>">
        <p style="margin-top:10px;">
            <button type="button" class="button" id="bitbot-chips-add">
                + <?php esc_html_e( 'Add a chip', 'bitbot' ); ?>
            </button>
            <span class="description" style="margin-left:8px;">
                <?php
                if ( $is_paid ) {
                    printf( esc_html__( 'Up to %d chips. Shown above the welcome message; visitors click to send.', 'bitbot' ), $cap );
                } else {
                    printf( esc_html__( 'Up to %d chips on Free (Pro unlocks more). Shown above the welcome message.', 'bitbot' ), $cap );
                }
                ?>
            </span>
        </p>
        <script>
        (function(){
            var rep  = document.getElementById('bitbot-chips-repeater');
            var json = document.getElementById('bitbot-chips-json');
            var cap  = <?php echo (int) $cap; ?>;

            function serialize() {
                var rows = rep.querySelectorAll('.bitbot-chip-row');
                var out  = [];
                rows.forEach(function(row){
                    var label   = row.querySelector('.bitbot-chip-label').value.trim();
                    var message = row.querySelector('.bitbot-chip-message').value.trim();
                    if (label === '' && message === '') return;
                    out.push({ label: label || message, message: message || label });
                });
                json.value = JSON.stringify(out);
            }

            function attachRow(row) {
                row.querySelector('.bitbot-chip-remove').addEventListener('click', function(){
                    row.remove();
                    serialize();
                    updateAddButton();
                });
                row.querySelectorAll('input').forEach(function(inp){
                    inp.addEventListener('input', serialize);
                });
            }

            function updateAddButton() {
                var addBtn = document.getElementById('bitbot-chips-add');
                var rows = rep.querySelectorAll('.bitbot-chip-row').length;
                addBtn.disabled = rows >= cap;
            }

            rep.querySelectorAll('.bitbot-chip-row').forEach(attachRow);

            document.getElementById('bitbot-chips-add').addEventListener('click', function(){
                var rows = rep.querySelectorAll('.bitbot-chip-row').length;
                if (rows >= cap) return;
                var div = document.createElement('div');
                div.className = 'bitbot-chip-row';
                div.style = 'display:flex; gap:8px; align-items:flex-start;';
                div.innerHTML =
                    '<input type="text" class="bitbot-chip-label regular-text" placeholder="Chip label" maxlength="40" style="flex:1;">' +
                    '<input type="text" class="bitbot-chip-message regular-text" placeholder="What gets sent (defaults to label)" maxlength="200" style="flex:2;">' +
                    '<button type="button" class="button bitbot-chip-remove" style="color:#b32d2e;">&times;</button>';
                rep.appendChild(div);
                attachRow(div);
                updateAddButton();
            });

            updateAddButton();
            // Ensure hidden field starts in sync.
            serialize();
        })();
        </script>
        <?php
    }

    /**
     * Render the custom avatar field — URL input + "Select Image" button
     * that opens the WP media library. Free: static raster only (PNG /
     * JPEG / WebP). Pro and above: also accepts animated SVG / Lottie.
     *
     * @since 1.9.0 Feature 8d
     */
    public function render_avatar_field() {
        $url     = (string) get_option( 'bitbot_avatar_url', '' );
        $is_paid = $this->is_pro();
        $tier_label = $is_paid ? esc_html__( 'You can upload static or animated (SVG / Lottie) avatars.', 'bitbot' ) : esc_html__( 'Free tier supports static PNG / JPEG / WebP.', 'bitbot' );
        ?>
        <div style="display:flex; flex-direction:column; gap:8px; max-width:480px;">
            <div style="display:flex; gap:8px; align-items:center;">
                <input
                    type="url"
                    name="bitbot_avatar_url"
                    id="bitbot-avatar-url-input"
                    value="<?php echo esc_attr( $url ); ?>"
                    class="regular-text"
                    placeholder="<?php esc_attr_e( 'https://yoursite.com/wp-content/uploads/bot-avatar.png', 'bitbot' ); ?>"
                    style="flex:1;"
                >
                <button type="button" id="bitbot-avatar-pick" class="button">
                    <?php esc_html_e( 'Select Image', 'bitbot' ); ?>
                </button>
            </div>
            <div id="bitbot-avatar-preview" style="display:<?php echo $url ? 'flex' : 'none'; ?>; align-items:center; gap:12px;">
                <img src="<?php echo esc_url( $url ); ?>" alt="" style="width:48px; height:48px; border-radius:50%; object-fit:cover; border:1px solid #ddd;">
                <button type="button" id="bitbot-avatar-clear" class="button-link" style="color:#b32d2e;">
                    <?php esc_html_e( 'Remove', 'bitbot' ); ?>
                </button>
            </div>
            <p class="description"><?php echo $tier_label; ?></p>
        </div>
        <script>
        (function(){
            if (typeof wp === 'undefined' || !wp.media) return;
            var picker;
            document.getElementById('bitbot-avatar-pick').addEventListener('click', function(){
                if (!picker) {
                    picker = wp.media({
                        title: <?php echo wp_json_encode( __( 'Select bot avatar', 'bitbot' ) ); ?>,
                        button: { text: <?php echo wp_json_encode( __( 'Use this image', 'bitbot' ) ); ?> },
                        library: { type: 'image' },
                        multiple: false
                    });
                    picker.on('select', function(){
                        var att = picker.state().get('selection').first().toJSON();
                        var input = document.getElementById('bitbot-avatar-url-input');
                        var preview = document.getElementById('bitbot-avatar-preview');
                        input.value = att.url;
                        preview.style.display = 'flex';
                        preview.querySelector('img').src = att.url;
                    });
                }
                picker.open();
            });
            document.getElementById('bitbot-avatar-clear').addEventListener('click', function(){
                document.getElementById('bitbot-avatar-url-input').value = '';
                document.getElementById('bitbot-avatar-preview').style.display = 'none';
            });
        })();
        </script>
        <?php
    }

    /**
     * Off-topic guardrails — newline-separated list of topics the bot
     * should refuse. Pro / Pro+ feature; on Free, the field is read-only
     * with a "Pro" badge nudge.
     *
     * @since 1.9.0 Feature 8l
     */
    public function render_off_topic_guardrails_field() {
        $value   = (string) get_option( 'bitbot_off_topic_guardrails', '' );
        $is_paid = $this->is_pro();
        ?>
        <textarea
            name="bitbot_off_topic_guardrails"
            rows="5"
            class="large-text"
            placeholder="<?php esc_attr_e( "competitor names\npricing details (direct to /pricing instead)\nmedical advice\ncode generation\npolitics", 'bitbot' ); ?>"
            <?php disabled( ! $is_paid ); ?>
        ><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description" style="margin-top:6px;">
            <?php esc_html_e( 'One topic per line. The bot will refuse questions about these topics instead of answering. Hard rules — enforced even if your indexed content covers the topic.', 'bitbot' ); ?>
            <?php if ( ! $is_paid ) : ?>
                <span style="display:inline-block; background:#e9e3ff; color:#4b2bcc; padding:1px 8px; border-radius:10px; font-size:11px; font-weight:600; margin-left:6px; vertical-align:1px;"><?php esc_html_e( 'Assistant', 'bitbot' ); ?></span>
            <?php endif; ?>
        </p>
        <?php
    }

    /**
     * Multi-line textarea for the admin's site-specific instructions
     * to the bot. Free / Assistant / Agent length caps are enforced server-side;
     * the admin UI shows the cap inline.
     *
     * @since 1.9.0
     */
    public function render_custom_instructions_field() {
        $instructions = get_option( 'bitbot_custom_instructions', '' );
        $plan         = $this->get_current_plan();
        if ( $plan === 'pro_plus' ) {
            $cap = 4000;
        } elseif ( $plan === 'pro' ) {
            $cap = 1000;
        } else {
            $cap = 200;
        }
        $current_length = strlen( $instructions );
        ?>
        <textarea
            name="bitbot_custom_instructions"
            rows="6"
            class="large-text"
            maxlength="<?php echo esc_attr( $cap ); ?>"
            placeholder="<?php esc_attr_e( "e.g.\n- Our WhatsApp is https://wa.me/5521995535057\n- Refunds: 30 days, no questions asked\n- Always respond in Spanish first", 'bitbot' ); ?>"
        ><?php echo esc_textarea( $instructions ); ?></textarea>
        <p class="description" style="margin-top:6px;">
            <?php
            // 1.9.1: tier display names — internal DB values stay
            // 'free' / 'pro' / 'pro_plus' for backwards compatibility,
            // but the customer-facing label is Free / Assistant / Agent.
            $plan_labels = array(
                'free'     => __( 'Free', 'bitbot' ),
                'pro'      => __( 'Assistant', 'bitbot' ),
                'pro_plus' => __( 'Agent', 'bitbot' ),
            );
            $plan_label = isset( $plan_labels[ $plan ] ) ? $plan_labels[ $plan ] : __( 'Free', 'bitbot' );
            printf(
                /* translators: %1$d current character count, %2$d allowed cap, %3$s plan name */
                esc_html__( 'Site-specific facts and behaviour notes for the bot. Used in addition to your indexed content. %1$d of %2$d characters used (%3$s plan).', 'bitbot' ),
                $current_length,
                $cap,
                esc_html( $plan_label )
            );
            ?>
        </p>
        <p class="description" style="margin-top:4px; color:#666;">
            <?php esc_html_e( 'This is added to the bot\'s instructions. It cannot override the privacy or accuracy guardrails — if a directive here conflicts with our base rules, the base rules win.', 'bitbot' ); ?>
        </p>
        <?php
    }

    /**
     * Corner radius slider (Free) + shape preset dropdown (Pro and above).
     * Free customers see only the radius slider — locks shape to 'rounded'
     * which respects the slider. Pro / Pro+ unlock alternate shapes; the
     * radius still applies as a base, and shapes layer clip-path on top.
     *
     * @since 1.9.0
     */
    public function render_appearance_field() {
        $radius   = (int) get_option( 'bitbot_corner_radius', 16 );
        $shape    = (string) get_option( 'bitbot_widget_shape', 'rounded' );
        $is_paid  = $this->is_pro();
        ?>
        <div style="display:flex; flex-direction:column; gap:20px; max-width:520px;">
            <label style="display:flex; flex-direction:column; gap:4px;">
                <span style="font-weight:500;"><?php esc_html_e( 'Corner radius', 'bitbot' ); ?></span>
                <div style="display:flex; align-items:center; gap:12px;">
                    <input
                        type="range"
                        name="bitbot_corner_radius"
                        min="0"
                        max="24"
                        step="1"
                        value="<?php echo esc_attr( $radius ); ?>"
                        oninput="document.getElementById('bitbot-radius-display').textContent = this.value + 'px'"
                        style="flex:1;"
                    >
                    <span id="bitbot-radius-display" style="font-family:monospace; min-width:40px; text-align:right;"><?php echo esc_html( $radius . 'px' ); ?></span>
                </div>
                <span class="description"><?php esc_html_e( 'Sharp / Bauhaus at 0px, fully rounded / Apple at 24px. Applies to the chat panel, FAB, and message bubbles.', 'bitbot' ); ?></span>
            </label>

            <label style="display:flex; flex-direction:column; gap:4px;">
                <span style="font-weight:500;">
                    <?php esc_html_e( 'FAB shape', 'bitbot' ); ?>
                    <?php if ( ! $is_paid ) : ?>
                        <span style="display:inline-block; background:#e9e3ff; color:#4b2bcc; padding:1px 8px; border-radius:10px; font-size:11px; font-weight:600; margin-left:6px; vertical-align:1px;"><?php esc_html_e( 'Assistant', 'bitbot' ); ?></span>
                    <?php endif; ?>
                </span>
                <select name="bitbot_widget_shape" <?php disabled( ! $is_paid ); ?>>
                    <option value="rounded" <?php selected( $shape, 'rounded' ); ?>><?php esc_html_e( 'Rounded (default — follows radius slider)', 'bitbot' ); ?></option>
                    <option value="circle" <?php selected( $shape, 'circle' ); ?>><?php esc_html_e( 'Perfect circle', 'bitbot' ); ?></option>
                    <option value="square" <?php selected( $shape, 'square' ); ?>><?php esc_html_e( 'Sharp square', 'bitbot' ); ?></option>
                    <option value="pill" <?php selected( $shape, 'pill' ); ?>><?php esc_html_e( 'Pill', 'bitbot' ); ?></option>
                    <option value="hexagon" <?php selected( $shape, 'hexagon' ); ?>><?php esc_html_e( 'Hexagon', 'bitbot' ); ?></option>
                </select>
                <?php if ( ! $is_paid ) : ?>
                    <span class="description"><?php esc_html_e( 'Alternate FAB shapes unlock on Assistant. Free tier uses the rounded slider above.', 'bitbot' ); ?></span>
                <?php else : ?>
                    <span class="description"><?php esc_html_e( 'Pick the shape of the floating chat button. "Rounded" follows the radius slider above; other shapes use a fixed clip-path.', 'bitbot' ); ?></span>
                <?php endif; ?>
            </label>
        </div>
        <?php
    }

    /**
     * Mobile-behaviour dropdown. Inherits desktop position by default;
     * admin can override to force the opposite corner on mobile OR hide
     * the widget below 480px entirely.
     *
     * @since 1.9.0
     */
    public function render_mobile_behaviour_field() {
        $value = get_option( 'bitbot_mobile_behaviour', 'inherit' );
        ?>
        <select name="bitbot_mobile_behaviour">
            <option value="inherit" <?php selected( $value, 'inherit' ); ?>><?php esc_html_e( 'Inherit desktop position (default)', 'bitbot' ); ?></option>
            <option value="bottom-right" <?php selected( $value, 'bottom-right' ); ?>><?php esc_html_e( 'Force bottom-right on mobile', 'bitbot' ); ?></option>
            <option value="bottom-left" <?php selected( $value, 'bottom-left' ); ?>><?php esc_html_e( 'Force bottom-left on mobile', 'bitbot' ); ?></option>
            <option value="hide" <?php selected( $value, 'hide' ); ?>><?php esc_html_e( 'Hide widget on mobile', 'bitbot' ); ?></option>
        </select>
        <p class="description" style="margin-top:6px;">
            <?php esc_html_e( 'How the widget behaves below 480px width. Useful if you don\'t want a chatbot on phones, or want to dodge iOS / Android bottom-bar UI by moving to the opposite corner.', 'bitbot' ); ?>
        </p>
        <?php
    }

    /**
     * Renders a textarea where the admin lists URL patterns on which
     * the widget should be hidden. One pattern per line; `*` wildcards;
     * lines starting with `#` are comments.
     *
     * @since 1.9.0
     */
    public function render_hide_on_pages_field() {
        $patterns = get_option( 'bitbot_hide_on_pages', '' );
        ?>
        <textarea
            name="bitbot_hide_on_pages"
            rows="5"
            class="large-text code"
            placeholder="<?php esc_attr_e( "/checkout\n/cart\n/account/*\n/login", 'bitbot' ); ?>"
        ><?php echo esc_textarea( $patterns ); ?></textarea>
        <p class="description" style="margin-top:6px;">
            <?php esc_html_e( 'One URL pattern per line. The widget will NOT appear on pages whose URL matches any pattern. Use `*` as a wildcard. Lines starting with `#` are comments.', 'bitbot' ); ?>
        </p>
        <p class="description" style="margin-top:4px;">
            <?php esc_html_e( 'Examples: /checkout, /cart, /account/*, /login. Chatbot on a checkout flow can hurt conversion — most stores hide it there.', 'bitbot' ); ?>
        </p>
        <?php
    }

    /**
     * Renders four free-text inputs for the visitor-facing copy bundle.
     * Empty values fall back to localized defaults at render time so
     * legacy installs see no change.
     *
     *   - Placeholder text in the input
     *   - "I don't know" fallback message (passed to backend, consumed by 8b)
     *   - Lead-capture prompt (passed to backend, consumed by 8b)
     *   - Typing indicator text (client-side, replaces dots-only animation)
     *
     * @since 1.9.0
     */
    public function render_custom_copy_fields() {
        $placeholder  = get_option( 'bitbot_placeholder_text', '' );
        $fallback     = get_option( 'bitbot_fallback_message', '' );
        $lead_prompt  = get_option( 'bitbot_lead_capture_prompt', '' );
        $typing       = get_option( 'bitbot_typing_indicator_text', '' );
        ?>
        <div style="display:flex; flex-direction:column; gap:16px; max-width:640px;">
            <label style="display:flex; flex-direction:column; gap:4px;">
                <span style="font-weight:500;"><?php esc_html_e( 'Input placeholder', 'bitbot' ); ?></span>
                <input
                    type="text"
                    name="bitbot_placeholder_text"
                    value="<?php echo esc_attr( $placeholder ); ?>"
                    class="regular-text"
                    maxlength="60"
                    placeholder="<?php esc_attr_e( 'Type your message…', 'bitbot' ); ?>"
                >
                <span class="description"><?php esc_html_e( 'The greyed-out hint inside the chat input box. Leave empty for the default.', 'bitbot' ); ?></span>
            </label>

            <label style="display:flex; flex-direction:column; gap:4px;">
                <span style="font-weight:500;"><?php esc_html_e( 'Typing indicator text', 'bitbot' ); ?></span>
                <input
                    type="text"
                    name="bitbot_typing_indicator_text"
                    value="<?php echo esc_attr( $typing ); ?>"
                    class="regular-text"
                    maxlength="40"
                    placeholder="<?php esc_attr_e( '(default animated dots)', 'bitbot' ); ?>"
                >
                <span class="description"><?php esc_html_e( 'Shown while the bot is generating a reply. e.g. "Mais IA is thinking…". Leave empty for the default animated dots.', 'bitbot' ); ?></span>
            </label>

            <label style="display:flex; flex-direction:column; gap:4px;">
                <span style="font-weight:500;"><?php esc_html_e( "\"I don't know\" fallback message", 'bitbot' ); ?></span>
                <textarea
                    name="bitbot_fallback_message"
                    rows="2"
                    class="large-text"
                    maxlength="280"
                    placeholder="<?php esc_attr_e( '(default: bot will use its own phrasing)', 'bitbot' ); ?>"
                ><?php echo esc_textarea( $fallback ); ?></textarea>
                <span class="description"><?php esc_html_e( "Shown when the bot can't answer from your indexed content. Leave empty to let the bot phrase it naturally.", 'bitbot' ); ?></span>
            </label>

            <label style="display:flex; flex-direction:column; gap:4px;">
                <span style="font-weight:500;"><?php esc_html_e( 'Lead-capture prompt', 'bitbot' ); ?></span>
                <textarea
                    name="bitbot_lead_capture_prompt"
                    rows="2"
                    class="large-text"
                    maxlength="280"
                    placeholder="<?php esc_attr_e( "(default: \"Leave your email so we can follow up\")", 'bitbot' ); ?>"
                ><?php echo esc_textarea( $lead_prompt ); ?></textarea>
                <span class="description"><?php esc_html_e( 'Phrasing the bot uses when asking visitors for an email handover. e.g. "Get a quote in 24 hours".', 'bitbot' ); ?></span>
            </label>
        </div>
        <?php
    }

    public function render_leads_section() {
        $is_paid = $this->is_pro();
        if ( $is_paid ) {
            echo '<p>' . esc_html__( 'Where BitBot should send an email when a new lead comes in. Leave blank to disable notifications.', 'bitbot' ) . '</p>';
            return;
        }

        // Free plan: lead-notification emails are gated to Pro/Pro+. Make
        // that explicit so Free users don't expect mail that never arrives.
        ?>
        <div style="padding: 12px 14px; background: #fff8e5; border: 1px solid #f0c33c; border-radius: 6px; margin-bottom: 8px;">
            <p style="margin: 0 0 6px; font-weight: 600; color: #3c2f00;">
                <?php esc_html_e( 'Email notifications are a Pro feature.', 'bitbot' ); ?>
            </p>
            <p style="margin: 0; color: #5c4a00; font-size: 13px;">
                <?php esc_html_e( 'On the Free plan, new leads are still captured and visible on your Leads dashboard — but no email is sent. Upgrade to Pro to get a real-time email every time a visitor leaves their details.', 'bitbot' ); ?>
            </p>
        </div>
        <?php
    }

    public function render_notification_email_field() {
        $email   = get_option( 'bitbot_notification_email', get_option( 'admin_email', '' ) );
        $is_paid = $this->is_pro();
        ?>
        <input
            type="email"
            name="bitbot_notification_email"
            value="<?php echo esc_attr( $email ); ?>"
            class="regular-text"
            placeholder="you@example.com"
            <?php disabled( ! $is_paid ); ?>
        >
        <p class="description">
            <?php
            if ( $is_paid ) {
                esc_html_e( 'Defaults to the WordPress admin email. You can change it any time.', 'bitbot' );
            } else {
                esc_html_e( 'Disabled on Free plan — emails are not sent. Upgrade to Pro to enable.', 'bitbot' );
            }
            ?>
        </p>
        <?php
    }

    /**
     * Sanitize + push cart-recovery config.
     *
     * @since 1.7.0
     * @param mixed $value
     * @return array
     */
    public function sanitize_recovery_config( $value ) {
        if ( ! is_array( $value ) ) {
            return (array) get_option( 'bitbot_recovery_config', array() );
        }
        $clean = array(
            'enabled'     => ! empty( $value['enabled'] ),
            'code'        => isset( $value['code'] ) ? sanitize_text_field( (string) $value['code'] ) : '',
            'description' => isset( $value['description'] ) ? sanitize_text_field( (string) $value['description'] ) : '',
        );

        $this->api_client->update_recovery_config( $clean['enabled'], $clean['code'], $clean['description'] );

        return $clean;
    }

    /**
     * @since 1.7.0
     */
    public function render_recovery_section() {
        if ( ! $this->is_pro_plus() ) {
            $this->render_proplus_gate_card(
                __( 'Cart Recovery is an Agent feature.', 'bitbot' ),
                __( 'On Agent the bot can surface a discount code to close hesitating visitors — only after real near-buy hesitation, never on the first mention of price. The settings below stay disabled until you upgrade.', 'bitbot' )
            );
            return;
        }
        ?>
        <p>
            <?php esc_html_e( 'Let the agent surface a discount code to close hesitating visitors. The agent won\'t use it on the first mention of price — only when a visitor has shown real near-buy hesitation.', 'bitbot' ); ?>
        </p>
        <?php
    }

    /**
     * @since 1.7.0
     */
    public function render_recovery_config_field() {
        $cfg          = (array) get_option( 'bitbot_recovery_config', array() );
        $enabled      = ! empty( $cfg['enabled'] );
        $code         = isset( $cfg['code'] ) ? (string) $cfg['code'] : '';
        $description  = isset( $cfg['description'] ) ? (string) $cfg['description'] : '';
        $is_pro_plus  = $this->is_pro_plus();
        $disabled_attr = $is_pro_plus ? '' : 'disabled';
        ?>
        <fieldset style="max-width: 560px; <?php echo $is_pro_plus ? '' : 'opacity: 0.55;'; ?>">
            <label style="display: block; margin-bottom: 12px;">
                <input type="checkbox" name="bitbot_recovery_config[enabled]" value="1" <?php checked( $enabled, true ); ?> <?php echo $disabled_attr; ?>>
                <strong><?php esc_html_e( 'Offer a discount code via the Agent', 'bitbot' ); ?></strong>
            </label>

            <p>
                <label style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e( 'Coupon code', 'bitbot' ); ?></label>
                <input type="text" name="bitbot_recovery_config[code]" value="<?php echo esc_attr( $code ); ?>" class="regular-text code" placeholder="SAVE10" <?php echo $disabled_attr; ?>>
                <span class="description" style="display: block; margin-top: 4px;">
                    <?php esc_html_e( 'The code you have configured in WooCommerce or your checkout. The agent will mention it verbatim.', 'bitbot' ); ?>
                </span>
            </p>

            <p>
                <label style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e( 'Offer description', 'bitbot' ); ?></label>
                <input type="text" name="bitbot_recovery_config[description]" value="<?php echo esc_attr( $description ); ?>" class="regular-text" placeholder="10% off your first order" <?php echo $disabled_attr; ?>>
                <span class="description" style="display: block; margin-top: 4px;">
                    <?php esc_html_e( 'One short phrase describing the deal. Used in the agent\'s reply.', 'bitbot' ); ?>
                </span>
            </p>
        </fieldset>
        <?php
    }

    /**
     * @since 1.7.0
     */
    public function render_appointments_section() {
        if ( ! $this->is_pro_plus() ) {
            $this->render_proplus_gate_card(
                __( 'Appointments is an Agent feature.', 'bitbot' ),
                __( 'On Agent the chatbot can take real bookings from visitors — consultations, calls, sessions — straight inside the widget. The settings below stay disabled until you upgrade.', 'bitbot' )
            );
            return;
        }
        ?>
        <p>
            <?php esc_html_e( 'Let the chatbot book appointments for you — consultations, calls, sessions, service slots.', 'bitbot' ); ?>
        </p>
        <p class="description">
            <?php esc_html_e( 'Visitors can book available slots inside the chat widget. Confirmation emails go to the visitor and to your notification email automatically.', 'bitbot' ); ?>
        </p>
        <?php
    }

    /**
     * All-in-one booking config field — grid of weekly hours + service settings.
     *
     * @since 1.7.0
     */
    public function render_appointments_config_field() {
        $cfg    = (array) get_option( 'bitbot_appointments_config', array() );
        $enabled = ! empty( $cfg['enabled'] );
        $name   = 'bitbot_appointments_config';
        $tz     = isset( $cfg['timezone'] ) ? (string) $cfg['timezone'] : 'UTC';
        $hours  = isset( $cfg['hours'] ) && is_array( $cfg['hours'] ) ? $cfg['hours'] : array();
        $days   = array(
            'mon' => __( 'Monday', 'bitbot' ),
            'tue' => __( 'Tuesday', 'bitbot' ),
            'wed' => __( 'Wednesday', 'bitbot' ),
            'thu' => __( 'Thursday', 'bitbot' ),
            'fri' => __( 'Friday', 'bitbot' ),
            'sat' => __( 'Saturday', 'bitbot' ),
            'sun' => __( 'Sunday', 'bitbot' ),
        );
        $is_pro_plus = $this->is_pro_plus();
        ?>
        <fieldset style="max-width: 640px; <?php echo $is_pro_plus ? '' : 'opacity: 0.55;'; ?>" <?php echo $is_pro_plus ? '' : 'disabled'; ?>>
            <label style="display: block; margin-bottom: 12px;">
                <input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( $enabled, true ); ?>>
                <strong><?php esc_html_e( 'Enable appointment booking', 'bitbot' ); ?></strong>
            </label>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="bitbot_appt_service_name"><?php esc_html_e( 'Service name', 'bitbot' ); ?></label></th>
                        <td>
                            <input type="text" id="bitbot_appt_service_name" name="<?php echo esc_attr( $name ); ?>[service_name]"
                                   value="<?php echo esc_attr( $cfg['service_name'] ?? '' ); ?>"
                                   class="regular-text" placeholder="30-min consultation">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bitbot_appt_duration"><?php esc_html_e( 'Duration (minutes)', 'bitbot' ); ?></label></th>
                        <td>
                            <input type="number" id="bitbot_appt_duration" name="<?php echo esc_attr( $name ); ?>[duration_minutes]"
                                   value="<?php echo esc_attr( (string) ( $cfg['duration_minutes'] ?? 30 ) ); ?>" min="5" max="240" step="5" class="small-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bitbot_appt_buffer"><?php esc_html_e( 'Buffer between slots (minutes)', 'bitbot' ); ?></label></th>
                        <td>
                            <input type="number" id="bitbot_appt_buffer" name="<?php echo esc_attr( $name ); ?>[buffer_minutes]"
                                   value="<?php echo esc_attr( (string) ( $cfg['buffer_minutes'] ?? 15 ) ); ?>" min="0" max="120" step="5" class="small-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bitbot_appt_tz"><?php esc_html_e( 'Timezone', 'bitbot' ); ?></label></th>
                        <td>
                            <input type="text" id="bitbot_appt_tz" name="<?php echo esc_attr( $name ); ?>[timezone]"
                                   value="<?php echo esc_attr( $tz ); ?>" class="regular-text" placeholder="Australia/Sydney">
                            <p class="description">
                                <?php esc_html_e( 'IANA name like Australia/Sydney, Europe/London, America/New_York. See https://en.wikipedia.org/wiki/List_of_tz_database_time_zones', 'bitbot' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bitbot_appt_lead"><?php esc_html_e( 'Minimum lead time (hours)', 'bitbot' ); ?></label></th>
                        <td>
                            <input type="number" id="bitbot_appt_lead" name="<?php echo esc_attr( $name ); ?>[lead_time_hours]"
                                   value="<?php echo esc_attr( (string) ( $cfg['lead_time_hours'] ?? 2 ) ); ?>" min="0" max="168" class="small-text">
                            <p class="description"><?php esc_html_e( 'Slots start at least this many hours from now.', 'bitbot' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bitbot_appt_ahead"><?php esc_html_e( 'Max days ahead', 'bitbot' ); ?></label></th>
                        <td>
                            <input type="number" id="bitbot_appt_ahead" name="<?php echo esc_attr( $name ); ?>[max_days_ahead]"
                                   value="<?php echo esc_attr( (string) ( $cfg['max_days_ahead'] ?? 14 ) ); ?>" min="1" max="60" class="small-text">
                        </td>
                    </tr>
                </tbody>
            </table>

            <h4 style="margin-top: 24px;"><?php esc_html_e( 'Weekly hours', 'bitbot' ); ?></h4>
            <p class="description">
                <?php esc_html_e( 'Check days you\'re available, then set the start and end time. Times are in the timezone you chose above.', 'bitbot' ); ?>
            </p>

            <table class="form-table" role="presentation" style="max-width: 480px;">
                <tbody>
                <?php foreach ( $days as $day_key => $day_label ) :
                    $row          = isset( $hours[ $day_key ] ) && is_array( $hours[ $day_key ] ) ? $hours[ $day_key ] : array();
                    $day_enabled  = ! empty( $row['enabled'] );
                    $start        = isset( $row['start'] ) ? (string) $row['start'] : '09:00';
                    $end          = isset( $row['end'] ) ? (string) $row['end'] : '17:00';
                ?>
                    <tr>
                        <th scope="row" style="width: 120px;">
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( $name ); ?>[hours][<?php echo esc_attr( $day_key ); ?>][enabled]" value="1" <?php checked( $day_enabled, true ); ?>>
                                <?php echo esc_html( $day_label ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="time" name="<?php echo esc_attr( $name ); ?>[hours][<?php echo esc_attr( $day_key ); ?>][start]"
                                   value="<?php echo esc_attr( $start ); ?>" <?php echo $day_enabled ? '' : 'disabled'; ?>>
                            <span style="margin: 0 8px;">→</span>
                            <input type="time" name="<?php echo esc_attr( $name ); ?>[hours][<?php echo esc_attr( $day_key ); ?>][end]"
                                   value="<?php echo esc_attr( $end ); ?>" <?php echo $day_enabled ? '' : 'disabled'; ?>>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </fieldset>
        <script>
        // Enable time inputs when the day checkbox flips on.
        document.addEventListener('change', function (e) {
            if (!e.target.matches('input[name^="<?php echo esc_js( $name ); ?>[hours]"][type="checkbox"]')) return;
            var inputs = e.target.closest('tr').querySelectorAll('input[type="time"]');
            inputs.forEach(function (i) { i.disabled = !e.target.checked; });
        });
        </script>
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
     * Sanitize the widget title input. Strips HTML, collapses whitespace,
     * caps at 40 chars (UI also enforces via maxlength, but defend in
     * depth in case the input is submitted programmatically).
     *
     * Empty string is a valid value — it tells the renderer to fall back
     * to the localized "Chat with us" default.
     *
     * @since 1.9.0
     * @param string $value Raw input.
     * @return string Sanitized title (empty string allowed).
     */
    public function sanitize_widget_title( $value ) {
        $value = sanitize_text_field( (string) $value );
        // Collapse runs of whitespace to a single space so a copy-paste
        // with embedded newlines doesn't render as a multi-line header.
        $value = preg_replace( '/\s+/u', ' ', $value );
        $value = trim( $value );
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $value, 0, 40 );
        }
        return substr( $value, 0, 40 );
    }

    /**
     * Sanitize the mobile-behaviour dropdown. Falls back to "inherit"
     * for any unrecognised value.
     *
     * @since 1.9.0
     */
    public function sanitize_mobile_behaviour( $value ) {
        $allowed = array( 'inherit', 'bottom-right', 'bottom-left', 'hide' );
        $value   = sanitize_text_field( (string) $value );
        return in_array( $value, $allowed, true ) ? $value : 'inherit';
    }

    /**
     * Clamp the corner radius to 0-32px. Anything outside the range falls
     * back to the default 32 (full circle on the 56x56 FAB).
     *
     * @since 1.9.0  range 0-24, default 16
     * @since 2.0.2  range extended to 0-32, default 32 to match the
     *               React slider + ship a fully-rounded FAB by default
     */
    public function sanitize_corner_radius( $value ) {
        $value = (int) $value;
        if ( $value < 0 || $value > 32 ) {
            return 32;
        }
        return $value;
    }

    /**
     * Sanitize off-topic guardrails. Each non-empty line becomes a topic
     * the bot refuses. Free tier silently locks to empty.
     *
     * @since 1.9.0 Feature 8l
     */
    public function sanitize_off_topic_guardrails( $value ) {
        // 2.0.2: chatbot-tab customisation is free on every plan, so the
        // old is_pro() short-circuit (which silently blanked the field
        // and pushed an empty string to backend) is gone. Every site
        // saves its actual value.
        $value = (string) $value;
        $value = wp_strip_all_tags( $value );
        $value = str_replace( array( "\r\n", "\r" ), "\n", $value );
        // Cap each line to 80 chars (topics should be brief) and total
        // entries to 20 (otherwise the system prompt bloats).
        $lines = array();
        foreach ( explode( "\n", $value ) as $line ) {
            $line = sanitize_text_field( trim( $line ) );
            if ( $line === '' ) {
                continue;
            }
            $lines[] = mb_substr( $line, 0, 80 );
            if ( count( $lines ) >= 20 ) {
                break;
            }
        }
        $cleaned = implode( "\n", $lines );

        // Fire-and-forget push to backend (same pattern as instructions).
        if ( $this->api_client ) {
            $this->api_client->update_guardrails( $cleaned );
        }

        return $cleaned;
    }

    /**
     * Sanitize pinned-message config. Any paid tier (Assistant or Agent);
     * Free silently zeroes.
     *
     * @since 1.9.0 Feature 8h
     */
    public function sanitize_pinned_message( $value ) {
        // 2.0.2: chatbot-tab customisation is free on every plan.
        // Previously this wiped to empty on Free, which made the React
        // textarea look like it rejected input (save → silent blank →
        // refetch returns blank → textarea reverts).
        $raw = (string) $value;
        if ( $raw === '' ) return '';
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return '';
        $text = isset( $decoded['text'] ) ? sanitize_text_field( (string) $decoded['text'] ) : '';
        $lbl  = isset( $decoded['ctaLabel'] ) ? sanitize_text_field( (string) $decoded['ctaLabel'] ) : '';
        $url  = isset( $decoded['ctaUrl'] ) ? esc_url_raw( (string) $decoded['ctaUrl'] ) : '';
        if ( function_exists( 'mb_substr' ) ) {
            $text = mb_substr( $text, 0, 200 );
            $lbl  = mb_substr( $lbl, 0, 40 );
        }
        if ( $text === '' ) {
            return '';
        }
        return wp_json_encode( array(
            'text'     => $text,
            'ctaLabel' => $lbl,
            'ctaUrl'   => $url,
        ) );
    }

    /**
     * Sanitize proactive nudge config. Pro / Pro+ only. RAG mode requires
     * Pro+; on Pro it's silently downgraded to 'simple'.
     *
     * @since 1.9.0 Feature 8f
     */
    public function sanitize_nudge( $value ) {
        // 2.0.2: chatbot-tab customisation is free on every plan. The
        // old is_pro() wipe blanked the JSON on every keystroke, so
        // the React editor toggles couldn't stay on.
        $raw = (string) $value;
        if ( $raw === '' ) {
            return '';
        }
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return '';
        }
        $enabled = ! empty( $decoded['enabled'] );
        $mode    = isset( $decoded['mode'] ) ? (string) $decoded['mode'] : 'simple';
        $delay   = isset( $decoded['delaySeconds'] ) ? (int) $decoded['delaySeconds'] : 8;
        $simple  = isset( $decoded['simpleText'] ) ? sanitize_text_field( (string) $decoded['simpleText'] ) : '';
        $pages   = isset( $decoded['pageRules'] ) ? sanitize_textarea_field( (string) $decoded['pageRules'] ) : '';

        $allowed_modes = array( 'simple', 'page-aware', 'rag' );
        if ( ! in_array( $mode, $allowed_modes, true ) ) {
            $mode = 'simple';
        }
        // 1.9.1: rag variant unlocked for any paid tier (Assistant or Agent).
        // The outer `is_pro()` gate at the top of this method already drops
        // Free saves to empty, so by this point we know it's a paid tier.

        $delay  = max( 2, min( 60, $delay ) );
        $simple = function_exists( 'mb_substr' ) ? mb_substr( $simple, 0, 80 ) : substr( $simple, 0, 80 );
        if ( strlen( $pages ) > 3000 ) {
            $pages = substr( $pages, 0, 3000 );
        }

        return wp_json_encode( array(
            'enabled'      => $enabled,
            'mode'         => $mode,
            'delaySeconds' => $delay,
            'simpleText'   => $simple,
            'pageRules'    => $pages,
        ) );
    }

    /**
     * Sanitize the auto-open trigger config. Pro / Pro+ only. Stored as
     * a JSON object with bounded numeric ranges. Free silently zeroes.
     *
     * @since 1.9.0 Feature 8g
     */
    public function sanitize_auto_open( $value ) {
        // 2.0.2: chatbot-tab customisation is free on every plan.
        $raw = (string) $value;
        if ( $raw === '' ) {
            return '';
        }
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return '';
        }
        $url_patterns = isset( $decoded['urlPatterns'] ) ? (string) $decoded['urlPatterns'] : '';
        $url_patterns = sanitize_textarea_field( $url_patterns );
        if ( strlen( $url_patterns ) > 2000 ) {
            $url_patterns = substr( $url_patterns, 0, 2000 );
        }
        $delay  = isset( $decoded['delaySeconds'] ) ? (int) $decoded['delaySeconds'] : 0;
        $scroll = isset( $decoded['scrollPercent'] ) ? (int) $decoded['scrollPercent'] : 0;
        $exit   = ! empty( $decoded['exitIntent'] );

        $delay  = max( 0, min( 300, $delay ) );
        $scroll = max( 0, min( 100, $scroll ) );

        // If nothing's actually configured, store empty for simpler
        // widget-side checks.
        if ( $url_patterns === '' && $delay === 0 && $scroll === 0 && ! $exit ) {
            return '';
        }

        return wp_json_encode( array(
            'urlPatterns'   => $url_patterns,
            'delaySeconds'  => $delay,
            'scrollPercent' => $scroll,
            'exitIntent'    => $exit,
        ) );
    }

    /**
     * Sanitize suggested-questions repeater. Accepts a JSON string;
     * filters items down to valid {label, message} shape, enforces
     * Free/Pro count cap, length-caps each field. Stores back as JSON.
     *
     * @since 1.9.0 Feature 8e
     */
    public function sanitize_suggested_questions( $value ) {
        $raw = (string) $value;
        if ( $raw === '' ) {
            return '';
        }
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return '';
        }
        // 2.0.2: chatbot-tab customisation is free on every plan, so the
        // 3-chip Free cap is lifted. Everyone gets 8.
        $cap   = 8;
        $clean = array();
        foreach ( $decoded as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $label   = isset( $item['label'] ) ? sanitize_text_field( (string) $item['label'] ) : '';
            $message = isset( $item['message'] ) ? sanitize_text_field( (string) $item['message'] ) : '';
            if ( function_exists( 'mb_substr' ) ) {
                $label   = mb_substr( $label, 0, 40 );
                $message = mb_substr( $message, 0, 200 );
            } else {
                $label   = substr( $label, 0, 40 );
                $message = substr( $message, 0, 200 );
            }
            if ( $label === '' && $message === '' ) {
                continue;
            }
            // Default each to the other if only one is filled.
            if ( $label === '' )   { $label = $message; }
            if ( $message === '' ) { $message = $label; }
            $clean[] = array( 'label' => $label, 'message' => $message );
            if ( count( $clean ) >= $cap ) {
                break;
            }
        }
        if ( empty( $clean ) ) {
            return '';
        }
        return wp_json_encode( $clean );
    }

    /**
     * Sanitize the custom avatar URL. Must be HTTP(S), must end in an
     * allowed image extension. Free tier rejects animated (.svg, .lottie,
     * .json) — silently strips back to empty if the URL extension matches
     * a paid-only format.
     *
     * @since 1.9.0 Feature 8d
     */
    public function sanitize_avatar_url( $value ) {
        $url = esc_url_raw( trim( (string) $value ) );
        if ( $url === '' ) {
            return '';
        }
        // Must be HTTPS in production; allow http for dev/localhost only.
        $scheme = parse_url( $url, PHP_URL_SCHEME );
        if ( $scheme !== 'https' && $scheme !== 'http' ) {
            return '';
        }
        $path  = parse_url( $url, PHP_URL_PATH ) ?: '';
        $lower = strtolower( $path );
        // File-extension whitelist.
        $free_exts = array( '.png', '.jpg', '.jpeg', '.webp' );
        $paid_exts = array( '.svg', '.lottie', '.json' );
        $is_free_ext = false;
        $is_paid_ext = false;
        foreach ( $free_exts as $ext ) {
            if ( substr( $lower, -strlen( $ext ) ) === $ext ) {
                $is_free_ext = true;
                break;
            }
        }
        if ( ! $is_free_ext ) {
            foreach ( $paid_exts as $ext ) {
                if ( substr( $lower, -strlen( $ext ) ) === $ext ) {
                    $is_paid_ext = true;
                    break;
                }
            }
        }
        if ( ! $is_free_ext && ! $is_paid_ext ) {
            return '';
        }
        if ( $is_paid_ext && ! $this->is_pro() ) {
            // Paid extension on Free → reject silently.
            return '';
        }
        return $url;
    }

    /**
     * Sanitize the avatar crop transform. Stored as a JSON string
     * shaped `{ xPercent, yPercent, zoom }`:
     *   - xPercent, yPercent: -100..100 (translate relative to img size)
     *   - zoom: 1..4 (clamp to a sane range — anything > 4 just blurs)
     * Returns '' if shape is invalid; widget then renders the avatar
     * with its default centered, 1× transform.
     *
     * @since 2.0.2
     */
    public function sanitize_avatar_transform( $value ) {
        $raw = (string) $value;
        if ( $raw === '' ) return '';
        $cfg = json_decode( $raw, true );
        if ( ! is_array( $cfg ) ) return '';
        $shape_in = isset( $cfg['shape'] ) ? (string) $cfg['shape'] : 'rounded';
        $shape    = in_array( $shape_in, array( 'rounded', 'pill' ), true ) ? $shape_in : 'rounded';
        // 2.0.2 (refactor): new shape stores source-pixel crop rect
        // + source natural dimensions. Renderers use these to compute
        // background-size / background-position. Legacy
        // { xPercent, yPercent, zoom } values are dropped — the
        // widget falls back to plain object-fit cover, and the admin
        // gets to re-crop with the new flow next time.
        if (
            ! isset( $cfg['cropW'], $cfg['cropH'], $cfg['sourceW'], $cfg['sourceH'] )
            || ! is_numeric( $cfg['cropW'] ) || ! is_numeric( $cfg['cropH'] )
            || ! is_numeric( $cfg['sourceW'] ) || ! is_numeric( $cfg['sourceH'] )
        ) {
            return '';
        }
        $cropW   = max( 1, (int) $cfg['cropW'] );
        $cropH   = max( 1, (int) $cfg['cropH'] );
        $sourceW = max( 1, (int) $cfg['sourceW'] );
        $sourceH = max( 1, (int) $cfg['sourceH'] );
        $cropX   = max( 0, (int) ( $cfg['cropX'] ?? 0 ) );
        $cropY   = max( 0, (int) ( $cfg['cropY'] ?? 0 ) );
        // Constrain crop rect to stay inside the source bounds.
        $cropX = min( $cropX, max( 0, $sourceW - $cropW ) );
        $cropY = min( $cropY, max( 0, $sourceH - $cropH ) );
        return wp_json_encode( array(
            'shape'   => $shape,
            'cropX'   => $cropX,
            'cropY'   => $cropY,
            'cropW'   => $cropW,
            'cropH'   => $cropH,
            'sourceW' => $sourceW,
            'sourceH' => $sourceH,
        ) );
    }

    /**
     * Sanitize custom instructions + push to backend so the agent picks
     * up the new prompt on the next chat call. Length cap applied
     * server-side (Site.updateInstructions) — we don't enforce here.
     *
     * @since 1.9.0 Feature 8b
     */
    public function sanitize_custom_instructions( $value ) {
        $value = (string) $value;
        // Strip basic HTML to defend against pasted markdown that the
        // backend would otherwise have to re-strip. Backend's prompt
        // assembler also slips this through but defence-in-depth.
        $value = wp_strip_all_tags( $value );
        $value = trim( $value );

        // Fire-and-forget push to backend. We don't block the Settings
        // save on the network call — if it fails, the next admin save
        // will retry (sanitize is invoked on every save), and the user's
        // local option value is the source of truth in WP-admin.
        if ( $this->api_client ) {
            $this->api_client->update_instructions( $value );
        }

        return $value;
    }

    /**
     * Sanitize the widget shape dropdown. Free tier locks to 'rounded' —
     * if a Free site somehow submits a Pro shape (form-tamper or downgrade
     * from Pro), revert silently to 'rounded'.
     *
     * @since 1.9.0
     */
    public function sanitize_widget_shape( $value ) {
        // 'circle', 'square', 'hexagon' stay in the allow-list for
        // backward compatibility — sites that saved them before 2.0.2
        // keep rendering as before. The picker now only offers 'rounded'
        // (slider-controlled, covers circle ↔ square) and 'pill'.
        // Invalid values default to 'rounded'.
        $allowed = array( 'rounded', 'circle', 'square', 'pill', 'hexagon' );
        $value   = sanitize_text_field( (string) $value );
        return in_array( $value, $allowed, true ) ? $value : 'rounded';
    }

    /**
     * Sanitize the hide-on-pages textarea. Strips HTML per line, normalises
     * line endings, drops empty lines (but keeps comments). Caps total
     * length to defend against pathological inputs.
     *
     * @since 1.9.0
     */
    public function sanitize_hide_on_pages( $value ) {
        $value = (string) $value;
        // Normalise CRLF / CR → LF so the regex matcher sees consistent input.
        $value = str_replace( array( "\r\n", "\r" ), "\n", $value );
        $lines = explode( "\n", $value );
        $clean = array();
        foreach ( $lines as $line ) {
            $line = sanitize_text_field( $line );
            // Allow empty separator lines for readability; drop runs of >1.
            if ( $line === '' && end( $clean ) === '' ) {
                continue;
            }
            $clean[] = $line;
        }
        $joined = implode( "\n", $clean );
        // Cap total length — 5000 chars is way more lines than anyone needs.
        if ( strlen( $joined ) > 5000 ) {
            $joined = substr( $joined, 0, 5000 );
        }
        return $joined;
    }

    /**
     * Shared sanitizer for the short single-line copy fields in the
     * custom copy bundle (placeholder, typing indicator). Strips HTML,
     * collapses whitespace, caps at 60 chars. Empty string is valid.
     *
     * @since 1.9.0
     */
    public function sanitize_short_copy( $value ) {
        $value = sanitize_text_field( (string) $value );
        $value = preg_replace( '/\s+/u', ' ', $value );
        $value = trim( $value );
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $value, 0, 60 );
        }
        return substr( $value, 0, 60 );
    }

    /**
     * Clamp widget offset to a sane range. Negative values would push
     * the widget off-screen; very large values too. 200px is the upper
     * bound -- past that the owner has bigger problems than collision
     * avoidance.
     *
     * @param mixed $value
     * @return int
     */
    public function sanitize_widget_offset( $value ) {
        $n = (int) $value;
        if ( $n < 0 ) {
            return 0;
        }
        if ( $n > 200 ) {
            return 200;
        }
        return $n;
    }

    /**
     * Feature 9 (v2.0.0): GET /wp-json/bitbot/v1/admin-settings
     * Returns every Feature 8 option key as JSON for the React admin SPA
     * to render its Chatbot tab.
     *
     * Read-only; write is on the POST endpoint below.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_get_admin_settings( $request ) {
        // Decode JSON-encoded options so React doesn't have to re-parse.
        $suggested_raw = (string) get_option( 'bitbot_suggested_questions', '' );
        $suggested     = array();
        if ( $suggested_raw !== '' ) {
            $decoded = json_decode( $suggested_raw, true );
            if ( is_array( $decoded ) ) {
                $suggested = $decoded;
            }
        }

        return new WP_REST_Response( array(
            'enabled'              => (bool)   get_option( 'bitbot_enabled', true ),
            'widgetTitle'          => (string) get_option( 'bitbot_widget_title', '' ),
            'widgetPosition'       => (string) get_option( 'bitbot_widget_position', 'bottom-right' ),
            'widgetOffsetX'        => (int)    get_option( 'bitbot_widget_offset_x', 20 ),
            'widgetOffsetY'        => (int)    get_option( 'bitbot_widget_offset_y', 20 ),
            'primaryColor'         => (string) get_option( 'bitbot_primary_color', '#0073aa' ),
            'welcomeMessage'       => (string) get_option( 'bitbot_welcome_message', '' ),
            'customInstructions'   => (string) get_option( 'bitbot_custom_instructions', '' ),
            'placeholderText'      => (string) get_option( 'bitbot_placeholder_text', '' ),
            'fallbackMessage'      => (string) get_option( 'bitbot_fallback_message', '' ),
            'leadCapturePrompt'    => (string) get_option( 'bitbot_lead_capture_prompt', '' ),
            'typingIndicatorText'  => (string) get_option( 'bitbot_typing_indicator_text', '' ),
            'hideOnPages'          => (string) get_option( 'bitbot_hide_on_pages', '' ),
            'mobileBehaviour'      => (string) get_option( 'bitbot_mobile_behaviour', 'inherit' ),
            'cornerRadius'         => (int)    get_option( 'bitbot_corner_radius', 32 ),
            'widgetShape'          => (string) get_option( 'bitbot_widget_shape', 'rounded' ),
            'suggestedQuestions'   => $suggested,
            'avatarUrl'            => (string) get_option( 'bitbot_avatar_url', '' ),
            'avatarTransform'      => (string) get_option( 'bitbot_avatar_transform', '' ),
            'nudge'                => (string) get_option( 'bitbot_nudge', '' ),
            'autoOpen'             => (string) get_option( 'bitbot_auto_open', '' ),
            'chatGateFormId'       => (string) get_option( 'bitbot_chat_gate_form_id', '' ),
            'chatGateEnabled'      => get_option( 'bitbot_chat_gate_enabled' ) === '1',
            'pinnedMessage'        => (string) get_option( 'bitbot_pinned_message', '' ),
            'offTopicGuardrails'   => (string) get_option( 'bitbot_off_topic_guardrails', '' ),
            'notificationEmail'    => (string) get_option( 'bitbot_notification_email', '' ),
        ), 200 );
    }

    /**
     * Feature 9 (v2.0.0): POST /wp-json/bitbot/v1/admin-settings
     * Accepts a partial JSON body; for each known key calls update_option()
     * — which triggers the existing registered sanitize callbacks, including
     * backend RPC side effects (UpdateInstructions, UpdateGuardrails).
     *
     * Unknown keys are silently ignored — defends against schema drift.
     * Per-key sanitize is the authority; this method does no validation.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_save_admin_settings( $request ) {
        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            return new WP_REST_Response( array( 'success' => false, 'error' => 'Body must be a JSON object' ), 400 );
        }

        // Map React-side camelCase keys to WP option_name keys. Anything
        // not in this map is silently ignored.
        $option_map = array(
            'enabled'              => 'bitbot_enabled',
            'widgetTitle'          => 'bitbot_widget_title',
            'widgetPosition'       => 'bitbot_widget_position',
            'widgetOffsetX'        => 'bitbot_widget_offset_x',
            'widgetOffsetY'        => 'bitbot_widget_offset_y',
            'primaryColor'         => 'bitbot_primary_color',
            'welcomeMessage'       => 'bitbot_welcome_message',
            'customInstructions'   => 'bitbot_custom_instructions',
            'placeholderText'      => 'bitbot_placeholder_text',
            'fallbackMessage'      => 'bitbot_fallback_message',
            'leadCapturePrompt'    => 'bitbot_lead_capture_prompt',
            'typingIndicatorText'  => 'bitbot_typing_indicator_text',
            'hideOnPages'          => 'bitbot_hide_on_pages',
            'mobileBehaviour'      => 'bitbot_mobile_behaviour',
            'cornerRadius'         => 'bitbot_corner_radius',
            'widgetShape'          => 'bitbot_widget_shape',
            'avatarUrl'            => 'bitbot_avatar_url',
            'avatarTransform'      => 'bitbot_avatar_transform',
            'nudge'                => 'bitbot_nudge',
            'autoOpen'             => 'bitbot_auto_open',
            'pinnedMessage'        => 'bitbot_pinned_message',
            'offTopicGuardrails'   => 'bitbot_off_topic_guardrails',
            'notificationEmail'    => 'bitbot_notification_email',
        );

        $saved = array();
        foreach ( $option_map as $api_key => $option_name ) {
            if ( ! array_key_exists( $api_key, $body ) ) continue;
            // Sanitize callbacks expect the raw shape — fire as-is. The
            // registered callbacks handle their own sanitization.
            update_option( $option_name, $body[ $api_key ] );
            $saved[] = $api_key;

            // 2.0.146: notification_email is one of the few settings
            // that's mirrored to the backend (sites.notification_email).
            // The legacy WP Settings API path called the api_client sync
            // via the sanitize_callback. The REST path didn't — so the
            // React admin UI would show "Emailing amit+test@…" while the
            // backend DB still held the old admin_email and Lead
            // Notifications kept emailing the old address. Fix: sync
            // here too so the React save matches the legacy form save.
            if ( 'notificationEmail' === $api_key ) {
                $clean_email = sanitize_email( (string) $body[ $api_key ] );
                if ( $clean_email || '' === (string) $body[ $api_key ] ) {
                    $this->api_client->update_notification_email( $clean_email );
                }
            }
        }

        // Suggested questions is stored as a JSON string in WP options
        // but exchanged as an array via the REST API for ergonomics.
        if ( array_key_exists( 'suggestedQuestions', $body ) && is_array( $body['suggestedQuestions'] ) ) {
            update_option( 'bitbot_suggested_questions', wp_json_encode( $body['suggestedQuestions'] ) );
            $saved[] = 'suggestedQuestions';
        }

        return new WP_REST_Response( array(
            'success' => true,
            'saved'   => $saved,
        ), 200 );
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

        // Kick off a resumable BACKGROUND sync and return immediately. The
        // crawl runs across many tiny wp-cron ticks (one batch each), so a
        // large site can't fatal the request and the admin can close the tab.
        // The SPA polls bitbot_sync_progress for live "X / Y" status.
        $started = BitBot::get_instance()->start_background_sync();

        wp_send_json_success(array(
            'state'        => $started['total'] > 0 ? 'running' : 'done',
            'total'        => $started['total'],
            'totalBatches' => $started['totalBatches'],
            'pagesQueued'  => $started['total'],
        ));
    }

    /**
     * AJAX: stop the active bulk sync.
     */
    public function ajax_sync_stop() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        wp_send_json_success(BitBot::get_instance()->stop_background_sync());
    }

    /**
     * AJAX: return live bulk-sync progress for the admin SPA to poll.
     */
    public function ajax_sync_progress() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $progress = get_option(BitBot::SYNC_PROGRESS_OPTION, null);

        if (!is_array($progress)) {
            // No bulk sync has ever run on this install.
            wp_send_json_success(array('state' => 'idle'));
        }

        // Self-heal: while a run is active, every poll nudges wp-cron so the
        // chain keeps advancing even on a zero-traffic site (where wp-cron
        // would otherwise only fire on a front-end visit). The SPA polls every
        // ~2.5s, so this keeps the counter moving without a system cron.
        if (isset($progress['state']) && $progress['state'] === 'running' && function_exists('spawn_cron')) {
            spawn_cron();
        }

        $total = isset($progress['total']) ? (int) $progress['total'] : 0;
        $done  = isset($progress['done']) ? (int) $progress['done'] : 0;
        $pct   = $total > 0 ? (int) floor(min($done, $total) / $total * 100) : 0;

        wp_send_json_success(array(
            'state'   => isset($progress['state']) ? $progress['state'] : 'idle',
            'done'    => min($done, $total),
            'total'   => $total,
            'percent' => $pct,
            'error'   => isset($progress['error']) ? $progress['error'] : null,
        ));
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
     * AJAX handler for "Edit in WordPress" — pushes a generated draft
     * into wp_posts as a real WP_Post with post_status='draft'. The
     * publish/schedule lifecycle lives in WordPress itself; BitBot
     * only creates the draft and links to the WP edit screen.
     *
     * After wp_insert_post() succeeds we round-trip the wp_post_id
     * back to the BitBot backend via LinkDraftToWp so subsequent
     * loads of the draft can deep-link to the WP edit screen.
     */
    public function ajax_publish_draft() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $draft_id = isset( $_POST['draft_id'] ) ? sanitize_text_field( wp_unslash( $_POST['draft_id'] ) ) : '';

        if ( '' === $draft_id ) {
            wp_send_json_error( array( 'error' => 'draft_id is required' ) );
        }

        $result = $this->api_client->create_wp_post_from_draft( $draft_id );

        if ( ! $result['success'] ) {
            wp_send_json_error( array( 'error' => $result['error'] ) );
        }

        // Round-trip wp_post_id back to BitBot. We log but don't fail
        // the whole flow if the sync errors — the post is already in
        // WP; the dashboard will re-fetch on next list view.
        $link = $this->api_client->link_draft_to_wp(
            $draft_id,
            (int) $result['data']['post_id'],
            'draft'
        );
        if ( empty( $link['success'] ) ) {
            error_log( '[BitBot] LinkDraftToWp failed for draft ' . $draft_id . ': ' . ( $link['error'] ?? 'unknown' ) );
        }

        wp_send_json_success( $result['data'] );
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
     * save_post hook — round-trip WP post-status changes to BitBot
     * whenever a post that originated from a generated draft is saved.
     *
     * Triggers on insert and every update (publish, schedule, revert
     * to draft, trash). We read the _bitbot_draft_id meta we stamped
     * in create_wp_post_from_draft and call link_draft_to_wp on the
     * API client so the dashboard's Drafts list reflects current state.
     *
     * Skipped for autosaves, revisions, and non-post post types.
     *
     * @since 2.0.0
     * @param int     $post_id
     * @param WP_Post $post
     * @param bool    $update
     */
    public function sync_bitbot_post_state( $post_id, $post, $update = false ) {
        unset( $update ); // signature compliance only; we don't branch on it

        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( ! ( $post instanceof WP_Post ) || $post->post_type !== 'post' ) {
            return;
        }
        $draft_id = get_post_meta( $post_id, '_bitbot_draft_id', true );
        if ( empty( $draft_id ) ) {
            return; // not a BitBot-originated post; nothing to sync
        }

        // Map WP post_status to the dashboard's narrower vocabulary.
        // Anything we don't explicitly recognise (e.g. private, pending)
        // falls back to 'draft' since the dashboard only displays the
        // four statuses below.
        $wp_status = $post->post_status;
        if ( 'publish' === $wp_status ) {
            $dashboard_status = 'published';
        } elseif ( 'future' === $wp_status ) {
            $dashboard_status = 'scheduled';
        } elseif ( 'trash' === $wp_status ) {
            $dashboard_status = 'draft'; // bot still has the body; admin
                                          // can re-edit/re-push from BitBot
        } else {
            $dashboard_status = 'draft';
        }

        if ( $this->api_client ) {
            $resp = $this->api_client->link_draft_to_wp(
                $draft_id,
                (int) $post_id,
                $dashboard_status
            );
            if ( empty( $resp['success'] ) ) {
                error_log( '[BitBot] sync_bitbot_post_state failed for draft ' . $draft_id . ': ' . ( $resp['error'] ?? 'unknown' ) );
            }
        }
    }

    /**
     * before_delete_post hook — fires when a WP post is permanently
     * deleted (Trash → Delete Permanently, or wp_delete_post directly).
     * save_post does NOT fire for permanent deletes, so we need this
     * companion hook to clear the wp_post_id association on the
     * BitBot side. Otherwise the dashboard keeps offering an
     * "Edit in WordPress ↗" link that 404s.
     *
     * @since 2.0.0
     * @param int $post_id
     */
    public function unlink_bitbot_post_on_delete( $post_id ) {
        $draft_id = get_post_meta( $post_id, '_bitbot_draft_id', true );
        if ( empty( $draft_id ) ) {
            return; // not a BitBot-originated post
        }
        if ( $this->api_client ) {
            $resp = $this->api_client->unlink_draft_from_wp( $draft_id );
            if ( empty( $resp['success'] ) ) {
                error_log( '[BitBot] unlink_bitbot_post_on_delete failed for draft ' . $draft_id . ': ' . ( $resp['error'] ?? 'unknown' ) );
            }
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

        // Tier comes from the new Upgrade page's per-card CTA. Legacy
        // 'Upgrade to Pro' buttons (top banner, Plan card hero) don't pass
        // it, so we default to 'pro' which preserves existing behaviour.
        $tier = isset( $_POST['tier'] ) ? sanitize_text_field( wp_unslash( $_POST['tier'] ) ) : 'pro';
        if ( $tier !== 'pro' && $tier !== 'pro_plus' ) {
            $tier = 'pro';
        }

        $result = $this->api_client->create_checkout( $tier );

        if ($result['success']) {
            // Two response shapes from the backend:
            //   1. { checkoutUrl } — new subscription path. Plugin redirects.
            //   2. { tierUpdated: true, tier } — existing subscription was
            //      swapped to the new tier. Plugin shows success + bounces
            //      to the dashboard. The DB has already been updated server-
            //      side so we bust the local cache here too.
            $data = $result['data'];
            if ( ! empty( $data['tierUpdated'] ) ) {
                $this->bust_plan_status_cache();
                wp_send_json_success( array(
                    'tierUpdated' => true,
                    'tier'        => $data['tier'] ?? $tier,
                ) );
            } else {
                wp_send_json_success(array(
                    'checkoutUrl' => $data['checkoutUrl'],
                ));
            }
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
            $this->bust_plan_status_cache();
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
            $this->bust_plan_status_cache();
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

        $page      = isset($_POST['page']) ? max(1, absint($_POST['page'])) : 1;
        $page_size = isset($_POST['pageSize']) ? max(1, min(200, absint($_POST['pageSize']))) : 50;

        $result = $this->api_client->get_conversations($page, $page_size);

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
            <?php $this->render_plan_banner(); ?>

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
                function escHtmlLocal(s) { return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
                function renderFormError(errMsg) {
                    // Free-plan form cap: render an upgrade card so the
                    // owner knows exactly why save was blocked + how to lift.
                    if (/Free plan includes \d+ form/i.test(errMsg)) {
                        var dashUrl = '<?php echo esc_url( admin_url( "admin.php?page=bitbot-upgrade" ) ); ?>';
                        $('#bitbot-create-status').html(
                            '<div style="padding:14px 16px;background:#fff8e5;border:1px solid #f0c33c;border-radius:6px;">' +
                            '<p style="margin:0 0 8px;font-weight:600;color:#3c2f00;">You\'ve reached the Free plan form limit.</p>' +
                            '<p style="margin:0 0 12px;color:#5c4a00;font-size:13px;">Free includes 1 form. Upgrade to Pro for unlimited forms — plus email lead notifications and AI lead research.</p>' +
                            '<a href="' + dashUrl + '" class="button button-primary">Upgrade to Pro — $20/mo</a>' +
                            '</div>'
                        ).show();
                        return;
                    }
                    $('#bitbot-create-status').html('<p class="error">' + escHtmlLocal(errMsg || 'Failed to create form.') + '</p>').show();
                }
                $.ajax({
                    url: config.ajaxUrl, method: 'POST',
                    data: { action: 'bitbot_create_form', nonce: config.nonce, name: name, description: description, fields: JSON.stringify(fields) },
                    success: function(response) {
                        if (response.success && response.data && response.data.id) {
                            window.location.href = '<?php echo esc_url(admin_url('admin.php?page=bitbot-forms')); ?>&form_id=' + response.data.id;
                        } else {
                            var errMsg = (response.data && response.data.error) || (response.data && typeof response.data === 'string' ? response.data : '') || 'Failed to create form.';
                            renderFormError(errMsg);
                        }
                    },
                    error: function() {
                        renderFormError('Failed to create form.');
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
            <?php $this->render_plan_banner(); ?>

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
            var currentPage = 1;
            var PAGE_SIZE = 50;

            function loadLeads() {
                $.ajax({
                    url: config.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'bitbot_get_leads',
                        nonce: config.nonce,
                        stage: stageFilter,
                        search: searchQuery,
                        page: currentPage,
                        pageSize: PAGE_SIZE
                    },
                    success: function(response) {
                        if (!response.success) {
                            $('#bitbot-leads-container').html('<p class="error">' + ((response.data && response.data.error) || 'Failed to load leads') + '</p>');
                            return;
                        }
                        var leads = response.data.leads || [];
                        var total = response.data.total || 0;
                        var page = response.data.page || 1;
                        var pageSize = response.data.pageSize || PAGE_SIZE;
                        var totalPages = Math.max(1, Math.ceil(total / pageSize));

                        if (leads.length === 0 && page === 1) {
                            // Distinguish "you have no leads at all" from
                            // "your stage/search filter excluded everything."
                            // The first message is reassuring during onboarding;
                            // the second would be alarming and wrong if the
                            // owner has 60 leads but the filter narrows to 0.
                            var hasFilters = !!stageFilter || !!searchQuery;
                            if (hasFilters) {
                                var resetUrl = '<?php echo esc_url(admin_url('admin.php?page=bitbot-leads')); ?>';
                                $('#bitbot-leads-container').html(
                                    '<div class="bitbot-card" style="text-align: center; padding: 40px;">' +
                                    '<h3>No leads match this filter</h3>' +
                                    '<p>Try a different stage or search term — or <a href="' + resetUrl + '">clear filters</a> to see all leads.</p>' +
                                    '</div>'
                                );
                            } else {
                                $('#bitbot-leads-container').html(
                                    '<div class="bitbot-card" style="text-align: center; padding: 40px;">' +
                                    '<h3>No leads yet</h3>' +
                                    '<p>Leads will appear here when visitors submit forms or share contact info in chat.</p>' +
                                    '</div>'
                                );
                            }
                            return;
                        }

                        // Header line: total + current window. Helps the
                        // owner immediately see whether they're looking at
                        // a slice or the whole list.
                        var firstIdx = (page - 1) * pageSize + 1;
                        var lastIdx  = Math.min(total, firstIdx + leads.length - 1);
                        var html = '<p>Showing <strong>' + firstIdx + '–' + lastIdx + '</strong> of ' + total + ' lead' + (total !== 1 ? 's' : '') + '</p>';
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
                            // Un-viewed leads are exactly the rows
                            // counted by the Leads menu badge. Visual
                            // treatment: tinted background row + an
                            // amber "NEW" pill before the name. Clears
                            // when the owner opens the lead detail
                            // page (server stamps viewed_at there).
                            var unviewed = !lead.viewedAt;
                            var rowStyle = unviewed ? ' style="background: #fff8e5;"' : '';
                            var newPill = unviewed
                                ? '<span style="display:inline-block;background:#f0c33c;color:#3c2f00;font-size:10px;font-weight:700;padding:2px 6px;border-radius:3px;margin-right:6px;letter-spacing:0.5px;">NEW</span>'
                                : '';

                            html += '<tr' + rowStyle + '>';
                            html += '<td>' + newPill + '<a href="' + detailUrl + '" style="font-weight: 600;">' + escHtml(lead.name) + '</a></td>';
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

                        // Prev/Next pager. Only render the container when
                        // we have more than one page so single-page sites
                        // don't get extra chrome.
                        if (totalPages > 1) {
                            html += '<div class="tablenav" style="margin-top: 12px;">';
                            html += '<div class="tablenav-pages" style="float: none; text-align: center;">';
                            html += '<button type="button" class="button bitbot-leads-prev" ' + (page <= 1 ? 'disabled' : '') + '>&laquo; Previous</button> ';
                            html += '<span style="margin: 0 12px; line-height: 28px;">Page ' + page + ' of ' + totalPages + '</span>';
                            html += '<button type="button" class="button bitbot-leads-next" ' + (page >= totalPages ? 'disabled' : '') + '>Next &raquo;</button>';
                            html += '</div></div>';
                        }

                        $('#bitbot-leads-container').html(html);
                    },
                    error: function() {
                        $('#bitbot-leads-container').html('<p class="error">Failed to load leads.</p>');
                    }
                });
            }

            // Delegated click — survives every re-render of the table.
            $(document).on('click', '.bitbot-leads-prev', function() {
                if (currentPage > 1) { currentPage -= 1; loadLeads(); }
            });
            $(document).on('click', '.bitbot-leads-next', function() {
                currentPage += 1;
                loadLeads();
            });

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
                        if (activity.type === 'calendar_booked') icon = '📅';

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
                                var errMsg = (r.data && r.data.error) || 'Unknown error';
                                // Detect the Pro-feature gate error and render
                                // a proper upgrade card instead of leaking the
                                // raw API string at the user.
                                if (/Pro feature/i.test(errMsg)) {
                                    var dashUrl = '<?php echo esc_url( admin_url( "admin.php?page=bitbot-upgrade" ) ); ?>';
                                    $('#bitbot-research-content').html(
                                        '<div style="padding:18px;background:#fff8e5;border:1px solid #f0c33c;border-radius:6px;">' +
                                        '<p style="margin:0 0 10px;font-weight:600;color:#3c2f00;">AI Lead Research is a Pro feature.</p>' +
                                        '<p style="margin:0 0 14px;color:#5c4a00;font-size:13px;">Auto-scrape the lead\'s website + LinkedIn, surface intent signals, and draft personalized outreach in one click.</p>' +
                                        '<a href="' + dashUrl + '" class="button button-primary">Upgrade to Pro — $20/mo</a>' +
                                        '</div>'
                                    );
                                } else {
                                    $('#bitbot-research-content').html('<p class="error">Research failed: ' + escHtml(errMsg) + '</p>');
                                }
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

        $stage     = isset($_POST['stage']) ? sanitize_text_field($_POST['stage']) : '';
        $search    = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $page      = isset($_POST['page']) ? max(1, absint($_POST['page'])) : 1;
        $page_size = isset($_POST['pageSize']) ? max(1, min(200, absint($_POST['pageSize']))) : 50;

        $result = $this->api_client->get_leads($stage, $search, $page, $page_size);

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
            // Opening a lead marks it AND every tied appointment viewed
            // upstream (Lead.getLead does both). Bust both badge caches
            // so the counts reflect reality on the very next admin page
            // load — otherwise the 60s TTL leaves the badges stale and
            // the user sees "Calendar 2" while the underlying data
            // already says 1.
            $this->bust_new_leads_count_cache();
            delete_transient( self::UPCOMING_APTS_COUNT_TRANSIENT );
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

    /**
     * Toggle the chat-gate on the default pre-chat form.
     *
     * Calls the backend SetChatGate RPC (which lazily provisions the
     * default form server-side if missing) and caches the resulting
     * formId + enabled flag locally so the public-facing widget config
     * can read them without an RPC round-trip on every page render.
     */
    public function ajax_set_chat_gate() {
        check_ajax_referer( 'bitbot_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'error' => 'Permission denied' ) );
        }
        $enabled = isset( $_POST['enabled'] ) && $_POST['enabled'] === '1';
        $result  = $this->api_client->set_chat_gate( $enabled );
        if ( ! $result['success'] ) {
            wp_send_json_error( array( 'error' => $result['error'] ) );
            return;
        }
        $data    = $result['data'];
        $form_id = isset( $data['formId'] ) ? sanitize_text_field( $data['formId'] ) : '';
        $on      = ! empty( $data['chatGateEnabled'] );
        if ( $form_id !== '' ) {
            update_option( 'bitbot_chat_gate_form_id', $form_id );
        }
        update_option( 'bitbot_chat_gate_enabled', $on ? '1' : '0' );

        // Cache the form's field schema as JSON so the widget on the
        // public site can render it without a fetch round-trip. Only
        // bothers when ENABLING — when disabling, the cached schema
        // is moot (parse_chat_gate_form short-circuits on the
        // chat_gate_enabled flag anyway) and the extra GetForm RPC
        // call adds noticeable latency to the OFF toggle.
        if ( $on && $form_id !== '' ) {
            $schema_res = $this->api_client->get_form( $form_id );
            if ( $schema_res['success'] && isset( $schema_res['data'] ) ) {
                update_option( 'bitbot_chat_gate_form_schema', wp_json_encode( $schema_res['data'] ) );
            }
        }

        wp_send_json_success( array(
            'chatGateEnabled' => $on,
            'formId'          => $form_id,
        ) );
    }

    /**
     * Flip the public-widget kill-switch (bitbot_enabled wp_option).
     * No backend round-trip — this is a pure local toggle. The next
     * public page load reads the new value via enqueue_frontend_assets;
     * the next admin SPA load picks up the updated widgetEnabled flag
     * in bitbotAdminConfig (so the Overview "Chat is turned off" banner
     * reacts on the next page render).
     *
     * @since 2.0.15
     */
    public function ajax_set_widget_enabled() {
        check_ajax_referer( 'bitbot_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'error' => 'Permission denied' ) );
        }
        $enabled = isset( $_POST['enabled'] ) && '1' === (string) $_POST['enabled'];
        update_option( 'bitbot_enabled', $enabled ? true : false, true );
        wp_send_json_success( array( 'widgetEnabled' => $enabled ) );
    }

    // ================================================================
    // Live Chats (Pro+ live-handover)
    // ================================================================

    /**
     * Presence heartbeat. Tiny AJAX handler so the backend knows the admin
     * is active; the Pro+ agent reads this to decide whether to offer live
     * handover to visitors.
     *
     * @since 1.7.0
     */
    public function ajax_heartbeat() {
        check_ajax_referer( 'bitbot_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'error' => 'Permission denied' ) );
        }
        // 2.0.12: every admin heartbeat also bumps the active-days
        // ledger that gates the rate-us nudge. Storing unique
        // YYYY-MM-DD strings (WP's site timezone) means we only count
        // distinct days, not every page load — and admins who pop in
        // multiple times the same day don't inflate the engagement
        // signal. Cap at 30 entries so the option can't grow unbounded.
        $today = wp_date( 'Y-m-d' );
        $days  = get_option( 'bitbot_admin_active_days', array() );
        if ( ! is_array( $days ) ) {
            $days = array();
        }
        if ( ! in_array( $today, $days, true ) ) {
            $days[] = $today;
            if ( count( $days ) > 30 ) {
                $days = array_slice( $days, -30 );
            }
            update_option( 'bitbot_admin_active_days', $days, false );
        }

        $result = $this->api_client->admin_heartbeat();
        if ( $result['success'] ) {
            wp_send_json_success( $result['data'] );
        } else {
            wp_send_json_error( array( 'error' => $result['error'] ?? 'Heartbeat failed' ) );
        }
    }

    /**
     * Public ping from the widget.js boot path. Fire-and-forget — we
     * just stamp the option and return 204-equivalent. No nonce
     * because this is public; throttling and abuse-resistance live
     * in widget.js (sessionStorage marker so each visitor session
     * pings at most once).
     *
     * @since 2.0.12
     */
    public function ajax_widget_seen() {
        update_option( 'bitbot_widget_last_seen_at', time(), false );
        wp_send_json_success( array( 'ok' => true ) );
    }

    /**
     * Persist the verdict from the rate-us admin notice. Three modes:
     *   - rate:    admin clicked "Rate BitBot →" — treat as dismissed
     *   - remind:  admin clicked "Remind me later" — suppress 14 days
     *   - dismiss: admin clicked the dismiss button — never show again
     *
     * Stored in wp_options. Returns 200 with no body either way; the
     * banner JS just hides on click without waiting for the response.
     *
     * @since 2.0.12
     */
    public function ajax_rate_us_action() {
        check_ajax_referer( 'bitbot_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'error' => 'Permission denied' ) );
        }
        $verdict = isset( $_POST['verdict'] ) ? sanitize_key( (string) $_POST['verdict'] ) : '';
        if ( 'rate' === $verdict || 'dismiss' === $verdict ) {
            update_option( 'bitbot_rate_us_dismissed', '1', false );
        } elseif ( 'remind' === $verdict ) {
            update_option( 'bitbot_rate_us_remind_after', time() + 14 * DAY_IN_SECONDS, false );
        } else {
            wp_send_json_error( array( 'error' => 'Unknown verdict' ) );
        }
        wp_send_json_success( array( 'ok' => true ) );
    }

    /**
     * Fetch a single live thread's messages for the polling admin UI.
     *
     * @since 1.7.0
     */
    public function ajax_live_chats_thread() {
        check_ajax_referer( 'bitbot_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'error' => 'Permission denied' ) );
        }
        $conversation_id = isset( $_POST['conversation_id'] ) ? sanitize_text_field( (string) $_POST['conversation_id'] ) : '';
        if ( ! $conversation_id ) {
            wp_send_json_error( array( 'error' => 'conversation_id is required' ) );
        }
        $result = $this->api_client->get_live_chat_messages( $conversation_id );
        $result['success'] ? wp_send_json_success( $result['data'] ) : wp_send_json_error( array( 'error' => $result['error'] ) );
    }

    /**
     * Admin reply → backend AdminReplyLive RPC. Server-side form POST
     * (not AJAX) to keep the flow robust even without JS.
     *
     * @since 1.7.0
     */
    public function handle_live_chats_reply() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permission denied' );
        }
        check_admin_referer( 'bitbot_live_chats_reply' );

        $conversation_id = isset( $_POST['conversation_id'] ) ? sanitize_text_field( (string) $_POST['conversation_id'] ) : '';
        $message         = isset( $_POST['message'] ) ? wp_kses_post( wp_unslash( (string) $_POST['message'] ) ) : '';

        if ( $conversation_id && trim( $message ) !== '' ) {
            $this->api_client->admin_reply_live( $conversation_id, $message );
        }

        wp_safe_redirect( add_query_arg(
            array( 'page' => 'bitbot-live-chats', 'thread' => $conversation_id ),
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    /**
     * Close a live thread → agent resumes on next visitor message.
     *
     * @since 1.7.0
     */
    public function handle_live_chats_close() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permission denied' );
        }
        check_admin_referer( 'bitbot_live_chats_close' );

        $conversation_id = isset( $_POST['conversation_id'] ) ? sanitize_text_field( (string) $_POST['conversation_id'] ) : '';
        if ( $conversation_id ) {
            $this->api_client->close_live_thread( $conversation_id );
        }

        // Drop the menu badge immediately on close instead of waiting for
        // the 30s transient to expire.
        delete_transient( self::LIVE_CHATS_COUNT_TRANSIENT );

        wp_safe_redirect( admin_url( 'admin.php?page=bitbot-live-chats&closed=1' ) );
        exit;
    }

    /**
     * Admin-initiated takeover: flips the conversation into live_mode
     * server-side so the agent steps aside. The visitor's widget picks
     * this up on its next heartbeat (≤30s) or on their next message,
     * whichever comes first.
     *
     * @since 1.7.0
     */
    public function handle_live_chats_takeover() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permission denied' );
        }
        check_admin_referer( 'bitbot_live_chats_takeover' );

        $conversation_id = isset( $_POST['conversation_id'] ) ? sanitize_text_field( (string) $_POST['conversation_id'] ) : '';
        $opener          = isset( $_POST['opener'] ) ? wp_kses_post( wp_unslash( (string) $_POST['opener'] ) ) : '';

        if ( $conversation_id ) {
            $this->api_client->admin_take_over_chat( $conversation_id, $opener );
            // Badge should reflect the new live thread straight away,
            // not on the next transient expiry.
            delete_transient( self::LIVE_CHATS_COUNT_TRANSIENT );
        }

        // Land the admin on the thread view of the conversation they
        // just took over so they can start replying immediately.
        $args = array( 'page' => 'bitbot-live-chats', 'takeover' => 1 );
        if ( $conversation_id ) {
            $args['thread'] = $conversation_id;
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Render the Live Chats admin page. Two-pane layout: list of threads
     * on the left, selected thread on the right. Server-rendered on load;
     * JS polls every 5s for new messages on the active thread.
     *
     * @since 1.7.0
     */
    public function render_live_chats_page() {
        $active_id = isset( $_GET['thread'] ) ? sanitize_text_field( (string) $_GET['thread'] ) : '';

        $list_response = $this->api_client->get_live_chats();
        $chats = array();
        if ( ! empty( $list_response['success'] ) && isset( $list_response['data']['chats'] ) ) {
            $chats = $list_response['data']['chats'];
        }

        $thread_messages = array();
        if ( $active_id ) {
            $thread_response = $this->api_client->get_live_chat_messages( $active_id );
            if ( ! empty( $thread_response['success'] ) && isset( $thread_response['data']['messages'] ) ) {
                $thread_messages = $thread_response['data']['messages'];
            }
        }

        $active_chat = null;
        foreach ( $chats as $c ) {
            if ( isset( $c['id'] ) && $c['id'] === $active_id ) {
                $active_chat = $c;
                break;
            }
        }
        ?>
        <div class="wrap bitbot-live-chats">
            <h1><?php esc_html_e( 'Live Chats', 'bitbot' ); ?></h1>

            <?php if ( isset( $_GET['closed'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Live thread closed. The agent will resume on the visitor\'s next message.', 'bitbot' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['takeover'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Thread taken over. The visitor\'s widget will switch to live mode on its next heartbeat (≤30s) or on their next message.', 'bitbot' ); ?></p></div>
            <?php endif; ?>

            <p class="description" style="margin-bottom: 16px;">
                <?php esc_html_e( 'Active chats and hand-over requests across your site. Threads in Needs human are waiting on a reply from you. Chatting shows visitors currently talking to the agent — jump in if you want.', 'bitbot' ); ?>
            </p>

            <?php
            // Split threads into two buckets. Needs-human pinned at top
            // with red accent, then Chatting below. Idle/left threads
            // still appear under Chatting with a subdued pill so the
            // admin can see recent history at a glance.
            $needs_human = array();
            $chatting    = array();
            foreach ( $chats as $c ) {
                if ( ! empty( $c['liveMode'] ) ) {
                    $needs_human[] = $c;
                } else {
                    $chatting[] = $c;
                }
            }
            $render_list = function ( $rows, $active_id, $empty_msg, $accent_color, $show_takeover = false ) {
                if ( empty( $rows ) ) {
                    echo '<p style="padding: 12px; color: #888; font-size: 12px;">' . esc_html( $empty_msg ) . '</p>';
                    return;
                }
                echo '<ul style="list-style: none; padding: 0; margin: 0;">';
                foreach ( $rows as $c ) {
                    $is_active     = isset( $c['id'] ) && $c['id'] === $active_id;
                    $unread        = (int) ( $c['unreadAdminCount'] ?? 0 );
                    // Backend (since GetLiveChats was extended) computes a
                    // glanceable cascade label: lead name → email → "Visitor
                    // from City, Country" → "Visitor #abc1234". Old plugin
                    // versions hitting a fresh backend get the new field;
                    // new plugin against an older backend falls back to the
                    // pre-cascade behavior so the inbox keeps rendering.
                    if ( ! empty( $c['displayLabel'] ) ) {
                        $visitor_label = (string) $c['displayLabel'];
                    } elseif ( ! empty( $c['visitorEmail'] ) ) {
                        $visitor_label = (string) $c['visitorEmail'];
                    } else {
                        $visitor_label = $c['visitorIp'] ?? __( 'visitor', 'bitbot' );
                    }
                    $presence      = (string) ( $c['presence'] ?? 'idle' );
                    $last_msg_at   = (string) ( $c['lastMessageAt'] ?? '' );
                    $ago           = $last_msg_at ? human_time_diff( strtotime( $last_msg_at ), current_time( 'timestamp', true ) ) : '';

                    $pill_bg   = '#888';
                    $pill_text = strtoupper( $presence );
                    if ( $presence === 'present' ) { $pill_bg = '#46b450'; }
                    elseif ( $presence === 'idle' ) { $pill_bg = '#f0b849'; }
                    elseif ( $presence === 'left' ) { $pill_bg = '#a0a0a5'; }
                    ?>
                    <li style="padding: 10px 12px; border-bottom: 1px solid #eee; border-left: 3px solid <?php echo esc_attr( $accent_color ); ?>; background: <?php echo $is_active ? '#f0f6fc' : 'transparent'; ?>;">
                        <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'bitbot-live-chats', 'thread' => $c['id'] ), admin_url( 'admin.php' ) ) ); ?>" style="text-decoration: none; color: inherit; display: block;">
                            <div>
                                <strong><?php echo esc_html( $visitor_label ); ?></strong>
                                <span style="display: inline-block; background: <?php echo esc_attr( $pill_bg ); ?>; color: #fff; font-size: 10px; padding: 1px 6px; border-radius: 10px; margin-left: 4px;"><?php echo esc_html( $pill_text ); ?></span>
                                <?php if ( $unread > 0 ) : ?>
                                    <span style="display: inline-block; background: #d63638; color: #fff; font-size: 10px; padding: 1px 6px; border-radius: 10px; margin-left: 4px;"><?php echo (int) $unread; ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ( $ago ) : ?>
                                <div style="font-size: 11px; color: #666; margin-top: 2px;">
                                    <?php /* translators: %s: time duration */ printf( esc_html__( 'last msg %s ago', 'bitbot' ), esc_html( $ago ) ); ?>
                                </div>
                            <?php endif; ?>
                        </a>
                        <?php if ( $show_takeover && $presence !== 'left' ) : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 6px;" onsubmit="return confirm('Take over this chat? The agent will step aside and you\'ll start replying as a human.');">
                                <input type="hidden" name="action" value="bitbot_live_chats_takeover">
                                <input type="hidden" name="conversation_id" value="<?php echo esc_attr( $c['id'] ?? '' ); ?>">
                                <?php wp_nonce_field( 'bitbot_live_chats_takeover' ); ?>
                                <button type="submit" class="button button-small" style="font-size: 11px; padding: 0 8px; height: 22px; line-height: 20px;">
                                    <?php esc_html_e( 'Take over', 'bitbot' ); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </li>
                    <?php
                }
                echo '</ul>';
            };
            ?>

            <div style="display: grid; grid-template-columns: 320px 1fr; gap: 20px; align-items: start;">
                <div>
                    <div class="card" style="margin-bottom: 14px;">
                        <h2 class="title" style="display: flex; align-items: center; gap: 8px;">
                            <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #d63638;"></span>
                            <?php esc_html_e( 'Needs human', 'bitbot' ); ?>
                            <?php if ( ! empty( $needs_human ) ) : ?>
                                <span style="font-size: 12px; color: #666; font-weight: normal;">(<?php echo count( $needs_human ); ?>)</span>
                            <?php endif; ?>
                        </h2>
                        <?php $render_list( $needs_human, $active_id, __( 'No handover requests right now.', 'bitbot' ), '#d63638' ); ?>
                    </div>
                    <div class="card">
                        <h2 class="title" style="display: flex; align-items: center; gap: 8px;">
                            <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #2271b1;"></span>
                            <?php esc_html_e( 'Chatting', 'bitbot' ); ?>
                            <?php if ( ! empty( $chatting ) ) : ?>
                                <span style="font-size: 12px; color: #666; font-weight: normal;">(<?php echo count( $chatting ); ?>)</span>
                            <?php endif; ?>
                        </h2>
                        <?php $render_list( $chatting, $active_id, __( 'No active chats in the last 30 minutes.', 'bitbot' ), '#2271b1', /* show_takeover */ true ); ?>
                    </div>
                </div>

                <div class="card" style="min-height: 420px;">
                    <?php if ( ! $active_id ) : ?>
                        <p style="padding: 30px; text-align: center; color: #666;">
                            <?php esc_html_e( 'Pick a thread on the left to view and reply.', 'bitbot' ); ?>
                        </p>
                    <?php else :
                        $is_live = $active_chat && ! empty( $active_chat['liveMode'] );
                    ?>
                        <div id="bitbot-live-thread" data-conversation-id="<?php echo esc_attr( $active_id ); ?>" data-is-live="<?php echo $is_live ? '1' : '0'; ?>">
                            <div id="bitbot-live-messages" style="max-height: 420px; overflow-y: auto; padding: 12px; background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 6px; margin-bottom: 16px;">
                                <?php foreach ( $thread_messages as $m ) :
                                    $role    = $m['role'] ?? '';
                                    $content = (string) ( $m['content'] ?? '' );
                                    if ( $role === 'admin' ) {
                                        $align = 'right';
                                        $bg    = '#d1e7fd';
                                        $label = __( 'You', 'bitbot' );
                                    } elseif ( $role === 'assistant' ) {
                                        $align = 'left';
                                        $bg    = '#fff3cd';
                                        $label = __( 'Agent', 'bitbot' );
                                    } else {
                                        $align = 'left';
                                        $bg    = '#fff';
                                        $label = __( 'Visitor', 'bitbot' );
                                    }
                                ?>
                                    <div style="margin: 8px 0; text-align: <?php echo esc_attr( $align ); ?>;">
                                        <div style="display: inline-block; max-width: 70%; background: <?php echo esc_attr( $bg ); ?>; padding: 8px 12px; border-radius: 10px; text-align: left;">
                                            <div style="font-size: 10px; color: #666; text-transform: uppercase; margin-bottom: 2px;"><?php echo esc_html( $label ); ?></div>
                                            <div><?php echo nl2br( esc_html( $content ) ); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if ( $is_live ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: flex; gap: 8px; align-items: flex-end;">
                                    <input type="hidden" name="action" value="bitbot_live_chats_reply">
                                    <input type="hidden" name="conversation_id" value="<?php echo esc_attr( $active_id ); ?>">
                                    <?php wp_nonce_field( 'bitbot_live_chats_reply' ); ?>
                                    <textarea name="message" rows="3" style="flex: 1;" placeholder="<?php esc_attr_e( 'Type your reply…', 'bitbot' ); ?>" required></textarea>
                                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Send', 'bitbot' ); ?></button>
                                </form>

                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 12px;">
                                    <input type="hidden" name="action" value="bitbot_live_chats_close">
                                    <input type="hidden" name="conversation_id" value="<?php echo esc_attr( $active_id ); ?>">
                                    <?php wp_nonce_field( 'bitbot_live_chats_close' ); ?>
                                    <button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Close this live thread? The agent will resume on the next visitor message.', 'bitbot' ) ); ?>');">
                                        <?php esc_html_e( 'Close thread', 'bitbot' ); ?>
                                    </button>
                                </form>
                            <?php else : ?>
                                <p style="color: #666; padding: 12px; background: #f4f4f5; border-radius: 6px;">
                                    <?php esc_html_e( 'This thread has ended. Messages are read-only.', 'bitbot' ); ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <script>
                        // Pin the messages pane to the bottom on every render so
                        // the admin actually sees the most recent turn instead
                        // of having to scroll past older history. Runs once on
                        // load; incremental appends below also re-scroll.
                        (function () {
                            var box = document.getElementById('bitbot-live-messages');
                            if (box) box.scrollTop = box.scrollHeight;
                        })();
                        </script>

                        <?php if ( $is_live ) : ?>
                            <script>
                            // Poll the thread every 5s for new visitor messages and
                            // append them in place. Previously this did a full
                            // location.reload() which wiped the inner scroll state
                            // (and the admin's draft reply), so the conversation
                            // appeared to "scroll up" every 5s.
                            (function () {
                                var CONV_ID = <?php echo wp_json_encode( $active_id ); ?>;
                                var NONCE   = <?php echo wp_json_encode( wp_create_nonce( 'bitbot_admin_nonce' ) ); ?>;
                                var AJAX    = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
                                var seenIds = new Set(
                                    <?php
                                        $ids = array();
                                        foreach ( $thread_messages as $m ) {
                                            if ( isset( $m['id'] ) ) {
                                                $ids[] = (string) $m['id'];
                                            }
                                        }
                                        echo wp_json_encode( $ids );
                                    ?>
                                );

                                function escapeHtml(s) {
                                    return String(s || '')
                                        .replace(/&/g, '&amp;')
                                        .replace(/</g, '&lt;')
                                        .replace(/>/g, '&gt;')
                                        .replace(/\n/g, '<br>');
                                }

                                function renderMessage(msg) {
                                    var role = msg.role || '';
                                    var align = 'left', bg = '#fff', label = 'Visitor';
                                    if (role === 'admin')     { align = 'right'; bg = '#d1e7fd'; label = 'You'; }
                                    else if (role === 'assistant') {                  bg = '#fff3cd'; label = 'Agent'; }
                                    var wrap = document.createElement('div');
                                    wrap.style.cssText = 'margin: 8px 0; text-align: ' + align + ';';
                                    wrap.innerHTML =
                                        '<div style="display: inline-block; max-width: 70%; background: ' + bg + '; padding: 8px 12px; border-radius: 10px; text-align: left;">' +
                                            '<div style="font-size: 10px; color: #666; text-transform: uppercase; margin-bottom: 2px;">' + escapeHtml(label) + '</div>' +
                                            '<div>' + escapeHtml(msg.content) + '</div>' +
                                        '</div>';
                                    return wrap;
                                }

                                setInterval(function () {
                                    var data = new FormData();
                                    data.append('action', 'bitbot_live_chats_thread');
                                    data.append('nonce', NONCE);
                                    data.append('conversation_id', CONV_ID);
                                    fetch(AJAX, { method: 'POST', body: data, credentials: 'same-origin' })
                                        .then(function (r) { return r.json(); })
                                        .then(function (json) {
                                            if (!json || !json.success || !json.data || !Array.isArray(json.data.messages)) return;
                                            var box = document.getElementById('bitbot-live-messages');
                                            if (!box) return;
                                            // Preserve "user is at bottom" semantics — only autoscroll if
                                            // the admin was already at the bottom. Otherwise they're
                                            // probably scrolling back through history and we shouldn't
                                            // yank them forward.
                                            var wasAtBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 40;
                                            var appended = 0;
                                            json.data.messages.forEach(function (m) {
                                                var id = m && m.id ? String(m.id) : null;
                                                if (!id || seenIds.has(id)) return;
                                                seenIds.add(id);
                                                box.appendChild(renderMessage(m));
                                                appended++;
                                            });
                                            if (appended > 0 && wasAtBottom) {
                                                box.scrollTop = box.scrollHeight;
                                            }
                                        })
                                        .catch(function () { /* swallow */ });
                                }, 5000);
                            })();
                            </script>
                        <?php endif; ?>

                    <?php endif; ?>
                </div>
            </div>

            <script>
            // List-pane auto-refresh so the admin sees new "chatting"
            // threads appear (and presence pills update) without having to
            // hit reload. Only runs when the admin is on the list view with
            // NO active thread selected — we used to reload unconditionally
            // every 20s, which blew away the inner messages scroll (and any
            // draft reply) while the admin was mid-conversation. Also held
            // off while typing so we don't clobber a reply draft.
            (function () {
                var hasActiveThread = <?php echo ! empty( $active_id ) ? 'true' : 'false'; ?>;
                if (hasActiveThread) return;
                setInterval(function () {
                    var ta = document.getElementById('bitbot-live-reply');
                    var typing = ta && (document.activeElement === ta || (ta.value && ta.value.trim().length > 0));
                    if (typing) return;
                    location.reload();
                }, 20000);
            })();
            </script>
        </div>
        <?php
    }

    /**
     * Render the BitBot Calendar admin surface.
     *
     * Pure visibility — no Accept/Decline/Send-Email buttons. Lists every
     * appointment captured by the chat agent in a FullCalendar.js view.
     * Clicking an entry navigates to the matching lead detail page so
     * the owner gets full context (conversation transcript, AI research,
     * activity feed). Communication and meeting setup live entirely in
     * the owner's own tools.
     *
     * FullCalendar.js v6 (MIT, ~70KB gzipped) loaded via CDN as a single
     * standalone bundle — no CommonJS plumbing, no build step, no
     * unpkg.com lookups blocked by site CSP. Falls back to a tabular
     * list if the script fails to load.
     *
     * @since 1.7.0
     */
    public function render_calendar_page() {
        $response = $this->api_client->get_appointments();

        $appointments  = array();
        $site_timezone = 'UTC';
        $api_error     = '';
        if ( ! empty( $response['success'] ) && isset( $response['data']['appointments'] ) ) {
            $appointments  = $response['data']['appointments'];
            $site_timezone = isset( $response['data']['siteTimezone'] ) ? (string) $response['data']['siteTimezone'] : 'UTC';
        } elseif ( isset( $response['error'] ) ) {
            $api_error = (string) $response['error'];
        }

        // Pre-shape rows as FullCalendar event objects. Each entry's
        // `url` field is the lead detail page so a click navigates the
        // browser without us needing to wire eventClick handlers.
        //
        // Title is the visitor's full email (or name if shared). It
        // can overflow the cell on dense days — FullCalendar truncates
        // with the standard ellipsis and the full string stays
        // visible in the hover tooltip + the cell expands when you
        // switch to week / day view.
        $events = array();
        foreach ( $appointments as $a ) {
            if ( empty( $a['startsAt'] ) || empty( $a['endsAt'] ) ) {
                continue;
            }
            if ( ! empty( $a['visitorName'] ) ) {
                $title = (string) $a['visitorName'];
            } elseif ( ! empty( $a['visitorEmail'] ) ) {
                $title = (string) $a['visitorEmail'];
            } else {
                $title = 'Appointment';
            }

            $url = ! empty( $a['leadId'] )
                ? admin_url( 'admin.php?page=bitbot-leads&lead_id=' . urlencode( $a['leadId'] ) )
                : '';

            // Two-state model: un-viewed = green (highlighted, draws
            // the eye -- this matches the menu badge count), viewed =
            // muted grey. No cancelled treatment because there is no
            // cancel UI in BitBot today; if we ever add one, paint it
            // strikethrough or hide it rather than surface a third
            // legend entry.
            $unviewed = empty( $a['viewedAt'] );
            if ( $unviewed ) {
                $bg     = '#46b450';
                $border = '#3a9241';
            } else {
                $bg     = '#c7cdd3';
                $border = '#a8b0b8';
            }

            $events[] = array(
                'id'              => isset( $a['id'] ) ? $a['id'] : '',
                'title'           => $title,
                'start'           => $a['startsAt'],
                'end'             => $a['endsAt'],
                'url'             => $url,
                'backgroundColor' => $bg,
                'borderColor'     => $border,
                'extendedProps'   => array(
                    'topic'        => isset( $a['topic'] ) ? $a['topic'] : '',
                    'visitorEmail' => isset( $a['visitorEmail'] ) ? $a['visitorEmail'] : '',
                    'serviceName'  => isset( $a['serviceName'] ) ? $a['serviceName'] : '',
                    'status'       => isset( $a['status'] ) ? $a['status'] : '',
                    'unviewed'     => $unviewed,
                ),
            );
        }
        ?>
        <div class="wrap bitbot-calendar">
            <h1><?php esc_html_e( 'Calendar', 'bitbot' ); ?></h1>
            <p class="description" style="margin-bottom: 12px;">
                <?php esc_html_e( 'Every appointment your chatbot has booked. Click any entry to open the matching lead — full conversation, AI research, and notes are there. Use your own email and calendar tools to confirm and share meeting links with the visitor.', 'bitbot' ); ?>
            </p>
            <p class="description" style="margin-bottom: 16px; font-size: 12px; color: #666;">
                <span style="display:inline-block;width:10px;height:10px;background:#46b450;border-radius:2px;vertical-align:middle;margin-right:4px;"></span><?php esc_html_e( 'New', 'bitbot' ); ?>
            </p>

            <?php if ( $api_error ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( $api_error ); ?></p></div>
            <?php endif; ?>

            <?php if ( empty( $events ) ) : ?>
                <div style="padding: 40px; background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; text-align: center;">
                    <p style="font-size: 16px; color: #555; margin: 0;">
                        <?php esc_html_e( 'No appointments yet — the calendar fills up as visitors book sessions through your chatbot.', 'bitbot' ); ?>
                    </p>
                </div>
            <?php else : ?>
                <!--
                    FullCalendar v6 standalone build. CDN URLs intentionally
                    pinned to a specific version so a future breaking
                    upstream release can't yank our admin UI.
                -->
                <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">
                <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>

                <div id="bitbot-calendar-app" style="background: #fff; padding: 16px; border: 1px solid #e0e0e0; border-radius: 8px;"></div>
                <noscript>
                    <p style="padding: 12px; background: #fff8e5; border: 1px solid #f0c33c; border-radius: 4px;">
                        <?php esc_html_e( 'JavaScript is required to render the calendar view. The bookings are still all in the Leads list.', 'bitbot' ); ?>
                    </p>
                </noscript>

                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var el = document.getElementById('bitbot-calendar-app');
                    if (!el || typeof FullCalendar === 'undefined') return;
                    var events = <?php echo wp_json_encode( $events ); ?>;
                    var calendar = new FullCalendar.Calendar(el, {
                        initialView: 'dayGridMonth',
                        // 'local' = the owner's browser timezone. Using
                        // the site's configured appointments_timezone
                        // (e.g. "Australia/Sydney") would require the
                        // FullCalendar Luxon / moment-timezone plugin
                        // -- without it, FullCalendar silently falls
                        // back to UTC for any named tz, which is why
                        // a Sydney-tz site was rendering bookings on
                        // the day-before cell. The browser tz is the
                        // right answer for the owner ~95% of the time
                        // (they're using their own machine in their
                        // own location); revisit if traveling-owner
                        // complaints come in.
                        timeZone: 'local',
                        height: 'auto',
                        nowIndicator: true,
                        // Force every event to render as a coloured block.
                        // FullCalendar's default "auto" mode renders short
                        // timed events as a dot + text (no background fill)
                        // when multiple events stack on the same day —
                        // which kills our amber-vs-green un-viewed/viewed
                        // visual cue. Block mode keeps the colour cue
                        // consistent across views and stack positions.
                        eventDisplay: 'block',
                        headerToolbar: {
                            left: 'prev,next today',
                            center: 'title',
                            right: 'dayGridMonth,timeGridWeek,timeGridDay'
                        },
                        events: events,
                        // Tooltip shown on hover — full visitor email,
                        // service, and topic (the visible cell only
                        // shows the visitor's name / handle, so the
                        // hover is where the rest lives).
                        eventDidMount: function (info) {
                            var props = info.event.extendedProps || {};
                            var bits = [];
                            if (props.serviceName) bits.push(props.serviceName);
                            if (props.visitorEmail) bits.push(props.visitorEmail);
                            if (props.topic) bits.push(props.topic);
                            if (bits.length > 0) info.el.title = bits.join(' · ');
                        },
                    });
                    calendar.render();
                });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }
}
