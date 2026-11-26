/**
 * ChangePlanModal — single entry point for all plan changes.
 *
 * Replaces the scattered tier-specific CTAs ("Get Assistant", "Upgrade
 * to Agent", "or go straight to Agent", "Manage billing") with one
 * modal showing both paid tiers side-by-side. Current plan is shown
 * as a header badge; buttons per column reflect the right transition
 * (Upgrade / Downgrade / Current / Reactivate).
 *
 * Backend wiring: every transition goes through the existing
 * `useStartCheckout` mutation which calls `CreateCheckout`. That RPC
 * already handles every case (new checkout, in-place subscription
 * swap with proration, reactivation) — no new endpoints needed.
 *
 * Downgrade (Pro+ → Pro / Free) intercepts with a confirmation modal
 * listing what stops working. Upgrades go straight to checkout / swap.
 *
 * See: Obsidian Vault/bitbot/BitBot/Freemium Migration Spec.md
 */
import { useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { useStartCheckout, useCancelSubscription, useReactivateSubscription } from '@/hooks/useSubscription'
import { usePlanStatus } from '@/hooks/usePlanStatus'
import { useSubscription } from '@/hooks/useSubscription'
import { priceForPlan } from '@/components/plan/planCardContent'

interface ChangePlanModalProps {
  open: boolean
  onClose: () => void
}

type Tier = 'pro' | 'pro_plus'

function formatDate(iso: string | null): string {
  if (!iso) return ''
  try {
    return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
  } catch {
    return ''
  }
}

export function ChangePlanModal({ open, onClose }: ChangePlanModalProps) {
  const { data: status } = usePlanStatus()
  const { data: subscription } = useSubscription()
  const startCheckout = useStartCheckout()
  const cancel = useCancelSubscription()
  const reactivate = useReactivateSubscription()

  const [confirmDowngrade, setConfirmDowngrade] = useState<Tier | 'free' | null>(null)

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open) return null
  if (!status) return null

  const plan = status.plan
  const accessMode = status.accessMode
  const periodEnd = subscription?.currentPeriodEnd ?? null
  const cancelled = subscription?.subscriptionStatus === 'cancelled'

  const headerText = (() => {
    if (accessMode === 'expired') return 'Expired — subscribe to continue'
    if (plan === 'pro' && cancelled) return `Assistant · ends ${formatDate(periodEnd)}`
    if (plan === 'pro_plus' && cancelled) return `Agent · ends ${formatDate(periodEnd)}`
    if (plan === 'pro') return `Assistant · active${periodEnd ? ` · renews ${formatDate(periodEnd)}` : ''}`
    if (plan === 'pro_plus') return `Agent · active${periodEnd ? ` · renews ${formatDate(periodEnd)}` : ''}`
    return `Free · ${status.usage.leadsCount} leads · ${status.usage.chatMessagesThisMonth}/${status.limits.chatMessagesPerMonth} chat this month`
  })()

  const buttonForTier = (tier: Tier): { label: string; action: 'upgrade' | 'downgrade' | 'current' | 'reactivate'; tone: 'primary' | 'ghost' | 'danger' } => {
    // Current plan, active
    if (plan === tier && accessMode === 'active' && !cancelled) {
      return { label: 'Current plan', action: 'current', tone: 'ghost' }
    }
    // Current plan, cancelled — show Reactivate
    if (plan === tier && cancelled) {
      return { label: `Reactivate ${tier === 'pro' ? 'Assistant' : 'Agent'}`, action: 'reactivate', tone: 'primary' }
    }
    // Expired
    if (accessMode === 'expired') {
      return { label: `Subscribe — ${tier === 'pro' ? 'Assistant' : 'Agent'}`, action: 'upgrade', tone: 'primary' }
    }
    // Downgrade Pro+ → Pro
    if (plan === 'pro_plus' && tier === 'pro') {
      return { label: 'Downgrade →', action: 'downgrade', tone: 'danger' }
    }
    // Switch Pro → Pro+ (upgrade)
    if (plan === 'pro' && tier === 'pro_plus') {
      return { label: 'Upgrade to Agent →', action: 'upgrade', tone: 'primary' }
    }
    // Free → either tier
    return { label: `Upgrade to ${tier === 'pro' ? 'Assistant' : 'Agent'} →`, action: 'upgrade', tone: 'primary' }
  }

  const handleClick = (tier: Tier) => {
    const { action } = buttonForTier(tier)
    if (action === 'current') return
    if (action === 'reactivate') {
      reactivate.mutate()
      return
    }
    if (action === 'downgrade') {
      setConfirmDowngrade(tier)
      return
    }
    // upgrade — direct
    startCheckout.mutate(tier)
  }

  const handleDowngradeConfirm = () => {
    if (!confirmDowngrade) return
    if (confirmDowngrade === 'free') {
      // Pro / Pro+ → Free is "cancel at period end". They keep working
      // until billing period closes, then drop to Free per the soft-
      // landing logic in the Stripe webhook.
      cancel.mutate()
    } else {
      startCheckout.mutate(confirmDowngrade)
    }
    setConfirmDowngrade(null)
  }

  const pending = startCheckout.isPending || cancel.isPending || reactivate.isPending

  // Portal to document.body so the fixed-position backdrop escapes
  // any ancestor that creates a CSS containing block (transform /
  // filter / will-change). Without this, mounting the modal from
  // deep inside Conversations / Leads makes `position: fixed`
  // render relative to the panel, not the viewport — the bug that
  // caused the "modal stuck in the right pane" report on 2.0.122.
  return createPortal(
    <div className="bb-modal-backdrop bb-portal-scope" onClick={onClose}>
      <div className="bb-modal bb-changeplan-modal" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true">
        <header className="bb-modal-head">
          <div className="bb-modal-titles">
            <h3 className="bb-modal-title">Change plan</h3>
            <span className="bb-modal-aside">{headerText}</span>
          </div>
          <button className="bb-modal-close" onClick={onClose} aria-label="Close">×</button>
        </header>
        <div className="bb-modal-body">
          <div className="bb-changeplan-grid">
            {/* Assistant */}
            <PlanColumn
              name="Assistant"
              price={priceForPlan('pro')}
              tagline="Everything in Free, plus full access."
              features={[
                '10,000 chat messages per month',
                'All your captured leads, unlocked',
                'AI post generation, subject to fair-use limits',
                'Unlimited topic searches',
                'Unlimited forms',
                'Stage tracking, notes, AI lead research',
                'Voice calls, 30 minutes a month',
              ]}
              button={buttonForTier('pro')}
              onClick={() => handleClick('pro')}
              disabled={pending}
            />
            {/* Agent */}
            <PlanColumn
              name="Agent"
              price={priceForPlan('pro_plus')}
              tagline="Everything in Assistant, plus integrations and the AI co-pilot."
              features={[
                'Replies on Facebook and Instagram',
                'Sends leads to Zoho CRM',
                'Knows your WooCommerce products, orders & stock',
                "Acts on your behalf: books, updates, follows up",
                'Your own AI co-pilot inside the admin',
                'Voice calls paid from your credit pool',
              ]}
              button={buttonForTier('pro_plus')}
              onClick={() => handleClick('pro_plus')}
              disabled={pending}
            />
          </div>

          {/* Pro / Pro+ active users get a downgrade-to-Free link */}
          {(plan === 'pro' || plan === 'pro_plus') && !cancelled && accessMode === 'active' && (
            <div className="bb-changeplan-cancel">
              <button
                type="button"
                className="bb-changeplan-cancel-link"
                disabled={pending}
                onClick={() => setConfirmDowngrade('free')}
              >
                Cancel and drop to Free →
              </button>
              <span className="bb-changeplan-cancel-note">You'll keep paid access until {formatDate(periodEnd) || 'your period ends'}.</span>
            </div>
          )}
        </div>
      </div>

      {/* Downgrade confirmation overlay */}
      {confirmDowngrade && (
        <DowngradeConfirm
          target={confirmDowngrade}
          currentPlan={plan}
          periodEnd={periodEnd}
          onCancel={() => setConfirmDowngrade(null)}
          onConfirm={handleDowngradeConfirm}
          pending={pending}
        />
      )}
    </div>,
    document.body
  )
}

interface PlanColumnProps {
  name: string
  price: string
  tagline: string
  features: string[]
  button: { label: string; action: 'upgrade' | 'downgrade' | 'current' | 'reactivate'; tone: 'primary' | 'ghost' | 'danger' }
  onClick: () => void
  disabled: boolean
}

function PlanColumn({ name, price, tagline, features, button, onClick, disabled }: PlanColumnProps) {
  const cls = button.tone === 'primary'
    ? 'bb-btn bb-btn-primary'
    : button.tone === 'danger'
      ? 'bb-btn bb-btn-ghost bb-changeplan-btn-danger'
      : 'bb-btn bb-btn-ghost'
  return (
    <article className={`bb-changeplan-col ${button.action === 'current' ? 'bb-changeplan-col-current' : ''}`}>
      <header>
        <h4 className="bb-changeplan-name">{name}</h4>
        <p className="bb-changeplan-price">
          {price}<span className="bb-changeplan-price-sub">/mo</span>
        </p>
        <p className="bb-changeplan-tagline">{tagline}</p>
      </header>
      <ul className="bb-changeplan-features">
        {features.map((f) => (
          <li key={f}>{f}</li>
        ))}
      </ul>
      <button
        type="button"
        className={cls}
        disabled={disabled || button.action === 'current'}
        onClick={onClick}
      >
        {button.label}
      </button>
    </article>
  )
}

interface DowngradeConfirmProps {
  target: Tier | 'free'
  currentPlan: 'free' | 'pro' | 'pro_plus'
  periodEnd: string | null
  onCancel: () => void
  onConfirm: () => void
  pending: boolean
}

function DowngradeConfirm({ target, currentPlan, periodEnd, onCancel, onConfirm, pending }: DowngradeConfirmProps) {
  const targetName = target === 'pro' ? 'Assistant' : target === 'free' ? 'Free' : 'Agent'
  const losingProPlus = currentPlan === 'pro_plus'

  // Same portal pattern as the parent — see ChangePlanModal comment.
  return createPortal(
    <div className="bb-modal-backdrop bb-modal-backdrop-stack bb-portal-scope" onClick={onCancel}>
      <div className="bb-modal bb-changeplan-confirm" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true">
        <header className="bb-modal-head">
          <h3 className="bb-modal-title">Switch to {targetName}?</h3>
          <button className="bb-modal-close" onClick={onCancel} aria-label="Cancel">×</button>
        </header>
        <div className="bb-modal-body">
          <p className="bb-changeplan-confirm-lede">
            {target === 'free'
              ? `You'll keep paid access until ${periodEnd ? formatDate(periodEnd) : 'your period ends'}, then drop to Free.`
              : `Stripe will prorate the difference and apply it to your next invoice.`}
          </p>

          {losingProPlus && (
            <>
              <p className="bb-changeplan-confirm-section">These will stop working immediately:</p>
              <ul className="bb-changeplan-confirm-list">
                <li>Replies on Facebook and Instagram <small>— inbound threads stay visible in Conversations for manual reply</small></li>
                <li>Sending leads to Zoho CRM <small>— leads already in Zoho stay; new ones won't push</small></li>
                <li>Knowing your WooCommerce products & orders</li>
                <li>Your AI co-pilot inside the admin</li>
              </ul>
              <p className="bb-changeplan-confirm-note">Connections stay in your account — re-upgrading restores them in one click, no re-setup.</p>
            </>
          )}

          {target === 'free' && (
            <>
              <p className="bb-changeplan-confirm-section">When your period ends:</p>
              <ul className="bb-changeplan-confirm-list">
                <li>Chat capped at 50 replies a month</li>
                <li>AI post generation turns off</li>
                <li>Topic research turns off</li>
                <li>Forms capped at 1</li>
              </ul>
              <p className="bb-changeplan-confirm-note">Re-upgrade any time to unlock everything.</p>
            </>
          )}

          <div className="bb-changeplan-confirm-actions">
            <button type="button" className="bb-btn bb-btn-ghost" onClick={onCancel} disabled={pending}>
              Keep {currentPlan === 'pro_plus' ? 'Agent' : 'Assistant'}
            </button>
            <button type="button" className="bb-btn bb-btn-primary" onClick={onConfirm} disabled={pending}>
              {pending ? 'Switching…' : `Switch to ${targetName} →`}
            </button>
          </div>
        </div>
      </div>
    </div>,
    document.body
  )
}
