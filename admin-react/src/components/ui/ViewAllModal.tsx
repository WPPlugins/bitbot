/**
 * ViewAllModal — small, focused dialog used by every Overview bento card
 * that lists ranked items (top questions, top countries, coverage gaps,
 * top cited pages). Each card surfaces top 3-5; the "View all →" button
 * opens this with the full list.
 *
 * The modal is presentational — callers pass in a title and a render
 * function for the body. Closes on Escape, click outside, or the X.
 */
import { useEffect, type ReactNode } from 'react'

interface ViewAllModalProps {
  open: boolean
  title: string
  aside?: string
  onClose: () => void
  children: ReactNode
}

export function ViewAllModal({ open, title, aside, onClose, children }: ViewAllModalProps) {
  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open) return null
  return (
    <div className="bb-modal-backdrop" onClick={onClose}>
      <div className="bb-modal" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true">
        <header className="bb-modal-head">
          <div className="bb-modal-titles">
            <h3 className="bb-modal-title">{title}</h3>
            {aside && <span className="bb-modal-aside">{aside}</span>}
          </div>
          <button className="bb-modal-close" onClick={onClose} aria-label="Close">×</button>
        </header>
        <div className="bb-modal-body">{children}</div>
      </div>
    </div>
  )
}
