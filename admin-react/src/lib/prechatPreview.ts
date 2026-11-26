/**
 * Pure helpers for the pre-chat form gate in Chatbot > Widget: the one-line
 * field summary under the switch and the gate card the preview draws.
 * The defaults mirror the widget's own (assets/js/widget.js renderGate).
 */
export interface GateFieldInput {
  name: string
  label: string
  required: boolean
  placeholder?: string
  type?: string
  options?: string[]
}

export interface GateFormInput {
  name: string
  fields: GateFieldInput[]
}

/** How the gate draws a field: a one-line box, a tall box, a select, or an option list. */
export type GateShape = 'input' | 'textarea' | 'select' | 'radio' | 'checkbox'

export interface GateRow {
  name: string
  label: string
  required: boolean
  placeholder: string
  shape: GateShape
  /** HTML input type for the 'input' shape (text, email, tel). */
  inputType: 'text' | 'email' | 'tel'
  options: string[]
}

export interface GateCardModel {
  heading: string
  rows: GateRow[]
  submitLabel: string
  backLabel: string
  pendingLabel: string
}

/** The form's fixed text in one language, as the plugin sends it (BitBot_Widget_Copy::gate_copy). */
export interface GateCopy { heading: string; pendingLabel: string; submit: string; back: string }

/** The copy for the previewed language, else the site language's, else none (English constants apply). */
export function gateCopyFor(all: Record<string, GateCopy> | undefined, ...codes: Array<string | undefined>): GateCopy | null {
  for (const code of codes) {
    const hit = code ? all?.[code.slice(0, 2).toLowerCase()] : undefined
    if (hit) return hit
  }
  return null
}

export const GATE_DEFAULT_HEADING = 'A few details first'
/** The widget's own gate copy (assets/js/widget.js GATE_COPY). */
export const GATE_SUBMIT_LABEL = 'Continue'
export const GATE_BACK_LABEL = 'Back'
export const GATE_PENDING_LABEL = 'Your message, sent once you share your details'
export const GATE_SELECT_PLACEHOLDER = 'Select...'
export const GATE_EXAMPLE_MESSAGE = 'Hi, do you ship overseas?'

const WIDGET_DEFAULT_FIELDS: GateRow[] = [
  { name: 'name', label: 'Full name', required: true, placeholder: 'Your name', shape: 'input', inputType: 'text', options: [] },
  { name: 'email', label: 'Email', required: true, placeholder: 'you@example.com', shape: 'input', inputType: 'email', options: [] },
]

function toRow(f: GateFieldInput): GateRow {
  const type = f.type ?? 'text'
  const shape: GateShape = type === 'textarea' || type === 'select' || type === 'radio' || type === 'checkbox' ? type : 'input'
  const inputType = type === 'email' || f.name === 'email' ? 'email' : type === 'phone' ? 'tel' : 'text'
  return {
    name: f.name,
    label: f.label,
    // Like the widget: name and email are always required.
    required: !!f.required || f.name === 'name' || f.name === 'email',
    placeholder: shape === 'select' ? f.placeholder || GATE_SELECT_PLACEHOLDER : f.placeholder ?? '',
    shape,
    inputType,
    options: (f.options ?? []).filter((o) => o.trim() !== ''),
  }
}

/** "Name, Email · 2 fields" (empty string when there are no fields). */
export function prechatSummary(fields: ReadonlyArray<{ label: string }>): string {
  if (fields.length === 0) return ''
  const noun = fields.length === 1 ? 'field' : 'fields'
  return `${fields.map((f) => f.label).join(', ')} · ${fields.length} ${noun}`
}

/** The gate card as the widget draws it: form name heading, one row per field. */
export function gateCardModel(form: GateFormInput | null | undefined, copy?: GateCopy | null): GateCardModel {
  // The visitor never sees the form's admin name; the gate shows one neutral line.
  const heading = GATE_DEFAULT_HEADING
  const fields = form?.fields ?? []
  const rows: GateRow[] = fields.length ? fields.map(toRow) : WIDGET_DEFAULT_FIELDS
  return {
    heading: copy?.heading || heading,
    rows,
    submitLabel: copy?.submit || GATE_SUBMIT_LABEL,
    backLabel: copy?.back || GATE_BACK_LABEL,
    pendingLabel: copy?.pendingLabel || GATE_PENDING_LABEL,
  }
}

/** Row label as shown: required fields carry " *". */
export function gateRowLabel(row: Pick<GateRow, 'label' | 'required'>): string {
  return row.required ? `${row.label} *` : row.label
}
