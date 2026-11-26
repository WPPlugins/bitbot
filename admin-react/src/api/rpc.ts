/**
 * Thin fetch wrapper around the Youami RPC endpoint. Mirrors the flat
 * body shape used by the PHP API client and documented in
 * Obsidian Vault/bitbot/BitBot/RPC API - calling from the outside.md:
 *
 *   POST {apiUrl}
 *   { "_tag": "<RpcTagName>", "apiKey": "...", ...other fields }
 *
 * Returns a success/error envelope rather than throwing — callers can
 * branch on the discriminator without try/catch boilerplate.
 */

import { getConfig } from '@/lib/config'
import { captureEvent } from '@/lib/telemetry'

export type RpcResult<T> =
  | { success: true; data: T }
  | { success: false; error: string }

/**
 * Broadcast an invalid-API-key signal so the app shell can surface a
 * reconnect prompt instead of silently rendering empty. Detected centrally
 * here because individual data hooks swallow RPC failures into empty/zero
 * states — so no single hook's error ever reaches the UI. When the backend
 * has no sites row matching our key, every authenticated RPC returns this,
 * and without a prompt the admin just looks blank.
 */
export const AUTH_ERROR_EVENT = 'bitbot:auth-error'
function notifyAuthError(error: string): void {
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent(AUTH_ERROR_EVENT, { detail: { error } }))
  }
}

export async function rpc<T>(
  tag: string,
  payload: Record<string, unknown> = {},
): Promise<RpcResult<T>> {
  const cfg = getConfig()
  const startedAt = performance.now()
  let res: Response
  try {
    res = await fetch(cfg.apiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ _tag: tag, apiKey: cfg.apiKey, ...payload }),
    })
  } catch (e) {
    const err = e instanceof Error ? e.message : 'Network error'
    captureEvent('rpc_failure', { tag, kind: 'network', error: err })
    return { success: false, error: err }
  }

  let body: unknown
  try {
    body = await res.json()
  } catch {
    const err = `Non-JSON response from ${tag} (HTTP ${res.status})`
    captureEvent('rpc_failure', { tag, kind: 'non_json', http_status: res.status })
    return { success: false, error: err }
  }

  // Effect RPC envelope shape: { _tag: 'Success' | 'Failure', value | error }
  if (body && typeof body === 'object') {
    const env = body as { _tag?: string; value?: T; error?: string }
    if (env._tag === 'Failure') {
      captureEvent('rpc_failure', { tag, kind: 'server', http_status: res.status, error: env.error })
      if (env.error && /invalid api key/i.test(env.error)) notifyAuthError(env.error)
      return { success: false, error: env.error ?? 'Unknown error' }
    }
    if (env._tag === 'Success' && env.value !== undefined) {
      const elapsed = performance.now() - startedAt
      if (elapsed > 2000) captureEvent('rpc_success_slow', { tag, ms: Math.round(elapsed) })
      return { success: true, data: env.value }
    }
  }

  // Fallback: some legacy RPCs return the value at top level
  if (!res.ok) {
    captureEvent('rpc_failure', { tag, kind: 'http', http_status: res.status })
    return { success: false, error: `HTTP ${res.status} from ${tag}` }
  }
  return { success: true, data: body as T }
}

/**
 * REST endpoint wrapper for the new /wp-json/bitbot/v1/admin-settings
 * endpoints (per BLUEPRINT.md Step 3). Uses X-WP-Nonce auth.
 */
export async function restGet<T>(path: string): Promise<RpcResult<T>> {
  const cfg = getConfig()
  try {
    const res = await fetch(`${cfg.restUrl}${path}`, {
      method: 'GET',
      headers: { 'X-WP-Nonce': cfg.nonce, 'Accept': 'application/json' },
      credentials: 'same-origin',
    })
    if (!res.ok) {
      captureEvent('rest_failure', { path, method: 'GET', http_status: res.status })
      return { success: false, error: `HTTP ${res.status}` }
    }
    const data = await res.json()
    return { success: true, data: data as T }
  } catch (e) {
    const err = e instanceof Error ? e.message : 'Network error'
    captureEvent('rest_failure', { path, method: 'GET', kind: 'network', error: err })
    return { success: false, error: err }
  }
}

export async function restPost<T>(path: string, body: unknown): Promise<RpcResult<T>> {
  const cfg = getConfig()
  try {
    const res = await fetch(`${cfg.restUrl}${path}`, {
      method: 'POST',
      headers: {
        'X-WP-Nonce': cfg.nonce,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify(body),
    })
    if (!res.ok) {
      captureEvent('rest_failure', { path, method: 'POST', http_status: res.status })
      return { success: false, error: `HTTP ${res.status}` }
    }
    const data = await res.json()
    return { success: true, data: data as T }
  } catch (e) {
    const err = e instanceof Error ? e.message : 'Network error'
    captureEvent('rest_failure', { path, method: 'POST', kind: 'network', error: err })
    return { success: false, error: err }
  }
}

/**
 * Hardened wrapper for WordPress admin-ajax.php actions (the `bitbot_*`
 * AJAX handlers). Callers previously did a raw `await res.json()`, which
 * blows up with the cryptic "Unexpected token '<', "<p>There h"... is not
 * valid JSON" whenever admin-ajax returns HTML instead of JSON — i.e. when
 * a heavy handler (notably the blocking content sync on large sites) hits a
 * PHP fatal/timeout and WordPress serves its "There has been a critical
 * error" page. That raw path was also uninstrumented, so these never showed
 * up in PostHog. This helper reads the body as text first, surfaces a
 * human-readable error, and emits `wp_ajax_failure` telemetry so the same
 * failure is visible in analytics the way backend `rpc_failure` already is.
 *
 * Throws on any failure (so it composes with react-query mutations);
 * returns the `data` payload on success.
 */
/**
 * Multipart admin-ajax POST (file uploads). Same success envelope as wpAjax;
 * the browser sets the multipart boundary, so no Content-Type header.
 */
export async function wpAjaxUpload<T>(action: string, form: FormData): Promise<T> {
  const { ajaxUrl, ajaxNonce } = getConfig()
  form.append('action', action)
  form.append('nonce', ajaxNonce)
  let res: Response
  try {
    res = await fetch(ajaxUrl, { method: 'POST', credentials: 'include', body: form })
  } catch {
    throw new Error('Could not reach your site. Check your connection and try again.')
  }
  const text = await res.text()
  let json: { success?: boolean; data?: T & { error?: string } }
  try {
    json = JSON.parse(text)
  } catch {
    throw new Error('Upload failed — the site returned an unexpected response.')
  }
  if (!json.success) {
    throw new Error((json.data && (json.data as { error?: string }).error) || 'Upload failed.')
  }
  return json.data as T
}

export async function wpAjax<T>(
  action: string,
  fields: Record<string, string> = {},
): Promise<T> {
  const { ajaxUrl, ajaxNonce } = getConfig()
  const body = new URLSearchParams({ action, nonce: ajaxNonce, ...fields })

  let res: Response
  try {
    res = await fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    })
  } catch (e) {
    const err = e instanceof Error ? e.message : 'Network error'
    captureEvent('wp_ajax_failure', { action, kind: 'network', error: err })
    throw new Error('Could not reach your site. Check your connection and try again.')
  }

  // Read as text so an HTML error page doesn't throw an opaque parse error.
  const text = await res.text()
  let json: { success?: boolean; data?: T | { error?: string } }
  try {
    json = JSON.parse(text)
  } catch {
    captureEvent('wp_ajax_failure', { action, kind: 'non_json', http_status: res.status })
    const looksLikePhpFatal = res.status >= 500 || /critical error|<\s*\/?\s*(p|html|b)\b/i.test(text)
    throw new Error(
      looksLikePhpFatal
        ? 'Your site hit a server error or timed out handling this. Large sites can exceed the host’s PHP memory/time limits — try again, and contact support if it keeps happening.'
        : `Unexpected response from your site (HTTP ${res.status}).`,
    )
  }

  if (!json.success) {
    const data = json.data as { error?: string; code?: string } | undefined
    const err = data?.error ?? 'Request failed'
    captureEvent('wp_ajax_failure', { action, kind: 'server', http_status: res.status, error: err })
    // A handler may add a stable `code` beside its (translated) message; callers branch on the code.
    throw Object.assign(new Error(err), data?.code ? { code: data.code } : {})
  }
  return json.data as T
}
