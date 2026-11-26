/**
 * useSyncFlash — true for a few seconds after a sync run ends, and refreshes
 * the sync status, the page list and the plan numbers at that moment.
 */
import { useEffect, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { PLAN_STATUS_KEY } from './usePlanStatus'

const SUCCESS_FLASH_MS = 5000

export function useSyncFlash(isIndexing: boolean): boolean {
  const qc = useQueryClient()
  const [flash, setFlash] = useState(false)
  const [wasIndexing, setWasIndexing] = useState(false)

  useEffect(() => {
    if (isIndexing) setWasIndexing(true)
  }, [isIndexing])

  useEffect(() => {
    if (isIndexing || !wasIndexing) return
    setWasIndexing(false)
    setFlash(true)
    qc.invalidateQueries({ queryKey: ['sync-status'] })
    qc.invalidateQueries({ queryKey: ['indexed-pages'] })
    qc.invalidateQueries({ queryKey: PLAN_STATUS_KEY })
    const t = setTimeout(() => setFlash(false), SUCCESS_FLASH_MS)
    return () => clearTimeout(t)
  }, [isIndexing, wasIndexing, qc])

  return flash
}
