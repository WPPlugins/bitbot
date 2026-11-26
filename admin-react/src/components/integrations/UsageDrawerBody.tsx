import { usePlanStatus } from '@/hooks/usePlanStatus'
import { CreditsPanel } from '@/components/billing/CreditsPanel'

export function UsageDrawerBody() {
  const { data, isLoading } = usePlanStatus()
  if (isLoading) return <div className="bb-usage-empty">Loading usage…</div>
  if (!data?.agentCredit) return <div className="bb-usage-empty">Usage unavailable</div>
  return <CreditsPanel />
}
