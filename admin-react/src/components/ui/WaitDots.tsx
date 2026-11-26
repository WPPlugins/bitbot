/**
 * WaitDots — three pulsing dots that take the place of a typed "…" at the end
 * of a waiting line ("Drafting an answer", "Reading your pages"). Decorative:
 * the sentence carries the meaning, so the dots are hidden from screen readers.
 * Use on standalone waiting lines, not inside button labels.
 */
export function WaitDots() {
  return (
    <span className="bb-wait-dots" aria-hidden="true">
      <span /><span /><span />
    </span>
  )
}
