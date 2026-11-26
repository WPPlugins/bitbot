/**
 * Artefact drawer bodies.
 *  - ArtefactViewerBody: renders a document artefact's HTML in a sandboxed
 *    iframe + a Download (print-to-PDF) button. The iframe is sandboxed
 *    (allow-scripts allow-modals, NO allow-same-origin) so the agent-authored
 *    HTML can run charts but can't touch the WP session. Print is triggered by
 *    postMessage into an injected listener (works without same-origin).
 *  - ArtefactsListBody: the "Artefacts" submenu list — open / delete.
 */
import { useMemo, useRef, useState } from 'react'
import { useArtefact, useArtefacts, useDeleteArtefact } from '@/hooks/useArtefacts'
import { confirm } from '@/components/ui/ConfirmDialog'

export function ArtefactViewerBody({ artefactId }: { artefactId: string }) {
  const { data, isLoading, isError } = useArtefact(artefactId)
  const iframeRef = useRef<HTMLIFrameElement>(null)

  const print = () => iframeRef.current?.contentWindow?.postMessage('bb-print', '*')

  if (isLoading) return <div className="bb-art-empty">Loading document…</div>
  if (isError || !data) return <div className="bb-art-empty">Couldn’t load this document.</div>

  // Append a tiny print listener so the Download button (outside the sandboxed
  // iframe) can trigger the iframe's own window.print() → browser Save-as-PDF.
  const srcDoc = `${data.html}\n<script>window.addEventListener('message',function(e){if(e.data==='bb-print'){try{window.focus();window.print();}catch(_){}}});</script>`

  return (
    <div className="bb-art-viewer">
      <div className="bb-art-viewer-bar">
        <span className="bb-art-viewer-hint">Preview — print to save as PDF</span>
        <button type="button" className="bb-btn bb-btn-primary bb-btn-small" onClick={print}>
          Download PDF
        </button>
      </div>
      <iframe
        ref={iframeRef}
        className="bb-art-frame"
        title={data.title}
        srcDoc={srcDoc}
        sandbox="allow-scripts allow-modals"
      />
    </div>
  )
}

type ArtefactSort = 'newest' | 'oldest' | 'name'

const SORTS: { key: ArtefactSort; label: string }[] = [
  { key: 'newest', label: 'Newest' },
  { key: 'oldest', label: 'Oldest' },
  { key: 'name', label: 'A–Z' },
]

export function ArtefactsListBody({ onOpen }: { onOpen: (id: string) => void }) {
  const { data: items = [], isLoading } = useArtefacts()
  const del = useDeleteArtefact()
  const [query, setQuery] = useState('')
  const [sort, setSort] = useState<ArtefactSort>('newest')
  const [selected, setSelected] = useState<Set<string>>(new Set())

  const shown = useMemo(() => {
    const q = query.trim().toLowerCase()
    const filtered = q ? items.filter((a) => a.title.toLowerCase().includes(q) || a.kind.toLowerCase().includes(q)) : items
    return [...filtered].sort((a, b) =>
      sort === 'name' ? a.title.localeCompare(b.title)
      : sort === 'oldest' ? a.createdAt.localeCompare(b.createdAt)
      : b.createdAt.localeCompare(a.createdAt),
    )
  }, [items, query, sort])

  const toggle = (id: string) =>
    setSelected((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })

  const allShownSelected = shown.length > 0 && shown.every((a) => selected.has(a.id))

  const deleteSelected = async () => {
    const ids = shown.filter((a) => selected.has(a.id)).map((a) => a.id)
    if (ids.length === 0) return
    const ok = await confirm({
      title: `Delete ${ids.length} document${ids.length === 1 ? '' : 's'}?`,
      message: 'Removes the selected documents.',
      confirmLabel: 'Delete',
      danger: true,
    })
    if (!ok) return
    for (const id of ids) del.mutate(id)
    setSelected(new Set())
  }

  if (isLoading) return <div className="bb-art-empty">Loading…</div>
  if (items.length === 0) {
    return <div className="bb-art-empty">No documents yet. Ask the agent for a report, brief, or one-pager.</div>
  }
  return (
    <div className="bb-art-list">
      <div className="bb-art-tools">
        <input
          className="bb-art-search"
          type="search"
          placeholder="Search documents…"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
        />
        <div className="bb-art-sorts">
          {SORTS.map((s) => (
            <button
              key={s.key}
              type="button"
              className={`bb-art-sort ${sort === s.key ? 'is-on' : ''}`}
              onClick={() => setSort(s.key)}
            >
              {s.label}
            </button>
          ))}
        </div>
      </div>

      <div className="bb-art-bulk">
        <label className="bb-art-selectall">
          <input
            type="checkbox"
            checked={allShownSelected}
            onChange={() =>
              setSelected(allShownSelected ? new Set() : new Set(shown.map((a) => a.id)))
            }
          />
          <span>{selected.size > 0 ? `${selected.size} selected` : `${shown.length} of ${items.length}`}</span>
        </label>
        {selected.size > 0 && (
          <button type="button" className="bb-art-bulk-del" disabled={del.isPending} onClick={deleteSelected}>
            Delete selected
          </button>
        )}
      </div>

      {shown.length === 0 ? (
        <div className="bb-art-empty">Nothing matches “{query}”.</div>
      ) : (
        shown.map((a) => (
          <div className={`bb-art-item ${selected.has(a.id) ? 'is-selected' : ''}`} key={a.id}>
            <input
              type="checkbox"
              className="bb-art-item-check"
              checked={selected.has(a.id)}
              onChange={() => toggle(a.id)}
            />
            <button type="button" className="bb-art-item-main" onClick={() => onOpen(a.id)}>
              <span className="bb-art-item-title">{a.title}</span>
              <span className="bb-art-item-meta">
                {new Date(a.createdAt).toLocaleString(undefined, {
                  day: '2-digit', month: 'short', year: 'numeric',
                  hour: '2-digit', minute: '2-digit',
                })}
                {' · '}{a.kind}
                {(a.version ?? 1) > 1 && <> · <strong>v{a.version}</strong></>}
              </span>
            </button>
            <button
              type="button"
              className="bb-art-item-del"
              title="Delete document"
              disabled={del.isPending}
              onClick={async () => {
                const ok = await confirm({ title: `Delete “${a.title}”?`, message: 'Removes this document.', confirmLabel: 'Delete', danger: true })
                if (ok) del.mutate(a.id)
              }}
            >✕</button>
          </div>
        ))
      )}
    </div>
  )
}
