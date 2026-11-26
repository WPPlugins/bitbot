/**
 * useSyncProgress — polls the WordPress `bitbot_sync_progress` AJAX endpoint
 * for the resumable background bulk sync. The sync runs server-side across
 * many wp-cron ticks (one batch each), writing { state, done, total } to a
 * WP option; this hook surfaces it as a live "X / Y (Z%)" for the SPA.
 *
 * Always enabled so the bar reappears after a reload / in another tab while
 * a sync is still running (the "close the tab, it keeps going" behaviour).
 * Polls while pass 1 runs or the render pass is running/paused/stalled.
 */
import { useQuery } from '@tanstack/react-query'
import { wpAjax } from '@/api/rpc'
import { getConfig } from '@/lib/config'
import { isSyncActive } from '@/lib/syncLabel'

/** Gather v2 render pass, mirrored by the plugin (status_from in class-gather-v2.php). */
export interface GatherStatus {
  phase?: string
  /** 'running' | 'paused' | 'stalled' | 'done' | 'off' | other engine states. */
  state?: string
  pauseReason?: string | null
  runId?: string
  /** Both include pages already current from a previous sync. */
  total?: number
  done?: number
  skippedCurrent?: number
  failed?: number
  images?: { sent?: number; pending?: number; dropped?: number; tries?: number }
  renderTypes?: unknown
  concurrency?: number
  concurrencyCap?: number
  gapSeconds?: number
  canContinue?: boolean
}

export interface SyncProgress {
  state: 'idle' | 'running' | 'done' | 'error'
  done?: number
  total?: number
  percent?: number
  error?: string | null
  /** Absent on older plugins and sitemap fills. */
  gather?: GatherStatus
}

const DEV_MOCK_KEY = 'bb_devmockapikey0000000000000000'

export function useSyncProgress() {
  const isDev = getConfig().apiKey === DEV_MOCK_KEY

  return useQuery({
    queryKey: ['sync-progress'],
    queryFn: async (): Promise<SyncProgress> => {
      if (isDev) return { state: 'idle' }
      return wpAjax<SyncProgress>('bitbot_sync_progress')
    },
    refetchInterval: (q) => (isSyncActive(q.state.data) ? 2500 : false),
    staleTime: 1000,
  })
}
