/**
 * TierGate — wraps any settings field with a small tier badge + soft
 * lock when the customer's plan is below the required tier. The Upgrade
 * link opens the plan picker (ChangePlanModal), the same one ProGate uses.
 *
 * Why per-field rather than per-card: customers want to *see* every
 * field even on Free — that's the upsell. They just can't change
 * the locked ones until they upgrade. The badge makes the tier
 * boundary obvious at a glance.
 *
 * Usage:
 *   <TierGate required="assistant" feature="Off-topic guardrails">
 *     <textarea … />
 *   </TierGate>
 */
import { useState, type ReactNode } from 'react'
import { isAgent, isPaid, planLabel, type Plan } from '@/lib/config'
import { useCurrentPlan } from '@/hooks/useCurrentPlan'
import { captureEvent } from '@/lib/telemetry'
import { ChangePlanModal } from '@/components/billing/ChangePlanModal'

type RequiredTier = 'assistant' | 'agent'

interface TierGateProps {
  required: RequiredTier
  /** Short, plain-English feature name used in the upgrade prompt. */
  feature: string
  children: ReactNode
}

function isUnlocked(plan: Plan, tier: RequiredTier): boolean {
  return tier === 'agent' ? isAgent(plan) : isPaid(plan)
}

export function TierGate({ required, feature, children }: TierGateProps) {
  const livePlan = useCurrentPlan()
  const [changePlanOpen, setChangePlanOpen] = useState(false)
  const unlocked = isUnlocked(livePlan, required)
  const tierLabel = required === 'agent' ? 'Agent' : 'Assistant'

  if (unlocked) return <>{children}</>

  return (
    <div className="bb-tiergate" role="group" aria-label={`${feature} (requires ${tierLabel})`}>
      <div className="bb-tiergate-head">
        <span className={`bb-tier-badge bb-tier-badge-${required}`}>{tierLabel}</span>
        <span className="bb-tiergate-caption">
          {feature} unlocks on {tierLabel}.
        </span>
        <button
          type="button"
          className="bb-tiergate-cta"
          onClick={() => {
            captureEvent('upgrade_cta_clicked', { feature, required_tier: required, current_plan: livePlan })
            setChangePlanOpen(true)
          }}
        >
          Upgrade →
        </button>
      </div>
      <div className="bb-tiergate-fade" aria-hidden>
        {children}
      </div>
      <ChangePlanModal open={changePlanOpen} onClose={() => setChangePlanOpen(false)} />
    </div>
  )
}

/** Inline tier badge — for labels next to fields that are still editable
 *  (e.g. when admin IS paid but we want to remind them this is a Pro
 *  feature so they know what tier customers see it). */
export function TierBadge({ required }: { required: RequiredTier }) {
  const label = required === 'agent' ? 'Agent' : 'Assistant'
  return <span className={`bb-tier-badge bb-tier-badge-${required} bb-tier-badge-inline`}>{label}</span>
}

/** Convenience: show the customer's current plan label inline. */
export function CurrentPlanBadge() {
  return <span className="bb-tier-badge bb-tier-badge-current">{planLabel(useCurrentPlan())}</span>
}
