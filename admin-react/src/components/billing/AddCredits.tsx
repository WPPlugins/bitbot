/**
 * AddCredits — the "Add credits" row: one button per amount the backend
 * offers, then the confirm step naming the saved card, then the receipt. It
 * drives the same flow as the PAUSED card (useTopup / topupFlow), so a click
 * never charges until Confirm.
 */
import type { PlanStatus } from '@/api/types'
import { creditsText, topupConfirmText, topupReceipt } from '@/components/integrations/usageLines'
import { useTopup } from '@/components/integrations/useTopup'

import { formatMinutes, minuteRate, minutesForCredits, minuteReceipt } from './minuteUsage'

type Credit = NonNullable<PlanStatus['agentCredit']>

export function AddCredits({ credit, plan = 'pro_plus' }: { credit: Credit; plan?: string }) {
  const assistant = plan === 'pro'
  const rate = minuteRate(credit)
  const receipt = (added: number | null | undefined, left: number | null | undefined) => assistant ? minuteReceipt(added, left, rate) : topupReceipt(added, left)
  const { step, error, busy, pick, confirm, cancel } = useTopup(receipt)
  const options = credit.topupOptionsUsd ?? []
  const perUsd = credit.creditsPerUsd ?? null
  if (options.length === 0) return null

  return (
    <div className="bb-add-credits">
      <span className="bb-usage-label">{assistant ? 'Buy more minutes' : 'Add credits'}</span>
      {step.kind === 'charged' ? (
        <>
          <p className="bb-agent-gate-charged" role="status">{receipt(step.credits, step.leftCredits)}</p>
          <div className="bb-agent-gate-actions">
            <button type="button" className="bb-btn bb-btn-small" onClick={cancel}>
              Add more
            </button>
          </div>
        </>
      ) : step.kind === 'confirm' || step.kind === 'charging' ? (
        <div className="bb-agent-gate-confirm" role="group" aria-label="Confirm top-up">
          <strong>
            {step.kind === 'confirm'
              ? assistant
                ? `Add ${formatMinutes(minutesForCredits(step.amountUsd * (perUsd ?? 0), rate) ?? 0)} min · $${step.amountUsd} to card •••• ${step.card.last4}?`
                : topupConfirmText(step.amountUsd, step.card.last4, perUsd)
              : `Charging $${step.amountUsd}…`}
          </strong>
          {step.kind === 'confirm' && (
            <div className="bb-agent-gate-actions">
              <button type="button" className="bb-btn bb-btn-primary bb-btn-small" onClick={() => void confirm(step.amountUsd)}>
                Confirm
              </button>
              <button type="button" className="bb-btn bb-btn-small" onClick={cancel}>
                Cancel
              </button>
            </div>
          )}
        </div>
      ) : (
        <div className="bb-credit-options">
          {options.map((amt) => (
            <button
              key={amt}
              type="button"
              className="bb-credit-option"
              disabled={busy || (assistant && (!rate || !perUsd))}
              onClick={() => void pick(amt)}
            >
              {busy && 'amountUsd' in step && step.amountUsd === amt
                ? step.kind === 'redirecting'
                  ? 'Opening checkout…'
                  : 'Checking…'
                : <><strong>{assistant ? `${rate && perUsd ? formatMinutes(minutesForCredits(amt * perUsd, rate)!) : '…'} min` : perUsd ? creditsText(amt * perUsd) : 'Top up'}</strong><span>${amt}</span></>}
            </button>
          ))}
        </div>
      )}
      {error && <p className="bb-agent-gate-error" role="alert">{assistant ? 'Top-up failed. Please try again.' : error}</p>}
    </div>
  )
}
