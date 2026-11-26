import type { MouseEvent } from 'react'
import type { Preview } from '@/lib/voiceCatalogue'

interface VoicePlayButtonProps {
  name: string
  preview: Preview | null
  playing: boolean
  onToggle: (url: string) => void
  size?: 'row' | 'summary'
}

/** Round play/stop control. Plays the greeting text as its tooltip when Habitat sends one. */
export function VoicePlayButton({ name, preview, playing, onToggle, size = 'row' }: VoicePlayButtonProps) {
  const onClick = (e: MouseEvent<HTMLButtonElement>) => {
    e.stopPropagation()
    if (preview) onToggle(preview.url)
  }
  return (
    <button
      type="button"
      className={`bb-voice-play bb-voice-play--${size}${playing ? ' is-playing' : ''}`}
      disabled={!preview}
      aria-label={`${playing ? 'Stop' : 'Play'} ${name}`}
      aria-pressed={playing}
      title={preview?.text}
      onClick={onClick}
    >
      <span className="bb-voice-ring" aria-hidden="true" />
      <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true" fill="currentColor">
        {playing ? <rect x="2" y="2" width="8" height="8" rx="1.5" /> : <path d="M3 1.5v9l7.5-4.5z" />}
      </svg>
    </button>
  )
}
