/**
 * StatRow — 4-up tile row of headline numbers. Lives directly under
 * the PlanCard on the Overview tab.
 *
 * Data sources (all real now — Phase 1 placeholders removed):
 *   - Conversations / 7d   → count of GetConversations rows whose
 *                            lastMessageAt is within the last 7 days
 *   - New leads / 7d       → count of GetLeads rows whose createdAt
 *                            is within the last 7 days
 *   - Indexed pages        → GetSyncStatus.pagesCount
 *   - Lead conversion      → newLeads7d / conversations7d × 100 (or 0
 *                            when no conversations yet). Replaces the
 *                            fake "Resolution rate" tile.
 *
 * Week-over-week deltas were placeholders; removed entirely until we
 * have backend support for "count by date range" with two windows.
 * The honest signal is the absolute number, not a made-up trend.
 */
import { useQuery } from '@tanstack/react-query'
import { StatTile } from '@/components/ui/StatTile'
import { rpc } from '@/api/rpc'
import { getConfig } from '@/lib/config'
import type { SyncStatus, ConversationSummary, LeadInfo } from '@/api/types'

interface StatBundle {
  conversations7d: number
  newLeads7d: number
  indexedPages: number
  leadConversionPct: number
}

const SEVEN_DAYS_MS = 7 * 24 * 60 * 60 * 1000

const DEV_MOCK_STATS: StatBundle = {
  conversations7d: 186,
  newLeads7d: 14,
  indexedPages: 128,
  leadConversionPct: 8,
}

export function StatRow() {
  const cfg = getConfig()
  const { data, isLoading } = useQuery({
    queryKey: ['overview-stats'],
    queryFn: async (): Promise<StatBundle> => {
      if (cfg.apiKey === 'bb_devmockapikey0000000000000000') {
        return DEV_MOCK_STATS
      }

      // Three reads in parallel. Each is a Get* RPC that's always
      // allowed (no trial gate), so the Overview tab renders even
      // when the site's mutations are blocked.
      const cutoff = Date.now() - SEVEN_DAYS_MS
      const [syncRes, convRes, leadsRes] = await Promise.all([
        rpc<SyncStatus>('GetSyncStatus', {}),
        rpc<{ conversations: ConversationSummary[] }>('GetConversations', { page: 1, pageSize: 200 }),
        rpc<{ leads: LeadInfo[] }>('GetLeads', { page: 1, pageSize: 200 }),
      ])

      const conversations = convRes.success ? convRes.data.conversations ?? [] : []
      const leads = leadsRes.success ? leadsRes.data.leads ?? [] : []

      const conversations7d = conversations.filter(c => {
        const t = c.lastMessageAt ? Date.parse(c.lastMessageAt) : 0
        return t >= cutoff
      }).length

      const newLeads7d = leads.filter(l => {
        const t = l.createdAt ? Date.parse(l.createdAt) : 0
        return t >= cutoff
      }).length

      const leadConversionPct = conversations7d > 0
        ? Math.min(100, Math.round((newLeads7d / conversations7d) * 100))
        : 0

      return {
        conversations7d,
        newLeads7d,
        indexedPages: syncRes.success ? syncRes.data.pagesCount : 0,
        leadConversionPct,
      }
    },
    staleTime: 60 * 1000,
  })

  if (isLoading || !data) {
    return (
      <div className="bb-statrow">
        {[1, 2, 3, 4].map((i) => (
          <div key={i} className="bb-skeleton" style={{ height: 76, borderRadius: 12 }} />
        ))}
      </div>
    )
  }

  return (
    <div className="bb-statrow">
      <StatTile
        label="Conversations / 7d"
        value={data.conversations7d}
      />
      <StatTile
        label="New leads / 7d"
        value={data.newLeads7d}
      />
      <StatTile
        label="Indexed pages"
        value={data.indexedPages}
      />
      <StatTile
        label="Lead conversion / 7d"
        value={data.leadConversionPct}
        unit="%"
      />
    </div>
  )
}
