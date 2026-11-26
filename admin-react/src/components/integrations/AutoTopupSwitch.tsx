/**
 * AutoTopupSwitch: the owner's automatic top-up. One switch, and the amount
 * picked from the same choices as a manual purchase. The monthly cap stays
 * whatever the account already has (the server keeps enforcing it); it is not
 * a decision the owner is asked to make here. Each change saves at once
 * through SetAgentCapability.
 */
import { useId } from 'react'
import type { AutoTopupStatus } from '@/api/types'
import { useSaveAutoTopup, type AutoTopupSettings } from '@/hooks/useAgentCapabilities'
import { creditsText } from './usageLines'

import { formatMinutes, minutesForCredits } from '../billing/minuteUsage'

const DEFAULT_MONTHLY_CAP = 1

export function AutoTopupSwitch({ status, perUsd, plan = 'pro_plus', rate = null }: { status: AutoTopupStatus; perUsd: number; plan?: string; rate?: number | null }) {
  const save = useSaveAutoTopup()
  const id = useId()
  // While a save is in flight, show what was asked for, not the old value.
  const shown: AutoTopupSettings = save.isPending && save.variables ? save.variables : status
  const monthlyCap = shown.monthlyCap > 0 ? shown.monthlyCap : DEFAULT_MONTHLY_CAP
  const apply = (patch: Partial<AutoTopupSettings>) =>
    save.mutate({ enabled: shown.enabled, credits: shown.credits, monthlyCap, ...patch })
  const options = status.optionsCredits.includes(shown.credits)
    ? status.optionsCredits
    : [...status.optionsCredits, shown.credits]
  const assistant = plan === 'pro'
  const amountText = (c: number) =>
    assistant ? `${rate ? formatMinutes(minutesForCredits(c, rate)!) : '…'} min` : creditsText(c)

  return (
    <section className="bb-usage-auto" aria-labelledby={`${id}-label`}>
      <label className="bb-usage-auto-switch">
        <input
          type="checkbox"
          role="switch"
          checked={shown.enabled}
          disabled={save.isPending}
          onChange={(e) => apply({ enabled: e.target.checked })}
        />
        <span className="bb-usage-auto-track" aria-hidden>
          <span className="bb-usage-auto-thumb" />
        </span>
        <span id={`${id}-label`} className="bb-usage-auto-label">
          {assistant ? 'Top up automatically when minutes run low' : 'Top up automatically when credits run low'}
        </span>
      </label>
      <div className={`bb-auto-choices${shown.enabled ? '' : ' bb-auto-choices-off'}`} role="radiogroup" aria-label="Amount to top up">
        {options.map((c) => (
          <button
            key={c}
            type="button"
            role="radio"
            aria-checked={shown.credits === c}
            className={`bb-auto-choice${shown.credits === c ? ' bb-auto-choice-on' : ''}`}
            disabled={save.isPending}
            onClick={() => apply({ credits: c, enabled: true })}
          >
            <strong>{amountText(c)}</strong>
            <span>${Math.round((c / perUsd) * 100) / 100}</span>
          </button>
        ))}
      </div>
      {save.isPending && <p className="bb-usage-note" role="status">Saving…</p>}
      {save.isError && <p className="bb-agent-gate-error" role="alert">Could not save. Please try again.</p>}
    </section>
  )
}
