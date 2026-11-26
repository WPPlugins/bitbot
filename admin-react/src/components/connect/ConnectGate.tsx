/**
 * Shown instead of the admin app until the administrator connects the site.
 * A fresh install sends nothing to youami.ai before this button is pressed.
 */
import { useState } from 'react'
import { useConnect } from '@/hooks/useConnect'
import { getConfig } from '@/lib/config'
import './connect.css'

export function ConnectGate() {
  const connect = useConnect()
  // Opt-in, off until the administrator ticks it (they can change it later in Account).
  const [shareAnalytics, setShareAnalytics] = useState(false)
  const host = (() => {
    try { return new URL(getConfig().siteUrl).host } catch { return getConfig().siteUrl }
  })()

  return (
    <div className="bb-app">
      <section className="bb-card bb-connect" aria-labelledby="bb-connect-title">
        <div className="bb-card-body">
          <h2 id="bb-connect-title" className="bb-card-title">Connect to youami.ai</h2>
          <div className="bb-connect-copy">
            <p>
              Connecting registers {host} with youami.ai and sends your published pages
              (text, titles and links) there so the chatbot can answer from them.
            </p>
            <p className="bb-connect-promise">Nothing is sent until you press Connect.</p>
          </div>
          <label className="bb-connect-optin">
            <input
              type="checkbox"
              checked={shareAnalytics}
              onChange={(e) => setShareAnalytics(e.target.checked)}
            />
            <span className="bb-connect-optin-label">Share anonymous usage analytics</span>
            <span className="bb-connect-optin-help">
              Optional. Helps us fix problems in this admin. You can change it later in Account.
            </span>
          </label>
          <div className="bb-connect-action">
            <button
              type="button"
              className="bb-btn bb-btn-primary"
              disabled={connect.isPending}
              onClick={() => connect.mutate({ shareAnalytics }, { onSuccess: () => window.location.reload() })}
            >
              {connect.isPending ? 'Connecting…' : 'Connect to youami.ai'}
            </button>
            {connect.isError && (
              <p role="alert" className="bb-field-counter bb-field-counter-over">
                {connect.error instanceof Error ? connect.error.message : 'Could not connect'}
              </p>
            )}
          </div>
        </div>
      </section>
    </div>
  )
}
