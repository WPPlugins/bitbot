/**
 * "What Youami remembers" — the owner-facing view of the agent's standing
 * directives. Auto-captured rules (source=agent) and owner-added ones live
 * together; both are applied on every future run. Owner can add or prune.
 */
import { useState } from 'react'
import { useAgentNotes, useAddAgentNote, useDeleteAgentNote, type NoteCategory } from '@/hooks/useAgentNotes'
import { toast } from '@/components/ui/Toast'
import { confirm } from '@/components/ui/ConfirmDialog'

const CATEGORY_LABEL: Record<NoteCategory, string> = {
  voice: 'Voice',
  seo: 'SEO',
  content: 'Content',
  design: 'Design',
  publishing: 'Publishing',
  off_limits: 'Off-limits',
  general: 'General',
}

export function MemoryDrawerBody() {
  const { data: notes = [], isLoading } = useAgentNotes()
  const add = useAddAgentNote()
  const del = useDeleteAgentNote()
  const [draft, setDraft] = useState('')

  const onAdd = async () => {
    const note = draft.trim()
    if (!note) return
    try {
      await add.mutateAsync({ note })
      setDraft('')
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Could not save.')
    }
  }

  return (
    <div className="bb-mem">
      <p className="bb-mem-intro">
        Rules Youami applies to everything it does — including scheduled runs. It saves these when you
        state a standing rule or correct it. Add or remove any.
      </p>

      <div className="bb-mem-add">
        <textarea
          className="bb-mem-input"
          rows={2}
          placeholder="e.g. Always use British spelling. Never edit the pricing page."
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) onAdd()
          }}
        />
        <button
          type="button"
          className="bb-btn bb-btn-primary bb-btn-small"
          disabled={!draft.trim() || add.isPending}
          onClick={onAdd}
        >
          {add.isPending ? 'Saving…' : 'Add rule'}
        </button>
      </div>

      {isLoading ? (
        <div className="bb-mem-empty">Loading…</div>
      ) : notes.length === 0 ? (
        <div className="bb-mem-empty">
          Nothing remembered yet. Tell Youami a standing rule in chat, or add one above.
        </div>
      ) : (
        <ul className="bb-mem-list">
          {notes.map((n) => (
            <li className="bb-mem-item" key={n.id}>
              <div className="bb-mem-item-main">
                <span className="bb-mem-cat">{CATEGORY_LABEL[n.category] ?? n.category}</span>
                <span className="bb-mem-note">{n.note}</span>
              </div>
              <button
                type="button"
                className="bb-mem-del"
                title="Forget this rule"
                disabled={del.isPending}
                onClick={async () => {
                  const ok = await confirm({
                    title: 'Forget this rule?',
                    message: n.note,
                    confirmLabel: 'Forget',
                    danger: true,
                  })
                  if (ok) del.mutate(n.id)
                }}
              >✕</button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
