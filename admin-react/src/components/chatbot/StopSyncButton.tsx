/**
 * StopSyncButton: ghost "Stop" with an inline two-click confirm.
 * Click once -> "Stop sync?" (reverts after 4 s); click again -> stops.
 */
import { useEffect, useState } from 'react'
import { CONFIRM_TIMEOUT_MS, confirmStep, type ConfirmState } from '@/lib/confirmStep'

interface Props {
  onStop: () => void
  isStopping: boolean
}

export function StopSyncButton({ onStop, isStopping }: Props) {
  const [state, setState] = useState<ConfirmState>('idle')

  useEffect(() => {
    if (state !== 'armed') return
    const t = setTimeout(() => setState(confirmStep(state, 'timeout').state), CONFIRM_TIMEOUT_MS)
    return () => clearTimeout(t)
  }, [state])

  const click = () => {
    const next = confirmStep(state, 'click')
    setState(next.state)
    if (next.run) onStop()
  }

  return (
    <button
      type="button"
      className="bb-btn bb-btn-ghost bb-btn-small"
      onClick={click}
      disabled={isStopping}
      title="Stop this sync. Pages already read stay available."
    >
      {isStopping ? 'Stopping…' : state === 'armed' ? 'Stop sync?' : 'Stop'}
    </button>
  )
}
