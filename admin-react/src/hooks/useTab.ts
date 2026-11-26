/**
 * URL-search-param tab router. Reads/writes the `tab` param so deep
 * links work and WP admin can pass a starting tab via the menu URL.
 *
 * No react-router-dom dependency — a 30-line hook covers our needs.
 */
import { useCallback, useEffect, useState } from 'react'
import { parseTab, VALID_TABS } from '@/lib/tabs'
import type { TabKey } from '@/lib/tabs'

export type { TabKey } from '@/lib/tabs'

function readFromUrl(): TabKey {
  return parseTab(new URL(window.location.href).searchParams.get('tab'))
}

// Custom event so every useTab caller stays in sync after a
// programmatic pushState. Browser popstate doesn't fire on pushState,
// so sibling instances would otherwise show stale tab state.
const TAB_CHANGE_EVENT = 'bb:tab-change'

export function useTab(): [TabKey, (next: TabKey) => void] {
  const [tab, setTab] = useState<TabKey>(readFromUrl)

  useEffect(() => {
    const onSync = () => setTab(readFromUrl())
    window.addEventListener('popstate', onSync)
    window.addEventListener(TAB_CHANGE_EVENT, onSync)
    return () => {
      window.removeEventListener('popstate', onSync)
      window.removeEventListener(TAB_CHANGE_EVENT, onSync)
    }
  }, [])

  const setTabAndUrl = useCallback((next: TabKey) => {
    if (!VALID_TABS.has(next)) return
    const url = new URL(window.location.href)
    if (url.searchParams.get('tab') === next) return
    url.searchParams.set('tab', next)
    window.history.pushState({}, '', url)
    setTab(next)
    window.dispatchEvent(new Event(TAB_CHANGE_EVENT))
  }, [])

  return [tab, setTabAndUrl]
}
