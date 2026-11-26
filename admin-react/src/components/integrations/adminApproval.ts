/** The owner's Allow / Deny card for one admin write.
 *
 * A card on screen waits for the owner. It does not expire while this tab is
 * open and holding it, up to the backend's safety ceiling (approvalTimeoutMs on
 * the command). While it waits the panel re-acknowledges the command, so the
 * backend can tell a slow owner from a closed tab. Mirrors the backend's
 * lib/writeDecision; the sentences are the same words.
 */
export type OwnerDecision = 'allow' | 'deny' | 'lapsed' | 'cancelled'

/** A backend older than the ceiling field expected the old 90 s card. */
export const LEGACY_APPROVAL_MS = 90_000
const MIN_APPROVAL_MS = 30_000
const MAX_APPROVAL_MS = 15 * 60_000
/** Re-acknowledge a held command this often; the backend treats 45 s of silence as a closed tab. */
export const APPROVAL_HEARTBEAT_MS = 15_000

export const DENIED_SENTENCE = 'You pressed Deny, so nothing was done.'
export const LAPSED_SENTENCE = 'The Allow / Deny card was not answered, so nothing was done.'

export function approvalCeiling(raw: unknown): number {
  return typeof raw === 'number' && Number.isFinite(raw) && raw >= MIN_APPROVAL_MS && raw <= MAX_APPROVAL_MS ? raw : LEGACY_APPROVAL_MS
}

/** Wait for the owner. `register` hands the panel the function its buttons call. */
export function waitForOwnerDecision(ceilingMs: number, register: (answer: ((decision: OwnerDecision) => void) | null) => void): Promise<OwnerDecision> {
  return new Promise(resolve => {
    const timer = setTimeout(() => { register(null); resolve('lapsed') }, ceilingMs)
    register(decision => { clearTimeout(timer); register(null); resolve(decision) })
  })
}

/** The final result for a write the owner did not allow. Nothing ran in any of these cases. */
export function unapprovedResult(decision: Exclude<OwnerDecision, 'allow'>): Record<string, unknown> {
  if (decision === 'deny') return { ok: false, approved: false, notRun: true, denied: true, outcome: 'denied', error: DENIED_SENTENCE }
  return { ok: false, approved: false, notRun: true, denied: false, outcome: 'approval_lapsed', error: LAPSED_SENTENCE }
}
