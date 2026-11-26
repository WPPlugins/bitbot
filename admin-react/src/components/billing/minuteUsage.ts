/** Plan-aware display maths. Rates always come from plan status. */
export interface MinuteInput {
  creditsPerMinute?: number
  voice?: { minutes: number; creditsPerMinute: number; pendingCredits?: number }
  money?: { credits?: { pool: number; left: number } }
}
export function minuteRate(credit: MinuteInput): number | null {
  const rate = credit.creditsPerMinute ?? credit.voice?.creditsPerMinute
  return rate != null && Number.isFinite(rate) && rate > 0 ? rate : null
}
export function minutesForCredits(credits: number, rate: number | null): number | null {
  return rate && Number.isFinite(credits) ? Math.floor(Math.max(0, credits) / rate) : null
}
export function formatMinutes(n: number, locale?: string): string {
  return new Intl.NumberFormat(locale, { maximumFractionDigits: 1 }).format(Math.max(0, n))
}
export function minuteUsage(credit: MinuteInput) {
  const rate = minuteRate(credit)
  const pool = credit.money?.credits
  if (!rate || !pool) return null
  return {
    used: Math.max(0, credit.voice?.minutes ?? 0),
    funded: minutesForCredits(pool.pool, rate)!,
    left: minutesForCredits(pool.left, rate)!,
    pending: minutesForCredits(credit.voice?.pendingCredits ?? 0, rate)!,
  }
}
export function minuteReceipt(added: number | null | undefined, left: number | null | undefined, rate: number | null): string {
  if (added == null || !rate) return 'Minutes added'
  const remaining = left == null ? '' : ` · ${formatMinutes(minutesForCredits(left, rate)!)} min left`
  return `${formatMinutes(minutesForCredits(added, rate)!)} min added${remaining}`
}

/** What a credit amount buys in calls, for the Agent cards: "50 min of calls", or null without a rate. */
export function callMinutesLine(credits: number, rate: number | null, locale?: string): string | null {
  const minutes = minutesForCredits(credits, rate)
  if (minutes == null || minutes <= 0) return null
  return `${formatMinutes(minutes, locale)} min of calls`
}
