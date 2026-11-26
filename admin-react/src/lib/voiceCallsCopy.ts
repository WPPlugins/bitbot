/**
 * The note under the "Voice calls" switch: only a status when calls cannot be
 * placed. Pure, so a test can hold it: the owner is never asked for a key or
 * an account of any kind, and an available feature carries no explainer.
 */
export interface VoiceCallsHelpInput {
  /** The server can place calls at all. */
  available: boolean
}

export function voiceCallsHelp({ available }: VoiceCallsHelpInput): string | undefined {
  if (!available) return 'Voice calls are not available yet. Please try again later.'
  return undefined
}
