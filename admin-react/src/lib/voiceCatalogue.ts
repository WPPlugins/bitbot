/** One greeting in one language: its audio and, when Habitat sends it, the words. */
export interface VoicePreview {
  url: string
  text?: string
}

export interface CatalogueVoice {
  id: string
  name: string
  gender: 'female' | 'male'
  description: string
  sample_url?: string | null
  /** Greetings by base language tag, when the catalogue was read with languages and Habitat had them ready. */
  previews?: Readonly<Record<string, VoicePreview>> | null
  /** The languages this voice is native in. Absent on the catalogue voices, which speak every language. */
  languages?: ReadonlyArray<string> | null
}

export interface VoiceCatalogue {
  voices: CatalogueVoice[]
  /** The site's stored voice; null = Habitat's default. */
  current: string | null
  defaultId: string
}

/** Never imply that a missing stored voice has been replaced by the default. */
export function voiceSelection(catalogue: VoiceCatalogue) {
  const id = catalogue.current ?? catalogue.defaultId
  return {
    selectedId: catalogue.voices.some((voice) => voice.id === id) ? id : null,
    isDefault: catalogue.current === null,
  }
}

/** The voice that speaks for the site today, or null when the stored one is not listed. */
export function currentVoice(catalogue: VoiceCatalogue): CatalogueVoice | null {
  const { selectedId } = voiceSelection(catalogue)
  return catalogue.voices.find((voice) => voice.id === selectedId) ?? null
}

/**
 * What the play button on a voice plays: the greeting in `language` when Habitat
 * has it, else the voice's own sample, else any greeting it has (a native speaker
 * greets only in its own languages and may have no sample).
 */
export interface Preview {
  url: string
  /** The language of what plays, or null when it is the voice's own sample. */
  language: string | null
  text?: string
}

export function previewFor(voice: CatalogueVoice, language: string | null): Preview | null {
  const asked = language ? voice.previews?.[language] : undefined
  if (asked) return { url: asked.url, language, ...(asked.text ? { text: asked.text } : {}) }
  if (voice.sample_url) return { url: voice.sample_url, language: null }
  const [code, any] = Object.entries(voice.previews ?? {})[0] ?? []
  return any ? { url: any.url, language: code, ...(any.text ? { text: any.text } : {}) } : null
}

/** A pick: the site's voice (null = back to Habitat's default) plus the language codes the picker listed it under. */
export interface VoicePick {
  voice: string | null
  languageHints: string[]
}

/** The SetVoiceCallOptions body: the hints travel with the voice so the server validates against the same list the owner saw. */
export function voicePayload(pick: VoicePick): { voice: string | null; languageHints: string[] } {
  return { voice: pick.voice, languageHints: Array.from(new Set(pick.languageHints)) }
}

/** The cache key part the catalogue query uses for a language set (see useVoiceCatalogue). */
export function catalogueLanguageKey(languages: ReadonlyArray<string>): string {
  return Array.from(new Set(languages)).join(',')
}

/** The picked voice's entry: the catalogue cached for the same language set first, then any other. */
export function findPickedVoice(
  entries: ReadonlyArray<{ languageKey: string; voices: ReadonlyArray<CatalogueVoice> | undefined }>,
  pick: VoicePick,
): CatalogueVoice | undefined {
  const key = catalogueLanguageKey(pick.languageHints)
  const ordered = [...entries.filter((e) => e.languageKey === key), ...entries.filter((e) => e.languageKey !== key)]
  for (const entry of ordered) {
    const found = entry.voices?.find((v) => v.id === pick.voice)
    if (found) return found
  }
  return undefined
}
