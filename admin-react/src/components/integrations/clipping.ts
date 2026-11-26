/**
 * CONTENT CUT OFF AT THE SCREEN'S EDGE, as a measurement.
 *
 * Round 3 E3 (2026-10-02, Hello Elementor at 390px): the library sections sat
 * at x=-70 and their left edge was cut off, yet the look said
 * `overflows: false`. Left overflow never scrolls, and the theme sets
 * `overflow-x: clip` on the body, so the scrollWidth check saw nothing.
 *
 * This is the pure decision; previewController walks the DOM and hands it the
 * boxes. No DOM access here, so the API's tests run it directly.
 */

/** One element's box as the walk read it. */
export interface ClipCandidate {
  /** Document order index. */
  readonly key: number
  /** The nearest candidate ancestor's key, or null. */
  readonly parentKey: number | null
  /** `div.wp-block-group`, `section#hero` */
  readonly selector: string
  readonly left: number
  readonly right: number
  readonly width: number
  readonly height: number
  /**
   * Out of the question for reasons the walk knows: not painted, fixed to the
   * viewport, or held inside an ancestor that clips it on purpose (a slider
   * track inside a box that sits on the screen).
   */
  readonly skip: boolean
}

export interface Clipped {
  readonly selector: string
  readonly left: number
  readonly right: number
}

/** The most clipped elements one look reports. */
export const CLIPPED_MAX = 5

/** Past this many pixels beyond an edge, an element counts as cut off. */
export const CLIP_TOLERANCE_PX = 1

/**
 * The elements cut off by the screen's left or right edge: partly on the
 * screen and partly past it. An element wholly off the screen is put there on
 * purpose (a screen-reader label, an off-canvas menu), and a hairline is not
 * content. Only the outermost clipped element of a branch is named; its
 * children are cut off because it is.
 */
export function findClipped(items: ReadonlyArray<ClipCandidate>, innerWidth: number, cap: number = CLIPPED_MAX): Clipped[] {
  const reported = new Set<number>()
  const parentOf = new Map(items.map((i) => [i.key, i.parentKey] as const))
  const underReported = (key: number): boolean => {
    let p = parentOf.get(key) ?? null
    while (p !== null) {
      if (reported.has(p)) return true
      p = parentOf.get(p) ?? null
    }
    return false
  }
  const out: Clipped[] = []
  for (const i of items) {
    if (out.length >= cap) break
    if (i.skip || i.width <= 2 || i.height <= 2) continue
    const onScreen = i.right > 0 && i.left < innerWidth
    const cut = i.left < -CLIP_TOLERANCE_PX || i.right > innerWidth + CLIP_TOLERANCE_PX
    if (!onScreen || !cut || underReported(i.key)) continue
    reported.add(i.key)
    out.push({ selector: i.selector, left: Math.round(i.left), right: Math.round(i.right) })
  }
  return out
}
