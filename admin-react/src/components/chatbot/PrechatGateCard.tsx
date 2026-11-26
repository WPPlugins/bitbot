/**
 * Settings card: ask visitors for details before the first reply (the
 * pre-chat form gate). The state is the default form's chatGateEnabled flag
 * (GetForms), the one source the Forms tab reads too. Writes go through
 * useSetChatGate, never the form's own enabled switch. The title carries the
 * switch; under it the field summary and an Edit link that opens the form
 * editor right here, so the owner never leaves the Widget settings.
 */
import { useState } from 'react'
import { Card } from '@/components/ui/Card'
import { FormEditorModal } from '@/components/forms/FormEditorModal'
import { useForms, useSetChatGate } from '@/hooks/useForms'
import { prechatSummary } from '@/lib/prechatPreview'

const TITLE = 'Ask for details before the first reply'

export function PrechatGateCard() {
  const { data: forms } = useForms()
  const setGate = useSetChatGate()
  const [editing, setEditing] = useState(false)
  const form = forms?.find((f) => f.isDefault) ?? null
  const on = !!form?.chatGateEnabled
  const summary = form ? prechatSummary(form.fields) : ''

  const toggle = (
    <div className="bb-prechat-head-controls">
      <span className={`bb-state-pill bb-state-pill--${on ? 'on' : 'off'}`}>{on ? 'On' : 'Off'}</span>
      <label className="bb-form-toggle">
        <input
          type="checkbox"
          aria-label={TITLE}
          checked={on}
          disabled={!form || setGate.isPending}
          onChange={(e) => setGate.mutate(e.target.checked)}
        />
        <span className="bb-form-toggle-track"><span className="bb-form-toggle-thumb" /></span>
      </label>
    </div>
  )

  return (
    <Card title={TITLE} titleAction={toggle}>
      {summary && <p className="bb-prechat-summary">{summary}</p>}
      <button type="button" className="bb-link-btn" onClick={() => setEditing(true)} disabled={!form}>
        Edit the form →
      </button>
      {setGate.isError && (
        <p className="bb-prechat-error" role="alert">
          {setGate.error instanceof Error ? setGate.error.message : 'Could not change the pre-chat form.'}
        </p>
      )}
      <FormEditorModal open={editing} form={form} onClose={() => setEditing(false)} />
    </Card>
  )
}
