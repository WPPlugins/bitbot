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
 * Version:           2.1.0
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
define( 'BITBOT_VERSION', '2.1.0' );
define( 'BITBOT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BITBOT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
// API endpoint.
//   main branch / WordPress.org release  -> PROD: https://www.youami.ai/api  (active below)
//   dev branch / local sandbox testing   -> DEV:  comment the prod line, uncomment the dev line
define( 'BITBOT_API_URL', 'https://www.youami.ai/api' );
// define( 'BITBOT_API_URL', 'https://dev.youami.ai/api' );

// Include required files
require_once BITBOT_PLUGIN_DIR . 'includes/class-plan.php';
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
     * Cron hook: hourly retry of first content sync. Self-unregisters
     * once the backend reports at least one indexed page, so it's a
     * bounded safety net, not a steady-state load.
     */
    const FIRST_SYNC_CRON_HOOK = 'bitbot_retry_first_sync';

    /**
     * Single-shot cron fired once from activate(). Runs every outbound
     * HTTP call (register_site, get_plan_status, trigger_sync) off the
     * activation-request critical path so slow hosts don't half-install.
     */
    const FIRST_BOOT_CRON_HOOK = 'bitbot_first_boot';

    /**
     * Deferred first sync — scheduled by first_boot_cron for +65s so it
     * runs in a separate wp-cron.php invocation, with its own PHP
     * execution budget, avoiding "blocking POST killed mid-flight"
     * failures on shared hosts.
     */
    const FIRST_SYNC_RUN_HOOK = 'bitbot_first_sync_run';

    /**
     * Resumable bulk-sync tick. Each fire processes ONE batch of pages then
     * reschedules itself for the next batch, so a full re-index of a large
     * site (thousands of pages) is spread across many tiny wp-cron requests
     * that each stay well under PHP memory/time limits. Progress is written
     * to the `bitbot_sync_progress` option so the admin SPA can poll it.
     */
    const SYNC_CHUNK_CRON_HOOK = 'bitbot_sync_chunk';

    /** Option holding live bulk-sync progress; read by the admin SPA. */
    const SYNC_PROGRESS_OPTION = 'bitbot_sync_progress';

    /** Transient caching the post-ID worklist for the active bulk sync. */
    const SYNC_IDS_TRANSIENT = 'bitbot_sync_ids';

    /**
     * Token identifying the CURRENT bulk-sync run. Every start_background_sync
     * mints a fresh one; each cron tick carries the token it was scheduled
     * with and bails if it no longer matches — so a re-triggered sync cleanly
     * supersedes any in-flight chain instead of racing it (which previously
     * stamped last_sync early and froze the counter).
     */
    const SYNC_RUN_OPTION = 'bitbot_sync_run';

    /**
     * Hard ceiling on a single bulk-sync run. A run that hasn't finished
     * within this window is force-stopped on the next tick — a backstop
     * against any chain that never reaches its final batch and would
     * otherwise reschedule itself indefinitely. The worklist transient also
     * self-expires (see SYNC_IDS_TRANSIENT TTL) as a second safety net.
     */
    const SYNC_MAX_RUN_SECONDS = 1800; // 30 minutes

    /**
     * Deferred, debounced prune of pages deleted in WordPress. A post delete
     * schedules this once (not a full re-sync); it sends only the current URL
     * list so the backend drops stale pages. Debounced so a burst of deletes
     * collapses into a single prune.
     */
    const PRUNE_CRON_HOOK = 'bitbot_prune_deleted';

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
     * Detect REST API requests at construction time (before REST_REQUEST is
     * defined). Matches when the request URI starts with the configured
     * rest prefix (default `wp-json/`).
     */
    private function is_rest_url() {
        if ( empty( $_SERVER['REQUEST_URI'] ) ) return false;
        $prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
        return strpos( $_SERVER['REQUEST_URI'], '/' . $prefix . '/' ) !== false;
    }

    /**
     * Constructor
     *
     * @since 1.0.0
     */
    private function __construct() {
        $this->api_client = new BitBot_API_Client();

        // Initialize admin in admin area OR for REST requests.
        // REST API runs in front-end context (is_admin() = false) but still
        // needs the admin handlers (e.g. /wp-json/bitbot/v1/admin-settings,
        // /sync, /generate-post). Without this, REST endpoints registered
        // with `array($this->admin, 'method')` resolve to `array(null,...)`
        // and WordPress returns 500 "rest_invalid_handler".
        if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || $this->is_rest_url() ) {
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

        // The admin React bundle is shipped as an ES module — Vite emits
        // cross-chunk imports between admin.js, module.js, and the lazy
        // analytics chart chunk. Without type="module" the browser refuses
        // to parse them and the whole admin SPA stays blank.
        add_filter( 'script_loader_tag', array( $this, 'mark_admin_react_as_module' ), 10, 3 );

        // Register REST API endpoints
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        // Auto-sync on content changes
        add_action( 'save_post', array( $this, 'on_post_saved' ), 10, 2 );
        add_action( 'trashed_post', array( $this, 'on_post_deleted' ) );
        add_action( 'before_delete_post', array( $this, 'on_post_deleted' ) );

        // Deferred sync handler (runs via WP-Cron, not inside the REST save request)
        add_action( 'bitbot_sync_post', array( $this, 'run_deferred_post_sync' ) );

        // Resumable bulk-sync tick — one batch per fire, reschedules until done.
        add_action( self::SYNC_CHUNK_CRON_HOOK, array( $this, 'run_sync_chunk' ), 10, 2 );

        // Deferred, debounced prune when posts are deleted (replaces the old
        // full blocking re-sync on every deletion).
        add_action( self::PRUNE_CRON_HOOK, array( $this, 'run_prune_deleted' ) );

        // Hourly safety-net that retries the first content sync until
        // at least one page is indexed. Self-unregisters on success.
        add_action( self::FIRST_SYNC_CRON_HOOK, array( $this, 'retry_first_sync_cron' ) );

        // Single-shot first-boot handler (register + plan seed + first
        // sync). Bumps PHP limits internally so the blocking sync POST
        // doesn't get killed by tight wp-cron execution budgets.
        add_action( self::FIRST_BOOT_CRON_HOOK, array( $this, 'first_boot_cron' ) );

        // Redirect to dashboard after activation
        add_action( 'admin_init', array( $this, 'activation_redirect' ) );

        // One-time upgrade tasks (e.g., backfill notification_email on 1.5.2 -> 1.6.0)
        add_action( 'admin_init', array( $this, 'maybe_run_upgrade_tasks' ) );

        // Self-heal registration: if the activation-time register_site() was
        // killed (TasteWP, multisite network-activate, hosting timeout), the
        // site holds a local api key the backend never received. Re-attempt on
        // admin load until it sticks, so the sites row + signup notification
        // eventually fire and RPCs stop failing. Rate-limited.
        add_action( 'admin_init', array( $this, 'maybe_register_site' ) );

        // Ensure API key exists (in case activation hook didn't run)
        $this->ensure_api_key();

        // Register shortcodes
        add_shortcode( 'bitbot_form', array( $this, 'render_form_shortcode' ) );

        // Public form-host iframe route. Triggered by ?bitbot_form_embed=<id>
        // — short-circuits WP routing and emits a minimal standalone HTML page
        // that any external site can iframe. Hooked early on `init` so it
        // runs before WP starts rendering the theme.
        add_action( 'init', array( $this, 'maybe_render_form_embed' ), 1 );

        // Add settings link to plugins page
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );

        // 2.0.12: rate-us nudge. Renders a dismissible admin notice on
        // BitBot screens once the time + engagement gates pass. Scoped
        // to our own screens so we never nag on unrelated admin pages.
        add_action( 'admin_notices', array( $this, 'maybe_render_rate_us_notice' ) );

        // 2.0.16: gate the wp_footer host-div render with the same
        // hide-on-pages logic the enqueue path uses. Without this, the
        // <div id="bitbot-widget"> still landed on /checkout etc., even
        // though widget.js wasn't loaded — confusing diagnostic state.
        add_filter( 'bitbot_should_hide_widget', array( $this, 'is_current_url_hidden' ) );
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
     * Render the rate-us admin notice if all gates pass.
     *
     * Time gate: 7 days since first install (set on activation, never
     * reset on deactivate/reactivate).
     * Engagement gate: admin has loaded the BitBot SPA on 3+ distinct
     * calendar days (tracked by the admin heartbeat handler, stored as
     * a YYYY-MM-DD set in wp_options, capped at 30 entries).
     * Suppression: dismissed forever OR remind-after timestamp in the
     * future.
     *
     * Scoped to BitBot screens via current_screen() so we never nag on
     * unrelated admin pages (Posts, Plugins, Dashboard, etc.).
     *
     * @since 2.0.12
     */
    public function maybe_render_rate_us_notice() {
        // Suppress permanently if the admin already dismissed or rated.
        if ( '1' === get_option( 'bitbot_rate_us_dismissed', '' ) ) {
            return;
        }
        // Suppress until the "remind me later" deadline passes.
        $remind_after = (int) get_option( 'bitbot_rate_us_remind_after', 0 );
        if ( $remind_after > 0 && time() < $remind_after ) {
            return;
        }
        // Time gate: 7 days since the first activation.
        $first_install = (int) get_option( 'bitbot_first_install_at', 0 );
        if ( $first_install <= 0 || ( time() - $first_install ) < 7 * DAY_IN_SECONDS ) {
            return;
        }
        // Engagement gate: at least 3 distinct active days on the SPA.
        $days = get_option( 'bitbot_admin_active_days', array() );
        if ( ! is_array( $days ) || count( $days ) < 3 ) {
            return;
        }
        // Screen gate: only on BitBot admin pages, never on unrelated screens.
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || strpos( (string) $screen->id, 'bitbot' ) === false ) {
            return;
        }

        $rate_url = 'https://wordpress.org/plugins/bitbot/#reviews';
        $nonce    = wp_create_nonce( 'bitbot_admin_nonce' );
        $ajax_url = admin_url( 'admin-ajax.php' );

        // Inline JS handles the three clicks: each calls the
        // bitbot_rate_us_action ajax endpoint with the chosen verdict
        // and hides the notice optimistically (no spinner — feels
        // snappier than waiting on the round-trip).
        ?>
        <div class="notice notice-info is-dismissible" id="bitbot-rate-us-notice" style="padding: 14px 16px;">
            <p style="margin: 0 0 8px; font-size: 14px;">
                <strong><?php esc_html_e( 'Enjoying BitBot?', 'bitbot' ); ?></strong>
                <?php esc_html_e( 'Drop us a 5-star rating on WordPress.org — it helps other small business owners find us.', 'bitbot' ); ?>
            </p>
            <p style="margin: 0;">
                <a href="<?php echo esc_url( $rate_url ); ?>" target="_blank" rel="noopener" class="button button-primary" id="bitbot-rate-us-rate">
                    <?php esc_html_e( 'Rate BitBot', 'bitbot' ); ?> &rarr;
                </a>
                <button type="button" class="button button-secondary" id="bitbot-rate-us-remind" style="margin-left: 6px;">
                    <?php esc_html_e( 'Remind me later', 'bitbot' ); ?>
                </button>
                <button type="button" class="button-link" id="bitbot-rate-us-dismiss" style="margin-left: 6px; text-decoration: none; color: #6b7280;">
                    <?php esc_html_e( 'Dismiss', 'bitbot' ); ?>
                </button>
            </p>
        </div>
        <script>
        (function() {
            var notice = document.getElementById('bitbot-rate-us-notice');
            if (!notice) return;
            function postVerdict(verdict) {
                var body = new URLSearchParams();
                body.append('action', 'bitbot_rate_us_action');
                body.append('nonce', <?php echo wp_json_encode( $nonce ); ?>);
                body.append('verdict', verdict);
                fetch(<?php echo wp_json_encode( $ajax_url ); ?>, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: body,
                    keepalive: true
                }).catch(function() { /* swallow */ });
                notice.style.display = 'none';
            }
            var rateBtn = document.getElementById('bitbot-rate-us-rate');
            if (rateBtn) rateBtn.addEventListener('click', function() { postVerdict('rate'); });
            var remindBtn = document.getElementById('bitbot-rate-us-remind');
            if (remindBtn) remindBtn.addEventListener('click', function() { postVerdict('remind'); });
            var dismissBtn = document.getElementById('bitbot-rate-us-dismiss');
            if (dismissBtn) dismissBtn.addEventListener('click', function() { postVerdict('dismiss'); });
            // WP's own .notice-dismiss (the X) also counts as a soft remind.
            notice.addEventListener('click', function(e) {
                if (e.target && e.target.classList && e.target.classList.contains('notice-dismiss')) {
                    postVerdict('remind');
                }
            });
        })();
        </script>
        <?php
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
     * Self-heal registration. The activation hook is the only other place
     * register_site() runs, and it can be killed mid-request (TasteWP,
     * multisite network-activate, hosting timeouts) — leaving the site with
     * a local api key the backend never received: no sites row, no signup
     * notification, every RPC returning "invalid api key" while the UI still
     * says "All systems normal". This re-attempts registration on admin load
     * (with the EXISTING key — non-destructive) until it succeeds. register_site()
     * sets `bitbot_registered` on success, which short-circuits this. A 5-minute
     * transient throttles retries so a down backend never slows the admin.
     *
     * @since 2.0.181
     */
    public function maybe_register_site() {
        if ( get_option( 'bitbot_registered' ) ) {
            return; // already confirmed with the backend
        }
        if ( get_transient( 'bitbot_register_retry' ) ) {
            return; // backed off; try again after the throttle window
        }
        set_transient( 'bitbot_register_retry', 1, 5 * MINUTE_IN_SECONDS );
        $this->ensure_api_key();
        $this->api_client->register_site();
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

        // 1.7.0: sites upgrading from 1.6.x never ran activate() for
        // this version, so defer a first-boot cron to seed the plan
        // cache + kick sync + schedule the refresh crons. Same deferral
        // pattern as a fresh install — outbound calls never block the
        // admin page load.
        if ( version_compare( $stored, '1.7.0', '<' ) ) {
            if ( ! wp_next_scheduled( self::FIRST_BOOT_CRON_HOOK ) ) {
                wp_schedule_single_event( time() + 1, self::FIRST_BOOT_CRON_HOOK );
            }
            if ( ! wp_next_scheduled( self::FIRST_SYNC_CRON_HOOK ) ) {
                wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::FIRST_SYNC_CRON_HOOK );
            }
        }

        // Retired the daily plan-cache refresh cron: the backend gates every
        // chat server-side and the widget reacts to an expired response, so the
        // poll only narrowed a cosmetic FAB flash and did it poorly at 24h.
        // Shed any event already scheduled on existing installs.
        wp_clear_scheduled_hook( BitBot_Plan::REFRESH_CRON_HOOK );

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
     * Kick off a resumable background bulk sync.
     *
     * Snapshots the post-ID worklist once (so batch boundaries stay stable
     * across cron ticks), seeds the progress option, and schedules the first
     * chunk. Returns immediately — the admin can close the tab; the cron
     * chain self-drives to completion. Safe to call again: a fresh call
     * restarts from batch 0 with a new snapshot.
     *
     * @since 2.0.3
     * @return array { total, totalBatches } for the SPA's initial render.
     */
    /** Batch size for the resumable bulk sync (pages per SyncContent POST). */
    const SYNC_BATCH_SIZE = 100;

    public function start_background_sync() {
        $ids = $this->api_client->gather_post_ids();
        $total = count( $ids );

        // Mint a fresh run token. Any cron tick from a PRIOR run carries an
        // older token and will bail, so re-triggering cleanly supersedes an
        // in-flight sync instead of racing it.
        $run_id = wp_generate_uuid4();
        update_option( self::SYNC_RUN_OPTION, $run_id, false );

        if ( $total === 0 ) {
            update_option( self::SYNC_PROGRESS_OPTION, array(
                'state'      => 'done',
                'done'       => 0,
                'total'      => 0,
                'run_id'     => $run_id,
                'message'    => 'No published content found to sync.',
                'updated_at' => time(),
            ), false );
            delete_transient( self::SYNC_IDS_TRANSIENT );
            return array( 'total' => 0, 'totalBatches' => 0 );
        }

        $total_batches = (int) ceil( $total / self::SYNC_BATCH_SIZE );

        // Snapshot the worklist so every tick chunks the SAME list.
        set_transient( self::SYNC_IDS_TRANSIENT, $ids, HOUR_IN_SECONDS );

        update_option( self::SYNC_PROGRESS_OPTION, array(
            'state'         => 'running',
            'done'          => 0,
            'cursor'        => 0,      // next batch index — authoritative resume point
            'total'         => $total,
            'total_batches' => $total_batches,
            'batch_size'    => self::SYNC_BATCH_SIZE,
            'run_id'        => $run_id,
            'started_at'    => time(),
            'updated_at'    => time(),
        ), false );

        $this->schedule_sync_chunk( 0, $run_id );

        return array( 'total' => $total, 'totalBatches' => $total_batches );
    }

    /**
     * Stop the active bulk sync immediately.
     *
     * Rotates the run token to a value no scheduled tick carries, so every
     * in-flight chain bails on its next fire (via the run guard), and clears
     * the worklist. Idempotent and safe to call when nothing is running.
     *
     * @since 2.0.3
     * @return array { state, done, total } snapshot after stopping.
     */
    public function stop_background_sync() {
        $progress = get_option( self::SYNC_PROGRESS_OPTION );
        $done  = ( is_array( $progress ) && isset( $progress['done'] ) )  ? (int) $progress['done']  : 0;
        $total = ( is_array( $progress ) && isset( $progress['total'] ) ) ? (int) $progress['total'] : 0;

        // New token nobody matches → all chains bail on next tick.
        update_option( self::SYNC_RUN_OPTION, 'stopped-' . wp_generate_uuid4(), false );
        delete_transient( self::SYNC_IDS_TRANSIENT );

        update_option( self::SYNC_PROGRESS_OPTION, array(
            'state'      => 'idle',
            'done'       => $done,
            'total'      => $total,
            'stopped_at' => time(),
            'updated_at' => time(),
        ), false );

        return array( 'state' => 'idle', 'done' => $done, 'total' => $total );
    }

    /**
     * Schedule (and nudge) the next bulk-sync chunk for a given run.
     *
     * @since 2.0.3
     * @param int    $batch_index Zero-based batch to process next.
     * @param string $run_id      Token of the run this tick belongs to.
     */
    private function schedule_sync_chunk( $batch_index, $run_id ) {
        $args = array( $batch_index, $run_id );
        if ( ! wp_next_scheduled( self::SYNC_CHUNK_CRON_HOOK, $args ) ) {
            wp_schedule_single_event( time(), self::SYNC_CHUNK_CRON_HOOK, $args );
        }
        // Nudge wp-cron to fire promptly instead of waiting for the next
        // front-end visit (low-traffic sites would otherwise stall).
        if ( function_exists( 'spawn_cron' ) ) {
            spawn_cron();
        }
    }

    /**
     * Process one bulk-sync batch, update progress, and reschedule the next.
     *
     * One batch per fire keeps each wp-cron request tiny so it never trips
     * memory/time limits — the failure mode that killed the old single-shot
     * blocking sync on large sites. Resumable: if a tick dies, the next
     * front-end visit re-fires the still-scheduled event.
     *
     * @since 2.0.3
     * @param int    $batch_index Zero-based batch to process.
     * @param string $run_id      Token of the run this tick belongs to.
     */
    public function run_sync_chunk( $batch_index = 0, $run_id = '' ) {
        // Run guard: bail if a newer sync (or a stop) has superseded this chain.
        if ( get_option( self::SYNC_RUN_OPTION ) !== $run_id ) {
            return;
        }

        // Only an actively-running sync does work. A tick that arrives after
        // the run finished / errored / was stopped (e.g. the watchdog below)
        // bails here — this also stops the max-run cap from ever flipping a
        // completed run to 'error'.
        $progress = get_option( self::SYNC_PROGRESS_OPTION );
        if ( ! is_array( $progress ) || ( isset( $progress['state'] ) && $progress['state'] !== 'running' ) ) {
            return;
        }

        // Hard max-run cap: force-stop a run that's overrun its window so a
        // desynced chain can never reschedule itself forever.
        $started_at = isset( $progress['started_at'] ) ? (int) $progress['started_at'] : time();
        if ( time() - $started_at > self::SYNC_MAX_RUN_SECONDS ) {
            update_option( self::SYNC_PROGRESS_OPTION, array_merge( $progress, array(
                'state'      => 'error',
                'error'      => 'Sync timed out',
                'updated_at' => time(),
            ) ), false );
            delete_transient( self::SYNC_IDS_TRANSIENT );
            return;
        }

        $ids = get_transient( self::SYNC_IDS_TRANSIENT );
        if ( ! is_array( $ids ) || empty( $ids ) ) {
            return; // worklist expired or cleared — stop cleanly
        }

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 );
        }

        $batch_size    = self::SYNC_BATCH_SIZE;
        $batches       = array_chunk( $ids, $batch_size );
        $total_batches = count( $batches );
        $total         = count( $ids );

        // The PERSISTED cursor is the authoritative resume point — not the
        // passed arg (which a stale/watchdog event may carry). So any tick,
        // however it was triggered, resumes from exactly where we left off.
        $cursor = isset( $progress['cursor'] ) ? (int) $progress['cursor'] : (int) $batch_index;
        if ( $cursor >= $total_batches ) {
            return;
        }

        // WATCHDOG (the #1 resilience fix): queue a recovery tick ~90s out
        // BEFORE the heavy loop. If this PHP process is killed mid-loop (tight
        // host limit / OOM), no progress is lost — the watchdog fires and
        // resumes from the persisted cursor. Sentinel index -1 + run_id keeps
        // exactly one watchdog queued per run; on normal completion it simply
        // bails (state !== 'running'). Batches are idempotent (content_hash),
        // so a re-run of an interrupted batch is a cheap no-op.
        if ( ! wp_next_scheduled( self::SYNC_CHUNK_CRON_HOOK, array( -1, $run_id ) ) ) {
            wp_schedule_single_event( time() + 90, self::SYNC_CHUNK_CRON_HOOK, array( -1, $run_id ) );
        }

        // 100 pages/batch, several batches per tick under a wall-clock budget.
        $time_budget_secs = 25;
        $max_per_tick     = 8;
        $tick_started     = time();
        $processed        = 0;

        while (
            $cursor < $total_batches
            && $processed < $max_per_tick
            && ( time() - $tick_started ) < $time_budget_secs
        ) {
            // A newer run may supersede mid-tick.
            if ( get_option( self::SYNC_RUN_OPTION ) !== $run_id ) {
                return;
            }

            $is_final = ( $cursor === $total_batches - 1 );
            $all_urls = $is_final
                ? array_values( array_filter( array_map( 'get_permalink', $ids ) ) )
                : null;

            $result = $this->api_client->sync_batch(
                $batches[ $cursor ],
                $cursor,
                $total_batches,
                $all_urls
            );

            if ( is_array( $result ) && empty( $result['success'] ) ) {
                update_option( self::SYNC_PROGRESS_OPTION, array_merge( $progress, array(
                    'state'      => 'error',
                    'done'       => min( $cursor * $batch_size, $total ),
                    'cursor'     => $cursor,
                    'error'      => isset( $result['error'] ) ? $result['error'] : 'Sync failed',
                    'updated_at' => time(),
                ) ), false );
                delete_transient( self::SYNC_IDS_TRANSIENT );
                return;
            }

            // Batch persisted — advance the authoritative cursor immediately.
            $cursor++;

            if ( $cursor >= $total_batches ) {
                // Final batch done. last_sync_at is stamped backend-side only on
                // that batch, so it can't read "just now" before completion.
                update_option( self::SYNC_PROGRESS_OPTION, array_merge( $progress, array(
                    'state'       => 'done',
                    'done'        => $total,
                    'cursor'      => $cursor,
                    'finished_at' => time(),
                    'updated_at'  => time(),
                ) ), false );
                delete_transient( self::SYNC_IDS_TRANSIENT );
                return;
            }

            update_option( self::SYNC_PROGRESS_OPTION, array_merge( $progress, array(
                'state'      => 'running',
                'done'       => min( $cursor * $batch_size, $total ),
                'cursor'     => $cursor,
                'updated_at' => time(),
            ) ), false );

            $processed++;
        }

        // More batches remain — hand off to the next tick (same run). The
        // watchdog above backstops this if the process dies before we get here.
        if ( $cursor < $total_batches ) {
            $this->schedule_sync_chunk( $cursor, $run_id );
        }
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

        // Schedule a deferred, DEBOUNCED prune instead of a full blocking
        // re-sync. Sending the URL list (no content) lets the backend drop the
        // deleted page; deferring keeps it off the delete request; debouncing
        // (skip if one's already queued) collapses bulk deletes into one prune.
        if ( ! wp_next_scheduled( self::PRUNE_CRON_HOOK ) ) {
            wp_schedule_single_event( time() + 15, self::PRUNE_CRON_HOOK );
        }
    }

    /**
     * Deferred prune handler — sends the current URL list so the backend drops
     * pages deleted in WordPress. No content fetch, no embeddings, no full
     * re-sync.
     *
     * @since 2.0.3
     */
    public function run_prune_deleted() {
        $this->api_client->prune_deleted_pages();
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

        // 2.0.12: stamp the first-install moment for the rate-us nudge
        // time gate (7 days minimum before any prompt). Once set, NEVER
        // overwrite — deactivate / reactivate cycles must not reset the
        // clock or admins doing routine maintenance get re-nagged.
        if ( ! get_option( 'bitbot_first_install_at' ) ) {
            update_option( 'bitbot_first_install_at', time(), false );
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

        // Defer every outbound HTTP call to a single-shot wp_cron so
        // activation finishes cleanly — TasteWP-style sandboxes, shared
        // hosts with short max_execution_time, and cheap hostgators
        // routinely kill activation mid-register_site / trigger_sync,
        // leaving the plugin half-seeded and the chatbot pointed at
        // an empty corpus forever. The cron runs on the next WP tick,
        // a few seconds after activation.
        if ( ! wp_next_scheduled( self::FIRST_BOOT_CRON_HOOK ) ) {
            wp_schedule_single_event( time() + 1, self::FIRST_BOOT_CRON_HOOK );
        }

        // Hourly safety-net: retries the first sync until the site has
        // at least one indexed page. Self-unregisters on success.
        if ( ! wp_next_scheduled( self::FIRST_SYNC_CRON_HOOK ) ) {
            wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::FIRST_SYNC_CRON_HOOK );
        }

        // Flag to redirect to dashboard and auto-sync on first load
        set_transient( 'bitbot_activation_redirect', true, 60 );
    }

    /**
     * First-boot handler, fired once from a single-shot wp_cron a few
     * seconds after activation. Does all the outbound work (register
     * site, fetch plan, initial sync) outside the activation request
     * so a slow API can't brick the install.
     *
     * @since 1.7.0
     */
    public function first_boot_cron() {
        // Give this cron invocation more runway — shared hosts often
        // inherit a 30s max_execution_time from the parent wp-cron.php
        // request, which kills trigger_sync mid-POST. Raise both time +
        // memory for the duration of THIS callback, and keep processing
        // even if the HTTP client that spawned us disconnects.
        @set_time_limit( 300 );
        @ignore_user_abort( true );
        wp_raise_memory_limit( 'admin' );

        update_option( 'bitbot_first_boot_started', current_time( 'mysql' ), false );

        $this->api_client->register_site();

        $plan = $this->api_client->get_plan_status();
        if ( ! empty( $plan['success'] ) && ! empty( $plan['data'] ) ) {
            BitBot_Plan::store_cached_status( $plan['data'] );
        }

        $sync = $this->api_client->trigger_sync();

        update_option(
            'bitbot_first_boot_finished',
            current_time( 'mysql' ) . ' sync=' . ( ! empty( $sync['success'] ) ? 'ok' : 'err:' . ( $sync['error'] ?? 'unknown' ) ),
            false
        );
    }

    /**
     * Plugin deactivation
     *
     * @since 1.0.0
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook( 'bitbot_scheduled_posts' );
        wp_clear_scheduled_hook( BitBot_Plan::REFRESH_CRON_HOOK );
        wp_clear_scheduled_hook( self::FIRST_SYNC_CRON_HOOK );
        wp_clear_scheduled_hook( self::FIRST_BOOT_CRON_HOOK );
        wp_clear_scheduled_hook( self::FIRST_SYNC_RUN_HOOK );
    }


    /**
     * Hourly safety-net: if the site still has zero indexed pages, kick
     * the sync again. Self-unregisters once pagesCount > 0 so we don't
     * keep hammering sync on already-synced sites.
     *
     * @since 1.7.0
     */
    public function retry_first_sync_cron() {
        $status = $this->api_client->get_sync_status();
        if ( ! empty( $status['success'] ) ) {
            $pages_count = (int) ( $status['data']['pagesCount'] ?? 0 );
            if ( $pages_count > 0 ) {
                wp_clear_scheduled_hook( self::FIRST_SYNC_CRON_HOOK );
                return;
            }
        }
        // Still zero — re-kick non-blocking so a tight cron budget can't
        // kill us mid-POST and leave the site empty forever.
        $this->api_client->trigger_sync( true );
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

        // 2.0.6: proactive trial-expired gate. Skip enqueue entirely when
        // the cached plan snapshot reports accessMode === "expired", so
        // first-time visitors after trial ends don't even see the FAB
        // (previously the bubble rendered, the visitor clicked, the bot
        // failed, then the widget hid itself reactively — a confusing
        // mid-session vanish). Fails OPEN on missing cache so installs
        // that haven't synced their plan once stay functional; widget.js
        // still has the reactive TRIAL_EXPIRED: handler as backstop.
        if ( BitBot_Plan::is_access_expired() ) {
            return;
        }

        // 1.9.0: hide-on-pages allow/deny list. Owner provides a newline-
        // separated list of URL patterns (glob-style `*` wildcards); if any
        // matches the current request, skip widget enqueue entirely. Common
        // use case: /checkout, /cart, /login, /account where a chat overlay
        // hurts conversion.
        if ( $this->is_current_url_hidden() ) {
            return;
        }

        // Use filemtime-derived cache-bust suffix so in-place plugin
        // updates (same BITBOT_VERSION, new asset content) still get
        // picked up by visitor browsers. Without this, a parseMarkdown
        // change shipped between releases would serve the old JS until
        // the plugin version bumped — visitors see literal `[text](url)`
        // markdown even when the deployed file has the fix.
        $css_ver = BITBOT_VERSION . '.' . (int) @filemtime( BITBOT_PLUGIN_DIR . 'assets/css/widget.css' );
        $js_ver  = BITBOT_VERSION . '.' . (int) @filemtime( BITBOT_PLUGIN_DIR . 'assets/js/widget.js' );

        // 1.8.2: widget.css is loaded INSIDE the shadow root via JS (not globally enqueued).
        // Theme CSS targeting bare button/svg/input selectors can no longer reach the widget,
        // and our rules cannot leak out onto the host page. The URL is registered as a
        // (non-enqueued) handle so wp_styles()->registered keeps the cache-bust version and
        // we can ship it to JS via wp_localize_script.
        $widget_css_url = BITBOT_PLUGIN_URL . 'assets/css/widget.css?ver=' . rawurlencode( $css_ver );

        wp_enqueue_script(
            'bitbot-widget',
            BITBOT_PLUGIN_URL . 'assets/js/widget.js',
            array(),
            $js_ver,
            true
        );

        // 2.0.12: stamp the moment we successfully queue the widget for
        // a public page render. The Overview-tab "widget not appearing"
        // banner compares this against widget_last_seen_at (set when the
        // browser actually executes widget.js) to detect themes that
        // forget to call wp_footer() — server thinks it sent the script
        // but visitors never load it. Cheap option write, throttled by
        // the page request itself; we don't fire on every action.
        update_option( 'bitbot_widget_enqueued_at', time(), false );

        // Get site language code (e.g., 'en', 'hi', 'es')
        $locale = get_locale();
        $language_code = substr( $locale, 0, 2 ); // Extract first 2 chars (e.g., 'en' from 'en_US')

        // 1.9.0: custom copy bundle. Empty option strings tell the widget
        // (and backend) to use built-in defaults. Sanitized at save time
        // (sanitize_short_copy / sanitize_textarea_field).
        $custom_placeholder       = (string) get_option( 'bitbot_placeholder_text', '' );
        $custom_typing            = (string) get_option( 'bitbot_typing_indicator_text', '' );
        $custom_fallback_message  = (string) get_option( 'bitbot_fallback_message', '' );
        $custom_lead_prompt       = (string) get_option( 'bitbot_lead_capture_prompt', '' );

        wp_localize_script( 'bitbot-widget', 'bitbotConfig', array(
            'restUrl'        => esc_url_raw( rest_url( 'bitbot/v1' ) ),
            'nonce'          => wp_create_nonce( 'wp_rest' ),
            'position'       => sanitize_text_field( get_option( 'bitbot_widget_position', 'bottom-right' ) ),
            'primaryColor'   => sanitize_hex_color( get_option( 'bitbot_primary_color', '#0073aa' ) ),
            'welcomeMessage' => wp_kses_post( get_option( 'bitbot_welcome_message', __( 'Hi! How can I help you?', 'bitbot' ) ) ),
            'languageCode'   => sanitize_text_field( $language_code ),
            'cssUrl'         => esc_url_raw( $widget_css_url ),
            // 1.9.0 custom copy bundle — client-side consumers:
            'placeholderText'      => $custom_placeholder,   // input placeholder
            'typingIndicatorText'  => $custom_typing,        // replaces dots animation
            // 1.9.0 custom copy bundle — server-side consumers (8b):
            // Plumbed into bitbotConfig so the widget can forward them on
            // every Chat RPC call. Backend consumes these in the agent
            // system prompt; until 8b lands the backend just ignores them.
            'fallbackMessage'      => $custom_fallback_message,
            'leadCapturePrompt'    => $custom_lead_prompt,
            // 1.9.0 mobile-behaviour (8k). Read by widget.js on init and
            // also gated by CSS @media so even a JS failure can't leave
            // the widget visible if admin chose 'hide'.
            'mobileBehaviour'      => sanitize_text_field( get_option( 'bitbot_mobile_behaviour', 'inherit' ) ),
            // 1.9.0 custom avatar (8d). Empty string keeps default SVG.
            'avatarUrl'            => esc_url( get_option( 'bitbot_avatar_url', '' ) ),
            // 2.0.2 avatar crop transform — `{ xPercent, yPercent, zoom }`.
            // Widget.js applies `transform: translate(% %) scale(N)` to
            // the avatar img inside its overflow-hidden container so the
            // admin's framing shows on the FAB and chat header.
            'avatarTransform'      => $this->parse_avatar_transform(),
            // 1.9.0 suggested questions chips (8e). Decoded so widget.js
            // doesn't have to parse JSON; backend already validated shape.
            'suggestedQuestions'   => $this->parse_suggested_questions(),
            // 1.9.0 auto-open trigger (8g). Already validated by
            // sanitize_auto_open; widget.js applies the triggers.
            'autoOpen'             => $this->parse_auto_open(),
            // 1.9.0 proactive nudge (8f). Three modes (simple, page-aware,
            // rag). Widget.js picks the right one at render time.
            'nudge'                => $this->parse_nudge(),
            // 2.1.x chat-gate. Default form's UUID + cached schema. When
            // chat_gate_enabled is on AND the referenced form is still
            // enabled, widget.js holds the first message and renders the
            // inlined form fields. parse_chat_gate_form() returns null
            // when either condition fails — we mirror the same guard on
            // the ID so the widget can't accidentally fire on a disabled
            // form.
            'chatGateFormId'       => $this->parse_chat_gate_form() !== null
                ? (string) get_option( 'bitbot_chat_gate_form_id', '' )
                : '',
            'chatGateForm'         => $this->parse_chat_gate_form(),
            // 2.0.3: pinnedMessage retired — admin UI removed the field
            // and widget.js no longer renders it. Stored option value is
            // left in place (no destructive migration) but not surfaced.
        ) );
    }

    /**
     * Decode the pinned-message config (8h, Pro+).
     *
     * @since 1.9.0 Feature 8h
     */
    /**
     * Decode the cached chat-gate form schema. Returns null when:
     *   - the gate flag is off (bitbot_chat_gate_enabled !== '1')
     *   - no schema cached yet
     *   - the cached schema says the form itself is disabled
     *     (admin turned off the form-row slider but didn't uncheck the
     *      "Show before first chat reply" box; the gate flag and form
     *      enabled flag are independent so we have to AND them here)
     */
    private function parse_chat_gate_form() {
        if ( get_option( 'bitbot_chat_gate_enabled' ) !== '1' ) return null;
        $raw = (string) get_option( 'bitbot_chat_gate_form_schema', '' );
        if ( $raw === '' ) return null;
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return null;
        // 2.0.23: don't ship a disabled form as a chat-gate. Cached
        // schema gets refreshed whenever the admin toggles the gate
        // checkbox; if the form has been disabled since then, this is
        // our defence against a stale cache.
        if ( isset( $decoded['enabled'] ) && ! $decoded['enabled'] ) return null;
        return $decoded;
    }

    /**
     * Decode the avatar crop transform JSON to a shape widget.js can
     * apply directly. Returns null when no admin-set transform exists;
     * widget then renders the avatar with no transform (centered, 1x).
     *
     * @since 2.0.2
     */
    private function parse_avatar_transform() {
        $raw = (string) get_option( 'bitbot_avatar_transform', '' );
        if ( $raw === '' ) return null;
        $cfg = json_decode( $raw, true );
        if ( ! is_array( $cfg ) ) return null;
        $shape_in = isset( $cfg['shape'] ) ? (string) $cfg['shape'] : 'rounded';
        $shape    = in_array( $shape_in, array( 'rounded', 'pill' ), true ) ? $shape_in : 'rounded';
        // 2.0.2 (refactor): ship source-pixel crop rect + source dims.
        // Widget.js computes background-size/position at render time.
        if ( ! isset( $cfg['cropW'], $cfg['cropH'], $cfg['sourceW'], $cfg['sourceH'] ) ) {
            return null;
        }
        return array(
            'shape'   => $shape,
            'cropX'   => (int) ( $cfg['cropX'] ?? 0 ),
            'cropY'   => (int) ( $cfg['cropY'] ?? 0 ),
            'cropW'   => (int) $cfg['cropW'],
            'cropH'   => (int) $cfg['cropH'],
            'sourceW' => (int) $cfg['sourceW'],
            'sourceH' => (int) $cfg['sourceH'],
        );
    }

    private function parse_pinned_message() {
        $raw = trim( (string) get_option( 'bitbot_pinned_message', '' ) );
        if ( $raw === '' ) return null;

        // 2.0.2: the React admin's pinned-message field is a plain
        // textarea — it stores the raw text, not a JSON envelope. The
        // legacy WP-admin UI saved the structured shape with CTA. Accept
        // both: JSON object → use as-is; anything else → treat the whole
        // string as `text` with no CTA.
        $cfg = json_decode( $raw, true );
        if ( is_array( $cfg ) && ! empty( $cfg['text'] ) ) {
            return array(
                'text'     => (string) $cfg['text'],
                'ctaLabel' => (string) ( $cfg['ctaLabel'] ?? '' ),
                'ctaUrl'   => (string) ( $cfg['ctaUrl'] ?? '' ),
            );
        }
        return array(
            'text'     => $raw,
            'ctaLabel' => '',
            'ctaUrl'   => '',
        );
    }

    /**
     * Decode the JSON-stored proactive nudge option.
     *
     * @since 1.9.0 Feature 8f
     */
    private function parse_nudge() {
        $raw = (string) get_option( 'bitbot_nudge', '' );
        if ( $raw === '' ) {
            return null;
        }
        $cfg = json_decode( $raw, true );
        if ( ! is_array( $cfg ) || empty( $cfg['enabled'] ) ) {
            return null;
        }
        // 2.0.2: React admin saves the simple-mode message as `message`;
        // the legacy WP-admin UI used `simpleText`. Accept either so both
        // surfaces round-trip cleanly into widget.js's `nudge.simpleText`.
        return array(
            'enabled'      => true,
            'mode'         => (string) ( $cfg['mode'] ?? 'simple' ),
            'delaySeconds' => (int)    ( $cfg['delaySeconds'] ?? 8 ),
            'simpleText'   => (string) ( $cfg['simpleText'] ?? $cfg['message'] ?? '' ),
            'pageRules'    => (string) ( $cfg['pageRules'] ?? '' ),
        );
    }

    /**
     * Decode the JSON-stored auto-open option to a shape widget.js can
     * use directly. Empty config → returns null (widget.js short-circuits).
     *
     * @since 1.9.0 Feature 8g
     */
    private function parse_auto_open() {
        $raw = (string) get_option( 'bitbot_auto_open', '' );
        if ( $raw === '' ) {
            return null;
        }
        $cfg = json_decode( $raw, true );
        if ( ! is_array( $cfg ) ) {
            return null;
        }
        // 2.0.2: honour the React UI's `enabled` toggle. If the admin
        // saves an explicit `enabled: false` we return null so widget.js
        // skips the whole feature. Configs without an `enabled` key
        // (legacy / WP-admin UI) keep firing — that matches old behaviour.
        if ( array_key_exists( 'enabled', $cfg ) && empty( $cfg['enabled'] ) ) {
            return null;
        }
        // 2.0.2: React admin saves the URL match field as `pagesPattern`;
        // the legacy WP-admin UI used `urlPatterns`. Accept either so
        // both surfaces feed widget.js's `autoOpen.urlPatterns`.
        return array(
            'urlPatterns'   => (string) ( $cfg['urlPatterns'] ?? $cfg['pagesPattern'] ?? '' ),
            'delaySeconds'  => (int)    ( $cfg['delaySeconds'] ?? 0 ),
            'scrollPercent' => (int)    ( $cfg['scrollPercent'] ?? 0 ),
            'exitIntent'    => (bool)   ( $cfg['exitIntent'] ?? false ),
        );
    }

    /**
     * Decode the JSON-stored suggested-questions option into an array of
     * { label, message } objects. Returns empty array on missing /
     * malformed data so widget.js can always treat it as a list.
     *
     * @since 1.9.0 Feature 8e
     */
    private function parse_suggested_questions() {
        $raw = (string) get_option( 'bitbot_suggested_questions', '' );
        if ( $raw === '' ) {
            return array();
        }
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return array();
        }
        $clean = array();
        foreach ( $decoded as $item ) {
            if ( ! is_array( $item ) ) continue;
            $label   = isset( $item['label'] ) ? sanitize_text_field( (string) $item['label'] ) : '';
            $message = isset( $item['message'] ) ? sanitize_text_field( (string) $item['message'] ) : '';
            if ( $label === '' && $message === '' ) continue;
            $clean[] = array(
                'label'   => $label !== '' ? $label : $message,
                'message' => $message !== '' ? $message : $label,
            );
        }
        return $clean;
    }

    /**
     * 1.9.0: check whether the current request matches the admin's
     * "hide widget on these pages" list. Returns true if a match is
     * found — the caller short-circuits widget enqueue.
     *
     * Patterns are newline-separated. Each line may use `*` wildcards
     * (translated to `.*` in regex). Lines starting with `#` are
     * comments. Match is case-insensitive and runs against:
     *   - the request path (e.g. `/checkout/`)
     *   - the full request URL (e.g. `https://site.com/checkout/?x=1`)
     *
     * Examples that all hide the widget on /checkout:
     *   /checkout
     *   /checkout/
     *   /checkout*
     *   *checkout*
     *
     * @since 1.9.0
     * @return bool True if current URL matches any pattern.
     */
    public function is_current_url_hidden() {
        $raw_patterns = (string) get_option( 'bitbot_hide_on_pages', '' );
        if ( $raw_patterns === '' ) {
            return false;
        }

        // Skip in admin / REST / cron / login contexts — widget never
        // renders there anyway, but the regex matching is wasted cycles.
        if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
            return false;
        }

        $request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
        $request_path = parse_url( $request_uri, PHP_URL_PATH ) ?: '/';
        $full_url     = home_url( $request_uri );

        $lines = preg_split( '/\r\n|\r|\n/', $raw_patterns );
        foreach ( $lines as $line ) {
            $pattern = trim( $line );
            if ( $pattern === '' || $pattern[0] === '#' ) {
                continue;
            }

            // Translate glob → regex. Escape everything, then re-expand `*`.
            $regex = '#^' . str_replace( '\\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';

            if ( preg_match( $regex, $request_path ) || preg_match( $regex, $full_url ) ) {
                return true;
            }

            // Substring match fallback for "user just typed `checkout`" —
            // without anchors, an unwrapped word matches anywhere.
            if ( strpos( $pattern, '*' ) === false ) {
                if ( stripos( $request_path, $pattern ) !== false ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Enqueue admin assets
     *
     * @since 1.0.0
     * @param string $hook Current admin page hook.
     */
    /**
     * Mark the admin React entry as an ES module so Vite's cross-chunk
     * imports resolve. Only flips the `bitbot-admin-react` handle; all
     * other plugin scripts (legacy admin.js, widget.js, etc.) stay as
     * classic scripts.
     */
    public function mark_admin_react_as_module( $tag, $handle, $src ) {
        if ( 'bitbot-admin-react' !== $handle ) {
            return $tag;
        }
        if ( false !== strpos( $tag, ' type=' ) ) {
            return preg_replace( '/\stype=(["\'])[^"\']*\1/', ' type="module"', $tag );
        }
        return str_replace( '<script ', '<script type="module" ', $tag );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'bitbot' ) === false ) {
            return;
        }

        // Same cache-bust strategy as the widget: use the asset's filemtime
        // suffix so in-place plugin updates (same BITBOT_VERSION, new asset
        // content) actually reach the admin's browser. Without this, the
        // browser holds the previous admin.js until BITBOT_VERSION bumps —
        // which made the post-cap notice fix invisible during dev cycles.
        $admin_css_ver = BITBOT_VERSION . '.' . (int) @filemtime( BITBOT_PLUGIN_DIR . 'assets/css/admin.css' );
        $admin_js_ver  = BITBOT_VERSION . '.' . (int) @filemtime( BITBOT_PLUGIN_DIR . 'assets/js/admin.js' );

        wp_enqueue_style(
            'bitbot-admin',
            BITBOT_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            $admin_css_ver
        );

        wp_enqueue_script(
            'bitbot-admin',
            BITBOT_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery', 'jquery-ui-sortable' ),
            $admin_js_ver,
            true
        );

        wp_localize_script( 'bitbot-admin', 'bitbotAdmin', array(
            'ajaxUrl'    => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
            'nonce'      => wp_create_nonce( 'bitbot_admin_nonce' ),
            // Used by the inline cap/error notice on the Content Generator
            // page so the "Upgrade to Pro" CTA links to the right place.
            'upgradeUrl' => esc_url_raw( admin_url( 'admin.php?page=bitbot-upgrade' ) ),
        ) );

        // Heartbeat so the agent knows the site owner is online (powers
        // live-handover on Agent tier). Small script, fires every 2 min
        // while any BitBot admin page is open.
        wp_enqueue_script(
            'bitbot-heartbeat',
            BITBOT_PLUGIN_URL . 'assets/js/heartbeat.js',
            array( 'jquery' ),
            BITBOT_VERSION,
            true
        );
        wp_localize_script( 'bitbot-heartbeat', 'bitbotHeartbeat', array(
            'ajaxUrl' => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
            'nonce'   => wp_create_nonce( 'bitbot_admin_nonce' ),
        ) );

        // Feature 9 hard cutover (v2.0.0): the React admin SPA is THE
        // admin surface — no opt-in flag, no legacy fallback. Only enqueue
        // on the BitBot admin page itself, not every WP admin screen.
        $hook = isset( $hook ) ? $hook : '';
        $on_bitbot_page = ( $hook === 'toplevel_page_bitbot' )
            || ( isset( $_GET['page'] ) && sanitize_key( $_GET['page'] ) === 'bitbot' );

        if ( $on_bitbot_page ) {
            $react_css_path = BITBOT_PLUGIN_DIR . 'assets/admin/admin.css';
            $react_js_path  = BITBOT_PLUGIN_DIR . 'assets/admin/admin.js';

            if ( file_exists( $react_js_path ) && file_exists( $react_css_path ) ) {
                $react_css_ver = BITBOT_VERSION . '.' . (int) @filemtime( $react_css_path );
                $react_js_ver  = BITBOT_VERSION . '.' . (int) @filemtime( $react_js_path );

                wp_enqueue_style(
                    'bitbot-admin-react',
                    BITBOT_PLUGIN_URL . 'assets/admin/admin.css',
                    array(),
                    $react_css_ver
                );
                wp_enqueue_script(
                    'bitbot-admin-react',
                    BITBOT_PLUGIN_URL . 'assets/admin/admin.js',
                    array(), // No jQuery / no other deps — React is self-contained
                    $react_js_ver,
                    true
                );

                // Enqueue the WP Media Library JS so the React admin can
                // open the media uploader for avatar selection. Adds
                // window.wp.media to the page (a thin Backbone-backed
                // modal). Cheap — most admins already have this loaded
                // from other pages.
                wp_enqueue_media();

                // Stripe checkout return: when the browser lands here with
                // `?subscription=success` the webhook has likely already
                // updated the plan on the backend, but our local plan cache
                // is throttled (60s). Without busting, the SPA boots with
                // the stale 'free' plan and the ProGate stays locked until
                // the admin hits refresh. Bust now so get_current_plan()
                // below refetches fresh and the SPA sees the new tier on
                // first paint. Also fires on `subscription=cancelled` so
                // a checkout abort doesn't leave a phantom paid state.
                if ( isset( $_GET['subscription'] ) && class_exists( 'BitBot_Plan' ) ) {
                    BitBot_Plan::bust_cache();
                }

                // Resolve current plan once so the React app gets the right
                // PlanCard tier badge on first paint (no flash of Free).
                $current_plan = ( $this->admin && method_exists( $this->admin, 'get_current_plan' ) )
                    ? $this->admin->get_current_plan()
                    : 'free';

                // PostHog telemetry — key is fetched from the BitBot
                // backend (RegisterSite response + GetTelemetryConfig RPC)
                // and stored in wp_options. Source-of-truth lives on
                // Vercel env vars (POSTHOG_KEY + POSTHOG_HOST) so we
                // never ship the key in the public plugin source.
                //
                // Customers can override either:
                //   define( 'BITBOT_POSTHOG_KEY', 'phc_yourKey' ); — use your own project
                //   define( 'BITBOT_POSTHOG_KEY', '' );           — disable telemetry entirely
                // OR via WP option `bitbot_posthog_key` (same precedence).
                if ( defined( 'BITBOT_POSTHOG_KEY' ) ) {
                    $posthog_key = BITBOT_POSTHOG_KEY;
                } else {
                    $posthog_key = (string) get_option( 'bitbot_posthog_key', '' );
                    // Backfill for installs that registered BEFORE the
                    // backend started returning the key. One-off RPC hit
                    // — once the option is set, this branch is skipped.
                    // Throttled to once-per-day via transient so a
                    // mis-configured server doesn't hammer the API.
                    if ( $posthog_key === '' && ! get_transient( 'bitbot_posthog_backfill_tried' ) ) {
                        set_transient( 'bitbot_posthog_backfill_tried', 1, DAY_IN_SECONDS );
                        $cfg = $this->api_client->fetch_telemetry_config();
                        if ( ! empty( $cfg['posthogKey'] ) ) {
                            $posthog_key = (string) $cfg['posthogKey'];
                        }
                    }
                }
                $posthog_host = defined( 'BITBOT_POSTHOG_HOST' )
                    ? BITBOT_POSTHOG_HOST
                    : (string) get_option( 'bitbot_posthog_host', 'https://us.i.posthog.com' );

                wp_localize_script( 'bitbot-admin-react', 'bitbotAdminConfig', array(
                    'apiKey'        => (string) get_option( 'bitbot_api_key', '' ),
                    'apiUrl'        => esc_url_raw( BITBOT_API_URL . '/rpc' ),
                    'restUrl'       => esc_url_raw( rest_url( 'bitbot/v1' ) ),
                    'nonce'         => wp_create_nonce( 'wp_rest' ),
                    'ajaxUrl'       => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
                    'ajaxNonce'     => wp_create_nonce( 'bitbot_admin_nonce' ),
                    'upgradeUrl'    => esc_url_raw( admin_url( 'admin.php?page=bitbot' ) ),
                    'pluginVersion' => BITBOT_VERSION,
                    'siteUrl'       => esc_url_raw( home_url() ),
                    // WP admin email — used as the default reply-from in the
                    // AI Support Inbox SMTP connect (always set, unlike the
                    // optional notification email).
                    'adminEmail'    => sanitize_email( get_option( 'admin_email', '' ) ),
                    'plan'          => sanitize_text_field( $current_plan ),
                    'posthogKey'    => sanitize_text_field( $posthog_key ),
                    'posthogHost'   => esc_url_raw( $posthog_host ),
                    // 2.0.12: widget-health flags consumed by Overview's
                    // WidgetStatusBanner. Server-side state we can read
                    // without a backend round-trip:
                    //   widgetEnabled  — admin toggled it off in Chatbot
                    //   widgetAccessExpired — trial / subscription ended
                    //   widgetEnqueuedAt — last time we sent the script
                    //   widgetLastSeenAt — last time a visitor browser
                    //                      actually executed widget.js
                    // Gap between the last two beyond 24h = theme not
                    // calling wp_footer(), or a cache plugin stripping
                    // our script. Banner copy explains the [bitbot]
                    // shortcode escape hatch.
                    'widgetEnabled'        => (bool) get_option( 'bitbot_enabled', true ),
                    'widgetAccessExpired'  => (bool) BitBot_Plan::is_access_expired(),
                    'widgetEnqueuedAt'     => (int) get_option( 'bitbot_widget_enqueued_at', 0 ),
                    'widgetLastSeenAt'     => (int) get_option( 'bitbot_widget_last_seen_at', 0 ),
                ) );
            }
        }
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

        // Live-mode update poll (public — used by the widget while a
        // conversation is in live handover mode).
        register_rest_route( 'bitbot/v1', '/live-updates', array(
            'methods'             => 'POST',
            'callback'            => array( $this->widget, 'handle_live_updates_request' ),
            'permission_callback' => '__return_true',
        ) );

        // Visitor presence: heartbeat while widget is open, beforeunload
        // beacon on tab close. Both are public — the backend scopes them
        // to (site, sessionId) after looking up the API key.
        register_rest_route( 'bitbot/v1', '/visitor-heartbeat', array(
            'methods'             => 'POST',
            'callback'            => array( $this->widget, 'handle_visitor_heartbeat' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'bitbot/v1', '/visitor-left', array(
            'methods'             => 'POST',
            'callback'            => array( $this->widget, 'handle_visitor_left' ),
            'permission_callback' => '__return_true',
        ) );

        // Citation click beacon (public). Used by the widget when the
        // visitor opens a cited page link inside a bot reply — drives
        // the Overview Top Cited Pages CTR.
        register_rest_route( 'bitbot/v1', '/citation-click', array(
            'methods'             => 'POST',
            'callback'            => array( $this->widget, 'handle_citation_click' ),
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

        // Feature 9 (v2.0.0): admin settings endpoints used by the React
        // admin SPA. GET returns all 23 Feature 8 option keys as JSON;
        // POST accepts a partial JSON body, calls update_option() per key
        // so existing registered sanitize callbacks fire (including the
        // UpdateInstructions / UpdateGuardrails backend RPC side effects).
        // Auth: standard WP REST cookie auth + X-WP-Nonce header.
        register_rest_route( 'bitbot/v1', '/admin-settings', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this->admin, 'handle_get_admin_settings' ),
                'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this->admin, 'handle_save_admin_settings' ),
                'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            ),
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
        // sessionId carries the visitor's chat session — used by the
        // chat-gate flow so the resulting lead gets linked to the
        // conversation row that subsequent chat messages also attach
        // to. Empty for shortcode forms with no chat context.
        $session_id = isset( $params['sessionId'] ) ? sanitize_text_field( $params['sessionId'] ) : '';

        // Custom-field answers — a { fieldName: value } map for everything
        // beyond name/email/phone/message. Sanitize each value; skip the
        // four standard keys (they have their own params above).
        $answers = array();
        if ( isset( $params['answers'] ) && is_array( $params['answers'] ) ) {
            $standard = array( 'name', 'email', 'phone', 'message' );
            foreach ( $params['answers'] as $field_key => $field_val ) {
                $key = sanitize_text_field( (string) $field_key );
                if ( '' === $key || in_array( $key, $standard, true ) ) {
                    continue;
                }
                $answers[ $key ] = sanitize_textarea_field( (string) $field_val );
            }
        }

        // 2.0.20: per-field validation so the widget can highlight the
        // offending input instead of just resetting the submit button.
        // sanitize_email() strips bad chars but doesn't reject loose
        // formats like "a@a" — is_email() does. Returning `field` lets
        // the client map the error to the right input.
        if ( empty( $name ) ) {
            return new WP_REST_Response( array( 'error' => 'Please enter your name.', 'field' => 'name' ), 400 );
        }
        if ( empty( $email ) || ! is_email( $email ) ) {
            return new WP_REST_Response( array( 'error' => 'Please enter a valid email address.', 'field' => 'email' ), 400 );
        }

        // Get visitor IP for geolocation
        $ip_address = $request->get_header( 'X-Forwarded-For' );
        if ( $ip_address ) {
            $ip_address = explode( ',', $ip_address )[0]; // First IP in chain
        } else {
            $ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
        }
        $ip_address = sanitize_text_field( trim( $ip_address ) );

        $result = $this->api_client->submit_form( $form_id, $name, $email, $phone, $message, $ip_address, $session_id, $answers );

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
        // 2.0.4: per-page instance counter so multiple shortcode invocations
        // on the same page get unique DOM IDs (was: hardcoded ids collided →
        // duplicate submit listeners → duplicate submissions).
        static $instance = 0;
        $instance++;
        $suffix = '-' . $instance;

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

        // 2.0.143: unique IDs so the submit handler can bind via getElementById
        // instead of document.currentScript + parentElement. The old approach
        // broke whenever wpautop / theme filters mangled the DOM around our
        // markup (script ended up in a sibling <p>, .parentElement no longer
        // contained the form, listener never attached, browser fell back to
        // a GET-to-current-page submit).
        $wrap_id   = 'bitbot-lead-form-wrap' . $suffix;
        $form_dom_id = 'bitbot-lead-form-el' . $suffix;

        ob_start();
        ?>
        <div id="<?php echo esc_attr( $wrap_id ); ?>" class="bitbot-lead-form" style="max-width: 500px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
            <!-- onsubmit="return false" is a defensive backstop: even if our
                 JS fails to load/run (CSP, theme strip, etc.), the form will
                 not navigate to a broken GET URL with field values in the
                 query string. -->
            <form id="<?php echo esc_attr( $form_dom_id ); ?>" class="bitbot-lead-form-el" novalidate onsubmit="return false;">
                <?php foreach ( $fields as $field ) :
                    $name = esc_attr( $field['name'] );
                    $field_id = 'bitbot-field-' . $name . $suffix;
                    $label = esc_html( $field['label'] );
                    $type = esc_attr( $field['type'] );
                    $required = ! empty( $field['required'] );
                    $placeholder = isset( $field['placeholder'] ) ? esc_attr( $field['placeholder'] ) : '';
                    $options = isset( $field['options'] ) ? $field['options'] : array();
                ?>
                <div class="bitbot-form-field" style="margin-bottom: 16px;">
                    <label for="<?php echo $field_id; ?>" style="display: block; font-weight: 500; margin-bottom: 4px; font-size: 14px;">
                        <?php echo $label; ?>
                        <?php if ( $required ) : ?><span style="color: #dc3545;">*</span><?php endif; ?>
                    </label>
                    <?php if ( $type === 'textarea' ) : ?>
                        <textarea id="<?php echo $field_id; ?>" name="<?php echo $name; ?>" placeholder="<?php echo $placeholder; ?>" rows="4" <?php echo $required ? 'required' : ''; ?>
                            style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; resize: vertical;"></textarea>
                    <?php elseif ( $type === 'select' ) : ?>
                        <select id="<?php echo $field_id; ?>" name="<?php echo $name; ?>" <?php echo $required ? 'required' : ''; ?>
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
                            <input type="checkbox" id="<?php echo $field_id; ?>" name="<?php echo $name; ?>" value="1" <?php echo $required ? 'required' : ''; ?>>
                            <?php echo $placeholder ? esc_html( $placeholder ) : $label; ?>
                        </label>
                    <?php else :
                        $input_type = ( $type === 'email' ) ? 'email' : ( ( $type === 'phone' ) ? 'tel' : 'text' );
                    ?>
                        <input type="<?php echo $input_type; ?>" id="<?php echo $field_id; ?>" name="<?php echo $name; ?>" placeholder="<?php echo $placeholder; ?>" <?php echo $required ? 'required' : ''; ?>
                            style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px;">
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <div class="bitbot-form-message" style="display: none; padding: 10px; border-radius: 6px; margin-bottom: 12px; font-size: 14px;"></div>

                <button type="submit" class="bitbot-form-submit"
                    style="background: <?php echo $primary_color; ?>; color: #fff; border: none; padding: 12px 24px; border-radius: 6px; font-size: 15px; cursor: pointer; width: 100%; font-weight: 500;">
                    <?php esc_html_e( 'Submit', 'bitbot' ); ?>
                </button>
            </form>

            <script>
            (function() {
                /* 2.0.143: bind via getElementById on unique wrap+form IDs.
                   Old document.currentScript approach broke when wpautop or
                   the theme moved the script tag outside the form wrapper. */
                function init() {
                    var wrap = document.getElementById('<?php echo esc_js( $wrap_id ); ?>');
                    var form = document.getElementById('<?php echo esc_js( $form_dom_id ); ?>');
                    if (!wrap || !form) return;
                    bind(wrap, form);
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', init);
                } else {
                    init();
                }
                function bind(wrap, form) {

                // 2.0.20: per-field error UI for shortcode forms. Same
                // pattern the in-widget chat-gate uses — red border on
                // the offending input, inline message under it, cleared
                // on next input event. Keeps the top-level banner for
                // network errors and unknown-field rejections.
                var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
                function fieldEl(name) {
                    // Prefer name= for compatibility with radio groups
                    return form.querySelector('[name="' + name + '"]');
                }
                function rowEl(name) {
                    var el = fieldEl(name);
                    return el ? el.closest('.bitbot-form-field') || el.parentElement : null;
                }
                function showFieldError(name, message) {
                    var row = rowEl(name);
                    var el = fieldEl(name);
                    if (!row) return;
                    row.classList.add('bitbot-form-field-error');
                    if (el ? el.style : false) el.style.borderColor = '#c43d3d';
                    var err = row.querySelector('.bitbot-form-field-msg');
                    if (!err) {
                        err = document.createElement('div');
                        err.className = 'bitbot-form-field-msg';
                        err.style.color = '#c43d3d';
                        err.style.fontSize = '12px';
                        err.style.marginTop = '4px';
                        row.appendChild(err);
                    }
                    err.textContent = message;
                }
                function clearFieldError(name) {
                    var row = rowEl(name);
                    var el = fieldEl(name);
                    if (!row) return;
                    row.classList.remove('bitbot-form-field-error');
                    if (el ? el.style : false) el.style.borderColor = '#ccc';
                    var err = row.querySelector('.bitbot-form-field-msg');
                    if (err) err.remove();
                }
                function clearAllFieldErrors() {
                    form.querySelectorAll('.bitbot-form-field-error').forEach(function(r) {
                        r.classList.remove('bitbot-form-field-error');
                        var input = r.querySelector('input, textarea, select');
                        if (input ? input.style : false) input.style.borderColor = '#ccc';
                        var m = r.querySelector('.bitbot-form-field-msg');
                        if (m) m.remove();
                    });
                }
                form.querySelectorAll('input, textarea, select').forEach(function(el) {
                    el.addEventListener('input', function() {
                        if (el.name) clearFieldError(el.name);
                    });
                });

                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var btn = wrap.querySelector('.bitbot-form-submit');
                    var msg = wrap.querySelector('.bitbot-form-message');
                    msg.style.display = 'none';
                    clearAllFieldErrors();

                    var formData = new FormData(form);
                    var data = { formId: '<?php echo esc_js( $form_id ); ?>' };
                    // Standard fields stay top-level (the backend has dedicated
                    // columns for them); every other field is a custom answer
                    // and goes under `answers` keyed by its field name.
                    var answers = {};
                    var STANDARD = { name: 1, email: 1, phone: 1, message: 1 };
                    formData.forEach(function(value, key) {
                        if (STANDARD[key]) { data[key] = value; }
                        else { answers[key] = value; }
                    });
                    data.answers = answers;

                    // Client-side validation BEFORE we burn a round-trip.
                    // Server still re-checks via is_email() so direct API
                    // hits can't bypass.
                    if (!data.name || !String(data.name).trim()) {
                        showFieldError('name', 'Please enter your name.');
                        return;
                    }
                    if (!data.email || !EMAIL_RE.test(String(data.email).trim())) {
                        showFieldError('email', 'Please enter a valid email address.');
                        return;
                    }

                    btn.disabled = true;
                    btn.textContent = 'Submitting...';

                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '<?php echo esc_url( rest_url( 'bitbot/v1/form-submit' ) ); ?>');
                    xhr.setRequestHeader('Content-Type', 'application/json');
                    xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce( 'wp_rest' ); ?>');
                    xhr.onload = function() {
                        btn.disabled = false;
                        btn.textContent = 'Submit';
                        // wptexturize on the_content converts '&&' to HTML
                        // entities, breaking JS parse. Use a guarded ternary
                        // form that avoids the operator entirely.
                        if (xhr.status >= 200 ? xhr.status < 300 : false) {
                            msg.style.display = 'block';
                            msg.style.background = '#edfaef';
                            msg.style.color = '#00a32a';
                            msg.textContent = 'Thank you! We\'ll be in touch soon.';
                            form.reset();
                        } else {
                            var errMsg = 'Something went wrong. Please try again.';
                            var fieldName = null;
                            try {
                                var resp = JSON.parse(xhr.responseText);
                                if (resp.error) errMsg = resp.error;
                                if (resp.field) fieldName = resp.field;
                            } catch(e) {}
                            // If server told us which field — highlight it
                            // and skip the top banner. Otherwise fall back
                            // to the banner so the visitor sees SOMETHING.
                            if (fieldName ? !!fieldEl(fieldName) : false) {
                                showFieldError(fieldName, errMsg);
                            } else {
                                msg.style.display = 'block';
                                msg.style.background = '#fcf0f1';
                                msg.style.color = '#d63638';
                                msg.textContent = errMsg;
                            }
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
                } // end bind()
            })();
            </script>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the public form-host iframe page when ?bitbot_form_embed=<id>
     * is present. Standalone HTML (no WP theme, no admin chrome) with
     * permissive frame-ancestors so any site can embed it.
     *
     * Form submissions stay same-origin (the iframe is hosted on the WP
     * site itself) so the existing `/wp-json/bitbot/v1/form-submit` nonce
     * flow works without CORS gymnastics.
     *
     * @since 2.0.4
     */
    public function maybe_render_form_embed() {
        if ( empty( $_GET['bitbot_form_embed'] ) ) {
            return;
        }
        $form_id = sanitize_text_field( wp_unslash( $_GET['bitbot_form_embed'] ) );

        // Frame-ancestors * — any origin can embed. If a site operator
        // wants to lock this down, they can use a security plugin to
        // override the header.
        header( 'Content-Type: text/html; charset=utf-8' );
        header( 'Content-Security-Policy: frame-ancestors *' );
        header( 'X-Robots-Tag: noindex, nofollow' );
        // Defensive: WP sometimes adds X-Frame-Options: SAMEORIGIN via
        // send_frame_options_header. Strip it so the iframe works
        // cross-origin.
        if ( function_exists( 'header_remove' ) ) {
            header_remove( 'X-Frame-Options' );
        }
        remove_action( 'login_init', 'send_frame_options_header' );
        remove_action( 'admin_init', 'send_frame_options_header' );

        $form_html = $this->render_form_shortcode( array( 'id' => $form_id ) );
        $primary_color = sanitize_hex_color( get_option( 'bitbot_primary_color', '#0073aa' ) );
        if ( empty( $primary_color ) ) {
            $primary_color = '#0073aa';
        }

        ?><!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php esc_html_e( 'Contact form', 'bitbot' ); ?></title>
    <meta name="robots" content="noindex, nofollow">
    <style>
        html, body {
            margin: 0;
            padding: 0;
            background: transparent;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #1a1a1a;
        }
        body { padding: 16px; }
        .bitbot-embed-wrap { max-width: 500px; margin: 0 auto; }
    </style>
</head>
<body>
    <div class="bitbot-embed-wrap">
        <?php
        // render_form_shortcode handles the full form markup + submit JS.
        echo $form_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
    </div>
</body>
</html>
        <?php
        exit;
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
