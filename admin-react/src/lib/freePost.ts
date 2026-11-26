/**
 * Pure decisions for the Free plan's monthly post on the Generate and Drafts sub tabs (no React), unit tested.
 *
 * The backend owns the rule (api lib/postLimit.ts): the number a month, which sources are free, the reset
 * day. This file only turns what GetPlanStatus says into what the screen shows. It never computes a date:
 * the reset day lives in the backend's own message.
 */

export interface PostPlanInput {
  plan: string | undefined
  accessMode?: string
  /** usage.postsThisMonth. */
  postsThisMonth: number
  /** limits.postsPerMonth (1 on Free). */
  postsPerMonth: number
  /** Sources a Free site may generate from, as the backend lists them. Missing (older backend) = none. */
  freePostSources?: readonly string[]
  /** The day the month's count resets, in the backend's words ("1 December"). Missing (older backend) = not shown. */
  monthResetsOn?: string
}

/** Free, and this month's post is not used yet. */
export function freePostAvailable(i: PostPlanInput): boolean {
  return i.plan === 'free' && i.postsPerMonth > 0 && i.postsThisMonth < i.postsPerMonth
}

/** The Generate button becomes the locked "open the upgrade" button. */
export function composerLocked(i: PostPlanInput & { sourceType: string }): boolean {
  if (i.accessMode === 'expired') return true
  if (i.plan !== 'free') return false
  return !(freePostAvailable(i) && (i.freePostSources ?? []).includes(i.sourceType))
}

/** True for the backend's "you've used your free post(s)" refusal, so the composer can show it as its line. */
export function isFreePostLimitMessage(message: string | null | undefined): boolean {
  return /^You've used your (\d+ )?free posts? /.test(message ?? '')
}

/** The one line above the source cards. */
export function composerLine(i: PostPlanInput & { refusal?: string | null }): string {
  if (i.plan !== 'free') return 'Each post counts against your monthly quota.'
  if (freePostAvailable(i)) {
    const left = i.postsPerMonth - i.postsThisMonth
    return i.postsPerMonth === 1 ? '1 free post this month.' : `${left} of ${i.postsPerMonth} free posts left this month.`
  }
  if (i.refusal && isFreePostLimitMessage(i.refusal)) return i.refusal
  const used = i.postsPerMonth === 1 ? "You've used your free post for this month." : "You've used your free posts for this month."
  if (!i.monthResetsOn) return used
  return `${used} ${i.postsPerMonth === 1 ? 'It resets' : 'They reset'} on ${i.monthResetsOn}.`
}

/**
 * Drafts sub tab on Free: shown when the site has a draft or a free post to make one with.
 * Paid plans always see it. Before the plan or the drafts have loaded it stays shown, so a deep
 * link is never bounced on a guess.
 */
export function showDraftsTab(i: PostPlanInput & { planKnown: boolean; draftsKnown: boolean; draftCount: number }): boolean {
  if (!i.planKnown || i.plan !== 'free') return true
  if (!i.draftsKnown) return true
  return i.draftCount > 0 || freePostAvailable(i)
}
