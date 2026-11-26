/**
 * SyncStrip — banner under the PlanCard on the Chatbot tab. Shows indexed
 * page count + last-sync time, with a "Sync now" button.
 *
 * "Sync now" fires the WP AJAX action `bitbot_sync`, which kicks off a
 * RESUMABLE BACKGROUND sync (one batch per wp-cron tick) and returns
 * immediately — the admin can close the tab and it keeps going. useSyncProgress
 * polls `bitbot_sync_progress` and we render a live "X / Y (Z%)" while it runs;
 * when it finishes we refresh the indexed count + last-sync time.
 */
import { useEffect, useRef } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useSyncStatus } from '@/hooks/useSyncStatus'
import { useSyncProgress } from '@/hooks/useSyncProgress'
import { useGatherContinue } from '@/hooks/useGatherContinue'
import { useSyncStop } from '@/hooks/useSyncStop'
import { StopSyncButton } from './StopSyncButton'
import { indexingLabel, isSyncActive, syncPhase } from '@/lib/syncLabel'
import { StatusPill } from '@/components/ui/StatusPill'
import { toast } from '@/components/ui/Toast'
import { relativeTime } from '@/lib/utils'
import { wpAjax } from '@/api/rpc'

interface SyncStartResult {
  state?: string
  total?: number
  pagesQueued?: number
  totalBatches?: number
}

// wpAjax surfaces a friendly message (and emits wp_ajax_failure telemetry)
// if admin-ajax ever returns an HTML PHP-fatal page instead of JSON.
async function triggerWpSync(): Promise<SyncStartResult> {
  return (await wpAjax<SyncStartResult>('bitbot_sync')) ?? {}
}

export function SyncStrip() {
  const qc = useQueryClient()
  const { data, isLoading } = useSyncStatus()
  const { data: progress } = useSyncProgress()
  const cont = useGatherContinue()
  const stop = useSyncStop()

  const sync = useMutation({
    mutationFn: triggerWpSync,
    onSuccess: (result) => {
      const count = result.total ?? result.pagesQueued ?? 0
      toast.success(count > 0 ? `Sync started: ${count} pages` : 'Sync started')
      qc.invalidateQueries({ queryKey: ['sync-progress'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Sync failed'),
  })

  // When the background sync transitions running → done, refresh the indexed
  // count + last-sync time (and the Indexed Pages table) once.
  const wasRunning = useRef(false)
  useEffect(() => {
    if (isSyncActive(progress)) wasRunning.current = true
    if (wasRunning.current && syncPhase(progress) === 'done') {
      wasRunning.current = false
      qc.invalidateQueries({ queryKey: ['sync-status'] })
      qc.invalidateQueries({ queryKey: ['indexed-pages'] })
    }
  }, [progress, qc])

  const isRunning = isSyncActive(progress) || sync.isPending
  // Same combined label every other surface shows (pass 1, render pass, images).
  const label = indexingLabel(null, progress)
  const pct = label?.percent ?? 0
  const legacyTotal = progress?.total ?? 0
  const hasBar = !!label && (label.phase ? true : legacyTotal > 0)

  return (
    <section className="bb-syncstrip">
      <div className="bb-syncstrip-left">
        <span className="bb-eyebrow">Indexed content</span>
        <div className="bb-syncstrip-meta">
          {isLoading ? (
            <span className="bb-syncstrip-muted">Checking sync status…</span>
          ) : isRunning ? (
            <span className="bb-syncstrip-muted">
              {label?.text
                ?? (legacyTotal > 0
                  ? `Syncing ${(progress?.done ?? 0).toLocaleString()} of ${legacyTotal.toLocaleString()} pages`
                  : 'Starting sync…')}
            </span>
          ) : (
            <>
              <span className="bb-syncstrip-count">{data?.pagesCount ?? 0}</span>
              <span className="bb-syncstrip-label">pages indexed</span>
              {progress?.state === 'error' && (
                <>
                  <span className="bb-syncstrip-dot">·</span>
                  <span className="bb-syncstrip-muted" style={{ color: '#dc3545' }}>
                    Last sync didn’t finish — try again
                  </span>
                </>
              )}
              {data?.lastSync && progress?.state !== 'error' && (
                <>
                  <span className="bb-syncstrip-dot">·</span>
                  <span className="bb-syncstrip-muted">Last synced {relativeTime(data.lastSync)}</span>
                </>
              )}
            </>
          )}
        </div>
        {isRunning && hasBar && (
          <div className="bb-syncstrip-progress" aria-hidden="true">
            <div className="bb-syncstrip-progress-fill" style={{ width: `${pct}%` }} />
          </div>
        )}
      </div>
      <div className="bb-syncstrip-right">
        {isRunning ? (
          <>
            {label?.canContinue ? (
              <button className="bb-btn bb-btn-ghost" onClick={() => cont.mutate()} disabled={cont.isPending}>
                Continue
              </button>
            ) : (
              <StatusPill status="syncing" label={hasBar ? `Syncing ${pct}%` : 'Syncing'} />
            )}
            <StopSyncButton onStop={() => stop.mutate()} isStopping={stop.isPending} />
          </>
        ) : (
          <button
            className="bb-btn bb-btn-ghost"
            onClick={() => sync.mutate()}
            disabled={sync.isPending}
          >
            {sync.isPending ? 'Starting…' : 'Sync now'}
          </button>
        )}
      </div>
    </section>
  )
}
