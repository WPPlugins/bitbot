/**
 * AgentTopupGate — replaces the composer when the month's credits are used
 * up. The card speaks credits; dollars appear only beside the charge (the
 * buttons, the confirm step), because that is where money changes hands. The
 * purchasable amounts and the credits rate come from the backend, never
 * hardcoded client-side.
 *
 * A click never charges on its own (see topupFlow): plan status is re-read
 * fresh, the saved card is named, and only Confirm charges it.
 */
import type { PlanStatus } from '@/api/types'
import { formatResetDate } from '@/lib/resetDate'
import { resetIso, topupButtonText, topupConfirmText, topupReceipt } from './usageLines'
import { useTopup } from './useTopup'

type Credit = NonNullable<PlanStatus['agentCredit']>

export function AgentTopupGate({ credit }: { credit: Credit }) {
  const { step, error, busy, pick, confirm, cancel } = useTopup()
  // The same reset date the Usage panel and the chat say.
  const resets = formatResetDate(resetIso(credit))
  const options = credit.topupOptionsUsd ?? []
  const perUsd = credit.creditsPerUsd ?? null

  return (
    <div className="bb-agent-gate">
      <div className="bb-agent-gate-head">
        <span className="bb-agent-gate-badge">Paused</span>
        <h3 className="bb-agent-gate-title">This month's credits are used up</h3>
      </div>
      <p className="bb-agent-gate-sub">
        The agent is paused until your credits reset on {resets}. Top up to keep going now. Your
        visitor chatbot keeps running either way.
      </p>
      {credit.autoTopup?.enabled && credit.autoTopup.skipReason && (
        <p className="bb-agent-gate-fine">{credit.autoTopup.skipReason}</p>
      )}
      {step.kind === 'charged' ? (
        <p className="bb-agent-gate-charged">{topupReceipt(step.credits, step.leftCredits)}</p>
      ) : step.kind === 'confirm' || step.kind === 'charging' ? (
        <div className="bb-agent-gate-confirm" role="group" aria-label="Confirm top-up">
          <strong>
            {step.kind === 'confirm'
              ? topupConfirmText(step.amountUsd, step.card.last4, perUsd)
              : `Charging $${step.amountUsd}…`}
          </strong>
          {step.kind === 'confirm' && (
            <div className="bb-agent-gate-actions">
              <button
                type="button"
                className="bb-btn bb-btn-primary"
                onClick={() => void confirm(step.amountUsd)}
              >
                Confirm
              </button>
              <button type="button" className="bb-btn" onClick={cancel}>
                Cancel
              </button>
            </div>
          )}
        </div>
      ) : (
        options.length > 0 && (
          <div className="bb-agent-gate-actions">
            {options.map((amt) => (
              <button
                key={amt}
                type="button"
                className="bb-btn bb-btn-primary"
                disabled={busy}
                onClick={() => void pick(amt)}
              >
                {busy && 'amountUsd' in step && step.amountUsd === amt
                  ? step.kind === 'redirecting'
                    ? 'Opening checkout…'
                    : 'Checking…'
                  : topupButtonText(amt, perUsd)}
              </button>
            ))}
          </div>
        )
      )}
      {error && <p className="bb-agent-gate-error">{error}</p>}
    </div>
  )
}
