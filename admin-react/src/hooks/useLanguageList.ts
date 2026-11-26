/**
 * The languages the site offers, shared by the language list, the editor and
 * the preview. Languages do not depend on the plan: only the voice-call
 * switch is gated.
 */
import { useIsMutating } from '@tanstack/react-query'
import { useVoiceCalls, useSetVoiceLanguages, SET_LANGUAGES_KEY } from '@/hooks/useVoiceCalls'
import { getConfig } from '@/lib/config'
import { listedSelection, siteLanguageCode, toggleLanguage, type LanguageOption } from '@/lib/voiceLanguages'
import { languageRows } from '@/lib/languageState'
import { rowFor } from '@/lib/widgetCopy'
import type { AdminSettings } from '@/api/types'
import languages from '../../../assets/languages.json'

export const CATALOGUE: LanguageOption[] = languages

export function describeLanguage(code: string): LanguageOption {
  return CATALOGUE.find((l) => l.code === code) ?? { code, name: code, native: code }
}

export function useLanguageList(settings: AdminSettings) {
  const { data, isLoading } = useVoiceCalls()
  const setLanguages = useSetVoiceLanguages()
  const translating = useIsMutating({ mutationKey: SET_LANGUAGES_KEY }) > 0
  const site = siteLanguageCode(getConfig().locale)
  const detected = getConfig().siteLanguages ?? []
  // The server's offeredLanguages is authoritative; the local rule only fills in before it loads.
  const selected = listedSelection(data?.offeredLanguages, data?.languageHints, detected, site, CATALOGUE)
  const copy = Object.fromEntries(
    Object.keys(settings.widgetCopy ?? {}).map((code) => [code, rowFor(settings.widgetCopy, code)]),
  )
  return {
    site,
    detected,
    selected,
    rows: languageRows(selected, detected, site, copy, translating),
    /** Loading, or a save and translation still running. Never disables the buttons. */
    busy: isLoading || translating,
    toggle: (code: string) => setLanguages.mutate(toggleLanguage(selected, code, site)),
  }
}
