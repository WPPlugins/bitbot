/**
 * Overview tab — landing surface. Layout:
 *
 *   ┌──────────────────────────────────────────────────────────────┐
 *   │ PlanCard (rendered by App shell, not here)                   │
 *   ├──────────────────────────────────┬───────────────────────────┤
 *   │ InsightBento (2×2)               │ QuickActions              │
 *   │  Top questions · Countries       │ NextBestStep              │
 *   │  Conversation pulse · Captures   │                           │
 *   └──────────────────────────────────┴───────────────────────────┘
 *
 * StatRow + the "01. Insights" section heading were removed because:
 *   - The four StatRow tiles (Conversations / New Leads / Indexed Pages
 *     / Lead Conversion) duplicate the Conversation pulse + Lead capture
 *     cards in the bento.
 *   - Numbering a section "01" only makes sense when there are 02/03
 *     siblings. Insights is the page; no need to label it.
 */
import { InsightBento } from '@/components/overview/InsightBento'
import { QuickActions } from '@/components/overview/QuickActions'
import { NextBestStep } from '@/components/overview/NextBestStep'
import { WidgetStatusBanner } from '@/components/overview/WidgetStatusBanner'
import { AnnouncementBanner } from '@/components/announcements/AnnouncementBanner'

export function Overview() {
  return (
    <>
      {/* Chat-cap messaging now lives in PlanCard (Priority 2 — critical).
          WidgetStatusBanner stays — it surfaces non-billing widget config
          issues (excluded pages, missing brand voice, etc). */}
      <WidgetStatusBanner />
      <AnnouncementBanner />
      <div className="bb-overview-grid">
        <div className="bb-overview-main">
          <InsightBento />
        </div>
        <aside className="bb-overview-aside">
          <QuickActions />
          <NextBestStep />
        </aside>
      </div>
    </>
  )
}
