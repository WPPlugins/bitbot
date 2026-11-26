/**
 * BackgroundPrefetch — warms the cache for the slow-to-load tabs so
 * tab switches feel instant.
 *
 * Profile on the sandbox showed three tabs that fetch a list from the
 * backend on cold mount and were taking 0.8 – 1.8s to render content:
 *
 *   Forms          ~1.8s
 *   Conversations  ~1.3s
 *   Leads          ~0.8s
 *
 * The other tabs (Overview, Chatbot, Knowledgebase, Agent) load in
 * 6-12ms because their data is either already in the cache (PlanCard,
 * lead-count badges fire at the App level) or doesn't need a fresh
 * fetch.
 *
 * Strategy:
 *   1. Wait 600ms after App mounts so the active tab's own fetch can
 *      win the network race for the user-facing render.
 *   2. Then mount PrefetchInner, which calls each slow tab's primary
 *      hook. TanStack Query dedupes by queryKey, so calling the same
 *      hook from this hidden component AND from the tab itself only
 *      fires one fetch — but the data lands in the shared cache.
 *   3. Subsequent tab switches read from cache (staleTime is set on
 *      every hook), so the content paints in a frame.
 *
 * Renders nothing visible. Safe to mount once at the App level.
 */
import { useEffect, useState } from 'react'
import { useForms } from '@/hooks/useForms'
import { useLeads } from '@/hooks/useLeads'
import { useConversations } from '@/hooks/useConversations'

function PrefetchInner() {
  // Calling these hooks fires their queries into the cache. Each tab
  // uses these same defaults on first mount, so the cache hit is real.
  useForms()
  useLeads()             // default params: stage='all', search='', formId=null
  useConversations()
  return null
}

export function BackgroundPrefetch() {
  const [armed, setArmed] = useState(false)
  useEffect(() => {
    const t = setTimeout(() => setArmed(true), 600)
    return () => clearTimeout(t)
  }, [])
  if (!armed) return null
  return <PrefetchInner />
}
