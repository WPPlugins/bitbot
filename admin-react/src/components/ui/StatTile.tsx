/**
 * StatTile — single metric with optional delta indicator.
 * Used in the Overview tab's 4-up stat row.
 *
 * Value is rendered in display serif; the eye lands on the number.
 */
import { formatNumber } from '@/lib/utils'

interface StatTileProps {
  label: string
  value: number | string | null | undefined
  /** Numeric delta vs prior period (e.g. +24, -3). Sign-aware colouring. */
  delta?: number | null | undefined
  /** Label that contextualises the delta — e.g. "vs prior week". */
  deltaLabel?: string
  /** Optional unit suffix on the value (e.g. "%"). */
  unit?: string
}

export function StatTile({ label, value, delta, deltaLabel, unit }: StatTileProps) {
  const formatted = typeof value === 'number' ? formatNumber(value) : (value ?? '—')
  const hasDelta = typeof delta === 'number' && delta !== 0
  const deltaSign = (delta ?? 0) > 0 ? '▲' : '▼'
  const deltaClass = (delta ?? 0) > 0 ? 'bb-stat-delta-up' : 'bb-stat-delta-down'

  return (
    <div className="bb-stat-tile">
      <div className="bb-stat-label">{label}</div>
      <div className="bb-stat-value">
        {formatted}
        {unit && <span className="bb-stat-unit">{unit}</span>}
      </div>
      {hasDelta && (
        <div className={`bb-stat-delta ${deltaClass}`}>
          <span className="bb-stat-delta-arrow">{deltaSign}</span>
          {Math.abs(delta ?? 0)}
          {deltaLabel && <span className="bb-stat-delta-label"> {deltaLabel}</span>}
        </div>
      )}
    </div>
  )
}
