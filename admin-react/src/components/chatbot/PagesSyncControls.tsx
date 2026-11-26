/**
 * PagesSyncControls — the sync buttons that sit in the Indexed pages toolbar:
 * "Sync now" when idle; Continue (stalled), Stop (two-click confirm) and a
 * Refresh icon while indexing. The status text lives in PagesStatusItem.
 * All state and the trigger come from the shared useSync() hook, so this
 * matches the Overview quick action exactly.
 */
import { useQueryClient } from '@tanstack/react-query'
import { useAccessMode } from '@/hooks/useAccessMode'
import { useSync } from '@/hooks/useSync'
import { StopSyncButton } from './StopSyncButton'

export function PagesSyncControls() {
  const qc = useQueryClient()
  const { isExpired } = useAccessMode()
  const { isIndexing, label, trigger, canContinue, continueGather, isContinuing, stopSync, isStopping } = useSync()

  if (isIndexing && label) {
    return (
      <>
        {canContinue && (
          <button type="button" className="bb-btn bb-btn-ghost bb-btn-small" onClick={continueGather} disabled={isContinuing}>
            Continue
          </button>
        )}
        <StopSyncButton onStop={stopSync} isStopping={isStopping} />
        <button
          type="button"
          className="bb-icon-refresh"
          onClick={() => {
            qc.invalidateQueries({ queryKey: ['sync-progress'] })
            qc.invalidateQueries({ queryKey: ['sync-status'] })
            qc.invalidateQueries({ queryKey: ['indexed-pages'] })
          }}
          title="Refresh"
          aria-label="Refresh"
        >↻</button>
      </>
    )
  }

  return (
    <button
      type="button"
      className="bb-btn bb-btn-ghost bb-btn-small"
      onClick={() => trigger()}
      disabled={isExpired}
      title={isExpired ? 'Reactivate your plan to sync content' : undefined}
    >
      Sync now
    </button>
  )
}
