/**
 * AgentDrawer — right-edge slide-over used by every action button in the
 * Agent surface (History / Integrations / Tasks). One primitive, one
 * z-index, one keyboard-escape handler — children supply the actual
 * panel content + title.
 *
 * Slides in from the right because the chat composer + transcript live
 * on the left. The admin's eye stays anchored on the conversation; the
 * drawer is a peripheral lookup, not a context switch.
 */
import { useEffect, type ReactNode } from 'react'

interface AgentDrawerProps {
  open: boolean
  title: string
  onClose: () => void
  children: ReactNode
  /** Optional header-right action (e.g. "+ New" inside History). */
  headerAction?: ReactNode
  /** Wide variant — for document/artefact viewing where the panel needs room. */
  wide?: boolean
}

export function AgentDrawer({ open, title, onClose, children, headerAction, wide }: AgentDrawerProps) {
  // Esc to close — same affordance as the account drawer.
  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])

  return (
    <>
      <div
        className={`bb-agent-drawer-scrim ${open ? 'bb-agent-drawer-scrim-open' : ''}`}
        onClick={onClose}
        aria-hidden={!open}
      />
      <aside
        className={`bb-agent-drawer ${wide ? 'bb-agent-drawer-wide' : ''} ${open ? 'bb-agent-drawer-open' : ''}`}
        role="dialog"
        aria-label={title}
        aria-hidden={!open}
      >
        <header className="bb-agent-drawer-head">
          <h3 className="bb-agent-drawer-title">{title}</h3>
          <div className="bb-agent-drawer-head-actions">
            {headerAction}
            <button
              type="button"
              className="bb-agent-drawer-close"
              onClick={onClose}
              aria-label="Close"
            >
              ✕
            </button>
          </div>
        </header>
        <div className="bb-agent-drawer-body">{children}</div>
      </aside>
    </>
  )
}
