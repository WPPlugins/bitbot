/**
 * TopicDiscovery — Step 01 of the Content tab. Calls DiscoverTopics
 * RPC (BAML pipeline + Reddit trends) to surface:
 *   - Topic suggestions: 3-5 specific ideas with reasoning + difficulty
 *   - Content gaps: short list of areas the site doesn't cover yet
 *   - Reddit sources: cited threads the AI used as signal
 *
 * Clicking a suggestion fires the parent-supplied callback so the
 * Generate-a-post composer in Step 02 pre-fills with that topic and
 * scrolls into view.
 *
 * The RPC is paid + slow (~10-30s), so we don't auto-fetch — admin
 * must press the button. Results stay in mutation state until the
 * page is reloaded, so they can browse and try a couple in turn.
 */
import { WaitDots } from '@/components/ui/WaitDots'
import { splitFirstSentence } from '@/lib/sentence'
import { useState } from 'react'
import { useDiscoverTopics, useSavedTopicDiscovery, type TopicSuggestion } from '@/hooks/useContent'
import { relativeTime } from '@/lib/utils'
import { ChangePlanModal } from '@/components/billing/ChangePlanModal'

interface TopicDiscoveryProps {
  onPickTopic: (suggestion: TopicSuggestion) => void
}

const DIFFICULTY_LABEL: Record<string, { label: string; cls: string }> = {
  easy:   { label: 'Easy',   cls: 'bb-difficulty-easy' },
  medium: { label: 'Medium', cls: 'bb-difficulty-medium' },
  hard:   { label: 'Hard',   cls: 'bb-difficulty-hard' },
}

export function TopicDiscovery({ onPickTopic }: TopicDiscoveryProps) {
  const discover = useDiscoverTopics()
  const [showGaps, setShowGaps] = useState(false)
  const [changePlanOpen, setChangePlanOpen] = useState(false)
  // The one value shown: the site's latest result, as kept by the backend. A new search writes into the
  // same cache entry (useDiscoverTopics), so there is no second copy to disagree with.
  const saved = useSavedTopicDiscovery()
  const data = saved.data ?? undefined
  const isPending = discover.isPending
  const errorMsg = discover.error instanceof Error ? discover.error.message : null

  // Backend returns three distinct kinds of "you can't run this right
  // now" errors; render distinct copy for each.
  const isAccessPaused = !!errorMsg && /^(ACCESS_PAUSED|TRIAL_EXPIRED)/.test(errorMsg)
  const isFreeCapHit   = !!errorMsg && /free Topic Discovery searches/i.test(errorMsg)
  const [capHeadline, capDetail] = splitFirstSentence(errorMsg ?? '')
  const isProGate      = !!errorMsg && /(ASSISTANT_REQUIRED|Pro feature)/i.test(errorMsg)
  const isGated        = isAccessPaused || isFreeCapHit || isProGate

  return (
    <section className="bb-topic-discovery">
      {!data && !isPending && !isGated && !saved.isLoading && (
        <div className="bb-topic-discovery-empty">
          <p className="bb-topic-discovery-empty-lede">
            Youami scans your indexed pages, checks what your audience is
            asking, and surfaces the topics worth writing next, plus the
            gaps your current coverage is missing.
          </p>
          <button
            type="button"
            className="bb-btn bb-btn-primary"
            onClick={() => discover.mutate()}
          >
            Find topics →
          </button>
          <p className="bb-topic-discovery-empty-foot">Takes about 15 seconds.</p>
          {errorMsg && (
            <p className="bb-topic-discovery-error">{errorMsg}</p>
          )}
        </div>
      )}

      {/* Free monthly limit reached: the backend's own words (how many, and when they reset). */}
      {isFreeCapHit && (
        <div className="bb-topic-discovery-gate">
          <div>
            <strong>{capHeadline}</strong>
            <p>{capDetail}</p>
          </div>
          <button
            type="button"
            className="bb-btn bb-btn-primary bb-btn-small"
            onClick={() => setChangePlanOpen(true)}
          >
            Upgrade to Assistant →
          </button>
        </div>
      )}

      {/* Access paused (rare ToS / expired). */}
      {isAccessPaused && (
        <div className="bb-topic-discovery-gate">
          <div>
            <strong>Your subscription has ended.</strong>
            <p>Reactivate to keep using Topic Discovery.</p>
          </div>
          <button
            type="button"
            className="bb-btn bb-btn-primary bb-btn-small"
            onClick={() => setChangePlanOpen(true)}
          >
            Reactivate →
          </button>
        </div>
      )}

      {/* Free user hasn't used a credit yet — server says Assistant-only. */}
      {isProGate && (
        <div className="bb-topic-discovery-gate">
          <div>
            <strong>Topic Discovery is on Assistant.</strong>
            <p>
              Scans your site + live reader-trend data and ranks the topics worth
              writing next. Unlimited on Assistant ($20/mo).
            </p>
          </div>
          <button
            type="button"
            className="bb-btn bb-btn-primary bb-btn-small"
            onClick={() => setChangePlanOpen(true)}
          >
            Upgrade to Assistant →
          </button>
        </div>
      )}

      {isPending && !data && (
        <div className="bb-topic-discovery-empty">
          <p className="bb-topic-discovery-empty-lede" role="status">
            Reading your indexed pages, then cross-referencing trending
            questions in your niche<WaitDots />
          </p>
        </div>
      )}

      {data && (
        <header className="bb-topic-discovery-head">
          <p>
            {data.at ? `Found ${relativeTime(data.at)}. ` : ''}Picked from your indexed content + live reader trends. Refresh for
            a different angle.
            {data.saved === false ? ' These topics could not be saved and will be gone after a reload.' : ''}
          </p>
          <button
            type="button"
            className="bb-btn bb-btn-ghost bb-btn-small"
            onClick={() => discover.mutate()}
            disabled={isPending}
          >
            {isPending ? 'Refreshing…' : 'Refresh'}
          </button>
        </header>
      )}

      {/* Gate messaging lives in the PlanCard at the top of the page —
          intentionally not duplicated here. The Find topics button above
          stays visible and disabled-by-error; isGated suppresses the
          empty-state pitch so we don't show "Find topics →" on a
          surface where it would just fail. */}

      {data && (
        <>
          {data.suggestions.length > 0 && (
            <div className="bb-topic-suggestions">
              <div className="bb-topic-suggestions-head">
                <span className="bb-eyebrow">Suggested topics</span>
                {data.contentGaps.length > 0 && (
                  <button
                    type="button"
                    className={`bb-topic-gaps-toggle ${showGaps ? 'bb-topic-gaps-toggle-active' : ''}`}
                    aria-label={showGaps ? 'Hide content gaps' : 'Show content gaps'}
                    aria-expanded={showGaps}
                    title={showGaps ? 'Hide content gaps' : 'Show content gaps: areas your indexed content doesn\'t cover yet'}
                    onClick={() => setShowGaps((v) => !v)}
                  >
                    <span aria-hidden>ⓘ</span>
                    <span className="bb-topic-gaps-toggle-label">
                      {showGaps ? 'Hide gaps' : `${data.contentGaps.length} content ${data.contentGaps.length === 1 ? 'gap' : 'gaps'}`}
                    </span>
                  </button>
                )}
              </div>

              {showGaps && data.contentGaps.length > 0 && (
                <div className="bb-content-gaps bb-content-gaps-inline">
                  <p className="bb-content-gaps-lede">
                    Areas your indexed content doesn't cover yet. Pick a
                    suggested topic below that closes one of these.
                  </p>
                  <ul className="bb-gap-list">
                    {data.contentGaps.map((g, i) => (
                      <li key={i} className="bb-gap-item">{g}</li>
                    ))}
                  </ul>
                </div>
              )}

              <ul className="bb-topic-list">
                {data.suggestions.map((s: TopicSuggestion, i: number) => {
                  const diff = DIFFICULTY_LABEL[(s.difficulty || '').toLowerCase()] ?? DIFFICULTY_LABEL.medium
                  return (
                    <li key={i} className="bb-topic-item">
                      <div className="bb-topic-item-body">
                        <div className="bb-topic-item-title">{s.topic}</div>
                        <div className="bb-topic-item-reason">{s.reasoning}</div>
                        <div className="bb-topic-item-meta">
                          <span className={`bb-difficulty-pill ${diff.cls}`}>{diff.label}</span>
                          {s.searchQuery && (
                            <span className="bb-topic-item-query">
                              <span className="bb-eyebrow">research</span>
                              <code>{s.searchQuery}</code>
                            </span>
                          )}
                        </div>
                      </div>
                      <button
                        type="button"
                        className="bb-btn bb-btn-ghost bb-btn-small"
                        onClick={() => onPickTopic(s)}
                      >
                        Write this →
                      </button>
                    </li>
                  )
                })}
              </ul>
            </div>
          )}

          {data.sources.length > 0 && (
            <details className="bb-topic-sources">
              <summary>
                <span className="bb-eyebrow">Sources ({data.sources.length})</span>
                {' '}Reddit threads the AI considered
              </summary>
              <ul className="bb-source-list">
                {data.sources.map((src, i) => (
                  <li key={i}>
                    <a href={src.url} target="_blank" rel="noopener noreferrer">
                      {src.title}
                    </a>
                    <span className="bb-source-meta">
                      {src.subreddit} · {src.numComments} comments
                    </span>
                  </li>
                ))}
              </ul>
            </details>
          )}
        </>
      )}
      <ChangePlanModal open={changePlanOpen} onClose={() => setChangePlanOpen(false)} />
    </section>
  )
}
