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

    /**
     * Why the last ensure_header_nav_override() left the header part as it
     * was (the user customised it, our override was deleted, no navigation
     * block), or null when it pinned the links. Reported on the menu apply.
     *
     * @var string|null
     */
    private $nav_pin_reason = null;

    /**
     * Translate every machine-translated widget copy row again from the
     * defaults, in place. Owner rows are untouched. Used once by the 2.1.413
     * upgrade: the welcome line is no longer written for the voice's gender.
     *
     * @return bool True when every asked row came back.
     */
    /**
     * Site language with no built-in default copy: translate the English
     * defaults into it once and cache them (BitBot_Widget_Copy::SITE_DEFAULTS_OPTION).
     * Retried a day later when the API did not answer. English sites and the
     * languages with built-in text never call the API.
     */
    public function maybe_localize_site_defaults() {
        if ( ! BitBot_Consent::is_connected() ) {
            return;
        }
        $site_code = BitBot_Languages::site_code( get_locale() );
        if ( '' === $site_code || 'en' === $site_code || BitBot_Widget_Copy::has_builtin_defaults( $site_code ) ) {
            return;
        }
        $row = get_option( BitBot_Widget_Copy::SITE_DEFAULTS_OPTION, array() );
        if ( is_array( $row ) && isset( $row['code'] ) && $row['code'] === $site_code && ! empty( $row['title'] ) ) {
            return;
        }
        if ( is_array( $row ) && isset( $row['code'], $row['attempted_at'] ) && $row['code'] === $site_code && time() - (int) $row['attempted_at'] < DAY_IN_SECONDS ) {
            return;
        }
        update_option( BitBot_Widget_Copy::SITE_DEFAULTS_OPTION, array( 'code' => $site_code, 'attempted_at' => time() ), false );
        $tr = $this->api_client->translate_widget_copy(
            BitBot_Widget_Copy::default_title( 'en' ),
            BitBot_Widget_Copy::default_welcome( 'en' ),
            'en',
            array( $site_code ),
            array(),
            BitBot_Widget_Copy::default_placeholder( 'en' )
        );
        $copy = ( ! empty( $tr['success'] ) && isset( $tr['data']['copy'][ $site_code ] ) && is_array( $tr['data']['copy'][ $site_code ] ) ) ? $tr['data']['copy'][ $site_code ] : null;
        if ( ! $copy || empty( $copy['title'] ) ) {
            return;
        }
        update_option( BitBot_Widget_Copy::SITE_DEFAULTS_OPTION, array(
            'code'         => $site_code,
            'title'        => sanitize_text_field( (string) $copy['title'] ),
            'welcome'      => wp_kses_post( (string) ( $copy['welcome'] ?? '' ) ),
            'placeholder'  => sanitize_text_field( (string) ( $copy['placeholder'] ?? '' ) ),
            'attempted_at' => time(),
        ), false );
    }

    /**
     * Automatic translations made under older rules (BitBot_Widget_Copy::AUTO_RULES)
     * are remade when the settings screen is read, once an hour at most on
     * failure, so a rule change (the gender-free welcome, 2026-11-09) reaches
     * every site without an owner action. Owner rows are never touched.
     */
    public function refresh_stale_auto_copy() {
        $site_code = BitBot_Languages::site_code( get_locale() );
        $selection = BitBot_Languages::effective_selection( get_option( BitBot_Languages::OPTION, array() ), BitBot_Languages::detected(), BitBot_Languages::all() );
        $rows      = BitBot_Widget_Copy::normalize( get_option( BitBot_Widget_Copy::OPTION, array() ) );
        $codes     = BitBot_Widget_Copy::stale_auto_codes( $selection, $site_code, $rows );
        if ( empty( $codes ) || get_transient( 'bitbot_widget_copy_refresh_failed' ) ) {
            return;
        }
        $title       = BitBot_Widget_Copy::base_title( '' );
        $welcome     = BitBot_Widget_Copy::default_welcome( $site_code );
        $placeholder = BitBot_Widget_Copy::default_placeholder();
        $questions   = BitBot_Widget_Copy::base_questions( get_option( 'bitbot_suggested_questions', '' ) );
        if ( '' === $title && '' === $welcome && empty( $questions ) && '' === $placeholder ) {
            return;
        }
        $tr      = $this->api_client->translate_widget_copy( $title, $welcome, $site_code, $codes, $questions, $placeholder );
        $results = ( ! empty( $tr['success'] ) && isset( $tr['data']['copy'] ) && is_array( $tr['data']['copy'] ) ) ? $tr['data']['copy'] : array();
        if ( empty( $results ) ) {
            set_transient( 'bitbot_widget_copy_refresh_failed', 1, HOUR_IN_SECONDS );
            return;
        }
        // Merge into the rows as they are NOW, not the snapshot read before the
        // API call, and write ONLY if nobody wrote in between: one conditional
        // statement (the stored value must still equal what was read), else
        // read again and merge again, three times at most. An owner save that
        // lands during the merge is therefore never lost (merge_auto never
        // touches an owner row either). Codex 2026-11-10.
        BitBot_Widget_Copy::cas_merge(
            array( $this, 'read_widget_copy_raw' ),
            array( $this, 'write_widget_copy_if_unchanged' ),
            $results
        );
    }

    /** The stored widget copy as it is in the database now, no option cache. */
    public function read_widget_copy_raw() {
        global $wpdb;
        $raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", BitBot_Widget_Copy::OPTION ) );
        return null === $raw ? null : maybe_unserialize( $raw );
    }

    /**
     * Writes $rows to the widget copy option only while its stored value is
     * still exactly $expected (null = the row does not exist yet).
     *
     * @return bool True when written (or already equal), false when someone else wrote first.
     */
    public function write_widget_copy_if_unchanged( $expected, array $rows ) {
        global $wpdb;
        $name = BitBot_Widget_Copy::OPTION;
        if ( null === $expected ) {
            $added = add_option( $name, $rows, '', 'yes' );
            return (bool) $added;
        }
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            maybe_serialize( $rows ), $name, maybe_serialize( $expected )
        ) );
        $affected = (int) $wpdb->rows_affected;
        wp_cache_delete( $name, 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        if ( 1 === $affected ) {
            return true;
        }
        // MySQL reports 0 for a matched row whose value did not change: that is
        // still this writer's value in place. Anything else means another write landed.
        return $this->read_widget_copy_raw() === $rows;
    }

    public function regenerate_auto_copy() {
        $site_code = BitBot_Languages::site_code( get_locale() );
        $selection = BitBot_Languages::effective_selection( get_option( BitBot_Languages::OPTION, array() ), BitBot_Languages::detected(), BitBot_Languages::all() );
        $rows      = BitBot_Widget_Copy::normalize( get_option( BitBot_Widget_Copy::OPTION, array() ) );
        $codes     = BitBot_Widget_Copy::regenerate_codes( BitBot_Widget_Copy::auto_codes( $rows ), $selection, $site_code );
        if ( empty( $codes ) ) {
            return true;
        }
        $title       = BitBot_Widget_Copy::base_title( '' );
        $welcome     = BitBot_Widget_Copy::default_welcome( $site_code );
        $placeholder = BitBot_Widget_Copy::default_placeholder();
        $questions   = BitBot_Widget_Copy::base_questions( get_option( 'bitbot_suggested_questions', '' ) );
        $tr          = $this->api_client->translate_widget_copy( $title, $welcome, $site_code, $codes, $questions, $placeholder );
        $results     = ( ! empty( $tr['success'] ) && isset( $tr['data']['copy'] ) && is_array( $tr['data']['copy'] ) ) ? $tr['data']['copy'] : array();
        if ( empty( $results ) ) {
            return false;
        }
        update_option( BitBot_Widget_Copy::OPTION, BitBot_Widget_Copy::merge_regenerated( $rows, $results, $codes ) );
        return count( array_intersect( $codes, array_keys( $results ) ) ) === count( $codes );
    }

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
        add_action('init', array(__CLASS__, 'upgrade_agent_role'), 5);
        add_action('admin_init', array($this, 'register_settings'));
        // A site language we have no built-in widget text for gets the English
        // defaults translated once (title, welcome, input hint) and cached.
        add_action('admin_init', array($this, 'maybe_localize_site_defaults'), 20);

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
        add_action('wp_ajax_bitbot_gather_continue', array($this, 'ajax_gather_continue'));
        add_action('wp_ajax_bitbot_generate', array($this, 'ajax_generate'));
        add_action('wp_ajax_bitbot_get_upload_url', array($this, 'ajax_get_upload_url'));
        add_action('wp_ajax_bitbot_generate_from_storage', array($this, 'ajax_generate_from_storage'));
        add_action('wp_ajax_bitbot_generate_from_doc_url', array($this, 'ajax_generate_from_doc_url'));
        add_action('wp_ajax_bitbot_discover_topics', array($this, 'ajax_discover_topics'));
        add_action('wp_ajax_bitbot_get_draft', array($this, 'ajax_get_draft'));
        add_action('wp_ajax_bitbot_publish_draft', array($this, 'ajax_publish_draft'));
        add_action('wp_ajax_bitbot_delete_draft', array($this, 'ajax_delete_draft'));

        // Phase 2 (Resident AI) — content edit proposals apply channel.
        add_action('wp_ajax_bitbot_get_content_edits', array($this, 'ajax_get_content_edits'));
        add_action('wp_ajax_bitbot_apply_content_edit', array($this, 'ajax_apply_content_edit'));
        add_action('wp_ajax_bitbot_reject_content_edit', array($this, 'ajax_reject_content_edit'));
        add_action('wp_ajax_bitbot_upload_chat_image', array($this, 'ajax_upload_chat_image'));
        // Edits apply SYNCHRONOUSLY: the backend calls /wp-apply with one edit
        // and gets the real outcome in the same request (see rest_wp_apply).
        // The pending-queue poller, its retry cron, and the post-turn kick are
        // gone — the queue rows are pure history now.
        add_action('rest_api_init', array($this, 'register_auto_apply_route'));
        // Clean up the old WP-cron poller left by earlier builds (2.1.54-55).
        if (wp_next_scheduled('bitbot_auto_apply_cron')) {
            wp_clear_scheduled_hook('bitbot_auto_apply_cron');
        }

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
        add_action('wp_ajax_bitbot_connect', array($this, 'ajax_connect'));
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
                    'postsPerMonth'        => 1,
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
            /* translators: %s: plan label e.g. "Assistant" or "Agent" */
            $label = sprintf( __( '%s plan', 'bitbot' ), BitBot_Plan::label( $plan ) );
            ?>
            <div class="notice" style="border-left-color: #22863a; padding: 10px 14px;">
                <p style="margin: 0; font-size: 14px;">
                    <strong style="color: #22863a;"><?php echo esc_html( $label ); ?></strong>
                    — <?php esc_html_e( 'all features unlocked. Thanks for supporting Youami!', 'bitbot' ); ?>
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
                        absint( $chat_used ), absint( $chat_cap ), absint( $post_used ), absint( $post_cap ), absint( $leads_used ), absint( $leads_cap )
                    );
                    ?>
                </div>
                <div>
                    <a href="<?php echo esc_url( $upgrade_url ); ?>" class="button button-primary">
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
        $bitbot_label  = __( 'Youami', 'bitbot' ) . $top_bubble;

        add_menu_page(
            __( 'Youami', 'bitbot' ),
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
        $page = sanitize_key( wp_unslash( $_GET['page'] ) );
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
        wp_safe_redirect( admin_url( $map[ $page ] ) );
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
        register_setting('bitbot_settings', 'bitbot_pill_height', array(
            'type'              => 'integer',
            'sanitize_callback' => array( $this, 'sanitize_pill_height' ),
            'default'           => 28,
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
        // WhatsApp click-to-chat: the owner's number (E.164, optional) and a
        // switch. Click-to-chat only: no WhatsApp account or API.
        register_setting('bitbot_settings', 'bitbot_whatsapp_number', array(
            'type'              => 'string',
            'sanitize_callback' => array( 'BitBot_WhatsApp', 'sanitize_number' ),
            'default'           => '',
        ));
        register_setting('bitbot_settings', 'bitbot_whatsapp_enabled', array(
            'type'              => 'string',
            'sanitize_callback' => array( 'BitBot_WhatsApp', 'sanitize_enabled' ),
            'default'           => '0',
        ));
        // Whether Enter sends the visitor's message (default off: they click Send).
        register_setting('bitbot_settings', 'bitbot_enter_to_send', array(
            'type'              => 'string',
            'sanitize_callback' => array( 'BitBot_WhatsApp', 'sanitize_enabled' ),
            'default'           => '0',
        ));
        // Consent switches (default off): the public "Powered by" link and
        // anonymous usage analytics in wp-admin.
        register_setting('bitbot_settings', BitBot_Consent::OPT_POWERED_BY, array(
            'type'              => 'string',
            'sanitize_callback' => array( 'BitBot_Consent', 'sanitize_flag' ),
            'default'           => '0',
        ));
        register_setting('bitbot_settings', BitBot_Consent::OPT_ANALYTICS, array(
            'type'              => 'string',
            'sanitize_callback' => array( 'BitBot_Consent', 'sanitize_flag' ),
            'default'           => '0',
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
                <?php esc_html_e( 'Pick the tier that matches how you use Youami. Upgrade or downgrade any time — billing prorates automatically.', 'bitbot' ); ?>
            </p>

            <div id="bitbot-pricing-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">

                <?php // ===== FREE ===== ?>
                <div class="bitbot-pricing-card" data-tier="free" style="background: #fff; border: 1px solid <?php echo $is_free ? '#0073aa' : '#e0e0e0'; ?>; border-radius: 8px; padding: 24px; box-shadow: <?php echo $is_free ? '0 0 0 2px #0073aa' : 'none'; ?>; display: flex; flex-direction: column;">
                    <h2 style="margin: 0 0 4px;"><?php esc_html_e( 'Free', 'bitbot' ); ?></h2>
                    <p style="font-size: 24px; font-weight: 600; margin: 0 0 4px;"><?php esc_html_e( 'Free', 'bitbot' ); ?> <span style="font-size: 14px; font-weight: 400; color: #666;"><?php esc_html_e( 'forever', 'bitbot' ); ?></span></p>
                    <p style="color: #666; margin: 0 0 18px; min-height: 36px;"><?php esc_html_e( 'Try the chatbot, capture your first leads, and see how visitors engage.', 'bitbot' ); ?></p>
                    <ul style="list-style: none; padding: 0; margin: 0 0 18px; flex: 1;">
                        <li style="margin: 6px 0;">✓ <?php esc_html_e( '100 chat messages per month', 'bitbot' ); ?></li>
                        <li style="margin: 6px 0;">✓ <?php esc_html_e( '3 Topic Discovery searches in total', 'bitbot' ); ?></li>
                        <li style="margin: 6px 0;">✓ <?php esc_html_e( '2 forms and enquiry counts', 'bitbot' ); ?></li>
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
                        <li style="margin: 6px 0;">✓ <?php esc_html_e( '10,000 chat messages per month', 'bitbot' ); ?> <span style="color: #888; font-size: 12px;"><?php esc_html_e( '(fair use applies)', 'bitbot' ); ?></span></li>
                        <li style="margin: 6px 0;">✓ <?php esc_html_e( 'AI post generation', 'bitbot' ); ?></li>
                        <li style="margin: 6px 0;">✓ <?php esc_html_e( 'Captured lead details and transcripts', 'bitbot' ); ?></li>
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
                <?php esc_html_e( 'Cancel any time from Youami → Dashboard. We don\'t charge for support, setup, or onboarding. All paid plans include fair-use limits — see', 'bitbot' ); ?>
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
            // Tier-aware checkout: clicking "Get Assistant" / "Get Agent" hits
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
            <h1><?php esc_html_e('Youami Dashboard', 'bitbot'); ?></h1>

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
                                    printf( esc_html__( 'Your paid access continues until %s, then the site drops to the Free plan.', 'bitbot' ), esc_html( gmdate( 'F j, Y', strtotime( $current_period_end ) ) ) );
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
                                printf(
                                    /* translators: %s: fair-use policy link */
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
                                absint( $draft_total )
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
            <h1><?php esc_html_e('Youami Settings', 'bitbot'); ?></h1>
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
            <?php esc_html_e( 'Distance from the chosen corner. If another floating widget is already there, Youami will automatically stack on top of it — adjust manually if you want a different gap.', 'bitbot' ); ?>
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
                    <?php echo esc_attr( $disabled_attr ); ?>
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
                        <?php echo esc_attr( $disabled_attr ); ?>
                    >
                </label>
                <label style="display:flex; flex-direction:column; gap:4px; flex:2;">
                    <span style="font-weight:500;"><?php esc_html_e( 'CTA button URL (optional)', 'bitbot' ); ?></span>
                    <input
                        type="url"
                        id="bitbot-pinned-cta-url"
                        value="<?php echo esc_attr( $cta_url ); ?>"
                        placeholder="https://yoursite.com/contact"
                        <?php echo esc_attr( $disabled_attr ); ?>
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
                <input type="checkbox" id="bitbot-nudge-enabled" value="1" <?php checked( $enabled ); ?> <?php echo esc_attr( $disabled_attr ); ?>>
                <span style="font-weight:500;"><?php esc_html_e( 'Enable proactive nudge', 'bitbot' ); ?></span>
                <?php if ( ! $is_paid ) : ?>
                    <span style="background:#e9e3ff; color:#4b2bcc; padding:1px 8px; border-radius:10px; font-size:11px; font-weight:600;"><?php esc_html_e( 'Assistant', 'bitbot' ); ?></span>
                <?php endif; ?>
            </label>

            <label style="display:flex; align-items:center; gap:8px;">
                <span style="font-weight:500;"><?php esc_html_e( 'Show after N seconds:', 'bitbot' ); ?></span>
                <input type="number" id="bitbot-nudge-delay" min="2" max="60" step="1" value="<?php echo esc_attr( $delay ); ?>" style="width:80px;" <?php echo esc_attr( $disabled_attr ); ?>>
            </label>

            <fieldset style="border:1px solid #ddd; padding:12px; border-radius:6px;">
                <legend style="padding:0 8px; font-weight:500;"><?php esc_html_e( 'Variant', 'bitbot' ); ?></legend>
                <label style="display:flex; flex-direction:column; gap:6px;">
                    <span><input type="radio" name="bitbot-nudge-mode" value="simple" <?php checked( $mode, 'simple' ); ?> <?php echo esc_attr( $disabled_attr ); ?>> <?php esc_html_e( 'Simple — one static line', 'bitbot' ); ?></span>
                    <input type="text" id="bitbot-nudge-simple" maxlength="80" class="regular-text" value="<?php echo esc_attr( $simple ); ?>" placeholder="<?php esc_attr_e( "Need help finding cottages?", 'bitbot' ); ?>" <?php echo esc_attr( $disabled_attr ); ?>>
                </label>

                <label style="display:flex; flex-direction:column; gap:6px; margin-top:12px;">
                    <span><input type="radio" name="bitbot-nudge-mode" value="page-aware" <?php checked( $mode, 'page-aware' ); ?> <?php echo esc_attr( $disabled_attr ); ?>> <?php esc_html_e( 'Page-aware — different text per URL pattern', 'bitbot' ); ?></span>
                    <textarea id="bitbot-nudge-pages" rows="4" class="large-text code" placeholder="<?php esc_attr_e( "/pricing | Got a question about pricing?\n/contact | Want a quick reply?\n/products/* | Looking for something specific?", 'bitbot' ); ?>" <?php echo esc_attr( $disabled_attr ); ?>><?php echo esc_textarea( $page_rules ); ?></textarea>
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
                    <?php echo esc_attr( $disabled_attr ); ?>
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
                    <?php echo esc_attr( $disabled_attr ); ?>
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
                    <?php echo esc_attr( $disabled_attr ); ?>
                >
                <span class="description"><?php esc_html_e( '0 = disabled', 'bitbot' ); ?></span>
            </label>

            <label style="display:flex; align-items:center; gap:8px;">
                <input
                    type="checkbox"
                    id="bitbot-auto-open-exit"
                    value="1"
                    <?php checked( $exit_intent ); ?>
                    <?php echo esc_attr( $disabled_attr ); ?>
                >
                <span style="font-weight:500;"><?php esc_html_e( 'Open on exit-intent (mouse leaves toward browser top)', 'bitbot' ); ?></span>
            </label>

            <input type="hidden" name="bitbot_auto_open" id="bitbot-auto-open-json" value="<?php echo esc_attr( $raw ); ?>">
            <p class="description" style="margin-top:4px;">
                <?php
                if ( $is_paid ) {
                    esc_html_e( 'Triggers fire once per session. Use sparingly — multiple aggressive triggers can hurt conversion.', 'bitbot' );
                } else {
                    esc_html_e( 'Auto-open needs a paid plan. Upgrade to enable any of the triggers above.', 'bitbot' );
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
                    /* translators: %d: maximum number of chips */
                    printf( esc_html__( 'Up to %d chips. Shown above the welcome message; visitors click to send.', 'bitbot' ), absint( $cap ) );
                } else {
                    /* translators: %d: maximum number of chips */
                    printf( esc_html__( 'Up to %d chips on Free (paid plans unlock more). Shown above the welcome message.', 'bitbot' ), absint( $cap ) );
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
            <p class="description"><?php echo esc_html( $tier_label ); ?></p>
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
                absint( $current_length ),
                absint( $cap ),
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
            echo '<p>' . esc_html__( 'Where Youami should send an email when a new lead comes in. Leave blank to disable notifications.', 'bitbot' ) . '</p>';
            return;
        }

        // Free plan: lead-notification emails are gated to Pro/Pro+. Make
        // that explicit so Free users don't expect mail that never arrives.
        ?>
        <div style="padding: 12px 14px; background: #fff8e5; border: 1px solid #f0c33c; border-radius: 6px; margin-bottom: 8px;">
            <p style="margin: 0 0 6px; font-weight: 600; color: #3c2f00;">
                <?php esc_html_e( 'Email notifications need Assistant or Agent.', 'bitbot' ); ?>
            </p>
            <p style="margin: 0; color: #5c4a00; font-size: 13px;">
                <?php esc_html_e( 'On the Free plan, new leads are counted but no email is sent. Upgrade to Assistant to read lead details and get an email each time a visitor leaves their details.', 'bitbot' ); ?>
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
                esc_html_e( 'Disabled on Free plan. Upgrade to Assistant to enable.', 'bitbot' );
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
                <input type="checkbox" name="bitbot_recovery_config[enabled]" value="1" <?php checked( $enabled, true ); ?> <?php echo esc_attr( $disabled_attr ); ?>>
                <strong><?php esc_html_e( 'Offer a discount code via the Agent', 'bitbot' ); ?></strong>
            </label>

            <p>
                <label style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e( 'Coupon code', 'bitbot' ); ?></label>
                <input type="text" name="bitbot_recovery_config[code]" value="<?php echo esc_attr( $code ); ?>" class="regular-text code" placeholder="SAVE10" <?php echo esc_attr( $disabled_attr ); ?>>
                <span class="description" style="display: block; margin-top: 4px;">
                    <?php esc_html_e( 'The code you have configured in WooCommerce or your checkout. The agent will mention it verbatim.', 'bitbot' ); ?>
                </span>
            </p>

            <p>
                <label style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e( 'Offer description', 'bitbot' ); ?></label>
                <input type="text" name="bitbot_recovery_config[description]" value="<?php echo esc_attr( $description ); ?>" class="regular-text" placeholder="10% off your first order" <?php echo esc_attr( $disabled_attr ); ?>>
                <span class="description" style="display: block; margin-top: 4px;">
                    <?php esc_html_e( 'One short phrase describing the deal. Used in the agent\'s reply.', 'bitbot' ); ?>
                </span>
            </p>
        </fieldset>
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
     * Clamp the Pill button height to 28-56px. Anything outside the range
     * falls back to the default 28.
     *
     * @since 2.1.367
     */
    public function sanitize_pill_height( $value ) {
        $value = (int) $value;
        if ( $value < 28 || $value > 56 ) {
            return 28;
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
        $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
        if ( $scheme !== 'https' && $scheme !== 'http' ) {
            return '';
        }
        $path  = wp_parse_url( $url, PHP_URL_PATH ) ?: '';
        $lower = strtolower( $path );
        // The plugin's own preset avatars are allowed on every tier.
        $presets = (string) wp_parse_url( BITBOT_PLUGIN_URL . 'assets/avatars/', PHP_URL_PATH );
        if ( '' !== $presets && 0 === strpos( $path, $presets ) && preg_match( '#^[a-z0-9-]+\.svg$#', substr( $lower, strlen( $presets ) ) ) ) {
            return $url;
        }
        // File-extension whitelist.
        $free_exts = array( '.png', '.jpg', '.jpeg', '.webp', '.gif' );
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

        // The backend (sites.voice_calls_enabled) is the truth the widget needs.
        // Bring the option the widget reads in line with it; on any failure the
        // option stays as it is. A mirror, so it waits 5 seconds at most: the
        // settings screen must not hang on a slow backend.
        $voice_res = $this->api_client->get_voice_calls( 5 );
        if ( ! empty( $voice_res['success'] ) && isset( $voice_res['data']['enabled'] ) ) {
            update_option( 'bitbot_voice_calls_enabled', $voice_res['data']['enabled'] ? '1' : '0' );
        }

        // Machine translations made under older rules are remade before they are shown.
        $this->refresh_stale_auto_copy();

        return new WP_REST_Response( array(
            'enabled'              => (bool)   get_option( 'bitbot_enabled', true ),
            'widgetTitle'          => (string) get_option( 'bitbot_widget_title', '' ),
            'widgetCopy'           => (object) array_map(
                function ( $row ) {
                    return array(
                        'title'       => $row['title'],
                        'welcome'     => $row['welcome'],
                        'questions'   => $row['questions'],
                        'placeholder' => $row['placeholder'],
                        'auto'        => $row['auto'],
                        'needsReview' => $row['needs_review'],
                        'failed'      => $row['failed'],
                    );
                },
                BitBot_Widget_Copy::normalize( get_option( BitBot_Widget_Copy::OPTION, array() ) )
            ),
            // What the widget shows while the owner's own field is empty, in the site's language. The admin
            // shows these as the fields' hints and in the preview, so it never promises English on a Greek site.
            'widgetDefaults'       => array(
                'title'       => BitBot_Widget_Copy::default_title( substr( get_locale(), 0, 2 ) ),
                'welcome'     => BitBot_Widget_Copy::default_welcome( substr( get_locale(), 0, 2 ) ),
                'placeholder' => BitBot_Widget_Copy::default_placeholder( substr( get_locale(), 0, 2 ) ),
            ),
            // The pre-chat form's fixed text per offered language, as the widget config carries it.
            'gateCopy'             => (object) BitBot_Widget_Copy::gate_copy_map( array_merge(
                array( substr( get_locale(), 0, 2 ) ),
                array_column( BitBot_Languages::widget_list( get_option( BitBot_Languages::OPTION, array() ), substr( get_locale(), 0, 2 ), BitBot_Languages::all(), BitBot_Languages::detected() ), 'code' )
            ) ),
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
            'pillHeight'           => (int)    get_option( 'bitbot_pill_height', 28 ),
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
            'voiceCallsEnabled'    => get_option( 'bitbot_voice_calls_enabled' ) === '1',
            'whatsappNumber'       => (string) get_option( 'bitbot_whatsapp_number', '' ),
            'whatsappEnabled'      => get_option( 'bitbot_whatsapp_enabled' ) === '1',
            'enterToSend'          => get_option( 'bitbot_enter_to_send' ) === '1',
            'poweredByEnabled'     => BitBot_Consent::powered_by_enabled(),
            'analyticsEnabled'     => BitBot_Consent::analytics_enabled(),
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
            'pillHeight'           => 'bitbot_pill_height',
            'widgetShape'          => 'bitbot_widget_shape',
            'avatarUrl'            => 'bitbot_avatar_url',
            'avatarTransform'      => 'bitbot_avatar_transform',
            'nudge'                => 'bitbot_nudge',
            'autoOpen'             => 'bitbot_auto_open',
            'pinnedMessage'        => 'bitbot_pinned_message',
            'offTopicGuardrails'   => 'bitbot_off_topic_guardrails',
            'notificationEmail'    => 'bitbot_notification_email',
            // Sanitised to E.164 or '' by the registered callback.
            'whatsappNumber'       => 'bitbot_whatsapp_number',
        );

        $saved = array();

        // WhatsApp switch: stored as '1' / '0'.
        if ( array_key_exists( 'whatsappEnabled', $body ) ) {
            update_option( 'bitbot_whatsapp_enabled', BitBot_WhatsApp::sanitize_enabled( $body['whatsappEnabled'] ) );
            $saved[] = 'whatsappEnabled';
        }

        // Enter sends the message: stored as '1' / '0', default off.
        if ( array_key_exists( 'enterToSend', $body ) ) {
            update_option( 'bitbot_enter_to_send', BitBot_WhatsApp::sanitize_enabled( $body['enterToSend'] ) );
            $saved[] = 'enterToSend';
        }

        // Consent switches, default off. Sanitised here as well as through the
        // registered callbacks, because the REST path never fires admin_init.
        if ( array_key_exists( 'poweredByEnabled', $body ) ) {
            update_option( BitBot_Consent::OPT_POWERED_BY, BitBot_Consent::sanitize_flag( $body['poweredByEnabled'] ) );
            $saved[] = 'poweredByEnabled';
        }
        if ( array_key_exists( 'analyticsEnabled', $body ) ) {
            update_option( BitBot_Consent::OPT_ANALYTICS, BitBot_Consent::sanitize_flag( $body['analyticsEnabled'] ) );
            $saved[] = 'analyticsEnabled';
        }

        // Voice calls switch (default off). The backend is the authority: it
        // refuses to turn calls on until a Habitat key is stored, so push first
        // and only mirror the WP option (which drives the widget's Call button)
        // when the backend accepted it.
        if ( array_key_exists( 'voiceCallsEnabled', $body ) ) {
            $voice_on  = ! empty( $body['voiceCallsEnabled'] );
            $voice_res = $this->api_client->set_voice_calls( $voice_on );
            if ( empty( $voice_res['success'] ) ) {
                return new WP_REST_Response( array(
                    'success' => false,
                    'error'   => isset( $voice_res['error'] ) ? (string) $voice_res['error'] : 'Could not update voice calls.',
                ), 400 );
            }
            update_option( 'bitbot_voice_calls_enabled', $voice_on ? '1' : '0' );
            $saved[] = 'voiceCallsEnabled';
        }

        // Languages the site offers. The site language is always in the list and
        // first. The whole list goes as the backend's hints and into the WP
        // option, even when it is just the site language: an empty option means
        // "nothing saved yet, use the detected languages", so a site-only save
        // must stay non-empty to hold. Push first, mirror on success.
        if ( array_key_exists( 'voiceLanguages', $body ) ) {
            $site_code = BitBot_Languages::site_code( get_locale() );
            $codes     = BitBot_Languages::sanitize_selection( $body['voiceLanguages'], $site_code, BitBot_Languages::all() );
            $lang_res  = $this->api_client->set_voice_languages( $codes );
            if ( empty( $lang_res['success'] ) ) {
                return new WP_REST_Response( array(
                    'success' => false,
                    'error'   => isset( $lang_res['error'] ) ? (string) $lang_res['error'] : 'Could not update languages.',
                ), 400 );
            }
            update_option( BitBot_Languages::OPTION, $codes );
            $saved[] = 'voiceLanguages';
        }

        // Translation source before this save, to tell whether the auto
        // translations are stale afterwards. Added languages open with the
        // DEFAULT title and welcome in their language, never a translation of
        // the owner's custom text, so only the suggested
        // questions count here.
        $old_questions = BitBot_Widget_Copy::base_questions( get_option( 'bitbot_suggested_questions', '' ) );

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

        // Per-language widget copy. Owner edits first (the row becomes theirs),
        // then machine translation of every row that is missing or still auto
        // when the base text or the language list changed. Synchronous; a
        // failed translation keeps the old rows and is reported, never fatal.
        $translation_error = '';
        $site_code         = BitBot_Languages::site_code( get_locale() );
        $selection         = BitBot_Languages::effective_selection( get_option( BitBot_Languages::OPTION, array() ), BitBot_Languages::detected(), BitBot_Languages::all() );
        $others            = array_values( array_diff( $selection, array( $site_code ) ) );
        $copy_rows         = BitBot_Widget_Copy::normalize( get_option( BitBot_Widget_Copy::OPTION, array() ) );
        $copy_changed      = false;
        $new_title     = BitBot_Widget_Copy::base_title( '' );
        $new_welcome   = BitBot_Widget_Copy::default_welcome( $site_code );
        $new_placeholder = BitBot_Widget_Copy::default_placeholder();
        $new_questions = BitBot_Widget_Copy::base_questions( get_option( 'bitbot_suggested_questions', '' ) );
        $base_moved    = $new_questions !== $old_questions;
        if ( $base_moved ) {
            // Owner text is never overwritten: it is flagged for review instead.
            $copy_rows    = BitBot_Widget_Copy::flag_review( $copy_rows, $others );
            $copy_changed = true;
        }
        if ( array_key_exists( 'widgetCopy', $body ) ) {
            $copy_rows    = BitBot_Widget_Copy::merge_owner( $copy_rows, $body['widgetCopy'], $others );
            $copy_changed = true;
            $saved[]      = 'widgetCopy';
        }
        $has_base = '' !== $new_title || '' !== $new_welcome || ! empty( $new_questions ) || '' !== $new_placeholder;
        // Rows translated before a base field existed (the default title,
        // say) are filled in on the next save, without touching the rest.
        $needed = $base_moved
            ? BitBot_Widget_Copy::codes_needing_translation( $selection, $site_code, $copy_rows )
            : BitBot_Widget_Copy::incomplete_auto_codes( $selection, $site_code, $copy_rows, $new_title, $new_welcome, $new_questions, $new_placeholder );
        if ( ! empty( $needed ) && $has_base ) {
            $tr = $this->api_client->translate_widget_copy( $new_title, $new_welcome, $site_code, $needed, $new_questions, $new_placeholder );
            $results = ( ! empty( $tr['success'] ) && isset( $tr['data']['copy'] ) && is_array( $tr['data']['copy'] ) ) ? $tr['data']['copy'] : array();
            $copy_rows    = BitBot_Widget_Copy::merge_auto( $copy_rows, $results );
            $copy_rows    = BitBot_Widget_Copy::mark_failed( $copy_rows, array_diff( $needed, array_keys( $results ) ) );
            $copy_changed = true;
            if ( empty( $results ) && ( empty( $tr['success'] ) ) ) {
                $translation_error = isset( $tr['error'] ) && is_string( $tr['error'] ) ? $tr['error'] : __( 'Translations could not be updated.', 'bitbot' );
            } elseif ( count( $results ) < count( $needed ) ) {
                $translation_error = __( 'Some translations could not be updated.', 'bitbot' );
            }
        }

        // Regenerate: translate the asked-for codes now, owner rows included.
        if ( array_key_exists( 'regenerateCopy', $body ) && $has_base ) {
            $regen = BitBot_Widget_Copy::regenerate_codes( $body['regenerateCopy'], $selection, $site_code );
            if ( ! empty( $regen ) ) {
                $tr      = $this->api_client->translate_widget_copy( $new_title, $new_welcome, $site_code, $regen, $new_questions, $new_placeholder );
                $results = ( ! empty( $tr['success'] ) && isset( $tr['data']['copy'] ) && is_array( $tr['data']['copy'] ) ) ? $tr['data']['copy'] : array();
                $copy_rows    = BitBot_Widget_Copy::merge_regenerated( $copy_rows, $results, $regen );
                $copy_changed = true;
                $saved[]      = 'regenerateCopy';
                if ( count( array_intersect( $regen, array_keys( $results ) ) ) < count( $regen ) ) {
                    $translation_error = isset( $tr['error'] ) && is_string( $tr['error'] ) && empty( $results ) ? $tr['error'] : __( 'Some translations could not be updated.', 'bitbot' );
                }
            }
        }
        if ( $copy_changed ) {
            update_option( BitBot_Widget_Copy::OPTION, $copy_rows );
            $saved[] = 'widgetCopy';
        }

        // The widget's look is BAKED INTO PAGE HTML (class-widget.php prints
        // offsets/color/radius/shape inline on the host div), so on a
        // page-cached host (LiteSpeed et al.) a saved appearance change keeps
        // serving the OLD markup until the cache happens to expire — the owner
        // dials X/Y and the live site visibly ignores them. Same treatment a
        // look-and-feel apply already gets: purge after save. Only fires when
        // a setting the front end actually renders changed — chat-behaviour
        // settings (instructions, fallback copy) ride the API, not the page.
        $rendered_in_page_html = array(
            'enabled', 'widgetTitle', 'widgetPosition', 'widgetOffsetX', 'widgetOffsetY',
            'primaryColor', 'welcomeMessage', 'placeholderText', 'hideOnPages',
            'mobileBehaviour', 'cornerRadius', 'pillHeight', 'widgetShape', 'avatarUrl',
            'avatarTransform', 'nudge', 'autoOpen', 'suggestedQuestions',
            'whatsappNumber', 'whatsappEnabled', 'widgetCopy', 'poweredByEnabled', 'enterToSend',
        );
        if ( array_intersect( $saved, $rendered_in_page_html ) ) {
            $this->purge_page_caches();
        }

        $response = array(
            'success' => true,
            'saved'   => array_values( array_unique( $saved ) ),
        );
        if ( '' !== $translation_error ) {
            $response['translationError'] = $translation_error;
        }
        return new WP_REST_Response( $response, 200 );
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

        // Self-heal, two tiers. Tier 1: while a run is active, every poll
        // nudges wp-cron so the chain keeps advancing even on a zero-traffic
        // site. Tier 2 (the one that matters on cheap hosts): if the run has
        // not advanced for 90s, wp-cron/loopback is dead on this host —
        // spawn_cron() is a no-op there, and a customer site sat on
        // "0 of 44 pages (0%)" for good because NOTHING else could ever run a
        // tick. So the poll itself runs one batch inline, throttled to one
        // attempt per 60s across concurrent polls. Progress then rides the
        // dashboard being open, with no dependency on the host's cron at all.
        if (isset($progress['state']) && $progress['state'] === 'running') {
            if (function_exists('spawn_cron')) {
                spawn_cron();
            }
            $updated_at = isset($progress['updated_at']) ? (int) $progress['updated_at'] : 0;
            $stalled    = ( time() - $updated_at ) > 90;
            if ($stalled && false === get_transient('bitbot_sync_inline_tick')) {
                set_transient('bitbot_sync_inline_tick', 1, 60);
                BitBot::get_instance()->run_sync_chunk(
                    isset($progress['cursor']) ? (int) $progress['cursor'] : 0,
                    (string) get_option(BitBot::SYNC_RUN_OPTION, '')
                );
                // Report the state the inline tick just produced, not the
                // stale snapshot read before it ran.
                $progress = get_option(BitBot::SYNC_PROGRESS_OPTION, $progress);
            }
        }

        $total = isset($progress['total']) ? (int) $progress['total'] : 0;
        $done  = isset($progress['done']) ? (int) $progress['done'] : 0;
        $pct   = $total > 0 ? (int) floor(min($done, $total) / $total * 100) : 0;

        $response = array(
            'state'   => isset($progress['state']) ? $progress['state'] : 'idle',
            'done'    => min($done, $total),
            'total'   => $total,
            'percent' => $pct,
            'error'   => isset($progress['error']) ? $progress['error'] : null,
        );
        // Gather v2 render pass (only present when the backend switched it on).
        $gather = BitBot::get_instance()->gather_v2()->status();
        if (isset($gather['state']) && 'off' !== $gather['state']) {
            $response['gather'] = $gather;
        }
        wp_send_json_success($response);
    }

    /**
     * AJAX: continue a stalled or paused gather v2 render pass. One tick, and the
     * cooldown still applies (a probe is not offered from the admin).
     */
    public function ajax_gather_continue() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        wp_send_json_success(BitBot::get_instance()->continue_gather(false));
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

    /* ---------------------------------------------------------------------
     * Phase 2 (Resident AI) — content edit proposals apply channel.
     *
     * The agent proposes editor-agnostic edits to existing posts (SEO title,
     * meta description, focus keyphrase, post title, tags, excerpt, status).
     * These handlers let the owner review the queue and apply/reject/revert.
     * The actual WordPress write lives here (the plugin is the only component
     * with WP privileges); the backend just stores the proposals + status.
     * ------------------------------------------------------------------- */

    /**
     * List content edit proposals (default: pending queue). Pass status to
     * fetch history (applied|rejected|reverted|failed).
     */
    public function ajax_get_content_edits() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : null;
        $result = $this->api_client->get_content_edit_proposals($status);

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * Approve + apply one proposal: fetch it, write the change to WordPress,
     * capture the resulting revision id, and report 'applied' back. Any WP
     * failure reports 'failed' so the queue reflects reality.
     */
    public function ajax_apply_content_edit() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $proposal_id = isset($_POST['proposal_id']) ? sanitize_text_field(wp_unslash($_POST['proposal_id'])) : '';
        if ('' === $proposal_id) {
            wp_send_json_error(array('error' => 'proposal_id is required'));
        }

        $proposal = $this->fetch_content_edit_proposal($proposal_id);
        if (is_wp_error($proposal)) {
            wp_send_json_error(array('error' => $proposal->get_error_message()));
        }

        // Same single-flight lock as the auto-apply poller: applies mutate
        // the whole-option overlay manifest read-modify-write, and two
        // concurrent applies clobber each other's override keys (a live
        // override whose manifest key vanished later reads as owner-
        // customised — a false consent veto).
        if (get_transient('bitbot_auto_apply_lock')) {
            wp_send_json_error(array('error' => 'Another change is applying right now — retry in a few seconds.'));
        }
        set_transient('bitbot_auto_apply_lock', 1, MINUTE_IN_SECONDS);
        try {
            $result = $this->apply_proposal($proposal);
        } finally {
            delete_transient('bitbot_auto_apply_lock');
        }
        if (is_wp_error($result)) {
            wp_send_json_error(array('error' => $result->get_error_message()));
        }
        wp_send_json_success($result);
    }

    /**
     * Apply one proposal to WordPress and report the status back to the
     * backend. Shared by the manual approve (ajax) and the auto-apply poller
     * (cron), so both paths behave identically. Returns the success payload
     * array, or WP_Error (after marking the proposal 'failed').
     *
     * @param array $proposal Proposal from the backend (must include id, kind,
     *                        wpPostId, afterValue).
     * @return array|WP_Error
     */
    private function apply_proposal($proposal) {
        $proposal_id = isset($proposal['id']) ? sanitize_text_field((string) $proposal['id']) : '';
        if ('' === $proposal_id) {
            return new WP_Error('bitbot_no_id', 'Proposal is missing its id.');
        }

        $kind = (string) $proposal['kind'];

        // Administrative kinds (plugin/theme install, permalinks) are never
        // applied; report the refusal back so the proposal does not sit open.
        $refusal = BitBot_Agent_Policy::kind_refusal($kind);
        if (null !== $refusal) {
            $this->api_client->update_content_edit_status($proposal_id, 'failed', null, $refusal);
            return new WP_Error('bitbot_kind_refused', $refusal);
        }

        // create_page is handled here (not in write_content_edit) because its
        // revert data (the created page id + prior homepage settings) only
        // exists AFTER the page is created.
        if ('create_page' === $kind) {
            $created = $this->create_page_apply($proposal['afterValue'] ?? null);
            if (is_wp_error($created)) {
                $this->api_client->update_content_edit_status($proposal_id, 'failed', null, $created->get_error_message());
                return $created;
            }
            // Args: (id, status, wp_revision_id, apply_error, before_value) —
            // the created page id + prior homepage settings go in before_value
            // (5th), NOT apply_error (4th), so revert can restore + trash later.
            $this->api_client->update_content_edit_status($proposal_id, 'applied', null, null, $created['before']);
            // Surface the READ-BACK facts (create_page_apply re-fetched the
            // page) so the caller reports what WordPress holds, not the request.
            return array(
                'proposalId'    => $proposal_id,
                'status'        => 'applied',
                'pageId'        => $created['pageId'],
                'pageStatus'    => isset($created['status']) ? $created['status'] : null,
                'verifiedTitle' => isset($created['verifiedTitle']) ? $created['verifiedTitle'] : null,
                'link'          => isset($created['link']) ? $created['link'] : null,
                // What became of the PREVIOUS homepage. Dropping these here is
                // how the caller ends up unable to say whether the old front
                // page was tidied away or is still sitting there live.
                'supersededPage'    => isset($created['supersededPage']) ? $created['supersededPage'] : null,
                'previousFrontPage' => isset($created['previousFrontPage']) ? $created['previousFrontPage'] : null,
                // Each requested image: in the Media Library (attachmentId,
                // blocks pointed at it) or failed (reason, no remote URL left).
                'imagesSideloaded'  => isset($created['imagesSideloaded']) ? $created['imagesSideloaded'] : array(),
                'imagesFailed'      => isset($created['imagesFailed']) ? $created['imagesFailed'] : array(),
            );
        }
        // wp_rest: the GENERAL write. The agent named { method, route, params }
        // and this dispatches it through WordPress's OWN REST controllers as the
        // scoped agent user — correct slashing/KSES/caps/revisions, no per-kind
        // PHP branch. Handled here (not in write_content_edit) because it is
        // site-scoped (the route carries the target, not a wp_post_id) and
        // captures its own before-snapshot for the history record.
        if ('wp_rest' === $kind) {
            $res = $this->apply_wp_rest($proposal);
            if (is_wp_error($res)) {
                $this->api_client->update_content_edit_status($proposal_id, 'failed', null, $res->get_error_message());
                return $res;
            }
            $this->api_client->update_content_edit_status(
                $proposal_id,
                'applied',
                $res['wpRevisionId'],
                null,
                (null !== $res['before']) ? wp_json_encode($res['before']) : null
            );
            // Flush caches so the change is LIVE before the next step verifies
            // by looking (and refresh the style snapshot for design routes).
            // Skip the full flush for routes that render nothing on the front
            // end (comment moderation) — a comment approval shouldn't purge the
            // whole object cache and push a style snapshot.
            $rest_route = strtolower((string) (is_array($proposal['afterValue']) ? ($proposal['afterValue']['route'] ?? '') : ''));
            if (1 !== preg_match('#^/?wp/v2/comments(/|$|\?)#', $rest_route)) {
                $this->after_style_apply();
            }
            return array('proposalId' => $proposal_id, 'status' => 'applied', 'result' => $res['result']);
        }
        // nav_menu is special-cased here too: it builds a menu + records the ids
        // needed to revert (prior location assignment + whether we created it).
        if ('nav_menu' === $kind) {
            $av = $proposal['afterValue'] ?? null;
            $items = (is_array($av) && isset($av['items']) && is_array($av['items'])) ? $av['items'] : array();
            $location = (is_array($av) && isset($av['location']) && is_string($av['location'])) ? sanitize_text_field($av['location']) : null;
            $res = $this->apply_nav_menu($items, $location, BitBot_Nav_Location::keeps_page_list($av));
            if (is_wp_error($res)) {
                $this->api_client->update_content_edit_status($proposal_id, 'failed', null, $res->get_error_message());
                return $res;
            }
            $this->api_client->update_content_edit_status($proposal_id, 'applied', null, null, $res['before']);
            $this->after_style_apply();
            return array_merge(
                array('proposalId' => $proposal_id, 'status' => 'applied', 'menuId' => $res['menuId'], 'assignedTo' => $res['assignedTo']),
                null !== $res['locationFact'] ? array('locationFact' => $res['locationFact']) : array(),
                isset($res['pinned']) ? array('pinned' => $res['pinned']) : array(),
                isset($res['pinFact']) ? array('pinFact' => $res['pinFact']) : array()
            );
        }
        // template_part: the generic chrome playground — the agent names an
        // AREA (header/footer/any part) and authors the full block markup;
        // this guarantees delivery (consent, cross-theme slug handling,
        // verified resolution, revert).
        if ('template_part' === $kind) {
            $res = $this->apply_template_part_override($proposal['afterValue'] ?? null);
            if (is_wp_error($res)) {
                $this->api_client->update_content_edit_status($proposal_id, 'failed', null, $res->get_error_message());
                return $res;
            }
            $this->api_client->update_content_edit_status($proposal_id, 'applied', null, null, $res['before']);
            $this->after_style_apply();
            return array('proposalId' => $proposal_id, 'status' => 'applied', 'partId' => $res['partId']);
        }
        // template: the TEMPLATE playground — the agent authors a whole page
        // template (front-page, single, archive, …); same guarantee shape as
        // template_part (consent, verified resolution, revert).
        if ('template' === $kind) {
            $res = $this->apply_template_override($proposal['afterValue'] ?? null);
            if (is_wp_error($res)) {
                $this->api_client->update_content_edit_status($proposal_id, 'failed', null, $res->get_error_message());
                return $res;
            }
            $this->api_client->update_content_edit_status($proposal_id, 'applied', null, null, $res['before']);
            $this->after_style_apply();
            return array('proposalId' => $proposal_id, 'status' => 'applied', 'templateId' => $res['templateId']);
        }
        // global_styles: the theme's REAL colours/fonts (block themes). The
        // plugin owns id resolution (the wp_global_styles CPT the front end
        // applies) — the agent only declares the desired settings/styles.
        if ('global_styles' === $kind) {
            $res = $this->apply_global_styles($proposal['afterValue'] ?? null);
            if (is_wp_error($res)) {
                $this->api_client->update_content_edit_status($proposal_id, 'failed', null, $res->get_error_message());
                return $res;
            }
            $this->api_client->update_content_edit_status($proposal_id, 'applied', null, null, $res['before']);
            $this->after_style_apply();
            return array('proposalId' => $proposal_id, 'status' => 'applied', 'result' => $res['result']);
        }
        // publish_post: create a real WordPress post from a backend draft and
        // publish it. before_value = { createdPostId } so revert can trash it.
        if ('publish_post' === $kind) {
            $av      = $proposal['afterValue'] ?? null;
            $draftId = (is_array($av) && !empty($av['draftId'])) ? sanitize_text_field((string) $av['draftId']) : '';
            $status  = (is_array($av) && !empty($av['status'])) ? (string) $av['status'] : 'publish';
            if ('' === $draftId) {
                $err = new WP_Error('bitbot_bad_publish', 'publish_post needs a draftId.');
                $this->api_client->update_content_edit_status($proposal_id, 'failed', null, $err->get_error_message());
                return $err;
            }
            $res = $this->api_client->create_wp_post_from_draft($draftId, $status);
            if (empty($res['success'])) {
                $msg = $res['error'] ?? 'Publishing the post failed.';
                $this->api_client->update_content_edit_status($proposal_id, 'failed', null, $msg);
                return new WP_Error('bitbot_publish_failed', $msg);
            }
            $post_id = (int) ($res['data']['post_id'] ?? 0);
            // Atomic delivery: if the proposal carries a resolved featured image
            // (publishAllDrafts / publishPostToWordpress fold one in), sideload +
            // set it now so the published post has a picture in one step. Best-
            // effort — a failed image never fails the publish (the post is live).
            if ($post_id && is_array($av) && !empty($av['imageUrl'])) {
                $this->apply_featured_image($post_id, array(
                    'imageUrl' => (string) $av['imageUrl'],
                    'alt'      => isset($av['imageAlt']) ? (string) $av['imageAlt'] : '',
                ));
            }
            // Round-trip the wp_post_id back so the draft deep-links to WP AND
            // the dashboard flips the draft to "published" (so it is never
            // re-published). The backend enum is draft|scheduled|published|
            // failed, so map WP's post_status ('publish'/'future'/'private')
            // onto it — passing 'publish' verbatim makes LinkDraftToWp reject
            // it ("Invalid status") and the draft silently stays 'draft',
            // which then re-publishes on the next batch run.
            $wp_status   = (string) ($res['data']['status'] ?? $status);
            $dash_status = 'draft';
            if ('publish' === $wp_status || 'private' === $wp_status) {
                $dash_status = 'published';
            } elseif ('future' === $wp_status) {
                $dash_status = 'scheduled';
            }
            $this->api_client->link_draft_to_wp($draftId, $post_id, $dash_status);
            $this->api_client->update_content_edit_status($proposal_id, 'applied', null, null, array('createdPostId' => $post_id));
            return array('proposalId' => $proposal_id, 'status' => 'applied', 'postId' => $post_id);
        }
        // body: snapshot the post's LIVE content at apply time. The agent's
        // proposePostEdit beforeValue is whatever it held in context — a
        // 663-char stub of a 15k homepage shipped once, and reverting wrote
        // the stub as the whole page. Reverts restore what was ACTUALLY
        // there, never the writer's memory of it.
        if ('body' === $kind || 'replace_content_image' === $kind || 'replace_content_text' === $kind || 'remove_content_blocks' === $kind) {
            $prev_body = get_post_field('post_content', (int) ($proposal['wpPostId'] ?? 0));
            if (is_string($prev_body) && '' !== $prev_body) {
                $before_value = $prev_body;
            }
        }
        // Capture the post's current featured image so revert can restore it.
        if ('set_featured_image' === $kind) {
            $before_value = array('prevThumbId' => (int) get_post_thumbnail_id((int) ($proposal['wpPostId'] ?? 0)));
        }
        $applied = $this->write_content_edit(
            (int) ($proposal['wpPostId'] ?? 0),
            $kind,
            $proposal['afterValue'] ?? null
        );

        if (is_wp_error($applied)) {
            $this->api_client->update_content_edit_status($proposal_id, 'failed', null, $applied->get_error_message());
            return $applied;
        }

        // SEO writes are only real when GOOGLE can see them. Saving the field
        // is not the outcome — the live <head> rendering it is (found live:
        // Rank Math stored every field while emitting nothing, and the agent
        // reported success over an invisible result). Purge the page's cache,
        // fetch it fresh (cache-buster query defeats page caches), and check
        // the just-written value actually renders; absent → the apply FAILS
        // with the evidence. Unreachable loopback (code 0) → proceed without
        // verifying, same posture as the permalink check.
        // Keyed on WHAT WAS WRITTEN, not on which tool wrote it. A general
        // wp_rest write that sets the same SEO field must earn the same proof;
        // otherwise the day the agent writes SEO freehand, the verification
        // silently stops applying and we relearn the Rank Math lesson.
        $seo_type    = in_array($kind, array('seo_title', 'seo_description'), true) ? $kind : '';
        $seo_post_id = (int) ($proposal['wpPostId'] ?? 0);
        $seo_value   = '' !== $seo_type ? trim((string) ($proposal['afterValue'] ?? '')) : '';
        if ('' === $seo_type && 'wp_rest' === $kind) {
            $spec_v   = isset($proposal['afterValue']) && is_array($proposal['afterValue']) ? $proposal['afterValue'] : array();
            $route_v  = strtolower((string) ($spec_v['route'] ?? ''));
            $params_v = isset($spec_v['params']) && is_array($spec_v['params']) ? $spec_v['params'] : array();
            $meta_v   = isset($params_v['meta']) && is_array($params_v['meta']) ? $params_v['meta'] : array();
            if (preg_match('#^/wp/v2/(?:posts|pages)/(\d+)#', $route_v, $rm_v)) {
                $flat = array_merge($params_v, $meta_v);
                foreach (array('rank_math_title', '_yoast_wpseo_title', 'bitbot_seo_title') as $k_v) {
                    if (!empty($flat[$k_v])) { $seo_type = 'seo_title'; $seo_value = trim((string) $flat[$k_v]); break; }
                }
                if ('' === $seo_type) {
                    foreach (array('rank_math_description', '_yoast_wpseo_metadesc', 'bitbot_seo_description') as $k_v) {
                        if (!empty($flat[$k_v])) { $seo_type = 'seo_description'; $seo_value = trim((string) $flat[$k_v]); break; }
                    }
                }
                if ('' !== $seo_type) { $seo_post_id = (int) $rm_v[1]; }
            }
        }
        if ('' !== $seo_type) {
            if ($seo_post_id && '' !== $seo_value) {
                clean_post_cache($seo_post_id);
                do_action('litespeed_purge_post', $seo_post_id);
                $check_url = add_query_arg('bbseo', (string) time(), get_permalink($seo_post_id));
                $res  = wp_remote_get($check_url, array('timeout' => 10, 'redirection' => 3));
                $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
                if (200 === $code) {
                    $html     = (string) wp_remote_retrieve_body($res);
                    $head_end = stripos($html, '</head>');
                    $head     = substr($html, 0, false !== $head_end ? $head_end : 20000);
                    $needle   = strtolower(html_entity_decode(substr($seo_value, 0, 40), ENT_QUOTES));
                    $rendered = false;
                    $evidence = '';
                    if ('seo_title' === $seo_type) {
                        $live_title = '';
                        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $head, $tm)) {
                            $live_title = trim(wp_strip_all_tags($tm[1]));
                            $rendered   = false !== strpos(strtolower(html_entity_decode($tm[1], ENT_QUOTES)), $needle);
                        }
                        $evidence = 'the live <title> is "' . ('' !== $live_title ? $live_title : '(none)') . '"';
                    } else {
                        $has_tag  = preg_match('/<meta[^>]+name=["\']description["\'][^>]*>/i', $head, $dm);
                        $rendered = $has_tag && false !== strpos(strtolower(html_entity_decode($dm[0], ENT_QUOTES)), $needle);
                        $evidence = $has_tag
                            ? 'a meta description tag exists but does not contain the new text'
                            : 'the page renders NO meta description tag at all';
                    }
                    if (!$rendered) {
                        $msg = sprintf(
                            'Saved to the SEO fields, but the live page does not RENDER it — %s. The change is invisible to Google until the head actually carries it.',
                            $evidence
                        );
                        $this->api_client->update_content_edit_status($proposal_id, 'failed', null, $msg);
                        return new WP_Error('bitbot_seo_not_rendered', $msg);
                    }
                }
            }
        }

        $revision_id = $this->latest_revision_id((int) ($proposal['wpPostId'] ?? 0));
        $this->api_client->update_content_edit_status($proposal_id, 'applied', $revision_id, null, $before_value);
        // Same re-keying: a freehand write that changes how the site LOOKS
        // needs the caches purged as much as a custom_css apply does.
        $touches_look = ('custom_css' === $kind) || ('global_styles' === $kind) || ('template' === $kind) || ('template_part' === $kind);
        if (!$touches_look && 'wp_rest' === $kind) {
            $spec_l  = isset($proposal['afterValue']) && is_array($proposal['afterValue']) ? $proposal['afterValue'] : array();
            $route_l = strtolower((string) ($spec_l['route'] ?? ''));
            $touches_look = (bool) preg_match('#^/wp/v2/(settings|global-styles|templates|template-parts|navigation)#', $route_l);
        }
        if ($touches_look) {
            $this->after_style_apply();
        }

        // Postmeta-only writes (SEO fields, tags) never fire save_post, so
        // the deferred single-post sync that keeps the index honest never
        // triggers — the agent then reads yesterday's values from the index
        // while the live page already renders the new ones (the exact
        // "split-brain" a review chased). Schedule the same debounced sync
        // the save_post hook uses.
        if (in_array($kind, array('seo_title', 'seo_description', 'focus_keyphrase', 'tags'), true)) {
            $sync_post_id = (int) ($proposal['wpPostId'] ?? 0);
            if ($sync_post_id > 0) {
                $sync_args = array($sync_post_id);
                $pending = wp_next_scheduled('bitbot_sync_post', $sync_args);
                if ($pending) {
                    wp_unschedule_event($pending, 'bitbot_sync_post', $sync_args);
                }
                wp_schedule_single_event(time() + 10, 'bitbot_sync_post', $sync_args);
            }
        }

        $out = array(
            'proposalId'   => $proposal_id,
            'status'       => 'applied',
            'wpRevisionId' => $revision_id,
        );
        // Kinds that measured their own outcome (replace_content_image returns
        // { swapped, thumbnailSwapped }) surface those counts to the backend as
        // ground truth — the caller reads them off applyResult.
        if (is_array($applied)) {
            $out = array_merge($applied, $out);
        }
        return $out;
    }


    /**
     * permission_callback for the routes the youami.ai backend calls with this
     * site's own api key. The key is compared in constant time against the
     * stored one. Never public: a missing, empty or wrong key is refused with a
     * 401 before any handler runs.
     *
     * @param WP_REST_Request $request
     * @return true|WP_Error
     */
    public function permission_api_key($request) {
        return BitBot_Consent::check_api_key($request);
    }

    /**
     * Register the backend-callable REST endpoint that runs the auto-apply
     * poller. The backend hits this right after an agent run (interactive or
     * scheduled) that created proposals under "Accept all & run".
     */
    public function register_auto_apply_route() {
        // Synchronous apply: the backend sends ONE edit and this applies it NOW
        // through the same guarded apply switch (fragment bounds, guardrails,
        // revert capture) and returns the real outcome — no pending queue, no
        // poller, no kick. Auth by the site's own api_key (constant-time).
        register_rest_route('bitbot/v1', '/wp-apply', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_wp_apply'),
            'permission_callback' => array($this, 'permission_api_key'),
        ));
        // Backend-triggered content sync: lets the AGENT refresh the index
        // itself (new pages — e.g. theme demo pages after a switch — are
        // invisible to its editing/cleanup tools until synced). Same apiKey
        // auth; kicks the existing resumable background sync.
        // Backfill provenance for content BitBot created BEFORE the stamp
        // existed. Not agent-reachable: the backend calls this only with ids it
        // already holds authorship records for, so nothing can talk its own way
        // into owner-content privileges.
        register_rest_route('bitbot/v1', '/stamp-generated', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_stamp_generated'),
            'permission_callback' => array($this, 'permission_api_key'),
        ));
        register_rest_route('bitbot/v1', '/run-sync', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_run_sync'),
            'permission_callback' => array($this, 'permission_api_key'),
        ));
        // Media library listing for the agent's browseMediaLibrary tool — the
        // agent should reach for images the site already OWNS before fetching
        // stock. Same apiKey auth as the other server-to-server routes.
        register_rest_route('bitbot/v1', '/list-media', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_list_media'),
            'permission_callback' => array($this, 'permission_api_key'),
        ));
        // Bulk WebP migration (agent's convertLibraryToWebp tool): one BATCH
        // per call — non-destructive twins + content/thumbnail reference
        // rewrite, cursor-driven so any library size survives request limits.
        register_rest_route('bitbot/v1', '/convert-images-webp', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_convert_images_webp'),
            'permission_callback' => array($this, 'permission_api_key'),
        ));
        // ONE read over the whole design surface (agent's getSiteDesign tool):
        // summary tree of every template + part (source, ours, rendered) plus
        // nav/identity/styles state, and node mode for any single item's full
        // markup. Read-before-write for chrome and templates — bespoke
        // per-surface reads kept leaving gaps (the agent edited menu ITEMS for
        // several turns trying to remove a CTA button that lived in its own
        // header part markup, because parts had no read).
        register_rest_route('bitbot/v1', '/design-tree', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_design_tree'),
            'permission_callback' => array($this, 'permission_api_key'),
        ));
        // LIVE editable source of one post (agent's getPostSource tool). Reads
        // post_content straight from WordPress so the read reflects the agent's
        // own just-applied edits — the pages index only refreshes on sync, so
        // reading it back mid-turn returned the pre-edit body and the agent
        // looped, re-proposing changes it had already made. Same apiKey auth.
        register_rest_route('bitbot/v1', '/post-source', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_post_source'),
            'permission_callback' => array($this, 'permission_api_key'),
        ));
        // The GENERAL WordPress read: the agent names any core REST route
        // ({ route, query }) and this dispatches it in-process as the scoped
        // agent user and returns the JSON. One endpoint replaces post-source,
        // list-media, and most of design-tree — new capability = a new route
        // string, no new PHP. Writes do NOT go here; they ride the proposal
        // channel (kind 'wp_rest') so guardrails/kick/revert still apply.
        register_rest_route('bitbot/v1', '/wp-read', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_wp_read'),
            'permission_callback' => array($this, 'permission_api_key'),
        ));
        // Byte-exact grep across post/page bodies (agent's grepPosts tool).
        // Semantic search answers "what talks about X"; this answers "which
        // EXACT BYTES exist where" — the contract surgical edits need. Matches
        // come back as exact-byte context windows lifted from post_content.
        register_rest_route('bitbot/v1', '/grep-posts', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_grep_posts'),
            'permission_callback' => array($this, 'permission_api_key'),
        ));
    }

    /**
     * REST handler: byte-exact search over post/page bodies. Plain substring
     * by default, optional bounded regex. Snippets are exact bytes from
     * post_content so a caller can feed them straight back into a byte-exact
     * find contract. Caps are explicit in the response — never silent.
     *
     * @param WP_REST_Request $request { apiKey, find, isRegex?, caseInsensitive?, postTypes?, statuses? }
     * @return WP_REST_Response
     */
    public function rest_grep_posts($request) {
        // Authenticated by permission_api_key() before this runs.
        $find = (string) $request->get_param('find');
        if ('' === $find) {
            return new WP_REST_Response(array('error' => 'find required'), 400);
        }
        if (strlen($find) > 500) {
            return new WP_REST_Response(array('error' => 'find too long (max 500 chars)'), 400);
        }
        $is_regex = (bool) $request->get_param('isRegex');
        $ci       = (bool) $request->get_param('caseInsensitive');
        $types    = (array) ($request->get_param('postTypes') ?: array('post', 'page'));
        $types    = array_values(array_intersect(array_map('sanitize_key', $types), array('post', 'page')));
        if (empty($types)) {
            $types = array('post', 'page');
        }

        $pattern = null;
        if ($is_regex) {
            $pattern = '#' . str_replace('#', '\#', $find) . '#s' . ($ci ? 'i' : '');
            if (false === @preg_match($pattern, '')) {
                return new WP_REST_Response(array('error' => 'invalid regex'), 400);
            }
        }

        $context_chars    = 120;
        $max_per_post     = 10;
        $max_total        = 80;
        $ids = get_posts(array(
            'post_type'   => $types,
            'post_status' => array('publish', 'draft', 'future', 'pending', 'private'),
            'numberposts' => -1,
            'fields'      => 'ids',
        ));
        $results = array();
        $total_returned = 0;
        $total_matches  = 0;
        foreach ($ids as $post_id) {
            $content = (string) get_post_field('post_content', $post_id);
            if ('' === $content) {
                continue;
            }
            $offsets = array();
            if ($is_regex) {
                if (preg_match_all($pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
                    foreach ($m[0] as $hit) {
                        $offsets[] = array((int) $hit[1], strlen($hit[0]));
                    }
                }
            } else {
                $pos = 0;
                while (false !== ($pos = $ci ? stripos($content, $find, $pos) : strpos($content, $find, $pos))) {
                    $offsets[] = array($pos, strlen($find));
                    $pos += max(1, strlen($find));
                }
            }
            if (empty($offsets)) {
                continue;
            }
            $total_matches += count($offsets);
            $snippets = array();
            foreach (array_slice($offsets, 0, $max_per_post) as $o) {
                if ($total_returned >= $max_total) {
                    break;
                }
                list($start, $len) = $o;
                $snippets[] = array(
                    'offset' => $start,
                    'before' => substr($content, max(0, $start - $context_chars), min($context_chars, $start)),
                    'match'  => substr($content, $start, $len),
                    'after'  => substr($content, $start + $len, $context_chars),
                );
                $total_returned++;
            }
            $post = get_post($post_id);
            $results[] = array(
                'wpPostId'   => (int) $post_id,
                'title'      => $post ? (string) $post->post_title : '',
                'status'     => $post ? (string) $post->post_status : '',
                'postType'   => $post ? (string) $post->post_type : '',
                'matchCount' => count($offsets),
                'snippets'   => $snippets,
            );
            if ($total_returned >= $max_total) {
                break;
            }
        }
        return new WP_REST_Response(array(
            'ok'              => true,
            'postsScanned'    => count($ids),
            'postsWithMatches' => count($results),
            'totalMatches'    => $total_matches,
            'snippetsReturned' => $total_returned,
            'truncated'       => $total_matches > $total_returned,
            'results'         => $results,
        ), 200);
    }

    /**
     * REST handler: the LIVE editable source of one post. Returns
     * { wpPostId, title, builtWith, bodyEditable, rawContent } plus the
     * awareness fields (awareness_for) read directly
     * from WordPress (get_post), so the agent's source read always reflects the
     * current post — including edits it applied moments earlier this turn.
     * apiKey-authed like the other server-to-server routes.
     *
     * @param WP_REST_Request $request { apiKey, postId }
     * @return WP_REST_Response
     */
    /** The most items one awareness batch answers; the caller is told the bound. */
    const AWARENESS_BATCH_MAX = 500;

    /**
     * The link targets in the navigation a visitor sees, read once per request.
     *
     * @var array|null
     */
    private $nav_targets_cache = null;

    /**
     * What an item IS on this site, for the agent to say plainly (brief
     * section 4): what built it, its assigned template, its roles, and whether
     * BitBot made it. Roles are computed live on every read, never stored.
     *
     * createdByBitBot reads both provenance stamps: `_bitbot_generated` on
     * everything BitBot creates and `_bitbot_created` on pages proposeCreatePage
     * made; either is a fact that BitBot made it.
     *
     * @param int     $post_id
     * @param WP_Post $post
     * @return array{builtWith:string, postType:string, slug:string, template:array, roles:array, createdByBitBot:bool}
     */
    public function awareness_for($post_id, $post) {
        $post_id = (int) $post_id;
        $reading = array(
            'show_on_front'  => (string) get_option('show_on_front', 'posts'),
            'page_on_front'  => (int) get_option('page_on_front', 0),
            'page_for_posts' => (int) get_option('page_for_posts', 0),
        );
        return array(
            'builtWith'       => $this->api_client->detect_built_with($post_id, $post),
            'postType'        => (string) $post->post_type,
            'slug'            => (string) $post->post_name,
            // The assigned one; the template it RENDERS through on a block theme
            // is resolved by the API from the hierarchy (lib/templateUsage.ts).
            'template'        => array('assigned' => (string) get_post_meta($post_id, '_wp_page_template', true)),
            'roles'           => BitBot_API_Client::roles_from(
                $post_id,
                (string) $post->post_type,
                (string) $post->post_status,
                BitBot_API_Client::nav_url_key((string) get_permalink($post_id), home_url('/')),
                $reading,
                $this->nav_targets(),
                BitBot_API_Client::nav_url_key(home_url('/'), home_url('/'))
            ),
            'createdByBitBot' => BitBot::is_bitbot_generated($post_id) || '' !== (string) get_post_meta($post_id, '_bitbot_created', true),
        );
    }

    /**
     * Every link target in the navigation a visitor sees: classic menus that
     * sit in a theme location, and on block themes the navigation blocks in
     * the header part(s) the templates render. Menus assigned nowhere do not
     * render, so they do not count.
     *
     * @return array{ids:array, urls:array, allPages:bool, unresolved:bool}
     */
    private function nav_targets() {
        if (null !== $this->nav_targets_cache) {
            return $this->nav_targets_cache;
        }
        $nav  = array('ids' => array(), 'urls' => array(), 'allPages' => false, 'unresolved' => false);
        $home = home_url('/');
        foreach ((array) get_nav_menu_locations() as $menu_id) {
            if ((int) $menu_id <= 0) {
                continue;
            }
            foreach ((array) wp_get_nav_menu_items((int) $menu_id) as $item) {
                if (!is_object($item)) {
                    continue;
                }
                if ('post_type' === $item->type) {
                    $nav['ids'][(int) $item->object_id] = true;
                } elseif (!empty($item->url)) {
                    $key = BitBot_API_Client::nav_url_key((string) $item->url, $home);
                    if ('' !== $key) {
                        $nav['urls'][$key] = true;
                    }
                }
            }
        }
        if (function_exists('wp_is_block_theme') && wp_is_block_theme() && function_exists('get_block_templates')) {
            $rendered = $this->template_referenced_part_slugs();
            foreach ((array) get_block_templates(array('area' => 'header'), 'wp_template_part') as $part) {
                if (in_array((string) $part->slug, $rendered, true)) {
                    $this->collect_nav_targets(parse_blocks((string) $part->content), $nav, $home, 0);
                }
            }
        }
        $this->nav_targets_cache = $nav;
        return $nav;
    }

    /**
     * Walk blocks for navigation link targets. Follows what WordPress follows
     * when it renders: a navigation block's `ref`, its inner blocks, or, with
     * neither, the most recently published navigation (core's own first
     * fallback); synced patterns and registered patterns by reference.
     *
     * @param array  $blocks parse_blocks() output.
     * @param array  $nav    Accumulator (see nav_targets).
     * @param string $home   home_url('/').
     * @param int    $depth  Recursion guard.
     */
    private function collect_nav_targets($blocks, &$nav, $home, $depth) {
        if ($depth > 8) {
            return;
        }
        foreach ((array) $blocks as $block) {
            $name  = isset($block['blockName']) ? (string) $block['blockName'] : '';
            $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();
            $inner = isset($block['innerBlocks']) && is_array($block['innerBlocks']) ? $block['innerBlocks'] : array();
            if ('core/navigation' === $name) {
                if (!empty($attrs['ref'])) {
                    $ref = get_post((int) $attrs['ref']);
                    // An unpublished or missing ref renders nothing.
                    if ($ref && 'wp_navigation' === $ref->post_type && 'publish' === $ref->post_status) {
                        $this->collect_nav_targets(parse_blocks((string) $ref->post_content), $nav, $home, $depth + 1);
                    }
                } elseif (!empty($inner)) {
                    $this->collect_nav_targets($inner, $nav, $home, $depth + 1);
                } else {
                    $latest = get_posts(array('post_type' => 'wp_navigation', 'post_status' => 'publish', 'numberposts' => 1, 'orderby' => 'date', 'order' => 'DESC'));
                    if (!empty($latest)) {
                        $this->collect_nav_targets(parse_blocks((string) $latest[0]->post_content), $nav, $home, $depth + 1);
                    } else {
                        // Core would build a fallback of its own here; not read.
                        $nav['unresolved'] = true;
                    }
                }
                continue;
            }
            if ('core/navigation-link' === $name || 'core/navigation-submenu' === $name) {
                // Only a post-type link's id is a post id; a term link carries a
                // term id (kind taxonomy, or type category/tag without a kind).
                $post_link = isset($attrs['kind'])
                    ? 'post-type' === $attrs['kind']
                    : !(isset($attrs['type']) && in_array($attrs['type'], array('category', 'tag', 'post_tag'), true));
                if (!empty($attrs['id']) && $post_link) {
                    $nav['ids'][(int) $attrs['id']] = true;
                } elseif (!empty($attrs['url'])) {
                    $key = BitBot_API_Client::nav_url_key((string) $attrs['url'], $home);
                    if ('' !== $key) {
                        $nav['urls'][$key] = true;
                    }
                }
            } elseif ('core/home-link' === $name) {
                $nav['urls'][BitBot_API_Client::nav_url_key($home, $home)] = true;
            } elseif ('core/page-list' === $name) {
                $parent = isset($attrs['parentPageID']) ? (int) $attrs['parentPageID'] : 0;
                if ($parent > 0) {
                    foreach ((array) get_pages(array('child_of' => $parent)) as $child) {
                        $nav['ids'][(int) $child->ID] = true;
                    }
                } else {
                    $nav['allPages'] = true;
                }
            } elseif ('core/block' === $name && !empty($attrs['ref'])) {
                $synced = get_post((int) $attrs['ref']);
                if ($synced && 'wp_block' === $synced->post_type) {
                    $this->collect_nav_targets(parse_blocks((string) $synced->post_content), $nav, $home, $depth + 1);
                }
            } elseif ('core/pattern' === $name && !empty($attrs['slug']) && class_exists('WP_Block_Patterns_Registry')) {
                $pattern = WP_Block_Patterns_Registry::get_instance()->get_registered((string) $attrs['slug']);
                if (is_array($pattern) && !empty($pattern['content'])) {
                    $this->collect_nav_targets(parse_blocks((string) $pattern['content']), $nav, $home, $depth + 1);
                }
            }
            if (!empty($inner)) {
                $this->collect_nav_targets($inner, $nav, $home, $depth + 1);
            }
        }
    }

    /**
     * The front page and the posts page as items, for getSiteDesign (brief
     * section 4): null when the Reading settings name no such page.
     *
     * @return array{showOnFront:string, frontPage:array|null, postsPage:array|null}
     */
    private function reading_items() {
        $show = (string) get_option('show_on_front', 'posts');
        $item = function ($id) {
            $id   = (int) $id;
            $post = $id > 0 ? get_post($id) : null;
            if (!$post) {
                return null;
            }
            return array_merge(array(
                'wpPostId' => $id,
                'title'    => (string) $post->post_title,
                'link'     => (string) get_permalink($id),
            ), $this->awareness_for($id, $post));
        };
        return array(
            'showOnFront' => $show,
            'frontPage'   => 'page' === $show ? $item(get_option('page_on_front', 0)) : null,
            'postsPage'   => 'page' === $show ? $item(get_option('page_for_posts', 0)) : null,
        );
    }

    /**
     * A fingerprint of the post's CURRENT editable state.
     *
     * Exists so a write can prove it is changing the thing that was read.
     * `find` text alone cannot: Codex, on the page the agent edited by mistake —
     * "expected old text alone does not identify the right document when
     * multiple documents contain it." If the same sentence lives on two pages,
     * finding it proves nothing about WHICH page this write landed on, and the
     * page can also be edited by a human in the seconds between the read and
     * the write.
     *
     * Elementor's content is not in post_content, so it fingerprints the
     * element tree through the adapter; everything else hashes the modified
     * stamp with the body, which moves on any edit from any source.
     *
     * @param int          $post_id
     * @param WP_Post|null $post
     * @return string
     */
    public function post_revision($post_id, $post = null) {
        $post = $post ? $post : get_post($post_id);
        if (!$post) {
            return '';
        }
        if ('elementor' === $this->api_client->detect_built_with($post_id, $post)
            && class_exists('BitBot_Elementor_Adapter')
            && !is_wp_error(BitBot_Elementor_Adapter::available())) {
            $doc = \Elementor\Plugin::$instance->documents->get((int) $post_id, false);
            if ($doc) {
                return 'el:' . BitBot_Elementor_Adapter::revision($doc->get_elements_data());
            }
        }
        return 'wp:' . hash('sha256', (string) $post->post_modified_gmt . '|' . (string) $post->post_content);
    }

    public function rest_post_source($request) {
        // Authenticated by permission_api_key() before this runs.
        $theme_type = (function_exists('wp_is_block_theme') && wp_is_block_theme()) ? 'block' : 'classic';
        // BATCH: awareness only, no bodies (listEditablePosts, brief section 4).
        // The same route rather than a second one: it is the same read of the
        // same posts, minus the body.
        $ids = $request->get_param('postIds');
        if (is_array($ids)) {
            $ids     = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, self::AWARENESS_BATCH_MAX);
            $items   = array();
            $missing = array();
            foreach ($ids as $id) {
                $p = get_post($id);
                if (!$p) {
                    $missing[] = $id;
                    continue;
                }
                $items[] = array_merge(array('wpPostId' => $id), $this->awareness_for($id, $p));
            }
            return new WP_REST_Response(array(
                'themeType' => $theme_type,
                'items'     => $items,
                'missing'   => $missing,
                'max'       => self::AWARENESS_BATCH_MAX,
            ), 200);
        }
        $post_id = (int) $request->get_param('postId');
        if ($post_id <= 0) {
            return new WP_REST_Response(array('error' => 'postId required'), 400);
        }
        $post = get_post($post_id);
        if (!$post) {
            return new WP_REST_Response(array('error' => 'post not found'), 404);
        }
        $awareness     = $this->awareness_for($post_id, $post);
        $built_with    = $awareness['builtWith'];
        $body_editable = in_array($built_with, array('block', 'classic'), true);
        $response = array_merge(array(
            'wpPostId'     => $post_id,
            'title'        => (string) $post->post_title,
            'themeType'    => $theme_type,
        ), $awareness, array(
            'bodyEditable' => $body_editable,
            // Carry this into the write. It is how the write proves it changed
            // the page that was read, at the state it was read in.
            'revision'     => $this->post_revision($post_id, $post),
            'link'         => (string) get_permalink($post_id),
            'rawContent'   => $body_editable ? (string) $post->post_content : null,
        ));
        // SAY WHAT IS ACTUALLY POSSIBLE, not just what is impossible.
        //
        // `bodyEditable: false` is still true of an Elementor page — there is no
        // post_content to edit. But 2.1.273 can change literal text inside it
        // through Elementor's own save, and the first live run after shipping
        // that showed the cost of not saying so: the agent read this response,
        // concluded "I cannot edit page-builder content", and told the owner to
        // do it by hand, over a capability sitting right there. A field that
        // reports only the absence teaches the agent it is powerless.
        if ('elementor' === $built_with) {
            $response['textEditable']      = true;
            $response['textEditableScope'] = 'Literal text inside Elementor heading and text widgets, via proposeReplaceText on this postId. Structure, styles, links, dynamic tags and third-party widgets are NOT editable and are refused by name.';
            // Which texts, by their current words (round 4 E1: the agent
            // called the theme's page title and a button label editable).
            $raw  = get_post_meta($post_id, '_elementor_data', true);
            $tree = is_string($raw) && '' !== $raw ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
            if (is_array($tree) && class_exists('BitBot_Elementor_Adapter')) {
                $response['editableTexts'] = BitBot_Elementor_Adapter::editable_texts($tree);
            }
        }
        return new WP_REST_Response($response, 200);
    }

    // ===================================================================
    //  General WordPress driving — the agent names a core REST route and
    //  we dispatch it IN-PROCESS (rest_do_request) as a scoped agent user.
    //  This is what lets the agent do anything an editor/admin can inside
    //  the WordPress it resides in, without a per-capability PHP branch and
    //  without leaving the box (no HTTP, past any bot wall). Reads go through
    //  /wp-read synchronously; writes ride the proposal channel (kind
    //  'wp_rest') so guardrails, the apply kick, and history all still apply.
    // ===================================================================

    /**
     * The scoped WordPress user the agent's REST dispatch acts as. Created
     * lazily and idempotently the first time the agent drives WordPress.
     *
     * Its role `bitbot_agent` grants the content / page / media / taxonomy /
     * comment caps plus edit_theme_options (menus, templates) — NOT
     * manage_options, switch_themes, activate_plugins, create_users / edit_users / delete_users /
     * promote_users, install_plugins / install_themes / update_core, edit_files
     * (theme/plugin editor), or unfiltered_html. Because apply runs
     * `wp_set_current_user($this)`, WordPress's own current_user_can() becomes
     * the ceiling on every dispatched controller: a run cannot reach a
     * capability this role lacks, and lacking unfiltered_html means
     * wp_kses_post() always runs on injected markup.
     *
     * @return int User id, or 0 on failure.
     */
    private function ensure_agent_user() {
        $role_key = BitBot_Agent_Policy::ROLE_KEY;
        if (!get_role($role_key)) {
            add_role($role_key, BitBot_Agent_Policy::ROLE_NAME, BitBot_Agent_Policy::role_caps());
            update_option(BitBot_Agent_Policy::ROLE_OPTION, BitBot_Agent_Policy::ROLE_VERSION, true);
        }
        self::upgrade_agent_role();
        // Only an account the plugin can prove is its own (BitBot_Agent_User):
        // an account that merely has the login is never adopted or promoted.
        $uid = BitBot_Agent_User::ensure($role_key);
        if ($uid) {
            $user = get_user_by('id', $uid);
            if ($user) {
                self::harden_agent_user($user);
            }
        }
        return (int) $uid;
    }

    /**
     * Boundary on the general capability. Default-allow inside the WordPress
     * CORE namespace (/wp/v2/*), because the scoped role caps are the real
     * ceiling — but HARD-DENY the routes/keys that are privilege escalation or
     * irreversible, regardless of caps or the autonomy toggle:
     *   - user create/edit/delete/promote          (/wp/v2/users)
     *   - plugin/theme install/activate/switch      (/wp/v2/plugins, /themes)
     *   - permanent force-delete                     (DELETE ...?force=true)
     *   - site URL / admin email                     (settings url/email keys)
     * Custom plugin namespaces (wc/v3, etc.) are out of scope until vetted.
     *
     * @param string $method
     * @param string $route
     * @param array  $params
     * @return bool
     */
    private function agent_route_allowed($method, $route, $params = array()) {
        return BitBot_Agent_Policy::route_allowed($method, $route, $params);
    }

    /**
     * Bring an existing `bitbot_agent` role in line with the current policy.
     * Installs created before the policy tightened hold manage_options in
     * their database; add_role() is skipped when the role exists, so this
     * removes every capability the policy no longer grants. Idempotent and
     * guarded by a stored version, so it costs one option read when current.
     * Runs on `init` and again whenever the agent user is provisioned.
     */
    public static function upgrade_agent_role() {
        if ((int) get_option(BitBot_Agent_Policy::ROLE_OPTION, 0) >= BitBot_Agent_Policy::ROLE_VERSION) {
            return;
        }
        $role = get_role(BitBot_Agent_Policy::ROLE_KEY);
        $clean = true;
        if ($role) {
            // The role belongs to the plugin's own account alone (ROLE_VERSION 5).
            // The version is recorded only once no other account holds it, so an
            // unfinished cleanup runs again on the next load.
            $clean = BitBot_Agent_User::demote_strangers(BitBot_Agent_Policy::ROLE_KEY);
            self::rename_agent_role_and_user();
            foreach (BitBot_Agent_Policy::caps_to_remove($role->capabilities) as $cap) {
                $role->remove_cap($cap);
            }
            foreach (BitBot_Agent_Policy::role_caps() as $cap => $grant) {
                if (empty($role->capabilities[$cap])) {
                    $role->add_cap($cap, $grant);
                }
            }
        }
        if ($clean) {
            update_option(BitBot_Agent_Policy::ROLE_OPTION, BitBot_Agent_Policy::ROLE_VERSION, true);
        }
    }

    /**
     * Idempotent rename of what owners can read: the role's display name and
     * the agent user's profile description. The role key and the user login
     * never change. Called from the role upgrade, so it runs once per
     * ROLE_VERSION.
     */
    /**
     * The agent user holds exactly the agent role and no direct capability.
     * Extra roles or capabilities an earlier state left on the account are removed.
     */
    private static function harden_agent_user($user) {
        $role_key = BitBot_Agent_Policy::ROLE_KEY;
        foreach (BitBot_Agent_Policy::stray_user_entries((array) $user->caps, $role_key) as $entry) {
            $user->remove_cap($entry);
        }
        if (!in_array($role_key, (array) $user->roles, true) || count((array) $user->roles) !== 1) {
            $user->set_role($role_key);
        }
    }

    private static function rename_agent_role_and_user() {
        $wp_roles = wp_roles();
        $key      = BitBot_Agent_Policy::ROLE_KEY;
        if (isset($wp_roles->roles[$key]) && BitBot_Agent_Policy::ROLE_NAME !== ($wp_roles->roles[$key]['name'] ?? '')) {
            $wp_roles->roles[$key]['name']      = BitBot_Agent_Policy::ROLE_NAME;
            $wp_roles->role_names[$key]         = BitBot_Agent_Policy::ROLE_NAME;
            update_option($wp_roles->role_key, $wp_roles->roles);
        }
        $user = BitBot_Agent_User::get();
        if ($user) {
            self::harden_agent_user($user);
        }
        if ($user && BitBot_Agent_Policy::AGENT_DESCRIPTION !== (string) $user->description) {
            wp_update_user(array('ID' => (int) $user->ID, 'description' => BitBot_Agent_Policy::AGENT_DESCRIPTION));
        }
    }

    /**
     * The agent's site settings, in plugin code. Reads return only the keys in
     * BitBot_Agent_Policy::settings_map() (shaped like core's /wp/v2/settings so
     * existing readers keep working); writes validate against the same fixed
     * list and call update_option on it. Reached only after the apiKey check
     * on /wp-read and /wp-apply. No site URL, admin email, language, timezone
     * or any other option is readable or writable, and no user is impersonated.
     *
     * @param string $method GET|POST|PUT|PATCH|DELETE
     * @param string $route  The settings route (may carry a query string).
     * @param array  $params Query (GET) or body params.
     * @return array|WP_Error
     */
    private function agent_settings($method, $route, $params) {
        $map = BitBot_Agent_Policy::settings_map();
        if ('GET' !== $method) {
            if (!in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
                return new WP_Error('bitbot_route_denied', 'That WordPress route is not permitted for the agent: ' . $method . ' ' . $route, array('status' => 403));
            }
            $plan = BitBot_Agent_Policy::settings_write_plan($params);
            if (empty($plan['ok'])) {
                return new WP_Error('bitbot_bad_settings', $plan['error'], array('status' => 400));
            }
            foreach ($plan['pages'] as $page_id) {
                if ('page' !== get_post_type($page_id) || 'trash' === get_post_status($page_id)) {
                    return new WP_Error('bitbot_bad_settings', 'Page ' . (int) $page_id . ' does not exist.', array('status' => 400));
                }
            }
            foreach ($plan['media'] as $att_id) {
                if ('attachment' !== get_post_type($att_id) || 0 !== strpos((string) get_post_mime_type($att_id), 'image/')) {
                    return new WP_Error('bitbot_bad_settings', 'Attachment ' . (int) $att_id . ' is not an image in the media library.', array('status' => 400));
                }
            }
            foreach ($plan['options'] as $option => $value) {
                update_option($option, $value);
            }
        }
        $query = array();
        $qpos  = strpos((string) $route, '?');
        if (false !== $qpos) {
            parse_str(substr((string) $route, $qpos + 1), $query);
        }
        $fields = 'GET' === $method ? array_merge($query, $params) : array();
        $keys   = array_keys($map);
        if (!empty($fields['_fields'])) {
            $want = array_map('trim', explode(',', is_array($fields['_fields']) ? implode(',', $fields['_fields']) : (string) $fields['_fields']));
            $keys = array_values(array_intersect($keys, $want));
        }
        $out = array();
        foreach ($keys as $key) {
            $value = get_option($map[$key]);
            $out[$key] = in_array($key, array('page_on_front', 'page_for_posts', 'posts_per_page', 'site_logo', 'site_icon'), true)
                ? (int) $value
                : (string) $value;
        }
        return $out;
    }

    /**
     * Dispatch one WordPress core REST call IN-PROCESS as the scoped agent
     * user. No HTTP, so it is immune to any front-door bot wall (Cloudflare);
     * capability, KSES-by-caps, revisions, and — critically — content SLASHING
     * are all the framework's job (controllers wp_slash() the prepared array
     * before wp_insert_post, so we pass UNSLASHED body params and content
     * round-trips verbatim; no `\n`→`n` corruption possible).
     *
     * @param string $method GET|POST|PUT|PATCH|DELETE
     * @param string $route  e.g. /wp/v2/pages/23
     * @param array  $params body params (writes) or query params (GET)
     * @return mixed|WP_Error Decoded response data, or WP_Error on denial/failure.
     */
    private function agent_rest_dispatch($method, $route, $params) {
        $method = strtoupper((string) $method);
        $route  = '/' . ltrim((string) $route, '/');
        $params = is_array($params) ? $params : array();
        // Site settings are served by the plugin itself, on a fixed key list,
        // and never reach core's settings controller or the agent user.
        if (BitBot_Agent_Policy::is_settings_route($route)) {
            return $this->agent_settings($method, $route, $params);
        }
        // Split an inline query string off the route. The agent (and our own
        // tool/prompt copy) commonly passes '/wp/v2/pages/23?context=edit';
        // WP_REST_Request needs the BARE path to match a registered route (a
        // '?...' suffix 404s) with the query folded into params.
        $inline = array();
        $qpos   = strpos($route, '?');
        if (false !== $qpos) {
            $qs    = substr($route, $qpos + 1);
            $route = substr($route, 0, $qpos);
            if ('' !== $qs) {
                parse_str($qs, $inline);
            }
        }

        // Authorise the FINAL request: the route's own query string, the explicit
        // params and the body all count, so ?force=true cannot slip past the guard.
        if (!$this->agent_route_allowed($method, $route, array_merge($inline, $params))) {
            return new WP_Error('bitbot_route_denied', 'That WordPress route is not permitted for the agent: ' . $method . ' ' . $route, array('status' => 403));
        }
        if (!function_exists('rest_do_request') || !class_exists('WP_REST_Request')) {
            return new WP_Error('bitbot_no_rest', 'The WordPress REST API is unavailable.', array('status' => 500));
        }
        $uid = $this->ensure_agent_user();
        if ($uid <= 0) {
            return new WP_Error('bitbot_no_agent_user', 'Could not provision the agent user.', array('status' => 500));
        }
        // Ensure the REST server is up (our handlers are REST requests so it
        // usually is, but the cron retry path may reach here cold).
        rest_get_server();

        // Defence in depth: while the agent user is current, hard-reject any
        // change to the site URL / home / admin email options — a second lock
        // under the route guard so a guard miss can never reach an account-
        // takeover / lockout option.
        $reject_option = function ($value, $old_value) {
            return $old_value;
        };
        $guarded_options = array('siteurl', 'home', 'admin_email', 'new_admin_email');
        foreach ($guarded_options as $opt) {
            add_filter('pre_update_option_' . $opt, $reject_option, 10, 2);
        }

        $prev = get_current_user_id();
        wp_set_current_user($uid);
        try {
            $req = new WP_REST_Request($method, $route);
            if (in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
                $req->set_body_params($params);
                if (!empty($inline)) {
                    $req->set_query_params($inline);
                }
            } else {
                $req->set_query_params(array_merge($inline, $params));
            }
            $res = rest_do_request($req);
            if ($res->is_error()) {
                $err    = $res->as_error();
                $status = (int) $res->get_status();
                return new WP_Error(
                    $err->get_error_code() ?: 'bitbot_rest_error',
                    $err->get_error_message() ?: 'The WordPress REST call failed.',
                    array('status' => $status ?: 500)
                );
            }
            return rest_get_server()->response_to_data($res, false);
        } finally {
            // Never leak the agent identity or the option guards into whatever
            // runs after us.
            wp_set_current_user($prev);
            foreach ($guarded_options as $opt) {
                remove_filter('pre_update_option_' . $opt, $reject_option, 10);
            }
        }
    }

    /**
     * REST handler: the general WordPress READ. { apiKey, route, query? } →
     * dispatch GET <route> as the scoped agent user and return { ok, data } (or
     * { ok:false, error }). apiKey-authed like the other server-to-server
     * routes. Read-only — writes ride the proposal channel (kind 'wp_rest').
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    /**
     * Unwrap an opaque request body. The API sends `{ apiKey, payload }` where
     * payload is base64url(JSON) of the fields these routes have always taken
     * (route, query, proposal, …). Edge WAFs match literal `/wp/v2/…` strings in
     * request bodies as site enumeration and blocked our own authenticated
     * reads and writes from the API's datacentre addresses (2026-09-29: every
     * such request answered `1` by the host's Cloudflare). Decoding here, the
     * handlers run unchanged. Plain bodies keep working: nothing is required.
     */
    private function unwrap_opaque_payload($request) {
        $payload = $request->get_param('payload');
        if (!is_string($payload) || '' === $payload) {
            return;
        }
        $raw = base64_decode(strtr($payload, '-_', '+/'), true);
        if (false === $raw) {
            return;
        }
        $fields = json_decode($raw, true);
        if (!is_array($fields)) {
            return;
        }
        foreach ($fields as $k => $v) {
            if ('apiKey' === $k) {
                continue; // the key travels in the clear and was checked as such
            }
            $request->set_param($k, $v);
        }
    }

    public function rest_wp_read($request) {
        $this->unwrap_opaque_payload($request);
        // Authenticated by permission_api_key() before this runs.
        $route = (string) $request->get_param('route');
        if ('' === trim($route)) {
            return new WP_REST_Response(array('error' => 'route required'), 400);
        }
        $query  = $request->get_param('query');
        $query  = is_array($query) ? $query : array();
        $result = $this->agent_rest_dispatch('GET', $route, $query);
        if (is_wp_error($result)) {
            $data   = $result->get_error_data();
            $status = is_array($data) && !empty($data['status']) ? (int) $data['status'] : 400;
            return new WP_REST_Response(array('ok' => false, 'error' => $result->get_error_message()), $status ?: 400);
        }
        // A single post read carries the edit token replace_content_text
        // checks, so the read the agent is told to make is enough to edit
        // (class-read-revision.php has the 2026-10-02 run).
        $result = BitBot_Read_Revision::attach($result, array($this, 'post_revision'));
        return new WP_REST_Response(array('ok' => true, 'data' => $result), 200);
    }

    /**
     * Apply a generic 'wp_rest' proposal: { method, route, params }. Snapshots
     * the target first (so the applied record carries a real before_value for
     * history), dispatches the write through the scoped agent user, and records
     * the revision the write created. Content is verbatim (controller-slashed).
     *
     * @param array $proposal
     * @return array|WP_Error { wpRevisionId, before, result }
     */
    private function apply_wp_rest($proposal) {
        $spec   = isset($proposal['afterValue']) && is_array($proposal['afterValue']) ? $proposal['afterValue'] : array();
        $method = strtoupper((string) ($spec['method'] ?? ''));
        $route  = (string) ($spec['route'] ?? '');
        $params = isset($spec['params']) && is_array($spec['params']) ? $spec['params'] : array();
        if ('' === $method || '' === $route) {
            return new WP_Error('bitbot_bad_wp_rest', 'wp_rest needs { method, route }.');
        }
        if ('GET' === $method) {
            // Reads must use /wp-read, not the write channel (they'd be no-op
            // history noise). Refuse so a mis-issued read fails loudly.
            return new WP_Error('bitbot_wp_rest_read', 'Use the read path for GET, not a wp_rest proposal.');
        }
        // NO RAW ROAD INTO A PAGE BUILDER'S PAGE (backstop; the API refuses
        // first). Round 3 E2/E3: raw writes of _elementor_data, content and
        // _elementor_edit_mode on an Elementor page all applied and left the
        // builder's data, post_content and the rendered page disagreeing.
        if ( in_array( $method, array( 'PUT', 'PATCH', 'POST' ), true )
            && preg_match( '#^/?wp/v2/(?:posts|pages)/(\d+)(?:[/?]|$)#i', $route, $bm ) ) {
            $builder_target = (int) $bm[1];
            $builder_post   = get_post( $builder_target );
            if ( $builder_post ) {
                $refusal = BitBot_API_Client::builder_write_refusal(
                    $this->api_client->detect_built_with( $builder_target, $builder_post ),
                    $params,
                    (string) $builder_post->post_status
                );
                if ( null !== $refusal ) {
                    return new WP_Error( 'bitbot_builder_raw_write', $refusal );
                }
            }
        }
        // PROVENANCE GATE. BitBot restyles what BitBot made. An item that was
        // on the site before it arrived keeps the layout its owner chose, so a
        // `template` assignment is refused there — while every CONTENT edit to
        // the same item stays allowed. Enforced HERE, at the write, not only in
        // the tool description: the agent picks tools by reading text, and text
        // is advice. This is the boundary.
        if ( in_array( $method, array( 'PUT', 'PATCH', 'POST' ), true )
            && preg_match( '#^/wp/v2/(?:posts|pages)/(\d+)#i', $route, $tm )
            && array_key_exists( 'template', $params ) ) {
            $tpl_target = (int) $tm[1];
            if ( ! BitBot::is_bitbot_generated( $tpl_target ) ) {
                return new WP_Error(
                    'bitbot_owner_content_template',
                    sprintf(
                        'Post %d was not created by Youami, so its template is the owner\'s to choose and cannot be changed here. Its CONTENT is still editable — text, images, blocks, SEO. To give it a different layout, the owner assigns the template in WordPress.',
                        $tpl_target
                    )
                );
            }
        }

        // FRAGMENT GUARD, same bounds as the body-edit channel: a content
        // update through the generic write path must not gut a post or page
        // either ("clearing the homepage to diagnose" emptied a live page).
        // A substantial current body replaced by a sliver is a fragment or a
        // wipe — refuse loudly; full rewrites and fresh creations pass.
        if ( in_array( $method, array( 'PUT', 'PATCH' ), true )
            && preg_match( '#^/wp/v2/(?:posts|pages)/(\d+)#i', $route, $pm )
            && isset( $params['content'] ) && is_string( $params['content'] ) ) {
            $target_id   = (int) $pm[1];
            $current_len = strlen( (string) get_post_field( 'post_content', $target_id ) );
            $new_len     = strlen( $params['content'] );
            if ( $current_len >= 2000 && $new_len < max( 600, (int) ( $current_len * 0.3 ) ) ) {
                return new WP_Error(
                    'bitbot_wp_rest_fragment',
                    sprintf(
                        'Refused: the new content is %d chars but this post/page currently holds %d. That looks like a fragment or a wipe, not a full replacement. Send the COMPLETE new content; to rebuild from scratch, write the full replacement in one call.',
                        $new_len,
                        $current_len
                    )
                );
            }
        }
        // A block template or part written here is as much BitBot's as one
        // written by apply_template_override / apply_site_part, and the next
        // slot fill must see it that way. Unrecorded, the fill on `home` was
        // refused as "the owner customised the home template themselves"
        // (live 2026-09-29) — the owner never had. Settled BEFORE the write:
        // an item the owner customised stays theirs whoever writes it next.
        $tpl_write = in_array($method, array('POST', 'PUT', 'PATCH'), true) ? $this->wp_rest_template_target($route) : null;
        $tpl_owner = $tpl_write ? $this->is_owner_template($tpl_write) : false;

        // Snapshot the target before mutating (POST creates — nothing prior).
        $before = null;
        if (in_array($method, array('PUT', 'PATCH', 'DELETE'), true)) {
            $snap = $this->agent_rest_dispatch('GET', $route, array('context' => 'edit'));
            if (!is_wp_error($snap)) {
                $before = $snap;
            }
        }
        $result = $this->agent_rest_dispatch($method, $route, $params);
        if (is_wp_error($result)) {
            return $result;
        }
        // DID THE FIELDS ACTUALLY TAKE? WordPress REST silently DROPS keys that
        // are not in a route's schema: it returns 200 with the resource
        // unchanged and says nothing. We then recorded "applied" over a write
        // that changed nothing.
        //
        // Observed live 2026-08-05: the tagline is `description` on
        // /wp/v2/settings — `blogdescription` is the OPTION name, not the REST
        // field. The agent sent `blogdescription` three times across two turns,
        // was told "applied" every time, looked at the page, saw the old tagline
        // and wrote it again. The one attempt that used `description` worked.
        //
        // Only a TOTAL no-op is refused. Some fields legitimately do not echo
        // back (write-only params), so a partial miss is reported as a note
        // rather than a failure.
        $echoed  = is_array($result) ? $result : array();
        $missing = array();
        if (!empty($params) && is_array($params) && !empty($echoed)) {
            foreach (array_keys($params) as $sent_key) {
                if (!array_key_exists($sent_key, $echoed)) {
                    $missing[] = (string) $sent_key;
                }
            }
        }
        if (!empty($missing) && count($missing) === count($params)) {
            $known = array_slice(array_keys($echoed), 0, 25);
            return new WP_Error(
                'bitbot_wp_rest_ignored',
                sprintf(
                    'WordPress accepted the request and IGNORED every field you sent (%s), so nothing changed. Those names are not in this route\'s REST schema — note that the REST field often differs from the option name (the tagline is `description`, not `blogdescription`). Fields this route actually accepts: %s. Re-send using those names; repeating the same keys will keep changing nothing.',
                    implode(', ', $missing),
                    implode(', ', $known)
                )
            );
        }
        if ($tpl_write && !$tpl_owner) {
            $this->record_wp_rest_template($tpl_write);
        }
        // Record the revision the write created (post types) for WP history.
        $rev = null;
        if (is_array($result) && !empty($result['id']) && function_exists('wp_get_post_revisions')) {
            $revisions = wp_get_post_revisions((int) $result['id'], array('numberposts' => 1));
            if (!empty($revisions)) {
                $ids = array_keys($revisions);
                $rev = (int) reset($ids);
            }
        }
        return array('wpRevisionId' => $rev, 'before' => $before, 'result' => $result);
    }

    /**
     * The block template or template part a wp_rest route addresses, or null.
     * WordPress routes both /wp/v2/templates/<theme>//<slug> and the
     * %2F%2F-encoded form to the same item; so does this.
     *
     * @param string $route
     * @return array{type:string, bucket:string, theme:string, slug:string}|null
     */
    private function wp_rest_template_target($route) {
        $path = rawurldecode((string) strtok((string) $route, '?'));
        if (!preg_match('#^/?wp/v2/(templates|template-parts)/([^/]+)//([^/]+?)/?$#i', $path, $m)) {
            return null;
        }
        $is_part = 'template-parts' === strtolower($m[1]);
        return array(
            'type'   => $is_part ? 'wp_template_part' : 'wp_template',
            'bucket' => $is_part ? 'partOverrides' : 'templateOverrides',
            'theme'  => $m[2],
            'slug'   => $m[3],
        );
    }

    /**
     * Every post id the overlay manifest records as BitBot's, across buckets.
     *
     * @param array $manifest
     * @return int[]
     */
    private function overlay_owned_ids($manifest) {
        $ids = array();
        foreach (array('partOverrides', 'footerOverrides', 'headerOverrides', 'navOverrides', 'templateOverrides', 'overrides') as $bucket) {
            if (!empty($manifest[$bucket]) && is_array($manifest[$bucket])) {
                foreach ($manifest[$bucket] as $v) {
                    $ids[] = (int) $v;
                }
            }
        }
        return $ids;
    }

    /**
     * Whether the template/part is a customisation the owner made: a custom
     * post the manifest does not record as ours. The same test the override
     * applies use before they refuse.
     *
     * @param array $target From wp_rest_template_target().
     * @return bool
     */
    private function is_owner_template(array $target) {
        $current = function_exists('get_block_template') ? get_block_template($target['theme'] . '//' . $target['slug'], $target['type']) : null;
        if (!$current || 'custom' !== $current->source || empty($current->wp_id)) {
            return false;
        }
        $plugin   = class_exists('BitBot') ? BitBot::get_instance() : null;
        $manifest = ($plugin && method_exists($plugin, 'get_overlay_manifest')) ? $plugin->get_overlay_manifest() : array();
        return !in_array((int) $current->wp_id, $this->overlay_owned_ids($manifest), true);
    }

    /**
     * Record the override a wp_rest write produced, exactly as the override
     * applies do: manifest bucket `<theme>::<slug>` => post id, plus a history
     * event. Read back from what WordPress now RESOLVES for the slot, so only
     * a custom post that actually renders there is recorded.
     *
     * @param array $target From wp_rest_template_target().
     */
    private function record_wp_rest_template(array $target) {
        $plugin = class_exists('BitBot') ? BitBot::get_instance() : null;
        if (!$plugin || !method_exists($plugin, 'save_overlay_manifest') || !function_exists('get_block_template')) {
            return;
        }
        $resolved = get_block_template($target['theme'] . '//' . $target['slug'], $target['type']);
        if (!$resolved || 'custom' !== $resolved->source || empty($resolved->wp_id)) {
            return;
        }
        $post_id  = (int) $resolved->wp_id;
        $theme    = (string) $resolved->theme;
        $slug     = (string) $resolved->slug;
        $bucket   = $target['bucket'];
        $key      = $theme . '::' . $slug;
        $manifest = $plugin->get_overlay_manifest();
        if (!isset($manifest[$bucket]) || !is_array($manifest[$bucket])) {
            $manifest[$bucket] = array();
        }
        $refreshed = isset($manifest[$bucket][$key]) && (int) $manifest[$bucket][$key] === $post_id;
        $manifest[$bucket][$key] = $post_id;
        $manifest['history'][] = 'partOverrides' === $bucket
            ? array('event' => $refreshed ? 'part_redesign_refreshed' : 'part_redesign_created', 'theme' => $theme, 'part' => $slug, 'partId' => $post_id, 'via' => 'wp_rest', 'at' => gmdate('c'))
            : array('event' => $refreshed ? 'template_redesign_refreshed' : 'template_redesign_created', 'theme' => $theme, 'template' => $slug, 'templateId' => $post_id, 'via' => 'wp_rest', 'at' => gmdate('c'));
        $plugin->save_overlay_manifest($manifest);
    }

    /**
     * REST handler: the site's design tree. node='' → summary (no bodies);
     * node='part:<area-or-slug>' or 'template:<slug>' → that item with its
     * full current markup, resolved exactly as an apply would resolve it
     * (rendered slot first). apiKey-authed like the other server-to-server
     * routes.
     *
     * @since 2.1.129
     * @param WP_REST_Request $request { apiKey, node? }
     * @return WP_REST_Response
     */
    public function rest_design_tree($request) {
        // Authenticated by permission_api_key() before this runs.
        if (!function_exists('wp_is_block_theme') || !wp_is_block_theme()) {
            // A bare 400 taught the agent the theme type only by FAILING —
            // it planned template work, attempted it, then backtracked
            // (reviewer-confirmed token waste). A classic theme is a valid
            // answer, not an error: state the type and which levers apply.
            $active = wp_get_theme();
            return new WP_REST_Response(array_merge(array(
                'themeType' => 'classic',
                'permalinkStructure' => (string) get_option('permalink_structure', ''),
                'theme'     => $active ? $active->get('Name') : get_stylesheet(),
                'note'      => 'Classic (non-block) theme: there are NO block templates or template parts, so template publishing / proposeSitePart / proposeGlobalStyles do not apply. Presentation levers here: page/post bodies, Additional CSS (proposeSiteStyleEdit), menus (proposeNavMenu), site identity, and widgets/customizer the owner manages in wp-admin.',
                'templates' => array(),
                'parts'     => array(),
                'pageTitle' => BitBot_Page_Title::fact(),
            ), $this->reading_items()), 200);
        }
        $theme      = get_stylesheet();
        $referenced = $this->template_referenced_part_slugs();
        $plugin     = class_exists('BitBot') ? BitBot::get_instance() : null;
        $manifest   = ($plugin && method_exists($plugin, 'get_overlay_manifest')) ? $plugin->get_overlay_manifest() : array();
        $our_ids    = $this->overlay_owned_ids($manifest);
        $is_ours = function ($tpl) use ($our_ids) {
            return $tpl && 'custom' === $tpl->source && !empty($tpl->wp_id) && in_array((int) $tpl->wp_id, $our_ids, true);
        };

        $node = trim((string) $request->get_param('node'));

        // Node mode: one item, full markup.
        if ('' !== $node && preg_match('/^(part|template):(.+)$/', $node, $m)) {
            $kind = $m[1];
            $name = sanitize_key($m[2]);
            if ('template' === $kind) {
                $current = get_block_template($theme . '//' . $name, 'wp_template');
                if (!$current) {
                    return new WP_REST_Response(array('error' => sprintf('No template "%s" on this theme.', $name)), 404);
                }
                return new WP_REST_Response(array(
                    'node' => 'template:' . $name, 'slug' => $name,
                    'source' => (string) $current->source, 'ours' => $is_ours($current),
                    'content' => (string) $current->content,
                ), 200);
            }
            // part: resolve area-or-slug to the RENDERED slot, like an apply.
            $slug_area = array();
            foreach ((array) get_block_templates(array(), 'wp_template_part') as $part) {
                $slug_area[(string) $part->slug] = !empty($part->area) ? (string) $part->area : 'uncategorized';
            }
            $target = '';
            $area_term = isset($slug_area[$name]) ? $slug_area[$name] : (in_array($name, $slug_area, true) ? $name : '');
            if (isset($slug_area[$name])) {
                $target = $name;
            }
            if ('' !== $area_term && 'uncategorized' !== $area_term && ('' === $target || !in_array($target, $referenced, true))) {
                foreach ($referenced as $ref_slug) {
                    if (isset($slug_area[$ref_slug]) && $slug_area[$ref_slug] === $area_term) {
                        $target = $ref_slug;
                        break;
                    }
                }
            }
            if ('' === $target) {
                return new WP_REST_Response(array('error' => sprintf('No template part matches "%s". Available: %s.', $name, implode(', ', array_keys($slug_area)))), 404);
            }
            $current = get_block_template($theme . '//' . $target, 'wp_template_part');
            return new WP_REST_Response(array(
                'node' => 'part:' . $target, 'slug' => $target,
                'area' => isset($slug_area[$target]) ? $slug_area[$target] : '',
                'rendered' => in_array($target, $referenced, true),
                'source' => $current ? (string) $current->source : 'missing', 'ours' => $is_ours($current),
                'content' => $current ? (string) $current->content : '',
            ), 200);
        }

        // Summary mode: the whole tree, no bodies.
        $templates = array();
        foreach ((array) get_block_templates(array(), 'wp_template') as $tpl) {
            $refs = array();
            if (preg_match_all('/wp:template-part\s+({[^}]*})/', (string) $tpl->content, $mm)) {
                foreach ($mm[1] as $attrs_json) {
                    $attrs = json_decode($attrs_json, true);
                    if (is_array($attrs) && !empty($attrs['slug'])) {
                        $refs[] = (string) $attrs['slug'];
                    }
                }
            }
            $templates[] = array(
                'slug' => (string) $tpl->slug, 'source' => (string) $tpl->source,
                'ours' => $is_ours($tpl), 'partRefs' => array_values(array_unique($refs)),
            );
        }
        $parts = array();
        foreach ((array) get_block_templates(array(), 'wp_template_part') as $part) {
            $parts[] = array(
                'slug' => (string) $part->slug,
                'area' => !empty($part->area) ? (string) $part->area : 'uncategorized',
                'source' => (string) $part->source, 'ours' => $is_ours($part),
                'rendered' => in_array((string) $part->slug, $referenced, true),
            );
        }
        $nav_ref = (int) get_option('bitbot_nav_post_id', 0);
        return new WP_REST_Response(array_merge(array(
            'themeType'    => 'block',
            'permalinkStructure' => (string) get_option('permalink_structure', ''),
            'theme'        => $theme,
            'templates'    => $templates,
            'parts'        => $parts,
            'nav'          => array('managedMenuId' => $nav_ref, 'live' => $nav_ref && 'publish' === get_post_status($nav_ref)),
            'identity'     => array('title' => (string) get_option('blogname', ''), 'tagline' => (string) get_option('blogdescription', '')),
            'customCssLen' => strlen(BitBot_Site_Css::read_additional_css()),
            'overlay'      => $manifest,
            'pageTitle'    => BitBot_Page_Title::fact(),
        ), $this->reading_items()), 200);
    }

    /**
     * REST handler: convert ONE batch of legacy library images to WebP.
     * Per image: create/reuse the non-destructive WebP twin, rewrite every
     * content reference (full URL + each same-name size variant, covering
     * src AND srcset) to the twin's URLs, and repoint _thumbnail_id metas so
     * featured images serve the twin. Originals are kept; every swap is
     * recorded in the bitbot_webp_migration_map option for a future revert.
     * apiKey-authed like the other server-to-server routes.
     *
     * @since 2.1.117
     * @param WP_REST_Request $request { apiKey, batchSize?, cursor? }
     * @return WP_REST_Response
     */
    public function rest_convert_images_webp($request) {
        // Authenticated by permission_api_key() before this runs.
        global $wpdb;
        $batch  = max(1, min(20, (int) ($request->get_param('batchSize') ?: 10)));
        $cursor = max(0, (int) $request->get_param('cursor'));

        // Candidates: JPEG/PNG originals past the cursor that are neither a
        // twin we created nor an original already twinned. Cursor-ordered so
        // repeated calls walk the whole library exactly once.
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
               AND p.post_mime_type IN ('image/jpeg','image/png') AND p.ID > %d
               AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key IN ('_bitbot_webp_twin','_bitbot_webp_source'))
             ORDER BY p.ID ASC LIMIT %d",
            $cursor,
            $batch
        )));

        $converted = array();
        $skipped   = array();
        $url_repl  = 0;
        $thumb_sw  = 0;
        $map       = get_option('bitbot_webp_migration_map', array());
        if (!is_array($map)) {
            $map = array();
        }

        foreach ($ids as $id) {
            $twin = (int) $this->ensure_webp_twin($id);
            if ($twin === (int) $id) {
                $skipped[] = array('id' => (int) $id, 'reason' => 'conversion unavailable (server image editor or file)');
                continue;
            }
            // Build URL pairs: full size + every same-name registered size.
            $pairs     = array();
            $orig_full = wp_get_attachment_url($id);
            $twin_full = wp_get_attachment_url($twin);
            if ($orig_full && $twin_full) {
                $pairs[$orig_full] = $twin_full;
            }
            $om = wp_get_attachment_metadata($id);
            $tm = wp_get_attachment_metadata($twin);
            if (is_array($om) && !empty($om['sizes']) && is_array($tm) && !empty($tm['sizes'])) {
                $obase = trailingslashit(dirname((string) $orig_full));
                $tbase = trailingslashit(dirname((string) $twin_full));
                foreach ($om['sizes'] as $size_name => $o_size) {
                    if (!empty($o_size['file']) && !empty($tm['sizes'][$size_name]['file'])) {
                        $pairs[$obase . $o_size['file']] = $tbase . $tm['sizes'][$size_name]['file'];
                    }
                }
            }
            $replaced = 0;
            foreach ($pairs as $from => $to) {
                if ($from === $to) {
                    continue;
                }
                $replaced += (int) $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
                    $from,
                    $to,
                    '%' . $wpdb->esc_like($from) . '%'
                ));
            }
            $url_repl += $replaced;
            // Featured images pointing at the original follow the twin.
            $swaps = (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_key = '_thumbnail_id' AND meta_value = %s",
                (string) $twin,
                (string) $id
            ));
            $thumb_sw += $swaps;
            $map[]     = array('orig' => (int) $id, 'twin' => $twin, 'pairs' => $pairs, 'thumbSwaps' => $swaps, 'at' => gmdate('c'));
            $converted[] = array('id' => (int) $id, 'twinId' => $twin, 'contentReplacements' => $replaced, 'thumbnailSwaps' => $swaps);
        }
        update_option('bitbot_webp_migration_map', $map, false);

        $last = !empty($ids) ? max($ids) : $cursor;
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(p.ID) FROM {$wpdb->posts} p
             WHERE p.post_type = 'attachment' AND p.post_mime_type IN ('image/jpeg','image/png') AND p.ID > %d
               AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key IN ('_bitbot_webp_twin','_bitbot_webp_source'))",
            $last
        ));
        return new WP_REST_Response(array(
            'converted'        => $converted,
            'skipped'          => $skipped,
            'urlReplacements'  => $url_repl,
            'thumbnailSwaps'   => $thumb_sw,
            'nextCursor'       => $remaining > 0 ? $last : null,
            'remaining'        => $remaining,
        ), 200);
    }

    /**
     * REST handler: list image attachments (id, url, alt, dims) for the agent.
     * apiKey-authed; search matches title/filename; paged 20 at a time.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function rest_list_media($request) {
        // Authenticated by permission_api_key() before this runs.
        $search = sanitize_text_field((string) $request->get_param('search'));
        $page   = max(1, (int) $request->get_param('page'));
        $query  = new WP_Query(array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => array('image/jpeg', 'image/png', 'image/webp', 'image/gif'),
            'posts_per_page' => 20,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
            's'              => $search,
        ));
        $items = array();
        foreach ($query->posts as $att) {
            $meta    = wp_get_attachment_metadata($att->ID);
            $items[] = array(
                'attachmentId' => (int) $att->ID,
                'url'          => (string) wp_get_attachment_url($att->ID),
                'filename'     => (string) basename((string) get_attached_file($att->ID)),
                'title'        => (string) $att->post_title,
                'alt'          => (string) get_post_meta($att->ID, '_wp_attachment_image_alt', true),
                'mime'         => (string) $att->post_mime_type,
                'width'        => is_array($meta) ? (int) ($meta['width'] ?? 0) : 0,
                'height'       => is_array($meta) ? (int) ($meta['height'] ?? 0) : 0,
                'uploadedAt'   => (string) $att->post_date_gmt,
            );
        }
        return new WP_REST_Response(array(
            'items' => $items,
            'total' => (int) $query->found_posts,
            'page'  => $page,
        ), 200);
    }

    /**
     * REST handler: verify the api_key, then kick the background content sync
     * (the same resumable batch sync the dashboard's "Sync Now" runs).
     *
     * @since 2.1.90
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    /**
     * Stamp _bitbot_generated on posts BitBot authored before the stamp shipped.
     * Idempotent; ignores ids that are not posts/pages.
     */
    public function rest_stamp_generated($request) {
        // Authenticated by permission_api_key() before this runs.
        $ids = $request->get_param('ids');
        if (!is_array($ids)) {
            return new WP_REST_Response(array('error' => 'ids[] required'), 400);
        }
        $stamped = array();
        foreach ($ids as $raw) {
            $id = (int) $raw;
            if ($id <= 0) {
                continue;
            }
            $post = get_post($id);
            if (!$post || !in_array($post->post_type, array('post', 'page'), true)) {
                continue;
            }
            update_post_meta($id, '_bitbot_generated', 1);
            $stamped[] = $id;
        }
        return new WP_REST_Response(array('ok' => true, 'stamped' => $stamped), 200);
    }

    public function rest_run_sync($request) {
        // Authenticated by permission_api_key() before this runs.
        $plugin = class_exists('BitBot') ? BitBot::get_instance() : null;
        if (!$plugin || !method_exists($plugin, 'start_background_sync')) {
            return new WP_REST_Response(array('error' => 'sync unavailable'), 500);
        }
        $res = $plugin->start_background_sync();
        return new WP_REST_Response(array(
            'started'      => true,
            'total'        => is_array($res) ? (int) ($res['total'] ?? 0) : 0,
            'totalBatches' => is_array($res) ? (int) ($res['totalBatches'] ?? 0) : 0,
        ), 200);
    }

    /**
     * REST handler: verify the caller holds this site's api_key, then apply any
     * pending auto proposals.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    /**
     * Synchronous apply: one edit in, the REAL outcome out. Runs the same
     * guarded apply switch the poller used (apply_proposal — fragment bounds,
     * guardrails, revert capture, status reporting all intact) but NOW, in the
     * caller's request, so the backend returns the truth to the agent in the
     * same tool call. Single-flight: WordPress's upgrader and content writes
     * aren't concurrency-safe, so parallel calls wait briefly for the lock.
     */
    public function rest_wp_apply($request) {
        $this->unwrap_opaque_payload($request);
        // Authenticated by permission_api_key() before this runs.
        $proposal = $request->get_param('proposal');
        if (!is_array($proposal) || empty($proposal['id']) || empty($proposal['kind'])) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'proposal {id, kind, …} required'), 400);
        }
        // Parallel tool batches produce concurrent applies; wait for the lock
        // instead of failing one of them.
        for ($i = 0; $i < 45 && get_transient('bitbot_auto_apply_lock'); $i++) {
            sleep(1);
        }
        if (get_transient('bitbot_auto_apply_lock')) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'another apply is still in flight — retry shortly'), 409);
        }
        set_transient('bitbot_auto_apply_lock', 1, 5 * MINUTE_IN_SECONDS);
        try {
            $r = $this->apply_proposal($proposal);
            if (is_wp_error($r)) {
                // Settle the row HERE, whatever branch produced the error. Most
                // branches already do; the ones that do not (an early return
                // before any callback) left rows `pending` with no reason on
                // record. A second 'failed' write over the first is harmless.
                $this->api_client->update_content_edit_status((string) $proposal['id'], 'failed', null, $r->get_error_message());
                return new WP_REST_Response(array('ok' => false, 'error' => $r->get_error_message()), 200);
            }
            return new WP_REST_Response(array('ok' => true, 'result' => $r), 200);
        } finally {
            delete_transient('bitbot_auto_apply_lock');
        }
    }

    /**
     * Reject a pending proposal (no WordPress write).
     */
    public function ajax_reject_content_edit() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }

        $proposal_id = isset($_POST['proposal_id']) ? sanitize_text_field(wp_unslash($_POST['proposal_id'])) : '';
        if ('' === $proposal_id) {
            wp_send_json_error(array('error' => 'proposal_id is required'));
        }

        $result = $this->api_client->update_content_edit_status($proposal_id, 'rejected');
        if ($result['success']) {
            wp_send_json_success(array('proposalId' => $proposal_id, 'status' => 'rejected'));
        } else {
            wp_send_json_error(array('error' => $result['error']));
        }
    }

    /**
     * Revert an applied proposal by restoring its snapshotted before-value.
     * We restore the value directly (not via wp_revision) because meta/tag
     * edits don't create WP revisions — the before_value snapshot is the
     * reliable source of truth for every editor-agnostic kind.
     */
    /**
     * Upload an image attached in the admin agent chat straight into the
     * Media Library. The binary never touches our backend — the chat message
     * carries only the attachment reference. Images only (SVG excluded: XSS
     * vector), 5MB cap; WP re-verifies the real filetype from content.
     */
    public function ajax_upload_chat_image() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('upload_files')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }
        if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
            wp_send_json_error(array('error' => 'No image received.'));
        }
        $file = $_FILES['image']; // phpcs:ignore WordPress.Security -- handled by wp_handle_upload below.
        $max_bytes = 5 * 1024 * 1024;
        if (!empty($file['size']) && (int) $file['size'] > $max_bytes) {
            wp_send_json_error(array('error' => 'Image is larger than 5MB. Optimize it first — oversized images also hurt your page speed.'));
        }
        $allowed = array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'webp'         => 'image/webp',
            'gif'          => 'image/gif',
        );
        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed);
        if (empty($check['type'])) {
            wp_send_json_error(array('error' => 'Only JPEG, PNG, WebP, or GIF images are supported.'));
        }
        if (!function_exists('media_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $attachment_id = media_handle_upload('image', 0, array(), array('mimes' => $allowed, 'test_form' => false));
        if (is_wp_error($attachment_id)) {
            wp_send_json_error(array('error' => $attachment_id->get_error_message()));
        }
        // WebP is a must: small files skip the composer's client-side pass
        // (it only optimizes >1.5MB), so finish the job server-side. This is
        // OUR upload flow — in-place conversion, nothing the owner had before.
        $this->optimize_attachment_image((int) $attachment_id);
        $meta = wp_get_attachment_metadata($attachment_id);
        wp_send_json_success(array(
            'attachmentId' => (int) $attachment_id,
            'url'          => (string) wp_get_attachment_url($attachment_id),
            'mime'         => (string) get_post_mime_type($attachment_id),
            'width'        => is_array($meta) ? (int) ($meta['width'] ?? 0) : 0,
            'height'       => is_array($meta) ? (int) ($meta['height'] ?? 0) : 0,
        ));
    }

    /**
     * Fetch a single proposal by id from the backend.
     *
     * @param string $proposal_id
     * @return array|WP_Error The proposal array, or WP_Error on failure.
     */
    private function fetch_content_edit_proposal($proposal_id) {
        $result = $this->api_client->get_content_edit_proposals(null, $proposal_id);
        if (empty($result['success'])) {
            return new WP_Error('bitbot_fetch_failed', $result['error'] ?? 'Could not load the proposal.');
        }
        $proposals = $result['data']['proposals'] ?? array();
        if (empty($proposals) || !is_array($proposals[0])) {
            return new WP_Error('bitbot_not_found', 'Proposal not found.');
        }
        return $proposals[0];
    }

    /**
     * Write one editor-agnostic edit value to a WordPress post. Shared by
     * apply (after-value) and revert (before-value). Returns true on success
     * or a WP_Error. Never touches the post BODY — only the safe, editor-
     * agnostic surfaces.
     *
     * @param int    $post_id
     * @param string $kind  seo_title|seo_description|focus_keyphrase|post_title|tags|excerpt|status
     * @param mixed  $value string, or array of strings for kind='tags'.
     * @return true|WP_Error
     */
    private function write_content_edit($post_id, $kind, $value) {
        // Site-scoped kinds (no post) are handled before the post lookup.
        if ('custom_css' === $kind) {
            // 2.2.7: paused. The WordPress.org review of 2.2.6 objected to the
            // plugin saving free-form CSS; the replacement (writing the theme's
            // own Additional CSS through BitBot_Site_Css::write_additional_css,
            // markup refused) is kept in code but not switched on until a
            // reviewer has accepted it. The owner's own Additional CSS is
            // untouched and the agent still reads it.
            return new WP_Error('bitbot_css_paused', 'Refused: Additional CSS changes are paused in this version of the plugin. The site owner can add CSS under Appearance > Customize > Additional CSS.');
        }
        // Administrative kinds are refused here as well as in apply_proposal,
        // so no path (apply or revert) can reach them.
        $refusal = BitBot_Agent_Policy::kind_refusal($kind);
        if (null !== $refusal) {
            return new WP_Error('bitbot_kind_refused', $refusal);
        }

        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('bitbot_no_post', 'That post no longer exists.');
        }

        switch ($kind) {
            case 'seo_title':
                return $this->write_seo_meta($post_id, 'title', is_string($value) ? $value : '');
            case 'seo_description':
                return $this->write_seo_meta($post_id, 'description', is_string($value) ? $value : '');
            case 'focus_keyphrase':
                return $this->write_seo_meta($post_id, 'focusKeyphrase', is_string($value) ? $value : '');

            case 'post_title':
                return $this->wp_update_post_field($post_id, 'post_title', sanitize_text_field((string) $value));

            case 'excerpt':
                return $this->wp_update_post_field($post_id, 'post_excerpt', wp_kses_post((string) $value));

            case 'body':
                // Server-side safety net: only Classic/Gutenberg bodies are safe
                // to write. Page-builder posts keep their content in postmeta, so
                // overwriting post_content would silently break the layout. Refuse
                // regardless of what the proposal claims.
                $editor = $this->api_client->detect_built_with($post_id, $post);
                if (!in_array($editor, array('classic', 'block'), true)) {
                    return new WP_Error('bitbot_body_unsafe', 'This post is built with ' . $editor . ', not a block or classic body; its body cannot be edited safely.');
                }
                // FRAGMENT GUARD (authoritative — measured against the LIVE
                // content, not what the proposal claims). A body edit REPLACES
                // the whole post; the model sending only the changed paragraph
                // flattened four 15-22k-char posts to ~170 chars each, applied
                // silently. A drastic shrink is a fragment until proven
                // otherwise: refuse it loudly so the agent resends the full
                // document. Intentional guts go through a fresh draft instead.
                $current_body = (string) get_post_field('post_content', $post_id);
                $current_len  = strlen($current_body);
                $new_len      = strlen((string) $value);
                if ($current_len >= 2000 && $new_len < max(600, (int) ($current_len * 0.3))) {
                    return new WP_Error(
                        'bitbot_body_fragment',
                        sprintf(
                            'Refused: the new body is %d chars but this post currently holds %d. That looks like a fragment, not the complete post. Fetch the full source, apply the change inside it, and resend the WHOLE body.',
                            $new_len,
                            $current_len
                        )
                    );
                }
                // bb-* utility classes and their block alignment are load-
                // bearing plumbing contracts (bb-flush-top = the hero joins the
                // header edge-to-edge). Full-body re-emission by the model is
                // lossy on invisible attributes — even under an explicit
                // words-only instruction a 15k rewrite dropped them twice. The
                // plumbing re-asserts them from the CURRENT content.
                $value = $this->preserve_utility_attrs(
                    $current_body,
                    (string) $value
                );
                // A class the model set in a block's JSON `className` but not
                // in its saved HTML never renders on static blocks (cover,
                // group). preserve_utility_attrs only re-asserts classes the
                // OLD body had; a fresh add slips through. Mirror every
                // JSON-declared class into the block's wrapper so it actually
                // takes effect on the page.
                $value = $this->sync_block_classnames_to_html((string) $value);
                // Do NOT wp_kses_post() here: it strips the `<!-- wp:... -->`
                // block delimiters and would corrupt Gutenberg content.
                // wp_update_post applies content KSES per the acting user's caps
                // (admins have unfiltered_html), and creates a native WP revision
                // so the change is revertable from both the snapshot and history.
                return $this->wp_update_post_field($post_id, 'post_content', (string) $value);

            case 'status':
                $status = sanitize_key((string) $value);
                if (!in_array($status, array('draft', 'publish', 'private', 'trash'), true)) {
                    return new WP_Error('bitbot_bad_status', 'Unsupported status.');
                }
                // Trash goes through wp_trash_post so WP stores the pre-trash
                // status and fires the proper hooks; reverting (before_value =
                // the old status) then untrashes first so the status write
                // lands on a live post.
                if ('trash' === $status) {
                    $trashed = wp_trash_post($post_id);
                    return $trashed ? true : new WP_Error('bitbot_trash_failed', 'Could not trash the post.');
                }
                if ('trash' === get_post_status($post_id)) {
                    wp_untrash_post($post_id);
                }
                return $this->wp_update_post_field($post_id, 'post_status', $status);

            case 'tags':
                $tags = is_array($value) ? array_map('sanitize_text_field', $value) : array();
                $set  = wp_set_post_tags($post_id, $tags, false);
                if (is_wp_error($set)) {
                    return $set;
                }
                return true;

            case 'set_featured_image':
                return $this->apply_featured_image($post_id, $value);

            case 'replace_content_image':
                return $this->replace_content_image($post_id, $value);

            case 'replace_content_text':
                return $this->replace_content_text($post_id, $post, $value);

            case 'remove_content_blocks':
                return $this->remove_content_blocks($post_id, $post, $value);

            default:
                return new WP_Error('bitbot_bad_kind', 'Unsupported edit kind: ' . $kind);
        }
    }

    /**
     * Set (apply) or restore (revert) a post's featured image.
     *  - APPLY: value = { imageUrl, alt } — sideload the resolved stock image
     *    into the Media Library and set it as the post thumbnail.
     *  - REVERT: value = { prevThumbId } — restore the prior thumbnail (or
     *    remove it if the post had none). We branch on the value shape, mirroring
     *    the create_page / nav_menu revert handlers.
     *
     * @param int   $post_id
     * @param mixed $value
     * @return true|WP_Error
     */
    private function apply_featured_image($post_id, $value) {
        if (!is_array($value)) {
            return new WP_Error('bitbot_bad_image', 'No image data.');
        }
        if (array_key_exists('prevThumbId', $value)) {
            $prev = (int) $value['prevThumbId'];
            if ($prev > 0) {
                set_post_thumbnail($post_id, $prev);
            } else {
                delete_post_thumbnail($post_id);
            }
            return true;
        }
        // Owner-supplied / library image: an existing attachment id — no
        // sideload. WebP is a must for anything we place: if the attachment
        // isn't WebP, use (or create once) its non-destructive WebP twin —
        // the owner's original stays in the library untouched.
        if (!empty($value['attachmentId'])) {
            $att = (int) $value['attachmentId'];
            if ('attachment' !== get_post_type($att) || !wp_attachment_is_image($att)) {
                return new WP_Error('bitbot_bad_image', 'attachmentId is not an image in the Media Library.');
            }
            set_post_thumbnail($post_id, $this->ensure_webp_twin($att));
            return true;
        }
        $url = isset($value['imageUrl']) ? esc_url_raw((string) $value['imageUrl']) : '';
        if ('' === $url) {
            return new WP_Error('bitbot_bad_image', 'No image URL.');
        }
        if (!function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $alt = isset($value['alt']) ? sanitize_text_field((string) $value['alt']) : '';
        // Two problems to solve:
        //  1) Many stock CDNs (StockSnap, rawpixel, …) 403 a server-side fetch
        //     carrying WordPress's default "WordPress/x.y" user-agent.
        //  2) Unsplash/CDN URLs have NO image extension in the path
        //     (…/photo-123?ixid=…), which media_sideload_image rejects as an
        //     "Invalid image URL".
        // So: download with a browser UA, then sideload the downloaded file with
        // a forced filename + extension (skipping the URL-extension check).
        $browser_ua = function ($args) {
            $args['user-agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36';
            return $args;
        };
        add_filter('http_request_args', $browser_ua, 999);
        $tmp = download_url($url);
        remove_filter('http_request_args', $browser_ua, 999);
        if (is_wp_error($tmp)) {
            return $tmp;
        }
        $name = sanitize_file_name(preg_replace('/[^a-z0-9]+/i', '-', $alt !== '' ? $alt : 'featured-image'));
        if ('' === $name) {
            $name = 'featured-image';
        }
        $file_array = array('name' => $name . '.jpg', 'tmp_name' => $tmp);
        $attach_id  = media_handle_sideload($file_array, $post_id, $alt !== '' ? $alt : null);
        if (is_wp_error($attach_id)) {
            if (file_exists($tmp)) {
                @unlink($tmp); // phpcs:ignore
            }
            return $attach_id;
        }
        if ('' !== $alt) {
            update_post_meta((int) $attach_id, '_wp_attachment_image_alt', $alt);
        }
        $this->optimize_attachment_image((int) $attach_id);
        set_post_thumbnail($post_id, (int) $attach_id);
        return true;
    }

    /**
     * Swap ONE image inside a post's body for another library attachment —
     * the whole swap owned here so the agent never hand-edits block markup.
     * The old image is named by attachment id or by its exact current URL;
     * every textual reference is repointed: full + every size URL (srcset
     * entries are size URLs, so they follow), the wp-image-<id> class that
     * drives render-time srcset, and — via parse_blocks, never regex — the
     * media ids in block JSON (image/cover `id`, media-text `mediaId`,
     * gallery `ids`). A thumbnail pointing at the old image follows too.
     * WebP rule as everywhere we place an image: the replacement is served
     * as its non-destructive WebP twin. 0 references found = loud failure,
     * never a silent no-op "applied".
     *
     * @param int   $post_id
     * @param mixed $value { toAttachmentId, fromAttachmentId?, fromUrl? }
     * @return array|WP_Error { swapped, thumbnailSwapped }
     */
    private function replace_content_image($post_id, $value) {
        if (!is_array($value) || empty($value['toAttachmentId'])) {
            return new WP_Error('bitbot_bad_swap', 'replace_content_image needs a toAttachmentId.');
        }
        $to = (int) $value['toAttachmentId'];
        if ('attachment' !== get_post_type($to) || !wp_attachment_is_image($to)) {
            return new WP_Error('bitbot_bad_swap', 'toAttachmentId is not an image in the Media Library.');
        }
        $to = (int) $this->ensure_webp_twin($to);

        $from_id  = isset($value['fromAttachmentId']) ? (int) $value['fromAttachmentId'] : 0;
        $from_url = isset($value['fromUrl']) ? trim((string) $value['fromUrl']) : '';
        // Bodies write the same site-local image as an absolute URL OR a
        // root-relative path (<img src="/placeholder.jpg"> is real, live
        // content) — and the agent passes whichever form it read. Match BOTH:
        // collect every equivalent spelling of from_url, and swap them all.
        $from_variants = array();
        if ('' !== $from_url) {
            $from_variants[] = $from_url;
            $home = untrailingslashit(home_url());
            if (0 === strpos($from_url, '/') && 0 !== strpos($from_url, '//')) {
                $from_variants[] = $home . $from_url;
            } else {
                $u_host = wp_parse_url($from_url, PHP_URL_HOST);
                $h_host = wp_parse_url($home, PHP_URL_HOST);
                $u_path = (string) wp_parse_url($from_url, PHP_URL_PATH);
                if ($u_host && $h_host && strtolower($u_host) === strtolower($h_host) && '' !== $u_path) {
                    $u_query = wp_parse_url($from_url, PHP_URL_QUERY);
                    $from_variants[] = $u_path . ($u_query ? '?' . $u_query : '');
                }
            }
        }
        if ($from_id <= 0 && '' !== $from_url) {
            // A library-owned URL upgrades to its attachment id, unlocking the
            // size-variant and block-id swaps. The lookup needs an ABSOLUTE
            // URL, and size-suffixed URLs (…-1024x768.jpg) need the suffix
            // stripped for the core lookup to hit.
            foreach ($from_variants as $candidate) {
                if (0 !== strpos($candidate, 'http')) {
                    continue;
                }
                $from_id = (int) attachment_url_to_postid($candidate);
                if ($from_id <= 0) {
                    $stripped = preg_replace('/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $candidate);
                    if ($stripped !== $candidate) {
                        $from_id = (int) attachment_url_to_postid($stripped);
                    }
                }
                if ($from_id > 0) {
                    break;
                }
            }
        }
        if ($from_id <= 0 && '' === $from_url) {
            return new WP_Error('bitbot_bad_swap', 'Identify the image to replace: fromAttachmentId or fromUrl.');
        }
        if ($from_id === $to) {
            return new WP_Error('bitbot_bad_swap', 'The old and new image are the same attachment.');
        }

        $new_full = wp_get_attachment_url($to);
        if (!$new_full) {
            return new WP_Error('bitbot_bad_swap', 'The replacement attachment has no file URL.');
        }
        // Old-URL → new-URL pairs: full size + every registered size the old
        // image has. A size with no same-name twin on the new image maps to the
        // new FULL url — a bigger file beats a dead reference.
        $pairs = array();
        if ($from_id > 0) {
            $old_full = wp_get_attachment_url($from_id);
            if ($old_full) {
                $pairs[$old_full] = $new_full;
                $om = wp_get_attachment_metadata($from_id);
                $tm = wp_get_attachment_metadata($to);
                if (is_array($om) && !empty($om['sizes'])) {
                    $obase = trailingslashit(dirname($old_full));
                    $tbase = trailingslashit(dirname($new_full));
                    foreach ($om['sizes'] as $size_name => $o_size) {
                        if (empty($o_size['file'])) {
                            continue;
                        }
                        $pairs[$obase . $o_size['file']] =
                            (is_array($tm) && !empty($tm['sizes'][$size_name]['file']))
                                ? $tbase . $tm['sizes'][$size_name]['file']
                                : $new_full;
                    }
                }
            }
        }
        foreach ($from_variants as $variant) {
            $pairs[$variant] = $new_full;
        }
        // Longest first so a size URL is never half-eaten by its own full-URL
        // prefix landing first — and so the ABSOLUTE spelling of from_url is
        // consumed before its root-relative substring can bite into it.
        uksort($pairs, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        $content = (string) get_post_field('post_content', $post_id);
        if ('' === $content) {
            return new WP_Error('bitbot_bad_swap', 'This post has no body content to edit.');
        }

        // The new image's OWN alt travels with it: stale alt text describing
        // the replaced picture is an accessibility/SEO lie. Synced onto the
        // matched blocks' JSON `alt` (covers) and every repointed img tag.
        $new_alt = trim((string) get_post_meta($to, '_wp_attachment_image_alt', true));

        $swapped = 0;
        // Block JSON ids (Gutenberg only): parse, retarget, reserialize.
        if ($from_id > 0 && has_blocks($content)) {
            $blocks = parse_blocks($content);
            if ($this->retarget_image_blocks($blocks, $from_id, $to, $swapped, $new_alt)) {
                $content = serialize_blocks($blocks);
            }
        }
        // Every textual URL reference — src, srcset entries, inline
        // background-image styles, classic HTML, block JSON "url" attrs.
        foreach ($pairs as $from => $to_url) {
            if ($from === $to_url) {
                continue;
            }
            $n = substr_count($content, $from);
            if ($n > 0) {
                $content  = str_replace($from, $to_url, $content);
                $swapped += $n;
            }
        }
        // The wp-image-<id> class WordPress keys render-time srcset off.
        if ($from_id > 0) {
            $n = substr_count($content, 'wp-image-' . $from_id);
            if ($n > 0) {
                $content  = str_replace('wp-image-' . $from_id, 'wp-image-' . $to, $content);
                $swapped += $n;
            }
        }

        // Alt follows the image: every <img> whose src is now one of the URLs
        // we just placed gets the NEW attachment's alt (only when it has one —
        // an empty library alt never blanks existing text).
        $alt_updated = 0;
        if ('' !== $new_alt) {
            $new_urls = array_values(array_unique(array_merge(array_values($pairs), array($new_full))));
            $content  = preg_replace_callback('/<img\b[^>]*>/i', function ($m) use ($new_urls, $new_alt, &$alt_updated) {
                $tag = $m[0];
                if (!preg_match('/\bsrc="([^"]+)"/i', $tag, $sm) || !in_array($sm[1], $new_urls, true)) {
                    return $tag;
                }
                $esc = esc_attr($new_alt);
                if (preg_match('/\balt="[^"]*"/i', $tag)) {
                    $updated = preg_replace('/\balt="[^"]*"/i', 'alt="' . $esc . '"', $tag, 1);
                } else {
                    $updated = preg_replace('/^<img\b/i', '<img alt="' . $esc . '"', $tag, 1);
                }
                if (is_string($updated) && $updated !== $tag) {
                    $alt_updated++;
                    return $updated;
                }
                return $tag;
            }, $content);
        }

        // "Every reference" includes the featured thumbnail when it pointed at
        // the old image.
        $thumb_swapped = false;
        if ($from_id > 0 && (int) get_post_thumbnail_id($post_id) === $from_id) {
            set_post_thumbnail($post_id, $to);
            $thumb_swapped = true;
        }

        if (0 === $swapped) {
            if ($thumb_swapped) {
                return array('swapped' => 0, 'thumbnailSwapped' => true, 'altUpdated' => 0);
            }
            return new WP_Error('bitbot_swap_not_found', sprintf(
                'The image to replace (%s) appears 0 times in post %d\'s body — nothing was changed. Read the live source (context=edit) and pass the id/url the body actually uses.',
                $from_id > 0 ? 'attachment ' . $from_id : $from_url,
                (int) $post_id
            ));
        }

        $write = $this->wp_update_post_field($post_id, 'post_content', $content);
        if (is_wp_error($write)) {
            return $write;
        }
        return array('swapped' => $swapped, 'thumbnailSwapped' => $thumb_swapped, 'altUpdated' => $alt_updated);
    }

    /**
     * Recursively retarget media blocks referencing $from_id: image/cover
     * `id`, media-text `mediaId`, gallery `ids` — and when the new image has
     * its own alt, the matched block's JSON `alt` (covers store one) follows
     * it. Only the JSON attrs — the URLs, classes and img-tag alts in saved
     * HTML are the string passes' job. Attachment ids are globally unique,
     * so an id match IS the image being replaced.
     *
     * @param array  $blocks parse_blocks() tree, mutated in place.
     * @param int    $from_id
     * @param int    $to_id
     * @param int    $swapped Running replacement counter, incremented in place.
     * @param string $new_alt The new attachment's alt ('' = leave alts alone).
     * @return bool Whether anything changed (gates the reserialize).
     */
    private function retarget_image_blocks(&$blocks, $from_id, $to_id, &$swapped, $new_alt = '') {
        $changed = false;
        foreach ($blocks as &$block) {
            if (!empty($block['attrs']) && is_array($block['attrs'])) {
                // Each id key pairs with the alt key its block type uses
                // (image/cover: alt; media-text: mediaAlt). The new image's
                // alt is SET on a match even when the key was absent — a
                // stale or missing alt describes the wrong picture.
                foreach (array('id' => 'alt', 'mediaId' => 'mediaAlt') as $attr => $alt_key) {
                    if (isset($block['attrs'][$attr]) && (int) $block['attrs'][$attr] === $from_id) {
                        $block['attrs'][$attr] = $to_id;
                        if ('' !== $new_alt) {
                            $block['attrs'][$alt_key] = $new_alt;
                        }
                        $changed = true;
                        $swapped++;
                    }
                }
                if (isset($block['attrs']['ids']) && is_array($block['attrs']['ids'])) {
                    foreach ($block['attrs']['ids'] as $k => $gid) {
                        if ((int) $gid === $from_id) {
                            $block['attrs']['ids'][$k] = $to_id;
                            $changed = true;
                            $swapped++;
                        }
                    }
                }
            }
            if (!empty($block['innerBlocks']) && $this->retarget_image_blocks($block['innerBlocks'], $from_id, $to_id, $swapped, $new_alt)) {
                $changed = true;
            }
        }
        return $changed;
    }

    /**
     * Exact find-and-replace inside ONE post's body, against the LIVE source.
     * Whole-body rewrites re-emit everything and have dropped structure
     * before; this changes only the named bytes. Contract: `find` must match
     * exactly one place (or replaceAll is passed deliberately); page-builder
     * bodies are refused (their real content lives in postmeta); and on
     * Gutenberg content the block-structure SEQUENCE must survive the
     * replacement byte-identically in shape — a find/replace that eats a
     * block delimiter is refused, never applied.
     *
     * @param int     $post_id
     * @param WP_Post $post    Already-fetched post (caller's lookup).
     * @param mixed   $value   { find, replace, replaceAll? }
     * @return array|WP_Error { replaced }
     */
    private function replace_content_text($post_id, $post, $value) {
        if (!is_array($value) || !isset($value['find']) || '' === (string) $value['find']) {
            return new WP_Error('bitbot_bad_text_edit', 'replace_content_text needs a non-empty find.');
        }
        $find    = (string) $value['find'];
        $replace = isset($value['replace']) ? (string) $value['replace'] : '';
        $all     = !empty($value['replaceAll']);

        // THE PAGE THAT WAS READ IS THE PAGE THAT GETS WRITTEN.
        //
        // Matching `find` proves the text is here; it does not prove this is
        // the document the agent meant, and it does not prove nobody has edited
        // it since. Both gaps are real: the agent picked the wrong page out of
        // two that carried the same sentence, and a human editing in another tab
        // between the read and the apply would have been silently overwritten.
        //
        // The revision read alongside the source settles both. Required rather
        // than optional: an optional proof is one a caller omits, and the whole
        // point is that the caller was the thing that got it wrong.
        $expected = isset($value['expectedRevision']) ? (string) $value['expectedRevision'] : '';
        if ('' === $expected) {
            return new WP_Error('bitbot_missing_revision', sprintf(
                'replace_content_text needs expectedRevision — the `revision` getPostSource returned for post %d. Read the post first and pass it back, so this write can prove it is changing the page you read, in the state you read it.',
                (int) $post_id
            ));
        }
        $current_revision = $this->post_revision($post_id, $post);
        if (!hash_equals($current_revision, $expected)) {
            return new WP_Error('bitbot_revision_moved', sprintf(
                'Post %d has changed since you read it (you expected %s, it is now %s) — NOTHING was changed. Someone may be editing it, or an earlier change of yours landed. Read it again and redo the edit against the current text.',
                (int) $post_id,
                substr($expected, 0, 16),
                substr($current_revision, 0, 16)
            ));
        }

        $editor = $this->api_client->detect_built_with($post_id, $post);
        // Elementor has its own route: its content is not in post_content at
        // all, and it owns the save that regenerates the CSS the front end
        // loads. Bounded to literal text in tested core widgets — anything
        // outside that comes back as a named refusal, not a silent miss.
        if ('elementor' === $editor) {
            // AS THE AGENT USER, because Elementor asks WordPress who is editing.
            //
            // The proposal apply path is authorised at its boundary by the API
            // key and never set a current user, so `current_user_can` inside
            // Elementor saw nobody and its document refused. Live on the first
            // attempt: the agent read `textEditable: true`, called this, and told
            // the owner "the Elementor page is locked against direct edits by
            // me" — inventing a page setting to explain an empty user context.
            //
            // The same scoped bitbot-agent user the general WordPress route
            // uses, restored in `finally` so the identity never leaks into
            // whatever runs after this request.
            $uid = $this->ensure_agent_user();
            if (!$uid) {
                return new WP_Error('bitbot_no_agent_user', 'Could not provision the agent user, so the Elementor edit was not attempted.');
            }
            $prev = get_current_user_id();
            wp_set_current_user($uid);
            try {
                $out = BitBot_Elementor_Adapter::replace_text(
                    $post_id,
                    $find,
                    $replace,
                    $all
                );
                if (is_wp_error($out)) {
                    return $out;
                }
                // Same identity on the way back as the Gutenberg path returns.
                // The adapter already carries its own before/after revisions;
                // these are the page's, so both routes report the same shape.
                return array_merge($out, array(
                    'wpPostId' => (int) $post_id,
                    'title'    => (string) $post->post_title,
                    'link'     => (string) get_permalink($post_id),
                ));
            } finally {
                wp_set_current_user($prev);
            }
        }
        if (!in_array($editor, array('classic', 'block'), true)) {
            return new WP_Error('bitbot_body_unsafe', 'This post is built with ' . $editor . ', not a block or classic body; its body cannot be edited safely.');
        }

        $content = (string) get_post_field('post_content', $post_id);
        if ('' === $content) {
            return new WP_Error('bitbot_bad_text_edit', 'This post has no body content to edit.');
        }
        $count = substr_count($content, $find);
        if (0 === $count) {
            return new WP_Error('bitbot_text_not_found', sprintf(
                'The find text appears 0 times in post %d\'s live body — nothing was changed. Read the live source (getPostSource / context=edit) and pass the text byte-exact as it holds it.',
                (int) $post_id
            ));
        }
        if ($count > 1 && !$all) {
            return new WP_Error('bitbot_text_ambiguous', sprintf(
                'The find text appears %d times in post %d\'s body — nothing was changed. Add surrounding context so it matches exactly one place, or pass replaceAll:true to change all %d deliberately.',
                $count,
                (int) $post_id,
                $count
            ));
        }

        $new_content = str_replace($find, $replace, $content);

        // Structural invariant (Gutenberg): the replacement may change text,
        // or REMOVE whole blocks the find contained byte-exactly — never
        // mutate or insert blocks. A find/replace that half-ate a delimiter
        // or broke a comment's JSON shows up as a renamed/inserted entry and
        // is refused instead of corrupting the post; a find that swallowed
        // complete blocks leaves the remaining sequence intact and is a
        // deliberate removal (re-emitting a 20k-char body to delete one
        // button is how posts get destroyed).
        if (has_blocks($content)) {
            $before = $this->flatten_block_names(parse_blocks($content));
            $after  = $this->flatten_block_names(parse_blocks($new_content));
            if ($before !== $after && !$this->is_block_subsequence($after, $before)) {
                return new WP_Error('bitbot_text_breaks_blocks', 'This replacement would mutate the block structure, not just text — nothing was changed. Text edits and whole-block removals (find contains the complete block markup) are allowed; anything that half-breaks or inserts a block is refused. For larger restructuring use a full body rewrite (proposePostEdit kind body).');
            }
        }

        $write = $this->wp_update_post_field($post_id, 'post_content', $new_content);
        if (is_wp_error($write)) {
            return $write;
        }
        // WHAT WAS CHANGED, not just how much. The agent reports from this, and
        // an owner can check the page and revision against what they were told.
        return array(
            'replaced'       => $count,
            'wpPostId'       => (int) $post_id,
            'title'          => (string) $post->post_title,
            'link'           => (string) get_permalink($post_id),
            'revisionBefore' => $expected,
            'revisionAfter'  => $this->post_revision($post_id),
        );
    }

    /**
     * Remove whole blocks from a Gutenberg body, addressed by NAME + a
     * contained substring — no byte-exact markup needed. proposeReplaceText's
     * byte-exact contract is right for prose but hopeless for multi-line
     * block markup travelling through an LLM (escaped quotes, entities,
     * unicode); this addresses the block instead and the server does the
     * byte surgery. Spans are located with a same-name-depth scanner on the
     * RAW source, so every byte outside the removed spans is untouched.
     *
     * @param int     $post_id
     * @param WP_Post $post
     * @param mixed   $value { blockName, containing, removeAll? }
     * @return array|WP_Error { removed }
     */
    private function remove_content_blocks($post_id, $post, $value) {
        if (!is_array($value) || empty($value['blockName'])) {
            return new WP_Error('bitbot_bad_block_edit', 'remove_content_blocks needs a blockName.');
        }
        $name = strtolower(trim((string) $value['blockName']));
        if (false === strpos($name, '/')) {
            $name = 'core/' . $name;
        }
        if (!preg_match('#^[a-z][a-z0-9-]*/[a-z][a-z0-9-]*$#', $name)) {
            return new WP_Error('bitbot_bad_block_edit', 'remove_content_blocks: blockName must look like core/buttons.');
        }
        $containing = isset($value['containing']) ? (string) $value['containing'] : '';
        $remove_all = !empty($value['removeAll']);

        // PROVENANCE: structural surgery is allowed only on what BitBot made.
        // Its own posts it may restructure freely; an owner's post it may edit
        // the WORDS of, never take blocks out of. Removing a block is not a
        // copy edit — it deletes something the owner put there on purpose.
        if (!BitBot::is_bitbot_generated($post_id)) {
            return new WP_Error(
                'bitbot_owner_content_structure',
                sprintf('Post %d was not created by Youami, so its structure is the owner\'s. Blocks cannot be removed from it. Its TEXT is still editable with proposeReplaceText, and its metadata with proposePostEdit.', (int) $post_id)
            );
        }

        $editor = $this->api_client->detect_built_with($post_id, $post);
        if ('block' !== $editor) {
            return new WP_Error('bitbot_body_unsafe', 'remove_content_blocks works on block bodies only; this post is built with ' . $editor . '; use proposeReplaceText on its HTML instead.');
        }
        $content = (string) get_post_field('post_content', $post_id);
        if ('' === $content) {
            return new WP_Error('bitbot_bad_block_edit', 'This post has no body content to edit.');
        }

        $short = preg_replace('#^core/#', '', $name);
        $spans = array();
        $offset = 0;
        // Openers: `<!-- wp:name ` / `<!-- wp:name -->` / self-closing `/-->`.
        // Core blocks serialize without the namespace; both spellings match.
        $open_re  = '#<!--\s+wp:(?:' . preg_quote($name, '#') . '|' . preg_quote($short, '#') . ')[\s/].*?-->#s';
        $close_re = '#<!--\s+/wp:(?:' . preg_quote($name, '#') . '|' . preg_quote($short, '#') . ')\s+-->#';
        while (preg_match($open_re, $content, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = (int) $m[0][1];
            $open  = $m[0][0];
            if ('/-->' === substr(str_replace(' ', '', $open), -4)) {
                $spans[] = array($start, $start + strlen($open));
                $offset  = $start + strlen($open);
                continue;
            }
            // Walk to the matching close at same same-name depth.
            $depth = 1;
            $pos   = $start + strlen($open);
            $end   = null;
            while ($depth > 0) {
                $next_open  = preg_match($open_re, $content, $mo, PREG_OFFSET_CAPTURE, $pos) ? $mo[0] : null;
                $next_close = preg_match($close_re, $content, $mc, PREG_OFFSET_CAPTURE, $pos) ? $mc[0] : null;
                if (null === $next_close) {
                    return new WP_Error('bitbot_bad_block_edit', 'remove_content_blocks: unbalanced ' . $name . ' block markup — refusing to guess.');
                }
                if (null !== $next_open && $next_open[1] < $next_close[1] && '/-->' !== substr(str_replace(' ', '', $next_open[0]), -4)) {
                    $depth++;
                    $pos = $next_open[1] + strlen($next_open[0]);
                    continue;
                }
                if (null !== $next_open && $next_open[1] < $next_close[1]) {
                    $pos = $next_open[1] + strlen($next_open[0]);
                    continue;
                }
                $depth--;
                $pos = $next_close[1] + strlen($next_close[0]);
                if (0 === $depth) {
                    $end = $pos;
                }
            }
            $spans[]  = array($start, $end);
            $offset   = $end;
        }
        if ('' !== $containing) {
            $needle = function_exists('mb_strtolower') ? mb_strtolower($containing) : strtolower($containing);
            $spans  = array_values(array_filter($spans, function ($s) use ($content, $needle) {
                $hay = substr($content, $s[0], $s[1] - $s[0]);
                $hay = function_exists('mb_strtolower') ? mb_strtolower($hay) : strtolower($hay);
                return false !== strpos($hay, $needle);
            }));
        }
        if (0 === count($spans)) {
            return new WP_Error('bitbot_block_not_found', sprintf(
                'No %s block%s found in post %d — nothing was changed.',
                $name,
                '' !== $containing ? ' containing "' . $containing . '"' : '',
                (int) $post_id
            ));
        }
        if (count($spans) > 1 && !$remove_all) {
            return new WP_Error('bitbot_block_ambiguous', sprintf(
                '%d %s blocks match in post %d — nothing was changed. Narrow `containing`, or pass removeAll:true to remove all %d deliberately.',
                count($spans),
                $name,
                (int) $post_id,
                count($spans)
            ));
        }

        $new_content = '';
        $cursor = 0;
        foreach ($spans as $s) {
            $new_content .= substr($content, $cursor, $s[0] - $cursor);
            $cursor = $s[1];
        }
        $new_content .= substr($content, $cursor);

        // SIZE FLOOR. The subsequence check below proves the edit only DELETED
        // things — and deleting the entire article passes it happily. That is
        // not theoretical: matching a class that had been merged onto the body
        // wrapper removed three whole posts in one call. A real block removal
        // (a TOC, a button, a stray embed) is a small fraction of a post; if
        // most of the body would disappear, the selector is wrong.
        $len_before = strlen($content);
        $len_after  = strlen($new_content);
        if ($len_before > 400 && $len_after < (int) ($len_before * 0.5)) {
            return new WP_Error(
                'bitbot_block_removal_too_large',
                sprintf(
                    'Refusing: removing that block would delete %d of %d bytes (%d%% of post %d) — nothing was changed. A block removal should take a small part of a post, so this selector is matching a wrapper around the content rather than the block itself. Read the real markup and target the inner block.',
                    $len_before - $len_after,
                    $len_before,
                    (int) round((($len_before - $len_after) / max(1, $len_before)) * 100),
                    (int) $post_id
                )
            );
        }

        // Same invariant replace_content_text holds: only deletions.
        $before = $this->flatten_block_names(parse_blocks($content));
        $after  = $this->flatten_block_names(parse_blocks($new_content));
        if ($before !== $after && !$this->is_block_subsequence($after, $before)) {
            return new WP_Error('bitbot_block_removal_unsafe', 'Removing those spans would mutate other blocks — nothing was changed.');
        }

        $write = $this->wp_update_post_field($post_id, 'post_content', $new_content);
        if (is_wp_error($write)) {
            return $write;
        }
        return array('removed' => count($spans));
    }

    /**
     * True when $needle is an in-order subsequence of $haystack — the block
     * sequence after a replace may only DELETE entries, never rename or add.
     *
     * @param array $needle   Block names after the replacement.
     * @param array $haystack Block names before it.
     * @return bool
     */
    private function is_block_subsequence($needle, $haystack) {
        $i = 0;
        $n = count($needle);
        foreach ($haystack as $name) {
            if ($i < $n && $needle[$i] === $name) {
                $i++;
            }
        }
        return $i === $n;
    }

    /**
     * Recursive block-name sequence of a parse_blocks() tree — the shape
     * fingerprint replace_content_text asserts is untouched by a text edit.
     *
     * Only REAL blocks count as structure. parse_blocks() surfaces loose text
     * between/after blocks as null-name freeform entries — that is text, not
     * structure, and stripping stray junk there (an orphan quote after the
     * final closing comment) must not read as a structural change. Dead
     * reusable-block stubs (wp:block with no valid ref) render nothing and
     * are likewise junk, not structure.
     *
     * @param array $blocks
     * @return array
     */
    private function flatten_block_names($blocks) {
        $names = array();
        foreach ((array) $blocks as $block) {
            $name = isset($block['blockName']) ? $block['blockName'] : null;
            if (null === $name || '' === $name) {
                continue;
            }
            if ('core/block' === $name && empty($block['attrs']['ref'])) {
                continue;
            }
            $names[] = (string) $name;
            if (!empty($block['innerBlocks'])) {
                $names = array_merge($names, $this->flatten_block_names($block['innerBlocks']));
            }
        }
        return $names;
    }

    /**
     * The generic chrome playground: replace ANY template part with agent-
     * authored block markup. The agent names an AREA ('header', 'footer', or
     * a part slug); we resolve the theme's matching part, apply the consent
     * rules (owner-customised → untouched; ours → refresh; trashed override →
     * standing veto; cleared slot → proceed), substitute {{BITBOT_NAV}} with
     * the real navigation block, re-assert the slug against cross-theme
     * leftovers, and VERIFY the theme resolves to the override — loud failure
     * otherwise. Everything recorded for revert.
     *
     * @since 2.1.122
     * @param mixed $value { area, content }
     * @return array|WP_Error { partId, before: { partId, prevContent, created } }
     */
    /**
     * Re-assert bb-* utility classes (and the alignment that travels with
     * them) that the previous content carried but a body rewrite dropped.
     * Matching is by (block name, ordinal among same-name blocks) — stable
     * under words-only edits, best-effort no-op under real restructures.
     * Patches BOTH the block-comment attrs and the first class="" in the
     * block's saved HTML (static blocks render their serialized classes).
     *
     * @since 2.1.131
     * @param string $prev Current post_content (the contract source).
     * @param string $new  Incoming post_content from the agent.
     * @return string Patched incoming content.
     */
    private function preserve_utility_attrs($prev, $new) {
        $opener_re = '/<!--\s*wp:([a-z][a-z0-9\/-]*)((?:\s+\{.*?\})?)\s*-->/s';
        $contract  = array();
        $seen      = array();
        if (preg_match_all($opener_re, (string) $prev, $pm, PREG_SET_ORDER)) {
            foreach ($pm as $m) {
                $name = $m[1];
                $seen[$name] = isset($seen[$name]) ? $seen[$name] + 1 : 0;
                $attrs = json_decode(trim($m[2]), true);
                if (!is_array($attrs) || empty($attrs['className'])) {
                    continue;
                }
                $bb = array_values(array_filter(explode(' ', (string) $attrs['className']), function ($t) {
                    return 0 === strpos($t, 'bb-');
                }));
                if (!$bb) {
                    continue;
                }
                $contract[$name . '#' . $seen[$name]] = array(
                    'bb'    => $bb,
                    'align' => isset($attrs['align']) ? (string) $attrs['align'] : null,
                );
            }
        }
        if (!$contract) {
            return $new;
        }
        if (!preg_match_all($opener_re, (string) $new, $nm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $new;
        }
        $result = '';
        $cursor = 0;
        $seen   = array();
        foreach ($nm as $m) {
            $full = $m[0][0];
            $off  = $m[0][1];
            $name = $m[1][0];
            $seen[$name] = isset($seen[$name]) ? $seen[$name] + 1 : 0;
            $key  = $name . '#' . $seen[$name];
            $result .= substr($new, $cursor, $off - $cursor);
            $cursor  = $off + strlen($full);
            if (!isset($contract[$key])) {
                $result .= $full;
                continue;
            }
            $want     = $contract[$key];
            $attr_raw = trim($m[2][0]);
            $attrs    = '' === $attr_raw ? array() : json_decode($attr_raw, true);
            if (!is_array($attrs)) {
                // Unparseable attrs (double-escaped or exotic markup):
                // rebuilding the opener from a partial parse would DESTROY
                // attributes like the cover's url. Fail safe: keep the opener
                // byte-identical; the HTML class list below still gets the
                // recovered tokens (static blocks render from the HTML).
                $result .= $full;
                if (preg_match('/class="([^"]*)"/', substr($new, $cursor, 400), $cm, PREG_OFFSET_CAPTURE)) {
                    $cls_val   = $cm[1][0];
                    $cls_start = $cursor + $cm[1][1];
                    $add       = array();
                    foreach ($want['bb'] as $t) {
                        if (false === strpos($cls_val, $t)) {
                            $add[] = $t;
                        }
                    }
                    if (null !== $want['align'] && false === strpos($cls_val, 'align' . $want['align'])) {
                        $add[] = 'align' . $want['align'];
                    }
                    if ($add) {
                        $result .= substr($new, $cursor, $cls_start - $cursor) . trim($cls_val . ' ' . implode(' ', $add));
                        $cursor  = $cls_start + strlen($cls_val);
                    }
                }
                continue;
            }
            $tokens        = isset($attrs['className']) && '' !== (string) $attrs['className'] ? explode(' ', (string) $attrs['className']) : array();
            $missing       = array_values(array_diff($want['bb'], $tokens));
            $align_missing = (null !== $want['align'] && !isset($attrs['align']));
            if (!$missing && !$align_missing) {
                $result .= $full;
                continue;
            }
            foreach ($missing as $t) {
                $tokens[] = $t;
            }
            $attrs['className'] = trim(implode(' ', array_filter($tokens)));
            if ($align_missing) {
                $attrs['align'] = $want['align'];
            }
            $result .= '<!-- wp:' . $name . ' ' . wp_json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' -->';
            // Static blocks serialize their classes into the saved HTML too —
            // inject the recovered tokens into the first class attribute that
            // follows this opener.
            if (preg_match('/class="([^"]*)"/', substr($new, $cursor, 400), $cm, PREG_OFFSET_CAPTURE)) {
                $cls_val   = $cm[1][0];
                $cls_start = $cursor + $cm[1][1];
                $add       = array();
                foreach ($missing as $t) {
                    if (false === strpos($cls_val, $t)) {
                        $add[] = $t;
                    }
                }
                if ($align_missing && false === strpos($cls_val, 'align' . $want['align'])) {
                    $add[] = 'align' . $want['align'];
                }
                if ($add) {
                    $result .= substr($new, $cursor, $cls_start - $cursor) . trim($cls_val . ' ' . implode(' ', $add));
                    $cursor  = $cls_start + strlen($cls_val);
                }
            }
        }
        $result .= substr($new, $cursor);
        return $result;
    }

    /**
     * Make every block's rendered HTML carry the classes its own JSON attrs
     * declare. Gutenberg keeps a block's `className` attribute and its saved
     * `<div class="…">` in lock-step; a hand-authored body edit can set
     * `className` in the JSON comment only, and STATIC blocks (cover, group,
     * columns — anything that renders its saved HTML) then drop that class on
     * the page entirely. That is invisible to every signal the agent has: the
     * write succeeds, the source looks right, the class is simply gone at
     * render. `preserve_utility_attrs` above only RE-ASSERTS classes the
     * PREVIOUS content already carried, so a FRESH add slips through — which is
     * exactly how a hero's `bb-flush-top` failed to flush.
     *
     * A single forward scan reads each opener ONCE — its name, its real attrs
     * (brace-matched JSON, correct for nested objects like a cover's `style`)
     * and its byte offset — then resumes AFTER that opener, so a literal
     * `<!-- wp:` sitting inside an attr string is never mistaken for a real
     * opener (the same discipline WordPress's own parser uses). Because the
     * className and the offset come from the same read, there is no second
     * enumerator to fall out of step with — the ordinal-desync class of bug
     * cannot arise. The splice is surgical: byte-identical except the missing
     * class tokens appended to the block's OWN wrapper (identified by its
     * `wp-block-<name>` class); it only ADDS, never removes or reorders, and
     * skips any block whose wrapper it cannot positively identify (dynamic
     * blocks apply className themselves and have no saved wrapper).
     *
     * @since 2.1.164
     * @param string $content Incoming post_content (block markup).
     * @return string Content with each block's JSON className mirrored in HTML.
     */
    private function sync_block_classnames_to_html($content) {
        $content = (string) $content;
        if (false === strpos($content, '<!-- wp:')) {
            return $content;
        }
        $openers = $this->scan_block_openers($content);
        if (!$openers) {
            return $content;
        }
        return $this->inject_classnames($content, $openers);
    }

    /**
     * Forward-scan the block openers in document order. For each real opener
     * returns [ name, span (offset of `<!--`), cstart (offset after `-->`),
     * cn (the JSON `className` string, '' if none) ]. Attrs JSON is matched
     * with a string-aware brace counter (nested objects, and any `}` / `-->` /
     * `<!-- wp:` living inside a string value, are handled), and the scan
     * always resumes past the opener it just consumed — so embedded delimiters
     * are skipped exactly as WordPress's tokenizer skips them. No WordPress
     * calls: unit-testable in isolation.
     *
     * @param string $content Block markup.
     * @return array<int,array{name:string,span:int,cstart:int,cn:string}>
     */
    private function scan_block_openers($content) {
        $len     = strlen($content);
        $openers = array();
        $pos     = 0;
        while (false !== ($lt = strpos($content, '<!-- wp:', $pos))) {
            $p = $lt + 8; // past "<!-- wp:"
            // Block name: optional namespace segment + name, WP's grammar.
            if (!preg_match('/^((?:[a-z][a-z0-9_-]*\/)?[a-z][a-z0-9_-]*)/', substr($content, $p, 128), $nm)) {
                $pos = $p;
                continue;
            }
            $name = $nm[1];
            $q    = $p + strlen($name);
            while ($q < $len && ctype_space($content[$q])) {
                $q++;
            }
            $cn = '';
            if ($q < $len && '{' === $content[$q]) {
                $end = $this->match_json_end($content, $q);
                if (-1 === $end) {
                    $pos = $p; // malformed attrs — not a usable opener, keep scanning.
                    continue;
                }
                $attrs = json_decode(substr($content, $q, $end - $q + 1), true);
                if (is_array($attrs) && isset($attrs['className']) && is_string($attrs['className'])) {
                    $cn = $attrs['className'];
                }
                $q = $end + 1;
                while ($q < $len && ctype_space($content[$q])) {
                    $q++;
                }
            }
            if ($q < $len && '/' === $content[$q]) {
                $q++; // void block marker
            }
            if ('-->' !== substr($content, $q, 3)) {
                $pos = $p; // not a well-formed opener — a bare "<!-- wp:" in prose.
                continue;
            }
            $cstart    = $q + 3;
            $openers[] = array('name' => $name, 'span' => $lt, 'cstart' => $cstart, 'cn' => $cn);
            $pos       = $cstart; // resume AFTER this opener; never re-read its JSON.
            // A Custom HTML block's body is verbatim author HTML that may itself
            // contain literal `<!-- wp:… -->` demo text; skip past it so those
            // are never mistaken for real openers.
            if ('html' === $name || 'core/html' === $name) {
                $close = strpos($content, '<!-- /wp:html -->', $cstart);
                if (false !== $close) {
                    $pos = $close + 17; // strlen('<!-- /wp:html -->')
                }
            }
        }
        return $openers;
    }

    /**
     * Index of the `}` that closes the object beginning at $start (which must
     * be `{`), or -1 if unbalanced. String-aware: braces, `-->` and `<!-- wp:`
     * inside a JSON string value do not affect depth.
     */
    private function match_json_end($s, $start) {
        $len    = strlen($s);
        $depth  = 0;
        $in_str = false;
        $esc    = false;
        for ($i = $start; $i < $len; $i++) {
            $ch = $s[$i];
            if ($in_str) {
                if ($esc) {
                    $esc = false;
                } elseif ('\\' === $ch) {
                    $esc = true;
                } elseif ('"' === $ch) {
                    $in_str = false;
                }
                continue;
            }
            if ('-' === $ch && '-->' === substr($s, $i, 3)) {
                return -1; // block-comment terminator before the JSON balanced — malformed.
            }
            if ('"' === $ch) {
                $in_str = true;
            } elseif ('{' === $ch) {
                $depth++;
            } elseif ('}' === $ch) {
                if (0 === --$depth) {
                    return $i;
                }
            }
        }
        return -1;
    }

    /**
     * Pure string surgery: for each opener that declares a className, find the
     * block's own wrapper tag (the first `class="…"` in the span up to the next
     * opener that carries the `wp-block-<name>` token) and append any declared
     * class it lacks. Everything else is copied byte-for-byte.
     *
     * @param string $content Block markup.
     * @param array  $openers Output of scan_block_openers (document order).
     * @return string Patched markup.
     */
    private function inject_classnames($content, $openers) {
        $len    = strlen($content);
        $count  = count($openers);
        $result = '';
        $cursor = 0;
        for ($i = 0; $i < $count; $i++) {
            $o = $openers[$i];
            if ('' === $o['cn']) {
                continue;
            }
            // Split on any whitespace; drop any token carrying a double quote or
            // a `<`. Neither can appear in a legitimate class, and both are
            // structurally dangerous once spliced into `class="…"`: a `"` breaks
            // out of the attribute (stored XSS), and a `<` can reform `<!--` —
            // which WordPress's quote-blind block tokenizer would read as a real
            // block delimiter and gut the block. `>`, `&`, `[`, `]`, `:` are inert
            // inside a double-quoted attribute, so they stay (Tailwind arbitrary
            // variants like `[&>li]` survive).
            $want = array_values(array_filter(
                preg_split('/\s+/', trim($o['cn']), -1, PREG_SPLIT_NO_EMPTY) ?: array(),
                function ($t) {
                    return false === strpos($t, '"') && false === strpos($t, '<');
                }
            ));
            if (!$want) {
                continue;
            }
            $win_start = $o['cstart'];
            $win_end   = ($i + 1 < $count) ? $openers[$i + 1]['span'] : $len;
            if ($win_end <= $win_start) {
                continue;
            }
            $window  = substr($content, $win_start, $win_end - $win_start);
            $short   = (0 === strpos($o['name'], 'core/')) ? substr($o['name'], 5) : $o['name'];
            $wrapper = 'wp-block-' . str_replace('/', '-', $short);
            // Lookbehind anchors the attribute name: a name ending in `class`
            // (data-class, ng-class, x-bind:class) must not be mistaken for the
            // block's own `class`. Zero-width, so the capture offset is unchanged.
            if (!preg_match_all('/(?<![-:\w])class="([^"]*)"/', $window, $cm, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $target = null;
            foreach ($cm[1] as $c) {
                if ($this->class_has_token($c[0], $wrapper)) {
                    $target = $c;
                    break;
                }
            }
            if (null === $target) {
                continue; // wrapper not positively identified — leave untouched.
            }
            $cls_val   = $target[0];
            $abs_start = $win_start + $target[1];
            if ($abs_start < $cursor) {
                continue; // never splice backwards (defensive; windows are ordered).
            }
            $existing = preg_split('/\s+/', trim($cls_val), -1, PREG_SPLIT_NO_EMPTY) ?: array();
            $add = array();
            foreach ($want as $t) {
                if (!in_array($t, $existing, true) && !in_array($t, $add, true)) {
                    $add[] = $t;
                }
            }
            if (!$add) {
                continue;
            }
            // Preserve the existing class value byte-for-byte (no trim — an
            // author's leading/trailing whitespace stays); append the new
            // tokens with a single separator only when one is needed.
            $sep = ('' === $cls_val || ctype_space($cls_val[strlen($cls_val) - 1])) ? '' : ' ';
            $result .= substr($content, $cursor, $abs_start - $cursor);
            $result .= $cls_val . $sep . implode(' ', $add);
            $cursor  = $abs_start + strlen($cls_val);
        }
        $result .= substr($content, $cursor);
        return $result;
    }

    /**
     * True when $token appears as a whole space-delimited class in $class_value.
     * Avoids `wp-block-button` matching inside `wp-block-buttons` or a cover's
     * `wp-block-cover__inner-container`.
     */
    private function class_has_token($class_value, $token) {
        return in_array($token, preg_split('/\s+/', trim((string) $class_value), -1, PREG_SPLIT_NO_EMPTY) ?: array(), true);
    }

    /**
     * Part slugs the active theme's TEMPLATES actually render, ordered by the
     * template that matters most (front page first). Themes ship several parts
     * per area (TT5: footer, footer-columns, footer-newsletter) but render
     * one; an override on an unreferenced sibling verifies fine and displays
     * never — that exact failure shipped on a live site. Area resolution and
     * supersede-cleanup both key off this list.
     */
    private function template_referenced_part_slugs() {
        if (!function_exists('get_block_templates')) {
            return array();
        }
        $priority = array('front-page' => 0, 'home' => 1, 'index' => 2, 'page' => 3, 'single' => 4, 'archive' => 5);
        $tpls = (array) get_block_templates(array(), 'wp_template');
        usort($tpls, function ($a, $b) use ($priority) {
            $pa = isset($priority[$a->slug]) ? $priority[$a->slug] : 9;
            $pb = isset($priority[$b->slug]) ? $priority[$b->slug] : 9;
            return $pa - $pb;
        });
        $slugs = array();
        foreach ($tpls as $tpl) {
            if (!preg_match_all('/<!--\s*wp:template-part\s+({.*?})\s*\/?-->/s', (string) $tpl->content, $m)) {
                continue;
            }
            foreach ($m[1] as $attrs_json) {
                $attrs = json_decode($attrs_json, true);
                if (is_array($attrs) && !empty($attrs['slug']) && !in_array((string) $attrs['slug'], $slugs, true)) {
                    $slugs[] = (string) $attrs['slug'];
                }
            }
        }
        return $slugs;
    }

    /**
     * The theme.json preset lists, and the CSS custom property each RENDERS as.
     *
     * Both facts are load-bearing: the path decides whether WordPress keeps the
     * preset at all (see normalize_user_presets), the variable is the only
     * honest way to ask whether it reached a visitor (see apply_global_styles).
     *
     * @return array<string, array> Each: { path: string[], var: string }
     */
    private function global_styles_preset_map() {
        return array(
            'color.palette'           => array('path' => array('color', 'palette'),           'var' => '--wp--preset--color--'),
            'color.gradients'         => array('path' => array('color', 'gradients'),         'var' => '--wp--preset--gradient--'),
            // Duotone renders as an SVG filter, not a custom property, so there
            // is no variable to look for on the page.
            'color.duotone'           => array('path' => array('color', 'duotone'),           'var' => ''),
            'typography.fontSizes'    => array('path' => array('typography', 'fontSizes'),    'var' => '--wp--preset--font-size--'),
            'typography.fontFamilies' => array('path' => array('typography', 'fontFamilies'), 'var' => '--wp--preset--font-family--'),
            'spacing.spacingSizes'    => array('path' => array('spacing', 'spacingSizes'),    'var' => '--wp--preset--spacing--'),
            'shadow.presets'          => array('path' => array('shadow', 'presets'),          'var' => '--wp--preset--shadow--'),
            'dimensions.aspectRatios' => array('path' => array('dimensions', 'aspectRatios'), 'var' => '--wp--preset--aspect-ratio--'),
            'border.radiusSizes'      => array('path' => array('border', 'radiusSizes'),      'var' => '--wp--preset--border-radius--'),
        );
    }

    private function nested_get($arr, array $path) {
        foreach ($path as $key) {
            if (!is_array($arr) || !array_key_exists($key, $arr)) {
                return null;
            }
            $arr = $arr[$key];
        }
        return $arr;
    }

    private function nested_set(array $arr, array $path, $value) {
        $ref = &$arr;
        foreach ($path as $key) {
            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                $ref[$key] = array();
            }
            $ref = &$ref[$key];
        }
        $ref = $value;
        unset($ref);
        return $arr;
    }

    private function is_indexed_list($value) {
        if (!is_array($value)) {
            return false;
        }
        if (empty($value)) {
            return true;
        }
        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * Make a theme.json `settings` fragment SURVIVE the save.
     *
     * Every wp_global_styles write is filtered through
     * wp_filter_global_styles_post() -> WP_Theme_JSON::remove_insecure_properties(),
     * which rebuilds the document from an EMPTY array and copies the settings
     * branch back only through remove_insecure_settings(). That function reads
     * presets at ORIGIN-KEYED paths only — settings.color.palette.default,
     * .theme or .custom — so a FLAT palette list (the shape a theme.json file
     * is authored in, and the shape the agent naturally sends) is invisible to
     * it and the whole settings node is dropped before it ever reaches the
     * database.
     *
     * That is the entire reason "the palette was updated" kept being reported
     * over a site where not one token changed. Nothing was refused and nothing
     * was capability-gated: by the time WordPress stored the post, it contained
     * no palette to render. Keying the list under `custom` is what the Site
     * Editor writes and what the front end reads.
     *
     * Core also drops any entry whose `name` is not a printable string, so a
     * bare {slug, color} pair — perfectly valid theme.json — vanishes silently.
     * Fill the display name from the slug, as the Site Editor does.
     *
     * @param array $settings  theme.json settings fragment as sent.
     * @param array $rejected  OUT: entries no slug could be resolved for.
     * @param array $resolved  OUT: { slug, var } per preset we are storing.
     * @return array The fragment in the shape WordPress keeps.
     */
    private function normalize_user_presets(array $settings, array &$rejected, array &$resolved) {
        foreach ($this->global_styles_preset_map() as $meta) {
            $list = $this->nested_get($settings, $meta['path']);
            if (!is_array($list)) {
                continue;
            }
            // A flat list is the shape that gets silently discarded; key it by
            // origin. An already origin-keyed one is the caller's deliberate
            // shape — leave it, but still expect it to render.
            if ($this->is_indexed_list($list)) {
                $clean = array();
                foreach ($list as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $slug = $this->resolve_preset_slug($entry);
                    if ('' === $slug) {
                        $rejected[] = isset($entry['name']) ? (string) $entry['name'] : 'an unnamed entry';
                        continue;
                    }
                    $entry['slug'] = $slug;
                    if (!isset($entry['name']) || '' === trim((string) $entry['name'])) {
                        $entry['name'] = ucwords(str_replace('-', ' ', $slug));
                    }
                    $clean[] = $entry;
                }
                $settings = $this->nested_set($settings, $meta['path'], array('custom' => $clean));
            }

            // Expectations come from what is FINALLY stored, in every origin
            // present. Collecting them only on the branch we rewrote left an
            // already-keyed palette with nothing to verify, which reads as
            // success — the exact silence this whole change exists to end.
            if ('' === $meta['var']) {
                continue;
            }
            $final = $this->nested_get($settings, $meta['path']);
            if (!is_array($final)) {
                continue;
            }
            foreach ($final as $origin_list) {
                if (!is_array($origin_list)) {
                    continue;
                }
                foreach ($origin_list as $entry) {
                    if (is_array($entry) && !empty($entry['slug'])) {
                        $resolved[] = array('slug' => (string) $entry['slug'], 'var' => $meta['var'] . (string) $entry['slug']);
                    }
                }
            }
        }
        return $settings;
    }

    /**
     * The slug a preset will be KEYED and rendered by.
     *
     * Every preset is addressed by slug — it is the token name in
     * --wp--preset--color--$slug — and core silently drops any entry whose slug
     * is not already a valid CSS class. A designer naming six colours does not
     * think in slugs, and the agent sent {name, color} pairs with none at all,
     * so requiring one perfectly would just move the silent drop upstream.
     * Derive it the way WordPress derives every other slug, and only give up
     * when there is nothing to derive from.
     */
    private function resolve_preset_slug(array $entry) {
        $slug = isset($entry['slug']) ? (string) $entry['slug'] : '';
        if ('' !== $slug && sanitize_html_class($slug) === $slug) {
            return $slug;
        }
        $source  = ('' !== $slug) ? $slug : (isset($entry['name']) ? (string) $entry['name'] : '');
        $derived = sanitize_title($source);
        return ('' !== $derived && sanitize_html_class($derived) === $derived) ? $derived : '';
    }

    /**
     * Apply GLOBAL STYLES — the theme's real colours/typography on a block
     * theme (the wp_global_styles store the Site Editor's Styles panel edits).
     * The plugin owns the mechanism: it resolves (or creates) the writable
     * wp_global_styles CPT post for the active theme, DEEP-MERGES the agent's
     * settings/styles into the current document, and updates it. The agent only
     * declares intent (the desired settings/styles) — no fragile id resolution
     * or read-after-write on the backend.
     *
     * @param mixed $value { settings?: object, styles?: object }
     * @return array|WP_Error { before, result }
     */
    private function apply_global_styles($value) {
        if (!function_exists('wp_is_block_theme') || !wp_is_block_theme()) {
            return new WP_Error('bitbot_classic_global_styles', 'Global Styles only exist on block themes; use Additional CSS instead.');
        }
        $incoming = is_array($value) ? $value : array();
        $has_settings = isset($incoming['settings']) && is_array($incoming['settings']);
        $has_styles   = isset($incoming['styles']) && is_array($incoming['styles']);
        if (!$has_settings && !$has_styles) {
            return new WP_Error('bitbot_empty_global_styles', 'Pass a settings and/or styles object.');
        }

        // Resolve the writable post; CREATE it the first time (as the Site
        // Editor does) — associated with the active theme via the wp_theme term.
        $post = $this->api_client->get_user_global_styles_post();
        $stylesheet = get_stylesheet();
        if (!$post || empty($post->ID)) {
            $new_id = wp_insert_post(array(
                'post_type'    => 'wp_global_styles',
                'post_status'  => 'publish',
                'post_title'   => 'Custom Styles',
                'post_name'    => 'wp-global-styles-' . urlencode($stylesheet),
                'post_content' => wp_slash(wp_json_encode(array('version' => 2, 'isGlobalStylesUserThemeJSON' => true))),
            ), true);
            if (is_wp_error($new_id)) {
                return $new_id;
            }
            wp_set_object_terms((int) $new_id, $stylesheet, 'wp_theme');
            $post = get_post($new_id);
        }
        if (!$post || empty($post->ID)) {
            return new WP_Error('bitbot_no_global_styles', 'Could not resolve or create the theme Global Styles post.');
        }

        $current = json_decode((string) $post->post_content, true);
        if (!is_array($current)) {
            $current = array('version' => 2);
        }
        $before = $post->post_content;

        // DEEP-MERGE so a partial send never clobbers unrelated tokens; the
        // agent's values win where they overlap.
        $rejected_slugs    = array();
        $resolved_presets  = array();
        $incoming_settings = array();
        if ($has_settings) {
            $incoming_settings   = $this->normalize_user_presets($incoming['settings'], $rejected_slugs, $resolved_presets);
            $current['settings'] = array_replace_recursive(
                isset($current['settings']) && is_array($current['settings']) ? $current['settings'] : array(),
                $incoming_settings
            );
            // A preset LIST replaces; it does not merge. array_replace_recursive
            // pairs list entries by INDEX, so a new three-colour palette laid
            // over a stored ten-colour one would keep seven colours nobody
            // asked for and the owner would see a mongrel of both.
            foreach ($this->global_styles_preset_map() as $meta) {
                $sent = $this->nested_get($incoming_settings, $meta['path']);
                if (is_array($sent)) {
                    $current['settings'] = $this->nested_set($current['settings'], $meta['path'], $sent);
                }
            }
        }
        if ($has_styles) {
            $current['styles'] = array_replace_recursive(
                isset($current['styles']) && is_array($current['styles']) ? $current['styles'] : array(),
                $incoming['styles']
            );
        }
        if (empty($current['version'])) {
            $current['version'] = 2;
        }
        $current['isGlobalStylesUserThemeJSON'] = true;

        $updated = wp_update_post(array(
            'ID'           => (int) $post->ID,
            'post_content' => wp_slash(wp_json_encode($current)),
        ), true);
        if (is_wp_error($updated)) {
            return $updated;
        }

        // VERIFY IN A SEPARATE REQUEST, AGAINST THE PAGE THE PUBLIC GETS.
        //
        // Two earlier attempts checked this inside the applying request and
        // both reported success over a palette that never rendered. Re-reading
        // the stored post only proves what we wrote. Building the stylesheet
        // here builds it as the agent user, from this process's theme.json
        // cache — neither is a visitor. The only thing that settles it is an
        // anonymous fetch of the live page, which is the check the SEO apply
        // has done for months.
        clean_post_cache((int) $post->ID);
        if (function_exists('wp_clean_theme_json_cache')) {
            wp_clean_theme_json_cache();
        }
        $this->purge_page_caches();

        // Check what THIS call sent, not everything the document holds, so an
        // older failure is never re-reported against this change. Expectations
        // come from normalize_user_presets, which is also the only thing that
        // knows an entry was dropped — building them by re-reading the cleaned
        // list instead would make a list emptied by normalisation look like a
        // call with nothing to verify, and report silent success over it.
        $expected = $resolved_presets;

        $missing    = array();
        $unverified = '';
        if (!empty($expected)) {
            $check_url = add_query_arg('bbstyle', (string) time(), home_url('/'));
            $res       = wp_remote_get($check_url, array('timeout' => 12, 'redirection' => 3));
            $code      = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
            if (200 !== $code) {
                $unverified = is_wp_error($res) ? $res->get_error_message() : ('the site returned HTTP ' . $code);
            } else {
                $html = (string) wp_remote_retrieve_body($res);
                foreach ($expected as $token) {
                    if (false === strpos($html, $token['var'])) {
                        $missing[] = $token['slug'];
                    }
                }
            }
        }

        $result = array(
            'id'       => (int) $post->ID,
            'settings' => isset($current['settings']) ? $current['settings'] : null,
            'styles'   => isset($current['styles']) ? $current['styles'] : null,
        );
        if (!empty($expected)) {
            // The token names the site now really has, so the agent styles
            // against what exists rather than what it thinks it named.
            $result['tokens'] = wp_list_pluck($expected, 'slug');
            if (empty($missing) && '' === $unverified) {
                $result['renderedOnPublicPage'] = count($expected);
            }
        }

        $problems = array();
        if (!empty($rejected_slugs)) {
            $problems[] = 'These presets were dropped because no valid slug could be derived for them: '
                . implode(', ', array_slice(array_unique($rejected_slugs), 0, 8))
                . ' — give each one a `slug` (lowercase, hyphenated).';
        }
        if (!empty($missing)) {
            $problems[] = 'Saved, but the PUBLIC page does not render these tokens: '
                . implode(', ', array_slice($missing, 0, 8))
                . '. They are not live and nothing on the site uses them. Do not report them as changed;'
                . ' set what you need through the CSS lever instead.';
        }
        if (!empty($problems)) {
            $result['incomplete'] = implode(' ', $problems);
        } elseif ('' !== $unverified) {
            $result['unverified'] = 'Saved, but I could not fetch the public page to confirm it renders ('
                . $unverified . '). Do NOT tell the owner it is verified — LOOK at the site first.';
        }

        return array('before' => $before, 'result' => $result);
    }

    /**
     * After a look-and-feel apply lands: purge caches (page + object,
     * best-effort per host) so visitors get the change, and push a fresh
     * style snapshot so the agent's styling memory reflects reality —
     * getSiteStyle reads the backend snapshot, which previously refreshed
     * only on theme switch or full sync, so right after its own applies the
     * agent reasoned from stale state (and reached for CSS workarounds).
     */
    /**
     * Drop every page/object cache we can reach, best-effort per host. Split
     * out of after_style_apply because a verification fetch that reads a cached
     * page proves nothing — the purge has to happen BEFORE we look.
     */
    private function purge_page_caches() {
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        do_action('litespeed_purge_all');
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }
    }

    private function after_style_apply() {
        $this->purge_page_caches();
        if ($this->api_client && method_exists($this->api_client, 'push_style_snapshot')) {
            $this->api_client->push_style_snapshot();
        }
    }

    private function apply_template_part_override($value) {
        if (!is_array($value) || empty($value['area']) || empty($value['content'])) {
            return new WP_Error('bitbot_bad_part', 'A template-part override needs an area and content.');
        }
        if (!function_exists('wp_is_block_theme') || !wp_is_block_theme()) {
            return new WP_Error('bitbot_classic_part', 'This theme is classic — template-part overrides only work on block themes.');
        }
        $area  = sanitize_key((string) $value['area']);
        $theme = get_stylesheet();

        // Resolve the requested area/slug against the part the theme actually
        // RENDERS. Themes ship several parts per area (TT5: footer,
        // footer-columns, footer-newsletter — only `footer` is referenced by
        // its templates); overriding an unreferenced variant verifies green
        // but never displays. So: an exact slug request wins (the agent named
        // a specific variant); an AREA request targets, in order, a part a
        // template actually references (front-page first), then the part
        // whose slug equals the area name, then the first area match.
        $target_slug = '';
        $area_term   = 'uncategorized';
        $available   = array();
        $referenced  = $this->template_referenced_part_slugs();
        if (function_exists('get_block_templates')) {
            $parts = (array) get_block_templates(array(), 'wp_template_part');
            // slug → area for every part, so BOTH request shapes (an area
            // name like "footer", or an exact slug like "footer-columns")
            // resolve to the same thing: the true area term.
            $slug_area = array();
            foreach ($parts as $part) {
                $available[] = $part->slug . (!empty($part->area) ? ' (' . $part->area . ')' : '');
                $slug_area[(string) $part->slug] = !empty($part->area) ? (string) $part->area : 'uncategorized';
            }
            if (isset($slug_area[$area])) {
                // Exact part slug named.
                $target_slug = $area;
                $area_term   = $slug_area[$area];
            } elseif (in_array($area, $slug_area, true)) {
                // Area name named.
                $area_term = $area;
            }
            // Candidates = every part in the RESOLVED area. Built from
            // $area_term, never from the raw request: an exact-slug request
            // ("footer-columns") must still compete with its area siblings
            // for the rendered slot — keying candidates off the request
            // string left this empty for slug requests, which disabled the
            // rendered-slot retarget and re-shipped the invisible-part bug.
            $area_matches = array();
            foreach ($slug_area as $slug => $slug_area_term) {
                if ('uncategorized' !== $area_term && $slug_area_term === $area_term) {
                    $area_matches[$slug] = $slug_area_term;
                }
            }
            if ('' === $target_slug && $area_matches) {
                foreach ($referenced as $ref_slug) {
                    if (isset($area_matches[$ref_slug])) {
                        $target_slug = $ref_slug;
                        break;
                    }
                }
                if ('' === $target_slug) {
                    $target_slug = (string) array_key_first($area_matches);
                }
            }
            // The rendered slot always wins: any pick no template references
            // is invisible by construction — retarget to the rendered
            // sibling of the same area when one exists.
            if ('' !== $target_slug && $referenced && !in_array($target_slug, $referenced, true)) {
                foreach ($referenced as $ref_slug) {
                    if (isset($area_matches[$ref_slug])) {
                        $target_slug = $ref_slug;
                        break;
                    }
                }
            }
        }
        if ('' === $target_slug) {
            return new WP_Error('bitbot_no_such_part', sprintf('The theme has no template part matching "%s". Available: %s.', $area, $available ? implode(', ', $available) : '(none)'));
        }

        $plugin   = class_exists('BitBot') ? BitBot::get_instance() : null;
        $manifest = ($plugin && method_exists($plugin, 'get_overlay_manifest')) ? $plugin->get_overlay_manifest() : array('history' => array());
        if (!isset($manifest['partOverrides']) || !is_array($manifest['partOverrides'])) {
            $manifest['partOverrides'] = array();
        }
        $key      = $theme . '::' . $target_slug;
        $recorded = isset($manifest['partOverrides'][$key]) ? (int) $manifest['partOverrides'][$key] : 0;
        // Any override WE previously wrote for this part — via the nav swap or
        // the retired fixed header/footer kinds — is ours to replace.
        $ours = array_filter(array(
            $recorded,
            isset($manifest['navOverrides'][$key]) ? (int) $manifest['navOverrides'][$key] : 0,
            ('header' === $area_term && isset($manifest['navOverrides'][$theme])) ? (int) $manifest['navOverrides'][$theme] : 0,
            isset($manifest['footerOverrides'][$key]) ? (int) $manifest['footerOverrides'][$key] : 0,
            isset($manifest['headerOverrides'][$key]) ? (int) $manifest['headerOverrides'][$key] : 0,
        ));

        $current = function_exists('get_block_template') ? get_block_template($theme . '//' . $target_slug, 'wp_template_part') : null;
        if (!$current) {
            return new WP_Error('bitbot_no_part', 'The theme part vanished mid-flight — try again.');
        }
        $update_id    = 0;
        $prev_content = null;
        if ('custom' === $current->source && !empty($current->wp_id)) {
            if (in_array((int) $current->wp_id, array_map('intval', $ours), true)) {
                $update_id    = (int) $current->wp_id;
                $prev_content = (string) $current->content;
            } else {
                return new WP_Error('bitbot_part_owned', sprintf('The owner customised the %s themselves — leaving it untouched.', $target_slug));
            }
        } elseif ($recorded && 'trash' === get_post_status($recorded)) {
            return new WP_Error('bitbot_part_deleted', sprintf('The owner trashed our %s override — respecting that choice. Restore or permanently delete it in Appearance → Editor, then ask again.', $target_slug));
        }

        // {{BITBOT_NAV}} → the real site menu block (managed menu when we have
        // one, the theme's default navigation otherwise).
        $nav_ref   = (int) get_option('bitbot_nav_post_id', 0);
        $nav_block = ($nav_ref && 'publish' === get_post_status($nav_ref))
            ? '<!-- wp:navigation {"ref":' . $nav_ref . '} /-->'
            : '<!-- wp:navigation /-->';
        $markup = str_replace('{{BITBOT_NAV}}', $nav_block, (string) $value['content']);

        $postarr = array(
            'post_type'    => 'wp_template_part',
            'post_status'  => 'publish',
            'post_name'    => $target_slug,
            'post_title'   => ucwords(str_replace('-', ' ', $target_slug)),
            'post_content' => wp_slash($markup),
            'post_excerpt' => 'Youami: agent-designed ' . $area_term . '.',
        );
        if ($update_id) {
            $postarr['ID'] = $update_id;
        }
        $post_id = wp_insert_post($postarr, true);
        if (is_wp_error($post_id) || !$post_id) {
            return is_wp_error($post_id) ? $post_id : new WP_Error('bitbot_part_failed', 'Could not write the template-part override.');
        }
        wp_set_object_terms((int) $post_id, $theme, 'wp_theme');
        wp_set_object_terms((int) $post_id, $area_term, 'wp_template_part_area');
        if ($target_slug !== (string) get_post_field('post_name', (int) $post_id)) {
            wp_update_post(array('ID' => (int) $post_id, 'post_name' => $target_slug));
        }
        $resolved = get_block_template($theme . '//' . $target_slug, 'wp_template_part');
        if (!$resolved || 'custom' !== $resolved->source || (int) $resolved->wp_id !== (int) $post_id) {
            $slug_now = (string) get_post_field('post_name', (int) $post_id);
            if (!$update_id) {
                wp_delete_post((int) $post_id, true);
            }
            $who = $resolved ? $resolved->source . ' (post ' . (int) $resolved->wp_id . ')' : 'nothing';
            return new WP_Error(
                'bitbot_part_not_resolving',
                sprintf('The %s override was saved (as "%s") but the theme still resolves to %s, so it would not render. A Site-Editor customisation may be holding the slot — Appearance → Editor → Clear customizations, then retry.', $target_slug, $slug_now, $who)
            );
        }

        if ($plugin && method_exists($plugin, 'save_overlay_manifest')) {
            // Supersede-cleanup: earlier applies may have landed this AREA on
            // an unreferenced sibling slug (TT5's footer-columns) — a part no
            // template renders, so it verified green and displayed never.
            // Those orphans are OURS: delete them and drop their manifest keys
            // so this apply becomes the one truth for the area.
            // $superseded survives across buckets: the same post id can be
            // recorded in several buckets (partOverrides + legacy footer/
            // headerOverrides), and force-deleting it in the first pass
            // destroys the term data the later passes' area guard reads —
            // which left dangling keys, host-cache-dependently.
            $superseded = array();
            foreach (array('partOverrides', 'footerOverrides', 'headerOverrides') as $bucket) {
                if (empty($manifest[$bucket]) || !is_array($manifest[$bucket])) {
                    continue;
                }
                foreach ($manifest[$bucket] as $mkey => $mid) {
                    $mid = (int) $mid;
                    if ($mid === (int) $post_id || 0 !== strpos((string) $mkey, $theme . '::')) {
                        continue;
                    }
                    $mslug = substr((string) $mkey, strlen($theme . '::'));
                    if ($mslug === $target_slug || in_array($mslug, $referenced, true)) {
                        continue;
                    }
                    $terms = get_the_terms($mid, 'wp_template_part_area');
                    $marea = (!is_wp_error($terms) && !empty($terms)) ? (string) $terms[0]->slug : '';
                    if ($marea !== $area_term && empty($superseded[$mid])) {
                        continue;
                    }
                    if ('wp_template_part' === get_post_type($mid)) {
                        wp_delete_post($mid, true);
                    }
                    $superseded[$mid] = true;
                    unset($manifest[$bucket][$mkey]);
                    $manifest['history'][] = array('event' => 'part_override_superseded', 'theme' => $theme, 'part' => $mslug, 'partId' => $mid, 'replacedBy' => $target_slug, 'at' => gmdate('c'));
                }
            }
            $manifest['partOverrides'][$key] = (int) $post_id;
            $manifest['history'][] = array('event' => $update_id ? 'part_redesign_refreshed' : 'part_redesign_created', 'theme' => $theme, 'part' => $target_slug, 'partId' => (int) $post_id, 'at' => gmdate('c'));
            $plugin->save_overlay_manifest($manifest);
        }
        return array(
            'partId' => (int) $post_id,
            'before' => array(
                'partId'      => (int) $post_id,
                'prevContent' => $prev_content,
                'created'     => $update_id ? false : true,
            ),
        );
    }

    /**
     * Replace every core/pattern block with the registered pattern's own
     * blocks, recursively, so a template that delegates its body to a pattern
     * can be edited as the blocks it renders. An unregistered slug is left as
     * it is; pattern nesting is capped so a pattern that includes itself
     * cannot loop.
     */
    private function expand_pattern_blocks(array $blocks, $depth = 0) {
        if ($depth > 5 || !class_exists('WP_Block_Patterns_Registry')) {
            return $blocks;
        }
        $registry = WP_Block_Patterns_Registry::get_instance();
        $out = array();
        foreach ($blocks as $b) {
            $slug = ('core/pattern' === ($b['blockName'] ?? '') && isset($b['attrs']['slug'])) ? (string) $b['attrs']['slug'] : '';
            if ('' !== $slug && $registry->is_registered($slug)) {
                $pattern = $registry->get_registered($slug);
                $inner   = is_array($pattern) && isset($pattern['content']) ? parse_blocks((string) $pattern['content']) : array();
                foreach ($this->expand_pattern_blocks($inner, $depth + 1) as $ib) {
                    $out[] = $ib;
                }
                continue;
            }
            if (!empty($b['innerBlocks'])) {
                // serialize_block() emits one inner block per null in
                // innerContent. A pattern that expands to N blocks inside a
                // parent must leave N nulls there, or only the first survives
                // the write: live 2026-09-29 the landing fill kept the hero
                // group and dropped all five queries, and the content guard
                // then refused the result as rendering no post list.
                $inner_blocks  = array();
                $inner_content = array();
                $i = 0;
                foreach ((array) $b['innerContent'] as $chunk) {
                    if (null !== $chunk) {
                        $inner_content[] = $chunk;
                        continue;
                    }
                    foreach ($this->expand_pattern_blocks(array($b['innerBlocks'][$i++]), $depth) as $ib) {
                        $inner_blocks[]  = $ib;
                        $inner_content[] = null;
                    }
                }
                $b['innerBlocks']  = $inner_blocks;
                $b['innerContent'] = $inner_content;
            }
            $out[] = $b;
        }
        return $out;
    }

    /**
     * apply_template_override — the TEMPLATE playground. The agent names a
     * template the theme registers (front-page, home, single, archive, page,
     * …) and authors its full block markup; we own delivery: the slug is
     * validated against the theme's real templates (errors list what
     * exists), a template the owner customised themselves is never touched,
     * {{BITBOT_NAV}} substitutes the real menu, content templates must keep
     * a rendering slot (wp:post-content or wp:query) so the page/post body
     * never vanishes, resolution is VERIFIED or the apply fails loudly, and
     * everything reverts (manifest bucket templateOverrides).
     */
    private function apply_template_override($value) {
        if (!is_array($value) || empty($value['template'])) {
            return new WP_Error('bitbot_bad_template', 'A template override needs a template.');
        }
        if (empty($value['content'])) {
            return new WP_Error('bitbot_bad_template', 'A template override needs content.');
        }
        if (!function_exists('wp_is_block_theme') || !wp_is_block_theme()) {
            return new WP_Error('bitbot_classic_template', 'This theme is classic — template overrides only work on block themes.');
        }
        $slug  = sanitize_key((string) $value['template']);
        $theme = get_stylesheet();

        // "By default we use the template id against the post" — record which
        // slug newly created items of a kind are born on. Stored as an option
        // so the CREATION hook can apply it without the backend having to reach
        // into every creation path (and without the next creation path someone
        // adds forgetting to).
        if (!empty($value['useForNewItems']) && !empty($value['forKind'])) {
            $kind = sanitize_key((string) $value['forKind']);
            if (in_array($kind, array('post', 'page'), true)) {
                $map        = get_option('bitbot_default_templates', array());
                $map        = is_array($map) ? $map : array();
                $map[$kind] = $slug;
                update_option('bitbot_default_templates', $map, false);
            }
        }

        $available = array();
        $known     = false;
        if (function_exists('get_block_templates')) {
            foreach ((array) get_block_templates(array(), 'wp_template') as $tpl) {
                $available[] = (string) $tpl->slug;
                if ((string) $tpl->slug === $slug) {
                    $known = true;
                }
            }
        }
        // A slug the theme doesn't ship is a CUSTOM template — the mechanism
        // behind "different layout for this particular post". Creating one is
        // allowed only when the caller says so, so a typo'd slug still fails
        // loudly instead of silently minting a stray template.
        $create_new = !empty($value['createIfMissing']);
        if (!$known && !$create_new) {
            return new WP_Error('bitbot_no_such_template', sprintf('The theme has no template "%s". Available: %s. Pass createIfMissing to add it as a custom template.', $slug, $available ? implode(', ', $available) : '(none)'));
        }
        if (!$known && $create_new) {
            $title = isset($value['title']) ? sanitize_text_field((string) $value['title']) : ucwords(str_replace('-', ' ', $slug));
            $new_id = wp_insert_post(array(
                'post_type'    => 'wp_template',
                'post_status'  => 'publish',
                'post_name'    => $slug,
                'post_title'   => $title,
                'post_content' => wp_slash((string) $value['content']),
                'post_excerpt' => isset($value['description']) ? sanitize_text_field((string) $value['description']) : '',
            ), true);
            if (is_wp_error($new_id)) {
                return $new_id;
            }
            // The theme taxonomy term is what makes WordPress resolve this as
            // one of the ACTIVE theme's templates; without it the template is
            // invisible to both the site editor and the post template picker.
            wp_set_object_terms((int) $new_id, $theme, 'wp_theme');
            return array(
                'template'   => $slug,
                'templateId' => (int) $new_id,
                'created'    => true,
                'selectable' => true,
            );
        }

        // Templates are chrome + SLOTS: the page/post body renders through
        // wp:post-content (single pages/posts) or a wp:query loop (lists).
        // Markup with neither would blank the content — reject loudly.
        // Judge what the template RENDERS: a layout that delegates its body
        // to a pattern (`<!-- wp:pattern {"slug":"agentic/magazine"} /-->`)
        // carries its queries inside that pattern, and the raw string never
        // shows them. Live 2026-09-29 two correct magazine layouts were
        // refused on the raw string. The markup is still stored as sent.
        $rendered_blocks = $this->expand_pattern_blocks(parse_blocks((string) $value['content']));
        $rendered        = serialize_blocks($rendered_blocks);
        if (false === strpos($rendered, 'wp:post-content') && false === strpos($rendered, 'wp:query')) {
            $found   = array();
            $collect = function (array $list) use (&$collect, &$found) {
                foreach ($list as $b) {
                    if (!empty($b['blockName'])) { $found[(string) $b['blockName']] = true; }
                    if (!empty($b['innerBlocks'])) { $collect($b['innerBlocks']); }
                }
            };
            $collect($rendered_blocks);
            $found_list = $found ? implode(', ', array_keys($found)) : '(none)';
            return new WP_Error('bitbot_template_no_slot', sprintf('A "%s" template must keep rendering the content: include a wp:post-content block (page/post body) or a wp:query loop (list templates). Without one the visitor sees an empty page. Blocks found, patterns expanded: %s.', $slug, $found_list));
        }

        $plugin   = class_exists('BitBot') ? BitBot::get_instance() : null;
        $manifest = ($plugin && method_exists($plugin, 'get_overlay_manifest')) ? $plugin->get_overlay_manifest() : array('history' => array());
        if (!isset($manifest['templateOverrides']) || !is_array($manifest['templateOverrides'])) {
            $manifest['templateOverrides'] = array();
        }
        $key      = $theme . '::' . $slug;
        $recorded = isset($manifest['templateOverrides'][$key]) ? (int) $manifest['templateOverrides'][$key] : 0;
        // Ours to replace: this bucket, plus the legacy theme-layer front-page
        // override bucket (manifest['overrides'][theme]).
        $ours = array_filter(array(
            $recorded,
            ('front-page' === $slug && isset($manifest['overrides'][$theme])) ? (int) $manifest['overrides'][$theme] : 0,
        ));

        $current = function_exists('get_block_template') ? get_block_template($theme . '//' . $slug, 'wp_template') : null;
        $update_id    = 0;
        $prev_content = null;
        if ($current && 'custom' === $current->source && !empty($current->wp_id)) {
            if (in_array((int) $current->wp_id, array_map('intval', $ours), true)) {
                $update_id    = (int) $current->wp_id;
                $prev_content = (string) $current->content;
            } else {
                return new WP_Error('bitbot_template_owned', sprintf('The owner customised the %s template themselves — leaving it untouched.', $slug));
            }
        } elseif ($recorded && 'trash' === get_post_status($recorded)) {
            return new WP_Error('bitbot_template_deleted', sprintf('The owner trashed our %s template override — respecting that choice. Restore or permanently delete it in Appearance → Editor, then ask again.', $slug));
        }

        $nav_ref   = (int) get_option('bitbot_nav_post_id', 0);
        $nav_block = ($nav_ref && 'publish' === get_post_status($nav_ref))
            ? '<!-- wp:navigation {"ref":' . $nav_ref . '} /-->'
            : '<!-- wp:navigation /-->';
        $markup = str_replace('{{BITBOT_NAV}}', $nav_block, (string) $value['content']);
        // Normalize part refs to the CURRENT theme: a stray/foreign "theme"
        // attr on wp:template-part resolves elsewhere (or nowhere) and ships
        // an invisible part with a green template verify — the verify only
        // proves the TEMPLATE resolves, never its referenced parts. Strip the
        // attr; core then resolves against the active theme, including our
        // own part overrides.
        $markup = preg_replace_callback(
            '/(<!--\s*wp:template-part\s+)({[^}]*})(\s*\/?-->)/',
            function ($m) {
                $attrs = json_decode($m[2], true);
                if (!is_array($attrs)) {
                    return $m[0];
                }
                unset($attrs['theme']);
                return $m[1] . wp_json_encode($attrs) . $m[3];
            },
            $markup
        );

        $postarr = array(
            'post_type'    => 'wp_template',
            'post_status'  => 'publish',
            'post_name'    => $slug,
            'post_title'   => ucwords(str_replace('-', ' ', $slug)),
            'post_content' => wp_slash($markup),
            'post_excerpt' => 'Youami: agent-designed ' . $slug . ' template.',
        );
        if ($update_id) {
            $postarr['ID'] = $update_id;
        }
        $post_id = wp_insert_post($postarr, true);
        if (is_wp_error($post_id) || !$post_id) {
            return is_wp_error($post_id) ? $post_id : new WP_Error('bitbot_template_failed', 'Could not write the template override.');
        }
        wp_set_object_terms((int) $post_id, $theme, 'wp_theme');
        if ($slug !== (string) get_post_field('post_name', (int) $post_id)) {
            wp_update_post(array('ID' => (int) $post_id, 'post_name' => $slug));
        }
        $resolved = get_block_template($theme . '//' . $slug, 'wp_template');
        if (!$resolved || 'custom' !== $resolved->source || (int) $resolved->wp_id !== (int) $post_id) {
            if (!$update_id) {
                wp_delete_post((int) $post_id, true);
            }
            $who = $resolved ? $resolved->source . ' (post ' . (int) $resolved->wp_id . ')' : 'nothing';
            return new WP_Error('bitbot_template_not_resolving', sprintf('The %s template override was saved but the theme still resolves to %s — it would not render. A Site-Editor customisation may be holding the slot; clear it in Appearance → Editor, then retry.', $slug, $who));
        }

        if ($plugin && method_exists($plugin, 'save_overlay_manifest')) {
            $manifest['templateOverrides'][$key] = (int) $post_id;
            // One post, one bucket: the legacy theme-layer front-page key is
            // superseded by templateOverrides once we've updated through it.
            if ('front-page' === $slug && isset($manifest['overrides'][$theme]) && (int) $manifest['overrides'][$theme] === (int) $post_id) {
                unset($manifest['overrides'][$theme]);
            }
            $manifest['history'][] = array('event' => $update_id ? 'template_redesign_refreshed' : 'template_redesign_created', 'theme' => $theme, 'template' => $slug, 'templateId' => (int) $post_id, 'at' => gmdate('c'));
            $plugin->save_overlay_manifest($manifest);
        }
        return array(
            'templateId' => (int) $post_id,
            'before' => array(
                'templateId'  => (int) $post_id,
                'prevContent' => $prev_content,
                'created'     => $update_id ? false : true,
            ),
        );
    }


    /**
     * Non-destructive WebP for an EXISTING library image: returns the id of a
     * WebP attachment to use in the original's place. WebP/GIF originals (and
     * any failure) return the original id. The twin is created ONCE — capped
     * at 1920px, alt/title copied, linked both ways via meta — and reused on
     * every later placement. The owner's original file is never modified.
     *
     * @since 2.1.116
     * @param int $attach_id Attachment id.
     * @return int Attachment id to place (twin or original).
     */
    private function ensure_webp_twin($attach_id) {
        $attach_id = (int) $attach_id;
        if (!wp_attachment_is_image($attach_id)) {
            return $attach_id;
        }
        $mime = get_post_mime_type($attach_id);
        if ('image/webp' === $mime || 'image/gif' === $mime) {
            return $attach_id; // already webp / animation would be flattened
        }
        $twin = (int) get_post_meta($attach_id, '_bitbot_webp_twin', true);
        if ($twin && 'attachment' === get_post_type($twin)) {
            $twin_file = get_attached_file($twin);
            if ($twin_file && file_exists($twin_file)) {
                return $twin;
            }
        }
        $path = get_attached_file($attach_id);
        if (!$path || !file_exists($path)) {
            return $attach_id;
        }
        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            return $attach_id;
        }
        $editor_class = get_class($editor);
        if (!is_callable(array($editor_class, 'supports_mime_type')) || !call_user_func(array($editor_class, 'supports_mime_type'), 'image/webp')) {
            return $attach_id;
        }
        $size = $editor->get_size();
        if (!empty($size['width']) && max((int) $size['width'], (int) $size['height']) > 1920) {
            $editor->resize(1920, 1920, false);
        }
        $upload = wp_upload_dir();
        $base   = preg_replace('/\.[a-z0-9]+$/i', '', wp_basename($path));
        $target = trailingslashit($upload['path']) . wp_unique_filename($upload['path'], $base . '.webp');
        $saved  = $editor->save($target, 'image/webp');
        if (is_wp_error($saved) || empty($saved['path']) || !file_exists($saved['path'])) {
            return $attach_id;
        }
        $twin_id = wp_insert_attachment(array(
            'post_mime_type' => 'image/webp',
            'post_title'     => get_the_title($attach_id),
            'post_status'    => 'inherit',
        ), $saved['path']);
        if (is_wp_error($twin_id) || !$twin_id) {
            @unlink($saved['path']); // phpcs:ignore
            return $attach_id;
        }
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        wp_update_attachment_metadata((int) $twin_id, wp_generate_attachment_metadata((int) $twin_id, $saved['path']));
        $alt = get_post_meta($attach_id, '_wp_attachment_image_alt', true);
        if ($alt) {
            update_post_meta((int) $twin_id, '_wp_attachment_image_alt', $alt);
        }
        update_post_meta($attach_id, '_bitbot_webp_twin', (int) $twin_id);
        update_post_meta((int) $twin_id, '_bitbot_webp_source', $attach_id);
        return (int) $twin_id;
    }

    /**
     * Image-SEO pass for a freshly sideloaded attachment: cap the longest
     * edge at 1920px and convert to WebP when the server's image editor
     * supports it (GD/Imagick with WebP — standard on most hosts). Falls back
     * to a resized original, or leaves the file untouched, when it can't —
     * best-effort by design, the image is already usable. Filename/alt are
     * the caller's job (both sideload paths derive them from the alt text).
     *
     * @since 2.1.115
     * @param int $attach_id Attachment id.
     * @return void
     */
    private function optimize_attachment_image($attach_id) {
        $path = get_attached_file($attach_id);
        if (!$path || !file_exists($path) || !wp_attachment_is_image($attach_id)) {
            return;
        }
        if ('image/gif' === get_post_mime_type($attach_id)) {
            return; // animation would be flattened
        }
        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            return;
        }
        $size    = $editor->get_size();
        $resized = false;
        if (!empty($size['width']) && max((int) $size['width'], (int) $size['height']) > 1920) {
            $res = $editor->resize(1920, 1920, false);
            $resized = !is_wp_error($res);
        }
        $editor_class = get_class($editor);
        $to_webp      = 'image/webp' !== get_post_mime_type($attach_id)
            && is_callable(array($editor_class, 'supports_mime_type'))
            && call_user_func(array($editor_class, 'supports_mime_type'), 'image/webp');
        if ($to_webp) {
            $webp_path = preg_replace('/\.[a-z0-9]+$/i', '.webp', $path);
            $saved     = $editor->save($webp_path, 'image/webp');
            if (!is_wp_error($saved) && !empty($saved['path']) && file_exists($saved['path'])) {
                if ($saved['path'] !== $path) {
                    @unlink($path); // phpcs:ignore
                }
                update_attached_file($attach_id, $saved['path']);
                wp_update_post(array('ID' => $attach_id, 'post_mime_type' => 'image/webp'));
                wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $saved['path']));
                return;
            }
        }
        if ($resized) {
            $saved = $editor->save($path);
            if (!is_wp_error($saved)) {
                wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $path));
            }
        }
    }

    /**
     * Update a single wp_posts field via wp_update_post (auto-creates a WP
     * revision for title/excerpt/status changes).
     *
     * @param int    $post_id
     * @param string $field
     * @param string $value
     * @return true|WP_Error
     */
    private function wp_update_post_field($post_id, $field, $value) {
        // wp_update_post() → wp_insert_post() runs wp_unslash() on the whole
        // array, stripping one backslash level. Un-slashed content loses every
        // literal backslash: an over-escaped newline `\n` collapses to `n`, a
        // `\d` regex to `d`, a Windows path `\Users` to `Users`. Slash first so
        // the internal unslash is a no-op and the bytes survive verbatim.
        $res = wp_update_post(array('ID' => $post_id, $field => wp_slash($value)), true);
        if (is_wp_error($res)) {
            return $res;
        }
        return true;
    }


    /**
     * Create a WordPress PAGE from the agent's block content, optionally making
     * it the static front page. Returns { pageId, before } where `before` is the
     * revert payload (created page id + prior homepage settings), or WP_Error.
     *
     * @since 2.1.57
     * @param mixed $value { title, content, slug?, status?, setAsHomepage?, template? }
     * @return array|WP_Error
     */
    private function create_page_apply($value) {
        if (!is_array($value) || empty($value['title']) || !isset($value['content'])) {
            return new WP_Error('bitbot_bad_page', 'A page needs a title and content.');
        }
        $status = isset($value['status']) && in_array($value['status'], array('draft', 'publish', 'private'), true)
            ? $value['status'] : 'publish';

        // Template pages ship with remote Openverse image URLs. Sideload each
        // into the Media Library so the images are local (survive if the source
        // goes away) and the owner can swap them in WP. Replace the remote URL
        // in the markup with the local attachment URL.
        $content = (string) $value['content'];
        $side    = array('imagesSideloaded' => array(), 'imagesFailed' => array());
        if (!empty($value['images']) && is_array($value['images'])) {
            $side = $this->sideload_page_images($content, $value['images']);
            $content = $side['content'];
        }

        $postarr = array(
            'post_type'    => 'page',
            'post_status'  => $status,
            'post_title'   => sanitize_text_field((string) $value['title']),
            // Block markup: do NOT wp_kses here (would strip <!-- wp:... -->);
            // wp_insert_post applies content KSES per the acting user's caps.
            'post_content' => wp_slash($content), // WordPress expects slashed input; unslashed, a literal \n loses its backslash and renders as "n"
        );
        if (!empty($value['slug'])) {
            $postarr['post_name'] = sanitize_title((string) $value['slug']);
        }

        $page_id = wp_insert_post($postarr, true);
        if (is_wp_error($page_id)) {
            return $page_id;
        }
        if (!$page_id) {
            return new WP_Error('bitbot_page_failed', 'Page creation failed.');
        }

        // PROVENANCE. The one marker that says WE made this page, so a later
        // decision about whether we may touch it is a fact rather than a guess.
        // The chrome flag below (_bitbot_canvas) looks like it would serve,
        // and must not be used for it: it describes how a page RENDERS, an
        // owner editing the page keeps it, and reading a
        // display flag as ownership is how an owner's homepage gets trashed by
        // something that meant to tidy up after itself.
        update_post_meta((int) $page_id, '_bitbot_created', time());

        $before = array('createdPageId' => (int) $page_id);
        // Outcome of the front-page handover, reported rather than assumed.
        $superseded      = null;
        $front_page_kept = null;

        if (!empty($value['setAsHomepage'])) {
            // Capture the prior front-page settings so revert restores them.
            $before['prevShowOnFront'] = (string) get_option('show_on_front', 'posts');
            $before['prevPageOnFront'] = (int) get_option('page_on_front', 0);
            update_option('show_on_front', 'page');
            update_option('page_on_front', (int) $page_id);
            // A block theme shipping front-page.html outranks these options in
            // the template hierarchy and would render its demo homepage instead
            // — assert a user-level front-page template override so the page we
            // just set actually shows.
            $this->ensure_front_page_override();

            // REPLACE, DO NOT ORPHAN.
            //
            // Moving the front-page pointer used to be the whole operation, so
            // the previous homepage stayed PUBLISHED and unlinked. Rebuilding a
            // homepage a few times left home-2 … home-7 behind on one site,
            // every one of them a live URL a visitor or a crawler could still
            // reach, none of them reachable from the site's own navigation.
            //
            // Only a page WE created and recorded as ours is superseded, and it
            // is TRASHED, never deleted, so the owner can restore it from
            // wp-admin like anything else. A homepage the owner built, or one
            // that predates us, is left exactly where it is — and the fact that
            // we left it is RETURNED AS DATA, so the agent can tell the owner
            // there are now two candidate homepages instead of quietly
            // deciding for them.
            $prev_id = (int) $before['prevPageOnFront'];
            if ($prev_id > 0 && $prev_id !== (int) $page_id) {
                if (get_post_meta($prev_id, '_bitbot_created', true)) {
                    $prev_title = get_the_title($prev_id);
                    if (wp_trash_post($prev_id)) {
                        $before['supersededPageId'] = $prev_id;
                        $superseded = array(
                            'id'     => $prev_id,
                            'title'  => (string) $prev_title,
                            'action' => 'trashed',
                        );
                    } else {
                        // A refused trash is not a tidy-up that happened. Say so.
                        $front_page_kept = array(
                            'id'     => $prev_id,
                            'title'  => (string) $prev_title,
                            'reason' => 'trash_refused',
                        );
                    }
                } else {
                    $front_page_kept = array(
                        'id'     => $prev_id,
                        'title'  => (string) get_the_title($prev_id),
                        'reason' => 'not_ours',
                    );
                }
            }
        }

        // Chrome flags (per-page post meta → wp_head CSS; see
        // BitBot::render_canvas_hide_css). We use post meta rather than a
        // <style> in content because content <style> is KSES-stripped on
        // multisite and leaks as visible text. Both flags go away when the page
        // is trashed on revert.
        //   - 'canvas' (explicit opt-in): hide the theme's header/footer AND the
        //     title so the page's own markup owns everything.
        //   - DEFAULT (incl. homepages): keep the theme header/nav/footer — so
        //     the theme menu (proposeNavMenu) is the ONE reliable site nav that
        //     shows on every page — and hide ONLY the page title so a designed
        //     page starts at its hero.
        // A NAMED template (the theme's own: landing, composed-page, home …)
        // is honoured as the page's template. The theme's homepage recipes
        // put the design in the template and want the page empty — without
        // this the page rendered as page-template-default and the agent's
        // composition carried the theme's palette and none of its structure.
        $named_tpl = isset($value['template']) ? sanitize_text_field((string) $value['template']) : '';
        if ('' !== $named_tpl && !in_array($named_tpl, array('default', 'canvas'), true)) {
            update_post_meta((int) $page_id, '_wp_page_template', $named_tpl);
        }
        // The POSTS page (Reading → "Posts page"). WordPress ignores its body
        // and template; the theme's `home` template renders it.
        if (!empty($value['setAsPostsPage'])) {
            $before['prevPageForPosts'] = (int) get_option('page_for_posts', 0);
            update_option('show_on_front', 'page');
            update_option('page_for_posts', (int) $page_id);
        }
        $explicit_canvas = isset($value['template']) && 'canvas' === $value['template'];
        if ($explicit_canvas) {
            update_post_meta((int) $page_id, '_bitbot_canvas', 1);
        }

        // READ-BACK, never echo. A creation is only real when WordPress says
        // it exists: the reported status/title/link come from re-fetching the
        // page we just inserted, so nothing downstream can claim a created
        // page from the REQUEST it sent. Missing row here = a loud 'missing'
        // status instead of a confident lie.
        $created = get_post((int) $page_id);
        return array(
            'pageId'        => (int) $page_id,
            'status'        => $created ? $created->post_status : 'missing',
            'verifiedTitle' => $created ? $created->post_title : '',
            'link'          => $created ? get_permalink((int) $page_id) : '',
            // Ground truth about the OLD homepage, so the agent reports what
            // actually happened to it instead of inferring from silence.
            'supersededPage'   => $superseded,
            'previousFrontPage' => $front_page_kept,
            'imagesSideloaded' => $side['imagesSideloaded'],
            'imagesFailed'     => $side['imagesFailed'],
            'before'        => $before,
        );
    }

    /**
     * Create an empty listing page and make it the site's Posts page so the
     * blog posts are browsable at a real URL. WordPress ignores the page's
     * content for a posts page (it renders the post loop), so we insert an
     * empty page. Captures the prior reading settings for a clean revert.
     *
     * @param array $value { title, slug }
     * @return array|WP_Error { pageId, before }
     */
    private function apply_nav_menu($items, $location = null, $keep_page_list = false) {
        if (!is_array($items) || count($items) === 0) {
            return new WP_Error('bitbot_bad_menu', 'A navigation menu needs at least one item.');
        }
        $before = array('prevLocations' => (array) get_theme_mod('nav_menu_locations', array()));

        // A header that lists published pages automatically keeps its page
        // list: the links are appended after it in the block navigation, and
        // no classic menu copy is made (round 2 TT25: a static 18-link copy
        // replaced the list). The API sends this only for block-theme headers.
        if ($keep_page_list) {
            $nav_id = $this->sync_block_navigation($items, true);
            if (!$nav_id) {
                return new WP_Error('bitbot_bad_menu', 'The block navigation could not be written (wp_navigation is not available).');
            }
            $this->ensure_header_nav_override();
            $before['createdMenuId'] = 0;
            $before['createdNavId']  = (int) $nav_id;
            return array(
                'menuId'       => 0,
                'navId'        => $nav_id,
                'assignedTo'   => array(),
                'locationFact' => null,
                'before'       => $before,
            );
        }

        // ONE store per theme (BitBot_Nav_Location::stores): a classic theme
        // writes its classic menu only, a block theme its navigation only.
        // Round 3: a classic add also wrote a wp_navigation post that took
        // over the header after a switch to a block theme.
        $stores = BitBot_Nav_Location::stores(function_exists('wp_is_block_theme') && wp_is_block_theme());
        $menu_id      = 0;
        $created_menu = false;
        $placed       = array('assignedTo' => array(), 'locationFact' => null);

        if ($stores['classicMenu']) {
            $menu_name = BitBot_Nav_Location::MENU_NAME;
            $existing_menu = wp_get_nav_menu_object($menu_name);
            if (!$existing_menu) {
                // Installs made before the rename hold the menu under its old name: adopt and rename it.
                $legacy_menu = wp_get_nav_menu_object(BitBot_Nav_Location::LEGACY_MENU_NAME);
                if ($legacy_menu) {
                    wp_update_nav_menu_object((int) $legacy_menu->term_id, array('menu-name' => $menu_name));
                    $existing_menu = wp_get_nav_menu_object($menu_name);
                }
            }
            if ($existing_menu) {
                $menu_id = (int) $existing_menu->term_id;
                // Clear its items so we rebuild cleanly (no duplicates on re-apply).
                $old = wp_get_nav_menu_items($menu_id);
                if (is_array($old)) {
                    foreach ($old as $it) {
                        wp_delete_post((int) $it->ID, true);
                    }
                }
            } else {
                $menu_id = wp_create_nav_menu($menu_name);
                if (is_wp_error($menu_id)) {
                    return $menu_id;
                }
                $menu_id = (int) $menu_id;
                $created_menu = true;
            }

            foreach ($items as $it) {
                $label = isset($it['label']) ? sanitize_text_field((string) $it['label']) : '';
                $url   = isset($it['url']) ? (string) $it['url'] : '';
                if ('' === $label || '' === $url) {
                    continue;
                }
                if (strpos($url, '/') === 0) {
                    $url = home_url($url); // root-relative → absolute
                }
                wp_update_nav_menu_item($menu_id, 0, array(
                    'menu-item-title'  => $label,
                    'menu-item-url'    => esc_url_raw($url),
                    'menu-item-status' => 'publish',
                    'menu-item-type'   => 'custom',
                ));
            }

            // Classic themes: the ONE location the API named (the active one,
            // else primary). No location named (an older API): every registered
            // location, as before. A named location the theme does not register
            // gets nothing, and the fact says so.
            $placed = BitBot_Nav_Location::assign(
                (array) get_theme_mod('nav_menu_locations', array()),
                array_keys((array) get_registered_nav_menus()),
                $menu_id,
                $location
            );
            if ($placed['changed']) {
                set_theme_mod('nav_menu_locations', $placed['locations']);
            }
        }

        // Block themes: a wp_navigation post from the same items, then the
        // header-part override so the new menu actually renders in the theme's
        // header (block themes ship demo links as literal inner blocks; see
        // ensure_header_nav_override).
        $nav_id = 0;
        $pin    = null;
        if ($stores['blockNavigation']) {
            $nav_id = $this->sync_block_navigation($items);
            if (!$nav_id) {
                return new WP_Error('bitbot_bad_menu', 'The block navigation could not be written (wp_navigation is not available).');
            }
            // The chosen links are written INLINE into the header part, so the
            // header does not depend on which wp_navigation post is newest.
            $header_part = $this->ensure_header_nav_override();
            $pin = array('pinned' => BitBot_Nav_Location::pinned_store($header_part));
            if (!$header_part && null !== $this->nav_pin_reason) {
                $pin['pinFact'] = $this->nav_pin_reason;
            }
        }

        $before['createdMenuId'] = $created_menu ? $menu_id : 0;
        $before['createdNavId']  = $nav_id ? (int) $nav_id : 0;
        return array_merge(array(
            'menuId'       => $menu_id,
            'navId'        => $nav_id,
            'assignedTo'   => $placed['assignedTo'],
            'locationFact' => $placed['locationFact'],
            'before'       => $before,
        ), $pin ? $pin : array());
    }

    /**
     * Create/update a wp_navigation post ("BitBot Primary") holding the menu as
     * navigation-link blocks — what block themes' header navigation block uses.
     * Returns the post id, or 0 if the CPT isn't available.
     *
     * @since 2.1.63
     * @param array $items [{ label, url }]
     * @return int
     */
    private function sync_block_navigation($items, $keep_page_list = false) {
        if (!post_type_exists('wp_navigation')) {
            return 0;
        }
        $links = '';
        foreach ($items as $it) {
            $label = isset($it['label']) ? sanitize_text_field((string) $it['label']) : '';
            // Store labels PLAIN ('&', not '&amp;'): agent-sent labels often
            // arrive entity-encoded — sometimes doubly ('&amp;amp;') — and any
            // residual entity inside the block JSON renders as garbage in the
            // menu. Decode until stable (bounded).
            for ($i = 0; $i < 3; $i++) {
                $decoded = html_entity_decode($label, ENT_QUOTES, 'UTF-8');
                if ($decoded === $label) {
                    break;
                }
                $label = $decoded;
            }
            $url   = isset($it['url']) ? (string) $it['url'] : '';
            if ('' === $label || '' === $url) {
                continue;
            }
            if (strpos($url, '/') === 0) {
                $url = home_url($url);
            }
            $attrs = wp_json_encode(array('label' => $label, 'url' => esc_url_raw($url), 'kind' => 'custom'));
            $links .= '<!-- wp:navigation-link ' . $attrs . " /-->\n";
        }

        $existing = BitBot_Nav_Location::find_navigation_posts();
        $postarr = array(
            'post_title'   => BitBot_Nav_Location::MENU_NAME,
            // wp_insert_post expects SLASHED input — without wp_slash every
            // backslash in the block JSON is eaten on save, so the &
            // escapes wp_json_encode emits render as literal "u0026" garbage
            // in menu labels.
            'post_content' => wp_slash(BitBot_Nav_Location::navigation_content($links, $keep_page_list)),
            'post_status'  => 'publish',
            'post_type'    => 'wp_navigation',
        );
        if (!empty($existing)) {
            $postarr['ID'] = (int) $existing[0]->ID;
        }
        $id = wp_insert_post($postarr);
        if (is_wp_error($id) || !$id) {
            return 0;
        }
        // Remember which post is OUR nav: BitBot::force_bitbot_navigation()
        // points every front-end core/navigation block at it, so the site nav
        // is ONE source that survives any theme switch (block themes hard-
        // reference their own demo menu post otherwise).
        update_option('bitbot_nav_post_id', (int) $id);
        return (int) $id;
    }

    /**
     * Make the owner's static front page immune to theme templates.
     *
     * A block theme that ships front-page.html outranks the Reading-settings
     * static front page in the template hierarchy, so switching to such a
     * theme replaces the owner's real homepage with theme demo content. The
     * sanctioned escape hatch is a USER-level template override (a wp_template
     * post scoped to the active theme) — same thing the Site Editor creates —
     * which beats the theme file and simply renders header + page content +
     * footer. No-op when the site doesn't use a static front page, the theme
     * is classic, the theme has no front-page template, or an override already
     * exists.
     *
     * @since 2.1.80
     * @return int The override wp_template post id, or 0 when not needed/failed.
     */
    public function ensure_front_page_override() {
        if (!function_exists('wp_is_block_theme') || !wp_is_block_theme()) {
            return 0;
        }
        $front_id = (int) get_option('page_on_front', 0);
        if ('page' !== get_option('show_on_front') || !$front_id) {
            return 0;
        }
        // SAFETY: only assert an override for a front page the AGENT built (our
        // chrome meta proves it). An established site whose owner set their own
        // front page — possibly relying on the theme's front-page template to
        // style it — must never have its homepage layout rewritten by us.
        // `_bitbot_created` is the provenance stamp; the chrome flags remain
        // for pages built before it.
        $agent_built = get_post_meta($front_id, '_bitbot_created', true) || get_post_meta($front_id, '_bitbot_canvas', true);
        if (!$agent_built) {
            return 0;
        }
        $theme = get_stylesheet();
        $tpl = function_exists('get_block_template') ? get_block_template($theme . '//front-page', 'wp_template') : null;
        if (!$tpl) {
            return 0; // theme has no front-page template — the static page already wins
        }
        if ('custom' === $tpl->source && !empty($tpl->wp_id)) {
            return (int) $tpl->wp_id; // a user override already exists for this theme
        }
        // CONSENT: if we created an override for this theme before and the
        // user deleted it in the Site Editor (record exists, post gone), they
        // chose the theme's own front-page design — never recreate it.
        $plugin = class_exists('BitBot') ? BitBot::get_instance() : null;
        if ($plugin && method_exists($plugin, 'get_overlay_manifest')) {
            $manifest = $plugin->get_overlay_manifest();
            $recorded = isset($manifest['overrides'][$theme]) ? (int) $manifest['overrides'][$theme] : 0;
            if ($recorded && 'publish' !== get_post_status($recorded)) {
                return 0;
            }
        }
        // Template-part slugs vary by theme — resolve header/footer by AREA.
        $header_slug = 'header';
        $footer_slug = 'footer';
        if (function_exists('get_block_templates')) {
            foreach ((array) get_block_templates(array(), 'wp_template_part') as $part) {
                if (!empty($part->area) && 'header' === $part->area) {
                    $header_slug = (string) $part->slug;
                } elseif (!empty($part->area) && 'footer' === $part->area) {
                    $footer_slug = (string) $part->slug;
                }
            }
        }
        $content = sprintf(
            "<!-- wp:template-part {\"slug\":\"%s\",\"theme\":\"%s\",\"tagName\":\"header\"} /-->\n" .
            "<!-- wp:group {\"tagName\":\"main\",\"layout\":{\"type\":\"constrained\"}} --><div class=\"wp-block-group\"><!-- wp:post-content {\"layout\":{\"type\":\"constrained\"}} /--></div><!-- /wp:group -->\n" .
            "<!-- wp:template-part {\"slug\":\"%s\",\"theme\":\"%s\",\"tagName\":\"footer\"} /-->",
            esc_attr($header_slug),
            esc_attr($theme),
            esc_attr($footer_slug),
            esc_attr($theme)
        );
        $post_id = wp_insert_post(array(
            'post_type'    => 'wp_template',
            'post_status'  => 'publish',
            'post_name'    => 'front-page',
            'post_title'   => 'Front Page',
            'post_content' => wp_slash($content), // WordPress expects slashed input; unslashed, a literal \n loses its backslash and renders as "n"
            'post_excerpt' => 'Youami: keep the static front page in charge on this theme.',
        ), true);
        if (is_wp_error($post_id) || !$post_id) {
            return 0;
        }
        wp_set_object_terms((int) $post_id, $theme, 'wp_theme');
        // Record the override in the overlay manifest (per-theme registry +
        // trace) so the deleted-guard above works and the agent's snapshot
        // shows the full styling history.
        if ($plugin && method_exists($plugin, 'get_overlay_manifest')) {
            $manifest = $plugin->get_overlay_manifest();
            $manifest['overrides'][$theme] = (int) $post_id;
            $manifest['history'][] = array('event' => 'override_created', 'theme' => $theme, 'templateId' => (int) $post_id, 'at' => gmdate('c'));
            $plugin->save_overlay_manifest($manifest);
        }
        return (int) $post_id;
    }

    /**
     * Render the agent-managed menu inside ANY block theme's header — the
     * native way. Block themes ship their header nav as literal inner
     * navigation-link blocks in the theme file (demo links: Typography,
     * Feeds, …), so a theme switch always resurrects them. The sanctioned
     * mechanism to change a header is the same one the Site Editor uses: a
     * USER override of the header template part, scoped to the active theme.
     * We take the theme's own header part verbatim and swap ONLY the first
     * navigation block's inner links for the "BitBot Primary" menu — the
     * header's design is untouched, the override is visible/deletable in the
     * Site Editor ("Clear customizations" restores the theme's), and:
     *   - if the USER already customised their header, we never touch it;
     *   - if the user deleted OUR override, we never recreate it;
     *   - if OUR override exists, we refresh it from the theme's ORIGINAL
     *     part (so edits never compound) with the current menu links.
     * No-op unless the agent manages the nav (bitbot_nav_post_id).
     *
     * @since 2.1.82
     * @return int The override wp_template_part post id, or 0.
     */
    public function ensure_header_nav_override() {
        $this->nav_pin_reason = null;
        if (!function_exists('wp_is_block_theme') || !wp_is_block_theme()) {
            return 0;
        }
        $nav_id = (int) get_option('bitbot_nav_post_id', 0);
        $nav_post = $nav_id ? get_post($nav_id) : null;
        if (!$nav_post || 'publish' !== $nav_post->post_status || '' === trim((string) $nav_post->post_content)) {
            return 0;
        }
        $links = array_values(array_filter(parse_blocks((string) $nav_post->post_content), function ($b) {
            return !empty($b['blockName']);
        }));
        if (empty($links)) {
            return 0;
        }
        $theme = get_stylesheet();
        // Resolve the theme's header/footer part slugs by AREA (varies by
        // theme). A theme with several header parts (Twenty Twenty-Five:
        // header, header-large-title, vertical-header) uses the one named
        // for its area in its templates, so that one wins whatever order
        // WordPress lists them in.
        $header_slug = 'header';
        $footer_slug = 'footer';
        if (function_exists('get_block_templates')) {
            $areas = array();
            foreach ((array) get_block_templates(array(), 'wp_template_part') as $part) {
                if (!empty($part->area) && in_array($part->area, array('header', 'footer'), true)) {
                    $areas[$part->area][] = (string) $part->slug;
                }
            }
            $header_slug = BitBot_Nav_Location::area_part($areas['header'] ?? array(), 'header');
            $footer_slug = BitBot_Nav_Location::area_part($areas['footer'] ?? array(), 'footer');
        }
        // The header part is often a single pattern reference (Twenty
        // Twenty-Five's parts/header.html is `wp:pattern
        // twentytwentyfive/header`): its navigation block lives in the
        // pattern, so patterns are expanded before the links are pinned.
        $result = $this->override_part_nav($theme, $header_slug, $links, 'header', true);

        // The footer often carries its OWN demo nav (dead theme links like
        // /features/, /typography/). Wire the real menu there too — the
        // override no-ops if the footer has no navigation block, and the same
        // consent rules apply (user-customised footers untouched). Patterns
        // are NOT expanded here: Twenty Twenty-Five's footer pattern carries
        // two demo navigation blocks, and pinning the first would leave the
        // second; those links are stated to the owner as a fact instead.
        $this->override_part_nav($theme, $footer_slug, $links, 'footer');

        // Some themes render the nav's links inside a separate OVERLAY part
        // (the hamburger dialog) — the header nav block names it in its
        // `overlay` attr. Wire our menu there too, or the mobile menu keeps
        // showing the theme's demo links.
        $overlay_slug = '';
        $file_tpl = function_exists('get_block_file_template') ? get_block_file_template($theme . '//' . $header_slug, 'wp_template_part') : null;
        if ($file_tpl && '' !== trim((string) $file_tpl->content)) {
            $find_overlay = function ($list) use (&$find_overlay) {
                foreach ($list as $b) {
                    if (isset($b['blockName']) && 'core/navigation' === $b['blockName'] && !empty($b['attrs']['overlay'])) {
                        return (string) $b['attrs']['overlay'];
                    }
                    if (!empty($b['innerBlocks'])) {
                        $found = $find_overlay($b['innerBlocks']);
                        if ('' !== $found) {
                            return $found;
                        }
                    }
                }
                return '';
            };
            $overlay_slug = $find_overlay($this->expand_pattern_blocks(parse_blocks((string) $file_tpl->content)));
        }
        if ('' !== $overlay_slug && $overlay_slug !== $header_slug) {
            $this->override_part_nav($theme, $overlay_slug, $links, 'uncategorized', true);
        }
        return $result;
    }

    /**
     * Create/refresh a user override of ONE theme template part with the
     * agent's menu swapped into its first navigation block (parser-based —
     * regex over block markup corrupts nested self-closing blocks). Consent
     * rules as documented on ensure_header_nav_override.
     *
     * @since 2.1.85
     * @param string $theme     Active stylesheet.
     * @param string $slug      Template part slug (e.g. 'header').
     * @param array  $links     Parsed navigation-link blocks.
     * @param string $area_term wp_template_part_area term for a new override.
     * @param bool   $expand    Expand pattern references before pinning (the header).
     * @return int Override post id, or 0 (the header's reason in $nav_pin_reason).
     */
    private function override_part_nav($theme, $slug, $links, $area_term, $expand = false) {
        $why = function ($reason) use ($area_term) {
            if ('header' === $area_term) {
                $this->nav_pin_reason = $reason;
            }
            return 0;
        };
        $plugin = class_exists('BitBot') ? BitBot::get_instance() : null;
        $manifest = ($plugin && method_exists($plugin, 'get_overlay_manifest')) ? $plugin->get_overlay_manifest() : array('navOverrides' => array(), 'history' => array());
        $key = $theme . '::' . $slug;
        // Back-compat: header overrides were recorded under just the theme key.
        $recorded = isset($manifest['navOverrides'][$key])
            ? (int) $manifest['navOverrides'][$key]
            : (('header' === $area_term && isset($manifest['navOverrides'][$theme])) ? (int) $manifest['navOverrides'][$theme] : 0);

        $current = function_exists('get_block_template') ? get_block_template($theme . '//' . $slug, 'wp_template_part') : null;
        if (!$current) {
            return $why('the theme has no header part ' . $slug);
        }
        $update_id = 0;
        if ('custom' === $current->source && !empty($current->wp_id)) {
            if ($recorded && (int) $current->wp_id === $recorded) {
                $update_id = $recorded; // ours — refresh it
            } else {
                return $why('the header was customised in the Site Editor, so it was left as it is'); // the USER customised this part — hands off
            }
        } elseif ($recorded && 'publish' !== get_post_status($recorded)) {
            return $why('the header override Youami made earlier was removed in the Site Editor, so it was not made again'); // user deleted our override — their choice is final
        }

        // Base = the theme's ORIGINAL part file (never our own output, so
        // repeated runs don't compound edits).
        $file_tpl = function_exists('get_block_file_template') ? get_block_file_template($theme . '//' . $slug, 'wp_template_part') : null;
        $base = $file_tpl ? (string) $file_tpl->content : (string) $current->content;
        if ('' === trim($base)) {
            return $why('the header part is empty');
        }

        $blocks = parse_blocks($base);
        if ($expand) {
            $blocks = $this->expand_pattern_blocks($blocks);
        }
        $pin = BitBot_Nav_Location::pin_links($blocks, $links);
        if (!$pin['pinned']) {
            return $why('the header part has no navigation block'); // nothing to wire
        }
        $swapped = serialize_blocks($pin['blocks']);
        if ('' === trim($swapped)) {
            return $why('the header part could not be written');
        }

        $postarr = array(
            'post_type'    => 'wp_template_part',
            'post_status'  => 'publish',
            'post_name'    => $slug,
            'post_title'   => ucwords(str_replace('-', ' ', $slug)),
            // wp_slash: wp_insert_post eats unslashed backslashes, corrupting
            // the \u escapes serialize_blocks emits in block-attr JSON.
            'post_content' => wp_slash($swapped),
            'post_excerpt' => 'Youami: theme part with the site menu wired in.',
        );
        if ($update_id) {
            $postarr['ID'] = $update_id;
        }
        $post_id = wp_insert_post($postarr, true);
        if (is_wp_error($post_id) || !$post_id) {
            return $why('the header part could not be written');
        }
        wp_set_object_terms((int) $post_id, $theme, 'wp_theme');
        wp_set_object_terms((int) $post_id, $area_term, 'wp_template_part_area');
        // Slug re-assert: a same-slug part left by a
        // PREVIOUS theme forces slug-2 at insert (theme term not yet set), and
        // the theme then never resolves our override. Re-apply the real slug
        // now that the wp_theme term exists so core scopes uniqueness per theme.
        if ($slug !== (string) get_post_field('post_name', (int) $post_id)) {
            wp_update_post(array('ID' => (int) $post_id, 'post_name' => $slug));
        }
        if ($plugin && method_exists($plugin, 'save_overlay_manifest')) {
            $manifest['navOverrides'][$key] = (int) $post_id;
            unset($manifest['navOverrides'][$theme]); // retire the legacy key
            $manifest['history'][] = array('event' => $update_id ? 'nav_part_refreshed' : 'nav_part_override_created', 'theme' => $theme, 'part' => $slug, 'partId' => (int) $post_id, 'at' => gmdate('c'));
            $plugin->save_overlay_manifest($manifest);
        }
        return (int) $post_id;
    }


    /**
     * Download each remote image into the Media Library and point its block
     * at the attachment (src, "id", wp-image-N; see BitBot_Page_Images). An
     * image that cannot be sideloaded is NOT left hotlinked: its block loses
     * the src, and the failure is reported with its reason.
     *
     * @since 2.1.61
     * @param string $content Block markup containing remote image URLs.
     * @param array  $images  [{ url, alt, slot? }] to sideload.
     * @return array { content, imagesSideloaded: [{slot|url, attachmentId, blocks}], imagesFailed: [{slot|url, reason}] }
     */
    private function sideload_page_images($content, $images) {
        if (!function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $swaps      = array();
        $failures   = array();
        $sideloaded = array();
        $failed     = array();
        foreach ($images as $img) {
            if (!is_array($img) || empty($img['url'])) {
                continue;
            }
            $remote = (string) $img['url'];
            // Reported by the slot the API named, else by the URL.
            $who    = !empty($img['slot']) ? array('slot' => (string) $img['slot']) : array('url' => $remote);
            $desc   = isset($img['alt']) ? sanitize_text_field((string) $img['alt']) : '';
            // Image SEO: the file gets a descriptive slug from its alt text
            // (never the CDN's hash name), then the optimizer caps it at
            // 1920px and converts to WebP where the server supports it.
            // download_url() wants the plain URL, never an &amp;-encoded one.
            $tmp = download_url(BitBot_Page_Images::normalise_url($remote));
            if (is_wp_error($tmp)) {
                $failures[] = array('from' => $remote);
                $failed[]   = array_merge($who, array('reason' => 'download failed: ' . $tmp->get_error_message()));
                continue;
            }
            $name = sanitize_file_name(preg_replace('/[^a-z0-9]+/i', '-', '' !== $desc ? $desc : 'page-image'));
            if ('' === $name) {
                $name = 'page-image';
            }
            $file_array = array('name' => $name . '.jpg', 'tmp_name' => $tmp);
            $attach_id  = media_handle_sideload($file_array, 0, '' !== $desc ? $desc : null);
            if (is_wp_error($attach_id)) {
                if (file_exists($tmp)) {
                    @unlink($tmp); // phpcs:ignore
                }
                $failures[] = array('from' => $remote);
                $failed[]   = array_merge($who, array('reason' => 'Media Library refused it: ' . $attach_id->get_error_message()));
                continue;
            }
            if ('' !== $desc) {
                update_post_meta((int) $attach_id, '_wp_attachment_image_alt', $desc);
            }
            $this->optimize_attachment_image((int) $attach_id);
            $local = wp_get_attachment_url((int) $attach_id);
            if (empty($local)) {
                $failures[] = array('from' => $remote);
                $failed[]   = array_merge($who, array('reason' => 'attachment ' . (int) $attach_id . ' has no URL'));
                continue;
            }
            $swaps[]      = array('from' => $remote, 'to' => $local, 'id' => (int) $attach_id);
            $sideloaded[] = array_merge($who, array('attachmentId' => (int) $attach_id, 'from' => $remote));
        }
        // The block takes the attachment the way core saves it (id + wp-image-N,
        // so srcset follows); a failed image leaves no remote URL behind.
        $rw = BitBot_Page_Images::rewrite($content, $swaps, $failures);
        foreach ($sideloaded as $i => $s) {
            $key = BitBot_Page_Images::normalise_url($s['from']);
            $sideloaded[$i]['blocks'] = isset($rw['swapped'][$key]) ? (int) $rw['swapped'][$key] : 0;
            unset($sideloaded[$i]['from']);
        }
        return array('content' => $rw['content'], 'imagesSideloaded' => $sideloaded, 'imagesFailed' => $failed);
    }

    /**
     * Write an SEO meta field to whichever SEO plugin is active (RankMath if
     * present, else Yoast keys — harmless as plain postmeta if neither runs).
     *
     * @param int    $post_id
     * @param string $field title|description|focusKeyphrase
     * @param string $value
     * @return true|WP_Error
     */
    private function write_seo_meta($post_id, $field, $value) {
        // ONE family decision, shared with the reader/renderer/sync
        // (api_client::seo_key_maps) — read and write can never diverge. The
        // twin family's key is DELETED on every write so each field converges
        // to a single storage location instead of two families shadowing each
        // other across plugin installs.
        $maps = $this->api_client->seo_key_maps();
        if (!isset($maps['primary'][$field])) {
            return new WP_Error('bitbot_bad_seo_field', 'Unsupported SEO field: ' . $field);
        }
        update_post_meta($post_id, $maps['primary'][$field], sanitize_text_field($value));
        delete_post_meta($post_id, $maps['legacy'][$field]);
        return true;
    }

    /**
     * Latest revision id for a post (revert target for body edits later; for
     * meta edits none is created and this returns null).
     *
     * @param int $post_id
     * @return int|null
     */
    private function latest_revision_id($post_id) {
        $revisions = wp_get_post_revisions($post_id, array('numberposts' => 1));
        if (empty($revisions)) {
            return null;
        }
        $latest = reset($revisions);
        return $latest ? (int) $latest->ID : null;
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

        // Optional: the page to switch off when this one takes its slot (Free page limit).
        $replace_page_id = isset( $_POST['replace_page_id'] ) ? sanitize_text_field( wp_unslash( $_POST['replace_page_id'] ) ) : '';

        $result = $this->api_client->resync_page($page_url, $replace_page_id);

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            $failure = array('error' => $result['error']);
            if (!empty($result['code'])) {
                $failure['code'] = $result['code'];
            }
            wp_send_json_error($failure);
        }
    }

    /**
     * AJAX handler for manual reconnect
     */
    /**
     * The administrator pressed "Connect to youami.ai". Records consent,
     * registers the site and queues the first content sync.
     */
    public function ajax_connect() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('error' => 'Permission denied'));
        }
        // The optional analytics box on the Connect screen: stored only when the
        // administrator ticked it. Absent or unticked leaves the option as it is (off by default).
        // Only the first connection can carry it (BitBot_Consent): a replayed
        // Connect request never overrides a later choice in Account.
        BitBot_Consent::apply_connect_analytics_choice( isset( $_POST['analytics'] ) ? sanitize_text_field( wp_unslash( $_POST['analytics'] ) ) : null );
        $result = BitBot::get_instance()->connect_site();
        if (!empty($result['success'])) {
            wp_send_json_success(array('connected' => true));
        }
        // Consent stays recorded; registration is retried on the next admin load.
        wp_send_json_success(array('connected' => true, 'registered' => false, 'message' => isset($result['error']) ? (string) $result['error'] : ''));
    }

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
        $lead_id = isset($_POST['lead_id']) ? sanitize_text_field(wp_unslash( $_POST['lead_id'] )) : '';
        $result = $this->api_client->run_lead_research($lead_id);
        $result['success'] ? wp_send_json_success($result['data']) : wp_send_json_error(array('error' => $result['error']));
    }

    public function ajax_draft_lead_email() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(array('error' => 'Permission denied')); }
        $lead_id = isset($_POST['lead_id']) ? sanitize_text_field(wp_unslash( $_POST['lead_id'] )) : '';
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
        $form_id = isset($_GET['form_id']) ? sanitize_text_field(wp_unslash( $_GET['form_id'] )) : '';
        $action = isset($_GET['action']) ? sanitize_text_field(wp_unslash( $_GET['action'] )) : '';

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
                            '<p style="margin:0 0 12px;color:#5c4a00;font-size:13px;">Upgrade to Assistant for more forms, plus email lead notifications and AI lead research.</p>' +
                            '<a href="' + dashUrl + '" class="button button-primary">Upgrade to Assistant: $20/mo</a>' +
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
        $lead_id = isset($_GET['lead_id']) ? sanitize_text_field(wp_unslash( $_GET['lead_id'] )) : '';

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
        $stage_filter = isset($_GET['stage']) ? sanitize_text_field(wp_unslash( $_GET['stage'] )) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash( $_GET['s'] )) : '';
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
                        <a href="<?php echo esc_url($url); ?>"<?php echo ( $stage_filter === $key ) ? ' class="current"' : ''; ?>><?php echo esc_html($label); ?></a>
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
                                        '<p style="margin:0 0 10px;font-weight:600;color:#3c2f00;">AI Lead Research needs Assistant or Agent.</p>' +
                                        '<p style="margin:0 0 14px;color:#5c4a00;font-size:13px;">Auto-scrape the lead\'s website + LinkedIn, surface intent signals, and draft personalized outreach in one click.</p>' +
                                        '<a href="' + dashUrl + '" class="button button-primary">Upgrade to Assistant: $20/mo</a>' +
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

        $stage     = isset($_POST['stage']) ? sanitize_text_field(wp_unslash( $_POST['stage'] )) : '';
        $search    = isset($_POST['search']) ? sanitize_text_field(wp_unslash( $_POST['search'] )) : '';
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

        $lead_id = isset($_POST['lead_id']) ? sanitize_text_field(wp_unslash( $_POST['lead_id'] )) : '';
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

        $lead_id = sanitize_text_field(wp_unslash( $_POST['lead_id'] ?? '' ));
        $stage = sanitize_text_field(wp_unslash( $_POST['stage'] ?? '' ));
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

        $lead_id = sanitize_text_field(wp_unslash( $_POST['lead_id'] ?? '' ));
        $content = sanitize_textarea_field(wp_unslash( $_POST['content'] ?? '' ));
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

        $lead_id = sanitize_text_field(wp_unslash( $_POST['lead_id'] ?? '' ));
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
        $form_id = isset($_POST['form_id']) ? sanitize_text_field(wp_unslash( $_POST['form_id'] )) : '';
        $result = $this->api_client->get_form($form_id);
        $result['success'] ? wp_send_json_success($result['data']) : wp_send_json_error(array('error' => $result['error']));
    }

    /**
     * The form fields posted by the admin screen as JSON: decoded first (a text
     * sanitizer would damage the JSON), then every field reduced to its allowed
     * shape by BitBot_Form_Markup::sanitize_fields. Malformed JSON or a non-list
     * is an error, never an empty field list that would wipe the form.
     *
     * @return array|WP_Error
     */
    private function form_fields_from_request() {
        // Nonce and capability are checked by every caller before this runs.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON: decoded, then each field sanitized below.
        $raw = isset($_POST['fields']) ? wp_unslash( $_POST['fields'] ) : '';
        // A JSON array of field objects. Decoded without assoc first so that an
        // object at the top ({} or {"0":...}) is told apart from a list ([]).
        $shape = is_string($raw) ? json_decode($raw) : null;
        if (!is_array($shape)) {
            return new WP_Error('bitbot_bad_fields', 'The form fields could not be read.');
        }
        return BitBot_Form_Markup::sanitize_fields(json_decode($raw, true));
    }

    public function ajax_create_form() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(array('error' => 'Permission denied')); }
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash( $_POST['name'] )) : '';
        $description = isset($_POST['description']) ? sanitize_text_field(wp_unslash( $_POST['description'] )) : '';
        $fields = $this->form_fields_from_request();
        if (is_wp_error($fields)) { wp_send_json_error(array('error' => $fields->get_error_message())); }
        $result = $this->api_client->create_form($name, $description, $fields);
        $result['success'] ? wp_send_json_success($result['data']) : wp_send_json_error(array('error' => $result['error']));
    }

    public function ajax_update_form() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(array('error' => 'Permission denied')); }
        $form_id = isset($_POST['form_id']) ? sanitize_text_field(wp_unslash( $_POST['form_id'] )) : '';
        $data = array();
        if (isset($_POST['name'])) $data['name'] = sanitize_text_field(wp_unslash( $_POST['name'] ));
        if (isset($_POST['fields'])) {
            $fields = $this->form_fields_from_request();
            if (is_wp_error($fields)) { wp_send_json_error(array('error' => $fields->get_error_message())); }
            $data['fields'] = $fields;
        }
        if (isset($_POST['enabled'])) $data['enabled'] = rest_sanitize_boolean(wp_unslash( $_POST['enabled'] ));
        // The editor saves through this handler (so the gate's cached schema refreshes); it sends the success message too.
        if (isset($_POST['success_message'])) $data['success_message'] = sanitize_textarea_field(wp_unslash($_POST['success_message']));
        $result = $this->api_client->update_form($form_id, $data);
        if ( $result['success'] ) {
            // The public widget renders the pre-chat form from a cached schema. Editing
            // the gate's form while the gate is on must reach the widget without a
            // toggle, so refresh the cache here from the update's own response (the
            // updated form row) and never report a save whose schema did not reach
            // the widget: the API would then refuse submissions for fields visitors
            // cannot see.
            if ( get_option( 'bitbot_chat_gate_enabled' ) === '1'
                && (string) get_option( 'bitbot_chat_gate_form_id', '' ) === (string) $form_id ) {
                $schema = ( is_array( $result['data'] ) && isset( $result['data']['fields'] ) ) ? $result['data'] : null;
                if ( null === $schema ) {
                    $schema_res = $this->api_client->get_form( $form_id );
                    if ( ! empty( $schema_res['success'] ) && isset( $schema_res['data']['fields'] ) ) {
                        $schema = $schema_res['data'];
                    }
                }
                $encoded = null === $schema ? null : wp_json_encode( $schema );
                // update_option() returns false for an unchanged value too, so compare after writing.
                $stored  = null !== $encoded && ( update_option( 'bitbot_chat_gate_form_schema', $encoded ) || get_option( 'bitbot_chat_gate_form_schema' ) === $encoded );
                if ( ! $stored ) {
                    wp_send_json_error( array( 'error' => 'The form was saved, but the chat gate could not load the new fields. Turn the pre-chat form off and on again.' ) );
                }
            }
            wp_send_json_success( $result['data'] );
        } else {
            wp_send_json_error( array( 'error' => $result['error'] ) );
        }
    }

    public function ajax_delete_form() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(array('error' => 'Permission denied')); }
        $form_id = isset($_POST['form_id']) ? sanitize_text_field(wp_unslash( $_POST['form_id'] )) : '';
        $result = $this->api_client->delete_form($form_id);
        $result['success'] ? wp_send_json_success($result['data']) : wp_send_json_error(array('error' => $result['error']));
    }

    public function ajax_generate_form() {
        check_ajax_referer('bitbot_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(array('error' => 'Permission denied')); }
        $description = isset($_POST['description']) ? sanitize_text_field(wp_unslash( $_POST['description'] )) : '';
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
        $enabled = isset( $_POST['enabled'] ) && sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) === '1';
        $result  = $this->api_client->set_chat_gate( $enabled );
        if ( ! $result['success'] ) {
            wp_send_json_error( array( 'error' => $result['error'] ) );
            return;
        }
        $data    = $result['data'];
        $form_id = isset( $data['formId'] ) ? sanitize_text_field( $data['formId'] ) : '';
        $on      = ! empty( $data['chatGateEnabled'] );

        // Enabling is only a success when the form's field schema is cached
        // too: the widget renders the gate from it. When the fetch fails the
        // gate flag is rolled back off (API and option) and the SPA gets an error.
        // When disabling, the cached schema is moot (parse_chat_gate_form
        // short-circuits on the flag), so no extra GetForm call.
        if ( $on ) {
            $schema_res = $form_id !== '' ? $this->api_client->get_form( $form_id ) : array( 'success' => false );
            if ( empty( $schema_res['success'] ) || ! isset( $schema_res['data'] ) ) {
                $this->api_client->set_chat_gate( false );
                update_option( 'bitbot_chat_gate_enabled', '0' );
                delete_option( 'bitbot_chat_gate_form_schema' );
                wp_send_json_error( array( 'error' => 'Could not load the form for the chat gate.' ) );
                return;
            }
            update_option( 'bitbot_chat_gate_form_schema', wp_json_encode( $schema_res['data'] ) );
        }
        if ( $form_id !== '' ) {
            update_option( 'bitbot_chat_gate_form_id', $form_id );
        }
        update_option( 'bitbot_chat_gate_enabled', $on ? '1' : '0' );

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
        $enabled = isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) );
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
        if ( ! BitBot_Consent::is_connected() ) {
            wp_send_json_error( array( 'error' => 'not_connected' ), 404 );
        }
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
        $verdict = isset( $_POST['verdict'] ) ? sanitize_key( (string) wp_unslash( $_POST['verdict'] ) ) : '';
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
        $conversation_id = isset( $_POST['conversation_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['conversation_id'] ) ) : '';
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

        $conversation_id = isset( $_POST['conversation_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['conversation_id'] ) ) : '';
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

        $conversation_id = isset( $_POST['conversation_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['conversation_id'] ) ) : '';
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

        $conversation_id = isset( $_POST['conversation_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['conversation_id'] ) ) : '';
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
        $active_id = isset( $_GET['thread'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['thread'] ) ) : '';

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

}
