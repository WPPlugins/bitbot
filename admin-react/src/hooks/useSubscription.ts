/**
 * Subscription hooks — GetSubscription, CancelSubscription,
 * ReactivateSubscription, GetBillingPortal. Powers the Account drawer.
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'
import { getConfig } from '@/lib/config'
import { toast } from '@/components/ui/Toast'
import type { SubscriptionInfo } from '@/api/types'

const DEV_MOCK_KEY = 'bb_devmockapikey0000000000000000'

const MOCK_SUBSCRIPTION: SubscriptionInfo = {
  tier: 'premium',
  trialEndsAt: null,
  currentPeriodEnd: new Date(Date.now() + 27 * 86_400_000).toISOString(),
  isActive: true,
  subscriptionStatus: 'active',
  daysRemaining: 27,
}

export function useSubscription() {
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useQuery({
    queryKey: ['subscription'],
    queryFn: async (): Promise<SubscriptionInfo> => {
      if (isDev) return MOCK_SUBSCRIPTION
      const res = await rpc<SubscriptionInfo>('GetSubscription')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    staleTime: 5 * 60 * 1000,
  })
}

export function useCancelSubscription() {
  const qc = useQueryClient()
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useMutation({
    mutationFn: async () => {
      if (isDev) {
        await new Promise((r) => setTimeout(r, 500))
        return { success: true, message: 'Subscription will end at period end' }
      }
      const res = await rpc<{ success: boolean; message: string }>('CancelSubscription')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: (data) => {
      toast.success(data.message)
      qc.invalidateQueries({ queryKey: ['subscription'] })
      qc.invalidateQueries({ queryKey: ['plan-status'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Cancel failed'),
  })
}

export function useReactivateSubscription() {
  const qc = useQueryClient()
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useMutation({
    mutationFn: async () => {
      if (isDev) {
        await new Promise((r) => setTimeout(r, 500))
        return { success: true, message: 'Subscription reactivated' }
      }
      const res = await rpc<{ success: boolean; message: string }>('ReactivateSubscription')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: (data) => {
      toast.success(data.message)
      qc.invalidateQueries({ queryKey: ['subscription'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Reactivate failed'),
  })
}

/**
 * useStartCheckout — POST to CreateCheckout RPC for a given tier and
 * redirect the browser to the Stripe checkout URL. If the response
 * comes back with `tierUpdated` (a no-op swap between paid tiers), we
 * just bounce back to the dashboard with the subscription-success
 * flag so the rest of the UI refreshes.
 *
 * 2.0.3: replaces the previous dead-end placeholder where PlanCard /
 * AccountDrawer CTAs linked to `?page=bitbot-upgrade` (a 302 to the
 * dashboard with no follow-through). Now the buttons actually move
 * the user to checkout.
 */
export function useStartCheckout() {
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useMutation({
    mutationFn: async (tier: 'pro' | 'pro_plus') => {
      if (isDev) {
        await new Promise((r) => setTimeout(r, 400))
        return { checkoutUrl: 'https://checkout.stripe.com/c/test_xxx' }
      }
      // After successful checkout Stripe will bounce here; the dashboard
      // listens for ?subscription=success&swap=1 to refresh plan state.
      const successUrl = new URL(window.location.href)
      successUrl.searchParams.set('subscription', 'success')
      successUrl.searchParams.set('swap', '1')
      const cancelUrl = window.location.href
      const res = await rpc<{ checkoutUrl?: string; tierUpdated?: boolean; tier?: string }>(
        'CreateCheckout',
        {
          successUrl: successUrl.toString(),
          cancelUrl,
          tier,
        },
      )
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: (data) => {
      if (data.checkoutUrl) {
        window.location.href = data.checkoutUrl
        return
      }
      // No checkout URL came back — server already swapped the tier
      // (existing paid customer moving between pro and pro_plus).
      // Bounce to the dashboard so plan-status refetches.
      const url = new URL(window.location.href)
      url.searchParams.set('subscription', 'success')
      url.searchParams.set('swap', '1')
      window.location.href = url.toString()
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Could not start checkout'),
  })
}

export function useOpenBillingPortal() {
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useMutation({
    mutationFn: async () => {
      if (isDev) {
        await new Promise((r) => setTimeout(r, 300))
        return { url: 'https://billing.stripe.com/p/login/test_xxx' }
      }
      const res = await rpc<{ url: string }>('GetBillingPortal', {
        returnUrl: window.location.href,
      })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: (data) => {
      window.location.href = data.url
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Portal failed'),
  })
}
