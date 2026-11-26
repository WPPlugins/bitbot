/**
 * Which tools the preview's chat header shows, decided the way the real widget
 * decides (widget.js): the language pill when the site offers more than one
 * language, the Call button when voice calls are on, the WhatsApp badge when
 * the switch is on and the number is a real one (the caller checks the number
 * with checkWhatsAppNumber, the same rule the field uses). Pure, so a test can
 * hold it.
 */
export interface PreviewHeaderInput {
  voiceOn: boolean
  whatsappOn: boolean
  /** The saved number passed checkWhatsAppNumber and is not empty. */
  whatsappNumberValid: boolean
  languageCount: number
}

export interface PreviewHeaderTools {
  lang: boolean
  call: boolean
  whatsapp: boolean
}

export function previewHeaderTools(input: PreviewHeaderInput): PreviewHeaderTools {
  return {
    lang: input.languageCount > 1,
    call: input.voiceOn,
    whatsapp: input.whatsappOn && input.whatsappNumberValid,
  }
}
