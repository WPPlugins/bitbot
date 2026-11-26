/**
 * Plays one audio sample at a time. `toggle` with the key that is playing
 * stops it; with another key it switches. Failures surface as a toast.
 */
import { useCallback, useEffect, useRef, useState } from 'react'
import { toast } from '@/components/ui/Toast'

export function useSamplePlayer() {
  const audio = useRef<HTMLAudioElement | null>(null)
  const [playing, setPlaying] = useState<string | null>(null)

  const stop = useCallback(() => {
    if (audio.current) {
      audio.current.pause()
      audio.current.onended = null
      audio.current.onerror = null
      audio.current = null
    }
    setPlaying(null)
  }, [])

  useEffect(() => () => { audio.current?.pause(); audio.current = null }, [])

  const toggle = useCallback((key: string, url: string) => {
    const wasPlaying = playing === key
    stop()
    if (wasPlaying) return
    const sample = new Audio(url)
    audio.current = sample
    setPlaying(key)
    sample.onended = () => { if (audio.current === sample) stop() }
    const failed = () => {
      if (audio.current !== sample) return
      stop()
      toast.error('Could not play preview')
    }
    sample.onerror = failed
    void sample.play().catch(failed)
  }, [playing, stop])

  return { playing, toggle, stop }
}
