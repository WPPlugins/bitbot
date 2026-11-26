/**
 * ActivityFeed — mixed event timeline (leads + conversations) shown
 * in the 8-col left side of the "01. What's happening" row on the
 * Overview tab.
 *
 * Each event row: typed icon + title + snippet + relative time.
 *
 * Data sources (real now — was returning [] in prod before):
 *   - GetLeads          → most recent leads (filtered to chat / form)
 *   - GetConversations  → most recent conversations
 *   - Merged client-side, sorted by createdAt desc, top 10.
 */
import { useQuery } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'
import { getConfig } from '@/lib/config'
import { relativeTime, truncate, formatLeadSource } from '@/lib/utils'
import type { LeadInfo, ConversationSummary } from '@/api/types'

type EventKind = 'lead' | 'conversation' | 'form'
interface ActivityEvent {
  id: string
  kind: EventKind
  title: string
  snippet: string
  createdAt: string
}

const FEED_LIMIT = 10
const SEVEN_DAYS_MS = 7 * 24 * 60 * 60 * 1000

const DEV_MOCK_EVENTS: ActivityEvent[] = [
  {
    id: '1',
    kind: 'lead',
    title: 'New lead — maria.silva@email.com asked about photography rates',
    snippet: '"Olá, gostaria de saber valores para evento corporativo no Rio em julho..."',
    createdAt: new Date(Date.now() - 3 * 60 * 1000).toISOString(),
  },
  {
    id: '2',
    kind: 'conversation',
    title: 'Conversation — visitor on /servicos/casamentos',
    snippet: '"vocês fazem casamentos em destination weddings? Qual a logística?" — 6 turns, resolved',
    createdAt: new Date(Date.now() - 12 * 60 * 1000).toISOString(),
  },
]

const ICON: Record<EventKind, React.ReactNode> = {
  lead: (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.5">
      <path d="M4 4h16v16H4z" />
      <path d="m4 7 8 5 8-5" />
    </svg>
  ),
  conversation: (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.5">
      <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
    </svg>
  ),
  form: (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.5">
      <rect x="4" y="3" width="16" height="18" rx="2" />
      <path d="M8 8h8M8 12h8M8 16h5" />
    </svg>
  ),
}

function leadToEvent(lead: LeadInfo): ActivityEvent {
  // Source can be 'chat', a 'chat_*' tool slug (chat_kyc, chat_handover,
  // etc.), 'form', or a custom form name. Anything that starts with
  // 'chat' is a chat-driven capture; everything else is a form
  // submission. The backend stamps the form name into `source` when
  // available, which makes the title more useful than just "form".
  const isChat = !lead.source || lead.source === 'chat' || lead.source.startsWith('chat_')
  const isForm = !isChat
  const who = lead.name || lead.email || 'A visitor'
  const sourceTag = isForm ? `via ${formatLeadSource(lead.source)}` : 'from chat'
  return {
    id: `lead:${lead.id}`,
    kind: isForm ? 'form' : 'lead',
    title: `New lead — ${who} ${sourceTag}`,
    snippet: lead.message
      ? `"${lead.message}"`
      : lead.email
        ? `Email captured: ${lead.email}`
        : 'Lead captured',
    createdAt: lead.createdAt,
  }
}

function conversationToEvent(conv: ConversationSummary): ActivityEvent {
  const where = conv.visitorCity && conv.visitorCountry
    ? `${conv.visitorCity}, ${conv.visitorCountry}`
    : conv.visitorCountry || null
  // displayLabel may be missing/empty in the backend response for
  // anonymous visitors without geo. Fall back to email → name →
  // generic "Anonymous visitor" before letting "undefined" slip in.
  const who =
    (conv.displayLabel && conv.displayLabel.trim()) ||
    conv.visitorEmail ||
    conv.visitorName ||
    'Anonymous visitor'
  const liveTag = conv.liveMode ? ' · LIVE' : ''
  return {
    id: `conv:${conv.id}`,
    kind: 'conversation',
    title: `Conversation — ${who}${liveTag}`,
    snippet: where ? `From ${where}` : 'Location not available',
    createdAt: conv.lastMessageAt,
  }
}

export function ActivityFeed() {
  const cfg = getConfig()
  const { data, isLoading } = useQuery({
    queryKey: ['overview-activity'],
    queryFn: async (): Promise<ActivityEvent[]> => {
      if (cfg.apiKey === 'bb_devmockapikey0000000000000000') {
        return DEV_MOCK_EVENTS
      }
      // Pull recent leads + conversations in parallel, merge by time,
      // take top FEED_LIMIT. Both endpoints are read-only (no trial
      // gate). We over-fetch by 2× to give the merge enough headroom.
      const [leadsRes, convRes] = await Promise.all([
        rpc<{ leads: LeadInfo[] }>('GetLeads', { page: 1, pageSize: FEED_LIMIT * 2 }),
        rpc<{ conversations: ConversationSummary[] }>('GetConversations', { page: 1, pageSize: FEED_LIMIT * 2 }),
      ])
      const leads = (leadsRes.success ? leadsRes.data.leads ?? [] : []).map(leadToEvent)
      const conversations = (convRes.success ? convRes.data.conversations ?? [] : []).map(conversationToEvent)

      // Drop anything older than 7 days so "Live for the last 7 days"
      // in the section header isn't a lie.
      const cutoff = Date.now() - SEVEN_DAYS_MS
      return [...leads, ...conversations]
        .filter(e => e.createdAt && Date.parse(e.createdAt) >= cutoff)
        .sort((a, b) => Date.parse(b.createdAt) - Date.parse(a.createdAt))
        .slice(0, FEED_LIMIT)
    },
    staleTime: 30 * 1000,
  })

  return (
    <section className="bb-card bb-activity-card">
      <header className="bb-card-header">
        <h3 className="bb-card-title">
          <span className="bb-eyebrow">Recent activity</span>
          <span>Conversations &amp; leads</span>
        </h3>
        <div className="bb-card-action">
          <a href="?page=bitbot&tab=conversations" className="bb-btn bb-btn-ghost bb-btn-small">Conversations</a>
          <a href="?page=bitbot&tab=leads" className="bb-btn bb-btn-ghost bb-btn-small">Leads</a>
        </div>
      </header>
      <div className="bb-activity-list">
        {isLoading && (
          <div className="bb-skeleton" style={{ height: 220, margin: 16 }} />
        )}
        {!isLoading && data && data.length === 0 && (
          <div className="bb-empty-state">
            <h4 className="bb-empty-headline">No activity yet</h4>
            <p className="bb-empty-body">
              Most sites get their first conversation within 24 hours of installing Youami.
              Try the widget yourself to confirm it's working.
            </p>
          </div>
        )}
        {!isLoading && data && data.length > 0 && data.map((event) => (
          <article key={event.id} className={`bb-activity-row bb-activity-${event.kind}`}>
            <div className="bb-activity-icon">{ICON[event.kind]}</div>
            <div className="bb-activity-body">
              <div className="bb-activity-title">{event.title}</div>
              <div className="bb-activity-snippet">{truncate(event.snippet, 140)}</div>
            </div>
            <div className="bb-activity-time">{relativeTime(event.createdAt)}</div>
          </article>
        ))}
      </div>
      {!isLoading && data && data.length > 0 && (
        <footer className="bb-activity-footer">
          <span>Showing {data.length} {data.length === 1 ? 'event' : 'events'} from the last 7 days</span>
          <a href="?page=bitbot&tab=conversations" className="bb-link">View all →</a>
        </footer>
      )}
    </section>
  )
}
