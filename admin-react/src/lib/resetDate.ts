/**
 * The credit reset date as the owner reads it: "3 Nov", in their browser's
 * time zone and locale. The PAUSED card and the Usage panel both use this.
 *
 * The chat's "used up" reply is written on the server, so every chat send
 * carries this browser's zone and locale (ownerDateContext) and the server
 * formats with the SAME options (packages/api lib/agentCredit.ts
 * RESET_DATE_OPTIONS). Change one, change both. The date passed in is
 * agentCredit.money.resetAt (the renewal date when it matches the window),
 * which the server's chat reply also uses.
 */
export const RESET_DATE_OPTIONS = { day: 'numeric', month: 'short' } as const

export function formatResetDate(iso: string): string {
  return new Date(iso).toLocaleDateString(undefined, RESET_DATE_OPTIONS)
}

/** This browser's time zone and locale, for the server to date replies with. */
export function ownerDateContext(): { timeZone?: string; locale?: string } {
  try {
    const { timeZone, locale } = Intl.DateTimeFormat().resolvedOptions()
    return { timeZone, locale }
  } catch {
    return {}
  }
}
