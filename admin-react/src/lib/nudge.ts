/** The invitation bubble ("nudge"): stored as a JSON string in one WP option. */
export interface NudgeConfig {
  enabled: boolean
  delaySeconds: number
  message: string
}

export const DEFAULT_NUDGE_DELAY = 8
export const DEFAULT_NUDGE_MESSAGE = "Need a hand? I'm here to help."

export function parseNudge(raw: string | null | undefined): NudgeConfig {
  let obj: Record<string, unknown> | null = null
  try {
    const parsed = raw ? JSON.parse(raw) : null
    obj = typeof parsed === 'object' && parsed !== null ? (parsed as Record<string, unknown>) : null
  } catch {
    obj = null
  }
  const delay = Number(obj?.delaySeconds ?? obj?.delay ?? DEFAULT_NUDGE_DELAY)
  return {
    enabled: Boolean(obj?.enabled),
    delaySeconds: Number.isFinite(delay) && delay > 0 ? delay : DEFAULT_NUDGE_DELAY,
    // `simpleText` is the canonical PHP/widget field name; `message` is accepted too.
    message: String(obj?.message ?? obj?.simpleText ?? ''),
  }
}

export function nudgeWith(current: string, patch: Partial<NudgeConfig>): string {
  return JSON.stringify({ ...parseNudge(current), ...patch })
}
