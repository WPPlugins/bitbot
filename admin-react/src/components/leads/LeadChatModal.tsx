/**
 * LeadChatModal — opens from the LeadDetail header chip and shows the
 * full conversation transcript that ground-truthed this lead. Read-
 * only; the engagement primitive lives on the lead detail itself
 * (research + suggested next step + draft response), and the chat is
 * background context the admin drills into when they need it.
 */
import * as Dialog from '@radix-ui/react-dialog'
import type { ConversationMessage } from '@/api/types'
import { relativeTime } from '@/lib/utils'

interface LeadChatModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  visitorName: string
  messages: ConversationMessage[]
}

export function LeadChatModal({ open, onOpenChange, visitorName, messages }: LeadChatModalProps) {
  const root = document.getElementById('bitbot-admin-root') ?? undefined

  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal container={root}>
        <Dialog.Overlay className="bb-lead-chat-overlay" />
        <Dialog.Content className="bb-lead-chat-modal" aria-describedby={undefined}>
          <header className="bb-lead-chat-head">
            <Dialog.Title className="bb-lead-chat-title">
              Chat transcript
              <span className="bb-lead-chat-count">
                {messages.length} {messages.length === 1 ? 'message' : 'messages'}
              </span>
            </Dialog.Title>
            <Dialog.Close className="bb-lead-chat-close" aria-label="Close">✕</Dialog.Close>
          </header>

          <div className="bb-lead-chat-body">
            {messages.length === 0 ? (
              <p className="bb-field-help">No conversation linked to this lead.</p>
            ) : (
              <ol className="bb-transcript">
                {messages.map((m, i) => (
                  <li key={i} className={`bb-msg bb-msg-${m.role}`}>
                    <span className="bb-msg-role">{m.role === 'user' ? visitorName || 'Visitor' : 'Youami'}</span>
                    <p className="bb-msg-body">{m.content}</p>
                    <span className="bb-msg-time">{relativeTime(m.createdAt)}</span>
                  </li>
                ))}
              </ol>
            )}
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  )
}
