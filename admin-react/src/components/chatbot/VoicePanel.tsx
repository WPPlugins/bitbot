/**
 * The voice list that opens from "Change": every voice with its own play
 * control (its greeting in the site language when Habitat has it, else its sample).
 * Anchored under the trigger in a portal; Escape, outside click and a pick close it.
 */
import { useEffect, useLayoutEffect, useRef, useState, type CSSProperties, type KeyboardEvent, type RefObject } from 'react'
import { createPortal } from 'react-dom'
import { previewFor, type CatalogueVoice } from '@/lib/voiceCatalogue'
import { VoicePlayButton } from './VoicePlayButton'

const PANEL_WIDTH = 340
const PANEL_GAP = 6
const VIEWPORT_PAD = 12
const MIN_BELOW = 300

interface VoicePanelProps {
  title: string
  options: CatalogueVoice[]
  /** The site language: each voice's greeting in it plays when Habitat has it. */
  language: string
  chosenId: string | null
  playing: string | null
  onToggle: (key: string, url: string) => void
  onPick: (id: string) => void
  onClose: () => void
  anchor: RefObject<HTMLElement | null>
  tone: string
  saving: boolean
}

function placement(anchor: HTMLElement): CSSProperties {
  const rect = anchor.getBoundingClientRect()
  const vw = window.visualViewport?.width ?? window.innerWidth
  const vh = window.visualViewport?.height ?? window.innerHeight
  const width = Math.min(PANEL_WIDTH, vw - VIEWPORT_PAD * 2)
  const left = Math.max(VIEWPORT_PAD, Math.min(rect.right - width, vw - width - VIEWPORT_PAD))
  const below = vh - rect.bottom - PANEL_GAP - VIEWPORT_PAD
  const above = rect.top - PANEL_GAP - VIEWPORT_PAD
  if (below < MIN_BELOW && above > below) {
    return { left, width, bottom: vh - rect.top + PANEL_GAP, maxHeight: above }
  }
  return { left, width, top: rect.bottom + PANEL_GAP, maxHeight: below }
}

export function VoicePanel({ title, options, language, chosenId, playing, onToggle, onPick, onClose, anchor, tone, saving }: VoicePanelProps) {
  const panel = useRef<HTMLDivElement>(null)
  const [style, setStyle] = useState<CSSProperties>({})

  useLayoutEffect(() => {
    const place = () => { if (anchor.current) setStyle(placement(anchor.current)) }
    place()
    // The panel grows when the catalogue refetches (a Hear in row appears): place again on its own size changes.
    const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(place) : null
    if (panel.current && observer) observer.observe(panel.current)
    window.addEventListener('resize', place)
    window.visualViewport?.addEventListener('resize', place)
    window.addEventListener('scroll', place, true)
    return () => {
      observer?.disconnect()
      window.removeEventListener('resize', place)
      window.visualViewport?.removeEventListener('resize', place)
      window.removeEventListener('scroll', place, true)
    }
  }, [anchor])

  useEffect(() => {
    const selected = panel.current?.querySelector<HTMLButtonElement>('.bb-voice-pick[aria-checked="true"]')
    ;(selected ?? panel.current?.querySelector<HTMLButtonElement>('.bb-voice-pick'))?.focus()
    const onPointer = (e: PointerEvent) => {
      const target = e.target as Node
      if (panel.current?.contains(target) || anchor.current?.contains(target)) return
      onClose()
    }
    const onKey = (e: globalThis.KeyboardEvent) => { if (e.key === 'Escape') onClose() }
    document.addEventListener('pointerdown', onPointer)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('pointerdown', onPointer)
      document.removeEventListener('keydown', onKey)
    }
  }, [anchor, onClose])

  function onListKey(e: KeyboardEvent<HTMLUListElement>) {
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(e.key)) return
    const picks = Array.from(e.currentTarget.querySelectorAll<HTMLButtonElement>('.bb-voice-pick'))
    const at = picks.findIndex((el) => el === document.activeElement || el.contains(document.activeElement))
    const next = e.key === 'Home' ? 0 : e.key === 'End' ? picks.length - 1 : (at + (e.key === 'ArrowDown' ? 1 : -1) + picks.length) % picks.length
    e.preventDefault()
    picks[next]?.focus()
  }

  return createPortal(
    <div ref={panel} className={`bb-portal-scope bb-voice-panel${tone}`} style={style} role="dialog" aria-label="Voice">
      <div className="bb-voice-panel-head">
        <span className="bb-voice-panel-title">{title}</span>
      </div>
      <ul className="bb-voice-list" role="radiogroup" aria-label="Voices" onKeyDown={onListKey}>
        {options.map((voice) => {
          const selected = voice.id === chosenId
          const preview = previewFor(voice, language)
          // One key per panel row: the same voice on the row behind must not light up too.
          const key = `panel|${voice.id}`
          return (
            <li key={voice.id} className={`bb-voice-row${selected ? ' is-selected' : ''}`}>
              <VoicePlayButton name={voice.name} preview={preview} playing={playing === key} onToggle={(url) => onToggle(key, url)} />
              <button type="button" role="radio" aria-checked={selected} className="bb-voice-pick" disabled={saving} onClick={() => onPick(voice.id)}>
                <span className="bb-voice-name">{voice.name}<span className="bb-voice-gender" aria-label={voice.gender}>{voice.gender === 'female' ? '♀' : '♂'}</span></span>
                <span className="bb-voice-desc">{voice.description}</span>
              </button>
              <span className="bb-voice-mark">{selected && <Check />}</span>
            </li>
          )
        })}
      </ul>
    </div>,
    document.body,
  )
}

function Check() {
  return (
    <svg className="bb-voice-check" width="18" height="18" viewBox="0 0 18 18" aria-hidden="true">
      <circle cx="9" cy="9" r="9" fill="currentColor" />
      <path d="M5 9.2l2.6 2.6L13 6.6" fill="none" stroke="#fff" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  )
}
