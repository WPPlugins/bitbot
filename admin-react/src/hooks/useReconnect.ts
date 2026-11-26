/**
 * Manual recovery for a site whose registration never reached the backend
 * (activation killed by the host, multisite network-activate, timeouts).
 * Calls the existing `bitbot_reconnect` ajax action, which regenerates the
 * api key and re-registers the site — creating the backend row + firing the
 * signup notification if it never registered. The auto self-heal
 * (admin_init -> maybe_register_site) handles most cases non-destructively;
 * this is the user-driven escape hatch.
 */
import { useMutation } from '@tanstack/react-query'
import { getConfig } from '@/lib/config'

interface WPAjaxResponse<T> {
  success: boolean
  data: T
}

interface ReconnectResult {
  apiKey?: string
  message?: string
}

export function useRegenerateKey() {
  const { ajaxUrl, ajaxNonce } = getConfig()

  return useMutation({
    mutationFn: async (): Promise<ReconnectResult> => {
      const body = new URLSearchParams({
        action: 'bitbot_reconnect',
        nonce: ajaxNonce,
      })

      const res = await fetch(ajaxUrl, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
      })
      const json: WPAjaxResponse<ReconnectResult> = await res.json()
      if (!json.success) {
        const err = (json.data as { error?: string })?.error ?? 'Reconnect failed'
        throw new Error(err)
      }
      return json.data
    },
  })
}
