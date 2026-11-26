/**
 * Context-aware PlanCard content resolver.
 *
 * One pure function that decides what the black strip says on every
 * (tab × sub × plan × usage × subscription) combination. The
 * PlanCard component reads its return value and renders.
 *
 * Priority order — first match wins:
 *
 *   1. accessMode === 'expired'        → critical "Subscription ended" message everywhere
 *   2. Free chat-cap hit               → critical "Visitors aren't getting answers"
 *   3. Cancelled-in-grace (Pro / Pro+) → info "{tier} ends {date}. Reactivate"
 *   4. Per-tab gate biting             → tab-specific upsell ("Forms 1/1", "Topic 3/3", etc.)
 *   5. Default per-tab                 → plan-default headline (Free / Assistant / Agent active)
 *
 * Overview tab is special: regardless of the resolved tone, it shows
 * the usage-meter row. Every other tab shows the resolved message alone.
 *
 * Single source of truth — change copy here, every surface follows.
 */
import type { TabKey } from '@/hooks/useTab'

export type CardTone = 'free' | 'assistant' | 'agent' | 'warn' | 'critical'
export type CardLayout = 'meters' | 'message'

export interface CardContent {
  tone: CardTone
  badgeLabel: string
  /** Drives left-pill colour. */
  badgeKind: 'free' | 'assistant' | 'agent' | 'expired' | 'warn' | 'critical'
  headline: string
  sub: string
  /** Overview = 'meters' (usage-meter row). Every other tab = 'message'. */
  layout: CardLayout
  /** Right-side big number. '$0' / '$20' / '$49'. */
  priceLabel: string
  /** Right-side CTA label. */
  ctaLabel: string
  /** Whether the CTA should open ChangePlanModal vs do nothing. Reactivate
   *  + Upgrade + Change plan all open the same modal. */
  ctaOpensModal: boolean
  /** Show the "cancel any time" fineprint under the CTA. Hidden for paid /
   *  cancelled / expired states where it's not informative. */
  showFineprint: boolean
}

interface ResolverInput {
  tab: TabKey
  /** content sub-tab when tab === 'content'. */
  contentSub: 'indexed' | 'faqs' | 'topics' | 'generate' | 'drafts' | null
  /** chatbot sub-tab when tab === 'chatbot'. */
  chatbotSub: 'widget' | 'replies' | null
  plan: 'free' | 'pro' | 'pro_plus'
  accessMode: 'free' | 'active' | 'expired'
  usage: {
    chatMessagesThisMonth: number
    topicCreditsThisMonth: number
    postsThisMonth: number
    leadsCount: number
    formsCount: number
  }
  limits: {
    chatMessagesPerMonth: number
    topicCreditsPerMonth: number
    postsPerMonth: number
    maxLeads: number
    maxForms: number
  }
  /** Leads still locked on Free: total minus rows the peek shows. null = not loaded yet. */
  subscriptionStatus: 'trialing' | 'active' | 'cancelled' | 'expired'
  /** ISO date — when the current paid period ends. Used by cancelled-in-grace. */
  periodEnd: string | null
}

function formatDate(iso: string | null): string {
  if (!iso) return ''
  try {
    return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
  } catch {
    return ''
  }
}

export function priceForPlan(plan: 'free' | 'pro' | 'pro_plus'): string {
  if (plan === 'pro_plus') return '$49'
  if (plan === 'pro') return '$20'
  return '$0'
}

/**
 * Surfaces where Free has no upgrade hook AND paid plans don't need any
 * fair-use heads-up. Returning null on these tabs hides the strip
 * entirely — no empty "Upgrade →" CTAs.
 *
 * Critical states (expired, chat-cap, cancelled-grace, Pro chat ≥80%)
 * BYPASS this — those messages must show everywhere because they affect
 * everything.
 */
function isNoUpgradeHookSurface(
  tab: string,
  plan: 'free' | 'pro' | 'pro_plus',
): boolean {
  if (tab === 'overview') return false  // dashboard always shows
  if (plan === 'free') {
    // Free-tier surfaces with no contextual upsell — fully usable today.
    if (tab === 'chatbot') return true
    // No banner on ANY Knowledgebase sub tab: a banner that appears on some sub tabs and not others
    // pushes the whole page down as the owner clicks through them. Each sub tab says what Free
    // cannot do in place (the Find Topics limit card, the locked Generate button and its note).
    if (tab === 'content') return true
    return false
  }
  // Paid plans: outside Overview, only show the strip when a critical
  // priority above triggered (those bypass this check).
  return true
}

export function getPlanCardContent(input: ResolverInput): CardContent | null {
  const { tab, plan, accessMode, usage, limits, subscriptionStatus, periodEnd } = input

  // Overview always shows meters. Every other tab shows a single
  // contextual message. This is a layout signal, not a content
  // signal — the resolved message below still drives headline/sub/cta.
  const layout: CardLayout = tab === 'overview' ? 'meters' : 'message'

  // ─── Priority 1: accessMode === 'expired' (rare; force-revoke) ────────
  if (accessMode === 'expired') {
    return {
      tone: 'critical',
      badgeLabel: 'Expired',
      badgeKind: 'expired',
      headline: 'Your subscription has ended.',
      sub: 'Past leads, drafts, and conversations are safe. Reactivate to start using Youami again.',
      layout,
      priceLabel: priceForPlan(plan),
      ctaLabel: 'Reactivate →',
      ctaOpensModal: true,
      showFineprint: false,
    }
  }

  // ─── Priority 2: Free chat-cap reached (visitors degraded) ────────────
  if (
    plan === 'free' &&
    limits.chatMessagesPerMonth > 0 &&
    usage.chatMessagesThisMonth >= limits.chatMessagesPerMonth
  ) {
    return {
      tone: 'critical',
      badgeLabel: 'Bot paused',
      badgeKind: 'critical',
      headline: 'Visitors aren\'t getting answers.',
      sub: `You've used all ${limits.chatMessagesPerMonth} chat replies this month. The widget hides until next month or you upgrade.`,
      layout,
      priceLabel: '$0',
      ctaLabel: 'Upgrade for unlimited →',
      ctaOpensModal: true,
      showFineprint: false,
    }
  }

  // ─── Priority 3: Cancelled-in-grace (paid still inside billing period)
  if (
    (plan === 'pro' || plan === 'pro_plus') &&
    subscriptionStatus === 'cancelled' &&
    periodEnd
  ) {
    const tierName = plan === 'pro_plus' ? 'Agent' : 'Assistant'
    return {
      tone: 'warn',
      badgeLabel: tierName,
      badgeKind: 'warn',
      headline: `${tierName} ends ${formatDate(periodEnd)}.`,
      sub: 'Reactivate before then to keep everything running. After the period ends you\'ll drop to Free.',
      layout,
      priceLabel: priceForPlan(plan),
      ctaLabel: 'Reactivate →',
      ctaOpensModal: true,
      showFineprint: false,
    }
  }

  // ─── Hide-check: surfaces with no upgrade hook ────────────────────────
  // Critical states (priorities 1-3 above) already returned. From here
  // down everything is either a per-tab nudge or a plan default — both
  // are pointless on tabs that have no scope to upgrade for.
  if (isNoUpgradeHookSurface(tab, plan)) return null

  // ─── Priority 4: Per-tab gates biting on Free ─────────────────────────
  // (Knowledgebase sub tabs returned above: they carry their own in-place notes.)

  // Forms — capped on Free (max_forms from the plan_limits config).
  if (plan === 'free' && tab === 'forms' && limits.maxForms > 0 && usage.formsCount >= limits.maxForms) {
    return {
      tone: 'warn',
      badgeLabel: 'Free',
      badgeKind: 'free',
      headline: `${usage.formsCount} of ${limits.maxForms} form${limits.maxForms === 1 ? '' : 's'} used.`,
      sub: `Edit ${limits.maxForms === 1 ? 'the form you have' : 'your forms'} any time. Unlimited forms + AI-generated form schemas on Assistant.`,
      layout,
      priceLabel: '$0',
      ctaLabel: 'Upgrade to Assistant →',
      ctaOpensModal: true,
      showFineprint: false,
    }
  }

  // Leads — Free sees every lead. The paywall is the paid output on each one.
  if (plan === 'free' && tab === 'leads' && usage.leadsCount > 0) {
    return {
      tone: 'warn',
      badgeLabel: 'Free',
      badgeKind: 'free',
      headline: 'Every lead is here. The follow-up is not.',
      sub: 'Upgrade to Assistant to read each chat and form submission, run AI research, draft follow-ups, and push leads to your CRM.',
      layout,
      priceLabel: '$0',
      ctaLabel: 'Upgrade to Assistant →',
      ctaOpensModal: true,
      showFineprint: false,
    }
  }

  // Conversations — transcripts gated on Free. Show only when ≥1 conv exists,
  // otherwise the default Free message reads better.
  if (plan === 'free' && tab === 'conversations' && usage.chatMessagesThisMonth > 0) {
    return {
      tone: 'warn',
      badgeLabel: 'Free',
      badgeKind: 'free',
      headline: 'Your latest conversation is open on Free.',
      sub: 'Read it in full. Upgrade to Assistant for every transcript, follow-ups on leads, and live takeover.',
      layout,
      priceLabel: '$0',
      ctaLabel: 'Upgrade to Assistant →',
      ctaOpensModal: true,
      showFineprint: false,
    }
  }

  // Pro fair-use heads-up: chat approaching cap (Pro tier — Pro+ has no chat cap).
  if (plan === 'pro' && limits.chatMessagesPerMonth > 0 && usage.chatMessagesThisMonth >= limits.chatMessagesPerMonth * 0.8) {
    const remaining = Math.max(0, limits.chatMessagesPerMonth - usage.chatMessagesThisMonth)
    return {
      tone: 'warn',
      badgeLabel: 'Assistant',
      badgeKind: 'assistant',
      headline: `${remaining} of ${limits.chatMessagesPerMonth.toLocaleString()} chat replies left this month.`,
      sub: 'Heads up on the Assistant fair-use ceiling. Resets on the 1st. Email support@youami.ai for higher limits.',
      layout,
      priceLabel: priceForPlan(plan),
      ctaLabel: 'Change plan →',
      ctaOpensModal: true,
      showFineprint: false,
    }
  }

  // ─── Priority 5: Defaults per plan ────────────────────────────────────
  // Agent active: the balance, top-ups and Change plan all live in the
  // Usage & credits drawer (plan chip, top right), so the dashboard strip is
  // not shown. Expired pro_plus is caught by the accessMode check up top, so
  // reaching here means the agent is active.
  if (plan === 'pro_plus') {
    return null
  }

  if (plan === 'pro') {
    return {
      tone: 'assistant',
      badgeLabel: 'Assistant',
      badgeKind: 'assistant',
      headline: 'Assistant active.',
      sub: 'Your bot, your voice.',
      layout,
      priceLabel: '$20',
      ctaLabel: 'Change plan →',
      ctaOpensModal: true,
      showFineprint: false,
    }
  }

  // Free default. Headline / sub varies slightly by tab so the message
  // matches the surface; CTA is constant.
  const FREE_DEFAULTS: Partial<Record<TabKey, { headline: string; sub: string }>> = {
    overview: {
      headline: 'Free forever.',
      sub: 'Your bot is live. Upgrade to unlock leads, AI posts, and integrations.',
    },
    chatbot: {
      headline: 'Tune your bot. Free on every plan.',
      sub: 'Voice, instructions, look & feel. All editable. Upgrade unlocks the rest of Youami.',
    },
    content: {
      headline: 'Free forever.',
      sub: 'Index pages and curate FAQs on Free. AI topic research, post drafts, and scheduling are on Assistant.',
    },
    forms: {
      headline: 'Free forever.',
      // Count comes from the plan_limits config, so this never drifts when
      // the Free form allowance changes.
      sub: `${limits.maxForms} form${limits.maxForms === 1 ? '' : 's'} on Free. Upgrade to Assistant for unlimited forms and AI form generation.`,
    },
    leads: {
      headline: 'Free forever.',
      sub: 'Your bot will start capturing visitor emails as soon as they share them. Upgrade to view + follow up.',
    },
    conversations: {
      headline: 'Free forever.',
      sub: 'Once visitors start chatting, threads land here. Upgrade to read transcripts and take over live.',
    },
    integrations: {
      // PlanCard is hidden on Agent tab today, but include for completeness.
      headline: 'Integrations are on Agent.',
      sub: 'Replies on Facebook + Instagram, push to Zoho CRM, knows your WooCommerce products. From $49/mo.',
    },
  }
  const def = FREE_DEFAULTS[tab] ?? FREE_DEFAULTS.overview!
  return {
    tone: 'free',
    badgeLabel: 'Free',
    badgeKind: 'free',
    headline: def.headline,
    sub: def.sub,
    layout,
    priceLabel: '$0',
    ctaLabel: tab === 'integrations' ? 'Upgrade to Agent →' : 'Upgrade →',
    ctaOpensModal: true,
    showFineprint: true,
  }
}
