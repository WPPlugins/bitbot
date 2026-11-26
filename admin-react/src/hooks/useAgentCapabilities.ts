/**
 * Hook: agent-capability flags (cart recovery / appointments / WooCommerce).
 * Backed by GetAgentCapabilities + SetAgentCapability RPCs. Each flag is
 * a boolean column on the `sites` table (recovery_enabled,
 * appointments_enabled, woocommerce_enabled) that the route.ts agent
 * loop reads to decide whether to include the corresponding skill.
 *
 * Dev-mock returns all flags off so the UI is in a knowable state
 * during local development.
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'
import { getConfig } from '@/lib/config'
import { toast } from '@/components/ui/Toast'
import { captureEvent } from '@/lib/telemetry'
import { PLAN_STATUS_KEY } from '@/hooks/usePlanStatus'

const DEV_MOCK_KEY = 'bb_devmockapikey0000000000000000'

export interface AgentCapabilities {
  recovery_enabled: boolean
  appointments_enabled: boolean
}

const DEV_MOCK: AgentCapabilities = {
  recovery_enabled: false,
  appointments_enabled: false,
}

export function useAgentCapabilities() {
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useQuery({
    queryKey: ['agent-capabilities'],
    queryFn: async (): Promise<AgentCapabilities> => {
      if (isDev) return DEV_MOCK
      const res = await rpc<AgentCapabilities>('GetAgentCapabilities')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    staleTime: 60_000,
  })
}

export function useUpdateAgentCapability() {
  const qc = useQueryClient()
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useMutation({
    mutationFn: async ({ key, enabled }: { key: keyof AgentCapabilities; enabled: boolean }) => {
      if (isDev) {
        DEV_MOCK[key] = enabled
        await new Promise((r) => setTimeout(r, 200))
        return DEV_MOCK
      }
      const res = await rpc<AgentCapabilities>('SetAgentCapability', { key, enabled })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onMutate: async ({ key, enabled }) => {
      await qc.cancelQueries({ queryKey: ['agent-capabilities'] })
      const prev = qc.getQueryData<AgentCapabilities>(['agent-capabilities'])
      if (prev) qc.setQueryData(['agent-capabilities'], { ...prev, [key]: enabled })
      return { prev }
    },
    onError: (err, _, ctx) => {
      if (ctx?.prev) qc.setQueryData(['agent-capabilities'], ctx.prev)
      toast.error(err instanceof Error ? err.message : 'Could not update')
    },
    onSuccess: (_data, { key, enabled }) => {
      toast.success(enabled ? `${prettify(key)} enabled` : `${prettify(key)} disabled`)
      captureEvent('agent_capability_toggled', { capability: key, enabled })
    },
    onSettled: () => qc.invalidateQueries({ queryKey: ['agent-capabilities'] }),
  })
}

function prettify(key: keyof AgentCapabilities): string {
  return ({
    recovery_enabled: 'Cart recovery',
    appointments_enabled: 'Appointment booking',
  } as const)[key]
}

/** The automatic top-up setting the owner saves from the Usage panel. */
export interface AutoTopupSettings {
  enabled: boolean
  credits: number
  monthlyCap: number
}

/**
 * Save the automatic top-up setting. Same RPC as the capability switches
 * (SetAgentCapability, key auto_topup); the server checks the amount against
 * the offered ones and the cap against its range. Only the owner, here, can
 * change it: no agent tool writes it.
 */
export function useSaveAutoTopup() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (autoTopup: AutoTopupSettings) => {
      const res = await rpc<{ auto_topup: AutoTopupSettings }>('SetAgentCapability', { key: 'auto_topup', autoTopup })
      if (!res.success) throw new Error(res.error)
      return res.data.auto_topup
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Could not save automatic top-up'),
    onSuccess: (saved) => {
      toast.success(saved.enabled ? 'Automatic top-up is on' : 'Automatic top-up is off')
      captureEvent('auto_topup_saved', { enabled: saved.enabled, credits: saved.credits, monthly_cap: saved.monthlyCap })
    },
    onSettled: () => qc.invalidateQueries({ queryKey: PLAN_STATUS_KEY }),
  })
}
