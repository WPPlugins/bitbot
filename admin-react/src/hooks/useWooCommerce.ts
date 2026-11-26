/**
 * WooCommerce — REST API key + secret pair, no OAuth.
 *
 * Flow:
 *   1. Admin pastes consumer key + secret from their WC settings.
 *   2. We push both to the backend via UpdateWcCredentials and enable
 *      the `woocommerce` skill via SetSkillEnabled in the same save.
 *   3. Status read is yes/no — we never round-trip the secret back to
 *      the client. The form re-prompts for the secret on every edit.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'
import { toast } from '@/components/ui/Toast'

export interface WooCommerceStatus {
  configured: boolean
}

export function useWooCommerceStatus() {
  return useQuery({
    queryKey: ['integrations', 'woocommerce'],
    queryFn: async (): Promise<WooCommerceStatus> => {
      const res = await rpc<WooCommerceStatus>('GetWooCommerceStatus')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    staleTime: 60_000,
  })
}

export function useSaveWooCommerce() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ consumerKey, consumerSecret }: { consumerKey: string; consumerSecret: string }) => {
      const credsRes = await rpc<{ success: boolean }>('UpdateWcCredentials', {
        consumerKey,
        consumerSecret,
      })
      if (!credsRes.success) throw new Error(credsRes.error)
      const skillRes = await rpc<{ ok: boolean }>('SetSkillEnabled', {
        skillName: 'woocommerce',
        enabled: true,
      })
      if (!skillRes.success) throw new Error(skillRes.error)
      // WC is dual-actor — visitor needs product discovery + order lookup,
      // admin needs store ops. Default visitor exposure to true on connect
      // (the whole point of connecting WC is that the chatbot can answer
      // store questions for visitors). Admin can flip it off later via the
      // Skills tab if they want WC tools admin-only.
      const exposeRes = await rpc<{ ok: boolean }>('SetSkillVisitorExposed', {
        skillName: 'woocommerce',
        exposed: true,
      })
      if (!exposeRes.success) throw new Error(exposeRes.error)
      return { success: true as const }
    },
    onSuccess: () => {
      toast.success('WooCommerce connected')
      qc.invalidateQueries({ queryKey: ['integrations', 'woocommerce'] })
    },
    onError: (err) =>
      toast.error(err instanceof Error ? err.message : 'Could not save WooCommerce credentials'),
  })
}

export function useDisconnectWooCommerce() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async () => {
      const credsRes = await rpc<{ success: boolean }>('UpdateWcCredentials', {
        consumerKey: '',
        consumerSecret: '',
      })
      if (!credsRes.success) throw new Error(credsRes.error)
      const skillRes = await rpc<{ ok: boolean }>('SetSkillEnabled', {
        skillName: 'woocommerce',
        enabled: false,
      })
      if (!skillRes.success) throw new Error(skillRes.error)
      return { success: true as const }
    },
    onSuccess: () => {
      toast.success('WooCommerce disconnected')
      qc.invalidateQueries({ queryKey: ['integrations', 'woocommerce'] })
    },
    onError: (err) =>
      toast.error(err instanceof Error ? err.message : 'Could not disconnect WooCommerce'),
  })
}
