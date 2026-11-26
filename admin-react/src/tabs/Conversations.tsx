/**
 * Conversations tab — list of live + recent chat threads with full
 * take-over flow. Admin can:
 *   • Click "Take over" to flip a thread into live_mode (bot stops
 *     replying, the widget switches to admin-reply polling)
 *   • Type and send replies (AdminReplyLive)
 *   • Hand back to the bot (CloseLiveThread)
 *
 * Selection persisted in `?conv=` so refresh keeps the open thread.
 * List polls every 10s, open thread polls every 5s — fast enough to
 * feel live without DDoSing the backend.
 *
 * Row state system — every row resolves to one of five states so admins
 * can scan the list for hot threads vs noise:
 *   live-admin  — you're already in the chat (green rail + pulsing dot)
 *   live-bot    — bot active, visitor present (teal rail + pulsing dot)
 *   needs-human — bot escalated, no admin yet (coral rail + urgent pill)
 *   idle        — visitor went quiet (grey rail)
 *   ended       — visitor left and bot resolved (no rail, soft type)
 *
 * Header filter chips slice the list to one state. Defaults to All but
 * the "Needs you" chip is highlighted whenever there's anything in it.
 */
import { useEffect, useMemo, useRef, useState, type JSX } from 'react'
import {
  useConversations, useConversationThread, useConversationFacets,
  useTakeOverConversation, useReplyLive, useCloseLiveThread,
  useCheckConversationLeads, useDeleteConversations, useMarkConversationsRead,
} from '@/hooks/useConversations'
import type { ConversationSummary } from '@/api/types'
import { Card } from '@/components/ui/Card'
import { EmptyState } from '@/components/ui/EmptyState'
import { relativeTime } from '@/lib/utils'
import { renderMarkdown, addressParts } from '@/lib/renderMarkdown'
import { flagFor, placeLabel } from '@/lib/flag'
import { usePlanStatus } from '@/hooks/usePlanStatus'
import { ChangePlanModal } from '@/components/billing/ChangePlanModal'
import {
  deriveRowState, matchesFilter, type RowState, type ConversationFilter,
} from '@/lib/conversationFilter'

/** Render text with bare URLs turned into clickable links (email bodies often
 *  contain raw https links / "Get Outlook for Android<https://…>" footers). */
function linkify(input: string): Array<string | JSX.Element> {
  return addressParts(input).map((part, i) =>
    'href' in part
      ? <a key={i} href={part.href} target="_blank" rel="noopener noreferrer">{part.label}</a>
      : part.text,
  )
}

function ChannelBadge({ channel }: { channel: ConversationSummary['channel'] }) {
  if (channel === 'messenger') {
    return <span className="bb-conv-channel bb-conv-channel-messenger" title="Messenger DM">FB</span>
  }
  if (channel === 'instagram_dm') {
    return <span className="bb-conv-channel bb-conv-channel-instagram" title="Instagram DM">IG</span>
  }
  if (channel === 'email') {
    return <span className="bb-conv-channel bb-conv-channel-email" title="Email support">✉</span>
  }
  return null
}


function StateBadge({ state }: { state: RowState }) {
  switch (state) {
    case 'live-admin':
      return (
        <span className="bb-conv-badge bb-conv-badge-admin">
          <span className="bb-conv-badge-dot" />
          Live · You
        </span>
      )
    case 'live-bot':
      return (
        <span className="bb-conv-badge bb-conv-badge-bot">
          <span className="bb-conv-badge-dot" />
          Live · Bot
        </span>
      )
    case 'needs-human':
      return (
        <span className="bb-conv-badge bb-conv-badge-urgent">
          Needs reply<span className="bb-conv-badge-arrow">→</span>
        </span>
      )
    // Idle and ended threads carry no badge: every thread ends, and the
    // timestamp already says how old it is. A badge means "something to do".
    case 'idle':
    case 'ended':
      return null
    case 'open':
      return <span className="bb-conv-badge bb-conv-badge-open">Open</span>
  }
}

function readConvParam(): string | null {
  const u = new URL(window.location.href)
  return u.searchParams.get('conv')
}
function writeConvParam(id: string | null) {
  const u = new URL(window.location.href)
  if (id) u.searchParams.set('conv', id); else u.searchParams.delete('conv')
  window.history.replaceState({}, '', u)
}

function ConversationRow({ conv, active, onSelect, selected, onToggleSelect, selectionActive, onDeleteSingle }: {
  conv: ConversationSummary
  active: boolean
  onSelect: () => void
  selected: boolean
  onToggleSelect: () => void
  /** Whether any row is selected — when true the checkbox stays
   *  visible even off-hover, so the column doesn't shift around mid-flow. */
  selectionActive: boolean
  /** Single-row delete (the trash icon on hover). Goes through the same
   *  confirm modal as the bulk path so the lead-keep prompt still fires. */
  onDeleteSingle: () => void
}) {
  const state = deriveRowState(conv)
  return (
    <div
      className={`bb-conv-row bb-conv-row-state-${state} ${active ? 'bb-conv-row-active' : ''} ${selected ? 'bb-conv-row-selected' : ''}`}
    >
      <label
        className={`bb-conv-row-check ${selectionActive || selected ? 'bb-conv-row-check-visible' : ''}`}
        onClick={(e) => e.stopPropagation()}
      >
        <input
          type="checkbox"
          checked={selected}
          onChange={onToggleSelect}
          aria-label={selected ? `Deselect ${conv.displayLabel}` : `Select ${conv.displayLabel}`}
        />
      </label>
      <button className="bb-conv-row-body" onClick={onSelect}>
        <div className="bb-conv-row-top">
          <span className="bb-conv-row-id">
            {!conv.viewedAt && (
              <span className="bb-conv-row-newdot" title="New — not opened yet" aria-label="New" />
            )}
            {conv.displayLabel}
            {flagFor(conv.visitorCountryCode) && (
              <span className="bb-conv-row-flag" title={placeLabel(conv.visitorCity, conv.visitorCountry)} aria-label={placeLabel(conv.visitorCity, conv.visitorCountry)}>
                {flagFor(conv.visitorCountryCode)}
              </span>
            )}
            <ChannelBadge channel={conv.channel} />
          </span>
          <span className="bb-conv-row-time">{relativeTime(conv.lastMessageAt)}</span>
        </div>
        {conv.unreadAdminCount > 0 && (
          <div className="bb-conv-row-mid">
            {conv.unreadAdminCount > 0 && (
              <span className="bb-conv-row-unread">{conv.unreadAdminCount} new</span>
            )}
          </div>
        )}
        <div className="bb-conv-row-status">
          <StateBadge state={state} />
        </div>
      </button>
      <button
        type="button"
        className="bb-conv-row-delete"
        title="Delete this thread"
        aria-label={`Delete thread ${conv.displayLabel}`}
        onClick={(e) => {
          e.stopPropagation()
          onDeleteSingle()
        }}
      >
        🗑
      </button>
    </div>
  )
}

type Filter = ConversationFilter

function channelFilterLabel(value: string): string {
  switch (value) {
    case 'web_widget': return 'Web chat'
    case 'messenger': return 'Facebook'
    case 'instagram_dm': return 'Instagram'
    case 'email': return 'Email'
    default: return value
  }
}

function Thread({ conv }: { conv: ConversationSummary }) {
  const { data: planStatus } = usePlanStatus()
  const isFreePlan = planStatus?.plan === 'free'
  // Free reads one transcript, the conversation with the latest activity; the server marks every
  // other row transcriptLocked. Fetch only once the plan is known and the row is
  // open; a stale or missing plan must never turn the gate into a load error.
  const rowLocked = isFreePlan && conv.transcriptLocked !== false
  const { data: messages, isLoading, error } = useConversationThread(conv.id, !!planStatus && !rowLocked)
  const takeOver = useTakeOverConversation()
  const reply    = useReplyLive()
  const close    = useCloseLiveThread()
  const [draft, setDraft] = useState('')
  const [changePlanOpen, setChangePlanOpen] = useState(false)
  const [msgSort, setMsgSort] = useState<'oldest' | 'newest'>('oldest')
  const transcriptRef = useRef<HTMLOListElement>(null)
  const state = deriveRowState(conv)
  // The backend answers ASSISTANT_REQUIRED when a cached paid plan has lapsed;
  // that is the gate, not an error.
  const showGate = rowLocked || /ASSISTANT_REQUIRED/.test(String((error as Error | null)?.message ?? ''))

  // Keep the latest message in view: bottom when oldest-first, top when
  // newest-first. Re-runs when a message arrives or the sort flips.
  useEffect(() => {
    const el = transcriptRef.current
    if (el) el.scrollTop = msgSort === 'newest' ? 0 : el.scrollHeight
  }, [messages?.length, msgSort])

  // Free-tier transcript gate. Backend GetLiveChatMessages returns
  // ASSISTANT_REQUIRED for Free; we render a count-only teaser
  // matching the Leads tab pattern. Metadata (the row + message
  // count from the list view) is still visible, just not the
  // transcript content.
  if (showGate) {
    return (
      <>
        <header className="bb-card-header">
          <div className="bb-card-headtext">
            <h3 className="bb-card-title">
              <span className="bb-eyebrow">{conv.displayLabel}</span>
              <span className="bb-conv-meta">Last message {relativeTime(conv.lastMessageAt)}</span>
            </h3>
          </div>
          <div className="bb-card-action"><ChannelBadge channel={conv.channel} /></div>
        </header>
        <div className="bb-card-body">
        <div className="bb-conv-thread-locked">
          <p className="bb-conv-thread-locked-headline">This transcript is locked on Free.</p>
          <p className="bb-conv-thread-locked-body">
            The conversation with the latest activity is open. Upgrade to Assistant to read every thread, follow up on the leads, and take over live.
          </p>
          <button
            type="button"
            className="bb-btn bb-btn-primary"
            onClick={() => setChangePlanOpen(true)}
          >
            Upgrade to view →
          </button>
        </div>
        <ChangePlanModal open={changePlanOpen} onClose={() => setChangePlanOpen(false)} />
        </div>
      </>
    )
  }

  if (isLoading || (!messages && !error)) {
    return <div className="bb-skeleton" style={{ height: 320, margin: 12, borderRadius: 8 }} />
  }
  if (!messages) {
    return <div className="bb-inline-error" role="alert">Couldn't load this thread. Try again.</div>
  }

  const onSend = () => {
    if (!draft.trim()) return
    reply.mutate({ conversationId: conv.id, message: draft }, {
      onSuccess: () => setDraft(''),
    })
  }

  // Take-over only makes sense when the bot is currently handling the
  // visitor (live-bot) — that's the moment to intercept. Past/idle/ended
  // threads have no one to talk to; live-admin and needs-reply are already
  // in live_mode so the composer is open instead. 'open' covers async
  // FB / IG threads — the inbox is always ready to receive a reply.
  const canTakeOver = !isFreePlan && (state === 'live-bot' || state === 'open')

  return (
    <>
      {/* One header for the pane: who, then the thread facts and the sort, with the
          badges and the take-over on the right. The transcript follows directly. */}
      <header className="bb-card-header">
        <div className="bb-card-headtext">
          <h3 className="bb-card-title">
            <span className="bb-eyebrow">{conv.displayLabel}</span>
            <span className="bb-conv-meta">
              {conv.visitorEmail && <a href={`mailto:${conv.visitorEmail}`}>{conv.visitorEmail}</a>}
              {conv.visitorEmail && <> · </>}
              {conv.visitorCity && <span>{conv.visitorCity}, {conv.visitorCountry} · </span>}
              <span>{messages.length} {messages.length === 1 ? 'message' : 'messages'}</span>
              {messages.length > 1 && (
                <button
                  type="button"
                  className="bb-conv-sort"
                  onClick={() => setMsgSort((s) => (s === 'oldest' ? 'newest' : 'oldest'))}
                  title="Toggle message order"
                >
                  {msgSort === 'oldest' ? 'Oldest first' : 'Newest first'} ⇅
                </button>
              )}
            </span>
          </h3>
        </div>
        <div className="bb-card-action bb-conv-head-actions">
          <ChannelBadge channel={conv.channel} />
          <StateBadge state={state} />
          {canTakeOver && (
            <button
              className="bb-btn bb-btn-primary bb-btn-small"
              disabled={takeOver.isPending}
              onClick={() => takeOver.mutate({ conversationId: conv.id })}
            >
              {takeOver.isPending ? 'Taking over…' : 'Take over chat →'}
            </button>
          )}
        </div>
      </header>
      <div className="bb-card-body bb-conv-body">

      <ol ref={transcriptRef} className="bb-transcript bb-conv-transcript">
        {messages.length === 0 && (
          <li className="bb-conv-empty-thread">No messages yet.</li>
        )}
        {(msgSort === 'newest' ? [...messages].reverse() : messages).map((m, i) => {
          // Email messages arrive as "Subject: …\n\n<body>"; render the subject
          // as a header and the body below so the thread reads like an email,
          // not a run-on blob. Null-safe: never call .match on a missing body.
          const content = m.content ?? ''
          const emailMatch = conv.channel === 'email' && m.role === 'user'
            ? content.match(/^Subject:\s*([^\n]*)\n+([\s\S]*)$/)
            : null
          return (
            <li key={m.id ?? i} className={`bb-msg bb-msg-${m.role}`}>
              <span className="bb-msg-role">
                {m.role === 'user' ? conv.visitorName ?? 'Visitor'
                  : m.role === 'admin' ? 'You' : 'Youami'}
              </span>
              {emailMatch ? (
                <div className="bb-msg-body bb-msg-email">
                  {emailMatch[1].trim() && (
                    <div className="bb-msg-email-subject">{emailMatch[1].trim()}</div>
                  )}
                  <div className="bb-msg-email-text">{linkify(emailMatch[2].trim())}</div>
                </div>
              ) : m.role === 'user' ? (
                // Visitors type plain text: never interpret it as markdown.
                <p className="bb-msg-body">{content}</p>
              ) : (
                // Youami's and the owner's replies are markdown, as the visitor
                // widget renders them (widget.js), with their line breaks kept.
                <div className="bb-msg-body bb-md">{renderMarkdown(content, { breaks: true })}</div>
              )}
              <span className="bb-msg-time">{relativeTime(m.createdAt)}</span>
            </li>
          )
        })}
      </ol>

      {isFreePlan && (
        <p className="bb-conv-free-note">Live takeover and replies unlock on Assistant.</p>
      )}
      {conv.liveMode && !isFreePlan && (
        <footer className="bb-conv-composer">
          <textarea
            className="bb-input bb-textarea"
            rows={3}
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            placeholder="Type your reply… The visitor sees this directly."
            onKeyDown={(e) => {
              if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
                e.preventDefault()
                onSend()
              }
            }}
          />
          <div className="bb-conv-composer-actions">
            <button
              className="bb-btn bb-btn-ghost bb-btn-small"
              disabled={close.isPending}
              onClick={() => close.mutate({ conversationId: conv.id })}
            >
              {close.isPending ? 'Handing back…' : 'Hand back to bot'}
            </button>
            <button
              className="bb-btn bb-btn-primary bb-btn-small"
              disabled={!draft.trim() || reply.isPending}
              onClick={onSend}
            >
              {reply.isPending ? 'Sending…' : 'Send reply (⌘↵)'}
            </button>
          </div>
        </footer>
      )}
      </div>
    </>
  )
}

export function Conversations() {
  const [selectedId, setSelectedId] = useState<string | null>(readConvParam())
  const [filter, setFilter] = useState<Filter>('all')
  const [channel, setChannel] = useState<string | null>(null)
  const [checked, setChecked] = useState<Set<string>>(new Set())
  const [confirmState, setConfirmState] = useState<{
    ids: string[]
    linkedLeads: Array<{ conversationId: string; leadIds: string[] }>
  } | null>(null)
  const {
    data: conversations,
    total,
    stateCounts,
    isLoading,
    hasNextPage,
    isFetchingNextPage,
    fetchNextPage,
  } = useConversations(channel)
  const { data: facets } = useConversationFacets()
  const checkLeads = useCheckConversationLeads()
  const deleteConvs = useDeleteConversations()
  const markRead = useMarkConversationsRead()

  useEffect(() => { writeConvParam(selectedId) }, [selectedId])

  const list = conversations ?? []
  const filtered = useMemo(
    () => list.filter((c) => matchesFilter(c, filter)),
    [list, filter],
  )
  const selected = list.find((c) => c.id === selectedId) ?? null

  // Filter the checked set down to ids still in `filtered` so stale
  // ids (after a delete or filter change) don't keep counting toward
  // "N selected".
  const activeChecked = useMemo(() => {
    const valid = new Set<string>()
    for (const c of filtered) if (checked.has(c.id)) valid.add(c.id)
    return valid
  }, [filtered, checked])
  const checkedCount = activeChecked.size
  const allFilteredChecked = checkedCount > 0 && checkedCount === filtered.length

  const toggleCheck = (id: string) => {
    const next = new Set(checked)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    setChecked(next)
  }
  const toggleAll = () => {
    if (allFilteredChecked) {
      setChecked(new Set())
    } else {
      setChecked(new Set(filtered.map((c) => c.id)))
    }
  }
  const clearChecks = () => setChecked(new Set())

  const onClickBulkDelete = async () => {
    const ids = Array.from(activeChecked)
    if (ids.length === 0) return
    // Ask backend which of these have leads so we can offer the right
    // confirmation copy. Showing the modal even when nothing is linked
    // so admin always gets a "you sure?" before destructive action.
    const res = await checkLeads.mutateAsync({ conversationIds: ids }).catch(() => null)
    setConfirmState({ ids, linkedLeads: res?.linked ?? [] })
  }

  const runDelete = async (deleteLeads: boolean) => {
    if (!confirmState) return
    await deleteConvs.mutateAsync({ conversationIds: confirmState.ids, deleteLeads }).catch(() => null)
    if (selectedId && confirmState.ids.includes(selectedId)) setSelectedId(null)
    setConfirmState(null)
    clearChecks()
  }

  const onClickBulkMarkRead = async () => {
    const ids = Array.from(activeChecked)
    if (ids.length === 0) return
    await markRead.mutateAsync({ conversationIds: ids }).catch(() => null)
    clearChecks()
  }

  const filterChips: Array<{ key: Filter; label: string; count: number; urgent?: boolean }> = [
    { key: 'all',   label: 'All',         count: stateCounts.all },
    { key: 'live',  label: 'Live',        count: stateCounts.live },
    { key: 'needs', label: 'Needs reply', count: stateCounts.needs, urgent: stateCounts.needs > 0 },
    { key: 'past',  label: 'Past',        count: stateCounts.past },
  ]

  return (
    <>

      <div className="bb-conv-grid">
        <section className="bb-conv-list-card">
          <header className="bb-card-header">
            <h3 className="bb-card-title">
              <span className="bb-eyebrow">Threads</span>
              <span>Live &amp; recent</span>
            </h3>
          </header>

          <div className="bb-conv-filters" role="tablist" aria-label="Conversation filter">
            {filterChips.map((chip) => {
              const isActive = filter === chip.key
              return (
                <button
                  key={chip.key}
                  type="button"
                  role="tab"
                  aria-selected={isActive}
                  className={`bb-conv-filter ${isActive ? 'bb-conv-filter-active' : ''} ${chip.urgent ? 'bb-conv-filter-urgent' : ''}`}
                  onClick={() => setFilter(chip.key)}
                >
                  {chip.label}
                  <span className="bb-conv-filter-count">{chip.count}</span>
                </button>
              )
            })}
          </div>

          {/* Channel filter — mirrors the Leads facet dropdowns. Only shown
              when there's more than one channel to choose between. */}
          {(facets?.channels.length ?? 0) > 1 && (
            <div className="bb-conv-channel-filter-wrap">
              <select
                className="bb-input bb-conv-channel-filter"
                value={channel ?? ''}
                onChange={(e) => setChannel(e.target.value || null)}
                aria-label="Filter by channel"
              >
                <option value="">All channels</option>
                {facets!.channels.map((c) => (
                  <option key={c.value} value={c.value}>{channelFilterLabel(c.value)} ({c.count})</option>
                ))}
              </select>
            </div>
          )}

          <div className="bb-conv-list">
            {isLoading && (
              <div className="bb-skeleton" style={{ height: 360, margin: 12, borderRadius: 8 }} />
            )}
            {!isLoading && list.length === 0 && (
              <EmptyState
                headline="No conversations yet"
                body="Once visitors start chatting, every thread shows up here. The list updates every 10 seconds."
                ctaLabel="Test your widget"
                onCta={() => window.open('/', '_blank')}
              />
            )}
            {!isLoading && list.length > 0 && filtered.length === 0 && (
              <div className="bb-conv-filter-empty">
                {filter === 'needs' ? 'Nothing needs you. All caught up.'
                  : filter === 'live'  ? 'No live conversations.'
                  : 'Nothing here yet.'}
              </div>
            )}
            {/* Bulk bar shows only when something's selected — keeps the
                list clean by default and matches the WordPress posts
                Bulk Actions UX. Each row has a trash icon for single
                delete, so the bulk bar isn't needed when nobody's
                staging a multi-action. */}
            {!isLoading && checkedCount > 0 && (
              <div className="bb-conv-bulk-bar">
                <label className="bb-conv-bulk-select-all">
                  <input
                    type="checkbox"
                    checked={allFilteredChecked}
                    ref={(el) => {
                      if (el) el.indeterminate = checkedCount > 0 && !allFilteredChecked
                    }}
                    onChange={toggleAll}
                    aria-label={allFilteredChecked ? 'Deselect all' : 'Select all'}
                  />
                  <span>
                    {allFilteredChecked
                      ? `${checkedCount} selected (all)`
                      : `${checkedCount} selected`}
                  </span>
                </label>
                <div className="bb-conv-bulk-actions">
                  <button
                    type="button"
                    className="bb-btn bb-btn-ghost bb-btn-small"
                    onClick={clearChecks}
                  >
                    Clear
                  </button>
                  <button
                    type="button"
                    className="bb-btn bb-btn-ghost bb-btn-small"
                    onClick={onClickBulkMarkRead}
                    disabled={markRead.isPending}
                  >
                    {markRead.isPending ? 'Marking…' : 'Mark read'}
                  </button>
                  <button
                    type="button"
                    className="bb-btn bb-btn-danger bb-btn-small"
                    onClick={onClickBulkDelete}
                    disabled={checkLeads.isPending || deleteConvs.isPending}
                  >
                    {checkLeads.isPending ? 'Checking…' : `Delete ${checkedCount}`}
                  </button>
                </div>
              </div>
            )}
            {!isLoading && filtered.map((c) => (
              <ConversationRow
                key={c.id}
                conv={c}
                active={c.id === selectedId}
                onSelect={() => setSelectedId(c.id)}
                selected={activeChecked.has(c.id)}
                onToggleSelect={() => toggleCheck(c.id)}
                selectionActive={checkedCount > 0}
                onDeleteSingle={async () => {
                  const res = await checkLeads.mutateAsync({ conversationIds: [c.id] }).catch(() => null)
                  setConfirmState({ ids: [c.id], linkedLeads: res?.linked ?? [] })
                }}
              />
            ))}
            {(() => {
              // 2.0.29: respect the active filter when deciding whether
              // to show "Load more". Previously the button was gated on
              // hasNextPage alone (more pages of ALL conversations
              // exist), so filtering to "Live 1" still showed
              // "Load more (50 remaining)" even though every live
              // thread was already on screen — there are just more
              // PAST threads paginated behind.
              const remainingForFilter = (() => {
                if (filter === 'live')  return Math.max(0, stateCounts.live  - filtered.length)
                if (filter === 'needs') return Math.max(0, stateCounts.needs - filtered.length)
                if (filter === 'past')  return Math.max(0, stateCounts.past  - filtered.length)
                return Math.max(0, total - list.length)
              })()
              const showLoadMore = hasNextPage && remainingForFilter > 0
              if (!showLoadMore) return null
              return (
                <button
                  type="button"
                  className="bb-btn bb-btn-ghost bb-load-more"
                  onClick={() => fetchNextPage()}
                  disabled={isFetchingNextPage}
                >
                  {isFetchingNextPage ? 'Loading…' : `Load more (${remainingForFilter} remaining)`}
                </button>
              )
            })()}
          </div>
        </section>

        <Card
          eyebrow={selected ? undefined : 'No thread selected'}
          title={selected ? undefined : 'Pick a conversation'}
          noBody={!!selected}
          className="bb-conv-detail-card"
        >
          {!selected && (
            <p className="bb-conv-placeholder-body">
              Choose a thread from the left. Live threads can be taken over so
              you reply directly while the bot stays out of the way. Idle and
              ended threads are read-only.
            </p>
          )}
          {selected && <Thread conv={selected} />}
        </Card>
      </div>

      {confirmState && (
        <BulkDeleteConfirm
          conversationCount={confirmState.ids.length}
          leadCount={confirmState.linkedLeads.reduce((n, l) => n + l.leadIds.length, 0)}
          isBusy={deleteConvs.isPending}
          onCancel={() => setConfirmState(null)}
          onConfirmKeepLeads={() => runDelete(false)}
          onConfirmDeleteLeads={() => runDelete(true)}
        />
      )}
    </>
  )
}

/**
 * Confirm dialog for the bulk delete. Two outcomes:
 *   - Keep leads: leads stay in the Leads inbox, their conversation_id
 *     gets nulled. Use when you're cleaning up old chat clutter but the
 *     captured emails are still worth following up.
 *   - Delete leads too: full purge. Use for test conversations or
 *     spammy threads that shouldn't have qualified.
 * When the selection has no linked leads, only the single "Delete N
 * threads" action is shown (no lead-keep/delete fork).
 */
function BulkDeleteConfirm({
  conversationCount,
  leadCount,
  isBusy,
  onCancel,
  onConfirmKeepLeads,
  onConfirmDeleteLeads,
}: {
  conversationCount: number
  leadCount: number
  isBusy: boolean
  onCancel: () => void
  onConfirmKeepLeads: () => void
  onConfirmDeleteLeads: () => void
}) {
  return (
    <div className="bb-modal-backdrop bb-portal-scope" onClick={onCancel} role="presentation">
      <div className="bb-modal bb-confirm-modal" onClick={(e) => e.stopPropagation()} role="alertdialog" aria-modal="true">
        <header className="bb-modal-head">
          <div className="bb-modal-titles">
            <h2 className="bb-modal-title">
              Delete {conversationCount} {conversationCount === 1 ? 'thread' : 'threads'}?
            </h2>
          </div>
          <button className="bb-modal-close" onClick={onCancel} disabled={isBusy} aria-label="Cancel">
            ×
          </button>
        </header>
        <div className="bb-modal-body">
          <p className="bb-confirm-modal-body">
            {leadCount === 0 ? (
              <>
                The selected {conversationCount === 1 ? 'thread is' : 'threads are'} not linked to any leads.
                All transcripts will be permanently removed.
              </>
            ) : (
              <>
                The selected {conversationCount === 1 ? 'thread is' : 'threads are'} linked to{' '}
                <strong>{leadCount}</strong> {leadCount === 1 ? 'lead' : 'leads'} in your inbox. What do you
                want to do with {leadCount === 1 ? 'it' : 'them'}?
              </>
            )}
          </p>
        </div>
        <footer className="bb-confirm-modal-actions">
          <button className="bb-btn bb-btn-ghost" onClick={onCancel} disabled={isBusy}>
            Cancel
          </button>
          {leadCount === 0 ? (
            <button
              className="bb-btn bb-btn-destructive"
              onClick={onConfirmKeepLeads}
              disabled={isBusy}
            >
              {isBusy ? 'Deleting…' : `Delete ${conversationCount} ${conversationCount === 1 ? 'thread' : 'threads'}`}
            </button>
          ) : (
            <>
              <button
                className="bb-btn bb-btn-ghost"
                onClick={onConfirmKeepLeads}
                disabled={isBusy}
              >
                Keep leads
              </button>
              <button
                className="bb-btn bb-btn-destructive"
                onClick={onConfirmDeleteLeads}
                disabled={isBusy}
              >
                Delete leads too
              </button>
            </>
          )}
        </footer>
      </div>
    </div>
  )
}
