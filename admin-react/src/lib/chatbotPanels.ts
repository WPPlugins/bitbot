/**
 * Pure helpers for the Chatbot tab: sub-tab names, the status bar words, the
 * page-visibility summary, the invitation delay options and the advanced-row
 * summaries. No React, so a plain node test can hold them.
 */
export type ChatbotSubtab = 'widget' | 'replies'

/** Accepts the old names too: look is widget, behaviour is replies. */
export function normalizeSub(raw: string | null | undefined): ChatbotSubtab | null {
  if (raw === 'widget' || raw === 'look') return 'widget'
  if (raw === 'replies' || raw === 'behaviour') return 'replies'
  return null
}

/** The excluded URL paths: one per line, blanks dropped. */
export function hiddenPages(raw: string | null | undefined): string[] {
  return (raw ?? '').split('\n').map((l) => l.trim()).filter((l) => l !== '')
}

export function statusSummary(enabled: boolean, hiddenCount: number): string {
  if (!enabled) return 'Off · hidden from visitors'
  return hiddenCount > 0 ? `On · every page except ${hiddenCount}` : 'On · every page'
}

const LISTED_PAGES = 3

export function hideSummary(hidden: readonly string[]): string {
  if (hidden.length === 0) return 'All pages'
  const shown = hidden.slice(0, LISTED_PAGES).join(', ')
  const more = hidden.length > LISTED_PAGES ? ` and ${hidden.length - LISTED_PAGES} more` : ''
  return `All pages except ${hidden.length}: ${shown}${more}`
}

export type SaveState = 'saving' | 'error' | 'saved'

export function saveState(isSaving: boolean, saveFailed: boolean): SaveState {
  if (isSaving) return 'saving'
  return saveFailed ? 'error' : 'saved'
}

export const SAVE_LABELS: Record<SaveState, string> = {
  saving: 'Saving…',
  error: "Couldn't save",
  saved: '✓ Saved',
}

export const SAVED_NOTE = 'changes appear on your site'

export const SHOW_AFTER_PRESETS: readonly number[] = [5, 8, 15]

/** The "Show after" choices: the presets, plus the stored value as a fourth when it is something else. */
export function showAfterOptions(seconds: number): number[] {
  return SHOW_AFTER_PRESETS.includes(seconds) ? [...SHOW_AFTER_PRESETS] : [...SHOW_AFTER_PRESETS, seconds]
}

export function radiusOffsetSummary(radius: number, x: number, y: number): string {
  return `${radius}px · ${x} / ${y} px`
}
