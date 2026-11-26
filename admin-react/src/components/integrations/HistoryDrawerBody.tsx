/**
 * History drawer body — list of admin conversations.
 *
 * Two modes:
 *   - Normal: click a row to open it, click the trash icon for a
 *     single-row delete.
 *   - Multi-select: checkboxes on every row + "select all" toggle in
 *     the header. While ≥1 row is selected, the header shows a
 *     "Delete N" action that wipes the selected conversations in one
 *     batch.
 *
 * Pinned conversations sort into their own group at the top. A pin is a
 * per-browser view preference, not shared state, so it lives in
 * localStorage rather than costing a new RPC + migration — the same
 * call the preview panel's pin already makes.
 *
 * Search filters the LOADED rows only. Pagination means the rest of the
 * list isn't in memory, so when a search runs against a partial list we
 * say so rather than implying the whole history was searched.
 *
 * No archive concept — a conversation is either active or gone.
 */
import { useMemo, useState } from 'react'
import type { AdminConversationSummary } from '@/hooks/useAdminChat'

const PINNED_STORAGE_KEY = 'bb-history-pinned'

/** Pins are cosmetic — a browser with storage disabled (private mode,
 *  quota) should still get a working list, so read/write fail soft. */
function loadPinnedIds(): Set<string> {
  try {
    const raw = localStorage.getItem(PINNED_STORAGE_KEY)
    if (!raw) return new Set()
    const parsed: unknown = JSON.parse(raw)
    return Array.isArray(parsed) ? new Set(parsed.filter((v): v is string => typeof v === 'string')) : new Set()
  } catch {
    return new Set()
  }
}

function savePinnedIds(ids: ReadonlySet<string>): void {
  try {
    localStorage.setItem(PINNED_STORAGE_KEY, JSON.stringify(Array.from(ids)))
  } catch {
    // Storage unavailable — pins simply don't survive the reload.
  }
}

function relativeTime(iso: string): string {
  const then = new Date(iso).getTime()
  const diff = Date.now() - then
  if (diff < 60_000) return 'just now'
  if (diff < 3_600_000) return `${Math.floor(diff / 60_000)}m ago`
  if (diff < 86_400_000) return `${Math.floor(diff / 3_600_000)}h ago`
  return `${Math.floor(diff / 86_400_000)}d ago`
}

interface ConversationRowProps {
  conv: AdminConversationSummary
  checked: boolean
  isActive: boolean
  isPinned: boolean
  onSelect: (id: string) => void
  onToggleCheck: (id: string) => void
  onTogglePin: (id: string) => void
  onDelete: (id: string) => void
}

function ConversationRow({
  conv,
  checked,
  isActive,
  isPinned,
  onSelect,
  onToggleCheck,
  onTogglePin,
  onDelete,
}: ConversationRowProps) {
  return (
    <div
      className={`bb-adminchat-conv ${isActive ? 'bb-adminchat-conv-active' : ''} ${checked ? 'bb-adminchat-conv-checked' : ''}`}
      onClick={() => onSelect(conv.id)}
      role="button"
      tabIndex={0}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault()
          onSelect(conv.id)
        }
      }}
    >
      <input
        type="checkbox"
        className="bb-adminchat-conv-check"
        checked={checked}
        onClick={(e) => e.stopPropagation()}
        onChange={() => onToggleCheck(conv.id)}
        aria-label={checked ? 'Deselect conversation' : 'Select conversation'}
      />
      <div className="bb-adminchat-conv-body">
        <div className="bb-adminchat-conv-title">
          {conv.title ?? 'Untitled conversation'}
        </div>
        <div className="bb-adminchat-conv-meta">
          <span>{conv.messageCount} msg</span>
          <span className="bb-adminchat-conv-sep">·</span>
          <span>{relativeTime(conv.lastMessageAt)}</span>
        </div>
      </div>
      <button
        type="button"
        className={`bb-adminchat-conv-pin ${isPinned ? 'bb-adminchat-conv-pin-on' : ''}`}
        onClick={(e) => {
          e.stopPropagation()
          onTogglePin(conv.id)
        }}
        title={isPinned ? 'Unpin conversation' : 'Pin conversation'}
        aria-label={isPinned ? 'Unpin conversation' : 'Pin conversation'}
        aria-pressed={isPinned}
      >
        📌
      </button>
      <button
        type="button"
        className="bb-adminchat-conv-archive"
        onClick={(e) => {
          e.stopPropagation()
          onDelete(conv.id)
        }}
        title="Delete conversation"
        aria-label="Delete conversation"
      >
        🗑
      </button>
    </div>
  )
}

interface HistoryDrawerBodyProps {
  conversations: AdminConversationSummary[]
  /** Total rows on the server (loaded + not-yet-loaded). Drives the
   *  honest "N of TOTAL" footer. */
  total: number
  hasMore: boolean
  isLoadingMore: boolean
  onLoadMore: () => void
  activeId: string | null
  onSelect: (id: string) => void
  onDelete: (id: string) => void
  /** Delete a batch of conversations. Called once per "Delete N" click. */
  onDeleteBulk: (ids: string[]) => void
}

export function HistoryDrawerBody({
  conversations,
  total,
  hasMore,
  isLoadingMore,
  onLoadMore,
  activeId,
  onSelect,
  onDelete,
  onDeleteBulk,
}: HistoryDrawerBodyProps) {
  // Track checked-row ids in a Set for O(1) lookup. The activeSelection
  // memo below filters to ids that still exist in the latest
  // conversations list so stale ids (e.g. after a delete completes)
  // don't keep counting toward "N selected".
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [pinnedIds, setPinnedIds] = useState<Set<string>>(loadPinnedIds)
  const [query, setQuery] = useState('')

  const trimmedQuery = query.trim()

  const matches = useMemo(() => {
    if (!trimmedQuery) return conversations
    const needle = trimmedQuery.toLowerCase()
    return conversations.filter((c) => (c.title ?? 'Untitled conversation').toLowerCase().includes(needle))
  }, [conversations, trimmedQuery])

  const pinnedRows = useMemo(() => matches.filter((c) => pinnedIds.has(c.id)), [matches, pinnedIds])
  const unpinnedRows = useMemo(() => matches.filter((c) => !pinnedIds.has(c.id)), [matches, pinnedIds])

  const activeSelection = useMemo(() => {
    const valid = new Set<string>()
    for (const c of matches) {
      if (selected.has(c.id)) valid.add(c.id)
    }
    return valid
  }, [matches, selected])

  const selectedCount = activeSelection.size
  const allSelected = selectedCount > 0 && selectedCount === matches.length

  const toggleOne = (id: string) => {
    const next = new Set(activeSelection)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    setSelected(next)
  }

  // Select-all covers what's on screen. While a search is active that is
  // the matching rows, so "Delete N" can never reach a row the user
  // can't see.
  const toggleAll = () => {
    setSelected(allSelected ? new Set() : new Set(matches.map((c) => c.id)))
  }

  const clearSelection = () => setSelected(new Set())

  const togglePin = (id: string) => {
    const next = new Set(pinnedIds)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    setPinnedIds(next)
    savePinnedIds(next)
  }

  if (conversations.length === 0) {
    return (
      <p className="bb-adminchat-rail-empty">
        No conversations yet. Close this and type a message below to start one.
      </p>
    )
  }

  const renderRow = (conv: AdminConversationSummary) => (
    <ConversationRow
      key={conv.id}
      conv={conv}
      checked={activeSelection.has(conv.id)}
      isActive={conv.id === activeId}
      isPinned={pinnedIds.has(conv.id)}
      onSelect={onSelect}
      onToggleCheck={toggleOne}
      onTogglePin={togglePin}
      onDelete={onDelete}
    />
  )

  return (
    <div className="bb-history-drawer">
      <div className="bb-history-search">
        <input
          type="search"
          className="bb-history-search-input"
          placeholder="Search conversations"
          aria-label="Search conversations"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
        />
      </div>
      <div className="bb-history-toolbar">
        <label className="bb-history-toolbar-select">
          <input
            type="checkbox"
            checked={allSelected}
            // Indeterminate when SOME but not ALL rows are selected —
            // visual cue that clicking the header checkbox will expand
            // to all.
            ref={(el) => {
              if (el) {
                el.indeterminate = selectedCount > 0 && !allSelected
              }
            }}
            onChange={toggleAll}
            aria-label={allSelected ? 'Deselect all' : 'Select all'}
          />
          <span>
            {selectedCount > 0
              ? `${selectedCount} selected`
              : trimmedQuery
                ? `${matches.length} match${matches.length === 1 ? '' : 'es'}`
                : hasMore
                  ? `${conversations.length} of ${total}`
                  : `${conversations.length} conversation${conversations.length === 1 ? '' : 's'}`}
          </span>
        </label>
        {selectedCount > 0 && (
          <div className="bb-history-toolbar-actions">
            <button
              type="button"
              className="bb-history-toolbar-clear"
              onClick={clearSelection}
            >
              Clear
            </button>
            <button
              type="button"
              className="bb-history-toolbar-delete"
              onClick={() => onDeleteBulk(Array.from(activeSelection))}
            >
              Delete {selectedCount}
            </button>
          </div>
        )}
      </div>
      <div className="bb-adminchat-rail-list">
        {matches.length === 0 && (
          <p className="bb-adminchat-rail-empty">
            No conversation titled “{trimmedQuery}”.
          </p>
        )}
        {pinnedRows.length > 0 && (
          <>
            <div className="bb-history-group-label">Pinned</div>
            {pinnedRows.map(renderRow)}
          </>
        )}
        {unpinnedRows.length > 0 && (
          <>
            {pinnedRows.length > 0 && <div className="bb-history-group-label">Recent</div>}
            {unpinnedRows.map(renderRow)}
          </>
        )}
        {trimmedQuery && hasMore && (
          <p className="bb-history-search-scope">
            Searched the {conversations.length} loaded conversations of {total}. Load more to widen the search.
          </p>
        )}
        {hasMore && (
          <div className="bb-history-loadmore">
            <button
              type="button"
              className="bb-history-loadmore-btn"
              onClick={onLoadMore}
              disabled={isLoadingMore}
            >
              {isLoadingMore ? 'Loading…' : `Load more (${total - conversations.length} remaining)`}
            </button>
          </div>
        )}
      </div>
    </div>
  )
}
