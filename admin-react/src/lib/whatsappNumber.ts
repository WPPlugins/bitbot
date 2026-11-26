/**
 * The WhatsApp number field. Same rule as the plugin (BitBot_WhatsApp::sanitize_number):
 * an international number, "+", a country code that does not start with 0,
 * then digits, 8 to 15 in all. Spaces, dashes, dots and brackets are fine to type.
 */
export type WhatsAppNumberCheck =
  | { ok: true; value: string }
  | { ok: false; error: string }

export function checkWhatsAppNumber(raw: string): WhatsAppNumberCheck {
  const typed = (raw ?? '').trim()
  if (typed === '') return { ok: true, value: '' }
  const value = typed.replace(/[\s\-.()]/g, '')
  if (!value.startsWith('+')) return { ok: false, error: 'Start with + and the country code, like +61 412 345 678.' }
  if (!/^\+[1-9][0-9]{7,14}$/.test(value)) return { ok: false, error: 'That does not look like a full phone number with its country code.' }
  return { ok: true, value }
}
