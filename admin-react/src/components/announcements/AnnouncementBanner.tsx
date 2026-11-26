/**
 * AnnouncementBanner — operator-pushed messages at the top of Overview.
 *
 * Content is authored by the operator (Supabase row), but bodyHtml is still
 * run through DOMPurify before render — never inject unsanitized HTML. Links
 * are forced to open in a new tab with rel="noopener". Structured imageUrl +
 * ctaLabel/ctaUrl cover the common case (banner image + button) without
 * hand-written markup.
 */
import { useMemo } from 'react'
import DOMPurify from 'dompurify'
import type { Announcement } from '@/api/types'
import { useAnnouncements, useDismissAnnouncement } from '@/hooks/useAnnouncements'

// One-time hook: any sanitized anchor opens safely in a new tab.
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
  if (node.tagName === 'A') {
    node.setAttribute('target', '_blank')
    node.setAttribute('rel', 'noopener noreferrer')
  }
})

const SANITIZE_CONFIG = {
  ALLOWED_TAGS: ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'a', 'ul', 'ol', 'li', 'span', 'img', 'h3', 'h4', 'small'],
  ALLOWED_ATTR: ['href', 'src', 'alt', 'title', 'target', 'rel'],
}

const TONES = new Set(['info', 'success', 'warn'])

export function AnnouncementBanner() {
  const { data: announcements } = useAnnouncements()
  const dismiss = useDismissAnnouncement()

  if (!announcements || announcements.length === 0) return null

  return (
    <div className="bb-ann-stack">
      {announcements.map((a) => (
        <AnnouncementCard key={a.id} announcement={a} onDismiss={() => dismiss.mutate(a.id)} />
      ))}
    </div>
  )
}

function AnnouncementCard({ announcement, onDismiss }: { announcement: Announcement; onDismiss: () => void }) {
  const clean = useMemo(
    () => (announcement.bodyHtml ? DOMPurify.sanitize(announcement.bodyHtml, SANITIZE_CONFIG) : ''),
    [announcement.bodyHtml],
  )
  const tone = TONES.has(announcement.tone) ? announcement.tone : 'info'

  return (
    <article className={`bb-ann bb-ann-${tone}`} role="status">
      {announcement.imageUrl && (
        <img className="bb-ann-img" src={announcement.imageUrl} alt="" />
      )}
      <div className="bb-ann-body">
        <h3 className="bb-ann-title">{announcement.title}</h3>
        {clean && <div className="bb-ann-rich" dangerouslySetInnerHTML={{ __html: clean }} />}
      </div>
      {announcement.ctaLabel && announcement.ctaUrl && (
        <a
          className="bb-btn bb-btn-primary bb-btn-small bb-ann-cta"
          href={announcement.ctaUrl}
          target="_blank"
          rel="noopener noreferrer"
        >
          {announcement.ctaLabel}
        </a>
      )}
      <button type="button" className="bb-ann-dismiss" aria-label="Dismiss" title="Dismiss" onClick={onDismiss}>
        ×
      </button>
    </article>
  )
}
