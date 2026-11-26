/**
 * Google Search Console — OAuth-based, per-site tokens.
 *
 * Flow:
 *   1. Admin clicks "Sign in with Google" → StartSearchConsoleOAuth
 *      returns Google's consent URL.
 *   2. We open it in a popup. Google redirects to
 *      /api/oauth/google/callback which posts a message back via
 *      window.opener.postMessage.
 *   3. We refetch the integration row + property list and let the
 *      admin pick which property to associate.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'
import { toast } from '@/components/ui/Toast'

export interface SearchConsoleIntegration {
  property: string | null
  status: 'pending' | 'connected' | 'error'
  accountEmail: string | null
  lastCheckedAt: string | null
  lastErrorMessage: string | null
  oauthConfigured: boolean
}

interface IntegrationResponse {
  integration: SearchConsoleIntegration | null
  oauthAvailable: boolean
}

export interface SearchConsoleProperty {
  siteUrl: string
  permissionLevel: string
}

export function useSearchConsoleIntegration() {
  return useQuery({
    queryKey: ['integrations', 'search-console'],
    queryFn: async (): Promise<IntegrationResponse> => {
      const res = await rpc<IntegrationResponse>('GetSearchConsoleIntegration')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    staleTime: 60_000,
  })
}

export function useStartSearchConsoleOAuth() {
  return useMutation({
    mutationFn: async () => {
      const res = await rpc<{ url: string }>('StartSearchConsoleOAuth')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Could not start sign-in'),
  })
}

export function useListSearchConsoleProperties() {
  return useQuery({
    queryKey: ['integrations', 'search-console', 'properties'],
    queryFn: async (): Promise<SearchConsoleProperty[]> => {
      const res = await rpc<{ properties: SearchConsoleProperty[] }>('ListSearchConsoleProperties')
      if (!res.success) throw new Error(res.error)
      return res.data.properties
    },
    // Manual — only triggered by setEnabled after a successful OAuth.
    enabled: false,
    staleTime: 30_000,
  })
}

export function useSelectSearchConsoleProperty() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ property }: { property: string }) => {
      const res = await rpc<SearchConsoleIntegration>('SelectSearchConsoleProperty', { property })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => {
      toast.success('Search Console connected')
      qc.invalidateQueries({ queryKey: ['integrations', 'search-console'] })
    },
    onError: (err) =>
      toast.error(err instanceof Error ? err.message : 'Could not link property'),
  })
}

export function useDisconnectSearchConsole() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async () => {
      const res = await rpc<{ ok: boolean }>('DisconnectSearchConsole')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => {
      toast.success('Search Console disconnected')
      qc.invalidateQueries({ queryKey: ['integrations', 'search-console'] })
    },
    onError: (err) =>
      toast.error(err instanceof Error ? err.message : 'Could not disconnect Search Console'),
  })
}
