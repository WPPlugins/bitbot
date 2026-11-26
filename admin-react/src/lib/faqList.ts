/** Pure helpers for the FAQs sub tab (no React), unit tested. */

/** The search box only earns its space on a long list. */
export const FAQ_SEARCH_FROM = 8

/** The one count on the tab, e.g. "2 of 20 FAQs" (plus how many are switched off, if any). */
export function faqCountText(active: number, cap: number, off: number): string {
  return `${active} of ${cap} FAQs` + (off > 0 ? ` · ${off} off` : '')
}

/** FAQs whose question or answer contains the search text (case-insensitive). */
export function filterFaqs<T extends { question: string; answer: string }>(faqs: T[], query: string): T[] {
  const q = query.trim().toLowerCase()
  if (!q) return faqs
  return faqs.filter((f) => f.question.toLowerCase().includes(q) || f.answer.toLowerCase().includes(q))
}

/**
 * Rows to show: the search matches, plus the row being edited even when it no longer matches,
 * so typing in the search box can never unmount an editor with unsaved text.
 */
export function visibleFaqList<T extends { id: string; question: string; answer: string }>(faqs: T[], query: string, editingId: string | null): T[] {
  const matches = new Set(filterFaqs(faqs, query).map((f) => f.id))
  return faqs.filter((f) => matches.has(f.id) || f.id === editingId)
}

/** The search box shows on a long list, and stays for as long as a search is typed in it. */
export function showFaqSearch(total: number, query: string): boolean {
  return total > FAQ_SEARCH_FROM || query.trim() !== ''
}

/** Select all acts on the rows on screen: every visible row ticked means "all". */
export function allVisibleChecked(visibleIds: string[], checked: ReadonlySet<string>): boolean {
  return visibleIds.length > 0 && visibleIds.every((id) => checked.has(id))
}

/** Tick or untick every visible row, leaving rows hidden by the search as they are. */
export function toggleVisible(visibleIds: string[], checked: ReadonlySet<string>): Set<string> {
  const next = new Set(checked)
  if (allVisibleChecked(visibleIds, checked)) visibleIds.forEach((id) => next.delete(id))
  else visibleIds.forEach((id) => next.add(id))
  return next
}
