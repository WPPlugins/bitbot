/**
 * WidgetStatusBanner — surfaces "chatbot isn't actually visible to
 * your visitors" states on the Overview tab.
 *
 * Reads two flags shipped by PHP via bitbotAdminConfig:
 *
 *   widgetAccessExpired — trial / subscription lapsed
 *   widgetEnabled       — the Chatbot tab toggle (bitbot_enabled option)
 *
 * Decision tree (first match wins, banner shows; if none match, render
 * nothing):
 *
 *   1. accessExpired → "Widget hidden until you subscribe"
 *   2. !enabled      → "Chat turned off in settings"
 *
 * Note: we used to also derive a "widget script not reaching visitors"
 * state from `widgetEnqueuedAt` + `widgetLastSeenAt`, but it false-
 * positived too often (low-traffic sites, pre-warmed sandbox installs)
 * to be worth the nag. The two cases we DO surface are admin-toggled
 * state, so they can't be wrong.
 */
import { getConfig } from '@/lib/config'

type Status =
  | { kind: 'ok' }
  | { kind: 'expired' }
  | { kind: 'disabled' }

// wp_localize_script ships array values as strings: PHP `(bool) true`
// becomes "1", `(bool) false` becomes "". Coerce explicitly so the
// truthiness checks don't silently misbehave when WP changes the
// serializer.
function asBool(v: unknown): boolean {
  if (typeof v === 'boolean') return v
  if (typeof v === 'number') return v !== 0
  if (typeof v === 'string') return v !== '' && v !== '0'
  return false
}

function deriveStatus(): Status {
  const cfg = getConfig()
  if (asBool(cfg.widgetAccessExpired)) return { kind: 'expired' }
  if (!asBool(cfg.widgetEnabled)) return { kind: 'disabled' }
  return { kind: 'ok' }
}

export function WidgetStatusBanner() {
  const status = deriveStatus()
  if (status.kind === 'ok') return null

  if (status.kind === 'expired') {
    return (
      <div className="bb-widget-status bb-widget-status-warn" role="status">
        <div className="bb-widget-status-icon" aria-hidden>!</div>
        <div className="bb-widget-status-body">
          <strong>Widget hidden on your site.</strong>{' '}
          Your subscription has ended. Reactivate to bring the chatbot back online for visitors.
        </div>
      </div>
    )
  }

  // status.kind === 'disabled'
  return (
    <div className="bb-widget-status bb-widget-status-info" role="status">
      <div className="bb-widget-status-icon" aria-hidden>!</div>
      <div className="bb-widget-status-body">
        <strong>Chat is turned off.</strong>{' '}
        Visitors won't see the widget until you flip it back on from the Chatbot tab.
      </div>
    </div>
  )
}
