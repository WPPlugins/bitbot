/**
 * Overview-tab data hooks. Currently exposes useOverviewInsights which
 * powers the Section 01 bento (top questions, top countries, engagement
 * depth, lead-capture rate). The server caches the bundle per-site for
 * 15 minutes so polling here is cheap.
 */
import { useQuery } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'
import type { OverviewInsights } from '@/api/types'

export function useOverviewInsights() {
  return useQuery({
    queryKey: ['overview-insights'],
    queryFn: async (): Promise<OverviewInsights> => {
      const res = await rpc<OverviewInsights>('GetOverviewInsights')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    // Server-side cache TTL is 15 min, so we can poll cheaply — pick a
    // 2-min stale window so the dashboard feels alive without thrashing
    // the cache logic.
    staleTime: 2 * 60 * 1000,
  })
}
