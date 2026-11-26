/**
 * Leads tab — two-column master/detail layout. Left column is the
 * filterable list; right column is the detail panel for the selected
 * lead (AI research, transcript, email composer, activity timeline).
 *
 * Selection is held in URL state via the `lead` search param so that
 * a) refreshing keeps you in the same lead and
 * b) deep links from the Overview feed land directly on the right lead.
 */
import { useEffect, useState } from 'react'
import type { LeadInfo } from '@/api/types'
import { LeadsList } from '@/components/leads/LeadsList'
import { LeadDetail } from '@/components/leads/LeadDetail'
import { useAdminSettings } from '@/hooks/useAdminSettings'
import { getConfig } from '@/lib/config'

function readLeadParam(): string | null {
  const u = new URL(window.location.href)
  return u.searchParams.get('lead')
}
function writeLeadParam(id: string | null) {
  const u = new URL(window.location.href)
  if (id) u.searchParams.set('lead', id); else u.searchParams.delete('lead')
  window.history.replaceState({}, '', u)
}

export function Leads() {
  const [selectedId, setSelectedId] = useState<string | null>(readLeadParam())

  useEffect(() => {
    writeLeadParam(selectedId)
  }, [selectedId])

  const handleSelect = (lead: LeadInfo) => setSelectedId(lead.id)

  return (
    <>
      <NotificationEmailStrip />

      <div className="bb-leads-grid">
        <LeadsList selectedId={selectedId} onSelect={handleSelect} />
        <LeadDetail leadId={selectedId} onClose={() => setSelectedId(null)} />
      </div>
    </>
  )
}

// Inline lead-notification settings strip. Sits just under the section
// head so admins land on the master/detail flow but can still toggle
// "where to email new leads" in two clicks.
function NotificationEmailStrip() {
  const { settings, save, isSaving } = useAdminSettings()
  // Suggested default shown in the input when notifications are off — the
  // owner's WP admin email. Display-only: it is NOT active until they save.
  const adminEmail = (getConfig().adminEmail ?? '').trim()
  const [email, setEmail] = useState(settings?.notificationEmail ?? '')
  const [editing, setEditing] = useState(false)

  useEffect(() => {
    if (!editing) setEmail(settings?.notificationEmail ?? '')
  }, [settings?.notificationEmail, editing])

  if (!settings) return null

  const saved = (settings.notificationEmail ?? '').trim()
  const dirty = editing && email !== saved
  const hasEmail = saved.length > 0
  // Opt-in: status reflects the SAVED value only. Enabling prefills the admin
  // email so it's one click to turn on, but "Off" stays off until saved.
  const startEditing = () => { setEmail(saved || adminEmail); setEditing(true) }

  return (
    <div className="bb-lead-notification-strip">
      {!editing && (
        <>
          <div className="bb-lead-notification-summary">
            <strong>Lead notifications</strong>
            <span className="bb-lead-notification-status">
              {hasEmail ? `Emailing ${saved}` : 'Off — no one gets notified'}
            </span>
          </div>
          <button
            className="bb-btn bb-btn-ghost bb-btn-small"
            onClick={startEditing}
          >
            {hasEmail ? 'Change' : 'Add email'}
          </button>
        </>
      )}

      {editing && (
        <>
          <div className="bb-lead-notification-edit">
            <strong>Lead notifications</strong>
            <p className="bb-field-help" style={{ margin: '4px 0 8px' }}>
              We email this address when a new lead lands. Leave blank to skip.
            </p>
            <input
              type="email"
              className="bb-input"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="notifications@yourbusiness.com"
              data-ph-mask
              autoFocus
            />
          </div>
          <div className="bb-lead-notification-actions">
            <button
              className="bb-btn bb-btn-primary bb-btn-small"
              disabled={isSaving || !dirty}
              onClick={() => {
                save({ notificationEmail: email })
                setEditing(false)
              }}
            >
              {isSaving ? 'Saving…' : 'Save'}
            </button>
            <button
              className="bb-btn bb-btn-ghost bb-btn-small"
              onClick={() => { setEmail(saved); setEditing(false) }}
            >
              Cancel
            </button>
          </div>
        </>
      )}
    </div>
  )
}
