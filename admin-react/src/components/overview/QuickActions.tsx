/**
 * QuickActions — 4-item shortcut panel in the right sidebar of the
 * "What's happening" row. Each row: icon, title, sub-line, chevron.
 *
 * 2.0.3: sub-lines no longer hardcoded ("42 captured · 4 new this
 * week" was a copy-paste artefact from the mock). Lead count pulls
 * live from GetUnviewedLeadsCount; sync sub-line reflects last sync
 * timestamp. Sync button now triggers the same WP AJAX action as
 * SyncTrigger on the Chatbot tab.
 */
import type { ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useAccessMode } from '@/hooks/useAccessMode'
import { useSync } from '@/hooks/useSync'
import { rpc } from '@/api/rpc'
import { getConfig } from '@/lib/config'
import { relativeTime } from '@/lib/utils'

interface Action {
  icon: ReactNode
  title: string
  sub: string
  onClick: () => void
  disabled?: boolean
  disabledHint?: string
}

function ChevronRight() {
  return (
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="1.5">
      <path d="m9 6 6 6-6 6" />
    </svg>
  )
}

const ICONS = {
  sync: (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.5">
      <path d="M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M21 12a9 9 0 0 1-15 6.7L3 16M3 21v-5h5" />
    </svg>
  ),
  leads: (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.5">
      <path d="M4 4h16v16H4z" />
      <path d="m4 7 8 5 8-5" />
    </svg>
  ),
  generate: (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.5">
      <path d="M12 5v14M5 12h14" />
    </svg>
  ),
  testChat: (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="1.5">
      <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
    </svg>
  ),
}

export function QuickActions() {
  const { isExpired } = useAccessMode()
  // Shared sync state + trigger — identical to the Knowledgebase SyncTrigger.
  const { isIndexing, label, failed, lastSync, trigger } = useSync()
  const cfg = getConfig()
  const isDevMock = cfg.apiKey === 'bb_devmockapikey0000000000000000'

  // Live unviewed-lead count for the View leads sub-line.
  const { data: unviewed } = useQuery({
    queryKey: ['unviewed-leads-count'],
    queryFn: async (): Promise<number> => {
      if (isDevMock) return 4
      const r = await rpc<{ count: number }>('GetUnviewedLeadsCount', {})
      return r.success ? r.data.count : 0
    },
    staleTime: 60 * 1000,
  })

  const goToTab = (tab: string) => {
    const url = new URL(window.location.href)
    url.searchParams.set('tab', tab)
    window.history.pushState({}, '', url)
    window.dispatchEvent(new PopStateEvent('popstate'))
  }

  // Title + sub come from the shared label so this reads word-for-word like the
  // Knowledgebase strip, whether the work is the WP gather or a sitemap fill.
  const syncTitle = label
    ? label.title
    : failed
      ? 'Sync didn’t finish'
      : 'Sync content'
  const syncSub = label
    ? (label.detail || 'Starting…')
    : failed
      ? 'Try again from the Knowledgebase tab'
      : (lastSync ? `Last sync ${relativeTime(lastSync)}` : 'Re-crawl your site for new pages')

  const leadsSub = unviewed && unviewed > 0
    ? `${unviewed} new ${unviewed === 1 ? 'lead' : 'leads'} to review`
    : 'See everyone who got in touch'

  const actions: Action[] = [
    {
      icon: ICONS.sync,
      title: syncTitle,
      sub: syncSub,
      onClick: () => trigger(),
      disabled: isExpired || isIndexing,
      disabledHint: isExpired ? 'Reactivate your plan to sync content' : undefined,
    },
    {
      icon: ICONS.leads,
      title: 'View leads',
      sub: leadsSub,
      onClick: () => goToTab('leads'),
    },
    {
      icon: ICONS.generate,
      title: 'Generate a blog post',
      sub: 'From a topic, URL, or upload',
      onClick: () => goToTab('content'),
    },
    {
      icon: ICONS.testChat,
      title: 'Open the chat',
      sub: 'Test your bot like a visitor',
      onClick: () => window.open(cfg.siteUrl || '/', '_blank'),
    },
  ]

  return (
    <section className="bb-card bb-quickactions-card">
      <header className="bb-card-header">
        <h3 className="bb-card-title">
          <span className="bb-eyebrow">Shortcuts</span>
          <span>Quick actions</span>
        </h3>
      </header>
      <ul className="bb-quickactions-list">
        {actions.map((action, i) => (
          <li key={i}>
            <button
              className="bb-quickaction"
              onClick={action.onClick}
              disabled={action.disabled}
              title={action.disabledHint}
            >
              <span className="bb-quickaction-icon">{action.icon}</span>
              <span className="bb-quickaction-text">
                <span className="bb-quickaction-title">{action.title}</span>
                <span className="bb-quickaction-sub">{action.sub}</span>
              </span>
              <span className="bb-quickaction-chevron"><ChevronRight /></span>
            </button>
          </li>
        ))}
      </ul>
    </section>
  )
}
