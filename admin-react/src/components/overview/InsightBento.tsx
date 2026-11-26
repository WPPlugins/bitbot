/**
 * Section 01 of the Overview tab — 2×3 bento of action-driving signals
 * about the last 7 days. All cards are non-blocking: they read from the
 * `overview_snapshots` table via GetOverviewInsights, which the backend
 * refreshes in the background after each conversation ends
 * (waitUntil-hooked into VisitorLeft). The frontend never sits on Haiku.
 *
 *   ┌──────────────────────────┬──────────────────────────┐
 *   │ Top cited pages          │ Coverage gaps            │
 *   ├──────────────────────────┼──────────────────────────┤
 *   │ Top questions            │ Where they're from       │
 *   ├──────────────────────────┼──────────────────────────┤
 *   │ Conversation pulse       │ Lead capture             │
 *   └──────────────────────────┴──────────────────────────┘
 *
 * Each ranked card shows top 3-5 entries with a "View all →" that opens
 * a modal listing the full bundle. Cards with a natural tab destination
 * (Conversation pulse → Conversations, Lead capture → Leads) navigate
 * instead of opening a modal.
 */
import { useState } from 'react'
import { useOverviewInsights } from '@/hooks/useOverview'
import type { OverviewInsights, TopQuestion, TopCountry, CoverageGap, CitedPage } from '@/api/types'
import { ViewAllModal } from '@/components/ui/ViewAllModal'

function formatDelta(current: number, prior: number): { label: string; tone: 'up' | 'down' | 'flat' } {
  if (current === 0 && prior === 0) return { label: 'no activity yet', tone: 'flat' }
  if (prior === 0) return { label: 'new — no prior data', tone: 'up' }
  const pct = Math.round(((current - prior) / prior) * 100)
  if (pct === 0) return { label: 'flat vs prior 7d', tone: 'flat' }
  if (pct > 0) return { label: `↑${pct}% vs prior 7d`, tone: 'up' }
  return { label: `↓${Math.abs(pct)}% vs prior 7d`, tone: 'down' }
}

const EMPTY_COPY = "Not enough chat yet. Cards fill in once visitors start engaging."

function ViewAllButton({ onClick, label = 'View all' }: { onClick: () => void; label?: string }) {
  return (
    <button type="button" className="bb-insight-viewall" onClick={onClick}>
      {label} →
    </button>
  )
}

/** Navigate to another tab in the dashboard. Uses the URL-param +
 *  popstate hack the rest of the app uses (no router dependency). */
function goToTab(key: string) {
  const url = new URL(window.location.href)
  url.searchParams.set('tab', key)
  window.history.pushState({}, '', url)
  window.dispatchEvent(new PopStateEvent('popstate'))
}

// ────────────────────────────────────────────────────────────────────
// Top cited pages
// ────────────────────────────────────────────────────────────────────
function TopCitedPagesCard({ pages }: { pages: CitedPage[] }) {
  const [open, setOpen] = useState(false)
  const ctr = (p: CitedPage) => (p.servedCount > 0 ? Math.round((p.clickCount / p.servedCount) * 100) : 0)
  return (
    <article className="bb-insight-card">
      <header className="bb-insight-head">
        <span className="bb-eyebrow">Top cited pages</span>
        <span className="bb-insight-aside">what the bot leans on</span>
      </header>
      {pages.length === 0 ? (
        <p className="bb-insight-empty">{EMPTY_COPY}</p>
      ) : (
        <>
          <ul className="bb-insight-pages">
            {pages.slice(0, 3).map((p, i) => (
              <li key={i} className="bb-insight-page">
                <div className="bb-insight-page-head">
                  <a href={p.url} target="_blank" rel="noopener noreferrer" className="bb-insight-page-title">
                    {p.title ?? p.url}
                  </a>
                  <span className="bb-insight-page-served">{p.servedCount}×</span>
                </div>
                <div className="bb-insight-page-foot">
                  <span className="bb-insight-page-url">{p.url}</span>
                  <span className="bb-insight-page-ctr">{ctr(p)}% CTR</span>
                </div>
              </li>
            ))}
          </ul>
          {pages.length > 3 && <ViewAllButton onClick={() => setOpen(true)} />}
        </>
      )}
      <ViewAllModal open={open} title="All cited pages" aside={`${pages.length} pages · last 7d`} onClose={() => setOpen(false)}>
        <ol className="bb-modal-list">
          {pages.map((p, i) => (
            <li key={i} className="bb-modal-row">
              <span className="bb-modal-rank">{i + 1}</span>
              <div className="bb-modal-body-col">
                <a href={p.url} target="_blank" rel="noopener noreferrer" className="bb-modal-row-title">{p.title ?? p.url}</a>
                <span className="bb-modal-row-sub">{p.url}</span>
              </div>
              <span className="bb-modal-row-count">{p.servedCount}× · {ctr(p)}% CTR</span>
            </li>
          ))}
        </ol>
      </ViewAllModal>
    </article>
  )
}

// ────────────────────────────────────────────────────────────────────
// Coverage gaps
// ────────────────────────────────────────────────────────────────────
function CoverageGapsCard({ gaps }: { gaps: CoverageGap[] }) {
  const [open, setOpen] = useState(false)
  const goToFaq = (gap: CoverageGap) => {
    sessionStorage.setItem('bb-faq-from-gap', JSON.stringify({ topic: gap.topic, example: gap.example }))
    const url = new URL(window.location.href)
    url.searchParams.set('tab', 'content')
    // Land on the FAQs sub-pane of the Knowledgebase tab, not the
    // default Indexed pane — see useContentSubtab.
    url.searchParams.set('sub', 'faqs')
    window.history.pushState({}, '', url)
    window.dispatchEvent(new PopStateEvent('popstate'))
  }
  return (
    <article className="bb-insight-card">
      <header className="bb-insight-head">
        <span className="bb-eyebrow">Coverage gaps</span>
        {gaps.length > 0 && <span className="bb-insight-aside">{gaps.reduce((s, g) => s + g.count, 0)} unanswered</span>}
      </header>
      {gaps.length === 0 ? (
        <p className="bb-insight-empty">
          The bot's answering everything confidently — or there isn't enough chat yet to spot patterns.
        </p>
      ) : (
        <>
          <ul className="bb-insight-gaps">
            {gaps.slice(0, 2).map((g, i) => (
              <li key={i} className="bb-insight-gap">
                <div className="bb-insight-gap-head">
                  <span className="bb-insight-gap-topic">{g.topic}</span>
                  <span className="bb-insight-gap-count">{g.count} asks</span>
                </div>
                <div className="bb-insight-gap-example">"{g.example}"</div>
                <button type="button" className="bb-insight-gap-cta" onClick={() => goToFaq(g)}>
                  Auto-draft FAQ →
                </button>
              </li>
            ))}
          </ul>
          {gaps.length > 2 && <ViewAllButton onClick={() => setOpen(true)} />}
        </>
      )}
      <ViewAllModal open={open} title="All coverage gaps" aside={`${gaps.length} topics`} onClose={() => setOpen(false)}>
        <ol className="bb-modal-list">
          {gaps.map((g, i) => (
            <li key={i} className="bb-modal-row">
              <span className="bb-modal-rank">{i + 1}</span>
              <div className="bb-modal-body-col">
                <span className="bb-modal-row-title">{g.topic}</span>
                <span className="bb-modal-row-sub">"{g.example}"</span>
              </div>
              <button type="button" className="bb-modal-row-action" onClick={() => { setOpen(false); goToFaq(g) }}>
                Draft FAQ →
              </button>
            </li>
          ))}
        </ol>
      </ViewAllModal>
    </article>
  )
}

// ────────────────────────────────────────────────────────────────────
// Top questions
// ────────────────────────────────────────────────────────────────────
function TopQuestionsCard({ topics, totalMessages }: { topics: TopQuestion[]; totalMessages: number }) {
  const [open, setOpen] = useState(false)
  return (
    <article className="bb-insight-card">
      <header className="bb-insight-head">
        <span className="bb-eyebrow">Top questions</span>
        {totalMessages > 0 && <span className="bb-insight-aside">{totalMessages} messages</span>}
      </header>
      {topics.length === 0 ? (
        <p className="bb-insight-empty">{EMPTY_COPY}</p>
      ) : (
        <>
          <ol className="bb-insight-list">
            {topics.slice(0, 3).map((t, i) => (
              <li key={i} className="bb-insight-list-row">
                <span className="bb-insight-list-rank">{i + 1}</span>
                <div className="bb-insight-list-body">
                  <div className="bb-insight-list-label">{t.label}</div>
                  <div className="bb-insight-list-sub">"{t.example}"</div>
                </div>
                <span className="bb-insight-list-count">{t.count}</span>
              </li>
            ))}
          </ol>
          {topics.length > 3 && <ViewAllButton onClick={() => setOpen(true)} />}
        </>
      )}
      <ViewAllModal open={open} title="All clustered questions" aside={`${topics.length} topics`} onClose={() => setOpen(false)}>
        <ol className="bb-modal-list">
          {topics.map((t, i) => (
            <li key={i} className="bb-modal-row">
              <span className="bb-modal-rank">{i + 1}</span>
              <div className="bb-modal-body-col">
                <span className="bb-modal-row-title">{t.label}</span>
                <span className="bb-modal-row-sub">"{t.example}"</span>
              </div>
              <span className="bb-modal-row-count">{t.count}</span>
            </li>
          ))}
        </ol>
      </ViewAllModal>
    </article>
  )
}

// ────────────────────────────────────────────────────────────────────
// Top countries
// ────────────────────────────────────────────────────────────────────
function TopCountriesCard({ countries, otherVisitors }: { countries: TopCountry[]; otherVisitors: number }) {
  const [open, setOpen] = useState(false)
  const total = countries.reduce((s, c) => s + c.count, 0) + otherVisitors
  return (
    <article className="bb-insight-card">
      <header className="bb-insight-head">
        <span className="bb-eyebrow">Where they're from</span>
        <span className="bb-insight-aside">visitor IP</span>
      </header>
      {countries.length === 0 ? (
        <p className="bb-insight-empty">{EMPTY_COPY}</p>
      ) : (
        <>
          <ul className="bb-insight-bars">
            {countries.slice(0, 3).map((c, i) => {
              const pct = total > 0 ? Math.round((c.count / total) * 100) : 0
              return (
                <li key={i} className="bb-insight-bar-row">
                  <span className="bb-insight-bar-label">{c.countryName}</span>
                  <span className="bb-insight-bar-track">
                    <span className="bb-insight-bar-fill" style={{ width: `${Math.max(pct, 4)}%` }} />
                  </span>
                  <span className="bb-insight-bar-count">{c.count}</span>
                </li>
              )
            })}
            {otherVisitors > 0 && (
              <li className="bb-insight-bar-row bb-insight-bar-other">
                <span className="bb-insight-bar-label">+ {otherVisitors} other</span>
              </li>
            )}
          </ul>
          {(countries.length > 3 || otherVisitors > 0) && <ViewAllButton onClick={() => setOpen(true)} />}
        </>
      )}
      <ViewAllModal open={open} title="All countries" aside={`${countries.length} countries`} onClose={() => setOpen(false)}>
        <ol className="bb-modal-list">
          {countries.map((c, i) => (
            <li key={i} className="bb-modal-row">
              <span className="bb-modal-rank">{i + 1}</span>
              <span className="bb-modal-row-title">{c.countryName}</span>
              <span className="bb-modal-row-count">{c.count}</span>
            </li>
          ))}
          {otherVisitors > 0 && (
            <li className="bb-modal-row bb-modal-row-other">
              <span className="bb-modal-row-title">+ {otherVisitors} other visitors</span>
            </li>
          )}
        </ol>
      </ViewAllModal>
    </article>
  )
}

// ────────────────────────────────────────────────────────────────────
// Conversation pulse (nav → Conversations tab)
// ────────────────────────────────────────────────────────────────────
function ConversationPulseCard({ data }: { data: OverviewInsights }) {
  const delta = formatDelta(data.conversations, data.conversationsPrior)
  const returningPct = Math.round(data.returningVisitorRate * 100)
  return (
    <article className="bb-insight-card">
      <header className="bb-insight-head">
        <span className="bb-eyebrow">Conversation pulse</span>
        <span className="bb-insight-aside">last {data.windowDays}d</span>
      </header>
      <div className="bb-insight-hero">
        <span className="bb-insight-hero-num">{data.conversations}</span>
        <span className={`bb-insight-hero-delta bb-insight-tone-${delta.tone}`}>{delta.label}</span>
      </div>
      <dl className="bb-insight-facts">
        <div className="bb-insight-fact">
          <dt>Unique visitors</dt>
          <dd>{data.uniqueVisitors}</dd>
        </div>
        <div className="bb-insight-fact">
          <dt>Returning</dt>
          <dd>
            {returningPct}<span className="bb-insight-fact-unit">%</span>
          </dd>
        </div>
      </dl>
      <ViewAllButton onClick={() => goToTab('conversations')} label="View conversations" />
    </article>
  )
}

// ────────────────────────────────────────────────────────────────────
// Lead capture (nav → Leads tab)
// ────────────────────────────────────────────────────────────────────
function LeadCaptureCard({ data }: { data: OverviewInsights }) {
  const ratePct = Math.round(data.leadCaptureRate * 100)
  return (
    <article className="bb-insight-card">
      <header className="bb-insight-head">
        <span className="bb-eyebrow">Lead capture</span>
        <span className="bb-insight-aside">last {data.windowDays}d</span>
      </header>
      <div className="bb-insight-hero">
        <span className="bb-insight-hero-num">{ratePct}<span className="bb-insight-hero-unit">%</span></span>
        <span className="bb-insight-hero-delta bb-insight-tone-flat">capture rate</span>
      </div>
      <dl className="bb-insight-facts">
        <div className="bb-insight-fact">
          <dt>Leads captured</dt>
          <dd>{data.leadsCount}</dd>
        </div>
        <div className="bb-insight-fact">
          <dt>From {data.conversations} conversations</dt>
          <dd>
            {data.leadsCount === 0 && data.conversations > 0
              ? 'no captures yet'
              : `${data.leadsCount} of ${data.conversations}`}
          </dd>
        </div>
      </dl>
      <ViewAllButton onClick={() => goToTab('leads')} label="View leads" />
    </article>
  )
}

export function InsightBento() {
  const { data, isLoading, isError } = useOverviewInsights()

  if (isLoading) {
    return (
      <div className="bb-insight-bento">
        {[0, 1, 2, 3, 4, 5].map((i) => (
          <div key={i} className="bb-insight-card">
            <div className="bb-skeleton" style={{ height: 160, borderRadius: 6 }} />
          </div>
        ))}
      </div>
    )
  }

  if (isError || !data) {
    return (
      <div className="bb-insight-bento">
        <div className="bb-insight-card bb-insight-card-error">
          Couldn't load insights right now. Try refreshing in a moment.
        </div>
      </div>
    )
  }

  return (
    <div className="bb-insight-bento">
      <TopCitedPagesCard pages={data.topCitedPages} />
      <CoverageGapsCard gaps={data.coverageGaps} />
      <TopQuestionsCard topics={data.topQuestions} totalMessages={data.userMessages} />
      <TopCountriesCard countries={data.topCountries} otherVisitors={data.otherCountriesVisitors} />
      <ConversationPulseCard data={data} />
      <LeadCaptureCard data={data} />
    </div>
  )
}
