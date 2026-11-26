/**
 * Agent-home hook. Powers the dynamic empty state of the Agent chat: a
 * first-run orientation welcome or a returning-visit hook, greeting +
 * suggestions all LLM-written in the owner's language. The greeting is
 * time-aware, so we pass the browser's LOCAL hour (the server can't know
 * the owner's timezone) and the WP admin locale for the language.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'
import { getConfig } from '@/lib/config'
import type { AgentHome } from '@/api/types'

// Last delivered home, persisted so a page load paints INSTANTLY (greeting
// up, cursor blinking) while the fresh brief generates. Content only — the
// component decides how stale data is presented.
const CACHE_KEY = 'bb-agent-home'

const readCached = (): AgentHome | undefined => {
  try {
    const raw = window.localStorage.getItem(CACHE_KEY)
    return raw ? (JSON.parse(raw) as AgentHome) : undefined
  } catch {
    return undefined
  }
}

export function useAgentHome() {
  return useQuery({
    queryKey: ['agent-home'],
    queryFn: async (): Promise<AgentHome> => {
      const cfg = getConfig()
      const locale = cfg.locale || (typeof navigator !== 'undefined' ? navigator.language : 'en') || 'en'
      const res = await rpc<AgentHome>('GetAgentHome', {
        ownerName: cfg.ownerName ?? null,
        localHour: new Date().getHours(),
        locale,
      })
      if (!res.success) throw new Error(res.error)
      try {
        window.localStorage.setItem(CACHE_KEY, JSON.stringify(res.data))
      } catch {
        /* storage full/blocked — the instant paint just degrades */
      }
      return res.data
    },
    // Instant paint from the last visit's home while the fresh one loads;
    // isPlaceholderData tells the component it's showing yesterday's paper.
    placeholderData: readCached,
    // Cheap Haiku call server-side; a 5-min stale window keeps the home
    // fresh without re-calling on every mount.
    staleTime: 5 * 60 * 1000,
    // While a scan is in flight, poll — an audit takes about three minutes and
    // the owner is watching a "scanning" screen the whole time. Off otherwise,
    // because every poll is a Haiku call.
    // A scan takes about three minutes and the owner is watching the terminal
    // narrate it, so poll faster than the idle case — 4s keeps the lines moving
    // without making the tab chatty once the scan is done.
    refetchInterval: (q) => (q.state.data?.scanStatus === 'running' ? 4000 : false),
  })
}

/**
 * Kick the one free site scan.
 *
 * The server owns every rule — one per site, claimed before the kick, run
 * read-only. This just asks. On success it invalidates the home so the screen
 * moves to the "scanning" state on the next tick rather than waiting out the
 * 5-minute stale window.
 */
export function useStartSiteScan() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (): Promise<{ started: boolean; reason?: string }> => {
      const res = await rpc<{ started: boolean; reason?: string }>('StartSiteScan', {})
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => {
      // The cached home says 'offered'; drop it so the poll starts from truth.
      try { window.localStorage.removeItem(CACHE_KEY) } catch { /* blocked */ }
      void qc.invalidateQueries({ queryKey: ['agent-home'] })
    },
  })
}
