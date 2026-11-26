/** Pill chat button height: the setting range and the preview size. */
export const PILL_HEIGHT_MIN = 28
export const PILL_HEIGHT_MAX = 56

/** Clamp to the allowed range; anything unusable falls back to the minimum. */
export function clampPillHeight(value: unknown): number {
  const n = Math.round(Number(value))
  if (!Number.isFinite(n)) return PILL_HEIGHT_MIN
  return Math.min(PILL_HEIGHT_MAX, Math.max(PILL_HEIGHT_MIN, n))
}

/** Size of the pill in the scaled preview (36/56 of the real button).
 *  `w` is the minimum width (real min-width is 112); the real width follows
 *  the label. `avatar` is the diameter of the separate avatar circle (the pill height) and `font` the label size. */
export function previewPillSize(pillHeight: number): { w: number; h: number; avatar: number; font: number } {
  const real = clampPillHeight(pillHeight)
  const scale = 36 / 56
  return {
    w: Math.round(112 * scale),
    h: Math.round(real * scale),
    avatar: Math.round(real * scale),
    font: Math.round((real <= 28 ? 13 : 14) * scale),
  }
}
