/**
 * Pure helpers for a form's Responses tab: row keys, search, CSV building
 * and the full-set page walk. No React, no fetch (the pager takes a loader).
 */
export interface ResponseRow {
  id: string
  submissionId?: string
  name: string
  email: string
  phone?: string | null
  message?: string | null
  customFields?: Record<string, string> | null
  createdAt: string
}

export interface ResponseColumn {
  name: string
  label: string
}

export const RESPONSES_PAGE_SIZE = 200
export const ANSWERS_LOCKED_LABEL = 'Assistant feature'
const STANDARD_KEYS = new Set(['name', 'email', 'phone', 'message'])

/** One row per submission; a repeat email shares its lead id, so prefer the submission id. */
export function responseKey(r: { id: string; submissionId?: string }): string {
  return r.submissionId ?? r.id
}

/** Form fields beyond the four lead columns, in form order, de-duplicated. */
export function answerColumns(fields: ReadonlyArray<{ name: string; label: string }>): ResponseColumn[] {
  const seen = new Set<string>()
  const out: ResponseColumn[] = []
  for (const f of fields) {
    if (STANDARD_KEYS.has(f.name) || seen.has(f.name)) continue
    seen.add(f.name)
    out.push({ name: f.name, label: f.label || f.name })
  }
  return out
}

/** Search across identity, message and every answer. Empty query matches all. */
export function matchesSearch(r: ResponseRow, query: string): boolean {
  const q = query.trim().toLowerCase()
  if (!q) return true
  const hay = [r.name, r.email, r.phone ?? '', r.message ?? '', ...Object.values(r.customFields ?? {})]
  return hay.some((v) => String(v).toLowerCase().includes(q))
}

/** Quote a CSV cell; neutralise spreadsheet formulas (= @ and +/- before a letter). */
export function csvCell(value: unknown): string {
  let v = String(value ?? '').replace(/\s+/g, ' ')
  if (/^(?:[=@]|[+-][A-Za-z])/.test(v)) v = `'${v}`
  return `"${v.replace(/"/g, '""')}"`
}

/** CSV text for the given rows. `answersLocked` writes the Assistant label in answer columns. */
export function buildResponsesCsv(
  rows: ReadonlyArray<ResponseRow>,
  columns: ReadonlyArray<ResponseColumn>,
  answersLocked: boolean,
): string {
  const head = ['date', 'name', 'email', 'phone', 'message', ...columns.map((c) => c.label)]
  const lines = [head.map(csvCell).join(',')]
  for (const r of rows) {
    lines.push([
      new Date(r.createdAt).toISOString(),
      r.name,
      r.email,
      r.phone ?? '',
      r.message ?? '',
      ...columns.map((c) => (answersLocked ? ANSWERS_LOCKED_LABEL : (r.customFields?.[c.name] ?? ''))),
    ].map(csvCell).join(','))
  }
  return lines.join('\n')
}

export interface ResponsePage {
  leads: ResponseRow[]
  total: number
}

/** Walk every page (size RESPONSES_PAGE_SIZE) until total is reached; stops on an empty page. */
export async function fetchAllPages(load: (page: number) => Promise<ResponsePage>): Promise<ResponseRow[]> {
  const all: ResponseRow[] = []
  for (let page = 1; ; page++) {
    const res = await load(page)
    all.push(...res.leads)
    if (res.leads.length === 0 || all.length >= res.total) return all
  }
}
