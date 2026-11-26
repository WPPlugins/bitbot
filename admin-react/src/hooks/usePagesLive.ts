/**
 * usePagesLive — keeps the Indexed pages list fresh while pages are arriving.
 *
 * One 4s ticker, running only while the pane is open, re-pulls the page list,
 * counts and GetSyncStatus whenever lib/pagesStatus.shouldPollPages says so:
 *  - a sync is active (progress option, GetSyncStatus 'syncing', sitemap fill),
 *  - the first sync is not done (GetSyncStatus.firstSyncDone, else lastSync),
 *  - some row is still being read (state 'reading'),
 *  - anything in the list changed in the last 20 s.
 * The first two-to-three conditions are independent of row counts: the backend
 * claims slots up front, so rows exist long before their content does.
 * Polling stops after 10 minutes with no change; `stalled` then tells the
 * status item not to look finished.
 */
import { useCallback, useEffect, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useSyncProgress } from './useSyncProgress'
import { useSyncStatus } from './useSyncStatus'
import { isSyncActive } from '@/lib/syncLabel'
import { FIRST_RUN_POLL_CEILING_MS, POLL_INTERVAL_MS, firstSyncPending, isStalled, shouldPollPages } from '@/lib/pagesStatus'

export function usePagesLive() {
  const qc = useQueryClient()
  const { data: progress } = useSyncProgress()
  const { data: status } = useSyncStatus()
  const live = isSyncActive(progress) || status?.status === 'syncing' || !!status?.sitemapFill?.active
  const pending = firstSyncPending(status)

  const openedAt = useRef(Date.now())
  const lastChange = useRef(0)
  const signature = useRef<string | null>(null)
  const [reading, setReading] = useState(false)
  const [windowOpen, setWindowOpen] = useState(true)
  const liveRef = useRef(live)
  liveRef.current = live
  const pendingRef = useRef(pending)
  pendingRef.current = pending
  const readingRef = useRef(reading)
  readingRef.current = reading

  const input = useCallback(() => {
    const now = Date.now()
    return {
      live: liveRef.current,
      firstSyncPending: pendingRef.current,
      reading: readingRef.current,
      msSinceCountChange: now - lastChange.current,
      msSinceOpen: now - openedAt.current,
    }
  }, [])

  /** Feed the list's signature and reading flag; a changed signature restarts the clock. */
  const noteList = useCallback((sig: string, isReading: boolean) => {
    if (signature.current !== null && signature.current !== sig) {
      lastChange.current = Date.now()
      setWindowOpen(true)
    }
    signature.current = sig
    setReading(isReading)
  }, [])

  useEffect(() => {
    const t = setInterval(() => {
      const i = input()
      setWindowOpen(Math.min(i.msSinceCountChange, i.msSinceOpen) < FIRST_RUN_POLL_CEILING_MS)
      if (!shouldPollPages(i)) return
      qc.invalidateQueries({ queryKey: ['indexed-pages'] })
      qc.invalidateQueries({ queryKey: ['sync-status'] })
    }, POLL_INTERVAL_MS)
    return () => clearInterval(t)
  }, [input, qc])

  const now = Date.now()
  const stalled = isStalled({
    live, firstSyncPending: pending, reading,
    msSinceCountChange: now - lastChange.current, msSinceOpen: now - openedAt.current,
  })
  return { live, firstSyncPending: pending, reading, windowOpen, stalled, noteList }
}
