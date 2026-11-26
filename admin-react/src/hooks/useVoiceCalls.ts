/**
 * Voice calls (Habitat voice session) — settings hooks.
 *
 * The on/off switch rides the existing /admin-settings REST route
 * (`voiceCallsEnabled`); the plugin pushes it to the backend, which refuses to
 * turn calls on on the Free plan. There is no key to enter: one Habitat
 * partner key lives on the backend, so GetVoiceCalls reports only `available`.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { rpc, restPost } from '@/api/rpc'
import { getConfig } from '@/lib/config'
import type { VoiceCatalogue, VoicePick } from '@/lib/voiceCatalogue'
import { findPickedVoice, voicePayload } from '@/lib/voiceCatalogue'

export type { VoicePick }
import { toast } from '@/components/ui/Toast'

const DEV_MOCK_KEY = 'bb_devmockapikey0000000000000000'
const STATUS_KEY = ['voice-calls'] as const

export interface VoiceCallsStatus {
  enabled: boolean
  /** The server can place calls at all (its partner key is set). */
  available: boolean
  /** Credits one minute of a call costs (the site's own rate), when known. */
  creditsPerMinute?: number
  voice: string | null
  gender: string | null
  languageHints: string[]
  /** The languages the site offers for voice, computed by the server (the authoritative rule). */
  offeredLanguages?: string[]
}

const DEV_MOCK: VoiceCallsStatus = { enabled: false, available: true, voice: null, gender: null, languageHints: [] }

export function useVoiceCalls() {
  const isDev = getConfig().apiKey === DEV_MOCK_KEY
  return useQuery({
    queryKey: STATUS_KEY,
    queryFn: async (): Promise<VoiceCallsStatus> => {
      if (isDev) return { ...DEV_MOCK }
      const res = await rpc<VoiceCallsStatus>('GetVoiceCalls')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    staleTime: 30_000,
  })
}

/** Save the languages the site offers (the site language is added by the server). */
export const SET_LANGUAGES_KEY = ['set-voice-languages'] as const

export function useSetVoiceLanguages() {
  const qc = useQueryClient()
  const isDev = getConfig().apiKey === DEV_MOCK_KEY
  return useMutation({
    mutationKey: SET_LANGUAGES_KEY,
    // The list changes at once; the save and translation carry on behind it.
    onMutate: async (codes: string[]) => {
      await qc.cancelQueries({ queryKey: STATUS_KEY })
      const previous = qc.getQueryData<VoiceCallsStatus>(STATUS_KEY)
      if (previous) qc.setQueryData<VoiceCallsStatus>(STATUS_KEY, { ...previous, languageHints: codes, offeredLanguages: codes })
      return { previous }
    },
    mutationFn: async (codes: string[]) => {
      if (isDev) {
        DEV_MOCK.languageHints = codes.length > 1 ? codes : []
        return { codes, translationError: undefined }
      }
      const res = await restPost<{ success: boolean; translationError?: string }>('/admin-settings', { voiceLanguages: codes })
      if (!res.success) throw new Error(res.error)
      return { codes, translationError: res.data.translationError }
    },
    onSuccess: (done) => {
      toast.success('Languages saved')
      if (done && typeof done === 'object' && 'translationError' in done && done.translationError) {
        toast.error('Translations could not be updated')
      }
    },
    onError: (err, _codes, ctx) => {
      if (ctx?.previous) qc.setQueryData(STATUS_KEY, ctx.previous)
      toast.error(err instanceof Error ? err.message : 'Could not save languages')
    },
    onSettled: () => {
      // Only the last response refreshes, so it cannot overwrite a newer click.
      if (qc.isMutating({ mutationKey: SET_LANGUAGES_KEY }) === 1) {
        qc.invalidateQueries({ queryKey: STATUS_KEY })
        qc.invalidateQueries({ queryKey: ['admin-settings'] })
      }
    },
  })
}

export const SET_VOICE_CALLS_KEY = ['set-voice-calls'] as const

export function useSetVoiceCalls() {
  const qc = useQueryClient()
  const isDev = getConfig().apiKey === DEV_MOCK_KEY
  return useMutation({
    mutationKey: SET_VOICE_CALLS_KEY,
    // The switch flips at once; the save (a backend hop) runs behind it.
    onMutate: async (enabled: boolean) => {
      await qc.cancelQueries({ queryKey: STATUS_KEY })
      const previous = qc.getQueryData<VoiceCallsStatus>(STATUS_KEY)
      if (previous) qc.setQueryData<VoiceCallsStatus>(STATUS_KEY, { ...previous, enabled })
      return { previous }
    },
    mutationFn: async (enabled: boolean) => {
      if (isDev) {
        DEV_MOCK.enabled = enabled
        return enabled
      }
      const res = await restPost<{ success: boolean }>('/admin-settings', { voiceCallsEnabled: enabled })
      if (!res.success) throw new Error(res.error)
      return enabled
    },
    onSuccess: (enabled) => {
      toast.success(enabled ? 'Voice calls turned on' : 'Voice calls turned off')
    },
    onError: (err, _enabled, ctx) => {
      if (ctx?.previous) qc.setQueryData(STATUS_KEY, ctx.previous)
      toast.error(err instanceof Error ? err.message : 'Could not update voice calls')
    },
    onSettled: () => {
      // Only the last response refreshes, so it cannot overwrite a newer click.
      if (qc.isMutating({ mutationKey: SET_VOICE_CALLS_KEY }) === 1) {
        qc.invalidateQueries({ queryKey: STATUS_KEY })
        qc.invalidateQueries({ queryKey: ['admin-settings'] })
      }
    },
  })
}

export const VOICE_CATALOGUE_KEY = ['voice-catalogue'] as const
export const SET_VOICE_KEY = ['set-voice'] as const

/** `languages`: the codes the site offers; Habitat adds each voice's greeting in them for the previews. */
export function useVoiceCatalogue(enabled = true, languages: ReadonlyArray<string> = []) {
  const wanted = Array.from(new Set(languages))
  return useQuery({
    queryKey: [...VOICE_CATALOGUE_KEY, wanted.join(',')],
    enabled,
    queryFn: async (): Promise<VoiceCatalogue> => {
      const res = await rpc<VoiceCatalogue>('GetVoiceCatalogue', wanted.length ? { languages: wanted } : {})
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    staleTime: 30_000,
  })
}

export function useSetVoice() {
  const qc = useQueryClient()
  return useMutation({
    mutationKey: SET_VOICE_KEY,
    onMutate: async (pick: VoicePick) => {
      await Promise.all([
        qc.cancelQueries({ queryKey: VOICE_CATALOGUE_KEY }),
        qc.cancelQueries({ queryKey: STATUS_KEY }),
      ])
      // One catalogue per language set is cached; the choice is the same in all of them.
      const previous = qc.getQueriesData<VoiceCatalogue>({ queryKey: VOICE_CATALOGUE_KEY })
      const status = qc.getQueryData<VoiceCallsStatus>(STATUS_KEY)
      qc.setQueriesData<VoiceCatalogue>({ queryKey: VOICE_CATALOGUE_KEY }, (entry) => entry && { ...entry, current: pick.voice })
      const picked = findPickedVoice(previous.map(([key, data]) => ({ languageKey: String(key[1] ?? ''), voices: data?.voices })), pick)
      if (status) qc.setQueryData(STATUS_KEY, { ...status, voice: pick.voice, gender: picked?.gender ?? null })
      return { previous, status }
    },
    mutationFn: async (pick: VoicePick) => {
      const res = await rpc('SetVoiceCallOptions', voicePayload(pick))
      if (!res.success) throw new Error(res.error)
    },
    onError: (err, _pick, ctx) => {
      for (const [key, data] of ctx?.previous ?? []) qc.setQueryData(key, data)
      // Restore only our fields: a language or switch save may also be in flight.
      if (ctx?.status) qc.setQueryData<VoiceCallsStatus>(STATUS_KEY, (current) => current && ({
        ...current, voice: ctx.status!.voice, gender: ctx.status!.gender,
      }))
      toast.error(err instanceof Error ? err.message : 'Could not save voice')
    },
    onSettled: () => {
      if (qc.isMutating({ mutationKey: SET_VOICE_KEY }) === 1) {
        return Promise.all([
          qc.invalidateQueries({ queryKey: VOICE_CATALOGUE_KEY }),
          qc.invalidateQueries({ queryKey: STATUS_KEY }),
        ])
      }
    },
  })
}
