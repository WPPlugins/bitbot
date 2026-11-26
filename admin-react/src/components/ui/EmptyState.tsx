/**
 * EmptyState — designed empty state that teaches, never punishes.
 * Per BLUEPRINT.md Phase 1 critique #5: every list-empty surface gets
 * one of these instead of a sad gray box.
 *
 * Style: editorial — italic serif headline, body, single CTA.
 */
import type { ReactNode } from 'react'

interface EmptyStateProps {
  /** Icon (SVG element) shown above the headline. */
  icon?: ReactNode
  headline: string
  body: string
  ctaLabel?: string
  onCta?: () => void
}

export function EmptyState({ icon, headline, body, ctaLabel, onCta }: EmptyStateProps) {
  return (
    <div className="bb-empty-state">
      {icon && <div className="bb-empty-icon">{icon}</div>}
      <h4 className="bb-empty-headline">{headline}</h4>
      <p className="bb-empty-body">{body}</p>
      {ctaLabel && onCta && (
        <button className="bb-btn bb-btn-primary" onClick={onCta}>
          {ctaLabel}
        </button>
      )}
    </div>
  )
}
