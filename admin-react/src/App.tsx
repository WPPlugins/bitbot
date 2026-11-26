/**
 * App shell — top-level layout that renders on every tab:
 *   BrandBar  → TabStrip  → (active tab content)  → footer
 *
 * Plan card lives inside individual tabs (not the shell) so it can
 * coexist with tab-specific layouts that don't always want it at the
 * very top (e.g. Chatbot tab puts sync-strip directly under plan card).
 */
import { useEffect, useState } from 'react'
import { BrandMark } from '@/components/ui/BrandMark'
import { useQueryClient } from '@tanstack/react-query'
import { useTab } from '@/hooks/useTab'
import type { TabKey } from '@/hooks/useTab'
import { getConfig, planLabel } from '@/lib/config'
import { useCurrentPlan } from '@/hooks/useCurrentPlan'
import { toast } from '@/components/ui/Toast'
import { Overview } from '@/tabs/Overview'
import { Chatbot } from '@/tabs/Chatbot'
import { Leads } from '@/tabs/Leads'
import { Conversations } from '@/tabs/Conversations'
import { Content } from '@/tabs/Content'
import { Forms } from '@/tabs/Forms'
import { Integrations } from '@/tabs/Integrations'
import { AccountDrawer } from '@/components/account/AccountDrawer'
import { useRegenerateKey } from '@/hooks/useReconnect'
import { useMenuBadgeCounts } from '@/hooks/useLeads'
import { useNewDraftsCount } from '@/hooks/useContent'
import { PlanCard } from '@/components/plan/PlanCard'
import { ErrorBoundary } from '@/components/ui/ErrorBoundary'
import { BackgroundPrefetch } from '@/components/BackgroundPrefetch'
import { captureEvent } from '@/lib/telemetry'
import { AUTH_ERROR_EVENT } from '@/api/rpc'
import './styles/app.css'

// bb_b479…a48 — show enough to identify, hide the rest.
function maskKey(key: string): string {
  if (!key || key.length < 12) return key || '—'
  return `${key.slice(0, 6)}…${key.slice(-4)}`
}

const TAB_LABELS: Record<TabKey, string> = {
  overview: 'Overview',
  chatbot: 'Chatbot',
  // Slug stays 'content' for deep-link compatibility; label flips to
  // "Knowledgebase" since the tab now leads with indexed pages + FAQs,
  // and post generation is a downstream consequence of that knowledge.
  content: 'Knowledgebase',
  forms: 'Forms',
  leads: 'Leads',
  conversations: 'Conversations',
  // Tab slug stays 'integrations' so existing deep-links keep working;
  // we just rename the display label. The whole surface lives behind the
  // Agent tier so the label maps 1:1 with what you're upgrading to.
  integrations: 'Agent',
}

const SHIPPED_TABS: TabKey[] = ['overview', 'chatbot', 'leads', 'conversations', 'content', 'forms', 'integrations']

function App() {
  const [tab, setTab] = useTab()
  const [accountOpen, setAccountOpen] = useState(false)
  const cfg = getConfig()
  const livePlan = useCurrentPlan()
  const qc = useQueryClient()
  const regenerate = useRegenerateKey()
  const [confirmRegen, setConfirmRegen] = useState(false)
  // Set when ANY rpc comes back "Invalid API key" — the backend has no row
  // for this site's key (registration never persisted it). Data hooks
  // swallow failures into empty states, so without this the admin just
  // looks blank. Surface a clear error + one-click regenerate instead.
  const [authError, setAuthError] = useState<string | null>(null)

  useEffect(() => {
    const onAuthError = (e: Event) => {
      const detail = (e as CustomEvent<{ error: string }>).detail
      setAuthError(detail?.error || 'Invalid API key')
    }
    window.addEventListener(AUTH_ERROR_EVENT, onAuthError)
    return () => window.removeEventListener(AUTH_ERROR_EVENT, onAuthError)
  }, [])

  const doRegenerate = () => {
    regenerate.mutate(undefined, {
      onSuccess: () => {
        setConfirmRegen(false)
        // New key is live in WP options; reload so the SPA picks it up.
        setTimeout(() => window.location.reload(), 600)
      },
    })
  }

  // Stripe checkout return. The PHP boot already busted its plan cache
  // when it saw `?subscription=success`, so cfg.plan is fresh by the
  // time we get here. But React Query is also caching plan-status +
  // subscription on its own; nudge both so any in-flight subscriber
  // sees the new tier immediately. Also strip the marker from the URL
  // so a reload doesn't re-fire the toast.
  useEffect(() => {
    const url = new URL(window.location.href)
    const flag = url.searchParams.get('subscription')
    if (!flag) return
    if (flag === 'success') {
      qc.invalidateQueries({ queryKey: ['plan-status'] })
      qc.invalidateQueries({ queryKey: ['subscription'] })
      toast.success('Payment successful. Welcome to your new plan.')
    } else if (flag === 'cancelled') {
      toast.info('Checkout cancelled. No charge made.')
    }
    url.searchParams.delete('subscription')
    url.searchParams.delete('swap')
    window.history.replaceState({}, '', url.toString())
  }, [qc])

  // Tab-strip attention badges, from ONE shared poll that also feeds the
  // top-level WP menu bubble — so the admin can tell WHICH tab the (N) is
  // hiding in, and the numbers always match the menu. Server-side
  // "unviewed" counts (stamp-on-open): a lead/conversation clears when
  // opened; a form's responses clear when its Responses tab is opened.
  const { data: badges } = useMenuBadgeCounts()
  const newDrafts = useNewDraftsCount()
  const tabBadges: Partial<Record<TabKey, number>> = {
    leads: badges?.leads ?? 0,
    conversations: badges?.conversations ?? 0,
    forms: badges?.forms ?? 0,
    content: newDrafts,
  }

  const domain = (() => {
    try { return new URL(cfg.siteUrl).host } catch { return cfg.siteUrl }
  })()

  return (
    <div className="bb-app">
      {/* ── Brand bar ───────────────────────────────────────────────── */}
      <header className="bb-brandbar">
        <div className="bb-brandbar-left">
          <BrandMark className="bb-brandmark" size={36} />
          <div className="bb-brandbar-meta">
            <div className="bb-brandbar-title">Youami</div>
            <div className="bb-brandbar-sub">
              <span className="bb-brandbar-key">
                <span className="bb-brandbar-keylabel">API key</span>
                <code title={`API key for ${domain}`}>{maskKey(cfg.apiKey)}</code>
                <button
                  type="button"
                  className="bb-brandbar-iconbtn bb-brandbar-regen"
                  onClick={() => setConfirmRegen((v) => !v)}
                  title="Regenerate API key"
                  aria-label="Regenerate API key"
                >
                  ↻
                </button>
              </span>
              <span className="bb-brandbar-sep">·</span>
              <span>Plugin {cfg.pluginVersion}</span>
            </div>
          </div>
        </div>
        <div className="bb-brandbar-right">
          <button
            className="bb-pill bb-pill-tier bb-pill-account"
            onClick={() => setAccountOpen(true)}
            aria-label="Open account drawer"
          >
            {planLabel(livePlan)} <span className="bb-pill-account-caret">⌃</span>
          </button>
        </div>
      </header>

      {/* Auto recovery prompt: the backend rejected this site's API key
          (no matching sites row). Every data call fails, so rather than
          render an empty admin, surface the error + a one-click regenerate
          that issues a fresh key and re-registers the site. */}
      {authError && !regenerate.isSuccess && (
        <div className="bb-regen-bar bb-regen-bar-error" role="alert">
          <span className="bb-regen-text">
            <strong>Youami can’t connect.</strong> The server didn’t recognise this
            site’s API key, so nothing can load. Regenerate the key to reconnect.
            <span className="bb-regen-error"> {authError}</span>
          </span>
          <span className="bb-regen-actions">
            <button
              className="bb-btn bb-btn-primary bb-btn-small"
              onClick={doRegenerate}
              disabled={regenerate.isPending}
            >
              {regenerate.isPending ? 'Reconnecting…' : 'Regenerate API key & reconnect'}
            </button>
          </span>
        </div>
      )}

      {/* Manual recovery: regenerate the API key + re-register. For when the
          bot won't load / the site won't connect. Styled confirm, no native
          dialog. */}
      {confirmRegen && (
        <div className="bb-regen-bar">
          <span className="bb-regen-text">
            Issue a <strong>new API key</strong> and reconnect this site? Use this only if
            the bot isn’t loading or won’t connect.
            {regenerate.isError && (
              <span className="bb-regen-error"> {(regenerate.error as Error)?.message || 'Reconnect failed.'}</span>
            )}
          </span>
          <span className="bb-regen-actions">
            <button
              className="bb-btn bb-btn-primary bb-btn-small"
              onClick={doRegenerate}
              disabled={regenerate.isPending}
            >
              {regenerate.isPending ? 'Reconnecting…' : 'Regenerate & reconnect'}
            </button>
            <button
              className="bb-btn bb-btn-ghost bb-btn-small"
              onClick={() => setConfirmRegen(false)}
              disabled={regenerate.isPending}
            >
              Cancel
            </button>
          </span>
        </div>
      )}

      <AccountDrawer open={accountOpen} onOpenChange={setAccountOpen} />

      {/* 2.0.27: warms the slow-to-load tabs (Forms, Leads, Conversations)
          in the background after first paint so subsequent tab switches
          hit cache instead of waiting on the backend. Renders nothing. */}
      <BackgroundPrefetch />

      {/* ── Tab strip ───────────────────────────────────────────────── */}
      <nav className="bb-tabstrip" role="tablist" aria-label="Youami sections">
        {(Object.keys(TAB_LABELS) as TabKey[]).map((key) => {
          const shipped = SHIPPED_TABS.includes(key)
          const active = tab === key
          return (
            <button
              key={key}
              role="tab"
              aria-selected={active}
              disabled={!shipped}
              className={`bb-tab ${active ? 'bb-tab-active' : ''} ${shipped ? '' : 'bb-tab-disabled'}`}
              onClick={() => {
                if (!shipped) return
                setTab(key)
                captureEvent('tab_changed', { from: tab, to: key })
              }}
            >
              {TAB_LABELS[key]}
              {(tabBadges[key] ?? 0) > 0 && (
                <span className="bb-tab-badge" aria-label={`${tabBadges[key]} new`}>
                  {tabBadges[key]}
                </span>
              )}
              {!shipped && <span className="bb-tab-soon">SOON</span>}
            </button>
          )
        })}
      </nav>

      {/* ── Tab content ─────────────────────────────────────────────── */}
      {/* Each tab body wrapped in its own ErrorBoundary so a crash in
          one (bad RPC payload, undefined access, third-party plugin
          interference) doesn't take down the rest of the admin. */}
      <main className={`bb-container${tab === 'integrations' ? ' bb-container-wide' : ''}`}>
        {/* 2.0.3: PlanCard lifted out of individual tabs into the shell
            so trial / expired state surfaces on every tab, not just the
            three (Overview, Chatbot, Content) that imported it.
            2.0.37: hide on the Agent tab when the site is NOT on Pro+,
            since the tab itself becomes a dedicated upgrade pitch — the
            Assistant trial card on top would double-CTA and confuse the
            message.
            2.0.77: hide on the Agent tab in EVERY plan state. Once the
            agent is activated this card duplicates the chat surface
            ('Agent active. Youami is acting on your conversations.' is
            already obvious from the tab itself) and the Manage-billing
            shortcut belongs in plan settings, not the working surface. */}
        {tab !== 'integrations' && <PlanCard />}
        <ErrorBoundary key={tab} boundary={tab}>
          {tab === 'overview' && <Overview />}
          {tab === 'chatbot' && <Chatbot />}
          {tab === 'content' && <Content />}
          {tab === 'forms' && <Forms />}
          {tab === 'leads' && <Leads />}
          {tab === 'conversations' && <Conversations />}
          {tab === 'integrations' && <Integrations />}
        </ErrorBoundary>
      </main>

      {/* ── Footer ──────────────────────────────────────────────────── */}
      <footer className="bb-footer">
        <span>Youami · </span>
        <a href="https://wordpress.org/plugins/bitbot/" target="_blank" rel="noopener noreferrer">Documentation</a>
        <span className="bb-brandbar-sep">·</span>
        <a href="mailto:support@youami.ai">Support</a>
      </footer>
    </div>
  )
}

export default App
