/**
 * Integration catalog — the list of available integrations + their
 * presentation, served by the backend GetIntegrationsCatalog RPC from
 * `integrations/<id>/integration.json` manifests.
 *
 * This replaces the hardcoded INTEGRATIONS array that used to live in
 * IntegrationsDrawerBody. The drawer falls back to its built-in list if
 * this query is still loading or errors (e.g. standalone Vite dev with a
 * mock apiKey), so the catalogue always renders.
 *
 * The catalog is static product data, so we cache it hard for the session.
 */
import { useQuery } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'

export interface IntegrationManifest {
  id: string
  order: number
  name: string
  category: string
  description: string
  plan: string
  auth: string
  kind: string | null
  skill: string | null
  comingSoon: boolean
}

export function useIntegrationsCatalog() {
  return useQuery({
    queryKey: ['integrations-catalog'],
    queryFn: async (): Promise<IntegrationManifest[]> => {
      const res = await rpc<{ integrations: IntegrationManifest[] }>('GetIntegrationsCatalog')
      if (!res.success) throw new Error(res.error)
      return res.data.integrations
    },
    staleTime: 60 * 60 * 1000,
    retry: false,
  })
}
