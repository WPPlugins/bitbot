/**
 * Per-field character limits and the inline counter, shared by the Branding
 * and Conversation cards.
 *
 * Per-field character limits. Picked to keep the rendered widget tidy:
 * - Title sits in a header bar, ~24 chars fits comfortably on the
 *   narrowest 320px mobile width.
 * - Welcome lives in a chat bubble, three short lines max.
 * - Placeholder is a single inline hint.
 *
 * Enforced both with the native `maxLength` attribute (hard stop) and
 * a visible counter under the field.
 */
export const LIMITS = {
  widgetTitle: 30,
  welcomeMessage: 240,
  placeholderText: 50,
} as const

/** Inline counter shown under length-constrained fields. */
export function CharCounter({ value, max }: { value: string; max: number }) {
  const len = (value ?? '').length
  const overage = len > max
  // Highlight when the user is within 10% of the limit so they get
  // a heads-up before the input refuses keystrokes.
  const near = !overage && len >= max - Math.max(5, Math.round(max * 0.1))
  return (
    <span
      className={`bb-field-counter ${overage ? 'bb-field-counter-over' : near ? 'bb-field-counter-near' : ''}`}
      aria-live="polite"
    >
      {len} / {max}
    </span>
  )
}
