/**
 * SwapPageDialog — "Use {title} instead". The owner picks ONE page that is On
 * to switch off; the left-out page takes its slot. The old page stays On until
 * the new one is stored (the backend swaps on ResyncPage with replacePageId).
 */
import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useQuery, keepPreviousData } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'
import { getConfig } from '@/lib/config'
import type { PageInfo } from '@/api/types'

const OPTION_PAGE_SIZE = 50
const DEV_MOCK_KEY = 'bb_devmockapikey0000000000000000'

interface Props {
  page: PageInfo
  /** Dev mock pages that are On, used offline. */
  devPages: PageInfo[]
  submitting: boolean
  onCancel: () => void
  onConfirm: (replacePageId: string) => void
}

export function SwapPageDialog({ page, devPages, submitting, onCancel, onConfirm }: Props) {
  const isDev = getConfig().apiKey === DEV_MOCK_KEY
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [choice, setChoice] = useState('')
  const selectRef = useRef<HTMLSelectElement>(null)

  useEffect(() => {
    const t = setTimeout(() => setDebounced(search), 300)
    return () => clearTimeout(t)
  }, [search])

  useEffect(() => { selectRef.current?.focus() }, [])
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape' && !submitting) onCancel() }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onCancel, submitting])

  const { data, isLoading } = useQuery({
    queryKey: ['indexed-pages', 'swap-options', debounced],
    queryFn: async (): Promise<{ pages: PageInfo[]; total: number }> => {
      if (isDev) {
        const q = debounced.toLowerCase()
        const rows = devPages.filter((p) => !q || `${p.title ?? ''} ${p.url}`.toLowerCase().includes(q))
        return { pages: rows, total: rows.length }
      }
      const res = await rpc<{ pages: PageInfo[]; total: number }>('GetPages', {
        page: 1,
        pageSize: OPTION_PAGE_SIZE,
        state: 'on',
        search: debounced || undefined,
      })
      if (!res.success) throw new Error(res.error)
      return { pages: res.data.pages, total: res.data.total }
    },
    placeholderData: keepPreviousData,
    staleTime: 30 * 1000,
  })

  const options = (data?.pages ?? []).filter((p) => p.id !== page.id)
  const total = data?.total ?? 0
  const name = page.title || page.url

  return createPortal(
    <div
      className="bb-portal-scope bb-avatar-crop-modal-overlay"
      onClick={(e) => { if (e.target === e.currentTarget && !submitting) onCancel() }}
      role="dialog"
      aria-modal="true"
      aria-label={`Use ${name} instead`}
    >
      <div className="bb-add-urls-modal bb-swap-modal">
        <header className="bb-avatar-crop-head">
          <h3>Use {name} instead</h3>
          <p>Choose one page to switch off. Its indexed copy stays saved.</p>
        </header>

        {total > OPTION_PAGE_SIZE && (
          <input
            type="search"
            className="bb-input"
            placeholder="Filter by URL or title"
            aria-label="Filter the pages that are On"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            disabled={submitting}
          />
        )}

        <select
          ref={selectRef}
          className="bb-input"
          aria-label="Page to switch off"
          value={choice}
          onChange={(e) => setChoice(e.target.value)}
          disabled={submitting || isLoading}
        >
          <option value="">{isLoading ? 'Loading pages…' : 'Choose a page'}</option>
          {options.map((p) => (
            <option key={p.id} value={p.id}>{(p.title || p.url).slice(0, 80)}</option>
          ))}
        </select>
        {total > OPTION_PAGE_SIZE && (
          <p className="bb-field-help">Showing the first {OPTION_PAGE_SIZE} of {total.toLocaleString()}. Filter to find the page.</p>
        )}
        <p className="bb-field-help">The current page stays on until the new page is ready.</p>

        <footer className="bb-avatar-crop-actions">
          <button type="button" className="bb-btn bb-btn-ghost" onClick={onCancel} disabled={submitting}>Cancel</button>
          <button
            type="button"
            className="bb-btn bb-btn-primary"
            onClick={() => onConfirm(choice)}
            disabled={submitting || !choice}
          >
            {submitting ? 'Swapping…' : 'Swap pages'}
          </button>
        </footer>
      </div>
    </div>,
    document.body,
  )
}
