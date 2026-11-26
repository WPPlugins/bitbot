/**
 * LeadDetail — right column of the Leads tab. Three jobs only:
 *
 *   1. Identify the lead — name, email, phone, where it came from.
 *   2. AI research — who is this lead + suggested next step.
 *   3. Draft a follow-up reply.
 *
 * The chat transcript that triggered the capture is BACKGROUND
 * context, not engagement surface. Lives behind a "View chat" chip
 * in the header → opens LeadChatModal. Keeps the detail panel
 * focused on what the admin is here to do: act on the lead.
 */
import { useState } from 'react'
import type { LeadInfo } from '@/api/types'
import { useLead, useRunLeadResearch, useDraftLeadEmail } from '@/hooks/useLeads'
import { useZohoIntegration, usePushLeadToZoho, useZohoLeadState } from '@/hooks/useZoho'
import { usePipedriveStatus, usePushLeadToPipedrive, usePipedriveLeadState } from '@/hooks/usePipedrive'
import { Card } from '@/components/ui/Card'
import { useCurrentPlan } from '@/hooks/useCurrentPlan'
import { ProGate } from '@/components/ui/ProGate'
import { isPaid } from '@/lib/config'
import { LeadChatModal } from './LeadChatModal'
import { LeadSubmissionModal } from './LeadSubmissionModal'
import { relativeTime, formatLeadSource, formatLeadChannel } from '@/lib/utils'

interface LeadDetailProps {
  leadId: string | null
  onClose: () => void
}

export function LeadDetail({ leadId, onClose }: LeadDetailProps) {
  const { data, isLoading } = useLead(leadId)
  const canAct = isPaid(useCurrentPlan())
  const runResearch = useRunLeadResearch()
  const draftEmail  = useDraftLeadEmail()
  const zohoStatus  = useZohoIntegration()
  const pushToZoho  = usePushLeadToZoho()
  const zohoConnected = zohoStatus.data?.integration?.status === 'connected'
  // Live Zoho state — only fetches when this lead has actually been
  // synced. The hook short-circuits via `enabled` for un-synced leads
  // so we never burn a Zoho API call on rows that have nothing to read.
  const zohoSynced = !!data?.lead?.zohoLeadId
  const zohoState = useZohoLeadState(leadId, zohoConnected && zohoSynced)
  // Pipedrive — same shape: a chip to push, a link once synced.
  const pipedriveStatus = usePipedriveStatus()
  const pushToPipedrive = usePushLeadToPipedrive()
  const pipedriveConnected = !!pipedriveStatus.data?.configured
  const pipedriveSynced = !!data?.lead?.pipedrivePersonId
  const pipedriveState = usePipedriveLeadState(leadId, pipedriveConnected && pipedriveSynced)
  const [draft, setDraft] = useState<{ subject: string; body: string } | null>(null)
  const [chatOpen, setChatOpen] = useState(false)
  const [submissionOpen, setSubmissionOpen] = useState(false)

  if (!leadId) {
    return (
      <aside className="bb-lead-detail bb-lead-detail-empty">
        <div className="bb-lead-detail-placeholder">
          <span className="bb-eyebrow">No lead selected</span>
          <h3 className="bb-lead-placeholder-headline">Pick a lead to see the full thread.</h3>
          <p className="bb-lead-placeholder-body">
            The detail panel shows AI research, the chat transcript, and lets you draft a follow-up email.
          </p>
        </div>
      </aside>
    )
  }

  if (isLoading || !data) {
    return (
      <aside className="bb-lead-detail">
        <div className="bb-skeleton" style={{ height: 480, margin: 20, borderRadius: 8 }} />
      </aside>
    )
  }

  const { lead, conversationMessages } = data
  // Free: identity stays in the header; chat, submission, research and follow-up sit
  // under ONE Assistant gate (the server withholds the content, the gate explains why).
  const freeLocked = !!data.freeLocked
  const chatChip = (conversationMessages.length > 0 || freeLocked) && (
    <button
      type="button"
      className="bb-lead-detail-chat-chip"
      onClick={() => setChatOpen(true)}
    >
      {freeLocked ? 'View chat' : `View chat (${conversationMessages.length})`}
    </button>
  )
  const submissionChip = (lead.formId || Object.keys(lead.customFields ?? {}).length > 0 || freeLocked) && (
    <button
      type="button"
      className="bb-lead-detail-chat-chip"
      onClick={() => setSubmissionOpen(true)}
    >
      View submission
    </button>
  )

  return (
    <aside className="bb-lead-detail">
      {/* Header */}
      <header className="bb-lead-detail-header">
        <div>
          <div className="bb-lead-detail-name">{lead.name || '(no name)'}</div>
          <div className="bb-lead-detail-email">
            <a href={`mailto:${lead.email}`}>{lead.email}</a>
            {lead.phone && <> · <span>{lead.phone}</span></>}
          </div>
          <div className="bb-lead-detail-sub">
            {(() => {
              const ch = formatLeadChannel(lead.channel)
              return lead.channel && lead.channel !== 'web_widget' ? (
                <span title={`Captured via ${ch.label}`}>{ch.icon} {ch.label} ·</span>
              ) : null
            })()}
            <span>via {formatLeadSource(lead.source)}</span>
            {lead.city && <span>· {lead.city}, {lead.country}</span>}
            <span>· {relativeTime(lead.createdAt)}</span>
            {!freeLocked && chatChip}
            {!freeLocked && submissionChip}
          </div>
        </div>
        <button className="bb-lead-close" onClick={onClose} aria-label="Close lead">✕</button>
      </header>

      {/* CRM status — one compact row per connected CRM. The CRM name IS the
         source; each row owns its single "Open ↗" (no duplicate chips). Live
         stage reads on open (30s staleTime), so it reflects the CRM directly.
         Un-synced rows become the push action + tell you it's not there yet. */}
      {canAct && (zohoConnected || pipedriveConnected) && (
        <div className="bb-crm-status">
          {zohoConnected && (
            <CrmStatusRow
              name="Zoho"
              synced={zohoSynced}
              loading={zohoState.isLoading}
              error={zohoState.isError}
              stage={zohoState.data?.stage ?? null}
              modifiedAt={zohoState.data?.modifiedAt ?? null}
              openUrl={zohoState.data?.zohoUrl ?? null}
              pushing={pushToZoho.isPending}
              onPush={() => pushToZoho.mutate(lead.id)}
            />
          )}
          {pipedriveConnected && (
            <CrmStatusRow
              name="Pipedrive"
              synced={pipedriveSynced}
              loading={pipedriveState.isLoading}
              error={pipedriveState.isError}
              stage={pipedriveState.data?.stage ?? null}
              noStageLabel="no deal"
              modifiedAt={pipedriveState.data?.modifiedAt ?? null}
              openUrl={pipedriveState.data?.pipedriveUrl ?? null}
              pushing={pushToPipedrive.isPending}
              onPush={() => pushToPipedrive.mutate(lead.id)}
            />
          )}
        </div>
      )}

      {/* Research and follow-up are Assistant features: ONE gate box over both cards,
          and on Free the chat and submission chips sit inside it too. */}
      <ProGate requiredTier="assistant" feature={freeLocked ? 'Chat transcript, form submission, research and follow-up' : 'Lead research with follow-up email'}>
        <>
      {freeLocked && (
        <div className="bb-lead-detail-sub bb-lead-detail-locked-chips">
          {chatChip}
          {submissionChip}
        </div>
      )}
      {/* AI research */}
      <Card eyebrow="AI research" title="Who is this lead?">
        {lead.researchSummary ? (
          <>
            <p className="bb-lead-research-summary">{lead.researchSummary}</p>
            {lead.suggestedAction && (
              <div className="bb-lead-research-action">
                <span className="bb-eyebrow">Suggested next step</span>
                <p>{lead.suggestedAction}</p>
              </div>
            )}
            <button
              className="bb-btn bb-btn-ghost bb-btn-small"
              onClick={() => runResearch.mutate({ leadId: lead.id })}
              disabled={runResearch.isPending}
            >
              {runResearch.isPending ? 'Re-researching…' : 'Refresh research'}
            </button>
          </>
        ) : (
          <div className="bb-lead-research-empty">
            <p>No research yet. The AI can scan public sources, summarise the lead's profile, and suggest a follow-up.</p>
            <button
              className="bb-btn bb-btn-primary"
              onClick={() => runResearch.mutate({ leadId: lead.id })}
              disabled={runResearch.isPending}
            >
              {runResearch.isPending ? 'Researching…' : 'Run AI research'}
            </button>
          </div>
        )}
      </Card>

      {/* Email composer */}
      <Card
        eyebrow="Follow-up email"
        title={draft ? 'Edit draft' : 'Send a follow-up'}
        titleAction={
          !draft && (
            <button
              className="bb-btn bb-btn-ghost bb-btn-small"
              onClick={() =>
                draftEmail.mutate({ leadId: lead.id }, { onSuccess: setDraft })
              }
              disabled={draftEmail.isPending}
            >
              {draftEmail.isPending ? 'Drafting…' : 'Draft with AI'}
            </button>
          )
        }
      >
        {draft ? (
          <div className="bb-lead-draft">
            <input
              className="bb-input"
              value={draft.subject}
              onChange={(e) => setDraft({ ...draft, subject: e.target.value })}
              placeholder="Subject"
            />
            <textarea
              className="bb-input bb-textarea"
              rows={8}
              value={draft.body}
              onChange={(e) => setDraft({ ...draft, body: e.target.value })}
            />
            <div className="bb-lead-draft-actions">
              <button className="bb-btn bb-btn-ghost bb-btn-small" onClick={() => setDraft(null)}>
                Cancel
              </button>
              <a
                className="bb-btn bb-btn-primary bb-btn-small"
                href={`mailto:${lead.email}?subject=${encodeURIComponent(draft.subject)}&body=${encodeURIComponent(draft.body)}`}
              >
                Open in mail client
              </a>
            </div>
          </div>
        ) : (
          <p className="bb-lead-draft-hint">
            Generate a personalised follow-up using the AI research and the chat transcript. You can edit before sending.
          </p>
        )}
      </Card>
        </>
      </ProGate>

      <LeadChatModal
        open={chatOpen}
        onOpenChange={setChatOpen}
        visitorName={lead.name || 'Visitor'}
        messages={conversationMessages.slice()}
      />
      <LeadSubmissionModal
        open={submissionOpen}
        onOpenChange={setSubmissionOpen}
        lead={lead}
      />
    </aside>
  )
}

// Suppress unused-import warning on LeadInfo (used implicitly via hook types)
export type { LeadInfo }

interface CrmStatusRowProps {
  /** CRM name — doubles as the source label, e.g. "Zoho" / "Pipedrive". */
  name: string
  synced: boolean
  loading: boolean
  error: boolean
  stage: string | null
  /** Label when synced but no stage/deal exists. Defaults to "not set". */
  noStageLabel?: string
  modifiedAt: string | null
  openUrl: string | null
  pushing: boolean
  onPush: () => void
}

/** One compact CRM status line: "✓ Zoho · stage: not set · 22h   Open ↗".
 *  Un-synced → the push action + a clear "not synced" so you know why it's
 *  not there. The open link lives ONLY here (one per CRM) — no header chips. */
function CrmStatusRow({
  name, synced, loading, error, stage, noStageLabel = 'not set', modifiedAt, openUrl, pushing, onPush,
}: CrmStatusRowProps) {
  return (
    <div className="bb-crm-row">
      <span className={synced ? 'bb-crm-name is-synced' : 'bb-crm-name'}>
        {synced ? `✓ ${name}` : name}
      </span>
      {!synced ? (
        <>
          <span className="bb-crm-muted">not synced</span>
          <button type="button" className="bb-crm-action" onClick={onPush} disabled={pushing}>
            {pushing ? 'Pushing…' : 'Push ↗'}
          </button>
        </>
      ) : loading ? (
        <span className="bb-crm-muted">checking…</span>
      ) : error ? (
        <span className="bb-crm-muted">couldn’t reach {name}</span>
      ) : (
        <>
          <span className="bb-crm-detail">stage: {stage ?? noStageLabel}</span>
          {modifiedAt && <span className="bb-crm-muted">· {relativeTime(modifiedAt)}</span>}
          {openUrl && (
            <a className="bb-crm-action" href={openUrl} target="_blank" rel="noopener noreferrer">
              Open ↗
            </a>
          )}
        </>
      )}
    </div>
  )
}
