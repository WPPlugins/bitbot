/**
 * useAccessMode — thin wrapper around usePlanStatus that surfaces the
 * gating signal to any component that wants to disable a button, show
 * an upgrade prompt, or change copy. Defaults to 'active' while loading
 * so the UI doesn't flash a disabled state on first paint.
 */
import { usePlanStatus } from './usePlanStatus'
import type { AccessMode } from '@/api/types'

interface AccessModeState {
  accessMode: AccessMode
  trialEndsAt: string | null
  daysRemaining: number
  isFree: boolean
  isExpired: boolean
  isActive: boolean
  isLoading: boolean
}

export function useAccessMode(): AccessModeState {
  const { data, isLoading } = usePlanStatus()
  const accessMode: AccessMode = data?.accessMode ?? 'active'
  return {
    accessMode,
    trialEndsAt: data?.trialEndsAt ?? null,
    daysRemaining: data?.daysRemaining ?? 0,
    isFree: accessMode === 'free',
    isExpired: accessMode === 'expired',
    isActive: accessMode === 'active',
    isLoading,
  }
}
