/**
 * The Usage panel's lines, kept free of React and path aliases so node tests
 * can run them (tests/usageLines.test.ts).
 *
 * The owner reads credits: whole numbers with thousands separators. Dollars
 * appear only where money changes hands (the top-up buttons, the confirm step
 * and the receipt). The backend sends the pool in agentCredit.money.credits
 * and the rate in agentCredit.creditsPerUsd; this file never holds its own
 * copy of the rate. An older backend sends neither, and the panel then keeps
 * its percentages without figures.
 */

export interface CreditsInput {
  periodEnd: string
  creditsPerUsd?: number
  money?: {
    resetAt: string
    credits?: {
      pool: number
      used: number
      left: number
      included: number
      purchased: number
      dailyShare: number
      last24h: number
    }
  }
}

export interface UsageCredits {
  pool: number
  used: number
  left: number
  included: number
  purchased: number
  dailyShare: number
  last24h: number
  /** Credits per retail dollar, from the backend. */
  perUsd: number
  resetAt: string
}

/** The pool in credits, or null when the backend has not sent credits yet. */
export function usageCredits(credit: CreditsInput): UsageCredits | null {
  const c = credit.money?.credits
  const perUsd = credit.creditsPerUsd
  if (!c || !perUsd || !Number.isFinite(perUsd) || perUsd <= 0) return null
  return { ...c, left: Math.max(0, c.left), perUsd, resetAt: credit.money?.resetAt ?? credit.periodEnd }
}

/** The reset date to show, with or without the credits block. */
export function resetIso(credit: CreditsInput): string {
  return credit.money?.resetAt ?? credit.periodEnd
}

/** "4,900": whole credits in the owner's locale. */
export function formatCredits(n: number, locale?: string): string {
  return new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(Number.isFinite(n) ? Math.round(n) : 0)
}

/** "1 credit", "4,900 credits". */
export function creditsText(n: number, locale?: string): string {
  return `${formatCredits(n, locale)} ${Math.round(n) === 1 ? 'credit' : 'credits'}`
}

/** Retail dollars as whole credits, at the backend's rate. */
export function usdToCredits(usd: number, perUsd: number): number {
  return Number.isFinite(usd) && Number.isFinite(perUsd) ? Math.round(usd * perUsd) : 0
}

/** The monthly bar's label: "Monthly credits 4,900". */
export function monthlyLabel(c: UsageCredits | null, locale?: string): string {
  return c ? `Monthly credits ${formatCredits(c.pool, locale)}` : 'Monthly credits'
}

/** "voice calls: 14 minutes, 560 credits": the inventory line and the tooltip tail. */
export function voiceText(minutes: number, credits: number, locale?: string): string {
  const m = Math.round(minutes * 100) / 100
  return `voice calls: ${new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(m)} ${m === 1 ? 'minute' : 'minutes'}, ${creditsText(credits, locale)}`
}

/** Credits held for calls in progress: pending, not yet used. They are counted as used when the call ends. */
export function pendingVoiceText(credits: number, locale?: string): string {
  return `${formatCredits(credits, locale)} credits held for calls in progress, not counted as used yet`
}

/** Under the monthly bar: used and left, the reset date, any topped-up credit, then voice calls when there were some. */
export function monthlyLines(
  c: UsageCredits | null,
  resets: string,
  locale?: string,
  voice?: { minutes: number; credits: number; pendingCredits?: number } | null
): string[] {
  if (!c) return [`Resets ${resets}`]
  const lines = [`Used ${formatCredits(c.used, locale)} · Left ${formatCredits(c.left, locale)}`]
  if (voice && voice.minutes > 0) lines.push(`Includes ${voiceText(voice.minutes, voice.credits, locale)}`)
  if (voice && voice.pendingCredits && voice.pendingCredits > 0) lines.push(pendingVoiceText(voice.pendingCredits, locale))
  lines.push(`Resets ${resets}`)
  if (c.purchased > 0) {
    lines.push(`Includes ${formatCredits(c.purchased, locale)} topped-up credits, which carry over to next month.`)
  }
  return lines
}

/** Under the pace bar. Without the figures it still says what the bar means. */
export function paceLine(c: UsageCredits | null, locale?: string, plan: string = 'pro_plus'): string {
  const rule = plan === 'pro_plus' ? 'Only your monthly credits pause the agent.' : 'Only your monthly credits stop voice calls.'
  if (!c) return `The last 24 hours against an even daily share. ${rule}`
  return `${creditsText(c.last24h, locale)} used in the last 24 hours against an even daily share of ${formatCredits(c.dailyShare, locale)}. ${rule}`
}

/** The 30-day chart's scale: the busiest day in credits, or that nothing ran. */
export function historyScale(history: ReadonlyArray<{ spentUsd: number }>, perUsd: number | null, locale?: string): string {
  const max = Math.max(0, ...history.map((d) => (Number.isFinite(d.spentUsd) ? d.spentUsd : 0)))
  if (max <= 0) return 'No usage yet'
  return perUsd ? `Busiest day ${creditsText(usdToCredits(max, perUsd), locale)}` : 'Busiest day'
}

/** One chart bar's tooltip: "6 Oct: 1,377 credits, 166% of the daily pace". */
export function dayTooltip(
  dateLabel: string,
  spentUsd: number,
  capUsd: number,
  perUsd: number | null,
  locale?: string,
  voice?: { minutes?: number; usd?: number } | null
): string {
  // UNCLAMPED: a 500% day saying "100%" hides exactly what the chart shows.
  const pct = capUsd > 0 ? Math.round((spentUsd / capUsd) * 100) : 0
  const amount = perUsd ? `${creditsText(usdToCredits(spentUsd, perUsd), locale)}, ` : ''
  const calls =
    voice && voice.minutes && voice.minutes > 0 && perUsd
      ? `; ${voiceText(voice.minutes, usdToCredits(voice.usd ?? 0, perUsd), locale)}`
      : ''
  return `${dateLabel}: ${amount}${pct}% of the daily pace${calls}`
}

/** The Usage badge's tooltip. */
export function badgeTooltip(c: UsageCredits | null, locale?: string): string {
  return c
    ? `${formatCredits(c.used, locale)} of ${formatCredits(c.pool, locale)} credits used this month`
    : "Monthly credits and today's pace"
}

/** The panel's closing paragraph. */
export const USAGE_FINEPRINT =
  'One pool of credits covers everything the agent does: chats, background tasks, and scheduled runs. When the month\'s credits run out, the agent pauses until they reset or you top up. Your visitor chatbot keeps running as normal.'

/** A top-up button: "2,000 credits · $20". Dollars only because money changes hands. */
export function topupButtonText(amountUsd: number, perUsd: number | null, locale?: string): string {
  return perUsd ? `${creditsText(usdToCredits(amountUsd, perUsd), locale)} · $${amountUsd}` : `Top up $${amountUsd}`
}

/** The confirm question: "Charge $20 to the card ending 4242 for 2,000 credits?" */
export function topupConfirmText(amountUsd: number, last4: string, perUsd: number | null, locale?: string): string {
  const what = perUsd ? ` for ${creditsText(usdToCredits(amountUsd, perUsd), locale)}` : ''
  return `Charge $${amountUsd} to the card ending ${last4}${what}?`
}

/** The receipt after a charge: "2,000 credits added. 2,000 left." */
export function topupReceipt(credits: number | null | undefined, leftCredits: number | null | undefined, locale?: string): string {
  if (credits == null) return 'Topped up. Your card on file was charged and the agent is back.'
  const left = leftCredits == null ? '' : ` ${formatCredits(Math.max(0, leftCredits), locale)} left.`
  return `${creditsText(credits, locale)} added.${left}`
}

/** The automatic top-up's amount choice: "2,000 credits · $20". */
export function autoTopupOptionText(credits: number, perUsd: number, locale?: string): string {
  return `${creditsText(credits, locale)} · $${Math.round((credits / perUsd) * 100) / 100}`
}

/** Under the automatic top-up switch: how it pays and how many are used. */
export function autoTopupNote(a: { enabled: boolean; usedThisMonth: number; monthlyCap: number }, plan: string = 'pro_plus'): string {
  return a.enabled
    ? `Charged to your card on file. ${a.usedThisMonth} of ${a.monthlyCap} used this month.`
    : plan === 'pro_plus'
      ? 'Off. When your credits run out, the agent pauses until you top up or they reset.'
      : 'Off. When your credits run out, voice calls stop until you top up or they reset.'
}

/** "time" or "times" after the monthly cap. */
export function timesWord(n: number): string {
  return n === 1 ? 'time' : 'times'
}

/** The Account drawer's eyebrow under "Usage & credits": what the credits pay for. */
export function creditsEyebrow(plan: string): string {
  return plan === 'pro_plus' ? 'Credits pay for the co-pilot, tasks and calls' : 'Credits pay for voice calls'
}

/** The Add credits row's heading. */
export const ADD_CREDITS_LABEL = 'Add credits'

/** Voice minutes the pool funds, from the backend's rate. Null when the rate is unknown. */
export function voiceMinutesMeter(
  credits: { pool: number } | null | undefined,
  voice: { minutes: number; creditsPerMinute: number } | null | undefined
): { used: number; funded: number } | null {
  if (!credits || !voice) return null
  if (!Number.isFinite(voice.creditsPerMinute) || voice.creditsPerMinute <= 0) return null
  if (!Number.isFinite(credits.pool) || credits.pool <= 0) return null
  return { used: Math.round(voice.minutes), funded: Math.floor(credits.pool / voice.creditsPerMinute) }
}
