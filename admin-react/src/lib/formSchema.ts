/**
 * Pure helpers for the form builder: stable field keys, schema validation
 * (what blocks Save) and answer validation (the editor's own preview).
 * The public renderers require a `name` and an `email` field, so those two
 * are fixed rows the owner can relabel but not remove.
 */
export type FieldType = 'text' | 'email' | 'phone' | 'textarea' | 'select' | 'radio' | 'checkbox'

export interface SchemaField {
  name: string
  label: string
  type: FieldType
  required: boolean
  options?: string[]
  placeholder?: string
}

export const LOCKED_FIELD_KEYS: readonly string[] = ['name', 'email']
export const CHOICE_TYPES: ReadonlySet<FieldType> = new Set<FieldType>(['select', 'radio', 'checkbox'])

const KEY_MAX = 40
/** Same rule as the public form (assets/js/widget-form.js EMAIL_RE). */
export const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/

export function isLockedField(f: { name: string }): boolean {
  return LOCKED_FIELD_KEYS.includes(f.name)
}

export function isChoiceType(type: FieldType): boolean {
  return CHOICE_TYPES.has(type)
}

function slug(label: string): string {
  return label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, KEY_MAX) || 'field'
}

/** A key from the label that is not in `taken` (suffix _2, _3 ... on collision). */
export function fieldKeyFromLabel(label: string, taken: Iterable<string>): string {
  const used = new Set(taken)
  const base = slug(label)
  if (!used.has(base)) return base
  let n = 2
  while (used.has(`${base}_${n}`)) n++
  return `${base}_${n}`
}

/** Fill a blank key from the label, once. A key that already exists is never touched. */
export function withStableKey<T extends { name: string; label: string }>(field: T, all: ReadonlyArray<{ name: string }>): T {
  if (field.name.trim() || !field.label.trim()) return field
  return { ...field, name: fieldKeyFromLabel(field.label, all.map((f) => f.name)) }
}

/** Non-empty, trimmed, de-duplicated options. */
export function cleanOptions(options: ReadonlyArray<string> | undefined): string[] {
  return Array.from(new Set((options ?? []).map((o) => o.trim()).filter(Boolean)))
}

/** Everything that must be fixed before the form can be saved. Empty list = valid. */
export function validateSchema(name: string, fields: ReadonlyArray<SchemaField>): string[] {
  const problems: string[] = []
  if (!name.trim()) problems.push('Give the form a name.')
  for (const key of LOCKED_FIELD_KEYS) {
    if (!fields.some((f) => f.name === key)) problems.push(`The form needs ${key === 'email' ? 'an' : 'a'} ${key} field.`)
  }
  const seen = new Set<string>()
  fields.forEach((f, i) => {
    const where = f.label.trim() ? `"${f.label.trim()}"` : `Field ${i + 1}`
    if (!f.label.trim()) problems.push(`Field ${i + 1} needs a label.`)
    const key = f.name.trim()
    if (key) {
      if (seen.has(key)) problems.push(`${where} shares a key with another field.`)
      seen.add(key)
    }
    if (isChoiceType(f.type) && cleanOptions(f.options).length === 0) {
      problems.push(`${where} needs at least one option.`)
    }
  })
  return problems
}

/** Final shape sent to the API: keys filled, options cleaned, locked rows kept required. */
export function normalizeFields(fields: ReadonlyArray<SchemaField>): SchemaField[] {
  const out: SchemaField[] = []
  for (const f of fields) {
    const keyed = withStableKey({ ...f, label: f.label.trim() }, out.concat(fields.filter((x) => x.name)))
    out.push({
      ...keyed,
      required: isLockedField(keyed) ? true : keyed.required,
      options: isChoiceType(keyed.type) ? cleanOptions(keyed.options) : undefined,
    })
  }
  return out
}

/** The public form's messages (assets/js/widget-form.js COPY). */
export const ANSWER_ERRORS = {
  name: 'Please enter your name.',
  email: 'Please enter a valid email address.',
  required: 'This field is required.',
  choose: 'Please choose an option.',
  chooseAny: 'Please choose at least one option.',
} as const

function hasValue(v: string | string[] | undefined): boolean {
  return Array.isArray(v) ? v.length > 0 : String(v ?? '').trim() !== ''
}

/**
 * The public form's validate(), ported rule for rule so "Check answers" in the
 * editor behaves like the real form. Returns { key: message }.
 */
export function validateAnswers(
  fields: ReadonlyArray<SchemaField>,
  answers: Readonly<Record<string, string | string[]>>,
): Record<string, string> {
  const errors: Record<string, string> = {}
  for (const f of fields) {
    const key = f.name || f.label
    const v = answers[key]
    if (f.name === 'name' && !hasValue(v)) { errors[key] = ANSWER_ERRORS.name; continue }
    if (f.name === 'email' || f.type === 'email') {
      const s = String(v ?? '').trim()
      if (s === '' ? f.required : !EMAIL_RE.test(s)) errors[key] = ANSWER_ERRORS.email
      continue
    }
    if (f.required && !hasValue(v)) {
      errors[key] = f.type === 'checkbox' ? ANSWER_ERRORS.chooseAny
        : (f.type === 'radio' || f.type === 'select') ? ANSWER_ERRORS.choose
        : ANSWER_ERRORS.required
    }
  }
  return errors
}
