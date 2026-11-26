/**
 * Top of the Chatbot tab: the widget switch, where it shows, and the real
 * save state (Saving…, Saved, or Couldn't save with a Retry).
 */
import { useWidgetEnabledToggle } from '@/hooks/useAdminSettings'
import { SAVE_LABELS, SAVED_NOTE, hiddenPages, saveState, statusSummary } from '@/lib/chatbotPanels'

interface StatusBarProps {
  hideOnPages: string
  isSaving: boolean
  saveFailed: boolean
  onRetry: () => void
}

export function StatusBar({ hideOnPages, isSaving, saveFailed, onRetry }: StatusBarProps) {
  const { enabled, pending, toggle } = useWidgetEnabledToggle()
  const state = saveState(isSaving, saveFailed)
  return (
    <div className={`bb-statusbar ${enabled ? '' : 'bb-statusbar--off'}`}>
      <label className="bb-toggle-switch" aria-label="Show widget on site">
        <input type="checkbox" checked={enabled} disabled={pending} onChange={(e) => toggle(e.target.checked)} />
        <span className="bb-toggle-switch-track" aria-hidden="true">
          <span className="bb-toggle-switch-thumb" />
        </span>
      </label>
      <div className="bb-statusbar-text">
        <strong>Show widget on site</strong>
        <span className="bb-statusbar-muted"> · {statusSummary(enabled, hiddenPages(hideOnPages).length)}</span>
      </div>
      <div className={`bb-statusbar-save bb-statusbar-save--${state}`} role="status" aria-live="polite">
        <span>{SAVE_LABELS[state]}</span>
        {state === 'saved' && <span className="bb-statusbar-muted"> · {SAVED_NOTE}</span>}
        {state === 'error' && (
          <>
            <span className="bb-statusbar-muted"> · </span>
            <button type="button" className="bb-link-btn" onClick={onRetry}>Retry</button>
          </>
        )}
      </div>
    </div>
  )
}
