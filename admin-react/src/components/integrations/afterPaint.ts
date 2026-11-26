/**
 * Wait for the browser to draw `frames` times, but never longer than `maxMs`.
 *
 * A tab in the background gets NO animation frames at all. Measured 2026-10-07
 * on Chromium 147 with another tab in front or the window minimised: 0 paints
 * a second while nothing was shared (60 while sharing, because a shared tab is
 * kept drawn). An unbounded wait here hung the agent's look until the backend
 * gave up and reported a page that never loaded. With the cap, a look taken
 * from a background tab carries on: the layout is already current (it does not
 * wait for paint), so the measurements stay true.
 */
export function afterPaint(frames = 1, maxMs = 250): Promise<void> {
  return new Promise<void>((resolve) => {
    let done = false
    const finish = () => {
      if (done) return
      done = true
      resolve()
    }
    const timer = setTimeout(finish, maxMs)
    const step = (left: number) => {
      if (left <= 0) {
        clearTimeout(timer)
        finish()
        return
      }
      requestAnimationFrame(() => step(left - 1))
    }
    step(frames)
  })
}
