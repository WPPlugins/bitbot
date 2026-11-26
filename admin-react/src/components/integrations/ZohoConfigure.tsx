/**
 * Zoho CRM configure panel.
 *
 * Two-state UI (simpler than GSC — Zoho doesn't have a property picker):
 *
 *   1. No row (or `pending`)  → "Connect Zoho" button.
 *   2. status=connected       → connected card with account email + Disconnect.
 *
 * The OAuth flow opens Zoho's consent screen in a popup. The callback route
 * (/api/oauth/zoho/callback) posts a `bitbot:zoho-oauth-complete` message
 * back via window.opener.postMessage; we listen for it, invalidate the
 * integration query, and the panel re-renders into the connected state.
 */
import { useEffect, useState } from 'react'
import { ConfigureTitle } from './BrandLogo'
import {
  useZohoIntegration,
  useStartZohoOAuth,
  useDisconnectZoho,
  useZohoAutoSync,
  useSetZohoAutoSync,
} from '@/hooks/useZoho'
import { useQueryClient } from '@tanstack/react-query'

export function ZohoConfigure({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient()
  const { data, isLoading } = useZohoIntegration()
  const startOAuth = useStartZohoOAuth()
  const disconnect = useDisconnectZoho()
  const [popupOpen, setPopupOpen] = useState(false)
  const [popupError, setPopupError] = useState<string | null>(null)

  const integration = data?.integration ?? null
  const oauthAvailable = data?.oauthAvailable ?? false
  const autoSyncQuery = useZohoAutoSync()
  const setAutoSync = useSetZohoAutoSync()

  useEffect(() => {
    const onMessage = (e: MessageEvent) => {
      const payload = e.data
      if (!payload || payload.type !== 'bitbot:zoho-oauth-complete') return
      setPopupOpen(false)
      if (payload.ok) {
        setPopupError(null)
        qc.invalidateQueries({ queryKey: ['integrations', 'zoho'] })
      } else {
        setPopupError(typeof payload.error === 'string' ? payload.error : 'Sign-in failed.')
      }
    }
    window.addEventListener('message', onMessage)
    return () => window.removeEventListener('message', onMessage)
  }, [qc])

  const signIn = async () => {
    setPopupError(null)
    const res = await startOAuth.mutateAsync().catch((e) => {
      setPopupError(e instanceof Error ? e.message : 'Could not start sign-in')
      return null
    })
    if (!res?.url) return
    const w = 520, h = 640
    const left = window.screenX + (window.outerWidth - w) / 2
    const top = window.screenY + (window.outerHeight - h) / 2
    const popup = window.open(
      res.url,
      'bitbot-zoho-oauth',
      `width=${w},height=${h},left=${left},top=${top},menubar=no,toolbar=no`,
    )
    if (!popup) {
      setPopupError('Popup was blocked. Allow popups for this site and try again.')
      return
    }
    setPopupOpen(true)
  }

  if (!oauthAvailable && !integration) {
    return (
      <div className="bb-int-configure">
        <div className="bb-int-configure-head">
          <ConfigureTitle id="zoho-crm">Zoho CRM</ConfigureTitle>
          <button className="bb-btn bb-btn-ghost bb-btn-small" onClick={onClose}>Close</button>
        </div>
        <p className="bb-int-configure-error">
          Zoho isn't set up on the server yet. Ask the Youami team to finish the
          OAuth configuration.
        </p>
      </div>
    )
  }

  if (isLoading) {
    return (
      <div className="bb-int-configure">
        <div className="bb-int-configure-head">
          <ConfigureTitle id="zoho-crm">Zoho CRM</ConfigureTitle>
          <button className="bb-btn bb-btn-ghost bb-btn-small" onClick={onClose}>Close</button>
        </div>
        <p className="bb-int-configure-body">Loading…</p>
      </div>
    )
  }

  if (integration?.status === 'connected') {
    return (
      <div className="bb-int-configure">
        <div className="bb-int-configure-head">
          <ConfigureTitle id="zoho-crm">Zoho CRM</ConfigureTitle>
          <button className="bb-btn bb-btn-ghost bb-btn-small" onClick={onClose}>Close</button>
        </div>
        <div className="bb-int-configure-connected">
          <div className="bb-int-status-line">
            <span className="bb-int-status bb-int-status-connected">Connected</span>
            {integration.accountEmail && (
              <span className="bb-int-configure-prop">{integration.accountEmail}</span>
            )}
          </div>
          <p className="bb-int-configure-body">
            The agent can read your Zoho pipeline. Use Push to Zoho on a
            lead to send it across, or turn on auto-sync below.
          </p>
          <label className="bb-int-toggle-row">
            <input
              type="checkbox"
              checked={autoSyncQuery.data === true}
              disabled={autoSyncQuery.isLoading || setAutoSync.isPending}
              onChange={(e) => setAutoSync.mutate(e.target.checked)}
            />
            <span>Auto-sync new leads to Zoho</span>
          </label>
          <div className="bb-int-configure-actions">
            <button
              className="bb-btn bb-btn-ghost bb-btn-small"
              onClick={() => disconnect.mutate()}
              disabled={disconnect.isPending}
            >
              {disconnect.isPending ? 'Disconnecting…' : 'Disconnect'}
            </button>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="bb-int-configure">
      <div className="bb-int-configure-head">
        <ConfigureTitle id="zoho-crm">Zoho CRM</ConfigureTitle>
        <button className="bb-btn bb-btn-ghost bb-btn-small" onClick={onClose}>Close</button>
      </div>
      <p className="bb-int-configure-body">
        Sign in with the Zoho account that owns this site's CRM. We'll ask for
        access to your Leads, Contacts, and Deals so the agent can read your
        pipeline and push new Youami leads in for you.
      </p>
      <div className="bb-int-configure-actions">
        <button
          className="bb-btn bb-btn-primary bb-btn-small"
          onClick={signIn}
          disabled={startOAuth.isPending || popupOpen}
        >
          {popupOpen ? 'Waiting for Zoho…' : 'Connect Zoho'}
        </button>
      </div>
      {popupError && (
        <p className="bb-int-configure-error">{popupError}</p>
      )}
      {integration?.lastErrorMessage && (
        <p className="bb-int-configure-error">
          Last attempt: {integration.lastErrorMessage}
        </p>
      )}
    </div>
  )
}
