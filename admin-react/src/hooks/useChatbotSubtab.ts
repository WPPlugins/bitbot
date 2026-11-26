/**
 * Sub-tab router for the Chatbot tab. Same `?sub=` URL param strategy
 * as useContentSubtab so deep-links from Overview NextBestStep land on
 * the right sub-pane. The old names still work: look is widget,
 * behaviour is replies.
 */
import { useCallback, useEffect, useState } from 'react'
import { normalizeSub, type ChatbotSubtab } from '@/lib/chatbotPanels'

export type { ChatbotSubtab }

const DEFAULT_SUB: ChatbotSubtab = 'widget'

function readFromUrl(): ChatbotSubtab {
  const url = new URL(window.location.href)
  return normalizeSub(url.searchParams.get('sub')) ?? DEFAULT_SUB
}

const SUB_CHANGE_EVENT = 'bb:sub-change'

export function useChatbotSubtab(): [ChatbotSubtab, (next: ChatbotSubtab) => void] {
  const [sub, setSub] = useState<ChatbotSubtab>(readFromUrl)

  useEffect(() => {
    const onSync = () => setSub(readFromUrl())
    window.addEventListener('popstate', onSync)
    window.addEventListener(SUB_CHANGE_EVENT, onSync)
    return () => {
      window.removeEventListener('popstate', onSync)
      window.removeEventListener(SUB_CHANGE_EVENT, onSync)
    }
  }, [])

  const setAndPush = useCallback((next: ChatbotSubtab) => {
    const url = new URL(window.location.href)
    if (url.searchParams.get('sub') === next) return
    url.searchParams.set('sub', next)
    window.history.pushState({}, '', url)
    setSub(next)
    window.dispatchEvent(new Event(SUB_CHANGE_EVENT))
  }, [])

  return [sub, setAndPush]
}
