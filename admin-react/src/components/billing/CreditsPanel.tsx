import { usePlanStatus } from '@/hooks/usePlanStatus'
import { AutoTopupSwitch } from '@/components/integrations/AutoTopupSwitch'
import { usageCredits } from '@/components/integrations/usageLines'
import { AddCredits } from './AddCredits'
import { CreditsMeter } from './CreditsMeter'
import { minuteRate } from './minuteUsage'
import './credits.css'

/** The drawer's own title: what the pool is called on this plan. */
export function creditsPanelTitle(plan: string): string {
  if (plan === 'pro') return 'Usage & minutes'
  if (plan === 'pro_plus') return 'Usage & credits'
  return 'Account'
}

export function CreditsPanel() {
  const { data: status } = usePlanStatus()
  const credit = status?.agentCredit
  if (!status || !credit || (status.plan !== 'pro' && status.plan !== 'pro_plus')) return null
  const credits = usageCredits(credit)
  return (
    <section className="bb-credit-panel bb-drawer-section" aria-label={creditsPanelTitle(status.plan)}>
      <CreditsMeter credit={credit} plan={status.plan} />
      <AddCredits credit={credit} plan={status.plan} />
      {credit.autoTopup && credits && <AutoTopupSwitch status={credit.autoTopup} perUsd={credits.perUsd} plan={status.plan} rate={minuteRate(credit)} />}
    </section>
  )
}
