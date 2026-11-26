/**
 * Search Console configure panel.
 *
 * Three-state UI based on the row in `site_integrations`:
 *
 *   1. No row (or `pending`)        → "Sign in with Google" button.
 *   2. Tokens stored, no property   → property picker (admin chooses
 *      which of their GSC properties this site is associated with).
 *   3. status=connected             → connected card with account
 *      email + property + Disconnect.
 *
 * The OAuth flow opens Google's consent screen in a popup. The callback
 * route (/api/oauth/google/callback) posts a `bitbot:gsc-oauth-complete`
 * message back via window.opener.postMessage; we listen for it,
 * invalidate the integration query, and advance the UI.
 */
import { useEffect, useState } from 'react'
import { ConfigureTitle } from './BrandLogo'
import { rpc } from '@/api/rpc'
import {
  useSearchConsoleIntegration,
  useStartSearchConsoleOAuth,
  useSelectSearchConsoleProperty,
  useDisconnectSearchConsole,
  type SearchConsoleProperty,
} from '@/hooks/useSearchConsole'
import { useQueryClient } from '@tanstack/react-query'

export function SearchConsoleConfigure({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient()
  const { data, isLoading } = useSearchConsoleIntegration()
  const startOAuth = useStartSearchConsoleOAuth()
  const selectProperty = useSelectSearchConsoleProperty()
  const disconnect = useDisconnectSearchConsole()
  const [properties, setProperties] = useState<SearchConsoleProperty[] | null>(null)
  const [picking, setPicking] = useState<string | null>(null)
  const [popupOpen, setPopupOpen] = useState(false)
  const [popupError, setPopupError] = useState<string | null>(null)

  const integration = data?.integration ?? null
  const oauthAvailable = data?.oauthAvailable ?? false

  // Listen for the OAuth callback's postMessage.
  useEffect(() => {
    const onMessage = (e: MessageEvent) => {
      const payload = e.data
      if (!payload || payload.type !== 'bitbot:gsc-oauth-complete') return
      setPopupOpen(false)
      if (payload.ok) {
        setPopupError(null)
        // The callback already persisted tokens + has the property
        // list, but we still re-fetch the integration row so the UI
        // reads the latest server state.
        qc.invalidateQueries({ queryKey: ['integrations', 'search-console'] })
        // Use the property list the callback returned directly — it's
        // freshly fetched and the same Google account we just authed.
        if (Array.isArray(payload.data?.properties)) {
          setProperties(payload.data.properties)
        }
      } else {
        setPopupError(typeof payload.error === 'string' ? payload.error : 'Sign-in failed.')
      }
    }
    window.addEventListener('message', onMessage)
    return () => window.removeEventListener('message', onMessage)
  }, [qc])

  // After connect, if the user reopens the panel we may already have
  // tokens stored but no property selected. Re-list properties on
  // demand so the picker has fresh data.
  useEffect(() => {
    const needPick =
      integration && integration.status !== 'connected' && integration.accountEmail && !properties
    if (!needPick) return
    let cancelled = false
    ;(async () => {
      const res = await rpc<{ properties: SearchConsoleProperty[] }>(
        'ListSearchConsoleProperties',
      )
      if (cancelled) return
      if (res.success) setProperties(res.data.properties)
    })()
    return () => { cancelled = true }
  }, [integration, properties])

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
      'bitbot-gsc-oauth',
      `width=${w},height=${h},left=${left},top=${top},menubar=no,toolbar=no`,
    )
    if (!popup) {
      setPopupError('Popup was blocked. Allow popups for this site and try again.')
      return
    }
    setPopupOpen(true)
  }

  const pick = async (siteUrl: string) => {
    setPicking(siteUrl)
    try {
      await selectProperty.mutateAsync({ property: siteUrl })
    } finally {
      setPicking(null)
    }
  }

  // ---------- render ----------

  if (!oauthAvailable && !integration) {
    return (
      <div className="bb-int-configure">
        <div className="bb-int-configure-head">
          <ConfigureTitle id="search-console">Google Search Console</ConfigureTitle>
          <button className="bb-btn bb-btn-ghost bb-btn-small" onClick={onClose}>Close</button>
        </div>
        <p className="bb-int-configure-error">
          Search Console isn't set up on the server yet. Ask the Youami team to
          finish the OAuth configuration.
        </p>
      </div>
    )
  }

  if (isLoading) {
    return (
      <div className="bb-int-configure">
        <div className="bb-int-configure-head">
          <ConfigureTitle id="search-console">Google Search Console</ConfigureTitle>
          <button className="bb-btn bb-btn-ghost bb-btn-small" onClick={onClose}>Close</button>
        </div>
        <p className="bb-int-configure-body">Loading…</p>
      </div>
    )
  }

  // State 3: fully connected
  if (integration?.status === 'connected' && integration.property) {
    return (
      <div className="bb-int-configure">
        <div className="bb-int-configure-head">
          <ConfigureTitle id="search-console">Google Search Console</ConfigureTitle>
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
            Linked property: <strong>{integration.property}</strong>
          </p>
          <p className="bb-int-configure-body">
            The agent can now answer questions about your organic search
            performance using live Search Console data.
          </p>
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

  // State 2: tokens stored, no property picked yet
  if (integration?.accountEmail && properties) {
    return (
      <div className="bb-int-configure">
        <div className="bb-int-configure-head">
          <ConfigureTitle id="search-console">Google Search Console</ConfigureTitle>
          <button className="bb-btn bb-btn-ghost bb-btn-small" onClick={onClose}>Close</button>
        </div>
        <p className="bb-int-configure-body">
          Signed in as <strong>{integration.accountEmail}</strong>. Pick which
          property the agent should query for this site.
        </p>
        {properties.length === 0 ? (
          <p className="bb-int-configure-error">
            This Google account doesn't have access to any Search Console
            properties. Verify a property first, then come back.
          </p>
        ) : (
          <ul className="bb-int-property-list">
            {properties.map((p) => (
              <li key={p.siteUrl} className="bb-int-property-item">
                <div className="bb-int-property-meta">
                  <span className="bb-int-property-url">{p.siteUrl}</span>
                  <span className="bb-int-property-perm">{p.permissionLevel}</span>
                </div>
                <button
                  className="bb-btn bb-btn-primary bb-btn-small"
                  onClick={() => pick(p.siteUrl)}
                  disabled={picking !== null}
                >
                  {picking === p.siteUrl ? 'Linking…' : 'Use this'}
                </button>
              </li>
            ))}
          </ul>
        )}
        <div className="bb-int-configure-actions">
          <button
            className="bb-btn bb-btn-ghost bb-btn-small"
            onClick={() => disconnect.mutate()}
            disabled={disconnect.isPending}
          >
            Sign out of Google
          </button>
        </div>
      </div>
    )
  }

  // State 1: not connected — show the Sign-in button.
  return (
    <div className="bb-int-configure">
      <div className="bb-int-configure-head">
        <ConfigureTitle id="search-console">Google Search Console</ConfigureTitle>
        <button className="bb-btn bb-btn-ghost bb-btn-small" onClick={onClose}>Close</button>
      </div>
      <p className="bb-int-configure-body">
        Sign in with the Google account that owns this site's Search Console
        property. We'll ask only for read-only access to your search analytics.
      </p>
      <div className="bb-int-configure-actions">
        <button
          className="bb-btn bb-btn-primary bb-btn-small"
          onClick={signIn}
          disabled={startOAuth.isPending || popupOpen}
        >
          {popupOpen ? 'Waiting for Google…' : 'Sign in with Google'}
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
