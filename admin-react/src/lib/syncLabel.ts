/**
 * Single source of truth for background-indexing copy, so the Overview Quick
 * action and the Knowledgebase progress strip always say the SAME thing.
 */
import type { SitemapFillProgress } from '../api/types.ts'
import type { GatherStatus } from '../hooks/useSyncProgress.ts'

export type SyncPhase = 'pages' | 'render' | 'images' | 'done'

export interface SyncLabel {
  /** Short heading, e.g. "Indexing your sitemap…" */
  title: string
  /** Detail line, e.g. "1,360 of 3,514 pages (39%)" */
  detail: string
  /** 0–100 for progress bars; 0 when total is unknown. */
  percent: number
  /** Which stage of the sync this is; absent on sitemap-fill / legacy labels. */
  phase?: SyncPhase
  /** Whole line in one string, e.g. "Rendering pages 840 / 2,112". */
  text?: string
  /** True when the render pass is stalled and the owner can nudge it. */
  canContinue?: boolean
}

/** The label as one line, e.g. "Rendering pages 14 / 18 (13 already current)". */
export function labelLine(l: SyncLabel | null | undefined): string {
  if (!l) return ''
  if (l.text) return l.text
  return [l.title.replace(/…$/, ''), l.detail].filter(Boolean).join(' ')
}

/** Label for an active server-side sitemap fill, or null when not running. */
export function sitemapFillLabel(fill: SitemapFillProgress | null | undefined): SyncLabel | null {
  if (!fill?.active) return null
  const percent = fill.total > 0 ? Math.min(100, Math.round((fill.done / fill.total) * 100)) : 0
  return {
    title: 'Indexing your sitemap…',
    detail: fill.total > 0
      ? `${fill.done.toLocaleString()} of ${fill.total.toLocaleString()} pages (${percent}%)`
      : 'Reading your sitemap…',
    percent,
    text: fill.total > 0 ? `Reading sitemap ${fill.done.toLocaleString()} / ${fill.total.toLocaleString()}` : 'Reading sitemap',
  }
}

/** WP-gather progress (the plugin reading your WordPress pages). */
interface GatherProgress {
  state?: string
  done?: number
  total?: number
  percent?: number
  gather?: GatherStatus
}

const ACTIVE_GATHER = ['running', 'paused', 'stalled']
const n = (v: number) => v.toLocaleString()
const pctOf = (done: number, total: number) => (total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0)

/** True while pass 1 runs or the render pass is running, paused or stalled. */
export function isSyncActive(p: GatherProgress | null | undefined): boolean {
  if (!p) return false
  return p.state === 'running' || ACTIVE_GATHER.includes(p.gather?.state ?? '')
}

/** Combined phase of the whole sync: pass 1, render pass, image reads, or done. */
export function syncPhase(p: GatherProgress | null | undefined): SyncPhase | null {
  if (!p) return null
  if (p.state === 'running') return 'pages'
  const g = p.gather
  if (g && ACTIVE_GATHER.includes(g.state ?? '')) {
    const total = g.total ?? 0
    const pending = g.images?.pending ?? 0
    return pending > 0 && (total === 0 || (g.done ?? 0) >= total) ? 'images' : 'render'
  }
  return p.state === 'done' ? 'done' : null
}

function gatherLabel(phase: SyncPhase, g: GatherStatus): SyncLabel {
  const canContinue = g.state === 'stalled'
  if (g.state === 'paused' && g.pauseReason) {
    return { title: 'Paused', detail: 'Site is slow, resuming', percent: pctOf(g.done ?? 0, g.total ?? 0), phase, text: 'Paused: site is slow, resuming' }
  }
  if (canContinue) {
    return { title: 'Stalled', detail: 'Continue', percent: pctOf(g.done ?? 0, g.total ?? 0), phase, text: 'Stalled, continue', canContinue }
  }
  if (phase === 'images') {
    const sent = g.images?.sent ?? 0
    const all = sent + (g.images?.pending ?? 0)
    const detail = `${n(sent)} / ${n(all)}`
    return { title: 'Reading images', detail, percent: pctOf(sent, all), phase, text: `Reading images ${detail}` }
  }
  const total = g.total ?? 0
  const done = g.done ?? 0
  const skipped = g.skippedCurrent ?? 0
  const detail = `${n(done)} / ${n(total)}` + (skipped > 0 ? ` (${n(skipped)} already current)` : '')
  return { title: 'Rendering pages', detail, percent: pctOf(done, total), phase, text: `Rendering pages ${detail}` }
}

/**
 * Single label for ANY active indexing, used by every sync surface so they all
 * say the same thing. The server-side sitemap fill is the big job, so it wins
 * when both run; otherwise the WP-page gather; null when nothing is indexing.
 */
export function indexingLabel(
  fill: SitemapFillProgress | null | undefined,
  gather: GatherProgress | null | undefined,
): SyncLabel | null {
  const f = sitemapFillLabel(fill)
  if (f) return f
  const phase = syncPhase(gather)
  if (gather?.gather && phase && phase !== 'done') {
    if (phase === 'pages') {
      const total = gather.total ?? 0
      const done = gather.done ?? 0
      const detail = total > 0 ? `${n(done)} / ${n(total)}` : 'Starting…'
      return { title: 'Reading posts', detail, percent: gather.percent ?? pctOf(done, total), phase, text: `Reading posts ${detail}` }
    }
    return gatherLabel(phase, gather.gather)
  }
  if (gather?.state === 'running') {
    const total = gather.total ?? 0
    const done = gather.done ?? 0
    const percent = gather.percent ?? (total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0)
    return {
      title: 'Indexing your pages…',
      detail: total > 0 ? `${done.toLocaleString()} of ${total.toLocaleString()} pages (${percent}%)` : 'Starting…',
      percent,
      text: total > 0 ? `Reading posts ${n(done)} / ${n(total)}` : 'Starting sync',
    }
  }
  return null
}
