/**
 * confirmStep: pure two-click confirm state machine (no window.confirm).
 * First click arms; a second click while armed runs the action; a timeout
 * or reset disarms. The UI owns the 4 s timer and feeds 'timeout' in.
 */
export type ConfirmState = 'idle' | 'armed'
export type ConfirmEvent = 'click' | 'timeout' | 'reset'

export const CONFIRM_TIMEOUT_MS = 4000

export interface ConfirmStep {
  state: ConfirmState
  /** True only on the click that should perform the action. */
  run: boolean
}

export function confirmStep(state: ConfirmState, event: ConfirmEvent): ConfirmStep {
  if (event === 'click') {
    return state === 'armed' ? { state: 'idle', run: true } : { state: 'armed', run: false }
  }
  return { state: 'idle', run: false }
}
