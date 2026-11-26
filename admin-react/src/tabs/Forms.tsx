/**
 * Forms tab — list of every form on this site. Each row is a slim
 * summary; clicking the row opens FormEditorModal where the actual
 * work happens (visual field editor, embed snippets, responses table).
 *
 * Top of the tab is the "Create a form" panel — AI generate or start
 * blank. Both append a new form to the list and immediately open it
 * in the editor.
 */
import { Fragment, useRef, useState } from 'react'
import type { FormInfo } from '@/api/types'
import {
  useForms,
  useDeleteForm,
  useToggleFormEnabled,
  useGenerateForm,
  useCreateForm,
  useSetChatGate,
  useFormIdeas,
} from '@/hooks/useForms'
import { usePlanStatus } from '@/hooks/usePlanStatus'
import { useAdminSettings } from '@/hooks/useAdminSettings'
import { gateCardModel, gateCopyFor } from '@/lib/prechatPreview'
import * as Dialog from '@radix-ui/react-dialog'
import { EmptyState } from '@/components/ui/EmptyState'
import { FormEditorModal } from '@/components/forms/FormEditorModal'
import { ChangePlanModal } from '@/components/billing/ChangePlanModal'
import { confirm } from '@/components/ui/ConfirmDialog'
import { relativeTime } from '@/lib/utils'

// Per-form localStorage seen-at marker — set when admin lands on this
// form's Responses sub-tab. Drives the per-form "new entries" indicator
// shown on the Forms list + the Responses sub-tab badge inside the modal.
export const formResponsesSeenKey = (formId: string) => `bb_form_responses_seen_at::${formId}`

function ResponseSummary({ form }: { form: FormInfo }) {
  // Server-side unviewed count — passive responses not yet opened. Same
  // signal as the top-level menu badge + Responses sub-tab, so the red dot
  // never disagrees with them (the old localStorage marker did). Clears when
  // the form's Responses tab is opened.
  const unviewed = form.unviewedCount ?? 0

  if (form.submissionsCount === 0) {
    return <span className="bb-form-row-summary">No responses yet</span>
  }
  const label = form.submissionsCount === 1 ? '1 response' : `${form.submissionsCount} responses`
  return (
    <span className="bb-form-row-summary">
      {label}
      {unviewed > 0 && (
        <span
          className="bb-form-row-newpill"
          aria-label={`${unviewed} unread ${unviewed === 1 ? 'response' : 'responses'}`}
          title={`${unviewed} response${unviewed === 1 ? '' : 's'} you haven't opened`}
        >
          {unviewed} new
        </span>
      )}
    </span>
  )
}

/**
 * Attention card shown while the chat gate (the one flag Chatbot > Widget
 * also reads) is OFF. Replaces the
 * slim disabled row with a value-forward pitch: it names the cost, shows the
 * exact fields, previews what the visitor sees, and turns the tiny toggle
 * into one obvious "Turn on the form" button. Once enabled it reverts to the
 * normal (calm) FormRow so it never nags.
 */
function PrechatOffCard({
  form,
  onEnable,
  onOpen,
  pending,
  error,
}: {
  form: FormInfo
  onEnable: () => void
  onOpen: (form: FormInfo) => void
  pending: boolean
  error: string | null
}) {
  const previewFields = form.fields.slice(0, 2)
  // The miniature shows the widget's own title and the form's fixed text in the site's language.
  const { settings } = useAdminSettings()
  const gate = gateCardModel(null, gateCopyFor(settings?.gateCopy, Object.keys(settings?.gateCopy ?? {})[0]))
  const chatTitle = settings?.widgetTitle || settings?.widgetDefaults?.title || ''

  return (
    <div className="bb-prechat-off">
      <div className="bb-prechat-off-content">
        <div className="bb-prechat-off-head">
          <span className="bb-prechat-off-pill"><span className="bb-prechat-off-dot" />Off</span>
          <span className="bb-prechat-off-name">Pre-chat form · {form.fields.length} fields</span>
        </div>

        <h3 className="bb-prechat-off-headline">You're letting visitors leave without a trace.</h3>
        <p className="bb-prechat-off-lede">
          Ask for details before the first chat reply.
        </p>

        <div className="bb-prechat-off-cta">
          <button className="bb-btn bb-btn-primary" onClick={onEnable} disabled={pending}>
            {pending ? 'Turning on…' : 'Ask before the first reply →'}
          </button>
          <button className="bb-link-btn" onClick={() => onOpen(form)}>
            Edit fields first
          </button>
        </div>
        {error && <p className="bb-form-row-error" role="alert">{error}</p>}
      </div>

      <div className="bb-prechat-off-preview" aria-hidden>
        <div className="bb-prechat-mini">
          <div className="bb-prechat-mini-bar">
            <span className="bb-prechat-mini-av" />
            {chatTitle}
          </div>
          <div className="bb-prechat-mini-body">
            <div className="bb-prechat-mini-formtitle">{gate.heading}</div>
            {previewFields.map((f) => (
              <div key={f.name} className="bb-prechat-mini-group">
                <span className="bb-prechat-mini-label">{f.label}{f.required ? ' *' : ''}</span>
                <div className="bb-prechat-mini-field">{f.placeholder || ''}</div>
              </div>
            ))}
            <div className="bb-prechat-mini-actions">
              <span className="bb-prechat-mini-submit">{gate.submitLabel}</span>
              <span className="bb-prechat-mini-cancel">{gate.backLabel}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

interface FormRowProps {
  form: FormInfo
  onOpen: (form: FormInfo) => void
  onDelete: (form: FormInfo) => void
  onToggle: (id: string, enabled: boolean) => void
  onToggleGate: (enabled: boolean) => void
  acceptPending: boolean
  gatePending: boolean
  acceptError: string | null
  gateError: string | null
}

function FormRow({ form, onOpen, onDelete, onToggle, onToggleGate, acceptPending, gatePending, acceptError, gateError }: FormRowProps) {
  // Clicks on the row open the modal; control surfaces (toggle, delete)
  // stop propagation so they don't also trigger an open.
  const stop = (fn: () => void) => (e: React.MouseEvent) => { e.stopPropagation(); fn() }

  return (
    <div
      className={`bb-form-row ${form.enabled ? '' : 'bb-form-row-disabled'}`}
      onClick={() => onOpen(form)}
      role="button"
      tabIndex={0}
      onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') onOpen(form) }}
    >
      <div className="bb-form-row-main">
        <div className="bb-form-row-title">
          <span>{form.name}</span>
          {form.isDefault && <span className="bb-form-row-badge">Default</span>}
          {!form.enabled && <span className="bb-form-row-badge bb-form-row-badge-off">Off</span>}
        </div>
        <div className="bb-form-row-meta">
          <ResponseSummary form={form} />
          <span>· {form.fields.length} fields</span>
          <span>· Created {relativeTime(form.createdAt)}</span>
        </div>
        <div className="bb-form-row-switches" onClick={(e) => e.stopPropagation()}>
          <label className="bb-form-row-switch">
            <span className="bb-form-toggle">
              <input
                type="checkbox"
                aria-label="Accept submissions"
                checked={form.enabled}
                disabled={acceptPending || (form.isDefault && form.chatGateEnabled && form.enabled)}
                onChange={(e) => onToggle(form.id, e.target.checked)}
              />
              <span className="bb-form-toggle-track"><span className="bb-form-toggle-thumb" /></span>
            </span>
            Accept submissions
          </label>
          {form.isDefault && form.chatGateEnabled && form.enabled && (
            <p className="bb-field-help" style={{ margin: 0 }}>Stays on while the form asks before the first reply.</p>
          )}
          {acceptError && <p className="bb-form-row-error" role="alert">{acceptError}</p>}
          {form.isDefault && (
            <>
              <label className="bb-form-row-switch">
                <span className="bb-form-toggle">
                  <input
                    type="checkbox"
                    aria-label="Ask before the first reply"
                    checked={form.chatGateEnabled}
                    disabled={gatePending}
                    onChange={(e) => onToggleGate(e.target.checked)}
                  />
                  <span className="bb-form-toggle-track"><span className="bb-form-toggle-thumb" /></span>
                </span>
                Ask before the first reply
              </label>
              {gateError && <p className="bb-form-row-error" role="alert">{gateError}</p>}
            </>
          )}
        </div>
      </div>

      <div className="bb-form-row-actions" onClick={(e) => e.stopPropagation()}>
        <button
          className="bb-btn bb-btn-ghost bb-btn-small"
          onClick={stop(() => onOpen(form))}
        >
          Open →
        </button>
        {!form.isDefault && (
          <button
            className="bb-link-btn bb-form-delete"
            onClick={stop(() => onDelete(form))}
          >Delete</button>
        )}
      </div>
    </div>
  )
}

// Plan-aware "Add a form" tile. Replaces the always-on Create panel. Click
// is wired by the parent: room on plan → opens the create modal; at the
// form cap → opens the standard upgrade modal (same gate as Leads / posts).
function AddFormTile({ atCap, onClick }: { atCap: boolean; onClick: () => void }) {
  return (
    <button type="button" className="bb-add-form-tile" onClick={onClick}>
      <span className="bb-add-form-plus" aria-hidden>
        {atCap ? (
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></svg>
        ) : '+'}
      </span>
      <span className="bb-add-form-text">
        <span className="bb-add-form-title">Add a form</span>
        <span className="bb-add-form-sub">
          {atCap
            ? "You're at your form limit — upgrade for unlimited forms + AI schemas."
            : 'Describe it and AI builds the fields, or start blank.'}
        </span>
      </span>
    </button>
  )
}

// Create-a-form flow, now in a modal opened by the AddFormTile (instead of
// the always-visible side panel). Describe → AI builds fields, or start
// blank; either way the new form materialises and opens in the editor.
function CreateFormModal({
  open,
  onClose,
  onCreated,
}: {
  open: boolean
  onClose: () => void
  onCreated: (form: FormInfo) => void
}) {
  const [description, setDescription] = useState('')
  const [promptError, setPromptError] = useState(false)
  const textareaRef = useRef<HTMLTextAreaElement>(null)
  const generate = useGenerateForm()
  const create = useCreateForm()
  const { data: ideas } = useFormIdeas(open)
  const root = document.getElementById('bitbot-admin-root') ?? undefined

  // Prefer a site-relevant, localized example (backend-generated from the
  // site's own content). Falls back to the neutral static example when the
  // site has nothing indexed yet or the ideas haven't loaded.
  const example = ideas?.[0]?.trim()
  const placeholder = example
    ? `What form do you want to generate? e.g. "${example}"`
    : 'What form do you want to generate? e.g. "Wedding venue tour booking — date, guest count, preferred time."'

  const done = (f: FormInfo) => { setDescription(''); setPromptError(false); onClose(); onCreated(f) }

  const startBlank = () => {
    create.mutate(
      {
        name: 'New form',
        description: '',
        fields: [
          { name: 'name',  label: 'Full name', type: 'text',  required: true,  placeholder: 'Your name' },
          { name: 'email', label: 'Email',     type: 'email', required: true,  placeholder: 'you@example.com' },
        ],
      },
      { onSuccess: done },
    )
  }

  const generateAndOpen = async () => {
    if (!description.trim()) {
      setPromptError(true)
      textareaRef.current?.focus()
      return
    }
    try {
      const gen = await generate.mutateAsync(description)
      create.mutate({ name: gen.name, description, fields: gen.fields }, { onSuccess: done })
    } catch {
      // toast already surfaced by the hook
    }
  }

  return (
    <Dialog.Root open={open} onOpenChange={(o) => !o && onClose()}>
      <Dialog.Portal container={root}>
        <Dialog.Overlay className="bb-form-modal-overlay" />
        <Dialog.Content className="bb-create-modal" aria-describedby={undefined}>
          <header className="bb-create-modal-head">
            <Dialog.Title className="bb-create-modal-title">Add a form</Dialog.Title>
            <Dialog.Close className="bb-form-modal-close" aria-label="Close">✕</Dialog.Close>
          </header>
          <div className="bb-create-modal-body">
            <textarea
              ref={textareaRef}
              className={`bb-input bb-textarea${promptError ? ' bb-textarea-error' : ''}`}
              rows={3}
              value={description}
              onChange={(e) => { setDescription(e.target.value); if (promptError) setPromptError(false) }}
              placeholder={placeholder}
              autoFocus
            />
            {promptError ? (
              <p className="bb-field-help bb-field-help-error">
                Tell the AI what form to build, or use Start blank for a Name + Email form.
              </p>
            ) : (
              <p className="bb-field-help">
                AI picks the right field types and marks required fields. Or skip the description and start with a blank Name + Email form.
              </p>
            )}
          </div>
          <footer className="bb-create-modal-foot">
            <button
              className="bb-btn bb-btn-primary"
              disabled={generate.isPending || create.isPending}
              onClick={generateAndOpen}
            >
              {generate.isPending ? 'Generating…' : create.isPending ? 'Creating…' : 'Generate with AI →'}
            </button>
            <button
              className="bb-btn bb-btn-ghost"
              disabled={generate.isPending || create.isPending}
              onClick={startBlank}
            >
              {create.isPending ? 'Creating…' : 'Start blank'}
            </button>
          </footer>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  )
}

/** Inline message for a failed mutation, or null while idle/ok. */
function errMsg(m: { isError: boolean; error: unknown }): string | null {
  if (!m.isError) return null
  return m.error instanceof Error ? m.error.message : 'That change did not save. Try again.'
}

export function Forms() {
  const { data: forms, isLoading } = useForms()
  const del = useDeleteForm()
  const toggle = useToggleFormEnabled()
  const chatGate = useSetChatGate()
  const { data: plan } = usePlanStatus()
  const [editingId, setEditingId] = useState<string | null>(null)
  const [createOpen, setCreateOpen] = useState(false)
  const [upgradeOpen, setUpgradeOpen] = useState(false)

  const editingForm = (forms ?? []).find((f) => f.id === editingId) ?? null

  // Plan cap: server-driven so dev / prod can shift independently. maxForms=0
  // means unlimited (paid plans). At the cap, "Add a form" opens the standard
  // upgrade modal instead of the create flow — same gate as Leads / posts.
  const formCount = (forms ?? []).length
  const maxForms  = plan?.limits.maxForms ?? 0
  const formsCapHit = maxForms > 0 && formCount >= maxForms

  const handleAddForm = () => {
    if (formsCapHit) setUpgradeOpen(true)
    else setCreateOpen(true)
  }

  return (
    <>

      <div className="bb-forms-main">
          {isLoading && <div className="bb-skeleton" style={{ height: 320, borderRadius: 12 }} />}
          {!isLoading && (forms ?? []).length === 0 && (
            <EmptyState
              headline="No forms yet"
              body="Add a form below. AI builds it from a one-line description, or start with a blank Name + Email form."
              ctaLabel="Add a form"
              onCta={handleAddForm}
            />
          )}
          {!isLoading && (forms ?? []).map((form) => (
            <Fragment key={form.id}>
              {form.isDefault && !form.chatGateEnabled && (
                <PrechatOffCard
                  form={form}
                  onEnable={() => chatGate.mutate(true)}
                  onOpen={(f) => setEditingId(f.id)}
                  pending={chatGate.isPending}
                  error={errMsg(chatGate)}
                />
              )}
              <FormRow
                form={form}
                onOpen={(f) => setEditingId(f.id)}
                onDelete={async (f) => {
                  const ok = await confirm({
                    title: `Delete "${f.name}"?`,
                    message: f.submissionsCount > 0
                      ? `The form stops accepting submissions and is removed from your site. Its ${f.submissionsCount} ${f.submissionsCount === 1 ? 'response goes' : 'responses go'} too. Export ${f.submissionsCount === 1 ? 'it' : 'them'} or add to Leads first to keep ${f.submissionsCount === 1 ? 'it' : 'them'}.`
                      : 'The form stops accepting submissions and is removed from your site. This cannot be undone.',
                    confirmLabel: 'Delete form',
                    danger: true,
                  })
                  if (ok) del.mutate(f.id)
                }}
                onToggle={(id, enabled) => toggle.mutate({ formId: id, enabled })}
                onToggleGate={(enabled) => chatGate.mutate(enabled)}
                acceptPending={toggle.isPending}
                gatePending={chatGate.isPending}
                acceptError={toggle.variables?.formId === form.id ? errMsg(toggle) : null}
                gateError={errMsg(chatGate)}
              />
            </Fragment>
          ))}
          {!isLoading && <AddFormTile atCap={formsCapHit} onClick={handleAddForm} />}
      </div>

      <FormEditorModal
        open={editingId !== null}
        form={editingForm}
        onClose={() => setEditingId(null)}
      />

      <CreateFormModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onCreated={(f) => setEditingId(f.id)}
      />

      <ChangePlanModal open={upgradeOpen} onClose={() => setUpgradeOpen(false)} />
    </>
  )
}
