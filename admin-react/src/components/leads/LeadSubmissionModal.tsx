/**
 * LeadSubmissionModal — opens from the LeadDetail header chip ("View
 * submission") and shows the form submission that captured this lead:
 * the standard fields plus every custom field answer. The form-response
 * equivalent of LeadChatModal — read-only background context.
 */
import * as Dialog from '@radix-ui/react-dialog'
import type { LeadInfo } from '@/api/types'
import { relativeTime } from '@/lib/utils'

interface LeadSubmissionModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  lead: LeadInfo
}

// Field-name slugs ("which-event-date") → readable labels. The form's own
// labels would be richer, but the form may have been deleted (responses
// outlive it), so derive a clean label from the key itself.
function prettyKey(key: string): string {
  const s = key.replace(/[-_]+/g, ' ').trim()
  return s.charAt(0).toUpperCase() + s.slice(1)
}

export function LeadSubmissionModal({ open, onOpenChange, lead }: LeadSubmissionModalProps) {
  const root = document.getElementById('bitbot-admin-root') ?? undefined
  const custom = Object.entries(lead.customFields ?? {})

  // Standard fields first (in submission order), then the custom answers.
  const standard: Array<[string, string]> = [
    ['Name', lead.name || ''],
    ['Email', lead.email || ''],
    ...(lead.phone ? [['Phone', lead.phone] as [string, string]] : []),
    ...(lead.message ? [['Message', lead.message] as [string, string]] : []),
  ]

  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal container={root}>
        <Dialog.Overlay className="bb-lead-chat-overlay" />
        <Dialog.Content className="bb-lead-chat-modal" aria-describedby={undefined}>
          <header className="bb-lead-chat-head">
            <Dialog.Title className="bb-lead-chat-title">
              Form submission
              <span className="bb-lead-chat-count">{relativeTime(lead.createdAt)}</span>
            </Dialog.Title>
            <Dialog.Close className="bb-lead-chat-close" aria-label="Close">✕</Dialog.Close>
          </header>

          <div className="bb-lead-chat-body">
            <dl className="bb-lead-submission">
              {standard.map(([label, value]) => (
                <div key={label} className="bb-lead-submission-row">
                  <dt>{label}</dt>
                  <dd>{value || '—'}</dd>
                </div>
              ))}
              {custom.map(([key, value]) => (
                <div key={key} className="bb-lead-submission-row">
                  <dt>{prettyKey(key)}</dt>
                  <dd>{value || '—'}</dd>
                </div>
              ))}
            </dl>
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  )
}
