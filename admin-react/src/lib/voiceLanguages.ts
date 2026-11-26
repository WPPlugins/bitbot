/**
 * Pure helpers for the Voice calls "Languages" control. The curated list is
 * not imported here (it is passed in) so a plain node test can run this file.
 */
export interface LanguageOption {
  code: string
  name: string
  native: string
}

/** The site language: the two-letter base tag of the WP locale ("en_US" gives "en"). */
export function siteLanguageCode(locale: string | undefined): string {
  const base = (locale ?? '').slice(0, 2).toLowerCase()
  return /^[a-z]{2}$/.test(base) ? base : 'en'
}

/** What is selected: the site language, always, then the saved codes that are still offered. */
export function selectedLanguages(saved: readonly string[] | undefined, site: string, curated: readonly LanguageOption[]): string[] {
  const known = new Set(curated.map((l) => l.code))
  const out = [site]
  for (const code of saved ?? []) {
    if (known.has(code) && !out.includes(code)) out.push(code)
  }
  return out
}

/** The list after the owner ticks or unticks one. The site language cannot be removed. */
export function toggleLanguage(selected: readonly string[], code: string, site: string): string[] {
  if (code === site) return [...selected]
  return selected.includes(code) ? selected.filter((c) => c !== code) : [...selected, code]
}

export const LANGUAGES_HELP = 'The languages your site speaks. Visitors can pick one.'
export const MORE_LANGUAGES_HELP = 'Search any language to add it to the list above.'

export const MAX_SEARCH_RESULTS = 8

/**
 * The list in force: the saved hints, or the detected languages while nothing
 * has been saved. The site language is always first. Mirrors the PHP
 * BitBot_Languages::effective_selection.
 */
export function effectiveSelection(
  saved: readonly string[] | undefined,
  detected: readonly string[],
  site: string,
  catalogue: readonly LanguageOption[],
): string[] {
  const source = saved && saved.length > 0 ? saved : detected
  return selectedLanguages(source, site, catalogue)
}

/**
 * The list the UI shows. The server's `offeredLanguages` (HabitatVoice.resolveOfferedLanguages)
 * is the authoritative rule and wins whenever it is present; effectiveSelection is ONLY the
 * pre-load/offline default, used until the status has arrived.
 */
export function listedSelection(
  offered: readonly string[] | undefined,
  saved: readonly string[] | undefined,
  detected: readonly string[],
  site: string,
  catalogue: readonly LanguageOption[],
): string[] {
  if (offered && offered.length > 0) return selectedLanguages(offered, site, catalogue)
  return effectiveSelection(saved, detected, site, catalogue)
}

/**
 * The chips to show: the detected languages first (site language first),
 * then any other selected language, in catalogue order.
 */
export function chipLanguages(
  catalogue: readonly LanguageOption[],
  detected: readonly string[],
  selected: readonly string[],
): LanguageOption[] {
  const byCode = new Map(catalogue.map((l) => [l.code, l]))
  const head: LanguageOption[] = []
  for (const code of detected) {
    const row = byCode.get(code)
    if (row && !head.includes(row)) head.push(row)
  }
  const tail = catalogue.filter((l) => selected.includes(l.code) && !head.includes(l))
  return [...head, ...tail]
}

/**
 * Catalogue languages matching a query by English name, native name or code
 * (case-insensitive), skipping the ones already selected. Names that start
 * with the query come first. Empty query gives no results.
 */
export function searchLanguages(
  query: string,
  catalogue: readonly LanguageOption[],
  selected: readonly string[],
  limit: number = MAX_SEARCH_RESULTS,
): LanguageOption[] {
  const q = query.trim().toLowerCase()
  if (!q) return []
  const hits = catalogue.filter(
    (l) =>
      !selected.includes(l.code) &&
      (l.code === q || l.name.toLowerCase().includes(q) || l.native.toLowerCase().includes(q)),
  )
  const rank = (l: LanguageOption): number =>
    l.code === q ? 0 : l.name.toLowerCase().startsWith(q) || l.native.toLowerCase().startsWith(q) ? 1 : 2
  return [...hits].sort((a, b) => rank(a) - rank(b)).slice(0, limit)
}
