import { usePlanStatus } from './usePlanStatus'
import { getConfig, type Plan } from '@/lib/config'

/**
 * The current plan, LIVE.
 *
 * `cfg.plan` (from getConfig) is a static snapshot injected by the PHP plugin
 * at page load, sourced from its throttled/daily-cron plan cache. After a plan
 * change it can lag the backend by the throttle window — which is exactly how
 * the account pill showed "Agent" while the live Change-Plan modal showed
 * "Assistant". `usePlanStatus()` reads the backend (sites.plan) directly.
 *
 * Use the live value when it's resolved, falling back to `cfg.plan` only while
 * the query is in flight. Labels and tier gates then self-heal without a reload
 * or waiting on the PHP cache. No extra network cost: usePlanStatus is a single
 * shared, 5-min-cached query already used across the app.
 */
export function useCurrentPlan(): Plan {
  const cfg = getConfig()
  const { data } = usePlanStatus()
  return (data?.plan as Plan | undefined) ?? cfg.plan
}
