import type { PlanStatus } from '@/api/types'

export interface PlanMeter {
  label: string
  used: number
  cap: number | null
  unit?: string
  lockedHint?: string
  unavailable?: boolean
  finite?: boolean
}

// Match the API's unlimited sentinels, including legacy large caps.
function capped(cap: number): boolean {
  return Number.isFinite(cap) && cap > 0 && cap <= 1_000_000
}

export function planMeters(
  status: PlanStatus,
  minutes: { used: number; funded: number } | null = null,
): PlanMeter[] {
  const { plan, usage, limits } = status
  const chat = { label: 'Chat queries', used: usage.chatMessagesThisMonth, cap: limits.chatMessagesPerMonth }
  if (plan === 'free') return [
    chat,
    { label: 'Topic searches', used: usage.topicCreditsThisMonth, cap: limits.topicCreditsPerMonth },
    { label: 'Leads', used: usage.leadsCount, cap: limits.maxLeads, lockedHint: 'Upgrade to view' },
  ]
  const meters: PlanMeter[] = capped(chat.cap) ? [chat] : []
  const credits = status.agentCredit?.money?.credits
  const balance = plan === 'pro' ? minutes && { used: minutes.used, cap: minutes.funded }
    : credits && { used: credits.used, cap: credits.pool }
  const ready = !!balance && Number.isFinite(balance.used) && Number.isFinite(balance.cap) && balance.cap >= 0
  meters.push({
    label: plan === 'pro' ? 'Voice minutes' : 'Credits',
    used: ready ? Math.max(0, balance.used) : 0,
    cap: ready ? balance.cap : null,
    unit: plan === 'pro' ? 'min' : undefined,
    unavailable: !ready,
    finite: true,
  })
  return meters
}
