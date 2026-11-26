/**
 * The administrator's explicit "Connect to youami.ai" action. Uses the
 * `bitbot_connect` ajax action: it records consent, registers the site and
 * queues the first content sync. Nothing is sent to youami.ai before this.
 */
import { useMutation } from '@tanstack/react-query'
import { getConfig } from '@/lib/config'

interface WPAjaxResponse<T> {
  success: boolean
  data: T
}

export function useConnect() {
  const { ajaxUrl, ajaxNonce } = getConfig()

  return useMutation({
    mutationFn: async ({ shareAnalytics }: { shareAnalytics: boolean }): Promise<{ connected: boolean }> => {
      // `analytics` is sent only when the administrator ticked the box.
      const body = new URLSearchParams({ action: 'bitbot_connect', nonce: ajaxNonce, ...(shareAnalytics ? { analytics: '1' } : {}) })
      const res = await fetch(ajaxUrl, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
      })
      const json: WPAjaxResponse<{ connected?: boolean; error?: string }> = await res.json()
      if (!json.success) throw new Error(json.data?.error ?? 'Could not connect')
      return { connected: true }
    },
  })
}
