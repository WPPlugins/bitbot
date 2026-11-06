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
 * Description:       AI text chat, voice calls, content tools and a leads inbox for your WordPress site.
 * Version:           2.2.3
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
 * This plugin connects to the youami.ai service (https://youami.ai) for chat,
 * content generation and Agent features. The service uses these providers:
 * Anthropic Claude (AI replies and content), Cohere (embeddings), Unsplash
 * (images), Tavily (web research), Habitat (voice calls on paid plans),
 * Forward Email and Resend (email) and Stripe (billing). Site details, public
 * content, visitor messages and form submissions are sent as needed to provide
 * these features. Full list with data sent and provider terms and privacy
 * links: see "External services" in readme.txt.
 * By using this plugin, you agree to the Terms of Service (https://youami.ai/terms)
 * and Privacy Policy (https://youami.ai/privacy).
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'BITBOT_VERSION', '2.2.3' );
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
require_once BITBOT_PLUGIN_DIR . 'includes/class-whatsapp.php';
require_once BITBOT_PLUGIN_DIR . 'includes/class-languages.php';
require_once BITBOT_PLUGIN_DIR . 'includes/class-widget-copy.php';
require_once BITBOT_PLUGIN_DIR . 'includes/class-form-markup.php';
require_once BITBOT_PLUGIN_DIR . 'includes/class-page-title.php';
require_once BITBOT_PLUGIN_DIR . 'includes/class-nav-location.php';
require_once BITBOT_PLUGIN_DIR . 'includes/class-page-images.php';
require_once BITBOT_PLUGIN_DIR . 'includes/class-read-revision.php';
require_once BITBOT_PLUGIN_DIR . 'includes/class-admin.php';
require_once BITBOT_PLUGIN_DIR . 'includes/class-widget.php';
// Text edits inside Elementor documents, through Elementor's own save. Loaded
// unconditionally; the class checks for Elementor per call rather than at boot,
// because a plugin can be deactivated between a read and the write that follows.
require_once BITBOT_PLUGIN_DIR . 'includes/class-elementor-adapter.php';

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

    /** Emit the lead-form baseline stylesheet once per page, however many
     *  [bitbot_form] shortcodes render. */
    private static $form_css_printed = false;

    /** Shown (shortcode) and returned (submit) when the owner turned "Accept submissions" off. */
    const FORM_CLOSED_MESSAGE = 'This form is closed.';

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
        // Plain permalinks, and hosts that answer 404 on /wp-json/ (arkatech-co.ir,
        // 2026-11-06), reach the REST API as index.php?rest_route=/... instead:
        // the admin handlers were not built for those and every admin route
        // answered 500 rest_invalid_handler, so the Chatbot tab stayed blank.
        if ( isset( $_GET['rest_route'] ) && '' !== $_GET['rest_route'] ) return true;
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

        // Fatal-error capture: the plugin runs inside EVERY request, including
        // the ones that crash — so at shutdown, if the request died on a fatal,
        // record the REAL error (message/file/line/url/time). The agent reads
        // it via /last-fatal instead of guessing "API is down" / "contact your
        // host" from an opaque critical-error page. Tiny, defensive, autoload
        // off; must never itself break a request.
        register_shutdown_function( function () {
            $e = error_get_last();
            if ( ! $e || ! in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
                return;
            }
            if ( ! function_exists( 'update_option' ) ) {
                return;
            }
            update_option( 'bitbot_last_fatal', array(
                'message' => (string) $e['message'],
                'file'    => (string) $e['file'],
                'line'    => (int) $e['line'],
                'url'     => isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '',
                'time'    => gmdate( 'c' ),
            ), false );
        } );

        // CONTENT PARSER-BOMB GUARD, at WordPress's OWN save boundary — every
        // writer passes through here: the agent, the editor, importers, other
        // plugins. fireneedle post-mortem: a post grew server-side to 153MB of
        // block markup nested 635k deep (something re-saved the post's own
        // wrapped output in a loop); each save was individually "valid" and
        // WordPress persisted a body that fataled the parser on every render.
        // Caps sit ~10x beyond any real content (heavy designed pages run
        // 100-300KB, a few hundred blocks, depth ~12). Cheapest check FIRST:
        // byte length refuses oversized content BEFORE any parsing, so the
        // guard itself can never OOM. Refusals are recorded like fatals, so
        // the next incident carries evidence instead of a mystery.
        add_filter( 'wp_insert_post_empty_content', array( $this, 'guard_content_bomb' ), 10, 2 );

        // PROVENANCE. BitBot may restructure what it created; everything that
        // was on the site before it arrived is the owner's, and its LAYOUT is
        // off limits (content edits stay allowed). Enforcing that needs a
        // durable answer to "did we make this?", and the honest place to keep
        // it is ON the item: post meta survives DB divergence, re-syncs, and
        // exports. Stamped at ONE choke point rather than at each of the nine
        // wp_insert_post call sites, because the tenth writer someone adds
        // later would silently inherit owner-content privileges.
        add_filter( 'rest_pre_dispatch', array( $this, 'mark_bitbot_request' ), 10, 3 );
        add_action( 'wp_insert_post', array( $this, 'stamp_bitbot_provenance' ), 10, 3 );

        // Register the same guard on REST insert/update for the post types the
        // site edits through the API, so API writers (the agent, the block
        // editor) get the PRECISE refusal instead of a generic "empty content".
        add_action( 'init', function () {
            foreach ( array( 'post', 'page', 'wp_template', 'wp_template_part', 'wp_block' ) as $pt ) {
                add_filter( "rest_pre_insert_{$pt}", array( $this, 'guard_content_bomb_rest' ), 10, 2 );
            }
        } );

        // The Templates drawer's shadow post type (bb_template) is no longer
        // registered (2.1.338). Existing bb_template posts are left in the
        // database untouched: nothing reads or deletes them.

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
        add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'enqueue_admin_bridge' ) );

        // BitBot renders the SEO head ITSELF when no SEO plugin is actively
        // emitting — the agent's titles/descriptions must be visible to
        // Google without depending on a third-party suite (a dormant Rank
        // Math stored every field while emitting nothing). Defers entirely
        // the moment a real SEO plugin emits.
        add_action( 'wp_head', array( $this, 'render_seo_head' ), 1 );
        add_filter( 'pre_get_document_title', array( $this, 'filter_seo_document_title' ), 20 );

        // Canvas pages: output the theme-chrome hide CSS from wp_head, NOT from
        // the page content — content <style> is stripped by KSES on multisite
        // (admins lack unfiltered_html there), which would leak the CSS as text.
        add_action( 'wp_head', array( $this, 'render_canvas_hide_css' ), 99 );

        // Kit CSS for a designed page (e.g. the blog listing): typography, card
        // depth + hover. Stored as post meta `_bitbot_page_css` and printed here
        // (wp_head) so KSES can't strip it and it beats theme styles.
        add_action( 'wp_head', array( $this, 'render_page_css' ), 100 );

        // Single-post kit CSS (the article page) — site option so every post
        // matches the blog index's kit. Presentation only; content untouched.
        add_action( 'wp_head', array( $this, 'render_post_css' ), 100 );

        // Site-wide chrome kit CSS (header/footer/comments/gutters/background) —
        // printed on every front-end page so the whole site carries the kit.
        add_action( 'wp_head', array( $this, 'render_site_css' ), 101 );

        // External content links open in a new tab (owner preference) —
        // behaviour applied at render so every existing and future post gets
        // it without content edits. Internal links stay same-tab.
        add_filter( 'the_content', array( $this, 'external_links_new_tab' ), 20 );

        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_toc_assets' ) );

        // Site chrome CSS (branded header layout, working sticky, zero-gap
        // join to content) — enqueued only when a BitBot header/footer
        // template-part override exists on this site.
        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_chrome_assets' ) );

        // AI-search: serve /llms.txt — a live markdown index of the site's
        // published pages + posts so AI assistants (ChatGPT, Perplexity,
        // Google AI) can find and cite the best content (llmstxt.org).
        // Generated from live WP content on request (one source of truth,
        // never stale) and gone the moment the plugin deactivates. Disable
        // via option `bitbot_llms_txt_disabled`.
        add_action( 'template_redirect', array( $this, 'maybe_serve_llms_txt' ), 0 );

        // Legacy-permalink rescue: after the agent switches the permalink
        // structure, WordPress does NOT reliably redirect the old date-based
        // URLs — every previously indexed/bookmarked link would 404. On 404,
        // match the trailing slug to a published post and 301 to its current
        // permalink. Enabled by the permalink apply (option flag).
        add_action( 'template_redirect', array( $this, 'maybe_redirect_legacy_permalink' ), 1 );

        // Theme-proofing: on theme switch, swap the per-theme styling layer,
        // re-assert the agent-built front-page + header-nav overrides for the
        // new theme (both native Site-Editor-style user overrides, both
        // consent-guarded via the overlay manifest), and push a fresh style
        // snapshot so the agent styles the RIGHT theme.
        add_action( 'after_switch_theme', array( $this, 'on_theme_switched' ), 10, 2 );

        // The admin React bundle is shipped as an ES module — Vite emits
        // cross-chunk imports between admin.js, module.js, and the lazy
        // analytics chart chunk. Without type="module" the browser refuses
        // to parse them and the whole admin SPA stays blank.
        add_filter( 'script_loader_tag', array( $this, 'mark_admin_react_as_module' ), 10, 3 );

        // The post kit's "More from the site" rail listed the post you were
        // already reading — observed live 2026-08-06 on a two-post site, where
        // one of the two suggestions was the current article. The shipped
        // markup carries `"exclude":[]`, and a static template cannot know the
        // current post, so it is excluded here at query time.
        //
        // Matched on the rail's own inner card class rather than on a marker we
        // would have to add to the query block: templates are PUBLISHED to
        // sites, so a marker-based fix would only reach sites that rebuilt
        // their template. This reaches every site already carrying the kit.
        add_filter( 'query_loop_block_query_vars', array( $this, 'exclude_current_from_related_rail' ), 10, 2 );

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
        // THE AGENT'S EYES. The BitBot admin frames the front-end (same origin)
        // for the live preview, and the agent measures that frame. Requests
        // carrying ?bb_view=1 get same-origin framing allowed, the admin bar
        // removed, and caching bypassed — but ONLY for a logged-in administrator,
        // so this is not an endpoint a stranger can reach (see the method).
        add_action( 'send_headers', array( $this, 'maybe_relax_preview_framing' ), 999 );
        add_action( 'wp_head', array( $this, 'maybe_emit_preview_status' ), 1 );
        add_filter( 'show_admin_bar', array( $this, 'maybe_hide_admin_bar_in_preview' ) );

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
        // Registered under THIS plugin version: nothing to do. Any other value
        // (older version, or the pre-2.1 boolean) re-registers with the existing
        // key so the backend learns the plugin version after an update; the
        // backend gates plugin routes on it (sites.plugin_version).
        if ( get_option( 'bitbot_registered' ) === BITBOT_VERSION ) {
            return;
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

        // 2.1.366: the Search presentation is gone, the widget is chat only.
        // A site that had it keeps translated rows that still say "Search";
        // drop the automatic ones so the next settings save refills them with
        // the chat text. Owner-edited rows are kept.
        if ( version_compare( $stored, '2.1.366', '<' ) && 'search' === get_option( 'bitbot_widget_mode', 'chat' ) ) {
            delete_option( 'bitbot_widget_mode' );
            update_option(
                BitBot_Widget_Copy::OPTION,
                BitBot_Widget_Copy::drop_auto_rows( BitBot_Widget_Copy::normalize( get_option( BitBot_Widget_Copy::OPTION, array() ) ) )
            );
        }

        // 2.1.381: the WhatsApp opening message is fixed ("Hello, I was on
        // <site name>", built in the widget); the owner option is gone.
        if ( version_compare( $stored, '2.1.381', '<' ) ) {
            delete_option( 'bitbot_whatsapp_message' );
        }

        // 2.1.413: the welcome line is written without a speaker's gender (it is
        // text first and any voice may say it); rows translated for the voice's
        // gender are made again. Owner rows are untouched. A failed call keeps
        // the old rows; the owner's next copy save fills them in.
        if ( version_compare( $stored, '2.1.413', '<' ) && $this->admin instanceof BitBot_Admin ) {
            $this->admin->regenerate_auto_copy();
        }

        // Retired the daily plan-cache refresh cron: the backend gates every
        // chat server-side and the widget reacts to an expired response, so the
        // poll only narrowed a cosmetic FAB flash and did it poorly at 24h.
        // Shed any event already scheduled on existing installs.
        wp_clear_scheduled_hook( BitBot_Plan::REFRESH_CRON_HOOK );

        // Every upgrade: BitBot renders into the page <head> (SEO meta, kit
        // CSS), so a new version can change every page's HTML — cached copies
        // of OUR output are stale the moment we update. Purge page caches via
        // their standard hooks (each is a no-op when that cache is absent):
        // a reviewer read a pre-upgrade cached head and reported the new SEO
        // output "missing" — the cache was lying, not the render.
        do_action( 'litespeed_purge_all' );
        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
        }
        if ( function_exists( 'w3tc_flush_posts' ) ) {
            w3tc_flush_posts();
        }
        wp_cache_flush();

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
        // Sync the SAME post types the full sync covers (all public CPTs:
        // hotels, products, events, listings, …), not just post+page —
        // otherwise new/edited CPT entries never auto-sync on save and the
        // bot silently goes stale until a manual full re-sync.
        if ( ! in_array( $post->post_type, $this->api_client->get_syncable_post_types(), true ) ) return;

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
        if ( ! in_array( $post->post_type, $this->api_client->get_syncable_post_types(), true ) ) return;
        if ( $post->post_status !== 'publish' ) return;

        // Same enriched gather as the bulk sync — the row lands LINKED
        // (wp_post_id + editor/SEO meta), never as a url-only ghost.
        $this->api_client->sync_post( (int) $post_id );
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
            // Worklist expired or cleared. Returning silently here used to
            // leave state 'running' on the books FOREVER — the dashboard
            // showed "0 of 44 pages (0%)" with nothing left that could ever
            // advance or clear it (seen live on a customer site). A run with
            // no worklist is over; say so.
            update_option( self::SYNC_PROGRESS_OPTION, array_merge( $progress, array(
                'state'      => 'error',
                'error'      => 'Sync worklist expired before finishing. Press Sync now to restart.',
                'updated_at' => time(),
            ) ), false );
            return;
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
    /**
     * True once the current request is an AUTHENTICATED BitBot server-to-server
     * call. Set from rest_pre_dispatch so every bitbot/v1 route — including any
     * added later — feeds provenance without its own bookkeeping.
     */
    private static $is_bitbot_request = false;

    public function mark_bitbot_request( $result, $server, $request ) {
        if ( 0 === strpos( (string) $request->get_route(), '/bitbot/v1/' ) ) {
            $key    = (string) $request->get_param( 'apiKey' );
            $stored = (string) get_option( 'bitbot_api_key', '' );
            if ( '' !== $stored && '' !== $key && hash_equals( $stored, $key ) ) {
                self::$is_bitbot_request = true;
            }
        }
        return $result;
    }

    /**
     * Stamp posts/pages BitBot itself creates. Only fires on CREATE ($update
     * false) — an edit to an owner's post never launders it into ours, which
     * is the whole point: editing their content is allowed and must not buy
     * the right to restyle it.
     */
    public function stamp_bitbot_provenance( $post_id, $post, $update ) {
        if ( $update || ! is_object( $post ) ) {
            return;
        }
        if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
            return;
        }
        if ( in_array( $post->post_status, array( 'auto-draft', 'inherit' ), true ) ) {
            return;
        }
        $is_bitbot_ajax = wp_doing_ajax()
            && isset( $_REQUEST['action'] )
            && 0 === strpos( sanitize_key( wp_unslash( $_REQUEST['action'] ) ), 'bitbot_' );
        if ( ! self::$is_bitbot_request && ! $is_bitbot_ajax ) {
            return;
        }
        update_post_meta( $post_id, '_bitbot_generated', 1 );

        // A REAL AUTHOR, FROM THE SITE'S OWN USER LIST.
        //
        // Nothing sets post_author on our inserts, so a post created by the
        // unauthenticated auto-apply poller lands with post_author = 0 —
        // authorless, rendering no byline at all. An empty or machine byline
        // reads as anonymous content to Google and to AI assistants, which is
        // the whole reason bylines matter here.
        //
        // Nothing is invented: the site already HAS users with names the owner
        // set up. We only ensure the post points at one of them, preferring an
        // administrator over the bitbot-agent service account, which exists for
        // permissions and was never meant to be a public byline.
        $author_id = (int) $post->post_author;
        $needs_author = 0 === $author_id;
        if ( ! $needs_author ) {
            $current = get_user_by( 'id', $author_id );
            if ( ! $current || 'bitbot-agent' === $current->user_login ) {
                $needs_author = true;
            }
        }
        if ( $needs_author ) {
            $admins = get_users( array( 'role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1 ) );
            if ( ! empty( $admins ) ) {
                wp_update_post( array( 'ID' => $post_id, 'post_author' => (int) $admins[0]->ID ) );
            }
        }

        // Born on the right layout. The owner's items keep whatever template
        // they have; ours get the library's default for their kind at the
        // moment of creation, so "BitBot content uses BitBot templates" needs
        // no follow-up call and cannot be forgotten by a new creation path.
        $map = get_option( 'bitbot_default_templates', array() );
        if ( is_array( $map ) && ! empty( $map[ $post->post_type ] ) ) {
            update_post_meta( $post_id, '_wp_page_template', (string) $map[ $post->post_type ] );
        }
    }

    /**
     * Did BitBot create this item? The stamp is authoritative going forward;
     * the backend also recognises its own older posts by wp_post_id, so the
     * absence of a stamp is reported, never silently treated as "owner's".
     */
    public static function is_bitbot_generated( $post_id ) {
        return '' !== (string) get_post_meta( (int) $post_id, '_bitbot_generated', true );
    }

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

        // Self-heal on (re)install: if the agent already manages this site's
        // nav / homepage, re-assert the theme-proofing state. Runs BEFORE the
        // heavier activation steps because cheap hosts kill activation
        // requests mid-way (that's why first-boot HTTP is deferred to cron).
        // Hard no-op on a site the agent never touched.
        $this->self_heal_theme_proofing();

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
     * Whether an SEO plugin is present AND actually emitting head output.
     * Yoast always emits when active. Rank Math emits only once its setup
     * wizard completed (rank_math_is_configured) — installed-but-dormant
     * stores fields while rendering NOTHING, so it does not count.
     *
     * @since 2.1.182
     * @return bool
     */
    private function seo_incumbent_emitting() {
        if ( defined( 'WPSEO_VERSION' ) ) {
            return true;
        }
        return defined( 'RANK_MATH_VERSION' ) && (bool) get_option( 'rank_math_is_configured' );
    }

    /**
     * The agent-written SEO title, when BitBot owns head output. Filtered on
     * pre_get_document_title so core/theme title generation is bypassed;
     * empty falls through to the default. The fields live in Rank Math's key
     * format (one key map — api_client::read_seo_meta), so a real SEO suite
     * installed later adopts them and BitBot yields automatically.
     *
     * @since 2.1.182
     * @param string $title
     * @return string
     */
    public function filter_seo_document_title( $title ) {
        if ( $this->seo_incumbent_emitting() || ! is_singular() ) {
            return $title;
        }
        $meta = $this->api_client->read_seo_meta( (int) get_queried_object_id() );
        $own  = trim( wp_strip_all_tags( (string) $meta['title'] ) );
        return '' !== $own ? $own : $title;
    }

    /**
     * Meta description + og basics, rendered by BitBot when no SEO plugin is
     * emitting. Description renders only when the field is actually set —
     * never auto-generated filler. og facts (title/url/image) come from the
     * post itself.
     *
     * @since 2.1.182
     */
    public function render_seo_head() {
        if ( $this->seo_incumbent_emitting() || ! is_singular() ) {
            return;
        }
        $post_id = (int) get_queried_object_id();
        if ( ! $post_id ) {
            return;
        }
        $meta  = $this->api_client->read_seo_meta( $post_id );
        $title = trim( wp_strip_all_tags( '' !== $meta['title'] ? $meta['title'] : get_the_title( $post_id ) ) );
        $desc  = trim( wp_strip_all_tags( (string) $meta['description'] ) );

        echo "\n<!-- BitBot SEO -->\n";
        if ( '' !== $desc ) {
            echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
        }
        echo '<meta property="og:type" content="' . ( is_singular( 'post' ) ? 'article' : 'website' ) . '" />' . "\n";
        if ( '' !== $title ) {
            echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
        }
        if ( '' !== $desc ) {
            echo '<meta property="og:description" content="' . esc_attr( $desc ) . '" />' . "\n";
        }
        echo '<meta property="og:url" content="' . esc_url( get_permalink( $post_id ) ) . '" />' . "\n";
        $img = get_the_post_thumbnail_url( $post_id, 'large' );
        if ( $img ) {
            echo '<meta property="og:image" content="' . esc_url( $img ) . '" />' . "\n";
        }
        echo "<!-- /BitBot SEO -->\n";
    }

    /**
     * On a canvas page (post meta `_bitbot_canvas`), print scoped CSS that hides
     * the theme's header/footer template parts + the page title so the agent's
     * own design owns the page. Output here (wp_head), never in post content, so
     * KSES on multisite can't strip it. Structural selectors only — never a
     * generic class name the agent's content might reuse.
     *
     * @since 2.1.64
     */
    public function render_canvas_hide_css() {
        if ( ! is_singular( 'page' ) ) {
            return;
        }
        $id = get_queried_object_id();
        if ( ! $id ) {
            return;
        }
        // Canvas pages only. The old per-page "hide the theme title" flag is
        // no longer read (2.1.338): a page keeps the theme's title, and the
        // agent writes its body heading as an H2 under it.
        if ( ! get_post_meta( $id, '_bitbot_canvas', true ) ) {
            return;
        }
        $s = is_front_page() ? 'body.home' : 'body.page-id-' . (int) $id;
        // Hide the theme's PAGE title so a designed page starts at its own hero.
        // Re-show post titles inside a Query Loop listing (a blog page uses the
        // same .wp-block-post-title class per card) — higher specificity wins —
        // so hiding the page title never blanks out the blog.
        $css = $s . ' .wp-block-post-title{display:none!important}'
             . $s . ' .wp-block-query .wp-block-post-title{display:block!important}';
        // Full canvas (explicit opt-in): the page IS the whole layout, so
        // hide the theme's header/footer template parts + collapse the empty
        // page-title wrapper band.
        $css .= $s . ' :has(> .wp-block-post-title):not(:has(.wp-block-post-content)){display:none!important}'
              . $s . ' .wp-block-template-part{display:none!important}'
              . $s . ' .wp-site-blocks{padding-top:0!important}';
        echo "<style id=\"bitbot-canvas\">" . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * Print a designed page's kit CSS (typography, card depth, hover) from
     * post meta `_bitbot_page_css`, scoped to that page only. Stored, not
     * inline, so KSES on multisite can't strip it (box-shadow, etc.) and it
     * beats the theme with !important. Set by BitBot's blog_page apply.
     *
     * @since 2.1.73
     */
    public function render_page_css() {
        if ( ! is_singular( 'page' ) ) {
            return;
        }
        $id  = get_queried_object_id();
        $css = $id ? get_post_meta( $id, '_bitbot_page_css', true ) : '';
        if ( ! $css ) {
            return;
        }
        echo "<style id=\"bitbot-page-css\">" . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * Print the site's single-post kit CSS (typography, contained featured
     * image, spacing) on single blog posts so each article matches the blog
     * index's design kit. Stored as the `bitbot_post_css` option by the
     * blog_page apply. Presentation only — the article content is untouched.
     *
     * @since 2.1.74
     */
    public function render_post_css() {
        if ( ! is_singular( 'post' ) ) {
            return;
        }
        $css = get_option( 'bitbot_post_css', '' );
        if ( ! $css ) {
            return;
        }
        echo "<style id=\"bitbot-post-css\">" . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * Print the site-wide chrome kit CSS (theme header/footer, nav, comments,
     * side gutters, page background) on every front-end page so the whole site
     * carries the design kit. Stored as the `bitbot_site_css` option by the
     * blog_page apply. Presentation only.
     *
     * @since 2.1.75
     */
    public function render_site_css() {
        if ( is_admin() ) {
            return;
        }
        $css = get_option( 'bitbot_site_css', '' );
        if ( $css ) {
            echo "<style id=\"bitbot-site-css\">" . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
        }
        // The agent's Additional CSS (custom_css proposals). Printed by US, not
        // the Customizer channel: wp_update_custom_css_post routes through KSES
        // for admins without unfiltered_html (all of multisite), which escapes
        // '>' to '&gt;' and silently kills every child-combinator rule.
        $custom = get_option( 'bitbot_custom_css', '' );
        if ( $custom ) {
            echo "<style id=\"bitbot-custom-css\">" . $custom . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
        }
    }

    /**
     * External links in content open in a new tab (owner preference, applied
     * at render): target=_blank + rel noopener on any content <a> whose host
     * is not this site's. Covers every existing and future post with no
     * content edits; internal navigation stays in the same tab.
     *
     * @param string $content Post content HTML.
     * @return string
     */
    public function external_links_new_tab( $content ) {
        if ( ! is_string( $content ) || false === strpos( $content, '<a' ) ) {
            return $content;
        }
        $home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        return preg_replace_callback(
            '#<a\s[^>]*href=("|\')(https?://[^"\']+)\1[^>]*>#i',
            function ( $m ) use ( $home_host ) {
                $tag  = $m[0];
                $host = (string) wp_parse_url( $m[2], PHP_URL_HOST );
                $suffix = '.' . $home_host;
                $is_internal = '' === $host
                    || $host === $home_host
                    || ( '' !== $home_host && substr( $host, -strlen( $suffix ) ) === $suffix );
                if ( $is_internal ) {
                    return $tag;
                }
                if ( false === stripos( $tag, 'target=' ) ) {
                    $tag = substr( $tag, 0, -1 ) . ' target="_blank">';
                }
                if ( false === stripos( $tag, 'rel=' ) ) {
                    $tag = substr( $tag, 0, -1 ) . ' rel="noopener noreferrer">';
                } elseif ( false === stripos( $tag, 'noopener' ) ) {
                    $tag = preg_replace( '#rel=("|\')(.*?)\1#i', 'rel=${1}${2} noopener${1}', $tag, 1 );
                }
                return $tag;
            },
            $content
        );
    }

    /**
     * Enqueue the TOC-rail runtime on single posts. The agent's template
     * preset ships an EMPTY .bb-toc-rail container; this pair (JS builds the
     * list from the post's real headings + scroll-spies it, CSS makes it a
     * sticky rail) is the plugin-owned guarantee behind it. Enqueued on every
     * single post: the JS no-ops (and hides the rail) when the container or
     * the headings are absent, so the gate stays simple and honest.
     *
     * @since 2.1.191
     */
    public function maybe_enqueue_toc_assets() {
        if ( ! is_singular( 'post' ) ) {
            return;
        }
        wp_enqueue_style(
            'bitbot-toc',
            BITBOT_PLUGIN_URL . 'assets/css/bitbot-toc.css',
            array(),
            BITBOT_VERSION
        );
        wp_enqueue_script(
            'bitbot-toc',
            BITBOT_PLUGIN_URL . 'assets/js/bitbot-toc.js',
            array(),
            BITBOT_VERSION,
            true
        );
    }

    /**
     * Enqueue the site chrome CSS when a BitBot header/footer template-part
     * override exists (overlay manifest headerOverrides/footerOverrides).
     * Carries the structural guarantees the agent promises: branded header
     * layout, sticky that actually works (z-index, admin-bar offset,
     * overflow-clip), and the flush zero-gap join to page content.
     *
     * @since 2.1.121
     */
    public function maybe_enqueue_chrome_assets() {
        $manifest = $this->get_overlay_manifest();
        $has_header = !empty( $manifest['headerOverrides'] ) && is_array( $manifest['headerOverrides'] );
        $has_footer = !empty( $manifest['footerOverrides'] ) && is_array( $manifest['footerOverrides'] );
        $has_parts  = !empty( $manifest['partOverrides'] ) && is_array( $manifest['partOverrides'] );
        if ( ! $has_header && ! $has_footer && ! $has_parts ) {
            return;
        }
        wp_enqueue_style(
            'bitbot-chrome',
            BITBOT_PLUGIN_URL . 'assets/css/bitbot-chrome.css',
            array(),
            BITBOT_VERSION
        );
    }

    /**
     * Serve /llms.txt — the emerging llmstxt.org standard: a markdown index
     * telling AI assistants what this site's best content is, so they can
     * find and CITE it. Built live from published pages + posts (never
     * stale). Skipped when the option `bitbot_llms_txt_disabled` is set.
     *
     * @since 2.1.88
     */
    public function maybe_serve_llms_txt() {
        if ( get_option( 'bitbot_llms_txt_disabled' ) ) {
            return;
        }
        $path = isset( $_SERVER['REQUEST_URI'] ) ? (string) parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
        if ( '/llms.txt' !== $path ) {
            return;
        }
        // Plain-text out: entities decoded ('&amp;' → '&'), tags stripped.
        $txt = function ( $s ) {
            return trim( html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES, 'UTF-8' ) );
        };
        $name    = $txt( get_option( 'blogname', '' ) );
        $tagline = $txt( get_option( 'blogdescription', '' ) );
        $lines   = array( '# ' . $name );
        if ( '' !== $tagline ) {
            $lines[] = '';
            $lines[] = '> ' . $tagline;
        }
        $pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 30, 'orderby' => 'menu_order', 'order' => 'ASC' ) );
        if ( ! empty( $pages ) ) {
            $lines[] = '';
            $lines[] = '## Pages';
            foreach ( $pages as $p ) {
                $excerpt = $txt( get_the_excerpt( $p ) );
                $lines[] = '- [' . $txt( $p->post_title ) . '](' . get_permalink( $p ) . ')' . ( $excerpt ? ': ' . mb_substr( $excerpt, 0, 160 ) : '' );
            }
        }
        $posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 50 ) );
        if ( ! empty( $posts ) ) {
            $lines[] = '';
            $lines[] = '## Articles';
            foreach ( $posts as $p ) {
                $excerpt = $txt( get_the_excerpt( $p ) );
                $lines[] = '- [' . $txt( $p->post_title ) . '](' . get_permalink( $p ) . ')' . ( $excerpt ? ': ' . mb_substr( $excerpt, 0, 160 ) : '' );
            }
        }
        // WP has already routed this URL as a 404 — override the status, or AI
        // crawlers treat the file as absent even though the body serves.
        status_header( 200 );
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'X-Robots-Tag: noindex' );
        echo implode( "\n", $lines ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- plain-text response
        exit;
    }

    /**
     * 301-rescue for legacy permalinks. On a 404 whose trailing path segment
     * exactly matches a published post/page slug, redirect permanently to its
     * current permalink — so old date-based URLs (from search results,
     * bookmarks, backlinks) keep working after a permalink-structure change.
     * Fires ONLY on genuine 404s with an exact slug match and a different
     * target, so it can never shadow real content. Opt out via option
     * `bitbot_legacy_permalink_redirect_disabled`.
     *
     * @since 2.1.93
     */
    public function maybe_redirect_legacy_permalink() {
        if ( ! is_404() || get_option( 'bitbot_legacy_permalink_redirect_disabled' ) ) {
            return;
        }
        $path = isset( $_SERVER['REQUEST_URI'] ) ? (string) parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
        $segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ) ) );
        if ( empty( $segments ) ) {
            return;
        }
        $slug = sanitize_title( end( $segments ) );
        if ( '' === $slug ) {
            return;
        }
        $posts = get_posts( array(
            'post_type'   => array( 'post', 'page' ),
            'post_status' => 'publish',
            'name'        => $slug,
            'numberposts' => 1,
        ) );
        if ( empty( $posts ) ) {
            return;
        }
        $target = get_permalink( $posts[0] );
        if ( ! $target ) {
            return;
        }
        // Never redirect to ourselves (avoids loops if the permalink IS 404ing).
        $target_path = (string) parse_url( $target, PHP_URL_PATH );
        if ( untrailingslashit( $target_path ) === untrailingslashit( $path ) ) {
            return;
        }
        wp_safe_redirect( $target, 301 );
        exit;
    }

    /**
     * The overlay manifest — the registry of every presentation "goodie"
     * BitBot has injected, layered PER THEME (one layer per theme, like an
     * Effect layer): { layers: { <stylesheet>: { siteCss, customCss, postCss,
     * archivedAt } }, overrides: { <stylesheet>: <wp_template post id> },
     * history: [ { event, ... , at } ] }. The base site (content, menus,
     * media, SEO meta) is vanilla WordPress and never lives here.
     *
     * @since 2.1.81
     * @return array
     */
    public function get_overlay_manifest() {
        $defaults = array( 'layers' => array(), 'overrides' => array(), 'navOverrides' => array(), 'history' => array() );
        $m = get_option( 'bitbot_overlay', array() );
        return is_array( $m ) ? array_merge( $defaults, $m ) : $defaults;
    }

    /** Persist the manifest, capping history so the option can't grow unbounded. */
    public function save_overlay_manifest( $m ) {
        if ( isset( $m['history'] ) && is_array( $m['history'] ) && count( $m['history'] ) > 40 ) {
            $m['history'] = array_slice( $m['history'], -40 );
        }
        update_option( 'bitbot_overlay', $m, false );
    }

    /** Append one event to the overlay trace. */
    public function overlay_trace( $event, $detail = array() ) {
        $m = $this->get_overlay_manifest();
        $m['history'][] = array_merge( array( 'event' => (string) $event, 'at' => gmdate( 'c' ) ), $detail );
        $this->save_overlay_manifest( $m );
    }

    /**
     * Theme switched — swap the presentation LAYER, never fight the theme.
     *
     *   1. Archive the active CSS layer under the OLD theme's key and clear
     *      the live options: the new theme renders pure vanilla (base = the
     *      owner's real content, which works everywhere).
     *   2. If the NEW theme has an archived layer from before, restore it —
     *      switching back is lossless.
     *   3. Re-assert agent-built state that WordPress scopes per theme (the
     *      front-page override; the nav-post option) — both hard-gated to
     *      agent-built artefacts and to "the user never deleted it".
     *   4. Record the whole move in the overlay trace and push a fresh style
     *      snapshot so the agent's memory has the full history and it can
     *      offer a re-fit instead of assuming.
     *
     * Everything best-effort; a theme switch must never fatal.
     *
     * @since 2.1.80 (layer semantics 2.1.81)
     * @param string $old_name Previous theme's display name (from core).
     * @param mixed  $old_theme Previous WP_Theme (from core).
     */
    public function on_theme_switched( $old_name = '', $old_theme = null ) {
        $new_stylesheet = get_stylesheet();
        $old_stylesheet = ( $old_theme instanceof WP_Theme ) ? $old_theme->get_stylesheet() : '';

        $m = $this->get_overlay_manifest();

        // 1. Archive the outgoing theme's layer + suspend it.
        $active = array(
            'siteCss'   => (string) get_option( 'bitbot_site_css', '' ),
            'customCss' => (string) get_option( 'bitbot_custom_css', '' ),
            'postCss'   => (string) get_option( 'bitbot_post_css', '' ),
        );
        if ( '' !== $old_stylesheet && ( $active['siteCss'] || $active['customCss'] || $active['postCss'] ) ) {
            $m['layers'][ $old_stylesheet ] = array_merge( $active, array( 'archivedAt' => gmdate( 'c' ) ) );
            $m['history'][] = array( 'event' => 'layer_archived', 'theme' => $old_stylesheet, 'at' => gmdate( 'c' ) );
        }
        update_option( 'bitbot_site_css', '' );
        update_option( 'bitbot_custom_css', '' );
        update_option( 'bitbot_post_css', '' );

        // 2. Restore the incoming theme's layer if one was archived earlier.
        if ( ! empty( $m['layers'][ $new_stylesheet ] ) && is_array( $m['layers'][ $new_stylesheet ] ) ) {
            $layer = $m['layers'][ $new_stylesheet ];
            update_option( 'bitbot_site_css', (string) ( $layer['siteCss'] ?? '' ) );
            update_option( 'bitbot_custom_css', (string) ( $layer['customCss'] ?? '' ) );
            update_option( 'bitbot_post_css', (string) ( $layer['postCss'] ?? '' ) );
            $m['history'][] = array( 'event' => 'layer_restored', 'theme' => $new_stylesheet, 'at' => gmdate( 'c' ) );
        }

        $m['history'][] = array(
            'event' => 'theme_switch',
            'from'  => $old_stylesheet ?: (string) $old_name,
            'to'    => $new_stylesheet,
            'at'    => gmdate( 'c' ),
        );
        $this->save_overlay_manifest( $m );

        // 3. Per-theme agent-built state (both no-ops on untouched sites).
        $this->self_heal_theme_proofing();

        // 4. The agent's memory: full trace rides inside the style snapshot.
        if ( $this->api_client instanceof BitBot_API_Client ) {
            $this->api_client->push_style_snapshot();

            // 5. Proactive agent run: tell the backend so the agent gives the
            // site a once-over ON THE NEW THEME (menu, homepage, look, links)
            // without waiting to be asked. Debounced — rapid theme flips
            // (browsing/testing themes) fire ONE run, not one per flip. The
            // backend no-ops on plans without the agent.
            if ( ! get_transient( 'bitbot_theme_switch_agent' ) ) {
                set_transient( 'bitbot_theme_switch_agent', 1, 10 * MINUTE_IN_SECONDS );
                $this->api_client->notify_theme_switch( $old_stylesheet ?: (string) $old_name, $new_stylesheet );
            }
        }
    }

    /**
     * Re-assert the agent's theme-proofing state: the nav-post option (so
     * the header-nav override reads it) plus the front-page and header-nav
     * template overrides. DB-only, no HTTP — safe during activation, which
     * cheap hosts kill mid-request. Hard no-op on sites the agent never
     * touched.
     *
     * @since 2.1.81
     */
    public function self_heal_theme_proofing() {
        if ( post_type_exists( 'wp_navigation' ) ) {
            $nav = get_posts( array(
                'post_type'   => 'wp_navigation',
                'title'       => 'BitBot Primary',
                'post_status' => 'publish',
                'numberposts' => 1,
            ) );
            if ( ! empty( $nav ) ) {
                update_option( 'bitbot_nav_post_id', (int) $nav[0]->ID );
            }
        }
        if ( $this->admin instanceof BitBot_Admin ) {
            $this->admin->ensure_front_page_override();
            $this->admin->ensure_header_nav_override();
        }
    }

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

        // Voice-call logic (unit tested in tests/widget-voice.test.js). Loaded
        // before widget.js; inert unless voiceCallsEnabled is on.
        $voice_ver = BITBOT_VERSION . '.' . (int) @filemtime( BITBOT_PLUGIN_DIR . 'assets/js/widget-voice.js' );
        wp_enqueue_script(
            'bitbot-widget-voice',
            BITBOT_PLUGIN_URL . 'assets/js/widget-voice.js',
            array(),
            $voice_ver,
            true
        );

        // WhatsApp click-to-chat link logic (unit tested in tests/widget-whatsapp.test.js).
        $wa_ver = BITBOT_VERSION . '.' . (int) @filemtime( BITBOT_PLUGIN_DIR . 'assets/js/widget-whatsapp.js' );
        wp_enqueue_script(
            'bitbot-widget-whatsapp',
            BITBOT_PLUGIN_URL . 'assets/js/widget-whatsapp.js',
            array(),
            $wa_ver,
            true
        );

        // Language pick logic (unit tested in tests/widget-language.test.js).
        $lang_ver = BITBOT_VERSION . '.' . (int) @filemtime( BITBOT_PLUGIN_DIR . 'assets/js/widget-language.js' );
        wp_enqueue_script(
            'bitbot-widget-language',
            BITBOT_PLUGIN_URL . 'assets/js/widget-language.js',
            array(),
            $lang_ver,
            true
        );

        // Answer stream logic: NDJSON line parser + delta merging (unit tested in
        // tests/widget-stream.test.js).
        $stream_ver = BITBOT_VERSION . '.' . (int) @filemtime( BITBOT_PLUGIN_DIR . 'assets/js/widget-stream.js' );
        wp_enqueue_script(
            'bitbot-widget-stream',
            BITBOT_PLUGIN_URL . 'assets/js/widget-stream.js',
            array(),
            $stream_ver,
            true
        );

        // Pre-chat gate form renderer + validator (unit tested in tests/widget-form.test.js).
        $form_ver = BITBOT_VERSION . '.' . (int) @filemtime( BITBOT_PLUGIN_DIR . 'assets/js/widget-form.js' );
        wp_enqueue_script(
            'bitbot-widget-form',
            BITBOT_PLUGIN_URL . 'assets/js/widget-form.js',
            array(),
            $form_ver,
            true
        );

        wp_enqueue_script(
            'bitbot-widget',
            BITBOT_PLUGIN_URL . 'assets/js/widget.js',
            array( 'bitbot-widget-voice', 'bitbot-widget-whatsapp', 'bitbot-widget-language', 'bitbot-widget-stream', 'bitbot-widget-form' ),
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

        // Widget config is built by build_widget_config() — the SINGLE source
        // of truth, also served by the /widget-config REST endpoint so the
        // widget can refresh it at runtime past full-page caches. See that
        // method for why the runtime fetch exists.
        wp_localize_script( 'bitbot-widget', 'bitbotConfig', $this->build_widget_config() );
    }

    /** The input hint the widget shows: the owner's option, else the mode default. */
    private function base_placeholder() {
        $custom = trim( (string) get_option( 'bitbot_placeholder_text', '' ) );
        return '' !== $custom ? $custom : BitBot_Widget_Copy::default_placeholder( substr( get_locale(), 0, 2 ) );
    }

    /**
     * Build the widget runtime config. SINGLE source of truth for BOTH the
     * inlined wp_localize_script (first paint) AND the /widget-config REST
     * endpoint (runtime refresh).
     *
     * Why the endpoint exists: wp_localize_script bakes this into the page
     * HTML, which full-page caches (LiteSpeed, WP Rocket, CDNs) then freeze —
     * so a settings change (avatar, colour, copy) wouldn't show on cached
     * pages until the cache expired. widget.js fetches /widget-config with a
     * cache-buster on load and overrides the (possibly stale) inlined values,
     * so the widget always reflects current settings regardless of ANY cache
     * layer — no cache-plugin enumeration, correct by construction.
     *
     * @since 2.1.2
     * @return array
     */
    private function widget_copy_map( $site_code, $base_welcome ) {
        $codes = BitBot_Languages::effective_selection(
            get_option( BitBot_Languages::OPTION, array() ),
            BitBot_Languages::detected(),
            BitBot_Languages::all()
        );
        if ( count( $codes ) < 2 ) {
            return (object) array();
        }
        $map = BitBot_Widget_Copy::config_map(
            $codes,
            $site_code,
            BitBot_Widget_Copy::normalize( get_option( BitBot_Widget_Copy::OPTION, array() ) ),
            BitBot_Widget_Copy::base_title( get_option( 'bitbot_widget_title', '' ), substr( get_locale(), 0, 2 ) ),
            $base_welcome,
            BitBot_Widget_Copy::base_questions( get_option( 'bitbot_suggested_questions', '' ) ),
            $this->base_placeholder()
        );
        return (object) $map;
    }

    public function build_widget_config() {
        $language_code  = substr( get_locale(), 0, 2 );
        $css_ver        = BITBOT_VERSION . '.' . (int) @filemtime( BITBOT_PLUGIN_DIR . 'assets/css/widget.css' );
        $widget_css_url = BITBOT_PLUGIN_URL . 'assets/css/widget.css?ver=' . rawurlencode( $css_ver );

        // Normalise the welcome on read: get_option()'s default only fires when the
        // option is ABSENT, so a cleared-and-saved value (empty string) would render
        // a blank bubble. Fall back to the default whenever it's blank/whitespace.
        $default_welcome = BitBot_Widget_Copy::default_welcome( $language_code );
        $welcome_message = trim( (string) get_option( 'bitbot_welcome_message', $default_welcome ) );
        if ( '' === $welcome_message ) {
            $welcome_message = $default_welcome;
        }

        return array_merge( $this->api_client->widget_stream_config(), array(
            'restUrl'              => esc_url_raw( rest_url( 'bitbot/v1' ) ),
            'nonce'                => wp_create_nonce( 'wp_rest' ),
            'position'             => sanitize_text_field( get_option( 'bitbot_widget_position', 'bottom-right' ) ),
            'primaryColor'         => sanitize_hex_color( get_option( 'bitbot_primary_color', '#0073aa' ) ),
            'welcomeMessage'       => wp_kses_post( $welcome_message ),
            'languageCode'         => sanitize_text_field( $language_code ),
            // The widget lays itself out for ITS language, not the page's (a Persian page mirrored English copy).
            'rtl'                  => BitBot_Widget_Copy::is_rtl( $language_code ),
            'cssUrl'               => esc_url_raw( $widget_css_url ),
            'placeholderText'      => (string) get_option( 'bitbot_placeholder_text', '' ),
            'typingIndicatorText'  => (string) get_option( 'bitbot_typing_indicator_text', '' ),
            'fallbackMessage'      => (string) get_option( 'bitbot_fallback_message', '' ),
            'leadCapturePrompt'    => (string) get_option( 'bitbot_lead_capture_prompt', '' ),
            'mobileBehaviour'      => sanitize_text_field( get_option( 'bitbot_mobile_behaviour', 'inherit' ) ),
            'avatarUrl'            => esc_url( get_option( 'bitbot_avatar_url', '' ) ),
            'avatarTransform'      => $this->parse_avatar_transform(),
            'suggestedQuestions'   => $this->parse_suggested_questions(),
            'autoOpen'             => $this->parse_auto_open(),
            'nudge'                => $this->parse_nudge(),
            'chatGateFormId'       => $this->parse_chat_gate_form() !== null
                ? (string) get_option( 'bitbot_chat_gate_form_id', '' )
                : '',
            'chatGateForm'         => $this->parse_chat_gate_form(),
            // Server-authoritative pause: true when the bot can't reply
            // (expired sub or Free monthly chat cap). widget.js hides the FAB
            // on this rather than guessing with a client flag/timer. Read from
            // the cached plan snapshot, refreshed (throttled) by the
            // /widget-config endpoint below so it stays fresh without a
            // per-pageview backend call.
            'accessPaused'         => (bool) BitBot_Plan::is_access_paused(),
            // Per-site "Voice calls" switch (default off). The backend
            // re-checks it on every call start; this only decides whether the
            // widget shows its Call button.
            'voiceCallsEnabled'    => get_option( 'bitbot_voice_calls_enabled' ) === '1',
            // Languages the visitor can pick (empty = no choice, no globe).
            'languages'            => BitBot_Languages::widget_list( get_option( BitBot_Languages::OPTION, array() ), $language_code, BitBot_Languages::all(), BitBot_Languages::detected() ),
            // Title + welcome message per selected language (site language =
            // the base text). Empty when the visitor has no language choice.
            'copy'                 => $this->widget_copy_map( $language_code, $welcome_message ),
            // WhatsApp click-to-chat: the digits wa.me wants, or '' when the
            // switch is off or the number is invalid. Rides this config only;
            // there is no route for it. siteName builds the pre-filled text.
            'whatsappNumber'       => BitBot_WhatsApp::widget_number(
                get_option( 'bitbot_whatsapp_number', '' ),
                get_option( 'bitbot_whatsapp_enabled', '0' )
            ),
            'siteName'             => wp_specialchars_decode( wp_strip_all_tags( (string) get_option( 'blogname', '' ) ), ENT_QUOTES ), // the option is stored HTML-escaped ("People&#039;s"); the widget uses this as plain text in the WhatsApp opening line '' ) ),
        ) );
    }

    /**
     * Public REST endpoint serving the live widget config, deliberately
     * uncacheable. Paired with widget.js fetching it with a cache-buster so
     * no page/CDN cache can serve stale settings. Public — it returns the
     * same data already inlined (and public) on every page via bitbotConfig.
     *
     * @since 2.1.2
     */
    /**
     * Plugin window: return a front-end page's rendered HTML, fetched over
     * LOOPBACK inside the host so the CDN / bot-protection edge is never in the
     * path. The one guaranteed way to see the owner's own site when the edge
     * challenges automated browsers. apiKey-authed; site-relative paths only.
     *
     * @param WP_REST_Request $request { apiKey, path }
     * @return WP_REST_Response { ok, html, via } | { ok:false, error }
     */
    /**
     * Structural sanity limits for post content. ~10x beyond any legitimate
     * page (heavy designed pages: 100-300KB, a few hundred blocks, nesting
     * ~12); only pathology exceeds them. Filterable for edge cases.
     *
     * @return array{max_chars:int, max_blocks:int, max_depth:int}
     */
    private function content_guard_limits() {
        return apply_filters( 'bitbot_content_guard_limits', array(
            'max_chars'  => 1500000,
            'max_blocks' => 5000,
            'max_depth'  => 60,
        ) );
    }

    /**
     * Why this content is a parser bomb — or null if it's sane. Cheapest check
     * FIRST: byte length refuses oversized content before ANY parsing, so the
     * guard itself can never OOM on the bomb it's refusing.
     *
     * @param string $content Raw post content.
     * @return string|null Refusal reason.
     */
    private function content_bomb_reason( $content ) {
        $limits = $this->content_guard_limits();
        $len    = strlen( $content );
        if ( $len > $limits['max_chars'] ) {
            return sprintf(
                'content is %s bytes — over the %s-byte sanity cap. No real page is this big; this is the signature of a runaway write loop.',
                number_format( $len ),
                number_format( $limits['max_chars'] )
            );
        }
        $openers = substr_count( $content, '<!-- wp:' );
        if ( $openers > $limits['max_blocks'] ) {
            return sprintf( 'content has %s blocks — over the %s-block sanity cap.', number_format( $openers ), number_format( $limits['max_blocks'] ) );
        }
        if ( $openers > 0 ) {
            $depth = 0;
            $max   = 0;
            $off   = 0;
            while ( preg_match( '#<!--\s*(/)?wp:#', $content, $m, PREG_OFFSET_CAPTURE, $off ) ) {
                $off = $m[0][1] + strlen( $m[0][0] );
                if ( isset( $m[1][0] ) && '/' === $m[1][0] ) {
                    $depth--;
                } else {
                    $depth++;
                    if ( $depth > $max ) {
                        $max = $depth;
                    }
                }
            }
            if ( $max > $limits['max_depth'] ) {
                return sprintf( 'block nesting reaches depth %s — over the %s-level sanity cap (real layouts stay under ~12).', number_format( $max ), number_format( $limits['max_depth'] ) );
            }
        }
        return null;
    }

    /** Record a refused save so the next incident carries evidence. */
    private function record_blocked_save( $reason, $post_id, $post_type ) {
        update_option( 'bitbot_last_blocked_save', array(
            'reason'    => (string) $reason,
            'post_id'   => (int) $post_id,
            'post_type' => (string) $post_type,
            'time'      => gmdate( 'c' ),
        ), false );
    }

    /**
     * wp_insert_post_empty_content filter — the universal abort: EVERY writer
     * (agent, editor, importer, plugin) funnels through wp_insert_post.
     * Returning true makes the save fail instead of persisting the bomb.
     *
     * @param bool  $maybe_empty Whether the post should be considered empty.
     * @param array $postarr     Slashed post data.
     * @return bool
     */
    public function guard_content_bomb( $maybe_empty, $postarr ) {
        $content = isset( $postarr['post_content'] ) ? (string) $postarr['post_content'] : '';
        if ( '' === $content ) {
            return $maybe_empty;
        }
        $reason = $this->content_bomb_reason( wp_unslash( $content ) );
        if ( null !== $reason ) {
            $this->record_blocked_save(
                $reason,
                isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0,
                isset( $postarr['post_type'] ) ? (string) $postarr['post_type'] : ''
            );
            return true;
        }
        return $maybe_empty;
    }

    /**
     * rest_pre_insert_{post_type} filter — same guard, but API writers (the
     * agent, the block editor) get the PRECISE refusal reason as a WP_Error
     * instead of wp_insert_post's generic "empty content" message.
     *
     * @param stdClass|WP_Error $prepared_post
     * @param WP_REST_Request   $request
     * @return stdClass|WP_Error
     */
    public function guard_content_bomb_rest( $prepared_post, $request ) {
        if ( is_wp_error( $prepared_post ) || ! isset( $prepared_post->post_content ) ) {
            return $prepared_post;
        }
        $content = (string) $prepared_post->post_content;
        if ( '' === $content ) {
            return $prepared_post;
        }
        $reason = $this->content_bomb_reason( $content );
        if ( null !== $reason ) {
            $this->record_blocked_save(
                $reason,
                isset( $prepared_post->ID ) ? (int) $prepared_post->ID : 0,
                isset( $prepared_post->post_type ) ? (string) $prepared_post->post_type : ''
            );
            return new WP_Error( 'bitbot_content_bomb', 'Save refused: ' . $reason, array( 'status' => 413 ) );
        }
        return $prepared_post;
    }

    /**
     * Last captured PHP fatal (recorded by the shutdown handler on whichever
     * request crashed). Returns { ok, fatal|null }; fatal = { message, file,
     * line, url, time }. Server-to-server, apiKey-authed.
     *
     * @param WP_REST_Request $request { apiKey }
     * @return WP_REST_Response
     */
    public function rest_last_fatal( $request ) {
        $key    = (string) $request->get_param( 'apiKey' );
        $stored = (string) get_option( 'bitbot_api_key', '' );
        if ( '' === $stored || '' === $key || ! hash_equals( $stored, $key ) ) {
            return new WP_REST_Response( array( 'ok' => false, 'error' => 'unauthorized' ), 401 );
        }
        $fatal = get_option( 'bitbot_last_fatal', null );
        return new WP_REST_Response( array( 'ok' => true, 'fatal' => is_array( $fatal ) ? $fatal : null ), 200 );
    }


    public function rest_widget_config( $request ) {
        // Refresh the cached plan snapshot so accessPaused is accurate: a
        // capped / expired site stays hidden, a reset / upgrade brings the
        // widget back. The widget fetches this endpoint on every page load,
        // but we only re-ask the backend once per 5 min per site (throttle
        // transient) so we're not calling it on every pageview. Set the
        // throttle BEFORE the call so a slow / erroring backend can't be
        // hammered; on failure the last-known snapshot stays in place. An
        // upgrade / cancel busts the cache directly, so it shows immediately
        // (fail-open on the empty cache) rather than waiting the window out.
        if ( ! get_transient( 'bitbot_widget_plan_throttle' ) ) {
            set_transient( 'bitbot_widget_plan_throttle', 1, 5 * MINUTE_IN_SECONDS );
            $plan = $this->api_client->get_plan_status();
            if ( ! empty( $plan['success'] ) && ! empty( $plan['data'] ) ) {
                BitBot_Plan::store_cached_status( $plan['data'] );
            }
        }

        $res = new WP_REST_Response( $this->build_widget_config() );
        $res->header( 'Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0' );
        $res->header( 'Pragma', 'no-cache' );
        return $res;
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
            'simpleText'   => (string) ( ( $cfg['simpleText'] ?? $cfg['message'] ?? '' ) !== '' ? ( $cfg['simpleText'] ?? $cfg['message'] ) : "Need a hand? I'm here to help." ),
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
        if ( ! in_array( $handle, array( 'bitbot-admin-react', 'bitbot-admin-bridge' ), true ) ) {
            return $tag;
        }
        if ( false !== strpos( $tag, ' type=' ) ) {
            return preg_replace( '/\stype=(["\'])[^"\']*\1/', ' type="module"', $tag );
        }
        return str_replace( '<script ', '<script type="module" ', $tag );
    }

    /**
     * Keep the post kit's related rail from suggesting the post being read.
     *
     * Fires for every Query Loop on the site, so it must identify OUR rail and
     * leave every other query alone — including a listing the owner built that
     * legitimately wants the current post in it. The rail is recognised by the
     * `bb-related-card` class on the group inside its post-template, which is
     * part of the shipped kit markup and is not something a stray query would
     * carry.
     *
     * @param array    $query The query vars the block will run.
     * @param WP_Block $block The query block.
     * @return array
     */
    public function exclude_current_from_related_rail( $query, $block ) {
        if ( ! is_singular() ) {
            return $query;
        }
        $current = get_queried_object_id();
        if ( ! $current ) {
            return $query;
        }
        $parsed = isset( $block->parsed_block ) ? $block->parsed_block : null;
        if ( ! is_array( $parsed ) ) {
            return $query;
        }
        // innerHTML holds only this block's own shell; the card class lives in
        // a descendant, so serialise the parsed tree back to markup and look
        // there. Cheap: this subtree is a handful of blocks.
        $markup = function_exists( 'serialize_blocks' ) ? serialize_blocks( array( $parsed ) ) : '';
        if ( '' === $markup || false === strpos( $markup, 'bb-related-card' ) ) {
            return $query;
        }
        $existing = isset( $query['post__not_in'] ) && is_array( $query['post__not_in'] ) ? $query['post__not_in'] : array();
        $query['post__not_in'] = array_values( array_unique( array_merge( $existing, array( (int) $current ) ) ) );
        return $query;
    }

    public function enqueue_admin_bridge() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        wp_enqueue_script( 'bitbot-admin-bridge', BITBOT_PLUGIN_URL . 'assets/admin/admin-browser-bridge.js', array(), BITBOT_VERSION, true );
        wp_localize_script( 'bitbot-admin-bridge', 'bitbotAdminBridge', array( 'adminBase' => esc_url_raw( admin_url() ) ) );
    }

    public function enqueue_admin_assets( $hook ) {
        // Reuse the admin enqueue hook. The controller must run within the
        // editor's own document when browser isolation prevents parent DOM access.
        if ( current_user_can( 'manage_options' ) && strpos( $hook, 'bitbot' ) === false ) {
            $this->enqueue_admin_bridge();
        }

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
                    // WP current-user display name — greets the owner by name on
                    // the agent home. Never invented backend-side.
                    'ownerName'     => sanitize_text_field( wp_get_current_user()->display_name ),
                    // Admin's WP language — the agent home greets in this locale.
                    'locale'        => sanitize_text_field( get_user_locale() ),
                    // The SITE locale (not the user's): picks the phone example.
                    'siteLocale'    => sanitize_text_field( get_locale() ),
                    // Languages this site speaks (site first); the Voice calls chips.
                    'siteLanguages' => BitBot_Languages::detected(),
                    'plan'          => sanitize_text_field( $current_plan ),
                    // Plugin assets base URL (mascot art for the Agent pitch, etc.).
                    'assetsUrl'     => esc_url_raw( BITBOT_PLUGIN_URL . 'assets/' ),
                    // Real, site-specific hooks for the Agent upsell, from WP ground
                    // truth. Only computed for non-Pro+ (the locked-pitch audience).
                    'agentTeaser'   => ( 'pro_plus' !== $current_plan ) ? $this->build_agent_teaser() : array(),
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
     * Real, site-specific hooks for the Agent upsell ("locked" pitch), computed
     * from WordPress ground truth so the mascot dangles something true about THIS
     * site rather than generic feature bullets. Cheap, bounded queries. Returns a
     * list of one-line findings, most-compelling first; the SPA shows one. Empty
     * is fine — the pitch falls back to a generic line.
     *
     * @since 2.1.169
     * @return string[]
     */
    private function build_agent_teaser() {
        $out = array();

        // Published posts with no featured image — look unfinished on Google.
        $no_thumb = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => 60,
            'no_found_rows'  => true,
            'meta_query'     => array( array( 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' ) ),
        ) );
        $n = count( $no_thumb );
        if ( $n >= 2 ) {
            $out[] = sprintf( 'SCAN: %d of your posts are live with no cover image. On Google they look half finished.', $n );
        }

        $counts = wp_count_posts( 'post' );

        // Weeks since the last published post — quiet sites slide down rankings.
        $latest = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );
        if ( $latest ) {
            $days = (int) floor( ( time() - (int) get_post_time( 'U', true, $latest[0] ) ) / DAY_IN_SECONDS );
            if ( $days >= 21 ) {
                $out[] = sprintf( 'SCAN: your last post went up %d weeks ago. Quiet sites slide down the rankings, and I do not do quiet.', intdiv( $days, 7 ) );
            }
        }

        // Finished drafts sitting unpublished.
        $drafts = isset( $counts->draft ) ? (int) $counts->draft : 0;
        if ( $drafts >= 1 ) {
            $out[] = sprintf( 'SCAN: %d finished draft%s sitting there, unpublished. Live and linked by tonight, if you want.', $drafts, 1 === $drafts ? '' : 's' );
        }

        // Very thin site — depth wins on Google.
        $pub = isset( $counts->publish ) ? (int) $counts->publish : 0;
        if ( $pub > 0 && $pub < 5 ) {
            $out[] = sprintf( 'SCAN: your whole site is %d post%s. Google rewards depth, and I write it fast.', $pub, 1 === $pub ? '' : 's' );
        }

        return array_values( $out );
    }

    /**
     * Register REST API routes
     *
     * @since 1.0.0
     */
    public function register_rest_routes() {
        // rest_api_init runs once WordPress knows this is a REST request, whatever
        // the URL form; the admin handlers must exist before their routes are
        // registered or WordPress sees array(null, 'method') and answers 500.
        if ( ! $this->admin ) {
            $this->admin = new BitBot_Admin( $this->api_client );
        }
        // Widget config — deliberately uncacheable. widget.js fetches this on
        // load (cache-busted) and overrides the inlined bitbotConfig, so a
        // settings change shows immediately even when the page itself is
        // frozen by a full-page cache (LiteSpeed/WP Rocket/CDN). Public — same
        // data already inlined (and public) on every page.
        register_rest_route( 'bitbot/v1', '/widget-config', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_widget_config' ),
            'permission_callback' => '__return_true',
        ) );

        // Last captured PHP fatal (see the shutdown handler in __construct).
        // apiKey-authed server-to-server; lets the agent see the REAL error
        // behind a "critical error on this website" page.
        register_rest_route( 'bitbot/v1', '/last-fatal', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_last_fatal' ),
            'permission_callback' => '__return_true',
        ) );

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

        // Voice call start (public). The widget asks the SITE for a session;
        // the site asks the backend (which holds the Habitat key). The key
        // never reaches the browser.
        register_rest_route( 'bitbot/v1', '/voice-call/start', array(
            'methods'             => 'POST',
            'callback'            => array( $this->widget, 'handle_voice_call_start' ),
            'permission_callback' => '__return_true',
        ) );

        // Citation links of the spoken answer just finished (public, read only).
        register_rest_route( 'bitbot/v1', '/voice-call/links', array(
            'methods'             => 'GET',
            'callback'            => array( $this->widget, 'handle_voice_call_links' ),
            'permission_callback' => '__return_true',
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

        // One id per submission, made by the client; the API stores a submission
        // once per key so a retry after a lost response is safe. Absent is
        // allowed (older pages); present but malformed is refused.
        $submission_key = '';
        if ( isset( $params['submissionKey'] ) ) {
            $submission_key = is_string( $params['submissionKey'] ) ? trim( $params['submissionKey'] ) : '';
            if ( 1 !== preg_match( '/^[A-Za-z0-9-]{16,64}$/', $submission_key ) ) {
                return new WP_REST_Response( array( 'error' => 'Invalid submission key.' ), 400 );
            }
        }

        // The form's own answers, a { fieldName: value } map of EVERY field,
        // including one named "message" (the top-level message is the gate's
        // held chat question or the shortcode's message field). Sanitize each value.
        $answers = array();
        if ( isset( $params['answers'] ) && is_array( $params['answers'] ) ) {
            foreach ( $params['answers'] as $field_key => $field_val ) {
                $key = sanitize_text_field( (string) $field_key );
                if ( '' === $key ) {
                    continue;
                }
                // A checkbox group arrives as a list of chosen options; the
                // API stores one string per answer, so join them.
                if ( is_array( $field_val ) ) {
                    $field_val = implode( ', ', array_map( 'strval', array_filter( $field_val, 'is_scalar' ) ) );
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

        // "Accept submissions" off: refuse here too, not only in the markup.
        // Fails closed: a form that cannot be looked up is not submitted to.
        if ( '' !== $form_id ) {
            $form_res = $this->api_client->get_form( $form_id );
            if ( empty( $form_res['success'] ) || ! isset( $form_res['data'] ) ) {
                return new WP_REST_Response( array( 'error' => 'Could not check this form. Please try again.' ), 502 );
            }
            if ( isset( $form_res['data']['enabled'] ) && ! $form_res['data']['enabled'] ) {
                return new WP_REST_Response( array( 'error' => self::FORM_CLOSED_MESSAGE ), 403 );
            }
        }

        $result = $this->api_client->submit_form( $form_id, $name, $email, $phone, $message, $ip_address, $session_id, $answers, $submission_key );

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
        $success_message = '';
        $form_enabled = true;
        if ( $form_id ) {
            $form_data = $this->api_client->get_form( $form_id );
            if ( $form_data['success'] && isset( $form_data['data']['enabled'] ) && ! $form_data['data']['enabled'] ) {
                $form_enabled = false;
            }
            if ( $form_data['success'] && ! empty( $form_data['data']['fields'] ) ) {
                $fields = $form_data['data']['fields'];
                $success_message = isset( $form_data['data']['successMessage'] ) ? (string) $form_data['data']['successMessage'] : '';
            }
        }

        // No id given: resolve to the site's first available form (a lookup,
        // not a fabrication — the fields still come from a real configured form).
        if ( empty( $fields ) && empty( $form_id ) ) {
            $forms_result = $this->api_client->get_forms();
            if ( $forms_result['success'] && ! empty( $forms_result['data']['forms'] ) ) {
                $first_form = $forms_result['data']['forms'][0];
                $form_id = $first_form['id'];
                $fields = $first_form['fields'];
                if ( isset( $first_form['enabled'] ) && ! $first_form['enabled'] ) {
                    $form_enabled = false;
                }
                $success_message = isset( $first_form['successMessage'] ) ? (string) $first_form['successMessage'] : '';
            }
        }

        if ( ! $form_enabled ) {
            // "Accept submissions" is off: no fields, only a notice.
            return '<p class="bitbot-form-closed">' . esc_html( self::FORM_CLOSED_MESSAGE ) . '</p>';
        }

        if ( empty( $fields ) ) {
            // No configured form could be resolved. Render NOTHING visible — a
            // substitute form here would silently collect submissions into
            // fields nobody configured. The comment makes the failure findable
            // in view-source; logged-in editors also get a visible notice.
            $notice = '';
            if ( current_user_can( 'edit_posts' ) ) {
                $notice = '<div style="border:1px dashed #c00;color:#c00;padding:10px 14px;font-size:13px;">'
                    . esc_html__( 'BitBot form could not be loaded (check the shortcode id or the API connection). Only editors see this notice.', 'bitbot' )
                    . '</div>';
            }
            return '<!-- bitbot_form: no form resolved (id="' . esc_attr( $form_id ) . '") — nothing rendered -->' . $notice;
        }
        $fields = BitBot_Form_Markup::normalize_fields( $fields );
        if ( '' === trim( $success_message ) ) {
            // Backstop only (all forms carry a stored message since migration
            // 131); the visitor must always get SOME confirmation.
            $success_message = __( 'Thank you! We\'ll be in touch soon.', 'bitbot' );
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
        <?php if ( ! self::$form_css_printed ) : self::$form_css_printed = true; ?>
        <style id="bitbot-lead-form-css">
        /* Neutral, theme-cohesive baseline. Typography and colours INHERIT from
           the site's theme; the accent rides a custom property. Everything is
           class-scoped (no per-element inline styles) so the agent's own CSS
           can restyle the form to the site's design without fighting inline
           attributes — the form is a design surface, this is only scaffolding. */
        .bitbot-lead-form{--bb-form-accent:<?php echo esc_html( $primary_color ); ?>;max-width:560px}
        .bitbot-lead-form .bitbot-form-field{margin-bottom:18px}
        .bitbot-lead-form .bitbot-form-field>label{display:block;font-weight:600;margin-bottom:6px;font-size:14px;letter-spacing:.01em}
        .bitbot-lead-form .bitbot-req{color:#c4442a;margin-left:2px}
        .bitbot-lead-form input[type=text],.bitbot-lead-form input[type=email],.bitbot-lead-form input[type=tel],.bitbot-lead-form textarea,.bitbot-lead-form select{width:100%;box-sizing:border-box;padding:11px 13px;font:inherit;font-size:15px;line-height:1.45;color:inherit;background:transparent;border:1px solid rgba(127,127,127,.35);border-radius:8px;transition:border-color .15s ease,box-shadow .15s ease}
        .bitbot-lead-form input:focus,.bitbot-lead-form textarea:focus,.bitbot-lead-form select:focus{outline:none;border-color:var(--bb-form-accent);box-shadow:0 0 0 3px rgba(127,127,127,.18);box-shadow:0 0 0 3px color-mix(in srgb,var(--bb-form-accent) 20%,transparent)}
        .bitbot-lead-form textarea{resize:vertical;min-height:110px}
        .bitbot-lead-form .bitbot-form-choice{display:block;font-weight:400;font-size:14px;margin-bottom:6px}
        .bitbot-lead-form .bitbot-form-message{display:none;padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:14px;line-height:1.5}
        .bitbot-lead-form .bitbot-form-submit{background:var(--bb-form-accent);color:#fff;border:0;padding:13px 26px;border-radius:8px;font:inherit;font-size:15px;font-weight:600;letter-spacing:.01em;cursor:pointer;width:100%;transition:filter .15s ease,transform .05s ease}
        .bitbot-lead-form .bitbot-form-submit:hover{filter:brightness(1.07)}
        .bitbot-lead-form .bitbot-form-submit:active{transform:translateY(1px)}
        .bitbot-lead-form .bitbot-form-submit:disabled{opacity:.6;cursor:default}
        .bitbot-lead-form .bitbot-form-field-msg{color:#c43d3d;font-size:13px;margin:4px 0 0}
        .bitbot-lead-form .bitbot-form-field-msg[hidden]{display:none}
        .bitbot-lead-form fieldset.bitbot-form-field{border:0;padding:0;margin:0 0 18px;min-width:0}
        .bitbot-lead-form .bitbot-form-legend{padding:0;display:block;font-weight:600;margin-bottom:6px;font-size:14px;letter-spacing:.01em}
        .bitbot-lead-form .bitbot-form-field>label.bitbot-form-choice,.bitbot-lead-form .bitbot-form-choice{display:flex;align-items:center;gap:8px;font-weight:400;font-size:14px;margin-bottom:6px}
        .bitbot-lead-form .bitbot-form-choice input{margin:0;flex:none}
        .bitbot-lead-form .bitbot-form-field-error input[type=text],.bitbot-lead-form .bitbot-form-field-error input[type=email],.bitbot-lead-form .bitbot-form-field-error input[type=tel],.bitbot-lead-form .bitbot-form-field-error textarea,.bitbot-lead-form .bitbot-form-field-error select{border-color:#c43d3d}
        </style>
        <?php endif; ?>
        <div id="<?php echo esc_attr( $wrap_id ); ?>" class="bitbot-lead-form">
            <!-- onsubmit="return false" is a defensive backstop: even if our
                 JS fails to load/run (CSP, theme strip, etc.), the form will
                 not navigate to a broken GET URL with field values in the
                 query string. -->
            <form id="<?php echo esc_attr( $form_dom_id ); ?>" class="bitbot-lead-form-el" novalidate onsubmit="return false;">
                <input type="hidden" name="bitbot_submission_key" value="">
                <?php echo BitBot_Form_Markup::render_fields( $fields, 'bitbot-field' . $suffix ); // Escaped inside. ?>

                <div class="bitbot-form-message"></div>

                <button type="submit" class="bitbot-form-submit">
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

                // Same field handling as the chat gate (assets/js/widget-form.js):
                // required checked per type, a visible message per field with
                // aria-invalid and aria-describedby, cleared on the next input.
                // Checkbox groups submit the chosen options joined with ", ".
                var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
                var MSG = {
                    name: '<?php echo esc_js( __( 'Please enter your name.', 'bitbot' ) ); ?>',
                    email: '<?php echo esc_js( __( 'Please enter a valid email address.', 'bitbot' ) ); ?>',
                    required: '<?php echo esc_js( __( 'This field is required.', 'bitbot' ) ); ?>',
                    choose: '<?php echo esc_js( __( 'Please choose an option.', 'bitbot' ) ); ?>',
                    chooseAny: '<?php echo esc_js( __( 'Please choose at least one option.', 'bitbot' ) ); ?>'
                };
                function rowEl(name) {
                    return form.querySelector('[data-field-row="' + name + '"]');
                }
                function controls(row) {
                    return row.querySelectorAll('[data-field]');
                }
                function showFieldError(name, message) {
                    var row = rowEl(name);
                    if (!row) return false;
                    row.classList.add('bitbot-form-field-error');
                    var err = row.querySelector('.bitbot-form-field-msg');
                    if (err) { err.textContent = message; err.hidden = false; }
                    var cs = controls(row);
                    for (var i = 0; i < cs.length; i++) {
                        cs[i].setAttribute('aria-invalid', 'true');
                        if (err) cs[i].setAttribute('aria-describedby', err.id);
                    }
                    if (err ? row.getAttribute('data-field-type') : false) row.setAttribute('aria-describedby', err.id);
                    return true;
                }
                function clearFieldError(name) {
                    var row = rowEl(name);
                    if (!row) return;
                    row.classList.remove('bitbot-form-field-error');
                    var err = row.querySelector('.bitbot-form-field-msg');
                    if (err) { err.textContent = ''; err.hidden = true; }
                    var cs = controls(row);
                    for (var i = 0; i < cs.length; i++) {
                        cs[i].removeAttribute('aria-invalid');
                        cs[i].removeAttribute('aria-describedby');
                    }
                    row.removeAttribute('aria-describedby');
                }
                function clearAllFieldErrors() {
                    form.querySelectorAll('.bitbot-form-field-error').forEach(function(r) {
                        clearFieldError(r.getAttribute('data-field-row'));
                    });
                }
                function onChange(ev) {
                    var n = ev.target && ev.target.getAttribute ? ev.target.getAttribute('data-field') : null;
                    if (n) clearFieldError(n);
                }
                form.addEventListener('input', onChange);
                form.addEventListener('change', onChange);

                function readRow(row) {
                    var type = row.getAttribute('data-field-type');
                    var cs = row.querySelectorAll('[data-field]');
                    if (type === 'checkbox') {
                        var picked = [];
                        for (var i = 0; i < cs.length; i++) { if (cs[i].checked) picked.push(cs[i].value); }
                        return picked;
                    }
                    if (type === 'radio') {
                        for (var j = 0; j < cs.length; j++) { if (cs[j].checked) return cs[j].value; }
                        return '';
                    }
                    return cs.length ? String(cs[0].value).trim() : '';
                }

                // The idempotency key is made in the browser, per form instance: a
                // key printed by PHP would be shared by every visitor a page cache
                // serves, and the second visitor's enquiry would be answered as a
                // duplicate of the first. Kept across retries; replaced after success.
                function newSubmissionKey() {
                    var nb = new Uint8Array(16);
                    if (window.crypto && window.crypto.getRandomValues) { window.crypto.getRandomValues(nb); }
                    else { for (var i = 0; i < 16; i++) { nb[i] = Math.floor(Math.random() * 256); } }
                    return Array.prototype.map.call(nb, function(x) { return (x < 16 ? '0' : '') + x.toString(16); }).join('');
                }
                (function() {
                    var k = form.querySelector('input[name="bitbot_submission_key"]');
                    if (k && !k.value) k.value = newSubmissionKey();
                })();
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var btn = wrap.querySelector('.bitbot-form-submit');
                    var msg = wrap.querySelector('.bitbot-form-message');
                    if (btn.disabled) return;
                    msg.style.display = 'none';
                    clearAllFieldErrors();

                    var keyEl = form.querySelector('input[name="bitbot_submission_key"]');
                    var data = { formId: '<?php echo esc_js( $form_id ); ?>', name: '', email: '', phone: '', submissionKey: keyEl ? keyEl.value : '' };
                    // name, email, phone and message also go top-level (the
                    // backend has dedicated columns for them); EVERY field, those
                    // included, is also kept under `answers` keyed by its name.
                    var answers = {};
                    var problems = [];
                    form.querySelectorAll('[data-field-row]').forEach(function(row) {
                        var key = row.getAttribute('data-field-row');
                        var type = row.getAttribute('data-field-type');
                        var first = row.querySelector('[data-field]');
                        var required = first ? first.getAttribute('aria-required') === 'true' : false;
                        if (type ? row.getAttribute('aria-required') === 'true' : false) required = true;
                        var v = readRow(row);
                        var empty = Array.isArray(v) ? v.length === 0 : v === '';
                        var text = Array.isArray(v) ? v.join(', ') : v;
                        if (key === 'name' ? empty : false) { problems.push([key, MSG.name]); return; }
                        if (key === 'email' ? true : (first ? first.type === 'email' : false)) {
                            if (empty ? required : !EMAIL_RE.test(text)) { problems.push([key, MSG.email]); return; }
                        }
                        if (required ? empty : false) {
                            problems.push([key, type === 'checkbox' ? MSG.chooseAny : (type === 'radio' || (first ? first.tagName === 'SELECT' : false)) ? MSG.choose : MSG.required]);
                            return;
                        }
                        if (key === 'name' || key === 'email' || key === 'phone') { data[key] = text; }
                        else if (key === 'message') { data.message = text; }
                        if (text !== '') { answers[key] = text; }
                    });
                    data.answers = answers;

                    // Client-side validation BEFORE we burn a round-trip.
                    // Server still re-checks via is_email() so direct API
                    // hits can't bypass.
                    if (problems.length) {
                        problems.forEach(function(pr) { showFieldError(pr[0], pr[1]); });
                        var bad = rowEl(problems[0][0]);
                        var target = bad ? bad.querySelector('[data-field]') : null;
                        if (target) target.focus();
                        return;
                    }

                    btn.disabled = true;
                    btn.textContent = '<?php echo esc_js( __( 'Submitting…', 'bitbot' ) ); ?>';

                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '<?php echo esc_url( rest_url( 'bitbot/v1/form-submit' ) ); ?>');
                    xhr.setRequestHeader('Content-Type', 'application/json');
                    xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce( 'wp_rest' ); ?>');
                    xhr.onload = function() {
                        btn.disabled = false;
                        btn.textContent = '<?php echo esc_js( __( 'Submit', 'bitbot' ) ); ?>';
                        // wptexturize on the_content converts '&&' to HTML
                        // entities, breaking JS parse. Use a guarded ternary
                        // form that avoids the operator entirely.
                        if (xhr.status >= 200 ? xhr.status < 300 : false) {
                            msg.style.display = 'block';
                            msg.style.background = '#edfaef';
                            msg.style.color = '#00a32a';
                            msg.textContent = '<?php echo esc_js( $success_message ); ?>';
                            form.reset();
                            // A new submission gets a new key (reset restores the old one).
                            if (keyEl) { keyEl.value = newSubmissionKey(); }
                        } else {
                            var errMsg = '<?php echo esc_js( __( 'Something went wrong. Please try again.', 'bitbot' ) ); ?>';
                            var fieldName = null;
                            try {
                                var resp = JSON.parse(xhr.responseText);
                                if (resp.error) errMsg = resp.error;
                                if (resp.field) fieldName = resp.field;
                            } catch(e) {}
                            // If server told us which field — highlight it
                            // and skip the top banner. Otherwise fall back
                            // to the banner so the visitor sees SOMETHING.
                            if (fieldName ? !!rowEl(fieldName) : false) {
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
                        btn.textContent = '<?php echo esc_js( __( 'Submit', 'bitbot' ) ); ?>';
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
    /**
     * Is this request the agent's preview frame? A front-end request carrying
     * ?bb_view=1 made by a logged-in ADMINISTRATOR — i.e. the owner's own browser
     * with the BitBot admin open in another frame.
     *
     * The capability check is the whole security of everything below it. The
     * earlier version of this keyed on the query parameter ALONE, which made
     * `?bb_view=1` a public switch for "serve this page uncached" — a free
     * cache-bust for anyone who found it. Nothing here is reachable without an
     * administrator session.
     *
     * @since 2.1.259
     * @return bool
     */
    private function is_preview_frame_request() {
        if ( is_admin() || empty( $_GET['bb_view'] ) ) {
            return false;
        }
        return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
    }

    /**
     * Let the BitBot admin frame the front-end, and show it the CURRENT page.
     *
     * A hardened host or security plugin sets X-Frame-Options: DENY or a
     * restrictive frame-ancestors, which silently breaks the agent's eyes. We
     * relax it to `frame-ancestors 'self'` — same-origin only, never a third
     * party — and bypass page caches, because a preview of the page as it was
     * BEFORE the agent's edit is worse than no preview: the agent looks, sees
     * stale content, and concludes its change did not land.
     *
     * @since 2.1.259
     */
    public function maybe_relax_preview_framing() {
        if ( ! $this->is_preview_frame_request() ) {
            return;
        }
        if ( function_exists( 'header_remove' ) ) {
            header_remove( 'X-Frame-Options' );
        }
        header( "Content-Security-Policy: frame-ancestors 'self'" );
        header( 'X-Robots-Tag: noindex, nofollow' );
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        do_action( 'litespeed_control_set_nocache', 'bitbot live preview' );
    }

    /**
     * Tell the frame what HTTP status this page really returned.
     *
     * An iframe navigation exposes NO status to the script that measures it, and
     * WordPress serves a full themed page for a missing URL — so without this a
     * soft 404 reads as a working page, which is exactly the blindness the old
     * loopback render existed to prevent. WordPress knows the answer from inside;
     * it just has to say so.
     *
     * @since 2.1.259
     */
    public function maybe_emit_preview_status() {
        if ( ! $this->is_preview_frame_request() ) {
            return;
        }
        $code = function_exists( 'http_response_code' ) ? (int) http_response_code() : 0;
        if ( $code <= 0 ) {
            $code = is_404() ? 404 : 200;
        }
        echo '<meta name="bitbot-render-status" content="' . esc_attr( (string) $code ) . '">' . "\n";
    }

    /**
     * No admin bar in the preview frame.
     *
     * Two reasons, and the first is security: the bar is a strip of
     * nonce-carrying links into the owner's own authenticated session, and the
     * agent can ask for a click. Removing it removes that surface entirely
     * (frame-level click interception in the panel is the second layer, not the
     * only one). The second reason is measurement: a 32px bar shifts every box on
     * the page, so geometry read with it present is wrong.
     *
     * @since 2.1.259
     * @param bool $show
     * @return bool
     */
    public function maybe_hide_admin_bar_in_preview( $show ) {
        return $this->is_preview_frame_request() ? false : $show;
    }

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
