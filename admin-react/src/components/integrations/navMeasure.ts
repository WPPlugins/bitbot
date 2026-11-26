/**
 * THE SITE MENU, AS A MEASUREMENT.
 *
 * Round 1 to 8 (2026-10-02, foretelllamp on Twenty Twenty-Five): the header's
 * navigation was a page list, so all 18 published pages sat in the menu and
 * wrapped onto three lines. Every look measured overflow, clipping and
 * headings, and nothing measured the menu, so no run ever said it.
 *
 * This is the pure part: previewController walks the DOM, reads each
 * top-level item's box and where it came from, and hands the list here. No DOM
 * access, so the API's tests run it directly.
 */

/** The most labels one look reports. */
export const NAV_LABELS_MAX = 30
/** How long one label may be. */
export const NAV_LABEL_TEXT_MAX = 60
/** Two items whose vertical centres are this close sit on the same line. */
export const NAV_ROW_TOLERANCE_PX = 6

/** One top-level menu item as the walk read it. */
export interface NavItemBox {
  readonly label: string
  /** The item's top edge and height in the viewport, px. */
  readonly top: number
  readonly height: number
  /** Painted at this width: a box, not visibility:hidden, no transparent ancestor. */
  readonly visible: boolean
  /** Rendered by a page list (`.wp-block-page-list`, or a classic theme's `li.page_item` fallback). */
  readonly fromPageList: boolean
  /** Rendered from a menu someone chose (a navigation link, a classic `menu-item`). */
  readonly fromMenu: boolean
}

export type NavSource = 'page-list' | 'menu' | 'unknown'

export interface NavMeasure {
  readonly found: boolean
  /** Top-level items, painted or not. */
  readonly items: number
  /** Their labels in order, capped at NAV_LABELS_MAX. */
  readonly labels: string[]
  /** Distinct lines the painted top-level items sit on (0 when none is painted). */
  readonly rows: number
  /** Items hidden behind a menu button at this width. */
  readonly collapsed: boolean
  readonly source: NavSource
}

export const NAV_NOT_FOUND: NavMeasure = { found: false, items: 0, labels: [], rows: 0, collapsed: false, source: 'unknown' }

/**
 * How many lines the items sit on: their vertical centres, sorted, a new line
 * wherever the next centre is more than `tolerance` below the current line's
 * first. Centres, not top edges, so a taller button-styled item on the same
 * line as plain links is not a line of its own.
 */
export function countRows(boxes: ReadonlyArray<{ readonly top: number; readonly height: number }>, tolerance: number = NAV_ROW_TOLERANCE_PX): number {
  const centres = boxes
    .map((b) => b.top + b.height / 2)
    .filter((c) => Number.isFinite(c))
    .sort((a, b) => a - b)
  let rows = 0
  let lineStart = Number.NEGATIVE_INFINITY
  for (const c of centres) {
    if (c - lineStart > tolerance) {
      rows += 1
      lineStart = c
    }
  }
  return rows
}

const cleanLabel = (s: string): string => s.replace(/\s+/g, ' ').trim().slice(0, NAV_LABEL_TEXT_MAX)

/**
 * The menu from its top-level items. `toggleVisible`: a menu button is painted
 * in the header at this width. Collapsed means that button is there and some
 * items are not painted (a phone-width header that folds its menu away).
 */
export function summariseNav(items: ReadonlyArray<NavItemBox>, toggleVisible: boolean): NavMeasure {
  if (items.length === 0) return NAV_NOT_FOUND
  const painted = items.filter((i) => i.visible)
  const source: NavSource = items.some((i) => i.fromPageList) ? 'page-list' : items.some((i) => i.fromMenu) ? 'menu' : 'unknown'
  return {
    found: true,
    items: items.length,
    labels: items.map((i) => cleanLabel(i.label)).slice(0, NAV_LABELS_MAX),
    rows: countRows(painted),
    collapsed: toggleVisible && painted.length < items.length,
    source,
  }
}

// ── THE FOOTER'S LINKS ─────────────────────────────────────────────────────
//
// Round 10 (2026-10-02, foretelllamp on Twenty Twenty-Five): the theme's
// footer carried eight demo links (Blog, About, FAQs, Authors, Events, Shop,
// Patterns, Themes), every one `href="#"`. The eyes walked the page and no look
// said so. The look now counts the footer's links and names the ones that go
// nowhere.

/** The most placeholder labels one look reports. */
export const FOOTER_LABELS_MAX = 20

/** One link in the footer as the walk read it. */
export interface FooterLink {
  readonly label: string
  /** The `href` attribute as written; null when the link has none. */
  readonly href: string | null
  /** For an in-page anchor ("#book"): the page carries that id. */
  readonly anchorOnPage: boolean
}

export interface FooterLinksMeasure {
  readonly found: boolean
  /** Links with a label in the footer. */
  readonly count: number
  /** The labels of the links that go nowhere, in order, capped at FOOTER_LABELS_MAX. */
  readonly placeholders: string[]
}

export const FOOTER_NOT_FOUND: FooterLinksMeasure = { found: false, count: 0, placeholders: [] }

/**
 * An address that is not a link: "#", "/#", "", "javascript:", a bare scheme,
 * or an in-page anchor the page does not carry. The same rule as the API's
 * lib/placeholderLinks `isPlaceholderHref` (tests/footerLinks.test.ts holds
 * them to the same cases).
 */
export function isPlaceholderHref(href: string | null, anchorOnPage = false): boolean {
  if (typeof href !== 'string') return true
  const h = href.trim()
  if (!h) return true
  if (/^javascript:/i.test(h)) return true
  if (/^https?:\/*$/i.test(h)) return true
  if (h === '/#' || h === '#') return true
  if (h.startsWith('#')) return !anchorOnPage
  return false
}

/** The footer's links: how many, and the labels of those that go nowhere. */
export function summariseFooterLinks(links: ReadonlyArray<FooterLink>): FooterLinksMeasure {
  const labelled = links.map((l) => ({ ...l, label: cleanLabel(l.label) })).filter((l) => l.label)
  if (labelled.length === 0) return FOOTER_NOT_FOUND
  return {
    found: true,
    count: labelled.length,
    placeholders: labelled.filter((l) => isPlaceholderHref(l.href, l.anchorOnPage)).map((l) => l.label).slice(0, FOOTER_LABELS_MAX),
  }
}
