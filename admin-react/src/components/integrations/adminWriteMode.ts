/** The owner's Manual / Auto choice for admin writes.
 *
 * It belongs to the owner until the owner changes it: code never flips it, and a
 * new chat keeps it. Stored per site in this browser's localStorage (the same
 * store the panel already uses for command receipts). Storage that is blocked or
 * empty falls back to Manual, the safe default.
 */
export type WriteMode = 'auto' | 'manual'

type ReadableStorage = Pick<Storage, 'getItem'>
type WritableStorage = Pick<Storage, 'setItem'>

export const writeModeKey = (siteUrl: string): string => `bitbot-admin-write-mode:${siteUrl}`

export function readWriteMode(storage: ReadableStorage | null | undefined, key: string): WriteMode {
  try {
    return storage?.getItem(key) === 'auto' ? 'auto' : 'manual'
  } catch {
    return 'manual'
  }
}

export function saveWriteMode(storage: WritableStorage | null | undefined, key: string, mode: WriteMode): void {
  try {
    storage?.setItem(key, mode)
  } catch {
    // Blocked storage only means the choice is not remembered after a reload.
  }
}

/** Reads, scrolls and known read-only screens never ask; every other action asks in Manual. */
export function needsWriteCard(mode: WriteMode, needsApproval: boolean): boolean {
  return needsApproval && mode !== 'auto'
}

export function browserStorage(): Storage | null {
  try {
    return typeof window !== 'undefined' ? window.localStorage : null
  } catch {
    return null
  }
}
