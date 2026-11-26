/**
 * WooCommerce configure panel.
 *
 * Two states:
 *   1. Not configured → form (consumer key + secret) + Save button.
 *   2. Configured     → connected card + Disconnect button. The form
 *      can be re-opened from the connected state to rotate creds.
 *
 * The secret is never round-tripped back to the client. Re-saving
 * requires re-pasting both fields.
 */
import { useState } from 'react'
import { ConfigureTitle } from './BrandLogo'
import {
  useWooCommerceStatus,
  useSaveWooCommerce,
  useDisconnectWooCommerce,
} from '@/hooks/useWooCommerce'

export function WooCommerceConfigure({ onClose }: { onClose: () => void }) {
  const { data, isLoading } = useWooCommerceStatus()
  const save = useSaveWooCommerce()
  const disconnect = useDisconnectWooCommerce()

  const [editing, setEditing] = useState(false)
  const [consumerKey, setConsumerKey] = useState('')
  const [consumerSecret, setConsumerSecret] = useState('')

  const configured = !!data?.configured
  const showForm = !configured || editing

  const handleSave = (e: React.FormEvent) => {
    e.preventDefault()
    if (!consumerKey.trim() || !consumerSecret.trim()) return
    save.mutate(
      { consumerKey: consumerKey.trim(), consumerSecret: consumerSecret.trim() },
      {
        onSuccess: () => {
          setEditing(false)
          setConsumerKey('')
          setConsumerSecret('')
        },
      },
    )
  }

  const handleDisconnect = () => {
    disconnect.mutate(undefined, {
      onSuccess: () => {
        setEditing(false)
        setConsumerKey('')
        setConsumerSecret('')
      },
    })
  }

  if (isLoading) {
    return (
      <div className="bb-int-configure">
        <header className="bb-int-configure-head">
          <ConfigureTitle id="woocommerce">WooCommerce</ConfigureTitle>
          <button className="bb-btn bb-btn-ghost bb-btn-small" onClick={onClose}>Close</button>
        </header>
        <p className="bb-int-configure-body">Loading…</p>
      </div>
    )
  }

  return (
    <div className="bb-int-configure">
      <header className="bb-int-configure-head">
        <ConfigureTitle id="woocommerce">WooCommerce</ConfigureTitle>
        <button className="bb-btn bb-btn-ghost bb-btn-small" onClick={onClose}>Close</button>
      </header>

      <div className="bb-int-configure-body">
        {configured && !editing ? (
          <>
            <p>
              WooCommerce is connected. The agent can answer product, stock, and order
              questions from your real store data.
            </p>
            <div className="bb-int-actions">
              <button
                className="bb-btn bb-btn-ghost bb-btn-small"
                onClick={() => setEditing(true)}
              >
                Rotate credentials
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
              In your WordPress admin, go to{' '}
              <strong>WooCommerce → Settings → Advanced → REST API</strong>, click{' '}
              <strong>Add key</strong>, give it read permissions, and paste the consumer
              key + secret below.
            </p>
            <label className="bb-field">
              <span>Consumer key</span>
              <input
                type="text"
                value={consumerKey}
                onChange={(e) => setConsumerKey(e.target.value)}
                placeholder="ck_..."
                autoComplete="off"
                spellCheck={false}
              />
            </label>
            <label className="bb-field">
              <span>Consumer secret</span>
              <input
                type="password"
                value={consumerSecret}
                onChange={(e) => setConsumerSecret(e.target.value)}
                placeholder="cs_..."
                autoComplete="off"
              />
            </label>
            <div className="bb-int-actions">
              {editing && (
                <button
                  type="button"
                  className="bb-btn bb-btn-ghost bb-btn-small"
                  onClick={() => {
                    setEditing(false)
                    setConsumerKey('')
                    setConsumerSecret('')
                  }}
                >
                  Cancel
                </button>
              )}
              <button
                type="submit"
                className="bb-btn bb-btn-primary bb-btn-small"
                disabled={save.isPending || !consumerKey.trim() || !consumerSecret.trim()}
              >
                {save.isPending ? 'Saving…' : configured ? 'Update' : 'Connect'}
              </button>
            </div>
          </form>
        ) : null}
      </div>
    </div>
  )
}
