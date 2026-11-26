/**
 * A realistic mobile number example for the site's country, so the WhatsApp
 * number help matches the owner's locale. Pure: input is the WordPress site
 * locale ("en_IN", "de_DE", "el"), output a dial code and an example.
 */
export interface PhoneExample {
  code: string
  example: string
}

const BY_REGION: Record<string, PhoneExample> = {
  IN: { code: '+91', example: '+91 98765 43210' },
  US: { code: '+1', example: '+1 212 555 0123' },
  CA: { code: '+1', example: '+1 416 555 0123' },
  GB: { code: '+44', example: '+44 7400 123456' },
  AU: { code: '+61', example: '+61 412 345 678' },
  NZ: { code: '+64', example: '+64 21 123 4567' },
  DE: { code: '+49', example: '+49 151 23456789' },
  FR: { code: '+33', example: '+33 6 12 34 56 78' },
  ES: { code: '+34', example: '+34 612 34 56 78' },
  IT: { code: '+39', example: '+39 312 345 6789' },
  GR: { code: '+30', example: '+30 691 234 5678' },
  NL: { code: '+31', example: '+31 6 12345678' },
  PT: { code: '+351', example: '+351 912 345 678' },
  BR: { code: '+55', example: '+55 11 91234 5678' },
  MX: { code: '+52', example: '+52 55 1234 5678' },
  AE: { code: '+971', example: '+971 50 123 4567' },
  SA: { code: '+966', example: '+966 50 123 4567' },
  SG: { code: '+65', example: '+65 9123 4567' },
  ZA: { code: '+27', example: '+27 82 123 4567' },
  PK: { code: '+92', example: '+92 300 1234567' },
  NG: { code: '+234', example: '+234 802 123 4567' },
  PH: { code: '+63', example: '+63 917 123 4567' },
  ID: { code: '+62', example: '+62 812 3456 7890' },
  JP: { code: '+81', example: '+81 90 1234 5678' },
  KR: { code: '+82', example: '+82 10 1234 5678' },
  TR: { code: '+90', example: '+90 532 123 4567' },
  IE: { code: '+353', example: '+353 85 123 4567' },
}

// A bare language with no region: the country most likely meant.
const BY_LANGUAGE: Record<string, string> = {
  el: 'GR', hi: 'IN', ja: 'JP', de: 'DE', fr: 'FR', es: 'ES', it: 'IT',
  pt: 'BR', ar: 'AE', en: 'SG', nl: 'NL', tr: 'TR', ko: 'KR', id: 'ID',
}

const FALLBACK = 'SG'

export function phoneExample(locale: string | undefined): PhoneExample {
  const parts = (locale ?? '').trim().replace('-', '_').split('_')
  const language = (parts[0] ?? '').toLowerCase()
  const region = (parts[1] ?? '').toUpperCase()
  if (region && BY_REGION[region]) return BY_REGION[region]
  const fromLanguage = BY_LANGUAGE[language]
  return BY_REGION[fromLanguage ?? FALLBACK] ?? BY_REGION[FALLBACK]
}
