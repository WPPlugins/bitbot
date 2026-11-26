/**
 * PlanCard — full-width dark-ink strip shown at the top of every tab.
 *
 * Content is fully context-aware: headline / sub / CTA / layout all
 * come from getPlanCardContent() which resolves the right message for
 * the current (tab × sub × plan × usage × subscription) combination.
 *
 * Layout variants:
 *   - 'meters'  → Overview tab: badge + headline + sub | usage meters | price + CTA
 *   - 'message' → every other tab: badge + headline + sub | price + CTA
 *                 (centered single-message strip — no meters)
 *
 * Single CTA opens ChangePlanModal — same modal whether the user is
 * upgrading, reactivating, or changing tiers. The lock-card CTAs on
 * Leads / Conversations open the *same* modal in-place so the experience
 * is identical regardless of entry point.
 */
import { useState } from 'react'
import { BrandMark } from '../ui/BrandMark'
import { usePlanStatus } from '@/hooks/usePlanStatus'
import { useSubscription } from '@/hooks/useSubscription'
import { useTab } from '@/hooks/useTab'
import { useContentSubtab } from '@/hooks/useContentSubtab'
import { useChatbotSubtab } from '@/hooks/useChatbotSubtab'
import { UsageMeter } from './UsageMeter'
import { ChangePlanModal } from '@/components/billing/ChangePlanModal'
import { getPlanCardContent } from './planCardContent'
import { minuteUsage } from '@/components/billing/minuteUsage'
import { formatResetDate } from '@/lib/resetDate'
import { planMeters } from './planMeters'

type SubscriptionStatus = 'trialing' | 'active' | 'cancelled' | 'expired'

function normaliseSubStatus(raw: string | undefined): SubscriptionStatus {
  if (raw === 'cancelled' || raw === 'canceled') return 'cancelled'
  if (raw === 'expired') return 'expired'
  if (raw === 'trialing') return 'trialing'
  return 'active'
}

export function PlanCard() {
  const { data: status, isLoading, isError } = usePlanStatus()
  const { data: subscription } = useSubscription()
  const [tab] = useTab()
  const [contentSub] = useContentSubtab()
  const [chatbotSub] = useChatbotSubtab()
  const [changePlanOpen, setChangePlanOpen] = useState(false)

  // Shares the Leads tab cache entry; only fetches on Free + Leads tab.

  if (isError) return null

  if (isLoading || !status) {
    return (
      <div className="bb-plancard bb-plancard-skeleton">
        <div className="bb-skeleton" style={{ height: 120 }} />
      </div>
    )
  }

  const content = getPlanCardContent({
    tab,
    contentSub: tab === 'content' ? contentSub : null,
    chatbotSub: tab === 'chatbot' ? chatbotSub : null,
    plan: status.plan,
    accessMode: status.accessMode,
    usage: {
      chatMessagesThisMonth: status.usage.chatMessagesThisMonth,
      topicCreditsThisMonth: status.usage.topicCreditsThisMonth,
      postsThisMonth: status.usage.postsThisMonth,
      leadsCount: status.usage.leadsCount,
      formsCount: status.usage.formsCount,
    },
    limits: {
      chatMessagesPerMonth: status.limits.chatMessagesPerMonth,
      topicCreditsPerMonth: status.limits.topicCreditsPerMonth,
      postsPerMonth: status.limits.postsPerMonth,
      maxLeads: status.limits.maxLeads,
      maxForms: status.limits.maxForms,
    },
    subscriptionStatus: normaliseSubStatus(subscription?.subscriptionStatus),
    periodEnd: subscription?.currentPeriodEnd ?? null,
  })

  // Resolver returns null on surfaces with no upgrade hook (e.g. Free
  // on Chatbot, KB/indexed, KB/faqs; paid plans outside Overview).
  if (!content) {
    return <ChangePlanModal open={changePlanOpen} onClose={() => setChangePlanOpen(false)} />
  }

  const isMessageLayout = content.layout === 'message'
  const meters = planMeters(status, status.agentCredit ? minuteUsage(status.agentCredit) : null)
  const resetIso = status.agentCredit?.money?.resetAt ?? status.agentCredit?.periodEnd
  const reset = status.plan !== 'free' && resetIso && Number.isFinite(Date.parse(resetIso))
    ? formatResetDate(resetIso) : null

  return (
    <>
      <article
        className={`bb-plancard bb-plancard-${content.tone}${isMessageLayout ? ' bb-plancard-message' : ''}`}
        aria-label={`Plan: ${content.badgeLabel}`}
      >
        <BrandMark className="bb-plancard-monogram" size={28} />

        <div className="bb-plancard-id">
          <span className={`bb-tier-badge bb-tier-badge-${content.badgeKind}`}>
            {content.badgeLabel}
          </span>
          <h2 className="bb-plancard-headline">{content.headline}</h2>
          <p className="bb-plancard-sub">{content.sub}</p>
        </div>

        {content.layout === 'meters' && (
          <div className="bb-plancard-usage">
            <div className="bb-plancard-meters">
              {meters.map(meter => <UsageMeter key={meter.label} {...meter} />)}
            </div>
            {reset && <p className="bb-plancard-reset">Resets {reset}</p>}
          </div>
        )}

        <div className="bb-plancard-cta">
          <div className="bb-plancard-price">
            {content.priceLabel}
            <span className="bb-plancard-price-sub">/mo</span>
          </div>
          <button
            type="button"
            className="bb-btn bb-btn-primary"
            onClick={() => {
              if (content.ctaOpensModal) setChangePlanOpen(true)
            }}
          >
            {content.ctaLabel}
          </button>
          {content.showFineprint && (
            <p className="bb-plancard-fineprint">cancel any time</p>
          )}
        </div>
      </article>

      <ChangePlanModal open={changePlanOpen} onClose={() => setChangePlanOpen(false)} />
    </>
  )
}
