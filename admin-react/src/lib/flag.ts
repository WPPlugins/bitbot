/**
 * Country flag for a thread: the ISO 3166-1 alpha-2 code as regional
 * indicator symbols. Pure; null when there is no usable code.
 */
export function flagFor(code: string | null | undefined): string | null {
  if (!code || !/^[A-Za-z]{2}$/.test(code)) return null
  const base = 0x1f1e6
  const up = code.toUpperCase()
  return String.fromCodePoint(base + up.charCodeAt(0) - 65, base + up.charCodeAt(1) - 65)
}

/** Hover text for the flag: "Melbourne, Australia" or just the country. */
export function placeLabel(city: string | null | undefined, country: string | null | undefined): string {
  if (city && country) return `${city}, ${country}`
  return country ?? ''
}
