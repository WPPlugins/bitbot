/**
 * One way to call a WP admin-ajax handler (nonce + form-encoded body) and get
 * the typed data back, or the handler's own error message as an Error.
 */
export interface WpAjaxConfig {
  ajaxUrl: string
  ajaxNonce: string
}

type Fetcher = (url: string, init: RequestInit) => Promise<{ json: () => Promise<unknown> }>

interface WpAjaxResponse<T> {
  success: boolean
  data: T | { error?: string }
}

export function buildAjaxBody(
  action: string,
  nonce: string,
  fields: Readonly<Record<string, string | undefined>>,
): string {
  const body = new URLSearchParams({ action, nonce })
  for (const [k, v] of Object.entries(fields)) {
    if (v !== undefined) body.set(k, v)
  }
  return body.toString()
}

export async function postWpAjax<T>(
  action: string,
  fields: Readonly<Record<string, string | undefined>>,
  cfg: WpAjaxConfig,
  fallbackError: string,
  fetcher: Fetcher = fetch as unknown as Fetcher,
): Promise<T> {
  const res = await fetcher(cfg.ajaxUrl, {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: buildAjaxBody(action, cfg.ajaxNonce, fields),
  })
  const json = (await res.json()) as WpAjaxResponse<T>
  if (!json.success) {
    throw new Error((json.data as { error?: string } | undefined)?.error ?? fallbackError)
  }
  return json.data as T
}
