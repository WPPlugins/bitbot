/**
 * PagesStatusItem — the ONE status item at the right of the filter row.
 * Replaces the old section-heading sync trigger text and any usage card:
 * last synced, live progress and the Free page allowance in a single line.
 * Text and tones come from lib/pagesStatus (pure, unit tested).
 */
import { useState } from 'react'
import { usePlanStatus } from '@/hooks/usePlanStatus'
import { useSync } from '@/hooks/useSync'
import { useSyncFlash } from '@/hooks/useSyncFlash'
import { useSyncStatus } from '@/hooks/useSyncStatus'
import { useSyncProgress } from '@/hooks/useSyncProgress'
import { pagesStatus, type SyncOutcome } from '@/lib/pagesStatus'
import { labelLine } from '@/lib/syncLabel'
import { ChangePlanModal } from '@/components/billing/ChangePlanModal'
import { relativeTime } from '@/lib/utils'

interface Props {
  finding?: boolean
  reading?: number
  firstSyncPending?: boolean
  stalled?: boolean
}

export function PagesStatusItem({ finding = false, reading = 0, firstSyncPending = false, stalled = false }: Props) {
  const { data: plan } = usePlanStatus()
  const { data: status } = useSyncStatus()
  const { data: progress } = useSyncProgress()
  const { isIndexing, lastSync, label } = useSync()
  const flash = useSyncFlash(isIndexing)
  const [upgradeOpen, setUpgradeOpen] = useState(false)

  const fill = status?.sitemapFill
  const gatherDone = progress?.gather?.done ?? progress?.done ?? 0
  const gatherTotal = progress?.gather?.total ?? progress?.total ?? 0
  const outcome: SyncOutcome =
    progress?.state === 'error' || status?.status === 'failed' ? 'failed' : 'ok'

  const item = pagesStatus({
    maxPages: plan?.limits.maxPages,
    pagesOn: plan?.usage.pagesOn,
    syncedAgo: relativeTime(lastSync),
    syncing: isIndexing || status?.status === 'syncing',
    syncDone: fill?.active ? fill.done : gatherDone,
    syncTotal: fill?.active ? fill.total : gatherTotal,
    syncText: labelLine(label),
    syncPercent: label?.percent,
    flash,
    finding,
    reading,
    firstSyncPending,
    stalled,
    outcome: isIndexing ? 'ok' : outcome,
  })

  if (!item.text && item.barPercent === null && !item.showUpgrade) return null

  return (
    <div className={`bb-pages-cap bb-pages-cap-${item.tone}`} role="status" aria-live="polite" title={lastSync ? new Date(lastSync).toLocaleString() : undefined}>
      {item.barPercent !== null && (
        <progress className="bb-pages-cap-bar" max={100} value={item.barPercent} aria-label={isIndexing ? 'Sync progress' : 'Pages used'} />
      )}
      {item.lead && <span className="bb-pages-cap-lead">{item.lead}{item.main ? ' · ' : ''}</span>}
      {item.main && <span className="bb-pages-cap-main">{item.main}</span>}
      {item.showUpgrade && (
        <button type="button" className="bb-link-btn bb-pages-cap-upgrade" onClick={() => setUpgradeOpen(true)}>Upgrade</button>
      )}
      {item.showUpgrade && <ChangePlanModal open={upgradeOpen} onClose={() => setUpgradeOpen(false)} />}
    </div>
  )
}
