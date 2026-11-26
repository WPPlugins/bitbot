import { useState } from 'react'
import { createPortal } from 'react-dom'

/**
 * AddUrlsModal — paste one URL per line, hit "Index these URLs", and
 * the backend's IndexUrlList RPC fetches/extracts/embeds each one as
 * source='manual'. Used to teach the bot pages the plugin's normal
 * crawl can't reach: members-only content, external docs, knowledge
 * bases hosted elsewhere, etc.
 *
 * Validation is minimal — we accept anything that parses as an absolute
 * URL with http(s) scheme. The backend re-validates and reports per-URL
 * failures in the response (we surface those in the success toast).
 */
export function AddUrlsModal({
  onCancel,
  onSubmit,
  submitting,
  limitHelp,
}: {
  onCancel: () => void
  onSubmit: (urls: string[]) => void
  submitting: boolean
  /** Free slots line; replaces the default help text when the plan has a limit. */
  limitHelp?: string | null
}) {
  const [text, setText] = useState('')

  const parsed = text
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)
  const valid: string[] = []
  const invalid: string[] = []
  for (const line of parsed) {
    try {
      const u = new URL(line)
      if (u.protocol === 'http:' || u.protocol === 'https:') valid.push(u.toString())
      else invalid.push(line)
    } catch {
      invalid.push(line)
    }
  }

  const handleSubmit = () => {
    if (valid.length === 0) return
    onSubmit(valid)
  }

  // Portal to <body> so the position:fixed overlay actually overlays
  // the viewport. Without the portal, a transformed/filtered ancestor
  // (the Card, WP admin chrome, etc.) localises position:fixed and
  // the modal gets clipped inside the Card. `.bb-portal-scope` on the
  // wrapper re-establishes the design-token CSS variables that are
  // otherwise scoped to #bitbot-admin-root.
  const modal = (
    <div
      className="bb-portal-scope bb-avatar-crop-modal-overlay"
      onClick={(e) => { if (e.target === e.currentTarget) onCancel() }}
      role="dialog"
      aria-modal="true"
      aria-label="Add URLs to bot knowledge"
    >
      <div className="bb-add-urls-modal">
        <header className="bb-avatar-crop-head">
          <h3>Add a URL or sitemap</h3>
          <p>
            {limitHelp ?? 'One URL per line, or a sitemap (.xml) to index every page it lists (crawled in the background).'}
          </p>
        </header>

        <textarea
          className="bb-input bb-textarea"
          rows={6}
          value={text}
          onChange={(e) => setText(e.target.value)}
          placeholder="https://example.com/docs/getting-started&#10;https://example.com/sitemap.xml"
          disabled={submitting}
        />

        <div className="bb-add-urls-meta">
          {valid.length > 0 && (
            <span className="bb-add-urls-count">
              {valid.length} URL{valid.length === 1 ? '' : 's'} ready
            </span>
          )}
          {invalid.length > 0 && (
            <span className="bb-add-urls-warn">
              {invalid.length} line{invalid.length === 1 ? '' : 's'} skipped (not a valid http/https URL)
            </span>
          )}
        </div>

        <footer className="bb-avatar-crop-actions">
          <button type="button" className="bb-btn bb-btn-ghost" onClick={onCancel} disabled={submitting}>Cancel</button>
          <button
            type="button"
            className="bb-btn bb-btn-primary"
            onClick={handleSubmit}
            disabled={submitting || valid.length === 0}
          >
            {submitting ? 'Indexing…' : `Index ${valid.length || ''} URL${valid.length === 1 ? '' : 's'}`}
          </button>
        </footer>
      </div>
    </div>
  )
  return createPortal(modal, document.body)
}
