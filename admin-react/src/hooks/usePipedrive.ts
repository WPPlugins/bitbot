/**
 * Pipedrive CRM — personal API token, no OAuth.
 *
 * Flow:
 *   1. Admin pastes their Pipedrive API token (Settings → Personal
 *      preferences → API).
 *   2. We push it via UpdatePipedriveCredentials (backend validates it
 *      against /users/me + derives the company domain) and enable the
 *      `pipedrive` skill via SetSkillEnabled in the same save.
 *   3. Status read is yes/no + the connected account name — the token is
 *      never round-tripped back to the client.
 *
 * Pipedrive is admin-only (visible-to-user: false), so unlike WooCommerce
 * there's no visitor-exposure step.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'
import { toast } from '@/components/ui/Toast'
import type { PipedriveLeadState } from '@/api/types'

export interface PipedriveStatus {
  configured: boolean
  companyName: string | null
  accountName: string | null
  companyDomain: string | null
}

export function usePipedriveStatus() {
  return useQuery({
    queryKey: ['integrations', 'pipedrive'],
    queryFn: async (): Promise<PipedriveStatus> => {
      const res = await rpc<PipedriveStatus>('GetPipedriveStatus')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    staleTime: 60_000,
  })
}

export function useSavePipedrive() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ apiToken }: { apiToken: string }) => {
      const credsRes = await rpc<{ success: boolean }>('UpdatePipedriveCredentials', { apiToken })
      if (!credsRes.success) throw new Error(credsRes.error)
      const skillRes = await rpc<{ ok: boolean }>('SetSkillEnabled', {
        skillName: 'pipedrive',
        enabled: true,
      })
      if (!skillRes.success) throw new Error(skillRes.error)
      return { success: true as const }
    },
    onSuccess: () => {
      toast.success('Pipedrive connected')
      qc.invalidateQueries({ queryKey: ['integrations', 'pipedrive'] })
    },
    onError: (err) =>
      toast.error(err instanceof Error ? err.message : 'Could not connect Pipedrive'),
  })
}

// --- lead sync (mirrors useZoho's auto-sync + push hooks) ---

export function usePipedriveAutoSync() {
  return useQuery({
    queryKey: ['integrations', 'pipedrive', 'auto-sync'],
    queryFn: async (): Promise<boolean> => {
      const res = await rpc<{ enabled: boolean }>('GetPipedriveAutoSync')
      if (!res.success) throw new Error(res.error)
      return res.data.enabled
    },
    staleTime: 60_000,
  })
}

export function useSetPipedriveAutoSync() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (enabled: boolean) => {
      const res = await rpc<{ enabled: boolean }>('SetPipedriveAutoSync', { enabled })
      if (!res.success) throw new Error(res.error)
      return res.data.enabled
    },
    onSuccess: (enabled) => {
      toast.success(enabled ? 'Auto-sync to Pipedrive turned on' : 'Auto-sync to Pipedrive turned off')
      qc.invalidateQueries({ queryKey: ['integrations', 'pipedrive', 'auto-sync'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Could not update auto-sync'),
  })
}

// Live Pipedrive state for the lead-detail CRM status row. Mirrors
// useZohoLeadState — refetches on open (30s staleTime) so the stage always
// reflects what the admin sees in Pipedrive. Only fires for synced leads.
export function usePipedriveLeadState(leadId: string | null, enabled: boolean) {
  return useQuery({
    queryKey: ['leads', leadId, 'pipedrive-state'],
    queryFn: async (): Promise<PipedriveLeadState | null> => {
      const res = await rpc<{ state: PipedriveLeadState | null }>('GetPipedriveLeadStateForLead', { leadId })
      if (!res.success) throw new Error(res.error)
      return res.data.state
    },
    enabled: !!leadId && enabled,
    staleTime: 30_000,
  })
}

/** Per-lead push, used by the "Push to Pipedrive ↗" chip on the lead row. */
export function usePushLeadToPipedrive() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (leadId: string) => {
      const res = await rpc<{ personId: string }>('PushLeadToPipedrive', { leadId })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => {
      toast.success('Lead pushed to Pipedrive')
      qc.invalidateQueries({ queryKey: ['leads'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Could not push lead to Pipedrive'),
  })
}

export function usePushAllLeadsToPipedrive() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async () => {
      const res = await rpc<{ pushed: number; skipped: number; failed: number; total: number }>('PushAllLeadsToPipedrive')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: ({ pushed, skipped, failed }) => {
      const parts = [`${pushed} pushed`]
      if (skipped > 0) parts.push(`${skipped} already in Pipedrive`)
      if (failed > 0) parts.push(`${failed} failed`)
      toast.success(parts.join(' · '))
      qc.invalidateQueries({ queryKey: ['leads'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Could not push leads to Pipedrive'),
  })
}

export function useDisconnectPipedrive() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async () => {
      const credsRes = await rpc<{ success: boolean }>('DisconnectPipedrive')
      if (!credsRes.success) throw new Error(credsRes.error)
      const skillRes = await rpc<{ ok: boolean }>('SetSkillEnabled', {
        skillName: 'pipedrive',
        enabled: false,
      })
      if (!skillRes.success) throw new Error(skillRes.error)
      return { success: true as const }
    },
    onSuccess: () => {
      toast.success('Pipedrive disconnected')
      qc.invalidateQueries({ queryKey: ['integrations', 'pipedrive'] })
    },
    onError: (err) =>
      toast.error(err instanceof Error ? err.message : 'Could not disconnect Pipedrive'),
  })
}
