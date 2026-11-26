/**
 * Row states and filter chips for the Conversations list.
 *
 * The Needs reply chip shows the rows the server flagged `needsOwner`. That
 * flag comes from the same function the server uses for the chip's count and
 * for the menu and tab badges (api lib/conversationAttention), so the badge,
 * the chip and the rows under it always agree.
 */
import type { ConversationSummary } from '@/api/types'

export type RowState = 'live-admin' | 'live-bot' | 'needs-human' | 'idle' | 'ended' | 'open'

export type ConversationFilter = 'all' | 'live' | 'needs' | 'past'

type RowFields = Pick<ConversationSummary, 'channel' | 'liveMode' | 'unreadAdminCount' | 'presence'>

/**
 * Distinguish "admin is in this chat" from "bot escalated, no one home yet"
 * using `unread_admin_count`: visitor messages awaiting an admin response.
 *
 * Both `startLiveThread` (bot-initiated escalation) and `AdminTakeOverChat`
 * (explicit takeover) write `live_mode=true, unread_admin_count=0`. When the
 * visitor messages back, `add_conversation_message` increments
 * `unread_admin_count`. When admin replies, `AdminReplyLive` resets it to 0.
 *
 * So `live_mode=true && unread_admin_count > 0` is the truthful "you owe the
 * visitor a reply" state, and `live_mode=true && unread_admin_count === 0`
 * is "admin engaged and caught up".
 */
export function deriveRowState(c: RowFields): RowState {
  const isAsync = c.channel === 'messenger' || c.channel === 'instagram_dm' || c.channel === 'email'
  if (c.liveMode) {
    // Visitor wrote back, so the admin owes a reply, on every channel.
    if (c.unreadAdminCount > 0) return 'needs-human'
    // Caught up. Web shows 'Live · You'; async inboxes stay 'Open'.
    return isAsync ? 'open' : 'live-admin'
  }
  // Async, no live_mode: always 'Open'. Inbox is takeable any time.
  if (isAsync) return 'open'
  if (c.presence === 'present') return 'live-bot'
  if (c.presence === 'idle') return 'idle'
  return 'ended'
}

export function matchesFilter(c: RowFields & Pick<ConversationSummary, 'needsOwner'>, filter: ConversationFilter): boolean {
  if (filter === 'all') return true
  const state = deriveRowState(c)
  // An API older than this flag sends none: fall back to its old rule.
  if (filter === 'needs') return c.needsOwner ?? state === 'needs-human'
  if (filter === 'live') return state === 'live-admin' || state === 'live-bot' || state === 'open'
  return state === 'idle' || state === 'ended'
}
