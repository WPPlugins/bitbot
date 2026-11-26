/**
 * StatusPill — colored, dot-prefixed pill for stateful concepts:
 * sync state, plan tier, lead stage, conversation freshness, etc.
 */
export type StatusPillKind = 'success' | 'warn' | 'idle' | 'syncing' | 'error'

interface StatusPillProps {
  status: StatusPillKind
  label: string
  className?: string
}

export function StatusPill({ status, label, className }: StatusPillProps) {
  return (
    <span className={`bb-status-pill bb-status-pill-${status} ${className ?? ''}`}>
      <span className={`bb-status-dot bb-status-dot-${status}`} aria-hidden />
      {label}
    </span>
  )
}
