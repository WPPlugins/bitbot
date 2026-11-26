/**
 * What goes into "Tone / instructions" when a post is written from a Find Topics suggestion (no React),
 * unit tested. The brief is written by Topic Discovery for THIS site (angle, reader, the gap it closes);
 * a result saved before briefs existed falls back to the suggestion's own reasoning.
 */
export interface TopicForBrief {
  reasoning?: string
  brief?: string
}

export function topicInstructions(s: TopicForBrief): string {
  const brief = (s.brief ?? '').trim()
  if (brief) return brief
  const why = (s.reasoning ?? '').trim()
  return why ? `Why this topic fits the site: ${why}` : ''
}

/**
 * A discovery result as the screen can safely draw it. The backend already sends one valid shape
 * (api lib/topicDiscovery); this only makes sure an unexpected answer shows as "no result" or with empty
 * lists instead of crashing the tab.
 */
export interface DrawableDiscovery<S> {
  suggestions: S[]
  contentGaps: string[]
  sources: { title: string; subreddit: string; url: string; numComments: number }[]
  at?: string
  saved?: boolean
}

const str = (v: unknown): string => (typeof v === 'string' ? v : '')

export function drawableDiscovery<S extends { topic: string }>(raw: unknown): DrawableDiscovery<S> | null {
  if (!raw || typeof raw !== 'object') return null
  const r = raw as Record<string, unknown>
  // Every field the screen calls a string method on is made a string here, whatever arrived.
  const suggestions = (Array.isArray(r.suggestions) ? r.suggestions : [])
    .filter((s): s is Record<string, unknown> => !!s && typeof s === 'object' && typeof (s as { topic?: unknown }).topic === 'string' && (s as { topic: string }).topic !== '')
    .map((s) => ({ ...s, topic: str(s.topic), reasoning: str(s.reasoning), searchQuery: str(s.searchQuery), difficulty: str(s.difficulty), brief: str(s.brief) }) as unknown as S)
  if (suggestions.length === 0) return null
  return {
    suggestions,
    contentGaps: (Array.isArray(r.contentGaps) ? r.contentGaps : []).filter((g): g is string => typeof g === 'string'),
    sources: (Array.isArray(r.sources) ? r.sources : [])
      .filter((s): s is Record<string, unknown> => !!s && typeof s === 'object' && /^https?:\/\//.test(str((s as { url?: unknown }).url)) && typeof (s as { title?: unknown }).title === 'string')
      .map((s) => ({ title: str(s.title), subreddit: str(s.subreddit), url: str(s.url), numComments: Number.isFinite(Number(s.numComments)) ? Number(s.numComments) : 0 })),
    at: typeof r.at === 'string' ? r.at : undefined,
    saved: r.saved === false ? false : undefined,
  }
}

/**
 * Which result stays on screen when an answer arrives: the one whose search finished LATER ("at"), the
 * same rule the database stores by. A slower answer to an older search can arrive after a newer one; it
 * must not replace it. An answer without a time never replaces one that has a time.
 */
export function latestDiscovery<T extends { at?: string }>(current: T | null | undefined, incoming: T | null): T | null {
  if (!incoming) return current ?? null
  if (!current) return incoming
  const a = Date.parse(current.at ?? ''), b = Date.parse(incoming.at ?? '')
  if (!Number.isFinite(b)) return Number.isFinite(a) ? current : incoming
  if (!Number.isFinite(a)) return incoming
  return b >= a ? incoming : current
}
