/**
 * The voice line: the voice that speaks for the site, a play control (its
 * greeting in the site language when Habitat has it, else its sample) and
 * "Change", which opens the list of every voice.
 */
import { useCallback, useRef, useState } from 'react'
import type { CatalogueVoice } from '@/lib/voiceCatalogue'
import { previewFor } from '@/lib/voiceCatalogue'
import type { VoicePick } from '@/hooks/useVoiceCalls'
import { VoicePanel } from './VoicePanel'
import { VoicePlayButton } from './VoicePlayButton'

interface VoiceRowProps {
  /** The site language (base tag): greetings in it play. */
  language: string
  /** The voice that speaks for the site today. */
  voice: CatalogueVoice | null
  /** Every voice the site may pick. */
  options: CatalogueVoice[]
  playing: string | null
  onToggle: (key: string, url: string) => void
  onPick: (pick: Pick<VoicePick, 'voice'>) => void
  onStop: () => void
  tone: string
  saving: boolean
}

export function VoiceRow({ language, voice, options, playing, onToggle, onPick, onStop, tone, saving }: VoiceRowProps) {
  const trigger = useRef<HTMLButtonElement>(null)
  const [open, setOpen] = useState(false)
  const close = useCallback(() => { setOpen(false); trigger.current?.focus() }, [])
  const preview = voice ? previewFor(voice, language) : null
  // The row's own key: the same voice in the open list must not light this button too.
  const playKey = `row|${voice?.id ?? ''}`
  const pick = (next: string) => { onStop(); onPick({ voice: next }); close() }

  return (
    <div className={`bb-voice${tone}`} aria-busy={saving}>
      <div className="bb-voice-summary">
        <div className="bb-voice-line">
          <span className="bb-voice-label">Voice</span>
          <span className="bb-voice-name">{voice?.name ?? 'Voice unavailable'}</span>
          {voice && <span className="bb-voice-gender" aria-label={voice.gender}>{voice.gender === 'female' ? '♀' : '♂'}</span>}
          {voice?.description && <span className="bb-voice-desc" title={voice.description}>{voice.description}</span>}
        </div>
      </div>
      <VoicePlayButton name={voice?.name ?? 'voice'} preview={preview} playing={playing === playKey} onToggle={(url) => onToggle(playKey, url)} size="summary" />
      <button ref={trigger} type="button" className="bb-voice-change" disabled={saving} aria-haspopup="dialog" aria-expanded={open} onClick={() => setOpen((v) => !v)}>
        Change
      </button>
      {open && (
        <VoicePanel
          title="Voice"
          options={options}
          language={language}
          chosenId={voice?.id ?? null}
          playing={playing}
          onToggle={onToggle}
          onPick={pick}
          onClose={close}
          anchor={trigger}
          tone={tone.replace('bb-voice--agent', 'bb-voice-panel--agent')}
          saving={saving}
        />
      )}
    </div>
  )
}
