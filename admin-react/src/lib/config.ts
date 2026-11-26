/**
 * Admin config shipped via wp_localize_script. On WP admin this is set
 * by bitbot.php:enqueue_admin_assets when ?bitbot_new_admin=1 is present.
 * For standalone dev (vite dev server) it's set inline in index.html.
 */
export interface BitBotAdminConfig {
  apiKey: string
  apiUrl: string
  restUrl: string
  nonce: string
  ajaxUrl: string
  ajaxNonce: string
  upgradeUrl: string
  pluginVersion: string
  siteUrl: string
  /** WP admin email — default reply-from for the AI Support Inbox. */
  adminEmail?: string
  /** WP current-user display name — used to greet the owner by name on the
   *  agent home. Never invented; absent if the SPA loaded without it. */
  ownerName?: string
  /** WP admin locale (e.g. "en_US", "el"). The agent home is written in this
   *  language. Falls back to navigator.language when absent. */
  locale?: string
  /** WP SITE locale (e.g. "en_IN"). Picks the phone number example. */
  siteLocale?: string
  /** Languages the WP site speaks (site language first), two-letter codes. */
  siteLanguages?: string[]
  /** Plugin assets base URL (ends with "assets/"). Mascot art lives under
   *  `${assetsUrl}mascot/bitbot-*.webp`. */
  assetsUrl?: string
  /** Real, site-specific one-line hooks for the Agent upsell, from WP ground
   *  truth. Only populated on non-Pro+ sites; empty otherwise. */
  agentTeaser?: string[]
  plan: Plan
  /** True once the administrator connected this site to youami.ai. A fresh
   *  install is false until Connect is pressed. */
  connected?: boolean
  /** True once the administrator switched on anonymous usage analytics. */
  analyticsOptIn?: boolean
  /** Optional PostHog project key (phc_...). Empty disables telemetry. */
  posthogKey?: string
  /** PostHog ingestion host. Defaults to us.i.posthog.com when omitted. */
  posthogHost?: string
  /** 2.0.12 widget-health flags consumed by Overview's
   *  WidgetStatusBanner. Server-side reality at the moment the admin
   *  loaded the SPA — no backend round-trip needed.
   *
   *  widgetEnabled:        bitbot_enabled option (admin toggle)
   *  widgetAccessExpired:  trial / subscription ended
   *  widgetEnqueuedAt:     unix seconds of last successful enqueue
   *  widgetLastSeenAt:     unix seconds of last visitor-browser ping */
  widgetEnabled?: boolean
  widgetAccessExpired?: boolean
  widgetEnqueuedAt?: number
  widgetLastSeenAt?: number
}

export type Plan = 'free' | 'pro' | 'pro_plus'

declare global {
  interface Window {
    bitbotAdminConfig?: BitBotAdminConfig
  }
}

/**
 * Resolve config at runtime; returns a stable object even if window is
 * missing the global (degrades to "config not provided" which UI surfaces
 * as a configuration error rather than throwing).
 */
export function getConfig(): BitBotAdminConfig {
  const c = window.bitbotAdminConfig
  if (!c) {
    throw new Error(
      'bitbotAdminConfig is missing. Ensure wp_localize_script ran ' +
      '(WP admin) or that index.html sets window.bitbotAdminConfig (dev).',
    )
  }
  return c
}

/**
 * Customer-facing tier label. Internal DB values stay 'free' / 'pro' /
 * 'pro_plus'; this maps to the names visitors / admins actually see.
 */
export function planLabel(plan: Plan): string {
  if (plan === 'pro_plus') return 'Agent'
  if (plan === 'pro') return 'Assistant'
  return 'Free'
}

/**
 * True if the plan unlocks the customisation surface (Feature 8) — i.e.
 * any paying tier. Mirrors PHP's BitBot_Plan::is_paid().
 */
export function isPaid(plan: Plan): boolean {
  return plan === 'pro' || plan === 'pro_plus'
}

/**
 * True only for the top tier (Agent). Mirrors PHP's
 * BitBot_Plan::is_pro_plus().
 */
export function isAgent(plan: Plan): boolean {
  return plan === 'pro_plus'
}
