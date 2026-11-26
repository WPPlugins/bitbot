/**
 * useSyncStatus — GetSyncStatus RPC with polling when status === 'syncing'.
 * Returns the raw status (idle / syncing / completed / failed) plus the
 * page count and last sync timestamp, all surfaced by SyncStrip.
 *
 * Dev-mock returns a stable "completed" state so the SPA renders with
 * realistic-looking data.
 */
import { useQuery } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'
import { getConfig } from '@/lib/config'
import type { SyncStatus } from '@/api/types'

const DEV_MOCK_KEY = 'bb_devmockapikey0000000000000000'

const DEV_MOCK_STATUS: SyncStatus = {
  status: 'completed',
  pagesCount: 128,
  firstSyncDone: true,
  lastSync: new Date(Date.now() - 4 * 60 * 60 * 1000).toISOString(),
}

export function useSyncStatus() {
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useQuery({
    queryKey: ['sync-status'],
    queryFn: async (): Promise<SyncStatus> => {
      if (isDev) return DEV_MOCK_STATUS
      const res = await rpc<SyncStatus>('GetSyncStatus')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    // Poll while syncing. A running sitemap fill reports status==='syncing'
    // already, but poll a touch faster then so the "N of M" ticks visibly.
    refetchInterval: (q) => {
      const d = q.state.data
      if (d?.sitemapFill?.active) return 3_000
      return d?.status === 'syncing' ? 5_000 : false
    },
    staleTime: 2 * 1000,
  })
}
