import { HIDDEN_CHIPS, OWNER_CHIP_LABELS } from './toolChipLabels.generated'

/**
 * Format an absolute ISO timestamp as a relative-time string.
 * "5m ago" / "2h ago" / "3d ago" / "Jun 14" / "Jun 14 2024" depending
 * on age. Returns "just now" for anything < 60 seconds.
 */
export function relativeTime(iso: string | null | undefined): string {
  if (!iso) return ''
  const ts = new Date(iso).getTime()
  if (Number.isNaN(ts)) return ''
  const diff = Date.now() - ts
  if (diff < 60_000) return 'just now'
  const minutes = Math.floor(diff / 60_000)
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h ago`
  const days = Math.floor(hours / 24)
  if (days < 30) return `${days}d ago`
  const d = new Date(ts)
  const sameYear = d.getFullYear() === new Date().getFullYear()
  return d.toLocaleDateString(undefined, sameYear ? { month: 'short', day: 'numeric' } : { month: 'short', day: 'numeric', year: 'numeric' })
}

/**
 * Format a number with thousand-separators. Falls back to the raw value
 * if the value is `null` / `undefined` (renders as empty string).
 */
export function formatNumber(n: number | null | undefined): string {
  if (n === null || n === undefined || Number.isNaN(n)) return ''
  return n.toLocaleString()
}

/**
 * Truncate a string to `max` chars, appending an ellipsis if cut.
 * Word-boundary aware: cuts at the last space within the budget.
 */
export function truncate(text: string, max = 100): string {
  if (text.length <= max) return text
  const slice = text.slice(0, max)
  const lastSpace = slice.lastIndexOf(' ')
  return (lastSpace > max * 0.6 ? slice.slice(0, lastSpace) : slice) + '…'
}

/**
 * Convert an internal lead `source` slug into a user-friendly label
 * for the dashboard. The backend writes machine-readable values like
 * "chat_kyc", "chat_handover", "chat_support" depending on which
 * tool created the lead — the admin shouldn't see those. Unknown
 * values fall through humanised: underscores → spaces, title-cased.
 */
/**
 * Map an internal tool name (the slug the model sees and calls) to the chip
 * shown in the Agent chat transcript, in owner words. Returns `null` to hide
 * the chip entirely (the agent's own plumbing: loading a capability, picking
 * its tools).
 *
 * ONE list: every label comes from the API's generated file
 * (packages/api/src/lib/toolChipLabels.ts `toolChipLabels`; round 9 still showed
 * "View Site" and "Get Theme Patterns"). A tool the generated file does not
 * know (a build older than the API) falls through to its slug in words, so a
 * new tool never crashes the transcript.
 */
export function formatToolChip(toolName: string | null | undefined): string | null {
  if (!toolName) return null
  if (HIDDEN_CHIPS.includes(toolName)) return null
  if (toolName in OWNER_CHIP_LABELS) return OWNER_CHIP_LABELS[toolName]
  return toolName
    .replace(/([A-Z])/g, ' $1')
    .replace(/^./, (c) => c.toUpperCase())
    .trim()
}

/** Channel → display label + emoji. Used everywhere we badge attribution. */
export function formatLeadChannel(channel: string | null | undefined): { label: string; icon: string } {
  switch (channel) {
    case 'messenger':    return { label: 'Messenger', icon: '📨' }
    case 'instagram_dm': return { label: 'Instagram', icon: '📷' }
    case 'whatsapp':     return { label: 'WhatsApp',  icon: '🟢' }
    case 'telegram':     return { label: 'Telegram',  icon: '✈️' }
    case 'sms':          return { label: 'SMS',       icon: '✉️' }
    case 'discord':      return { label: 'Discord',   icon: '💜' }
    case 'form':         return { label: 'Form',      icon: '📝' }
    case 'email':        return { label: 'Support email', icon: '✉️' }
    case 'web_widget':
    default:             return { label: 'Web chat',  icon: '💬' }
  }
}

export function formatLeadSource(source: string | null | undefined): string {
  if (!source) return 'Chat'
  const known: Record<string, string> = {
    chat: 'Chat',
    chat_kyc: 'Chat',
    chat_handover: 'Chat',
    chat_appointment: 'Chat',
    chat_live: 'Chat',
    chat_support: 'Chat',
    chat_qualified: 'Chat',
    form: 'Form',
  }
  if (known[source]) return known[source]
  return source
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase())
}
