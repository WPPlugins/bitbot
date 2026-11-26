/** A collapsed "Advanced" row at the bottom of a card, with a summary of what is customised. */
import { useId, useState, type ReactNode } from 'react'

interface AdvancedRowProps {
  label: string
  summary: string
  children: ReactNode
}

export function AdvancedRow({ label, summary, children }: AdvancedRowProps) {
  const [open, setOpen] = useState(false)
  const id = useId()
  return (
    <div className="bb-advanced">
      <button
        type="button"
        className="bb-advanced-head"
        aria-expanded={open}
        aria-controls={id}
        onClick={() => setOpen(!open)}
      >
        <span>{label}</span>
        <span className="bb-advanced-summary">{summary}<span aria-hidden="true">{open ? ' ▴' : ' ▾'}</span></span>
      </button>
      {open && <div id={id} className="bb-advanced-body">{children}</div>}
    </div>
  )
}
