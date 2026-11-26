/**
 * IndexedPagesTable — paginated list of crawled pages (GetPages RPC).
 *
 * Toolbar: search, source select, "+ Add URL/Sitemap" and the sync buttons.
 * Under it a filter row: On / Left out / Off / All pages on the left, the one
 * status item (last synced, live progress, Free page allowance) on the right.
 * Each row has an On/Off/Left out state, a sortable word count and last crawl,
 * and a menu (resync / turn on or off / remove). Left-out rows get "Swap in".
 *
 * Dev-mock returns sample pages so the table has shape without a live backend.
 * The empty state replaces the table only when the site has no pages and
 * nothing is syncing (first install, before Connect finishes reading).
 */
import { useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { rpc } from '@/api/rpc'
import { getConfig } from '@/lib/config'
import { useSyncProgress } from '@/hooks/useSyncProgress'
import { isSyncActive } from '@/lib/syncLabel'
import { useSyncStatus } from '@/hooks/useSyncStatus'
import { usePlanStatus, PLAN_STATUS_KEY } from '@/hooks/usePlanStatus'
import { usePagesLive } from '@/hooks/usePagesLive'
import { usePagesActions } from '@/hooks/usePagesActions'
import { addUrlHelp, anyReading, emptyPagesCopy, isFindingPages, listSignature, pageState, rowCells, swapNeedsReplacement } from '@/lib/pagesStatus'
import type { PageInfo, PageSourceCounts, PageStateFilter } from '@/api/types'
import { Card } from '@/components/ui/Card'
import { EmptyState } from '@/components/ui/EmptyState'
import { confirm } from '@/components/ui/ConfirmDialog'
import { relativeTime } from '@/lib/utils'
import { AddUrlsModal } from './AddUrlsModal'
import { RowActionMenu } from './RowActionMenu'
import { PagesSyncControls } from './PagesSyncControls'
import { PagesStatusItem } from './PagesStatusItem'
import { PagesStateFilter } from './PagesStateFilter'
import { SwapPageDialog } from './SwapPageDialog'

const DEV_MOCK_KEY = 'bb_devmockapikey0000000000000000'

const HOURS_4_AGO = () => new Date(Date.now() - 4 * 60 * 60 * 1000).toISOString()
const DEV_MOCK_PAGES: PageInfo[] = [
  { id: '1', url: 'https://example.com/', title: 'Home', summary: null, enabled: true, state: 'on', wordCount: 220, lastCrawledAt: HOURS_4_AGO(), source: 'wp_query' },
  { id: '2', url: 'https://example.com/services', title: 'Services', summary: null, enabled: true, state: 'on', wordCount: 410, lastCrawledAt: HOURS_4_AGO(), source: 'wp_query' },
  { id: '3', url: 'https://example.com/services/photography', title: 'Photography', summary: null, enabled: true, state: 'on', wordCount: 180, lastCrawledAt: HOURS_4_AGO(), source: 'sitemap_fallback' },
  { id: '4', url: 'https://example.com/services/videography', title: 'Videography', summary: null, enabled: true, state: 'on', wordCount: 150, lastCrawledAt: HOURS_4_AGO(), source: 'sitemap_fallback' },
  { id: '5', url: 'https://example.com/about', title: 'About us', summary: null, enabled: true, state: 'on', wordCount: 300, lastCrawledAt: HOURS_4_AGO(), source: 'wp_query' },
  { id: '6', url: 'https://example.com/docs/handbook', title: 'Staff handbook', summary: null, enabled: false, state: 'off', wordCount: 500, lastCrawledAt: HOURS_4_AGO(), source: 'manual' },
  { id: '8', url: 'https://example.com/contact', title: 'Contact', summary: null, enabled: true, state: 'reading', wordCount: 0, lastCrawledAt: null, source: 'wp_query' },
  { id: '7', url: 'https://example.com/blog/spring-sale', title: 'Spring sale', summary: null, enabled: false, state: 'left_out', wordCount: 0, lastCrawledAt: null, source: 'wp_query' },
]

const PAGE_SIZE = 25

/**
 * Source filter buckets — deliberately just two origins: "auto" (anything
 * Youami found on its own: WordPress pages + sitemap) and "manual" (URLs the
 * owner added). The internal wp/sitemap distinction stays server-side only.
 */
type SourceFilter = 'all' | 'auto' | 'manual'

/** Map a raw page.source to a small labelled chip for the Source column. */
function sourceChip(source: string | null | undefined): { label: string; icon: string; manual: boolean } {
  if (source === 'manual') return { label: 'Added by you', icon: '🔗', manual: true }
  // wp_query / wp / sitemap_fallback / null all collapse to "Your website" —
  // they're all pages Youami found on the owner's own site.
  return { label: 'Your website', icon: '🌐', manual: false }
}

const matchesBucket = (p: PageInfo, b: SourceFilter) =>
  b === 'all' || (b === 'manual' && p.source === 'manual') || (b === 'auto' && p.source !== 'manual')

const matchesState = (p: PageInfo, f: PageStateFilter) =>
  f === 'all' || pageState(p) === f || (f === 'on' && pageState(p) === 'reading')

function devCounts(): PageSourceCounts {
  const manual = DEV_MOCK_PAGES.filter((p) => p.source === 'manual').length
  const by = (s: string) => DEV_MOCK_PAGES.filter((p) => pageState(p) === s).length
  return { all: DEV_MOCK_PAGES.length, auto: DEV_MOCK_PAGES.length - manual, manual, on: by('on') + by('reading'), off: by('off'), leftOut: by('left_out'), reading: by('reading') }
}

function WordsCell({ page }: { page: PageInfo }) {
  const kind = rowCells(pageState(page)).words
  if (kind === 'not_indexed') return <span className="bb-pages-wordcount bb-pages-muted">Not indexed</span>
  if (kind === 'ellipsis') return <span className="bb-pages-wordcount bb-pages-muted" title="Reading this page" aria-label="Reading this page">…</span>
  const wc = page.wordCount ?? 0
  const thin = wc > 0 && wc < 30
  return (
    <span
      className={`bb-pages-wordcount${thin ? ' bb-pages-wordcount-thin' : ''}`}
      title={thin ? 'Very little content. May not help the bot answer questions.' : (wc >= 500 ? 'Capped at the indexed summary length' : 'Words in the indexed summary')}
    >
      {wc >= 500 ? '500+' : wc}
    </span>
  )
}

function StatePill({ page }: { page: PageInfo }) {
  const st = pageState(page)
  if (st === 'left_out') {
    return (
      <span className="bb-pages-status-stack">
        <span className="bb-pages-status bb-pages-status-left">Left out</span>
        <span className="bb-pages-status-note">Page limit</span>
      </span>
    )
  }
  const cls = st === 'on' ? 'bb-pages-status-on' : st === 'reading' ? 'bb-pages-status-reading' : 'bb-pages-status-off'
  return <span className={`bb-pages-status ${cls}`}>{rowCells(st).pill}</span>
}

export function IndexedPagesTable() {
  const cfg = getConfig()
  const qc = useQueryClient()
  const isDev = cfg.apiKey === DEV_MOCK_KEY
  const [pageIdx, setPageIdx] = useState(0)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [showAdd, setShowAdd] = useState(false)
  // Server-side sort. Clicking "Summary words" cycles asc → desc → off (url default);
  // asc surfaces the thinnest, de-index-candidate pages first. "Last crawled"
  // cycles newest → oldest → off; oldest surfaces stale pages due a refresh.
  const [sort, setSort] = useState<'url' | 'words_asc' | 'words_desc' | 'crawled_desc' | 'crawled_asc'>('url')
  const [source, setSource] = useState<SourceFilter>('all')
  const [stateFilter, setStateFilter] = useState<PageStateFilter>('all')
  const [swapTarget, setSwapTarget] = useState<PageInfo | null>(null)

  // Debounce the search box so we hit the server once the user pauses,
  // not on every keystroke. Resetting to page 1 keeps results coherent.
  useEffect(() => {
    const t = setTimeout(() => { setDebouncedSearch(search); setPageIdx(0) }, 300)
    return () => clearTimeout(t)
  }, [search])

  const { live, firstSyncPending, windowOpen, stalled, noteList } = usePagesLive()
  const { data: plan } = usePlanStatus()
  const maxPages = plan?.limits.maxPages
  const pagesOn = plan?.usage.pagesOn

  const { data, isLoading } = useQuery({
    queryKey: ['indexed-pages', pageIdx, debouncedSearch, sort, source, stateFilter],
    queryFn: async (): Promise<{ pages: PageInfo[]; total: number }> => {
      if (isDev) {
        const filtered = DEV_MOCK_PAGES.filter((p) => matchesBucket(p, source) && matchesState(p, stateFilter))
        return { pages: filtered, total: filtered.length }
      }
      const res = await rpc<{ pages: PageInfo[]; total: number }>('GetPages', {
        page: pageIdx + 1,
        pageSize: PAGE_SIZE,
        search: debouncedSearch || undefined,
        sort: sort === 'url' ? undefined : sort,
        source: source === 'all' ? undefined : source,
        state: stateFilter === 'all' ? undefined : stateFilter,
      })
      if (!res.success) throw new Error(res.error)
      return { pages: res.data.pages, total: res.data.total }
    },
    // Keep the previous page on screen while the next loads — no flicker.
    placeholderData: keepPreviousData,
    staleTime: 60 * 1000,
    // Rows appear as they are stored: usePagesLive re-pulls this list while pages are arriving.
  })

  // Counts fetched ONCE per search term, independent of the active bucket or
  // page, so the source and state numbers stay consistent while switching filters.
  const { data: counts } = useQuery({
    // Keyed under the 'indexed-pages' prefix so the broad invalidateQueries
    // calls (after add / remove / live sync) refresh the counts too.
    queryKey: ['indexed-pages', 'counts', debouncedSearch],
    queryFn: async (): Promise<PageSourceCounts> => {
      if (isDev) return devCounts()
      const res = await rpc<{ total: number; counts?: PageSourceCounts }>('GetPages', {
        page: 1,
        pageSize: 1,
        search: debouncedSearch || undefined,
      })
      if (!res.success) throw new Error(res.error)
      return res.data.counts ?? { all: res.data.total, auto: res.data.total, manual: 0 }
    },
    placeholderData: keepPreviousData,
    staleTime: 60 * 1000,
  })

  // As the count moves, note it (keeps polling alive after a sync) and refresh
  // the plan numbers the status item reads.
  const onCount = counts?.on
  const readingNow = anyReading(counts, data?.pages ?? [])
  const signature = listSignature(counts, data?.pages ?? [])
  useEffect(() => {
    noteList(signature, readingNow)
  }, [signature, readingNow, noteList])
  useEffect(() => {
    if (typeof onCount === 'number') qc.invalidateQueries({ queryKey: PLAN_STATUS_KEY })
  }, [onCount, qc])

  // Live-refresh while a background sync runs: as new pages land, the
  // progress 'done' count ticks up — refetch the list so the admin watches
  // it fill in instead of having to reload.
  const { data: syncProgress } = useSyncProgress()
  const syncing = isSyncActive(syncProgress)
  useEffect(() => {
    if (!syncing) return
    qc.invalidateQueries({ queryKey: ['indexed-pages'] })
  }, [syncProgress?.done, syncProgress?.gather?.done, syncing, qc])

  // Server-side sitemap fill (pasted sitemap expanding in the background).
  const { data: syncStatus } = useSyncStatus()
  const fill = syncStatus?.sitemapFill
  const fillActive = !!fill?.active
  const fillWasActive = useRef(false)
  useEffect(() => {
    if (fillActive) {
      fillWasActive.current = true
      qc.invalidateQueries({ queryKey: ['indexed-pages'] })
    } else if (fillWasActive.current) {
      // Final refresh when the fill finishes so counts/list settle.
      fillWasActive.current = false
      qc.invalidateQueries({ queryKey: ['indexed-pages'] })
    }
  }, [fillActive, fill?.done, qc])

  // Per-row action menu state — pageId of the currently open menu
  // plus the trigger button's bounding rect for portaled positioning.
  const [openMenu, setOpenMenu] = useState<{ id: string; anchor: DOMRect } | null>(null)

  // Multi-select for bulk actions. Holds page ids across pagination pages
  // (the select-all checkbox only acts on the currently-visible page).
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const toggleOne = (id: string, on: boolean) =>
    setSelected((cur) => {
      const next = new Set(cur)
      if (on) next.add(id); else next.delete(id)
      return next
    })

  const { resyncPage, togglePage, turnOffMany, removePage, removePages, indexUrls } = usePagesActions({
    onClearSelection: () => setSelected(new Set()),
    onAdded: () => setShowAdd(false),
  })

  const handleBulkRemove = async () => {
    const ids = Array.from(selected)
    if (ids.length === 0) return
    const many = ids.length !== 1
    const ok = await confirm({
      title: `Remove ${ids.length} page${many ? 's' : ''}?`,
      message: `The bot stops using ${many ? 'them' : 'it'} and ${many ? 'they stay' : 'it stays'} out of future syncs. Add ${many ? 'their URLs' : 'its URL'} again to bring ${many ? 'them' : 'it'} back.`,
      confirmLabel: `Remove ${ids.length}`,
      danger: true,
    })
    if (ok) removePages.mutate(ids)
  }

  const startSwapIn = (page: PageInfo) => {
    // A free slot needs no replacement: just read the page.
    if (!swapNeedsReplacement(maxPages, pagesOn)) resyncPage.mutate({ url: page.url, source: page.source })
    else setSwapTarget(page)
  }

  // Server returns exactly this page; total is the full (filtered) count.
  const visible = data?.pages ?? []
  const total = data?.total ?? 0
  const c = counts ?? { all: 0, auto: 0, manual: 0 }

  // Source dropdown options with their (search-scoped) counts. Buckets with a
  // zero count are hidden so the menu only offers origins the site actually has.
  const sourceOptions = ([
    { key: 'all', label: 'All sources', n: c.all },
    { key: 'auto', label: '🌐 Your website', n: c.auto },
    { key: 'manual', label: '🔗 Added by you', n: c.manual },
  ] as { key: SourceFilter; label: string; n: number }[]).filter((o) => o.key === 'all' || o.n > 0)
  const showSourceFilter = sourceOptions.length > 2
  const selectSource = (key: SourceFilter) => { setSource(key); setPageIdx(0) }
  const selectState = (key: PageStateFilter) => { setStateFilter(key); setPageIdx(0) }
  const start = pageIdx * PAGE_SIZE
  const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE))

  const addModal = showAdd && (
    <AddUrlsModal
      onCancel={() => setShowAdd(false)}
      onSubmit={(urls) => indexUrls.mutate(urls)}
      submitting={indexUrls.isPending}
      limitHelp={addUrlHelp(maxPages, pagesOn)}
    />
  )

  const untouched = debouncedSearch === '' && source === 'all' && stateFilter === 'all'
  // Nothing stored yet: the toolbar, filter row and status item stay, and the
  // empty message sits in the table card below them.
  const isEmptySite = !isLoading && total === 0 && untouched
  const finding = isEmptySite && isFindingPages(live, firstSyncPending, windowOpen)
  const emptyCopy = emptyPagesCopy(finding)

  const noRowsText = debouncedSearch !== ''
      ? `No pages match "${debouncedSearch}"`
      : 'No pages in this view.'

  return (
    <Card
      titleAction={
        // The toolbar morphs in place when rows are selected — bulk actions
        // replace the search/Add row at the SAME height, so selecting never
        // inserts a bar that shoves the table down (no layout shift).
        selected.size > 0 ? (
          <div className="bb-pages-actions-row bb-pages-actions-row-bulk">
            <span className="bb-pages-bulkbar-count">{selected.size} selected</span>
            <button
              type="button"
              className="bb-btn bb-btn-ghost bb-btn-small"
              onClick={() => turnOffMany.mutate(Array.from(selected))}
              disabled={turnOffMany.isPending}
            >{turnOffMany.isPending ? 'Turning off…' : 'Turn off'}</button>
            <button
              type="button"
              className="bb-btn bb-btn-danger bb-btn-small"
              onClick={handleBulkRemove}
              disabled={removePages.isPending}
            >{removePages.isPending ? 'Removing…' : `Remove ${selected.size}`}</button>
            <button
              type="button"
              className="bb-link-btn bb-btn-small"
              onClick={() => setSelected(new Set())}
            >Clear</button>
          </div>
        ) : (
          <div className="bb-pages-actions-row">
            <input
              type="search"
              className="bb-input bb-pages-search"
              placeholder="Filter by URL or title"
              aria-label="Filter by URL or title"
              disabled={isEmptySite}
              value={search}
              onChange={(e) => { setSearch(e.target.value); setPageIdx(0) }}
            />
            {showSourceFilter && (
              <select
                className="bb-input bb-pages-srcselect"
                aria-label="Filter by source"
                value={source}
                onChange={(e) => selectSource(e.target.value as SourceFilter)}
              >
                {sourceOptions.map((o) => (
                  <option key={o.key} value={o.key}>{o.label} ({o.n})</option>
                ))}
              </select>
            )}
            <button
              type="button"
              className="bb-btn bb-btn-ghost bb-btn-small"
              onClick={() => setShowAdd(true)}
              title="Add a page URL or a sitemap (.xml) for the bot to learn"
            >+ Add URL/Sitemap</button>
            <PagesSyncControls />
          </div>
        )
      }
      noBody
    >
      <div className="bb-pages-filterbar">
        <PagesStateFilter value={stateFilter} counts={counts} onChange={selectState} />
        <PagesStatusItem finding={finding} reading={counts?.reading ?? 0} firstSyncPending={firstSyncPending} stalled={stalled} />
      </div>
      {isEmptySite ? (
        <EmptyState
          headline={emptyCopy.headline}
          body={emptyCopy.body}
          ctaLabel={emptyCopy.cta ? 'Add URL/Sitemap' : undefined}
          onCta={emptyCopy.cta ? () => setShowAdd(true) : undefined}
        />
      ) : (
      <table className="bb-pages-table">
        <thead>
          <tr>
            <th className="bb-pages-check">
              <input
                type="checkbox"
                aria-label="Select all on this page"
                checked={visible.length > 0 && visible.every((p) => selected.has(p.id))}
                ref={(el) => {
                  if (el) {
                    el.indeterminate =
                      visible.some((p) => selected.has(p.id)) &&
                      !visible.every((p) => selected.has(p.id))
                  }
                }}
                onChange={(e) => {
                  const on = e.target.checked
                  setSelected((cur) => {
                    const next = new Set(cur)
                    visible.forEach((p) => { if (on) next.add(p.id); else next.delete(p.id) })
                    return next
                  })
                }}
              />
            </th>
            <th>URL</th>
            <th className="bb-pages-source-h">Source</th>
            <th className="bb-pages-words-h">
              <button
                type="button"
                className="bb-pages-sortbtn"
                onClick={() => {
                  setPageIdx(0)
                  setSort((s) => (s === 'words_asc' ? 'words_desc' : s === 'words_desc' ? 'url' : 'words_asc'))
                }}
                title="Sort by word count. Ascending shows the thinnest pages first."
              >
                Summary words
                <span className="bb-pages-sortarrow" aria-hidden="true">
                  {sort === 'words_asc' ? '▲' : sort === 'words_desc' ? '▼' : '↕'}
                </span>
              </button>
            </th>
            <th className="bb-pages-words-h">
              <button
                type="button"
                className="bb-pages-sortbtn"
                onClick={() => {
                  setPageIdx(0)
                  setSort((s) => (s === 'crawled_desc' ? 'crawled_asc' : s === 'crawled_asc' ? 'url' : 'crawled_desc'))
                }}
                title="Sort by last crawl: newest first, then oldest"
              >
                Last crawled
                <span className="bb-pages-sortarrow" aria-hidden="true">
                  {sort === 'crawled_desc' ? '▼' : sort === 'crawled_asc' ? '▲' : '↕'}
                </span>
              </button>
            </th>
            <th>Status</th>
            <th aria-label="actions" />
          </tr>
        </thead>
        <tbody>
          {isLoading && (
            <tr><td colSpan={7} className="bb-pages-empty">Loading pages…</td></tr>
          )}
          {!isLoading && visible.length === 0 && (
            <tr><td colSpan={7} className="bb-pages-empty">{noRowsText}</td></tr>
          )}
          {!isLoading && visible.map((page) => {
            const st = pageState(page)
            const cells = rowCells(st)
            return (
              <tr key={page.id} className={selected.has(page.id) ? 'bb-pages-row-selected' : undefined}>
                <td className="bb-pages-check">
                  <input
                    type="checkbox"
                    aria-label={`Select ${page.title || page.url}`}
                    checked={selected.has(page.id)}
                    onChange={(e) => toggleOne(page.id, e.target.checked)}
                  />
                </td>
                <td className="bb-pages-url">
                  <div className="bb-pages-title">{page.title || '—'}</div>
                  <a
                    className="bb-pages-href"
                    href={page.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    title="Open page in a new tab"
                  >
                    <span className="bb-pages-href-text">{page.url}</span>
                    <span className="bb-pages-href-ext" aria-hidden="true">↗</span>
                  </a>
                </td>
                <td className="bb-pages-source">
                  {(() => {
                    const chip = sourceChip(page.source)
                    return (
                      <span className={`bb-pages-srcchip${chip.manual ? ' bb-pages-srcchip-manual' : ''}`}>
                        <span aria-hidden="true">{chip.icon}</span>{chip.label}
                      </span>
                    )
                  })()}
                </td>
                <td className="bb-pages-words"><WordsCell page={page} /></td>
                <td className="bb-pages-time">{cells.crawled === 'never' ? 'Never' : cells.crawled === 'now' ? 'Now' : page.lastCrawledAt ? relativeTime(page.lastCrawledAt) : '—'}</td>
                <td><StatePill page={page} /></td>
                <td className="bb-pages-actions">
                  {cells.swapIn && (
                    <button
                      type="button"
                      className="bb-btn bb-btn-ghost bb-btn-small bb-pages-swapbtn"
                      onClick={() => startSwapIn(page)}
                      disabled={resyncPage.isPending}
                    >Swap in</button>
                  )}
                  <button
                    className="bb-link-btn bb-pages-action-trigger"
                    type="button"
                    aria-label="Row actions"
                    onClick={(e) => {
                      const rect = e.currentTarget.getBoundingClientRect()
                      setOpenMenu((cur) => cur?.id === page.id ? null : { id: page.id, anchor: rect })
                    }}
                  >⋯</button>
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
      )}
      {total > PAGE_SIZE && (
        <footer className="bb-pages-footer">
          <span>
            Showing {start + 1}–{Math.min(start + PAGE_SIZE, total)} of {total}
          </span>
          <div className="bb-pages-pager">
            <button
              className="bb-btn bb-btn-ghost bb-btn-small"
              onClick={() => setPageIdx((i) => Math.max(0, i - 1))}
              disabled={pageIdx === 0}
            >← Previous</button>
            <span className="bb-pages-pageno">{pageIdx + 1} / {totalPages}</span>
            <button
              className="bb-btn bb-btn-ghost bb-btn-small"
              onClick={() => setPageIdx((i) => Math.min(totalPages - 1, i + 1))}
              disabled={pageIdx >= totalPages - 1}
            >Next →</button>
          </div>
        </footer>
      )}
      {addModal}
      {swapTarget && (
        <SwapPageDialog
          page={swapTarget}
          devPages={DEV_MOCK_PAGES.filter((p) => pageState(p) === 'on')}
          submitting={resyncPage.isPending}
          onCancel={() => setSwapTarget(null)}
          onConfirm={(replacePageId) =>
            resyncPage.mutate({ url: swapTarget.url, replacePageId, source: swapTarget.source }, { onSuccess: () => setSwapTarget(null) })
          }
        />
      )}
      {openMenu && (() => {
        const page = visible.find((p) => p.id === openMenu.id)
        if (!page) return null
        return (
          <RowActionMenu
            anchor={openMenu.anchor}
            page={page}
            onClose={() => setOpenMenu(null)}
            onResync={() => { setOpenMenu(null); resyncPage.mutate({ url: page.url, source: page.source }) }}
            onToggle={() => { setOpenMenu(null); togglePage.mutate({ pageId: page.id, url: page.url, source: page.source, enabled: !page.enabled }) }}
            onRemove={async () => {
              setOpenMenu(null)
              const ok = await confirm({
                title: 'Remove this page?',
                message: 'The bot stops using it and it stays out of future syncs. Add its URL again to bring it back.',
                confirmLabel: 'Remove',
                danger: true,
              })
              if (ok) removePage.mutate(page.id)
            }}
            resyncing={resyncPage.isPending}
            toggling={togglePage.isPending}
            removing={removePage.isPending}
          />
        )
      })()}
    </Card>
  )
}
