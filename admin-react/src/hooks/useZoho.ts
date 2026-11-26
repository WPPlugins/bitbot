/**
 * Zoho CRM — OAuth-based per-site connection.
 *
 * Flow mirrors useSearchConsole:
 *   1. Admin clicks "Connect Zoho" → StartZohoOAuth returns the consent URL.
 *   2. We open it in a popup. Zoho redirects to
 *      /api/oauth/zoho/callback which posts a message back via
 *      window.opener.postMessage ('bitbot:zoho-oauth-complete').
 *   3. We refetch the integration row; status flips from null → connected.
 *
 * Zoho specifics:
 *   - Multiple data centers; we don't surface that to the user. Backend
 *     remembers each site's api_domain.
 *   - No property picker (unlike GSC) — Zoho uses the org the user belongs to.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'
import { toast } from '@/components/ui/Toast'
import type { ZohoLeadState } from '@/api/types'
import { isAgent } from '@/lib/config'
import { useCurrentPlan } from '@/hooks/useCurrentPlan'

export type IntegrationStatus = 'pending' | 'connected' | 'error'

export interface ZohoIntegration {
  status: IntegrationStatus
  accountEmail: string | null
  apiDomain: string | null
  lastCheckedAt: string | null
  lastErrorMessage: string | null
  oauthConfigured: boolean
}

interface ZohoStatusResponse {
  integration: ZohoIntegration | null
  oauthAvailable: boolean
}

// The backend answers PRO_PLUS_REQUIRED below the Agent plan, so only ask
// on that plan (same isAgent rule the rest of the SPA uses).
export function useZohoIntegration() {
  const onAgent = isAgent(useCurrentPlan())
  return useQuery({
    enabled: onAgent,
    queryKey: ['integrations', 'zoho'],
    queryFn: async (): Promise<ZohoStatusResponse> => {
      const res = await rpc<ZohoStatusResponse>('GetZohoIntegration')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    staleTime: 60_000,
  })
}

export function useStartZohoOAuth() {
  return useMutation({
    mutationFn: async () => {
      const res = await rpc<{ url: string }>('StartZohoOAuth')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Could not start Zoho sign-in'),
  })
}

export function useDisconnectZoho() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async () => {
      const res = await rpc<{ ok: boolean }>('DisconnectZoho')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => {
      toast.success('Zoho disconnected')
      qc.invalidateQueries({ queryKey: ['integrations', 'zoho'] })
    },
    onError: (err) =>
      toast.error(err instanceof Error ? err.message : 'Could not disconnect Zoho'),
  })
}

// Auto-sync toggle. Off by default — admin opts in. When on, every new
// captured lead is fire-and-forget pushed to Zoho via waitUntil.
export function useZohoAutoSync() {
  return useQuery({
    queryKey: ['integrations', 'zoho', 'auto-sync'],
    queryFn: async (): Promise<boolean> => {
      const res = await rpc<{ enabled: boolean }>('GetZohoAutoSync')
      if (!res.success) throw new Error(res.error)
      return res.data.enabled
    },
    staleTime: 60_000,
  })
}

export function useSetZohoAutoSync() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (enabled: boolean) => {
      const res = await rpc<{ enabled: boolean }>('SetZohoAutoSync', { enabled })
      if (!res.success) throw new Error(res.error)
      return res.data.enabled
    },
    onSuccess: (enabled) => {
      toast.success(enabled ? 'Auto-sync to Zoho turned on' : 'Auto-sync to Zoho turned off')
      qc.invalidateQueries({ queryKey: ['integrations', 'zoho', 'auto-sync'] })
    },
    onError: (err) =>
      toast.error(err instanceof Error ? err.message : 'Could not update auto-sync'),
  })
}

// Manual per-lead push. Used by the "Push to Zoho" button in the Leads UI.
// The mutation returns { zohoId } on success; we invalidate the leads
// queries so the ✓ Zoho pill + LeadDetail state card pick up the new
// zoho_lead_id stamp without needing a manual refresh.
export function usePushLeadToZoho() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (leadId: string) => {
      const res = await rpc<{ zohoId: string }>('PushLeadToZoho', { leadId })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => {
      toast.success('Lead pushed to Zoho')
      qc.invalidateQueries({ queryKey: ['leads'] })
    },
    onError: (err) =>
      toast.error(err instanceof Error ? err.message : 'Could not push lead to Zoho'),
  })
}

// Live read of a synced lead's current Zoho state. Drives the "Zoho
// state" card in the LeadDetail pane. We deliberately don't cache the
// payload anywhere on the Youami side — every open of the pane refetches
// (within React Query's 30s staleTime) so the admin always sees current
// Zoho data, even after they edited the record directly in Zoho.
export function useZohoLeadState(leadId: string | null, enabled: boolean) {
  return useQuery({
    queryKey: ['leads', leadId, 'zoho-state'],
    queryFn: async (): Promise<ZohoLeadState | null> => {
      const res = await rpc<{ state: ZohoLeadState | null }>('GetZohoLeadStateForLead', { leadId })
      if (!res.success) throw new Error(res.error)
      return res.data.state
    },
    enabled: !!leadId && enabled,
    staleTime: 30_000,
  })
}

// Bulk push every lead in the inbox. Returns counts so the toast can be
// specific ("Pushed 12, 3 already in Zoho, 0 failed"). Invalidates the
// leads list afterward so the ✓ Zoho pills appear without a refresh.
export function usePushAllLeadsToZoho() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async () => {
      const res = await rpc<{ pushed: number; skipped: number; failed: number; total: number }>(
        'PushAllLeadsToZoho',
      )
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: ({ pushed, skipped, failed }) => {
      const parts: string[] = []
      parts.push(`${pushed} pushed`)
      if (skipped > 0) parts.push(`${skipped} already in Zoho`)
      if (failed > 0) parts.push(`${failed} failed`)
      toast.success(parts.join(' · '))
      qc.invalidateQueries({ queryKey: ['leads'] })
    },
    onError: (err) =>
      toast.error(err instanceof Error ? err.message : 'Could not sync leads to Zoho'),
  })
}
