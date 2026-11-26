/**
 * One feature row: optional switch, title, optional one-line note, and a state
 * pill or action on the right. Configuration (children) sits under the text.
 */
import type { ReactNode } from 'react'

interface FeatureRowProps {
  title: string
  /** Optional one-line note, for a status such as a feature being unavailable. */
  description?: string
  /** Omit onToggle for a row without a switch (it keeps the same grid). */
  on?: boolean
  onToggle?: (next: boolean) => void
  disabled?: boolean
  /** Small state pill, e.g. On / Off. */
  pill?: string
  pillTone?: 'on' | 'off'
  action?: ReactNode
  children?: ReactNode
}

export function FeatureRow({ title, description, on, onToggle, disabled, pill, pillTone, action, children }: FeatureRowProps) {
  return (
    <div className={`bb-feature${onToggle ? '' : ' bb-feature--plain'}`}>
      {onToggle && (
        <label className="bb-form-toggle">
          <input
            type="checkbox"
            aria-label={title}
            checked={!!on}
            disabled={disabled}
            onChange={(e) => onToggle(e.target.checked)}
          />
          <span className="bb-form-toggle-track"><span className="bb-form-toggle-thumb" /></span>
        </label>
      )}
      <div className="bb-feature-text">
        <h4 className="bb-feature-title">{title}</h4>
        {description && <p className="bb-feature-desc">{description}</p>}
      </div>
      <div className="bb-feature-side">
        {pill && <span className={`bb-state-pill bb-state-pill--${pillTone ?? 'off'}`}>{pill}</span>}
        {action}
      </div>
      {children && <div className="bb-feature-sub">{children}</div>}
    </div>
  )
}
