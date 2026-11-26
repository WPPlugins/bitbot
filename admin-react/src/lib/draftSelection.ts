/**
 * Which draft the Drafts sub tab shows on the right (no React), unit tested.
 *
 * The owner's pick is shown for as long as it is not known to be gone. "Not in the loaded rows" is NOT
 * "gone": the list is paged, and a refetch can push the picked draft onto a page that is not loaded yet.
 * A draft is gone only when the owner deleted it here or its own read failed (goneIds). With no pick,
 * the newest draft that is not gone (the first such row) is shown, so the detail side is never an
 * empty "pick one" card and a deleted draft hands over to the next.
 */
export function shownDraftId(
  drafts: readonly { id: string }[],
  pickedId: string | null,
  goneIds: ReadonlySet<string> = new Set(),
): string | null {
  if (pickedId && !goneIds.has(pickedId)) return pickedId
  return drafts.find((d) => !goneIds.has(d.id))?.id ?? null
}

/**
 * The owner's pick and the drafts deleted in this visit. Held together in ONE place (the Knowledgebase
 * tab, which outlives the Drafts sub tab), so leaving Drafts and coming back cannot bring a deleted pick back.
 */
export interface DraftPick {
  pickedId: string | null
  goneIds: ReadonlySet<string>
}

export const NO_DRAFT_PICK: DraftPick = { pickedId: null, goneIds: new Set() }

export function pickDraft(state: DraftPick, id: string): DraftPick {
  return { ...state, pickedId: id }
}

/** A draft was deleted here: it is never shown again, and a pick that pointed at it is dropped. */
export function draftDeleted(state: DraftPick, id: string): DraftPick {
  return { pickedId: state.pickedId === id ? null : state.pickedId, goneIds: new Set(state.goneIds).add(id) }
}
