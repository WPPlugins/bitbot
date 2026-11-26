/**
 * ChipPicker — drag-sortable list of suggested-question chips. Each chip
 * pairs a short *label* (button text) with the *message* the user is
 * actually sending. Uses dnd-kit for reordering.
 *
 * Persists via the onChange callback — the parent owns state.
 */
import {
  DndContext,
  closestCenter,
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core'
import type { DragEndEvent } from '@dnd-kit/core'
import {
  SortableContext,
  arrayMove,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'

export interface Chip {
  label: string
  message: string
}

interface ChipPickerProps {
  chips: Chip[]
  onChange: (chips: Chip[]) => void
  maxChips?: number
}

function GripIcon() {
  return (
    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden>
      <circle cx="9" cy="6" r="1.5" /><circle cx="15" cy="6" r="1.5" />
      <circle cx="9" cy="12" r="1.5" /><circle cx="15" cy="12" r="1.5" />
      <circle cx="9" cy="18" r="1.5" /><circle cx="15" cy="18" r="1.5" />
    </svg>
  )
}

function TrashIcon() {
  return (
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden>
      <path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1.5 14a2 2 0 0 1-2 2h-7a2 2 0 0 1-2-2L5 6" />
    </svg>
  )
}

interface SortableChipProps {
  id: string
  chip: Chip
  onChange: (chip: Chip) => void
  onRemove: () => void
}

function SortableChip({ id, chip, onChange, onRemove }: SortableChipProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
    useSortable({ id })

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  }

  return (
    <li ref={setNodeRef} style={style} className="bb-chip-row">
      <button
        type="button"
        className="bb-chip-handle"
        {...attributes}
        {...listeners}
        aria-label="Drag to reorder"
      >
        <GripIcon />
      </button>
      <input
        className="bb-input bb-chip-label"
        value={chip.label}
        onChange={(e) => onChange({ ...chip, label: e.target.value })}
        placeholder="Button label"
        aria-label="Chip label"
      />
      <input
        className="bb-input bb-chip-message"
        value={chip.message}
        onChange={(e) => onChange({ ...chip, message: e.target.value })}
        placeholder="Message sent when clicked"
        aria-label="Chip message"
      />
      <button
        type="button"
        className="bb-chip-remove"
        onClick={onRemove}
        aria-label="Remove chip"
      >
        <TrashIcon />
      </button>
    </li>
  )
}

export function ChipPicker({ chips, onChange, maxChips = 6 }: ChipPickerProps) {
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }))

  const handleDragEnd = (e: DragEndEvent) => {
    const { active, over } = e
    if (!over || active.id === over.id) return
    const oldIndex = Number(active.id)
    const newIndex = Number(over.id)
    onChange(arrayMove(chips, oldIndex, newIndex))
  }

  const updateChip = (index: number, chip: Chip) => {
    const next = [...chips]
    next[index] = chip
    onChange(next)
  }
  const removeChip = (index: number) => onChange(chips.filter((_, i) => i !== index))
  const addChip = () => onChange([...chips, { label: '', message: '' }])

  const ids = chips.map((_, i) => String(i))

  return (
    <div className="bb-chips">
      <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
        <SortableContext items={ids} strategy={verticalListSortingStrategy}>
          <ul className="bb-chip-list">
            {chips.map((chip, i) => (
              <SortableChip
                key={i}
                id={String(i)}
                chip={chip}
                onChange={(c) => updateChip(i, c)}
                onRemove={() => removeChip(i)}
              />
            ))}
          </ul>
        </SortableContext>
      </DndContext>
      {chips.length < maxChips && (
        <button type="button" className="bb-chip-add" onClick={addChip}>
          + Add chip
        </button>
      )}
    </div>
  )
}
