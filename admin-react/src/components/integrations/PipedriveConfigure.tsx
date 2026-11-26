/**
 * Pipedrive configure panel.
 *
 * Two states:
 *   1. Not connected → paste API token + Connect.
 *   2. Connected     → connected card (account + company) + Disconnect.
 *
 * The token is never round-tripped back to the client; reconnecting
 * requires re-pasting it.
 */
import { useState } from 'react'
import { ConfigureTitle } from './BrandLogo'
import {
  usePipedriveStatus,
  useSavePipedrive,
  useDisconnectPipedrive,
  usePipedriveAutoSync,
  useSetPipedriveAutoSync,
} from '@/hooks/usePipedrive'

export function PipedriveConfigure({ onClose }: { onClose: () => void }) {
  const { data, isLoading } = usePipedriveStatus()
  const save = useSavePipedrive()
  const disconnect = useDisconnectPipedrive()
  const autoSyncQuery = usePipedriveAutoSync()
  const setAutoSync = useSetPipedriveAutoSync()

  const [editing, setEditing] = useState(false)
  const [apiToken, setApiToken] = useState('')

  const configured = !!data?.configured
  const showForm = !configured || editing

  const handleSave = (e: React.FormEvent) => {
    e.preventDefault()
    if (!apiToken.trim()) return
    save.mutate(
      { apiToken: apiToken.trim() },
      { onSuccess: () => { setEditing(false); setApiToken('') } },
    )
  }

  const handleDisconnect = () => {
    disconnect.mutate(undefined, {
      onSuccess: () => { setEditing(false); setApiToken('') },
    })
  }

  if (isLoading) {
    return (
      <div className="bb-int-configure">
        <header className="bb-int-configure-head">
          <ConfigureTitle id="pipedrive">Pipedrive</ConfigureTitle>
          <button className="bb-btn bb-btn-ghost bb-btn-small" onClick={onClose}>Close</button>
        </header>
        <p className="bb-int-configure-body">Loading…</p>
      </div>
    )
  }

  return (
    <div className="bb-int-configure">
      <header className="bb-int-configure-head">
        <ConfigureTitle id="pipedrive">Pipedrive</ConfigureTitle>
        <button className="bb-btn bb-btn-ghost bb-btn-small" onClick={onClose}>Close</button>
      </header>

      <div className="bb-int-configure-body">
        {configured && !editing ? (
          <>
            <p>
              Pipedrive is connected{data?.companyName ? <> to <strong>{data.companyName}</strong></> : null}
              {data?.accountName ? <> ({data.accountName})</> : null}. The agent can answer
              questions about your people and deals, and push captured leads into Pipedrive.
            </p>
            <label className="bb-int-toggle-row">
              <input
                type="checkbox"
                checked={autoSyncQuery.data === true}
                disabled={autoSyncQuery.isLoading || setAutoSync.isPending}
                onChange={(e) => setAutoSync.mutate(e.target.checked)}
              />
              <span>Auto-sync new leads to Pipedrive</span>
            </label>
            <div className="bb-int-actions">
              <button className="bb-btn bb-btn-ghost bb-btn-small" onClick={() => setEditing(true)}>
                Replace token
              </button>
              <button
                className="bb-btn bb-btn-danger bb-btn-small"
                onClick={handleDisconnect}
                disabled={disconnect.isPending}
              >
                {disconnect.isPending ? 'Disconnecting…' : 'Disconnect'}
              </button>
            </div>
          </>
        ) : null}

        {showForm ? (
          <form onSubmit={handleSave}>
            <p>
              In Pipedrive, go to <strong>Settings → Personal preferences → API</strong>,
              copy your personal API token, and paste it below. We only need read access plus
              the ability to create people and deals.
            </p>
            <label className="bb-field">
              <span>API token</span>
              <input
                type="password"
                value={apiToken}
                onChange={(e) => setApiToken(e.target.value)}
                placeholder="Your Pipedrive API token"
                autoComplete="off"
                spellCheck={false}
              />
            </label>
            <div className="bb-int-actions">
              {editing && (
                <button
                  type="button"
                  className="bb-btn bb-btn-ghost bb-btn-small"
                  onClick={() => { setEditing(false); setApiToken('') }}
                >
                  Cancel
                </button>
              )}
              <button
                type="submit"
                className="bb-btn bb-btn-primary bb-btn-small"
                disabled={save.isPending || !apiToken.trim()}
              >
                {save.isPending ? 'Connecting…' : configured ? 'Update' : 'Connect'}
              </button>
            </div>
          </form>
        ) : null}
      </div>
    </div>
  )
}
