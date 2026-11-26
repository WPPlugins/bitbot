/**
 * Telemetry — thin wrapper around PostHog for event capture + session
 * replay + exception logging. Optional: if `bitbotAdminConfig.posthogKey`
 * is empty, every call short-circuits to a console log instead.
 *
 * Why PostHog: free tier is generous (1M events, 5k recordings/mo),
 * built-in session replay (rrweb) for debugging visual issues, and
 * no PII leakage by default — we explicitly opt fields out via
 * `data-ph-no-capture` when we want them masked.
 *
 * Sensitive fields are masked at the DOM level (the apiKey input + the
 * notificationEmail input both carry data-ph-no-capture).
 *
 * What gets captured automatically once initialised:
 *  - pageviews ($pageview)
 *  - element clicks (buttons, links, custom rrweb capture)
 *  - rage-clicks + dead clicks
 *  - JS exceptions via window.onerror + Promise rejections
 *  - the full session replay (DOM mutations + clicks + scrolls + console)
 *
 * What we capture explicitly via captureEvent():
 *  - rpc_failure, rpc_success_slow (>2s), mutation_failure
 *  - admin_loaded, tab_changed
 *  - take_over_chat, reply_sent, conversation_closed
 *
 * All events automatically include super-properties: { plan, plugin_version,
 * site_domain } so you can filter by plan tier or version.
 */
import { getConfig } from '@/lib/config'
import type posthogType from 'posthog-js'

// Lazy-loaded — only fetched + initialised when a real key is configured.
// Saves ~65 KB gzipped on sites that haven't opted into telemetry yet.
let posthog: typeof posthogType | null = null
let initialized = false

/**
 * Initialise PostHog. Idempotent — safe to call multiple times.
 * No-op if the posthog key isn't configured. SDK is dynamic-imported so
 * the bundle stays slim for sites that don't use telemetry.
 */
export function initTelemetry() {
  if (initialized) return
  initialized = true

  const cfg = (() => { try { return getConfig() } catch { return null } })()
  if (!cfg) return

  // Strictly opt-in: no key, no SDK, no events unless the administrator
  // switched analytics on. The server also withholds the key until then.
  if (!cfg.analyticsOptIn) return

  const key = cfg.posthogKey ?? ''
  const host = cfg.posthogHost ?? 'https://us.i.posthog.com'

  if (!key) {
    // No key configured → log a one-time notice and operate in
    // console-only fallback. Events still emit through captureEvent
    // (visible in DevTools) so the surface is debuggable in dev.
    // eslint-disable-next-line no-console
    console.info('[Youami telemetry] No PostHog key configured. Events log to console only.')
    return
  }

  // Lazy-import the SDK so it only ships to sites with telemetry on.
  import('posthog-js').then((mod) => {
    posthog = mod.default
    posthog.init(key, {
      api_host: host,
      autocapture: true,
      capture_pageview: true,
      capture_pageleave: true,
      // Session replay — the headline feature for debugging.
      session_recording: {
        maskAllInputs: false,
        // Mask elements explicitly tagged sensitive (api key input, etc).
        maskTextSelector: '[data-ph-mask]',
      },
      // DNT off: this SDK only instruments the *site owner's own* admin
      // dashboard (never their visitors), and the owner has opted into the
      // product. Honouring DNT here just silently blackholes our own support
      // telemetry for privacy-browser owners (e.g. freehomeoce.com sent zero
      // events despite loading the dashboard). Visitor-side carries no PostHog.
      respect_dnt: false,
      persistence: 'localStorage',
      loaded: (ph) => {
        const domain = safeHost(cfg.siteUrl)
        const environment = detectEnvFromApiUrl(cfg.apiUrl)
        ph.identify(domain, {
          site_url: cfg.siteUrl,
          plan: cfg.plan,
          plugin_version: cfg.pluginVersion,
        })
        // Super-properties — attached to EVERY subsequent event including
        // autocaptures + session replays. `environment` lets you filter
        // PostHog by env=prod vs env=dev so dev-site noise doesn't pollute
        // prod customer dashboards (and vice versa).
        ph.register({
          plan: cfg.plan,
          plugin_version: cfg.pluginVersion,
          site_domain: domain,
          environment,
        })
        // Flush any events that fired before the SDK finished loading.
        for (const ev of pendingEvents) ph.capture(ev.name, ev.props)
        pendingEvents.length = 0
      },
    })
  }).catch((err) => {
    // eslint-disable-next-line no-console
    console.warn('[Youami telemetry] PostHog SDK failed to load:', err)
  })
}

// Buffer for events fired before the SDK finished its dynamic import.
// Replayed once posthog is loaded; capped to avoid unbounded growth if
// the SDK never loads (e.g. ad-blocker, network failure).
const pendingEvents: Array<{ name: string; props?: Record<string, unknown> }> = []
const PENDING_CAP = 50

/**
 * Capture a custom event. Falls back to console.log when PostHog isn't
 * initialised (no key configured) so the surface is debuggable in dev.
 */
export function captureEvent(name: string, properties?: Record<string, unknown>) {
  // Always log to console in addition to PostHog — gives the developer
  // immediate feedback in DevTools regardless of telemetry config.
  // eslint-disable-next-line no-console
  console.log(`[Youami telemetry] ${name}`, properties ?? {})

  if (posthog) {
    posthog.capture(name, properties)
  } else if (hasKey() && pendingEvents.length < PENDING_CAP) {
    // Buffer until SDK finishes its dynamic import.
    pendingEvents.push({ name, props: properties })
  }
}

/**
 * Capture an exception with optional metadata. Always logs to console
 * too — PostHog's $exception event is great for aggregation but the
 * console is what shows up in the browser dev-tools where the user is
 * actually debugging.
 */
export function captureException(err: unknown, context?: Record<string, unknown>) {
  const message = err instanceof Error ? err.message : String(err)
  const stack = err instanceof Error ? err.stack : undefined

  // eslint-disable-next-line no-console
  console.error('[Youami exception]', message, { context, stack })

  if (posthog) {
    posthog.capture('$exception', {
      $exception_message: message,
      $exception_type: err instanceof Error ? err.name : 'Unknown',
      $exception_stack_trace_raw: stack,
      ...context,
    })
  } else if (hasKey() && pendingEvents.length < PENDING_CAP) {
    pendingEvents.push({
      name: '$exception',
      props: {
        $exception_message: message,
        $exception_type: err instanceof Error ? err.name : 'Unknown',
        $exception_stack_trace_raw: stack,
        ...context,
      },
    })
  }
}

function hasKey(): boolean {
  try {
    const c = getConfig()
    return !!c.analyticsOptIn && !!c.posthogKey
  } catch {
    return false
  }
}

function safeHost(url: string): string {
  try { return new URL(url).host } catch { return url }
}

/**
 * Derive environment from the API URL the plugin is talking to.
 *
 *   dev.youami.ai   → 'dev'
 *   youami.ai       → 'prod'
 *   localhost / 127 → 'local'
 *   anything else   → 'other' (preview branches, custom deployments)
 */
function detectEnvFromApiUrl(apiUrl: string): string {
  try {
    const host = new URL(apiUrl).host
    if (host.startsWith('dev.'))     return 'dev'
    if (host === 'youami.ai')        return 'prod'
    if (host.startsWith('localhost') || host.startsWith('127.')) return 'local'
    return 'other'
  } catch {
    return 'other'
  }
}

// Underlying posthog instance — null until the dynamic import resolves.
// Use captureEvent / captureException above instead of touching this
// directly unless you need a method not exposed by the helpers.
export function getPosthog(): typeof posthogType | null {
  return posthog
}
