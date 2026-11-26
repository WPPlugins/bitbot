import type { SightCapture } from './previewSight'

/**
 * SIGHT FOR THE ADMIN SCREEN, 2026-10-07 owner direction: the agent's work must
 * not depend on which panel the owner has open or which tab they are on.
 *
 * Only one thing is ever required, and only for a command that changes the
 * site: the owner has shared this tab ("Let me see" is on). A drawer over the
 * dock, a minimized dock or another tab in front never stops anything. Reads
 * need nothing at all. Whether this look came with a picture is data on the
 * result (`sight.captured`, `sight.reason`), never a reason to stop.
 */
export class AdminSightRequired extends Error {
  constructor(reason = 'Screen sharing is off.') {
    super(`${reason} Click “Let me see” in the preview and share this Youami tab, then ask me to continue. I will not change anything in the editor without live sight.`)
  }
}

/** Before a write is dispatched (and again after an approval wait): sharing must be on. */
export function requireSharingForWrite(live: () => boolean, changesSite: boolean): void {
  if (changesSite && !live()) throw new AdminSightRequired()
}

/** One picture for this look, or the reason there is none. Never throws, never stops the command. */
export async function observeAdminSight(live: () => boolean, capture: () => Promise<SightCapture>): Promise<SightCapture> {
  if (!live()) return { captured: false, reason: 'screen sharing is off, so this look is measurements only' }
  let shot: SightCapture
  try { shot = await capture() }
  catch (e) { return { captured: false, reason: `the picture could not be taken (${e instanceof Error ? e.message : 'unknown error'}), so this look is measurements only` } }
  if (shot.captured && shot.base64 && live()) return shot
  const { base64: _b, mediaType: _m, ...rest } = shot
  return { ...rest, captured: false, reason: shot.reason || (live() ? 'no picture came back on this look; the measurements are current' : 'screen sharing ended during this look, so it is measurements only') }
}
