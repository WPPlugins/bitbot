/**
 * useTopup — the one React driver of the top-up flow (topupFlow.ts), shared by
 * the PAUSED card (AgentTopupGate) and the Account drawer's Add credits row.
 * A click never charges: pick an amount, see the saved card, press Confirm.
 */
import { useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'
import { toast } from '@/components/ui/Toast'
import { fetchFreshPlanStatus, PLAN_STATUS_KEY } from '@/hooks/usePlanStatus'
import { topupReceipt } from './usageLines'
import { chooseAmount, confirmCharge, makeNonce, type TopupDeps, type TopupOutcome, type TopupReply, type TopupStep, topupErrorText } from './topupFlow'

export function useTopup(receipt: typeof topupReceipt = topupReceipt) {
  const qc = useQueryClient()
  const [step, setStep] = useState<TopupStep>({ kind: 'idle' })
  const [error, setError] = useState<string | null>(null)
  // A second click can land before React re-renders the disabled buttons.
  const inFlight = useRef(false)

  const deps = (): TopupDeps => {
    // No hash in the success URL: Stripe appends the session parameter to
    // it, and a query after a hash never reaches the server. tab=integrations
    // routes back to the Agent surface on its own.
    const base = `${window.location.origin}${window.location.pathname}${window.location.search}`
    return {
      readFreshLeftUsd: async () => (await fetchFreshPlanStatus(qc)).agentCredit?.leftUsd ?? null,
      call: (payload) => rpc<TopupReply>('CreateAgentTopup', payload),
      successUrl: base,
      cancelUrl: `${base}${window.location.hash}`,
      makeNonce,
    }
  }

  const run = async (busyStep: TopupStep, action: () => Promise<TopupOutcome>) => {
    if (inFlight.current) return
    inFlight.current = true
    setStep(busyStep)
    setError(null)
    try {
      const out = await action()
      if (out.redirectTo) {
        window.location.href = out.redirectTo
        return
      }
      setStep(out.step)
      if (out.error) setError(topupErrorText(out.error))
      // Charged: say the receipt (it outlives a card that unmounts when the
      // refreshed plan status lifts the gate), then refresh plan status now.
      if (out.step.kind === 'charged') {
        toast.success(receipt(out.step.credits, out.step.leftCredits))
        void qc.invalidateQueries({ queryKey: PLAN_STATUS_KEY, refetchType: 'all' })
      }
    } catch (e) {
      setStep({ kind: 'idle' })
      setError(topupErrorText(e))
    } finally {
      inFlight.current = false
    }
  }

  return {
    step,
    error,
    busy: step.kind === 'checking' || step.kind === 'charging' || step.kind === 'redirecting',
    pick: (amountUsd: number) => run({ kind: 'checking', amountUsd }, () => chooseAmount(amountUsd, deps())),
    confirm: (amountUsd: number) => run({ kind: 'charging', amountUsd }, () => confirmCharge(amountUsd, deps())),
    cancel: () => setStep({ kind: 'idle' }),
  }
}
