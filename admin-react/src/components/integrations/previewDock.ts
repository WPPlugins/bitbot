/**
 * Where the live preview dock sits inside the chat column, and how big the
 * page inside it is drawn. Pure: every input is a measured box, so the same
 * numbers can be tested without a browser.
 *
 * The chat column (`.bb-agent-main`) is the containing block. Whatever the
 * window, the WP admin menu or the frame's own size, the dock box returned here
 * lies inside that column and above the composer, and the page is scaled to fit
 * the dock (never wider or taller than the room it was given).
 */

export interface Size {
  readonly width: number
  readonly height: number
}

export type DockMode = 'compact' | 'expanded'

export interface DockInput {
  readonly mode: DockMode
  /** The chat column's padding box: the dock's containing block. */
  readonly chat: Size
  /** Height kept free at the bottom of the chat for the composer row (attachments, gate), gap included. */
  readonly composerSpace: number
  /** Height of the dock's own head and status rows. */
  readonly chrome: number
  /** The page's true viewport, before scaling (e.g. 390 x 844 for mobile). */
  readonly frame: Size
  /** The owner's preferred compact width. */
  readonly compactWidth: number
}

export interface DockBox {
  /** Dock border box, in px from the chat column's top-left corner. */
  readonly left: number
  readonly top: number
  readonly width: number
  readonly height: number
  /** Room the page is fitted into (inside the dock's border). */
  readonly stage: Size
  /** Visual scale of the page; its layout viewport stays `frame`. */
  readonly scale: number
  /** The page's drawn size: frame x scale. */
  readonly drawn: Size
  /** Padding the transcript needs so no message sits under the dock. */
  readonly reserve: { readonly right: number; readonly bottom: number }
}

/** Space between the dock and the chat column's edges. */
export const DOCK_GAP = 8
/** The dock's border, each side. */
export const DOCK_BORDER = 1
/** The compact dock is a thumbnail: its page area never grows past this height. */
export const COMPACT_MAX_STAGE_HEIGHT = 240
/** Narrowest transcript strip worth keeping beside the dock. */
export const MIN_TRANSCRIPT_WIDTH = 280
/** Narrowest expanded dock; below this the head buttons would wrap badly. */
export const MIN_EXPANDED_WIDTH = 480

const nonNegative = (n: number): number => (Number.isFinite(n) && n > 0 ? n : 0)

/** Largest scale (at most 1) at which the frame fits inside width x height. */
export function fitScale(room: Size, frame: Size): number {
  if (frame.width <= 0 || frame.height <= 0) return 0
  return nonNegative(Math.min(1, room.width / frame.width, room.height / frame.height))
}

function drawnSize(frame: Size, scale: number): Size {
  return { width: Math.floor(frame.width * scale), height: Math.floor(frame.height * scale) }
}

function compactBox(input: DockInput, availWidth: number, availHeight: number): DockBox {
  const width = Math.min(nonNegative(input.compactWidth), availWidth)
  const inner = nonNegative(width - 2 * DOCK_BORDER)
  const stageRoom = Math.min(COMPACT_MAX_STAGE_HEIGHT, nonNegative(availHeight - input.chrome - 2 * DOCK_BORDER))
  const scale = fitScale({ width: inner, height: stageRoom }, input.frame)
  const drawn = drawnSize(input.frame, scale)
  const height = Math.min(availHeight, input.chrome + drawn.height + 2 * DOCK_BORDER)
  const stage = { width: inner, height: nonNegative(height - input.chrome - 2 * DOCK_BORDER) }
  const left = input.chat.width - DOCK_GAP - width
  const top = Math.max(DOCK_GAP, input.chat.height - input.composerSpace - height)
  const besideFits = input.chat.width - width - 2 * DOCK_GAP >= MIN_TRANSCRIPT_WIDTH
  const reserve = besideFits
    ? { right: width + 2 * DOCK_GAP, bottom: 0 }
    : { right: 0, bottom: height + DOCK_GAP }
  return { left, top, width, height, stage, scale, drawn, reserve }
}

function expandedBox(input: DockInput, availWidth: number, availHeight: number): DockBox {
  const stageHeight = nonNegative(availHeight - input.chrome - 2 * DOCK_BORDER)
  // Keep a transcript strip (where Allow/Deny cards appear) whenever the chat
  // is wide enough for both; otherwise the dock may use the whole column.
  const stripLeft = availWidth - MIN_TRANSCRIPT_WIDTH - DOCK_GAP
  const maxWidth = stripLeft >= MIN_EXPANDED_WIDTH ? stripLeft : availWidth
  const scale = fitScale({ width: nonNegative(maxWidth - 2 * DOCK_BORDER), height: stageHeight }, input.frame)
  const drawn = drawnSize(input.frame, scale)
  const fitted = drawn.width + 2 * DOCK_BORDER
  const width = Math.min(maxWidth, Math.max(fitted, Math.min(MIN_EXPANDED_WIDTH, maxWidth)))
  const left = input.chat.width - DOCK_GAP - width
  const besideFits = input.chat.width - width - 2 * DOCK_GAP >= MIN_TRANSCRIPT_WIDTH
  return {
    left,
    top: DOCK_GAP,
    width,
    height: availHeight,
    stage: { width: nonNegative(width - 2 * DOCK_BORDER), height: stageHeight },
    scale,
    drawn,
    reserve: { right: besideFits ? width + 2 * DOCK_GAP : 0, bottom: 0 },
  }
}

/**
 * The dock's box inside the chat column. Compact: a thumbnail at the bottom
 * right, directly above the composer. Expanded: the full height between the
 * top of the chat and the composer, as wide as the fitted page needs (never
 * wider than the chat). Both keep the composer row entirely uncovered.
 */
export function computeDockBox(input: DockInput): DockBox {
  const availWidth = nonNegative(input.chat.width - 2 * DOCK_GAP)
  const availHeight = nonNegative(input.chat.height - nonNegative(input.composerSpace) - DOCK_GAP)
  return input.mode === 'expanded'
    ? expandedBox(input, availWidth, availHeight)
    : compactBox(input, availWidth, availHeight)
}

/**
 * Does this frame address show nothing at all? A frame with no address, or
 * the blank page a new chat leaves in the admin screen, paints an empty white
 * box. The dock says so in words instead of showing that box.
 */
export function isBlankFrameSrc(src: string | null | undefined): boolean {
  const value = (src ?? '').trim()
  return value === '' || value === 'about:blank'
}
