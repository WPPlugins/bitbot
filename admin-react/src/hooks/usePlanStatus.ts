/**
 * Plan status — used by the always-visible PlanCard.
 * staleTime 5 minutes per BLUEPRINT.md; refetch on window focus. Anything
 * that spends money reads it fresh (fetchFreshPlanStatus), never cached.
 *
 * Dev fallback: if the apiKey is the dev mock value, we short-circuit
 * the network call and return canned data so the layout renders in the
 * standalone Vite dev server. In real WP this never fires.
 */
import { useQuery, type QueryClient } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'
import { getConfig } from '@/lib/config'
import type { PlanStatus } from '@/api/types'

const DEV_MOCK_PLAN_STATUS: PlanStatus = {
  plan: 'pro',
  accessMode: 'active',
  trialEndsAt: null,
  daysRemaining: 0,
  limits: {
    chatMessagesPerMonth: 1000,
    topicCreditsPerMonth: 0,
    postsPerMonth: 30,
    postCooldownHours: 24,
    maxLeads: 1000,
    maxForms: 10,
    maxPages: 0,
  },
  usage: {
    chatMessagesThisMonth: 347,
    topicCreditsThisMonth: 0,
    postsThisMonth: 8,
    leadsCount: 42,
    formsCount: 3,
    lastPostGeneratedAt: null,
    pagesOn: 128,
  },
}

/** Offline demo of the Free page limit: open the dev server with ?devfree. */
const DEV_MOCK_FREE_PLAN_STATUS: PlanStatus = {
  ...DEV_MOCK_PLAN_STATUS,
  plan: 'free',
  accessMode: 'free',
  limits: { ...DEV_MOCK_PLAN_STATUS.limits, maxPages: 5, postsPerMonth: 1 },
  freePostSources: ['prompt'],
  usage: { ...DEV_MOCK_PLAN_STATUS.usage, pagesOn: 5 },
}

export const PLAN_STATUS_KEY = ['plan-status'] as const

async function fetchPlanStatus(): Promise<PlanStatus> {
  const cfg = getConfig()
  // Dev mock short-circuit — only fires in the Vite dev server.
  if (cfg.apiKey === 'bb_devmockapikey0000000000000000') {
    return typeof window !== 'undefined' && window.location.search.includes('devfree')
      ? DEV_MOCK_FREE_PLAN_STATUS
      : DEV_MOCK_PLAN_STATUS
  }
  const r = await rpc<PlanStatus>('GetPlanStatus', {})
  if (!r.success) throw new Error(r.error)
  return r.data
}

export function usePlanStatus() {
  return useQuery({
    queryKey: PLAN_STATUS_KEY,
    queryFn: fetchPlanStatus,
    staleTime: 5 * 60 * 1000, // 5 minutes
    refetchOnWindowFocus: true,
  })
}

/**
 * Read plan status from the server NOW, ignoring the 5-minute cache, and put
 * the answer in the cache so every reader (badge, gate, drawer) moves with it.
 * Money decisions use this, never the cached copy.
 */
export function fetchFreshPlanStatus(qc: QueryClient): Promise<PlanStatus> {
  return qc.fetchQuery({ queryKey: PLAN_STATUS_KEY, queryFn: fetchPlanStatus, staleTime: 0 })
}
