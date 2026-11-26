/**
 * Tab slugs and URL parsing, kept pure so it can be tested without React.
 *
 * The Agent tab's internal slug is 'integrations' (old deep links use it).
 * `?tab=agent` is the name people see, so it is accepted as an alias for
 * the same screen the Agent tab button opens.
 */
export type TabKey =
  | 'overview'
  | 'chatbot'
  | 'content'
  | 'forms'
  | 'leads'
  | 'conversations'
  | 'integrations'

export const VALID_TABS: ReadonlySet<string> = new Set<TabKey>([
  'overview', 'chatbot', 'content', 'forms', 'leads', 'conversations', 'integrations',
])

const TAB_ALIASES: Readonly<Record<string, TabKey>> = { agent: 'integrations' }

export function parseTab(raw: string | null | undefined): TabKey {
  if (!raw) return 'overview'
  if (Object.hasOwn(TAB_ALIASES, raw)) return TAB_ALIASES[raw]
  return VALID_TABS.has(raw) ? (raw as TabKey) : 'overview'
}
