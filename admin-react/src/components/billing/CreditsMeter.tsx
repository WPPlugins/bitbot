import type { PlanStatus } from '@/api/types'
import { formatResetDate } from '@/lib/resetDate'
import { formatCredits, resetIso, usageCredits } from '@/components/integrations/usageLines'
import { formatMinutes, minuteUsage } from './minuteUsage'
import './credits.css'

type Credit = NonNullable<PlanStatus['agentCredit']>

export function BalanceMeter({ used, pool, label }: { used: number; pool: number; label: string }) {
  const value = pool > 0 ? Math.min(100, Math.max(0, used / pool * 100)) : used > 0 ? 100 : 0
  return <progress className="bb-balance-meter" max={100} value={value} aria-label={label} />
}

export function CreditsMeter({ credit, plan = 'pro_plus', compact = false }: { credit: Credit; plan?: string; compact?: boolean }) {
  const assistant = plan === 'pro'
  const credits = usageCredits(credit)
  const minutes = minuteUsage(credit)
  const reset = formatResetDate(resetIso(credit))
  const ready = assistant ? minutes != null : credits != null
  const value = assistant ? formatMinutes(minutes?.left ?? 0) : formatCredits(credits?.left ?? 0)
  const used = assistant ? minutes?.used ?? 0 : credits?.used ?? 0
  const pool = assistant ? minutes?.funded ?? 0 : credits?.pool ?? 0
  const usage = assistant
    ? `${formatMinutes(used)} of ${formatMinutes(pool)} min used`
    : `${formatCredits(used)} of ${formatCredits(pool)} credits used`
  return (
    <div className={`bb-balance bb-balance-${assistant ? 'assistant' : 'agent'}${compact ? ' bb-balance-compact' : ''}`}>
      <div className="bb-balance-heading"><span>{assistant ? 'Voice minutes' : 'Monthly balance'}</span><span>{compact ? 'This month' : assistant ? 'Assistant' : 'Agent'}</span></div>
      <div className="bb-balance-value">{ready ? value : '…'} <span>{assistant ? 'min left' : 'credits left'}</span></div>
      {ready ? <>
        <BalanceMeter used={used} pool={pool} label={usage} />
        <div className="bb-balance-meta"><span>{usage}</span><span>Resets {reset}</span></div>
      </> : <p className="bb-balance-meta">Usage unavailable</p>}
      {!!credit.voice?.pendingCredits && <p className="bb-balance-meta">{assistant ? `${formatMinutes(minutes?.pending ?? 0)} min` : `${formatCredits(credit.voice.pendingCredits)} credits`} held for active calls</p>}
    </div>
  )
}
