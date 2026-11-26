/**
 * SettingField — shared label/help/input wrapper for all settings cards.
 * Keeps every form row visually consistent so the cards read as one
 * single coherent surface rather than a stack of dissimilar widgets.
 */
import type { ReactNode } from 'react'

interface SettingFieldProps {
  label: string
  help?: string
  htmlFor?: string
  /** Small affordance rendered ON the label row (e.g. the nudge demo "?"). */
  labelAction?: ReactNode
  children: ReactNode
}

export function SettingField({ label, help, htmlFor, labelAction, children }: SettingFieldProps) {
  return (
    <div className="bb-field">
      <span className="bb-field-label-row">
        <label className="bb-field-label" htmlFor={htmlFor}>{label}</label>
        {labelAction}
      </span>
      {children}
      {help && <p className="bb-field-help">{help}</p>}
    </div>
  )
}
