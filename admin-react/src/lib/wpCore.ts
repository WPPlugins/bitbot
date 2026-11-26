/**
 * Address of a WordPress core REST route (wp/v2), derived from the plugin's own REST base.
 * The base comes in two shapes: pretty ("…/wp-json/bitbot/v1") and plain ("…/?rest_route=/bitbot/v1"),
 * and in the plain shape extra parameters join with "&", not "?".
 */
export function wpCoreUrl(pluginRestUrl: string, path: string, params: Record<string, string> = {}): string {
  const base = pluginRestUrl.replace(/\/?bitbot\/v1\/?$/, '')
  const url = `${base}/wp/v2/${path.replace(/^\//, '')}`
  const query = new URLSearchParams(params).toString()
  if (!query) return url
  return url + (url.includes('?') ? '&' : '?') + query
}

/** What the draft detail shows for the body of a post that lives in WordPress. */
export type WpBody =
  | { kind: 'body'; html: string }
  | { kind: 'empty' }
  | { kind: 'gone' }
  | { kind: 'error' }

/** Turn the wp/v2 answer for one post into what the body area shows. */
export function wpBodyFrom(httpStatus: number, json: unknown): WpBody {
  // 404 also covers a post converted to a page or another type, so 'gone' is worded as "not found".
  if (httpStatus === 404 || httpStatus === 410) return { kind: 'gone' }
  if (httpStatus < 200 || httpStatus >= 300) return { kind: 'error' }
  const post = json as { status?: string; content?: { rendered?: string } } | null
  if (post?.status === 'trash') return { kind: 'gone' }
  const html = post?.content?.rendered ?? ''
  return html.trim() ? { kind: 'body', html } : { kind: 'empty' }
}
