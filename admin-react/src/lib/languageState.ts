/**
 * The language list on the Widget tab: which languages are listed, and the
 * state of each translated row. Pure (no React).
 */
import type { WidgetCopyRow } from './widgetCopy.ts'

export type CopyState = 'site' | 'auto' | 'review' | 'edited' | 'failed' | 'none' | 'pending' | 'off'

export const STATE_LABELS: Record<CopyState, string> = {
  site: 'Site language',
  auto: 'Automatic',
  review: 'Needs review',
  edited: 'Edited',
  failed: 'Failed',
  none: 'Not translated',
  pending: 'Translating',
  off: 'Off',
}

/** The state of one translated row. Failed wins, then needs review, then automatic. */
export function rowState(row: WidgetCopyRow | undefined): CopyState {
  if (!row) return 'none'
  if (row.failed) return 'failed'
  if (row.needsReview) return 'review'
  return row.auto ? 'auto' : 'edited'
}

export interface LanguageRow {
  code: string
  isSite: boolean
  /** Offered to visitors (in the effective selection). */
  on: boolean
  /** Found on the site by the language detector. */
  detected: boolean
  state: CopyState
}

/**
 * The rows to list: the site language first, then every other selected
 * language, then detected languages that were switched off (they stay listed).
 */
export function languageRows(
  selected: readonly string[],
  detected: readonly string[],
  site: string,
  copy: Record<string, WidgetCopyRow> | undefined,
  /** A save is in flight, so a missing row is being translated, not absent. */
  translating = false,
): LanguageRow[] {
  const rest = selected.filter((c) => c !== site)
  const off = detected.filter((c) => c !== site && !selected.includes(c))
  const make = (code: string, on: boolean): LanguageRow => ({
    code,
    isSite: code === site,
    on,
    detected: code === site || detected.includes(code),
    state: code === site ? 'site' : on ? (translating && !copy?.[code] ? 'pending' : rowState(copy?.[code])) : 'off',
  })
  return [make(site, true), ...rest.map((c) => make(c, true)), ...off.map((c) => make(c, false))]
}

/** Right-to-left scripts, so the preview can set the direction. */
const RTL = new Set(['ar', 'he', 'fa', 'ur', 'ps', 'sd', 'yi'])
export function isRtl(code: string): boolean {
  return RTL.has(code)
}
