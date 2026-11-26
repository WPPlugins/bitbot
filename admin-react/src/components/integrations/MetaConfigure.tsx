/**
 * Facebook Messenger configure panel.
 *
 * Scope note: the UI is Messenger-only for now. The backend can also ride
 * a Page-linked Instagram account on the same connection, but Instagram DMs
 * stay hidden here until instagram_manage_messages clears its own Meta App
 * Review. Don't re-surface Instagram copy/badges before that approval.
 *
 * Primary flow: Facebook Login for Business (popup).
 *   1. Admin clicks "Connect Facebook Page" → StartMetaOAuth returns
 *      Meta's Login dialog URL containing the app's config_id (set on
 *      the dashboard, references scopes + asset selection).
 *   2. We open it in a popup. Customer signs in with their Facebook,
 *      Meta walks them through "pick which Page(s) you grant access to",
 *      redirects to /api/oauth/meta/callback?code=...
 *   3. Callback exchanges the code for tokens, enumerates the granted
 *      Pages via /me/accounts, persists each Page's access token,
 *      subscribes our webhook, posts `bitbot:meta-oauth-complete` back
 *      via window.opener.postMessage.
 *   4. We invalidate the integration query; status flips to connected.
 *
 * Pre-App-Review reality: Meta blocks non-Tester users from this dialog.
 * If the admin lands on the popup and sees "App not active" / "App in
 * Development Mode", we surface a fallback message pointing them to
 * email support@youami.ai for early-access onboarding (we add them as
 * a Tester on the Meta dashboard).
 *
 * If a token is later invalidated (password change, app removal, lost
 * Page access), the integration flips to status='error' and this panel
 * shows a reconnect prompt — the same popup re-points the Page.
 */
import { useEffect, useState } from 'react'
import { ConfigureTitle } from './BrandLogo'
import {
  useMetaIntegration,
  useStartMetaOAuth,
  useDisconnectMeta,
} from '@/hooks/useMeta'
import { useQueryClient } from '@tanstack/react-query'

export function MetaConfigure({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient()
  const { data, isLoading } = useMetaIntegration()
  const startOAuth = useStartMetaOAuth()
  const disconnect = useDisconnectMeta()
  const [popupOpen, setPopupOpen] = useState(false)
  const [popupError, setPopupError] = useState<string | null>(null)

  const integration = data?.integration ?? null
  const oauthAvailable = data?.oauthAvailable ?? false

  useEffect(() => {
    const onMessage = (e: MessageEvent) => {
      const payload = e.data
      if (!payload || payload.type !== 'bitbot:meta-oauth-complete') return
      setPopupOpen(false)
      if (payload.ok) {
        setPopupError(null)
        qc.invalidateQueries({ queryKey: ['integrations', 'meta'] })
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
    const w = 600, h = 700
    const left = window.screenX + (window.outerWidth - w) / 2
    const top = window.screenY + (window.outerHeight - h) / 2
    const popup = window.open(
      res.url,
      'bitbot-meta-oauth',
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
          <ConfigureTitle id="messenger">Facebook Messenger</ConfigureTitle>
          <button className="bb-btn bb-btn-ghost bb-btn-small" onClick={onClose}>Close</button>
        </div>
        <p className="bb-int-configure-error">
          Meta isn&apos;t set up on the server yet. Ask the Youami team to finish
          configuration.
        </p>
      </div>
    )
  }

  if (isLoading) {
    return (
      <div className="bb-int-configure">
        <div className="bb-int-configure-head">
          <ConfigureTitle id="messenger">Facebook Messenger</ConfigureTitle>
          <button className="bb-btn bb-btn-ghost bb-btn-small" onClick={onClose}>Close</button>
        </div>
        <p className="bb-int-configure-body">Loading…</p>
      </div>
    )
  }

  const isConnected = integration?.status === 'connected' && integration.pages.length > 0

  return (
    <div className="bb-int-configure">
      <div className="bb-int-configure-head">
        <ConfigureTitle id="messenger">Facebook Messenger</ConfigureTitle>
        <button className="bb-btn bb-btn-ghost bb-btn-small" onClick={onClose}>Close</button>
      </div>

      {isConnected && (
        <div className="bb-int-configure-connected">
          <div className="bb-int-status-line">
            <span className="bb-int-status bb-int-status-connected">Connected</span>
            {integration!.accountEmail && (
              <span className="bb-int-configure-prop">{integration!.accountEmail}</span>
            )}
          </div>
          <p className="bb-int-configure-body">
            The visitor agent answers Messenger DMs using the same RAG +
            skills it uses on your website.
          </p>
          <ul className="bb-int-page-list">
            {integration!.pages.map((p) => (
              <li key={p.pageId} className="bb-int-page-item">
                <span className="bb-int-page-name">{p.pageName}</span>
                <span className="bb-int-page-channels">
                  💬 Messenger
                </span>
              </li>
            ))}
          </ul>
          <div className="bb-int-configure-actions">
            <button
              className="bb-btn bb-btn-ghost bb-btn-small"
              onClick={signIn}
              disabled={startOAuth.isPending || popupOpen}
            >
              {popupOpen ? 'Waiting for Facebook…' : 'Connect another Page'}
            </button>
            <button
              className="bb-btn bb-btn-ghost bb-btn-small"
              onClick={() => disconnect.mutate()}
              disabled={disconnect.isPending}
            >
              {disconnect.isPending ? 'Disconnecting…' : 'Disconnect'}
            </button>
          </div>
        </div>
      )}

      {!isConnected && (
        <>
          {integration?.status === 'error' ? (
            <p className="bb-int-configure-body">
              Your connection stopped working. Facebook invalidated the Page
              token (usually a password change, the app being removed, or lost
              Page access). Reconnect to restore Messenger replies.
            </p>
          ) : (
            <>
              <p className="bb-int-configure-body">
                Connect the Facebook Page you want the agent to answer Messenger
                DMs on:
              </p>
              <ol className="bb-int-configure-steps">
                <li>Click Connect Facebook Page below.</li>
                <li>Sign in with the Facebook account that manages your Page.</li>
                <li>Choose the Page Youami should answer DMs on.</li>
                <li>Done. New Messenger DMs get the same answers your website chat gives.</li>
              </ol>
              <p className="bb-int-configure-help">
                You need to be an admin of a published Facebook Page.
              </p>
            </>
          )}
          <div className="bb-int-configure-actions">
            <button
              className="bb-btn bb-btn-primary bb-btn-small"
              onClick={signIn}
              disabled={startOAuth.isPending || popupOpen}
            >
              {popupOpen
                ? 'Waiting for Facebook…'
                : integration?.status === 'error'
                ? 'Reconnect Facebook Page'
                : 'Connect Facebook Page'}
            </button>
          </div>
        </>
      )}

      {popupError && (
        <p className="bb-int-configure-error">
          {popupError}
          {popupError.toLowerCase().includes('development') || popupError.toLowerCase().includes('not active') ? (
            <>
              {' '}This usually means your Facebook account hasn&apos;t been
              added as a Tester on our Meta app yet. Email{' '}
              <a href="mailto:support@youami.ai?subject=Youami%20Messenger%20early%20access">
                support@youami.ai
              </a>{' '}
              with your Page name — we&apos;ll add you within a day.
            </>
          ) : null}
        </p>
      )}

      {integration?.lastErrorMessage && (
        <p className="bb-int-configure-error">
          Last attempt: {integration.lastErrorMessage}
        </p>
      )}
    </div>
  )
}
