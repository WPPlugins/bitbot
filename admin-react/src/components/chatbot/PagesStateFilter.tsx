/**
 * PagesStateFilter — On / Left out / Off / All pages, as a row of toggle
 * buttons with counts. "Left out" shows only when there is something left
 * out, and is highlighted when not selected. Renders nothing when the
 * backend sent no per-state counts (older backend).
 */
import type { PageSourceCounts, PageStateFilter } from '@/api/types'
import { stateFilterOptions } from '@/lib/pagesStatus'

interface Props {
  value: PageStateFilter
  counts: PageSourceCounts | undefined
  onChange: (next: PageStateFilter) => void
}

export function PagesStateFilter({ value, counts, onChange }: Props) {
  const options = stateFilterOptions(counts)
  if (options.length === 0) return null
  return (
    <div className="bb-pages-filters" role="group" aria-label="Filter by page state">
      {options.map((o) => {
        const active = value === o.key
        const cls = `bb-pages-filter${active ? ' bb-pages-filter-active' : ''}${o.warn && !active ? ' bb-pages-filter-warn' : ''}`
        return (
          <button key={o.key} type="button" className={cls} aria-pressed={active} onClick={() => onChange(o.key)}>
            {o.n === null ? o.label : `${o.label} (${o.n.toLocaleString()})`}
          </button>
        )
      })}
    </div>
  )
}
