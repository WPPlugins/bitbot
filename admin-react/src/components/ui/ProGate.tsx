/**
 * ProGate — soft lock overlay shown on features that aren't unlocked on
 * the current plan. The gated children render UNDERNEATH the overlay
 * (slightly faded) so the visitor can see what they'd unlock — never a
 * blank space.
 *
 * Two flavours of gate:
 *   - 'assistant' → unlocks for any paying customer
 *   - 'agent'     → unlocks only on Agent (Pro+ DB value)
 *
 * The "Get X" button drives Stripe directly via useStartCheckout. For an
 * existing Assistant subscriber hitting an Agent gate, CreateCheckout
 * swaps the subscription's price via stripe.subscriptions.update with
 * proration_behavior: 'always_invoice' (no checkout round-trip). For
 * trial/free users it opens a fresh Stripe checkout session.
 */
import { useState, type ReactNode } from 'react'
import { isPaid, isAgent, type Plan } from '@/lib/config'
import { useCurrentPlan } from '@/hooks/useCurrentPlan'
import { ChangePlanModal } from '@/components/billing/ChangePlanModal'

type RequiredTier = 'assistant' | 'agent'

interface ProGateProps {
  requiredTier: RequiredTier
  /** Short feature name used in the lock copy. */
  feature: string
  children: ReactNode
}

function isUnlocked(plan: Plan, tier: RequiredTier): boolean {
  if (tier === 'assistant') return isPaid(plan)
  return isAgent(plan)
}

export function ProGate({ requiredTier, feature, children }: ProGateProps) {
  const unlocked = isUnlocked(useCurrentPlan(), requiredTier)
  const tierLabel = requiredTier === 'agent' ? 'Agent' : 'Assistant'
  const [changePlanOpen, setChangePlanOpen] = useState(false)

  if (unlocked) return <>{children}</>

  return (
    <div className="bb-progate">
      <div className="bb-progate-overlay">
        <span className={`bb-tier-badge bb-tier-badge-${requiredTier}`}>{tierLabel}</span>
        <p className="bb-progate-text">
          {feature} unlocks on {tierLabel}.
        </p>
        <button
          type="button"
          className="bb-btn bb-btn-primary"
          onClick={() => setChangePlanOpen(true)}
        >
          Get {tierLabel}
        </button>
      </div>
      <div className="bb-progate-content" aria-hidden>
        {children}
      </div>
      <ChangePlanModal open={changePlanOpen} onClose={() => setChangePlanOpen(false)} />
    </div>
  )
}
