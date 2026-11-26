/**
 * useSync — the SINGLE source of truth for the "is anything indexing right now,
 * what do we call it, and how do I start a sync" question. Both live sync
 * surfaces — the Overview Quick action and the Knowledgebase Indexed pages controls — use
 * this so they always call the same trigger and show the same messaging,
 * whether the work is the WP-page gather OR a server-side sitemap fill.
 *
 * "Indexing" is true when ANY of these are happening:
 *   - we just fired the trigger (mutation pending),
 *   - pass 1 is running OR the render pass is running/paused/stalled (useSyncProgress),
 *   - a server-side sitemap fill is active (GetSyncStatus.sitemapFill).
 */
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { wpAjax } from '@/api/rpc'
import { toast } from '@/components/ui/Toast'
import { useSyncStatus } from './useSyncStatus'
import { useSyncProgress } from './useSyncProgress'
import { useGatherContinue } from './useGatherContinue'
import { useSyncStop } from './useSyncStop'
import { indexingLabel, syncPhase, type SyncLabel, type SyncPhase } from '@/lib/syncLabel'

export interface SyncState {
  /** True while any indexing is in flight — disable every "Sync now" on this. */
  isIndexing: boolean
  /** True only for the brief gap between click and the first progress poll. */
  isStarting: boolean
  /** Unified progress label (title + detail + percent), or null when idle. */
  label: SyncLabel | null
  /** Combined stage: pass 1 'pages', then 'render', then 'images', else 'done' (null before any sync). */
  phase: SyncPhase | null
  /** Render pass is stalled: show a Continue button. */
  canContinue: boolean
  continueGather: () => void
  isContinuing: boolean
  /** Stop the running sync (pages already read stay available). */
  stopSync: () => void
  isStopping: boolean
  /** Last sync didn't finish (WP gather errored). */
  failed: boolean
  lastSync: string | null
  pagesCount: number
  /** Kick a sync (WP gather + server re-probe). Same call on every surface. */
  trigger: () => void
}

export function useSync(): SyncState {
  const qc = useQueryClient()
  const { data: status } = useSyncStatus()
  const { data: progress } = useSyncProgress()

  const mutation = useMutation({
    // wpAjax turns an HTML PHP-fatal page into a readable error instead of
    // "Unexpected token '<'", and emits wp_ajax_failure telemetry.
    mutationFn: async () => (await wpAjax('bitbot_sync')) ?? {},
    onSuccess: () => {
      toast.success('Sync started')
      qc.invalidateQueries({ queryKey: ['sync-progress'] })
      qc.invalidateQueries({ queryKey: ['sync-status'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Sync failed'),
  })

  const cont = useGatherContinue()
  const stop = useSyncStop()
  const base = indexingLabel(status?.sitemapFill, progress)
  const isStarting = mutation.isPending
  const isIndexing = isStarting || !!base
  const label: SyncLabel | null = base ?? (isStarting ? { title: 'Starting sync…', detail: '', percent: 0 } : null)

  return {
    isIndexing,
    isStarting,
    label,
    phase: syncPhase(progress),
    canContinue: !!base?.canContinue,
    continueGather: () => cont.mutate(),
    isContinuing: cont.isPending,
    stopSync: () => stop.mutate(),
    isStopping: stop.isPending,
    failed: progress?.state === 'error',
    lastSync: status?.lastSync ?? null,
    pagesCount: status?.pagesCount ?? 0,
    trigger: () => mutation.mutate(),
  }
}
