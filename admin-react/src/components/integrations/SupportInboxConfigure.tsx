/**
 * AI Support Inbox configure pane (Agent / Pro+). The inbox address + copy up
 * top, a single auto-send toggle, then the three things it unlocks. The address
 * IS the support inbox — it receives directly and the agent replies from it
 * (Forward Email outbound), so there's no mailbox to connect. Threads appear
 * in Conversations (channel=email).
 */
import { useState } from 'react'
import { ConfigureTitle } from './BrandLogo'
import { useSupportInbox, useSetSupportAutoSend } from '@/hooks/useSupportInbox'

export function SupportInboxConfigure({ onClose }: { onClose: () => void }) {
  const { data: inbox, isLoading } = useSupportInbox()
  const setAutoSend = useSetSupportAutoSend()
  const [copied, setCopied] = useState(false)

  const copy = async () => {
    if (!inbox?.address) return
    try {
      await navigator.clipboard.writeText(inbox.address)
      setCopied(true)
      setTimeout(() => setCopied(false), 1500)
    } catch { /* clipboard blocked — user can select manually */ }
  }

  return (
    <div className="bb-int-configure">
      <div className="bb-int-configure-head">
        <ConfigureTitle id="support-inbox">AI Support Inbox</ConfigureTitle>
        <button className="bb-btn bb-btn-ghost bb-btn-small" onClick={onClose}>Close</button>
      </div>

      {isLoading && <p className="bb-int-configure-body">Loading…</p>}

      {inbox && (
        <div className="bb-support-setup">
          {/* Address — front and centre, with a copy icon. */}
          <div className="bb-support-addr">
            <code className="bb-support-addr-value">{inbox.address}</code>
            <button
              className="bb-support-copy-icon"
              onClick={copy}
              title="Copy address"
              aria-label="Copy support address"
            >
              {copied ? '✓' : '⧉'}
            </button>
          </div>

          {/* One toggle, no essay. */}
          <label className="bb-support-autosend">
            <input
              type="checkbox"
              checked={inbox.autoSend}
              onChange={(e) => setAutoSend.mutate(e.target.checked)}
            />
            <span>
              Send replies automatically
              <span className="bb-field-help">
                {inbox.autoSend ? 'On — answers customers itself; risky ones still wait for you.' : 'Off — drafts a reply and flags it for you.'}
              </span>
            </span>
          </label>

          {/* The two things this unlocks. */}
          <ol className="bb-support-highlights">
            <li>
              <span className="bb-support-hl-num">1</span>
              <span><strong>Use it as your support email</strong> — put the address above on your site. Customers email it, the agent answers from it.</span>
            </li>
            <li>
              <span className="bb-support-hl-num">2</span>
              <span><strong>You</strong> can email it too to run actions or get reports — your Agent co-pilot, by email.</span>
            </li>
          </ol>
        </div>
      )}
    </div>
  )
}
