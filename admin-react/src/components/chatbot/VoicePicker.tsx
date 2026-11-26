/**
 * The voice setting under the Voice calls switch: the one voice that speaks for
 * the site. Every voice speaks every language in its own accent; the list is
 * Habitat's catalogue plus the native speakers of the languages the site offers.
 */
import { useIsMutating } from '@tanstack/react-query'
import type { AdminSettings } from '@/api/types'
import { useCurrentPlan } from '@/hooks/useCurrentPlan'
import { useLanguageList } from '@/hooks/useLanguageList'
import { useSamplePlayer } from '@/hooks/useSamplePlayer'
import { isAgent, isPaid } from '@/lib/config'
import { currentVoice } from '@/lib/voiceCatalogue'
import { SET_VOICE_KEY, useSetVoice, useVoiceCatalogue } from '@/hooks/useVoiceCalls'
import { VoiceRow } from './VoiceRow'
import './voice-picker.css'

export function VoicePicker({ settings }: { settings: AdminSettings }) {
  const plan = useCurrentPlan()
  const allowed = isPaid(plan)
  const list = useLanguageList(settings)
  const offered = list.rows.filter((row) => row.on).map((row) => row.code)
  const catalogue = useVoiceCatalogue(allowed, offered)
  const setVoice = useSetVoice()
  const saving = useIsMutating({ mutationKey: SET_VOICE_KEY }) > 0
  const player = useSamplePlayer()

  if (!allowed) return null
  if (catalogue.isPending) return <p role="status">Loading voices…</p>
  if (catalogue.isError) return <div role="alert">Could not load voices. <button type="button" onClick={() => void catalogue.refetch()}>Retry</button></div>
  if (!catalogue.data.voices.length) return <p role="status">No voices available</p>

  const data = catalogue.data
  return (
    <div className="bb-voice-rows">
      <VoiceRow
        language={list.site}
        voice={currentVoice(data)}
        options={data.voices}
        playing={player.playing}
        onToggle={player.toggle}
        onStop={player.stop}
        onPick={(pick) => setVoice.mutate({ ...pick, languageHints: offered })}
        tone={isAgent(plan) ? ' bb-voice--agent' : ''}
        saving={saving}
      />
    </div>
  )
}
