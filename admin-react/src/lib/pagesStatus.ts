/**
 * Pure logic for the Knowledgebase → Indexed pages status item and filters.
 * No React, no network: the component feeds numbers in and renders the result.
 * Every new backend field may be absent (older backend), so each helper copes.
 */
import type { PageInfo, PageSourceCounts, PageState, SyncStatus } from '../api/types.ts'

/** From this share of the Free page limit the status item shows a bar and Upgrade. */
export const NEAR_LIMIT_RATIO = 0.8

/** First sync not finished yet. Unknown (status not loaded) counts as done. */
export function firstSyncPending(status: Pick<SyncStatus, 'firstSyncDone' | 'lastSync'> | null | undefined): boolean {
  if (!status) return false
  if (typeof status.firstSyncDone === 'boolean') return !status.firstSyncDone
  return status.lastSync === null
}

/** Any row still waiting for its content: from the counts, else from visible rows. */
export function anyReading(counts: PageSourceCounts | null | undefined, rows: Pick<PageInfo, 'state' | 'enabled'>[]): boolean {
  if (typeof counts?.reading === 'number') return counts.reading > 0
  return rows.some((r) => pageState(r) === 'reading')
}

/** Changes whenever anything visible about the list changes: rows, counts, a row's state or words. */
export function listSignature(
  counts: PageSourceCounts | null | undefined,
  rows: Pick<PageInfo, 'id' | 'state' | 'enabled' | 'wordCount'>[],
): string {
  const c = counts ? [counts.all, counts.on, counts.off, counts.leftOut, counts.reading].join(',') : ''
  return `${c}|${rows.map((r) => `${r.id}:${pageState(r)}:${r.wordCount ?? 0}`).join(';')}`
}

/** What a row shows, by state. Pure so it can be tested without rendering. */
export interface RowCells {
  words: 'count' | 'ellipsis' | 'not_indexed'
  crawled: 'time' | 'now' | 'never'
  pill: 'On' | 'Off' | 'Reading' | 'Left out'
  /** Menu items offered; Remove is always there. */
  menu: { resync: boolean; toggle: boolean }
  swapIn: boolean
}
export function rowCells(state: PageState): RowCells {
  if (state === 'reading') return { words: 'ellipsis', crawled: 'now', pill: 'Reading', menu: { resync: false, toggle: false }, swapIn: false }
  if (state === 'left_out') return { words: 'not_indexed', crawled: 'never', pill: 'Left out', menu: { resync: false, toggle: false }, swapIn: true }
  return { words: 'count', crawled: 'time', pill: state === 'on' ? 'On' : 'Off', menu: { resync: true, toggle: true }, swapIn: false }
}

/** How long to keep refetching the list after the page count last changed. */
export const POLL_SETTLE_MS = 20_000

/** Refetch period while polling. */
export const POLL_INTERVAL_MS = 4_000

/**
 * While the first sync is unfinished or rows are still being read, poll this
 * long with no change in the list at all (counted from the last change, or from
 * opening the pane) before stopping. An abandoned tab must not poll forever.
 */
export const FIRST_RUN_POLL_CEILING_MS = 10 * 60_000

export const STALLED_TEXT = 'Still reading pages. Press Sync now if this does not move.'

/** Page state, derived from `enabled` when the backend sends no `state`. */
export function pageState(p: Pick<PageInfo, 'state' | 'enabled'>): PageState {
  if (p.state === 'on' || p.state === 'off' || p.state === 'left_out' || p.state === 'reading') return p.state
  return p.enabled ? 'on' : 'off'
}

/** A plan limit is in force only for a positive maxPages. */
export function hasPageLimit(maxPages: number | null | undefined): maxPages is number {
  return typeof maxPages === 'number' && maxPages > 0
}

/** Free slots left, or null when there is no limit or usage is unknown. */
export function slotsLeft(maxPages: number | null | undefined, pagesOn: number | null | undefined): number | null {
  if (!hasPageLimit(maxPages) || typeof pagesOn !== 'number') return null
  return Math.max(0, maxPages - pagesOn)
}

export type SyncOutcome = 'ok' | 'stopped' | 'failed'

export interface PagesStatusInput {
  maxPages?: number | null
  pagesOn?: number | null
  /** Relative time of the last sync, e.g. "3h ago"; empty when never synced. */
  syncedAgo: string
  syncing: boolean
  /** Pages read so far in the current sync (any source), and the total when known. */
  syncDone: number
  syncTotal: number
  /** What the running sync is doing right now, e.g. "Reading posts 6 / 18" (lib/syncLabel). Wins over every count. */
  syncText?: string
  /** 0-100 progress of that step. */
  syncPercent?: number
  /** Brief "Synced" flash after a run ends. */
  flash?: boolean
  outcome?: SyncOutcome
  /** Zero pages and a first sync is expected or running. */
  finding?: boolean
  /** Rows holding a slot with no content yet (they count in pagesOn). */
  reading?: number
  /** First sync not finished. */
  firstSyncPending?: boolean
  /** Polling gave up while the first sync or reads were still unfinished. */
  stalled?: boolean
}

export type PagesStatusTone = 'muted' | 'accent' | 'success'

export interface PagesStatus {
  /** Whole line as one string (lead and main joined by a middle dot). */
  text: string
  /** Muted lead: "Sync stopped", "Synced 3h ago". May be empty. */
  lead: string
  /** The part the tone applies to: allowance or live progress. May be empty. */
  main: string
  tone: PagesStatusTone
  /** 0-100 when a bar should show, else null. */
  barPercent: number | null
  showUpgrade: boolean
}

const n = (v: number) => v.toLocaleString()
const pct = (done: number, total: number) => (total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0)

function lead(outcome: SyncOutcome | undefined): string {
  if (outcome === 'stopped') return 'Sync stopped'
  if (outcome === 'failed') return 'Sync failed'
  return ''
}

function join(parts: string[]): string {
  return parts.filter(Boolean).join(' · ')
}

function build(lead: string, main: string, tone: PagesStatusTone, barPercent: number | null, showUpgrade: boolean): PagesStatus {
  return { text: join([lead, main]), lead, main, tone, barPercent, showUpgrade }
}

/** The single status item at the right of the filter row. */
export function pagesStatus(i: PagesStatusInput): PagesStatus {
  const limited = hasPageLimit(i.maxPages) && typeof i.pagesOn === 'number'
  const synced = i.flash ? 'Synced' : i.syncedAgo ? `Synced ${i.syncedAgo}` : ''
  const leadText = join([lead(i.outcome), synced])
  const tone: PagesStatusTone = i.flash ? 'success' : 'muted'
  const reading = i.reading ?? 0
  const busy = !!i.syncing || reading > 0 || !!i.firstSyncPending

  // Gave up polling with work unfinished: never claim "Synced".
  if (i.stalled) return build('', STALLED_TEXT, 'muted', null, false)

  // A running sync says what it is doing; the allowance returns when it ends.
  // The allowance alone ("15 of 15") never moves during a re-sync, so it must not stand in for progress.
  if (i.syncing && i.syncText) {
    return build('', i.syncText, 'muted', Math.max(0, Math.min(100, Math.round(i.syncPercent ?? 0))), false)
  }

  if (!limited) {
    if (i.syncing && i.syncTotal > 0) {
      return build('', `Syncing ${n(i.syncDone)} of ${n(i.syncTotal)} pages`, 'muted', pct(i.syncDone, i.syncTotal), false)
    }
    if (reading > 0 && typeof i.pagesOn === 'number') {
      const ready = Math.max(0, i.pagesOn - reading)
      return build('', `Syncing ${n(ready)} of ${n(i.pagesOn)} pages`, 'muted', pct(ready, i.pagesOn), false)
    }
    if (i.finding) return build('', 'Finding pages…', 'muted', null, false)
    if (busy) return build('', 'Syncing pages…', 'muted', null, false)
    return build(leadText, '', tone, null, false)
  }

  const max = i.maxPages as number
  const on = i.pagesOn as number
  const atLimit = on >= max
  const near = on / max >= NEAR_LIMIT_RATIO

  if (busy || (i.finding && on === 0)) {
    const ready = Math.max(0, on - reading)
    const finding = on === 0 && i.syncDone === 0
    const main = finding ? 'Free plan: finding pages…' : `Free plan: syncing ${n(ready)} of ${n(max)} pages`
    return build('', main, atLimit ? 'accent' : 'muted', finding ? null : pct(ready, max), near)
  }
  return build(leadText, `Free plan: ${n(on)} of ${n(max)} pages`, atLimit ? 'accent' : tone, near ? pct(on, max) : null, near)
}

export interface PollInput {
  /** A sync is active (progress option, GetSyncStatus 'syncing', or sitemap fill). */
  live: boolean
  /** The site's first sync has not finished (independent of how many rows exist). */
  firstSyncPending: boolean
  /** Some row holds a slot but has no content yet. */
  reading: boolean
  /** Time since anything in the list last changed. */
  msSinceCountChange: number
  /** Time since the pane opened. */
  msSinceOpen: number
}

/**
 * Keep polling while a sync is active, for a while after anything changed, and
 * while the first sync is unfinished or rows are still being read, until
 * FIRST_RUN_POLL_CEILING_MS pass with no change. The first sync after Connect
 * runs from cron and reports through none of the sync signals, and the backend
 * claims slots up front, so row counts alone say nothing about completion.
 */
export function shouldPollPages(i: PollInput): boolean {
  if (i.live) return true
  if (i.msSinceCountChange < POLL_SETTLE_MS) return true
  return (i.firstSyncPending || i.reading) && Math.min(i.msSinceCountChange, i.msSinceOpen) < FIRST_RUN_POLL_CEILING_MS
}

/** Polling gave up, yet work is unfinished: the screen must not look finished. */
export function isStalled(i: PollInput): boolean {
  return !i.live && (i.firstSyncPending || i.reading) && !shouldPollPages(i)
}

/** True while an empty site is waiting for its first pages. */
export function isFindingPages(live: boolean, firstRun: boolean, pollingFirstRun: boolean): boolean {
  return live || (firstRun && pollingFirstRun)
}

export interface EmptyCopy {
  headline: string
  body: string
  /** Call to action; absent while pages are being found. */
  cta: boolean
}

/** Empty-table copy: finding while a first sync is expected, else the idle call to action. */
export function emptyPagesCopy(finding: boolean): EmptyCopy {
  return finding
    ? { headline: 'Finding your pages', body: 'Pages appear here as they are read. This can take a minute.', cta: false }
    : { headline: 'No pages indexed yet', body: 'Run a sync to crawl your site, or paste specific URLs you want the bot to learn.', cta: true }
}

export interface StateFilterOption {
  key: 'on' | 'left_out' | 'off' | 'all'
  label: string
  n: number | null
  warn: boolean
}

/**
 * Filter options with counts. Empty (no filter shown) when the backend sends no
 * per-state counts. "Left out" appears only when there is something left out.
 */
export function stateFilterOptions(c: PageSourceCounts | null | undefined): StateFilterOption[] {
  if (!c || typeof c.on !== 'number') return []
  const out: StateFilterOption[] = [{ key: 'on', label: 'On', n: c.on, warn: false }]
  if ((c.leftOut ?? 0) > 0) out.push({ key: 'left_out', label: 'Left out', n: c.leftOut ?? 0, warn: true })
  out.push({ key: 'off', label: 'Off', n: c.off ?? 0, warn: false })
  out.push({ key: 'all', label: 'All pages', n: null, warn: false })
  return out
}

/** Backend error codes for enabling or swapping a page. */
export type PageErrorKind = 'limit' | 'needs_sync' | null
export function pageErrorKind(err: unknown): PageErrorKind {
  const raw = err instanceof Error ? err.message : String(err ?? '')
  if (raw.includes('PAGE_LIMIT_REACHED')) return 'limit'
  if (raw.includes('PAGE_NEEDS_SYNC')) return 'needs_sync'
  return null
}

export const PAGE_LIMIT_TOAST = 'Page limit reached. Turn a page off first, or upgrade.'

/** "Swap in" needs no replacement when a slot is free. */
export function swapNeedsReplacement(maxPages: number | null | undefined, pagesOn: number | null | undefined): boolean {
  const left = slotsLeft(maxPages, pagesOn)
  return left === null ? hasPageLimit(maxPages) : left <= 0
}

/** Help line in the Add URL dialog; null when the plan has no limit. */
export function addUrlHelp(maxPages: number | null | undefined, pagesOn: number | null | undefined): string | null {
  const left = slotsLeft(maxPages, pagesOn)
  if (left === null) return null
  return `${n(left)} slot${left === 1 ? '' : 's'} left on Free. Extra pages are listed under Left out.`
}

/** The two ways to read one page and store it. */
export type ReadPath = 'plugin' | 'backend'

/**
 * Which path reads a page. Only the plugin can read a page of YOUR WordPress (it resolves the URL to a post): wp_query / wp.
 * Everything else (a sitemap URL such as an archive, an external or manual URL) has no WordPress post, so the plugin
 * can never read it: the backend fetches it.
 */
export function readPath(source: string | null | undefined): ReadPath {
  return source === 'wp_query' || source === 'wp' ? 'plugin' : 'backend'
}

/** The plugin answered that it cannot read this URL (no post for it, or it is not published): the backend then takes over once. */
export function pluginCouldNotRead(err: unknown): boolean {
  const code = (err as { code?: unknown } | null)?.code
  if (code === 'unreadable') return true
  // A plugin older than the code: its English message.
  const raw = err instanceof Error ? err.message : String(err ?? '')
  return /Could not find WordPress post|Post not found or not published/i.test(raw)
}

export interface IndexUrlOutcomeInput { fetched: number; skippedUnchanged: number; failed: number; errors: string[]; leftOut?: number }

/**
 * What the backend's one-URL IndexUrlList answer means for a single page read. Stored (or already current) is ok. Nothing
 * stored and the page did not fit (or the swap was refused) reads as the limit; otherwise the fetch failed.
 */
export function indexUrlOutcome(r: IndexUrlOutcomeInput): { ok: true } | { ok: false; message: string } {
  if (r.fetched + r.skippedUnchanged > 0) return { ok: true }
  if ((r.leftOut ?? 0) > 0) return { ok: false, message: 'PAGE_LIMIT_REACHED' }
  return { ok: false, message: r.errors[0] || 'Could not read the page' }
}

export interface ReadPageIo {
  viaPlugin: (url: string, replacePageId?: string) => Promise<void>
  viaBackend: (url: string, replacePageId?: string) => Promise<void>
}

/**
 * Read one page and store it (also the swap: replacePageId is the page switched off in the same step). The path follows the
 * page's source; if the plugin says it cannot read the URL, the backend is tried once. Any other failure is the caller's.
 */
export async function readPage(
  a: { url: string; replacePageId?: string; source?: string | null },
  io: ReadPageIo,
): Promise<void> {
  if (readPath(a.source) === 'backend') return io.viaBackend(a.url, a.replacePageId)
  try {
    await io.viaPlugin(a.url, a.replacePageId)
  } catch (err) {
    if (!pluginCouldNotRead(err)) throw err
    await io.viaBackend(a.url, a.replacePageId)
  }
}

/**
 * Toast for Add URL (no sitemap). Says what actually happened: pages read and stored, pages already in the
 * list that were left as they are (an Off page stays off, an unchanged page is not re-read), and failures.
 */
export function addUrlToast(r: Pick<IndexUrlOutcomeInput, 'fetched' | 'skippedUnchanged' | 'failed'>): string {
  const urls = (k: number) => `${n(k)} URL${k === 1 ? '' : 's'}`
  const parts: string[] = []
  if (r.fetched > 0) parts.push(`Indexed ${urls(r.fetched)}`)
  if (r.skippedUnchanged > 0) parts.push(`${urls(r.skippedUnchanged)} already in your list, nothing changed`)
  if (r.failed > 0) parts.push(`${n(r.failed)} failed`)
  return parts.length > 0 ? parts.join(', ') : 'Nothing to index'
}
