/**
 * Card — base surface with optional title + header actions.
 * Editorial style: hairline border, no shadow by default (shadow only
 * for the lifted plan card / phone preview).
 */
import type { ReactNode } from 'react'

interface CardProps {
  /** Small uppercase eyebrow shown above the title in the header. */
  eyebrow?: string
  title?: string
  /** One short line under the title. */
  subtitle?: string
  titleAction?: ReactNode
  children: ReactNode
  className?: string
  /** Skip the automatic bb-card-body wrapper for cards that need direct
   *  control of internal padding (e.g. tables, lists with their own gutters). */
  noBody?: boolean
}

export function Card({ eyebrow, title, subtitle, titleAction, children, className, noBody }: CardProps) {
  const hasHeader = eyebrow || title || titleAction
  return (
    <section className={`bb-card ${className ?? ''}`}>
      {hasHeader && (
        <header className="bb-card-header">
          {(eyebrow || title) && (
            <div className="bb-card-headtext">
              <h3 className="bb-card-title">
                {eyebrow && <span className="bb-eyebrow">{eyebrow}</span>}
                {title && <span>{title}</span>}
              </h3>
              {subtitle && <p className="bb-card-subtitle">{subtitle}</p>}
            </div>
          )}
          {titleAction && <div className="bb-card-action">{titleAction}</div>}
        </header>
      )}
      {noBody ? children : <div className="bb-card-body">{children}</div>}
    </section>
  )
}
