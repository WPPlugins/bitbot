/**
 * NextBestStep — coral-stripe pull-quote card in the right sidebar. Auto-
 * picks the highest-impact unconfigured / underused feature to surface,
 * so the admin keeps making forward progress without us hand-curating per
 * site.
 *
 * Detection order is intentional — checks for the most catastrophic gaps
 * first (bot has nothing to answer with → not even worth talking about
 * leads), then natural onboarding milestones (greeting, chips, guardrails,
 * forms), then traffic-driven upsells (trial wall, Agent recommendation).
 *
 * Each detector returns a recommendation with a `met` flag. We surface
 * the first unmet one. If every box is checked, we celebrate with a
 * quiet "all set" card — not blank space.
 */
import { useMemo } from 'react'
import { useAdminSettings } from '@/hooks/useAdminSettings'
import { useSyncStatus } from '@/hooks/useSyncStatus'
import { usePlanStatus } from '@/hooks/usePlanStatus'
import { useOverviewInsights } from '@/hooks/useOverview'
import { useMetaIntegration } from '@/hooks/useMeta'
import { useWooCommerceStatus } from '@/hooks/useWooCommerce'
import { useZohoIntegration } from '@/hooks/useZoho'

// Critical onboarding steps always win if unmet — an unindexed bot or one with
// nowhere to send leads shouldn't be pitched integrations. Everything past
// these is randomized so the same card doesn't nag forever.
const CRITICAL_KEYS = ['sync-content', 'notification-email']

function ArrowRight() {
  return (
    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="1.5">
      <path d="M5 12h14M13 5l7 7-7 7" />
    </svg>
  )
}

interface Recommendation {
  key: string
  eyebrow: string
  headline: string
  body: string
  ctaLabel: string
  /** Where the CTA sends the admin. Plain tab slug ("chatbot") or a
   *  sub-tab deep-link ("chatbot:behaviour", "leads"). */
  goTo: string
  /** Whether this setting is already configured. */
  met: boolean
}

function detectAll(args: {
  pagesCount: number
  hasNotificationEmail: boolean
  chipsCount: number
  hasGuardrails: boolean
  hasInstructions: boolean
  accessMode: 'free' | 'active' | 'expired'
  conversations7d: number
  plan: 'free' | 'pro' | 'pro_plus'
  metaConnected: boolean
  wcConfigured: boolean
  zohoConnected: boolean
}): Recommendation[] {
  const recs: Recommendation[] = []

  // 1. Indexed content.
  recs.push({
    key: 'sync-content',
    met: args.pagesCount > 0,
    eyebrow: args.pagesCount > 0 ? 'Indexed' : 'Start here',
    headline: args.pagesCount > 0
      ? `Indexed ${args.pagesCount} ${args.pagesCount === 1 ? 'page' : 'pages'}.`
      : 'Index your site so the bot can answer.',
    body: args.pagesCount > 0
      ? 'Re-sync any time you publish new content.'
      : 'Youami reads your pages and posts to ground every reply. Trigger the first sync, it takes about 90 seconds.',
    ctaLabel: args.pagesCount > 0 ? 'Open Knowledgebase' : 'Sync content',
    goTo: 'content',
  })

  // 2. Notification email.
  recs.push({
    key: 'notification-email',
    met: args.hasNotificationEmail,
    eyebrow: args.hasNotificationEmail ? 'Lead notifications' : "You'll miss leads otherwise",
    headline: args.hasNotificationEmail
      ? 'Lead notification email is set.'
      : 'Tell us where new leads should go.',
    body: args.hasNotificationEmail
      ? 'New leads route to your inbox the moment they land.'
      : 'Right now, when a visitor leaves their email, the notification has nowhere to land. Add your inbox in 30 seconds.',
    ctaLabel: args.hasNotificationEmail ? 'Edit email' : 'Set notification email',
    goTo: 'account:notificationEmail',
  })

  // 3. Starter chips.
  recs.push({
    key: 'chips',
    met: args.chipsCount > 0,
    eyebrow: args.chipsCount > 0 ? 'Starter chips' : 'Quick win',
    headline: args.chipsCount > 0
      ? `${args.chipsCount} starter ${args.chipsCount === 1 ? 'chip' : 'chips'} live.`
      : 'Add 3 starter questions.',
    body: args.chipsCount > 0
      ? 'Visitors see these as one-tap conversation starters in the widget.'
      : 'Sites with at least 3 starter chips get 2.4× more conversations. Suggest the questions visitors actually ask, like "How much does it cost?" or "Do you ship internationally?".',
    ctaLabel: args.chipsCount > 0 ? 'Edit chips' : 'Add chips',
    goTo: 'chatbot',
  })

  // 4. Off-topic guardrails.
  recs.push({
    key: 'guardrails',
    met: args.hasGuardrails,
    eyebrow: args.hasGuardrails ? 'Guardrails set' : 'Stay on-message',
    headline: args.hasGuardrails
      ? 'Off-topic guardrails are in place.'
      : 'Tell the bot what NOT to discuss.',
    body: args.hasGuardrails
      ? 'The bot refuses the topics you listed and steers back to what you can help with.'
      : 'Stop the bot from drifting into competitor talk, refund promises, or anything else off-brand. Two lines of guidance is usually enough.',
    ctaLabel: args.hasGuardrails ? 'Edit guardrails' : 'Add guardrails',
    goTo: 'guardrails',
  })

  // 6. Bot instructions.
  recs.push({
    key: 'instructions',
    met: args.hasInstructions,
    eyebrow: args.hasInstructions ? 'Voice set' : 'Sound like you',
    headline: args.hasInstructions
      ? 'Bot voice is set.'
      : 'Give the bot your voice.',
    body: args.hasInstructions
      ? 'Every reply respects the style note you wrote.'
      : 'A short style note ("be friendly, lean concise, always recommend booking a demo for pricing questions") shapes every reply.',
    ctaLabel: args.hasInstructions ? 'Edit instructions' : 'Set instructions',
    goTo: 'instructions',
  })

  // Agent-tier activation hints. Only for pro_plus, surfacing the capabilities
  // they're paying for but haven't switched on yet. Each becomes a candidate
  // for the randomized pick below until it's connected.
  if (args.plan === 'pro_plus') {
    recs.push({
      key: 'connect-meta',
      met: args.metaConnected,
      eyebrow: args.metaConnected ? 'Messenger + Instagram' : 'Answer DMs too',
      headline: args.metaConnected ? 'Facebook & Instagram are connected.' : 'Answer Facebook & Instagram DMs.',
      body: args.metaConnected
        ? 'The agent replies to Messenger and Instagram DMs with the same brain it uses on your site.'
        : 'Connect your Facebook Page and the agent answers Messenger and Instagram DMs automatically — same knowledge and skills as your website widget.',
      ctaLabel: args.metaConnected ? 'Manage Messenger' : 'Connect Facebook & Instagram',
      goTo: 'integrations',
    })
    recs.push({
      key: 'connect-woo',
      met: args.wcConfigured,
      eyebrow: args.wcConfigured ? 'WooCommerce' : 'Know your store',
      headline: args.wcConfigured ? 'WooCommerce is connected.' : 'Let the agent know your store.',
      body: args.wcConfigured
        ? 'The agent can answer product, order and stock questions, and look up a verified customer’s own orders.'
        : 'Connect WooCommerce so the agent can answer product, order and stock questions — and look up a customer’s own orders in support.',
      ctaLabel: args.wcConfigured ? 'Manage WooCommerce' : 'Connect WooCommerce',
      goTo: 'integrations',
    })
    recs.push({
      key: 'connect-zoho',
      met: args.zohoConnected,
      eyebrow: args.zohoConnected ? 'Zoho CRM' : 'Sync your leads',
      headline: args.zohoConnected ? 'Leads sync to Zoho CRM.' : 'Send leads straight to Zoho CRM.',
      body: args.zohoConnected
        ? 'Every captured lead flows into your Zoho pipeline automatically.'
        : 'Connect Zoho CRM and every captured lead lands in your pipeline automatically — no copy-paste.',
      ctaLabel: args.zohoConnected ? 'Manage Zoho' : 'Connect Zoho CRM',
      goTo: 'integrations',
    })
  }

  // 7. Agent upsell (only when there's clearly enough volume to justify it).
  if (args.plan === 'pro' && args.conversations7d >= 40) {
    recs.push({
      key: 'agent-upsell',
      met: false,
      eyebrow: "You're getting busy",
      headline: 'Let the Agent do the follow-up.',
      body: `${args.conversations7d} conversations this week. Agent tier turns chats into actions automatically: handover, bookings, lead enrichment.`,
      ctaLabel: 'See Agent',
      goTo: 'integrations',
    })
  }

  // Trailing all-set card (only when nothing pressing is unmet).
  const anyUnmet = recs.some((r) => !r.met)
  if (!anyUnmet) {
    recs.push({
      key: 'all-set',
      met: true,
      eyebrow: 'Looking good',
      headline: 'Everything important is configured.',
      body: 'Bot is indexed, leads route to your inbox, chips are live, voice is set. Explore the Agent tab if you want to take it further.',
      ctaLabel: 'Browse Agent',
      goTo: 'integrations',
    })
  }

  return recs
}

export function NextBestStep() {
  const { settings, isLoading: settingsLoading } = useAdminSettings()
  const { data: sync, isLoading: syncLoading } = useSyncStatus()
  const { data: plan, isLoading: planLoading } = usePlanStatus()
  const { data: insights, isLoading: insightsLoading } = useOverviewInsights()
  // Agent-tier integration states. The hooks always run (rules of hooks);
  // they're cheap + cached, and only become candidate hints on pro_plus.
  const { data: meta } = useMetaIntegration()
  const { data: woo } = useWooCommerceStatus()
  const { data: zoho } = useZohoIntegration()
  // Goto target may be a plain tab slug ("chatbot", "integrations") or
  // a sub-tab deep-link ("chatbot:behaviour", "chatbot:leads"). We push
  // both ?tab and ?sub onto the URL and emit a popstate so the active
  // tab + sub-tab hooks re-read it.
  const goToTab = (key: string) => {
    // Notification email moved to the Leads top-level tab. Bot voice +
    // guardrails moved to Chatbot → Behaviour. Redirect legacy account:
    // and guardrails/instructions keys so older Overview recs keep working.
    if (key === 'guardrails' || key === 'instructions') {
      key = 'chatbot:behaviour'
    } else if (key.startsWith('account')) {
      key = 'leads'
    }
    const [tab, sub] = key.includes(':') ? key.split(':') : [key, null]
    const url = new URL(window.location.href)
    url.searchParams.set('tab', tab)
    if (sub) {
      url.searchParams.set('sub', sub)
    } else {
      url.searchParams.delete('sub')
    }
    window.history.pushState({}, '', url)
    window.dispatchEvent(new PopStateEvent('popstate'))
  }

  // Detect runs every render; cheap. While the signals are loading,
  // detectAll() falls back to safe defaults and produces a sensible
  // (if optimistic) list — we just render a skeleton below if needed.
  const metaConnected = !!(meta?.integration && meta.integration.status === 'connected' && (meta.integration.pages?.length ?? 0) > 0)
  const wcConfigured = !!woo?.configured
  const zohoConnected = zoho?.integration?.status === 'connected'

  const recs = useMemo(
    () =>
      detectAll({
        pagesCount: sync?.pagesCount ?? 0,
        hasNotificationEmail: !!(settings?.notificationEmail?.trim()),
        chipsCount: settings?.suggestedQuestions?.length ?? 0,
        hasGuardrails: !!(settings?.offTopicGuardrails?.trim()),
        hasInstructions: !!(settings?.customInstructions?.trim()),
        accessMode: plan?.accessMode ?? 'free',
        conversations7d: insights?.conversations ?? 0,
        plan: plan?.plan ?? 'free',
        metaConnected,
        wcConfigured,
        zohoConnected,
      }),
    [sync?.pagesCount, settings, plan, insights?.conversations, metaConnected, wcConfigured, zohoConnected],
  )

  // Pick which step to surface: critical onboarding first (fixed), otherwise
  // a RANDOM unmet step — stable per unmet-set so it doesn't reshuffle on each
  // render, but varies between sessions/once a step is completed.
  const pickedKey = useMemo(() => {
    const unmet = recs.filter((r) => !r.met)
    if (unmet.length === 0) return null
    const critical = unmet.find((r) => CRITICAL_KEYS.includes(r.key))
    if (critical) return critical.key
    return unmet[Math.floor(Math.random() * unmet.length)].key
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [recs.map((r) => `${r.key}:${r.met ? 1 : 0}`).join('|')])

  // Skeleton during initial fetch — must come AFTER the hooks above so
  // hook count stays stable across renders (React rule of hooks).
  if (settingsLoading || syncLoading || planLoading) {
    return (
      <section className="bb-nbs">
        <div className="bb-skeleton" style={{ height: 120, borderRadius: 6 }} />
      </section>
    )
  }

  // Pick the single most-impactful unmet rec, or fall through to the
  // trailing all-set celebration when nothing is pressing.
  const rec = recs.find((r) => r.key === pickedKey) ?? recs[recs.length - 1]
  if (!rec) return null

  return (
    <section className="bb-nbs" data-recommendation={rec.key} data-met={rec.met ? 'true' : 'false'}>
      <div className="bb-nbs-icon" aria-hidden>
        <ArrowRight />
      </div>
      <div className="bb-nbs-eyebrow">{rec.eyebrow}</div>
      <h4 className="bb-nbs-headline">{rec.headline}</h4>
      <p className="bb-nbs-body">{rec.body}</p>
      <button
        className="bb-btn bb-btn-primary"
        onClick={() => goToTab(rec.goTo)}
        disabled={insightsLoading && rec.key === 'all-set'}
      >
        {rec.ctaLabel} →
      </button>
    </section>
  )
}
