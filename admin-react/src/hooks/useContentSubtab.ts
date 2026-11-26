/**
 * Sub-tab router for the Knowledgebase tab. Mirrors useTab but for
 * the `?sub=` URL param so deep-linking to a specific sub-pane works
 * (e.g. Overview's "Auto-draft FAQ →" CTA needs to land on the FAQs
 * sub-tab, not the default).
 */
import { useCallback, useEffect, useState } from 'react'

export type ContentSubtab = 'indexed' | 'faqs' | 'topics' | 'generate' | 'drafts'

const VALID: Set<ContentSubtab> = new Set([
  'indexed', 'faqs', 'topics', 'generate', 'drafts',
])

const DEFAULT_SUB: ContentSubtab = 'indexed'

function readFromUrl(): ContentSubtab {
  const url = new URL(window.location.href)
  const raw = url.searchParams.get('sub')
  if (raw && VALID.has(raw as ContentSubtab)) return raw as ContentSubtab
  return DEFAULT_SUB
}

const SUB_CHANGE_EVENT = 'bb:sub-change'

export function useContentSubtab(): [ContentSubtab, (next: ContentSubtab) => void] {
  const [sub, setSub] = useState<ContentSubtab>(readFromUrl)

  useEffect(() => {
    const onSync = () => setSub(readFromUrl())
    window.addEventListener('popstate', onSync)
    window.addEventListener(SUB_CHANGE_EVENT, onSync)
    return () => {
      window.removeEventListener('popstate', onSync)
      window.removeEventListener(SUB_CHANGE_EVENT, onSync)
    }
  }, [])

  const setAndPush = useCallback((next: ContentSubtab) => {
    if (!VALID.has(next)) return
    const url = new URL(window.location.href)
    if (url.searchParams.get('sub') === next) return
    url.searchParams.set('sub', next)
    window.history.pushState({}, '', url)
    setSub(next)
    window.dispatchEvent(new Event(SUB_CHANGE_EVENT))
  }, [])

  return [sub, setAndPush]
}
