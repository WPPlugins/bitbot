/**
 * Meta (Facebook Messenger + Instagram DMs) — OAuth-based per-site
 * connection.
 *
 * Flow mirrors useSearchConsole / useZoho:
 *   1. Admin clicks "Connect Facebook Page" → StartMetaOAuth returns
 *      Facebook's consent URL.
 *   2. We open it in a popup. Facebook redirects to
 *      /api/oauth/meta/callback which posts a message back via
 *      window.opener.postMessage ('bitbot:meta-oauth-complete').
 *   3. We refetch the integration row; status flips from null → connected.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'
import { toast } from '@/components/ui/Toast'

export type MetaStatus = 'connected' | 'error'

export interface MetaConnectedPage {
  pageId: string
  pageName: string
  /** Present only when the admin linked an IG Business / Creator
   *  account to this Page AND granted instagram_basic at consent time. */
  instagramUserId: string | null
}

export interface MetaIntegration {
  status: MetaStatus
  accountEmail: string | null
  pages: MetaConnectedPage[]
  oauthConfigured: boolean
  lastErrorMessage: string | null
}

interface MetaStatusResponse {
  integration: MetaIntegration | null
  oauthAvailable: boolean
}

export function useMetaIntegration() {
  return useQuery({
    queryKey: ['integrations', 'meta'],
    queryFn: async (): Promise<MetaStatusResponse> => {
      const res = await rpc<MetaStatusResponse>('GetMetaIntegration')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    staleTime: 60_000,
  })
}

export function useStartMetaOAuth() {
  return useMutation({
    mutationFn: async () => {
      const res = await rpc<{ url: string }>('StartMetaOAuth')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onError: (err) =>
      toast.error(err instanceof Error ? err.message : 'Could not start Facebook sign-in'),
  })
}

export function useConnectMetaPage() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (vars: { pageId: string; pageAccessToken: string }) => {
      const res = await rpc<{ page: MetaConnectedPage }>('ConnectMetaPage', vars)
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: (data) => {
      toast.success(`Connected ${data.page.pageName}`)
      qc.invalidateQueries({ queryKey: ['integrations', 'meta'] })
    },
    onError: (err) =>
      toast.error(err instanceof Error ? err.message : 'Could not connect this Page'),
  })
}

export function useDisconnectMeta() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async () => {
      const res = await rpc<{ ok: boolean }>('DisconnectMeta')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => {
      toast.success('Facebook disconnected')
      qc.invalidateQueries({ queryKey: ['integrations', 'meta'] })
    },
    onError: (err) =>
      toast.error(err instanceof Error ? err.message : 'Could not disconnect Facebook'),
  })
}
