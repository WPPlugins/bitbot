/**
 * UsageMeter — one usage metric inside the PlanCard. Editorial typography:
 * label small caps, value display-serif, "/ cap" italic, progress bar
 * thin and ink-coloured.
 *
 * Treats `Infinity` cap as "unlimited" → hides the bar, shows "∞".
 */
import { formatNumber } from '@/lib/utils'

interface UsageMeterProps {
  label: string
  used: number
  cap: number | null  // null or Infinity → unlimited
  unit?: string
  unavailable?: boolean
  /** Credit and minute pools have real zero balances, not unlimited sentinels. */
  finite?: boolean
  /** When set, the meter renders as "{used} · {lockedHint}" instead of
   *  "{used} / ∞". Used for the Free-tier Leads meter so visitors don't
   *  see "0 / ∞" (misleading — leads aren't unlimited; they're hidden
   *  behind upgrade). */
  lockedHint?: string
}

export function UsageMeter({ label, used, cap, lockedHint, unit, unavailable, finite }: UsageMeterProps) {
  // Locked meter (Free leads): count is visible but the cap concept doesn't
  // apply — the entire feature is gated. Render "N · Upgrade to view" with
  // no bar, no infinity glyph.
  if (lockedHint) {
    return (
      <div className="bb-meter bb-meter-locked">
        <div className="bb-meter-label">{label}</div>
        <div className="bb-meter-values">
          <span className="bb-meter-used">{formatNumber(used)}</span>
          <span className="bb-meter-locked-hint">{lockedHint}</span>
        </div>
      </div>
    )
  }

  if (unavailable) return (
    <div className="bb-meter">
      <div className="bb-meter-label">{label}</div>
      <div className="bb-meter-cap">Usage unavailable</div>
    </div>
  )

  // Legacy count limits use unlimited sentinels. Credit and minute pools
  // are finite, including a zero balance.
  const isUnlimited = cap === null || !Number.isFinite(cap) || (!finite && (cap <= 0 || cap > 1_000_000))
  const pct = isUnlimited ? 0 : cap! > 0 ? Math.min(100, Math.max(0, (used / cap!) * 100)) : used > 0 ? 100 : 0
  // Visual cue when nearing cap: amber at >75%, red at >90%.
  const tone = isUnlimited ? 'ok' : pct > 90 ? 'danger' : pct > 75 ? 'warn' : 'ok'

  return (
    <div className="bb-meter">
      <div className="bb-meter-label">{label}</div>
      <div className="bb-meter-values">
        <span className="bb-meter-used">{formatNumber(used)}</span>
        <span className="bb-meter-sep">/</span>
        <span className="bb-meter-cap">{isUnlimited ? '∞' : formatNumber(cap)}{unit && ` ${unit}`}</span>
      </div>
      {!isUnlimited && (
        <div className={`bb-meter-bar bb-meter-bar-${tone}`} role="progressbar" aria-label={label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={pct} aria-valuetext={`${formatNumber(used)} of ${formatNumber(cap)}${unit ? ` ${unit}` : ''}`}>
          <div className="bb-meter-fill" style={{ width: `${pct}%` }} />
        </div>
      )}
    </div>
  )
}
