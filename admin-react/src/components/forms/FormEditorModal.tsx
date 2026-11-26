/**
 * FormEditorModal — the single screen where a form lives.
 *
 * Three tabs:
 *   - Build:     name input + field list (label, type, required, options,
 *                reorder, remove) + "Add field" + live preview pane
 *   - Embed:     [bitbot_form] shortcode for this WP site, plus an
 *                iframe snippet for embedding on any other site
 *   - Responses: flat table of this form's submissions, search +
 *                CSV export + mailto reply link. Each row shows an "Add
 *                to Leads" promote button for passive form submissions
 *                (shortcode/iframe). Chat-driven submissions are already
 *                in the Leads inbox via conversation_id.
 *
 * The default Pre-chat form is editable like any other form (admin can
 * rename, edit/add fields, reorder) and gets the same Responses table.
 */
import { useState, useEffect } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import * as Dialog from '@radix-ui/react-dialog'
import type { FormInfo, FormFieldSchema } from '@/api/types'
import { useUpdateForm } from '@/hooks/useForms'
import { BuildTab } from './BuildTab'
import { ResponsesTab } from './ResponsesTab'
import { normalizeFields, validateSchema } from '@/lib/formSchema'
import { toast } from '@/components/ui/Toast'
import { confirm } from '@/components/ui/ConfirmDialog'
import { rpc } from '@/api/rpc'
import { getConfig } from '@/lib/config'

type TabKey = 'build' | 'embed' | 'responses'

// Canonical default-form shape. Mirrored from the backend
// (Form.ts DEFAULT_FORM_FIELDS) so the Reset button on the Pre-chat
// form's modal can snap the fields back to factory in one click. Keep
// these in sync if the backend default changes.
const DEFAULT_PRECHAT_NAME = 'Pre-chat form'
const DEFAULT_PRECHAT_FIELDS: FormFieldSchema[] = [
  { name: 'name',  label: 'Full name', type: 'text',  required: true,  placeholder: 'Your name' },
  { name: 'email', label: 'Email',     type: 'email', required: true,  placeholder: 'you@example.com' },
  { name: 'phone', label: 'Phone',     type: 'phone', required: false, placeholder: '+1 (555) 000-0000' },
]

interface FormEditorModalProps {
  open: boolean
  form: FormInfo | null
  onClose: () => void
}

export function FormEditorModal({ open, form, onClose }: FormEditorModalProps) {
  const [tab, setTab] = useState<TabKey>('build')
  const [name, setName] = useState('')
  const [successMessage, setSuccessMessage] = useState('')
  const [fields, setFields] = useState<FormFieldSchema[]>([])
  const update = useUpdateForm()

  const qc = useQueryClient()

  // Re-seed local state every time the modal opens with a new form,
  // so closing-and-reopening always starts from server truth. Also
  // invalidate the forms list + this form's responses so the
  // "new submission" dot + the Responses tab pick up rows that came
  // in since the admin last loaded the page.
  // Reseed editable state + pick the initial tab ONCE per opened form,
  // keyed on the form id — NOT the whole form object. Keying on the object
  // would re-run on every background ['forms'] refetch, which (a) clobbers
  // unsaved edits and (b) flipped the tab back to Build the instant
  // MarkFormResponsesSeen dropped unviewedCount to 0. Reading unviewedCount
  // here captures the open-time value, so a form with unread responses opens
  // straight to Responses (where it clears); otherwise Build.
  useEffect(() => {
    if (!open || !form) return
    setName(form.name)
    setSuccessMessage(form.successMessage ?? '')
    setFields(form.fields.map((f) => ({ ...f })))
    setTab((form.unviewedCount ?? 0) > 0 ? 'responses' : 'build')
    qc.invalidateQueries({ queryKey: ['forms'] })
    qc.invalidateQueries({ queryKey: ['leads'] })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, form?.id])

  // Landing on the Responses sub-tab counts as viewing this form's responses:
  // stamp the server-side viewed_at on this form's passive responses, then
  // refresh the forms list + shared badge counts so the per-form red dot, the
  // Responses sub-tab badge, the nav tab badge, and the WP menu badge all
  // drop together.
  useEffect(() => {
    if (!form || tab !== 'responses') return
    void rpc('MarkFormResponsesSeen', { formId: form.id }).then(() => {
      qc.invalidateQueries({ queryKey: ['menu-badges'] })
      qc.invalidateQueries({ queryKey: ['forms'] })
    })
  }, [tab, form, qc])

  if (!form) return null

  const dirty = form.name !== name || (form.successMessage ?? '') !== successMessage || JSON.stringify(form.fields) !== JSON.stringify(fields)

  const root = document.getElementById('bitbot-admin-root') ?? undefined

  const problems = validateSchema(name, fields)

  const requestClose = async () => {
    if (update.isPending) return
    if (dirty) {
      const ok = await confirm({
        title: 'Discard your changes?',
        message: 'This form has edits you have not saved.',
        confirmLabel: 'Discard',
        danger: true,
      })
      if (!ok) return
    }
    onClose()
  }

  const handleSave = () => {
    if (problems.length > 0) return
    update.mutate(
      { formId: form.id, name: name.trim(), fields: normalizeFields(fields), successMessage: successMessage.trim() },
      { onSuccess: () => onClose() },
    )
  }

  return (
    <Dialog.Root open={open} onOpenChange={(o) => { if (!o) void requestClose() }}>
      <Dialog.Portal container={root}>
        <Dialog.Overlay className="bb-form-modal-overlay" />
        <Dialog.Content className="bb-form-modal" aria-describedby={undefined}>
          <header className="bb-form-modal-head">
            <div className="bb-form-modal-title-row">
              <input
                className="bb-form-modal-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Form name"
                disabled={tab !== 'build'}
              />
              {form.isDefault && <span className="bb-form-default-badge">Default</span>}
            </div>
            <Dialog.Close className="bb-form-modal-close" aria-label="Close">✕</Dialog.Close>
          </header>

          <FormModalTabs tab={tab} setTab={setTab} form={form} />

          <div className="bb-form-modal-body">
            {tab === 'build' && (
              <>
                <BuildTab fields={fields} onChange={setFields} problems={problems} />
                <label className="bb-form-success-msg">
                  <span>Message after submit</span>
                  <input
                    value={successMessage}
                    onChange={(e) => setSuccessMessage(e.target.value)}
                    placeholder="What the visitor sees once the form is sent"
                  />
                </label>
              </>
            )}
            {tab === 'embed' && <EmbedTab form={form} />}
            {tab === 'responses' && <ResponsesTab form={form} />}
          </div>

          {tab === 'build' && (
            <footer className="bb-form-modal-foot">
              <button
                className="bb-btn bb-btn-primary"
                disabled={!dirty || update.isPending || problems.length > 0}
                title={problems.length > 0 ? problems[0] : undefined}
                onClick={handleSave}
              >
                {update.isPending ? 'Saving…' : 'Save'}
              </button>
              <button
                className="bb-btn bb-btn-ghost"
                onClick={() => void requestClose()}
                disabled={update.isPending}
              >
                Cancel
              </button>
              {form.isDefault && (
                <button
                  className="bb-link-btn bb-form-modal-reset"
                  type="button"
                  onClick={async () => {
                    const ok = await confirm({
                      title: 'Reset the Pre-chat form?',
                      message: 'This restores the original Name + Email + Phone fields. Your unsaved changes will be replaced — click Save afterwards to persist.',
                      confirmLabel: 'Reset',
                    })
                    if (!ok) return
                    setName(DEFAULT_PRECHAT_NAME)
                    setFields(DEFAULT_PRECHAT_FIELDS.map((f) => ({ ...f })))
                  }}
                  disabled={update.isPending}
                >
                  Reset to defaults
                </button>
              )}
            </footer>
          )}
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  )
}

// ── Modal sub-tab strip with per-form "new responses" badge ─────────
//
// The Responses sub-tab gets a count badge when there are submissions
// newer than this admin's last-visit timestamp for THIS form. Mirrors
// the tab-strip badges pattern (Leads N / Conversations N / Forms N).
function FormModalTabs({
  tab,
  setTab,
  form,
}: {
  tab: TabKey
  setTab: (t: TabKey) => void
  form: FormInfo
}) {
  // Server-side unviewed count — same signal as the per-form red dot on the
  // list and the top-level menu badge, so they never disagree. Clears when
  // this sub-tab is opened (MarkFormResponsesSeen, in the parent).
  const newCount = form.unviewedCount ?? 0

  return (
    <nav className="bb-form-modal-tabs" role="tablist">
      <button
        role="tab"
        aria-selected={tab === 'build'}
        className={`bb-form-modal-tab ${tab === 'build' ? 'bb-form-modal-tab-active' : ''}`}
        onClick={() => setTab('build')}
      >Build</button>
      <button
        role="tab"
        aria-selected={tab === 'embed'}
        className={`bb-form-modal-tab ${tab === 'embed' ? 'bb-form-modal-tab-active' : ''}`}
        onClick={() => setTab('embed')}
      >Embed</button>
      <button
        role="tab"
        aria-selected={tab === 'responses'}
        className={`bb-form-modal-tab ${tab === 'responses' ? 'bb-form-modal-tab-active' : ''}`}
        onClick={() => setTab('responses')}
      >
        Responses
        {newCount > 0 && tab !== 'responses' && (
          <span className="bb-form-modal-tab-newdot" aria-label="new responses" title="New responses since you last looked" />
        )}
      </button>
    </nav>
  )
}

// ── Embed tab: shortcode + iframe ───────────────────────────────────

function EmbedTab({ form }: { form: FormInfo }) {
  const cfg = getConfig()
  const shortcode = `[bitbot_form id="${form.id}"]`
  const embedUrl = `${cfg.siteUrl.replace(/\/$/, '')}/?bitbot_form_embed=${form.id}`
  const iframeSnippet = `<iframe src="${embedUrl}" width="100%" height="500" style="border:none;"></iframe>`

  return (
    <div className="bb-form-embed">
      <section className="bb-form-embed-section">
        <h4 className="bb-form-embed-heading">On this WordPress site</h4>
        <p className="bb-field-help" style={{ marginTop: 0 }}>
          Paste this shortcode into any post, page, or block where you want the form.
        </p>
        <CodeBox value={shortcode} label="shortcode" />
      </section>

      <section className="bb-form-embed-section">
        <h4 className="bb-form-embed-heading">Anywhere else on the internet</h4>
        <p className="bb-field-help" style={{ marginTop: 0 }}>
          Drop this iframe snippet into any HTML page. Submissions land in this dashboard.
        </p>
        <CodeBox value={iframeSnippet} label="iframe snippet" />
        <p className="bb-form-embed-note">
          <a href={embedUrl} target="_blank" rel="noopener noreferrer">Preview the public form ↗</a>
        </p>
      </section>
    </div>
  )
}

function CodeBox({ value, label }: { value: string; label: string }) {
  const [copied, setCopied] = useState(false)
  const onCopy = () => {
    navigator.clipboard?.writeText(value).then(
      () => { setCopied(true); setTimeout(() => setCopied(false), 2000) },
      () => toast.error('Copy failed'),
    )
  }
  return (
    <div className="bb-form-codebox">
      <code className="bb-form-codebox-code">{value}</code>
      <button className="bb-btn bb-btn-ghost bb-btn-small" onClick={onCopy} aria-label={`Copy ${label}`}>
        {copied ? 'Copied ✓' : 'Copy'}
      </button>
    </div>
  )
}

