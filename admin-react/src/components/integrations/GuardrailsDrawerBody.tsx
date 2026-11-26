/**
 * Guardrails — the site's hard compliance constraints. Auto-populated by the
 * agent from LIVE sources (each rule carries the terms/policy URL it was
 * distilled from and when it was checked); owner can add or retire. Every
 * outward agent action (publishing, emails, page/post changes) is checked
 * against these before it executes — violations are rejected.
 */
import { useState } from 'react'
import {
  useGuardrails,
  useAddGuardrail,
  useDeleteGuardrail,
  type GuardrailCategory,
} from '@/hooks/useGuardrails'
import { toast } from '@/components/ui/Toast'
import { confirm } from '@/components/ui/ConfirmDialog'

const CATEGORY_LABEL: Record<GuardrailCategory, string> = {
  affiliate: 'Affiliate',
  legal: 'Legal',
  platform: 'Platform',
  brand: 'Brand',
  general: 'General',
}

export function GuardrailsDrawerBody() {
  const { data: guardrails = [], isLoading } = useGuardrails()
  const add = useAddGuardrail()
  const del = useDeleteGuardrail()
  const [rule, setRule] = useState('')
  const [sourceUrl, setSourceUrl] = useState('')

  const onAdd = async () => {
    const text = rule.trim()
    if (!text) return
    try {
      await add.mutateAsync({ rule: text, sourceUrl: sourceUrl.trim() || undefined })
      setRule('')
      setSourceUrl('')
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Could not save.')
    }
  }

  return (
    <div className="bb-mem">
      <p className="bb-mem-intro">
        Hard rules Youami must not cross — sourced from live program terms and policies. Every
        outward action (publishing, emails, site changes) is checked against these before it
        happens; violations are blocked. Youami adds them when it takes on regulated work.
      </p>

      <div className="bb-mem-add">
        <textarea
          className="bb-mem-input"
          rows={2}
          placeholder="e.g. Never place affiliate-tagged links in emails."
          value={rule}
          onChange={(e) => setRule(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) onAdd()
          }}
        />
        <input
          className="bb-mem-input"
          type="url"
          placeholder="Source URL (the terms/policy page this comes from)"
          value={sourceUrl}
          onChange={(e) => setSourceUrl(e.target.value)}
        />
        <button
          type="button"
          className="bb-btn bb-btn-primary bb-btn-small"
          disabled={!rule.trim() || add.isPending}
          onClick={onAdd}
        >
          {add.isPending ? 'Saving…' : 'Add guardrail'}
        </button>
      </div>

      {isLoading ? (
        <div className="bb-mem-empty">Loading…</div>
      ) : guardrails.length === 0 ? (
        <div className="bb-mem-empty">
          No guardrails yet. Youami adds them automatically when it starts work governed by
          program terms (like affiliate content), or add one above.
        </div>
      ) : (
        <ul className="bb-mem-list">
          {guardrails.map((g) => (
            <li className="bb-mem-item" key={g.id}>
              <div className="bb-mem-item-main">
                <span className="bb-mem-cat">
                  {g.program || CATEGORY_LABEL[g.category] || g.category}
                </span>
                <span className="bb-mem-note">
                  {g.rule}
                  {(g.source_url || g.checked_at) && (
                    <span className="bb-guard-src">
                      {g.source_url && (
                        <a href={g.source_url} target="_blank" rel="noreferrer noopener">
                          source ↗
                        </a>
                      )}
                      {g.checked_at && (
                        <span className="bb-guard-checked">
                          checked {new Date(g.checked_at).toLocaleDateString()}
                        </span>
                      )}
                    </span>
                  )}
                </span>
              </div>
              <button
                type="button"
                className="bb-mem-del"
                title="Retire this guardrail"
                disabled={del.isPending}
                onClick={async () => {
                  const ok = await confirm({
                    title: 'Retire this guardrail?',
                    message: `${g.rule}\n\nOutward actions will no longer be checked against it.`,
                    confirmLabel: 'Retire',
                    danger: true,
                  })
                  if (ok) del.mutate(g.id)
                }}
              >✕</button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
