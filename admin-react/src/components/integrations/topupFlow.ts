/**
 * The top-up flow (the PAUSED card and the Account drawer's Add credits row), kept free of React and app imports so it can
 * be tested with node:test.
 *
 * On 5 Oct two $100 charges landed 5 s apart: the buttons charged on one click,
 * from plan data cached for 5 minutes. Now a click never charges:
 *   1. re-read plan status FRESH; a site with no credit pool stops here (a
 *      pool with credit left may still be topped up: the owner's click is
 *      allowed any time on a paid plan),
 *   2. ask the server which saved card would be charged (nothing is charged),
 *   3. show "Charge $20 to the card ending 4242 for 2,000 credits?" with
 *      Confirm / Cancel (usageLines topupConfirmText),
 *   4. only Confirm charges, carrying a nonce made once for that click.
 * With no saved card there is nothing to charge in one click, so the owner
 * goes to Stripe's Checkout page, which is its own confirm step.
 */

/** Shown when plan status has no credit pool for the site. */
export const NO_POOL_ERROR = 'This site has no credit pool to add to.'

export interface SavedCard {
  brand: string
  last4: string
}

export type TopupStep =
  | { kind: 'idle' }
  | { kind: 'checking'; amountUsd: number }
  | { kind: 'confirm'; amountUsd: number; card: SavedCard }
  | { kind: 'charging'; amountUsd: number }
  | { kind: 'redirecting'; amountUsd: number }
  /** The server's receipt: credits added and credits left after the charge. */
  | { kind: 'charged'; credits?: number; leftCredits?: number | null }

/** What CreateAgentTopup answers: a preview's card, a charge's receipt, or Checkout. */
export interface TopupReply {
  url?: string
  charged?: boolean
  card?: SavedCard | null
  credits?: number
  leftCredits?: number | null
}

export type TopupResult<T> = { success: true; data: T } | { success: false; error: string }

export interface TopupDeps {
  /** A FRESH plan-status read (never the cache). Returns the pool left, or
   *  null when the site has no agent pool. */
  readFreshLeftUsd: () => Promise<number | null>
  call: (payload: Record<string, unknown>) => Promise<TopupResult<TopupReply>>
  successUrl: string
  cancelUrl: string
  makeNonce: () => string
}

/**
 * What the owner reads when a top-up fails. Server codes are not sentences:
 * a plan code says the plan is short, anything else machine-shaped gets one
 * plain line. A message already written for people passes through.
 */
export const TOPUP_FAILED_TEXT = 'The top-up could not start. Please try again.'
export const TOPUP_PLAN_TEXT = 'Credits can be added on a paid plan. Upgrade to add credits.'

export function topupErrorText(raw: unknown): string {
  const text = typeof raw === 'string' ? raw.trim() : raw instanceof Error ? raw.message.trim() : ''
  if (!text) return TOPUP_FAILED_TEXT
  if (/^(PRO_PLUS|PAID_PLAN)_REQUIRED$/.test(text)) return TOPUP_PLAN_TEXT
  // A code: letters, digits and underscores only, or a thrown-error prefix.
  if (/^[A-Z0-9_]+$/.test(text) || /^(Error|TypeError|RpcError):/.test(text) || !/\s/.test(text)) return TOPUP_FAILED_TEXT
  return text
}

export type TopupOutcome =
  | { step: TopupStep; error?: undefined; redirectTo?: undefined }
  | { step: TopupStep; error: string; redirectTo?: undefined }
  | { step: TopupStep; redirectTo: string; error?: undefined }

/** Step 1-3: an amount was picked. Never charges. */
export async function chooseAmount(amountUsd: number, deps: TopupDeps): Promise<TopupOutcome> {
  let left: number | null
  try {
    left = await deps.readFreshLeftUsd()
  } catch (e) {
    return { step: { kind: 'idle' }, error: e instanceof Error ? e.message : String(e) }
  }
  // Credits can be added any time on a paid plan; only a site with no pool
  // at all (nothing to add to) stops here.
  if (left === null) return { step: { kind: 'idle' }, error: NO_POOL_ERROR }

  const preview = await deps.call({ amountUsd, preview: true })
  if (!preview.success) return { step: { kind: 'idle' }, error: preview.error }
  const card = preview.data?.card
  if (card) return { step: { kind: 'confirm', amountUsd, card } }
  return checkout(amountUsd, deps)
}

/** Step 4: the owner pressed Confirm. One nonce per call. */
export async function confirmCharge(amountUsd: number, deps: TopupDeps): Promise<TopupOutcome> {
  const r = await deps.call({
    amountUsd,
    nonce: deps.makeNonce(),
    successUrl: deps.successUrl,
    cancelUrl: deps.cancelUrl,
  })
  if (!r.success) return { step: { kind: 'idle' }, error: r.error }
  if (r.data?.charged) return { step: { kind: 'charged', credits: r.data.credits, leftCredits: r.data.leftCredits } }
  // The saved card could not be charged (expired, needs authentication):
  // the server answers with Checkout, where the owner pays in person.
  if (r.data?.url) return { step: { kind: 'redirecting', amountUsd }, redirectTo: r.data.url }
  return { step: { kind: 'idle' }, error: 'No checkout URL returned' }
}

async function checkout(amountUsd: number, deps: TopupDeps): Promise<TopupOutcome> {
  const r = await deps.call({ amountUsd, successUrl: deps.successUrl, cancelUrl: deps.cancelUrl })
  if (!r.success) return { step: { kind: 'idle' }, error: r.error }
  if (r.data?.url) return { step: { kind: 'redirecting', amountUsd }, redirectTo: r.data.url }
  return { step: { kind: 'idle' }, error: 'No checkout URL returned' }
}

/** A once-per-click nonce. randomUUID needs a secure context, and plenty of
 *  WordPress admins run on plain http, so getRandomValues is the source. */
export function makeNonce(): string {
  const bytes = new Uint8Array(16)
  globalThis.crypto.getRandomValues(bytes)
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
}
