/**
 * previewController — THE AGENT'S EYES.
 *
 * Drives the same-origin preview iframe (navigate, scroll, click, measure) and
 * reads back what the page actually renders. Same origin as the admin, so the
 * DOM, the computed styles and the box geometry are all readable — this is
 * Playwright-grade inspection of the REAL page, with the site's real assets,
 * at whatever viewport the agent asked for.
 *
 * WHY THIS IS NOT A SCREENSHOT. It used to be: a Cloudflare Puppeteer Worker
 * rendered the page and the agent looked at a picture. The picture lied. The
 * page HTML came from inside the host (loopback, immune to any bot wall) but the
 * headless browser still fetched every IMAGE, STYLESHEET and FONT from the
 * public edge, where CDN rules and bot protection break them — so the owner got
 * a broken rendition and the agent judged a page that never looked like that.
 * Here the owner is looking at the actual page, and the agent reads what the
 * browser computed. `imgNaturalWidth > 0` PROVES an asset arrived;
 * `contrastRatio` is computed from the colours that were actually painted.
 *
 * SECURITY. The frame is same-origin AND carries the owner's administrator
 * session, so it is not a sandbox — it is a loaded gun pointed at their site if
 * anything in the page can be made to act. Five independent layers, because a
 * prompt injection only needs one gap:
 *
 *   0. THE FRAMED PAGE RUNS NO JAVASCRIPT AT ALL. The iframe's sandbox grants
 *      `allow-same-origin` (which is what lets US read contentDocument) and
 *      WITHHOLDS `allow-scripts`. This layer is first because it is the only one
 *      that closes the whole class: with `allow-scripts`, a genuinely same-origin
 *      page carrying the owner's admin session can reach `window.parent.document`
 *      and read or rewrite the admin SPA — no click, no navigation, no user
 *      interaction. A compromised plugin or theme, a stored-XSS post body, or
 *      content the agent itself published from an untrusted web source would all
 *      have had it. Layers 1–4 stop navigation and submission; not one of them
 *      stops a page's own script from reaching out of the frame.
 *   1. Front-end paths only, enforced here AND in the backend tool.
 *   2. The origin must match the admin's own.
 *   3. A click may TOGGLE UI; it may never navigate or submit — and that is
 *      decided HERE, in the parent, BEFORE the click (`whyClickUnsafe`), because
 *      layer 0 turns out to disarm the old in-frame version of this guard on
 *      WebKit: a capture-phase listener the parent adds to a scripts-disabled
 *      document never fires there, while `el.click()` still runs the browser's
 *      default action. The in-frame cancel is still installed behind it. (The
 *      deleted version of this file had neither: it closed the "point it at
 *      /wp-admin" vector and left the "click a nonce-carrying front-end link in
 *      the owner's session" one open.)
 *   4. The plugin removes the admin bar from the frame, so the nonce-carrying
 *      links are not in the document at all.
 *
 * WHAT LAYER 0 COSTS, AND WHY THE EYES SURVIVE IT. Every measurement here runs
 * in the PARENT, reaching into the frame — `iframe.contentDocument`,
 * `win.getComputedStyle`, `win.scrollTo`, `img.naturalWidth`. None of it is
 * injected into the frame, so none of it needs the frame to be allowed to run
 * scripts; `allow-same-origin` alone is what it depends on. Measured across
 * Chromium, Firefox and WebKit against this file's real entry point: every
 * measurement it produces — geometry, computed styles, contrast, overflow,
 * naturalWidth, sticky detection, rendered text, the status meta — is identical
 * with and without `allow-scripts`, and the only field that moves is the page
 * text its own script would have rewritten.
 *
 * What is lost is the page's OWN scripting: a JS-driven slider, a JS lazy-loader,
 * a script-built layout, a hamburger that opens through the Interactivity API. So
 * this is a JS-off rendition — truthful and more deterministic than the scripted
 * one, but NOT what a visitor with JS sees, and the result SAYS so (`scripting`)
 * rather than letting the agent assume otherwise.
 *
 * TWO THINGS ONLY WEBKIT DOES, both found by measuring rather than reasoning, and
 * both handled above: it does not run animation-frame callbacks in a
 * scripts-disabled document (so `settle`'s two-frame wait must be raced against a
 * deadline or every look on Safari hangs until the panel's 25s cap and reports a
 * healthy page as one that failed to load), and it does not fire a capture-phase
 * listener the parent adds to that document (so layer 3 moved in front of the
 * click — see `whyClickUnsafe`).
 */

import { CLIP_TOLERANCE_PX, findClipped, type ClipCandidate, type Clipped } from './clipping'
import { FOOTER_NOT_FOUND, NAV_NOT_FOUND, summariseFooterLinks, summariseNav, type FooterLinksMeasure, type NavItemBox, type NavMeasure } from './navMeasure'

/** How many headings a look reports, and how long each may be. */
const HEADINGS_MAX = 8
const HEADING_TEXT_MAX = 120

/** The site's own header and footer: their headings (a site title) are not the page's. */
const SITE_CHROME = 'header.site-header, #masthead, .wp-site-blocks > header, body > header, [role="banner"], footer, [role="contentinfo"], .site-footer'

/**
 * h1 and h2 elements in the page's main area, in document order, painted
 * ones only: the theme's printed title and the page's own headlines.
 */
function readHeadings(doc: Document, win: Window): Array<{ level: 1 | 2; text: string }> {
  const main = doc.querySelector('main, [role="main"], #main, .site-main, #content, .wp-site-blocks') ?? doc.body
  if (!main) return []
  const out: Array<{ level: 1 | 2; text: string }> = []
  for (const el of Array.from(main.querySelectorAll('h1, h2'))) {
    if (out.length >= HEADINGS_MAX) break
    if (el.closest(SITE_CHROME)) continue
    const style = win.getComputedStyle(el)
    const box = (el as HTMLElement).getBoundingClientRect()
    if (style.display === 'none' || style.visibility === 'hidden' || box.width === 0 || box.height === 0) continue
    const text = ((el as HTMLElement).innerText || el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, HEADING_TEXT_MAX)
    if (text) out.push({ level: el.tagName === 'H1' ? 1 : 2, text })
  }
  return out
}

/** The site header, chosen as readChrome chooses it. */
const findHeader = (doc: Document): HTMLElement | null =>
  (doc.querySelector('header.wp-block-template-part') as HTMLElement | null) ||
  (doc.querySelector('.wp-site-blocks > header') as HTMLElement | null) ||
  (doc.querySelector('header') as HTMLElement | null)

/** A navigation: the block theme's navigation block, any nav element, or a classic theme's primary menu container. */
const NAV_CANDIDATES = '.wp-block-navigation, nav, #site-navigation, .main-navigation, .primary-navigation, #primary-menu, .menu-primary-container'

/** A button that opens a folded menu, wherever the theme puts it in the header. */
const MENU_TOGGLE =
  '.wp-block-navigation__responsive-container-open, .menu-toggle, .elementor-menu-toggle, button[aria-controls], button[aria-expanded], [class*="hamburger"], [class*="menu-toggle"]'

/** The `li` items of a navigation that sit under no other `li` of it. */
export function topLevelItems(nav: HTMLElement): HTMLElement[] {
  return Array.from(nav.querySelectorAll('li')).filter((li) => {
    const parentItem = li.parentElement?.closest('li')
    return !parentItem || !nav.contains(parentItem)
  }) as HTMLElement[]
}

/** Painted at this width: a box, not hidden, and no transparent ancestor up to `stop`. */
function painted(el: HTMLElement, win: Window, stop: HTMLElement | null): boolean {
  const box = el.getBoundingClientRect()
  if (box.width === 0 || box.height === 0) return false
  const style = win.getComputedStyle(el)
  if (style.visibility === 'hidden' || style.display === 'none') return false
  for (let p: HTMLElement | null = el; p && p !== stop?.parentElement; p = p.parentElement) {
    if (win.getComputedStyle(p).opacity === '0') return false
  }
  return true
}

/**
 * The PRIMARY navigation: inside the site header (as readChrome finds it),
 * the outermost NAV_CANDIDATES in document order, and the first of them that
 * holds at least one top-level item. Several in the header (a top bar, a
 * social list, Elementor's dropdown copy after its main menu): the first with
 * items wins. No header: the same walk over the page, skipping footers.
 */
export function pickNav(doc: Document): HTMLElement | null {
  const header = findHeader(doc)
  const scope: ParentNode = header ?? doc
  const all = Array.from(scope.querySelectorAll(NAV_CANDIDATES)) as HTMLElement[]
  const outermost = all.filter((n) => !all.some((o) => o !== n && o.contains(n)))
  return outermost.find((n) => (header || !n.closest('footer, [role="contentinfo"]')) && topLevelItems(n).length > 0) ?? null
}

export function readNav(doc: Document, win: Window): NavMeasure {
  try {
    const nav = pickNav(doc)
    if (!nav) return NAV_NOT_FOUND
    const boxes: NavItemBox[] = topLevelItems(nav).map((li) => {
      const box = li.getBoundingClientRect()
      const target = (li.querySelector('a, button') as HTMLElement | null) ?? li
      return {
        label: target.textContent || '',
        top: box.top,
        height: box.height,
        visible: painted(li, win, nav),
        fromPageList: !!li.closest('.wp-block-page-list') || li.classList.contains('page_item'),
        fromMenu: li.classList.contains('menu-item') || li.classList.contains('wp-block-navigation-link') || li.classList.contains('wp-block-navigation-item'),
      }
    })
    const toggleScope: ParentNode = findHeader(doc) ?? nav.parentElement ?? nav
    const toggleVisible = Array.from(toggleScope.querySelectorAll(MENU_TOGGLE)).some((t) => painted(t as HTMLElement, win, null))
    return summariseNav(boxes, toggleVisible)
  } catch {
    return NAV_NOT_FOUND
  }
}

/** The site footer: the block theme's footer part, else the page's footer element. */
const findFooter = (doc: Document): HTMLElement | null =>
  (doc.querySelector('footer.wp-block-template-part') as HTMLElement | null) ||
  (doc.querySelector('.wp-site-blocks > footer') as HTMLElement | null) ||
  (doc.querySelector('footer, [role="contentinfo"]') as HTMLElement | null)

/** The footer's links (navMeasure summariseFooterLinks): round 10, eight demo links that went nowhere. */
export function readFooterLinks(doc: Document): FooterLinksMeasure {
  try {
    const footer = findFooter(doc)
    if (!footer) return FOOTER_NOT_FOUND
    const links = Array.from(footer.querySelectorAll('a')).map((a) => {
      const href = a.getAttribute('href')
      const id = href && href.startsWith('#') && href.length > 1 ? decodeURIComponent(href.slice(1)) : ''
      return { label: a.textContent || '', href, anchorOnPage: !!id && !!doc.getElementById(id) }
    })
    return summariseFooterLinks(links)
  } catch {
    return FOOTER_NOT_FOUND
  }
}

export interface PreviewCommand {
  requestId: string
  path: string
  viewport: 'desktop' | 'tablet' | 'mobile'
  measure: string[]
  scrollToSelector?: string
  click?: string
}

export interface MeasuredElement {
  selector: string
  found: boolean
  tag?: string
  box?: { x: number; y: number; w: number; h: number }
  styles?: Record<string, string>
  text?: string
  imgSrc?: string
  imgLoaded?: boolean
  /** The image's intrinsic size. > 0 is PROOF the asset arrived and decoded. */
  imgNaturalWidth?: number
  imgNaturalHeight?: number
  /** The background actually behind this element, resolved past `transparent`. */
  effectiveBackground?: string
  /** WCAG contrast of this element's text against that background, 2dp. */
  contrastRatio?: number
  /** Why no ratio: the composition behind this text could not be resolved. */
  contrastUnknown?: string
  // false when the element is in the DOM but not painted right now
  // (display:none / visibility:hidden / zero box). Its colour and background
  // are NOT a meaningful reading in that state — a state-gated overlay, menu,
  // modal, or hover/focus style is dormant until its state is triggered.
  rendered?: boolean
  note?: string
}

export interface PreviewResult {
  ok: boolean
  error?: string
  url: string
  viewport: string
  title?: string
  /** The page's REAL HTTP status, stated by the plugin in a meta tag — an iframe
   *  navigation exposes none, and a soft 404 renders as a full themed page. */
  httpStatus?: number
  scrollHeight?: number
  viewportWidth?: number
  contentWidth?: number
  viewportHeight?: number
  /**
   * Is the page wider than the screen, what sticks out, and can a visitor
   * actually scroll to it? `scrollable` is tried, not inferred: round 5 E3's
   * page was 70px wider at 388 but body clips overflow-x, so scrollX stayed 0
   * and the content was cut off where nobody could reach it.
   */
  overflowX?: { overflows: boolean; by: number; culprits: string[]; clipped?: Clipped[]; scrollable: boolean }
  /**
   * The main and second-level headings of the page's main area, in order,
   * rendered ones only, capped. Round 8: "the page shows two titles" was
   * printed from the build's report on a page with one H1; whether the title
   * shows twice is now read off the page itself.
   */
  headings?: Array<{ level: 1 | 2; text: string }>
  /**
   * The site menu in the header (navMeasure): how many top-level links, their
   * labels, how many lines they wrap onto, whether they fold behind a menu
   * button at this width, and whether a page list writes them. Round 8 TT25:
   * 18 links on three lines, and no look said so.
   */
  nav?: NavMeasure
  /** The footer's links: how many, and the labels of those that go nowhere (round 10: eight demo links, all "#"). */
  footerLinks?: FooterLinksMeasure
  /** The words a visitor actually reads, bounded. Placeholder markers that never
   *  got filled show up here and nowhere else. */
  renderedText?: string
  /**
   * THE BOUNDARY OF THIS RENDITION, read off the real iframe rather than
   * assumed. The frame withholds `allow-scripts` (see the file header), so the
   * page's own JavaScript does not run and `scriptElements` counts how much of it
   * sat the look out. Reported on every result so the agent knows what these
   * measurements do and do not cover, instead of reading a JS-off render as the
   * page a visitor sees.
   */
  scripting?: { enabled: boolean; scriptElements: number }
  /** What became of the agent's `click`, when it asked for one. Present only
   *  then. A refusal names itself rather than reading as a dead control. */
  click?: { selector: string; clicked: boolean; reason?: string }
  chrome?: Record<string, unknown>
  elements?: MeasuredElement[]
}

const STYLE_KEYS = [
  'color',
  'backgroundColor',
  'fontSize',
  'fontFamily',
  'fontWeight',
  'display',
  'position',
  'marginTop',
  'paddingTop',
  'width',
  'textAlign',
] as const

const round = (n: number) => Math.round(n)

/** How much rendered page text to hand back. Enough to read the copy on any
 *  normal page; bounded so a 5,000-word post cannot blow the tool result. */
const RENDERED_TEXT_CHARS = 6000

// ── colour + contrast, computed from what the browser PAINTED ────────────────
// This is the single most valuable thing measurement gives that a screenshot
// does not: a number, on the defect (illegible text) that an owner notices first
// and that `checkContrast` can only ever check against values the agent PASSED
// IN. Here it is checked against what actually rendered, so a wrapper colour
// winning over the hex the agent wrote is caught rather than argued about.

const parseRgb = (v: string): [number, number, number, number] | null => {
  const m = /^rgba?\(([^)]+)\)$/.exec(v.trim())
  if (!m) return null
  const parts = m[1].split(/[,/]/).map((x) => parseFloat(x.trim()))
  if (parts.length < 3 || parts.some((n) => Number.isNaN(n))) return null
  return [parts[0], parts[1], parts[2], parts.length > 3 ? parts[3] : 1]
}

/**
 * The background actually behind an element.
 *
 * This walked up and returned the FIRST colour with alpha > 0.05, then contrast
 * treated that as opaque. Three ways that lied, all of them confidently:
 *
 *   - a 60%-opaque layer was measured as if it were solid, when what the eye
 *     sees is it composited over whatever is beneath;
 *   - a background IMAGE or gradient was walked straight past, so the ratio came
 *     back against some ancestor's colour that is nowhere near the text;
 *   - nothing resolved at all fell through to white, which is a guess.
 *
 * Now it composites translucent layers properly, and where the composition
 * cannot be resolved it says so instead of returning a number. A photo has no
 * single colour to measure against; "unknown" is the true answer and the agent
 * is built to handle three of them.
 */
type BackgroundResolution =
  | { readonly kind: 'solid'; readonly color: string }
  | { readonly kind: 'unresolved'; readonly why: string }

function resolvedBackground(el: HTMLElement, win: Window): BackgroundResolution {
  const layers: Array<[number, number, number, number]> = []
  let node: HTMLElement | null = el
  while (node) {
    const cs = win.getComputedStyle(node)
    const tag = node.tagName.toLowerCase()
    if (cs.backgroundImage && cs.backgroundImage !== 'none') {
      return {
        kind: 'unresolved',
        why: `a background image or gradient on <${tag}> sits behind this text, so there is no single colour to measure a ratio against`,
      }
    }
    if (cs.mixBlendMode && cs.mixBlendMode !== 'normal') {
      return { kind: 'unresolved', why: `a mix-blend-mode on <${tag}> changes the painted colour in a way this cannot compute` }
    }
    const backdrop =
      (cs as CSSStyleDeclaration & { backdropFilter?: string }).backdropFilter ??
      (cs as CSSStyleDeclaration & { webkitBackdropFilter?: string }).webkitBackdropFilter
    if (backdrop && backdrop !== 'none') {
      return { kind: 'unresolved', why: `a backdrop-filter on <${tag}> alters what shows through, so the background colour here is not what is painted` }
    }
    const op = parseFloat(cs.opacity)
    if (node !== el && Number.isFinite(op) && op < 1) {
      return { kind: 'unresolved', why: `an ancestor <${tag}> is semi-transparent (opacity ${cs.opacity}), so the painted colours are a blend this cannot compute` }
    }
    const rgba = parseRgb(cs.backgroundColor)
    if (rgba && rgba[3] > 0) {
      layers.push(rgba)
      // Opaque: nothing below it can show through, so stop.
      if (rgba[3] >= 0.999) break
    }
    node = node.parentElement
  }
  // Composite bottom-up onto the canvas, which the browser paints white.
  let base: [number, number, number] = [255, 255, 255]
  for (let i = layers.length - 1; i >= 0; i--) {
    const [r, g, b, a] = layers[i]
    base = [r * a + base[0] * (1 - a), g * a + base[1] * (1 - a), b * a + base[2] * (1 - a)]
  }
  return { kind: 'solid', color: `rgb(${Math.round(base[0])}, ${Math.round(base[1])}, ${Math.round(base[2])})` }
}

const luminance = ([r, g, b]: [number, number, number]) => {
  const lin = [r, g, b].map((c) => {
    const s = c / 255
    return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4)
  })
  return 0.2126 * lin[0] + 0.7152 * lin[1] + 0.0722 * lin[2]
}

function contrast(fg: string, bg: string): number | undefined {
  const f = parseRgb(fg)
  const b = parseRgb(bg)
  if (!f || !b) return undefined
  // A translucent foreground is composited over the background before it is
  // read, or the ratio would be reported as if the text were opaque.
  const a = f[3]
  const comp: [number, number, number] = [
    f[0] * a + b[0] * (1 - a),
    f[1] * a + b[1] * (1 - a),
    f[2] * a + b[2] * (1 - a),
  ]
  const l1 = luminance(comp)
  const l2 = luminance([b[0], b[1], b[2]])
  const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05)
  return Math.round(ratio * 100) / 100
}

function measureOne(doc: Document, win: Window, selector: string): MeasuredElement {
  try {
    const el = doc.querySelector(selector) as HTMLElement | null
    if (!el) return { selector, found: false }
    const r = el.getBoundingClientRect()
    const cs = win.getComputedStyle(el)
    const styles: Record<string, string> = {}
    for (const k of STYLE_KEYS) styles[k] = cs[k as keyof CSSStyleDeclaration] as string
    const out: MeasuredElement = {
      selector,
      found: true,
      tag: el.tagName.toLowerCase(),
      box: { x: round(r.x), y: round(r.y), w: round(r.width), h: round(r.height) },
      styles,
      text: (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 200),
    }
    // An element can be present but not painted: a mobile-nav overlay, dropdown,
    // modal, or any :hover/:focus/aria-expanded style is dormant until its state
    // is triggered. In that state getComputedStyle returns the RESTING values
    // (e.g. a transparent background), which are not evidence about the
    // state-gated style. Flag it so a resting reading is never mistaken for the
    // real one — to inspect such an element, re-run with `click` set to its
    // trigger so it is measured OPEN.
    const notPainted =
      cs.display === 'none' || cs.visibility === 'hidden' || (r.width === 0 && r.height === 0)
    out.rendered = !notPainted
    if (notPainted) {
      out.note =
        'not painted in the current state (display:none / hidden / zero-box) — its colour and background are NOT a meaningful reading here. To verify an overlay, menu, modal, or hover/focus style, re-run viewSite with `click` set to the control that opens it, then measure it in the open state.'
    } else if ((el.textContent || '').trim().length > 0) {
      // Contrast only where there is text to read, and only in a state where it
      // is actually painted — a ratio off a dormant overlay is noise.
      const bg = resolvedBackground(el, win)
      if (bg.kind === 'solid') {
        out.effectiveBackground = bg.color
        const ratio = contrast(cs.color, bg.color)
        if (ratio !== undefined) out.contrastRatio = ratio
      } else {
        // NO NUMBER RATHER THAN A WRONG ONE. A ratio here would be measured
        // against a colour that is not behind the text.
        out.contrastUnknown = bg.why
      }
    }
    const img = (el.tagName === 'IMG' ? el : el.querySelector('img')) as HTMLImageElement | null
    if (img) {
      out.imgSrc = img.currentSrc || img.src || ''
      out.imgLoaded = img.complete && img.naturalWidth > 0
      out.imgNaturalWidth = img.naturalWidth
      out.imgNaturalHeight = img.naturalHeight
    }
    return out
  } catch (e) {
    return { selector, found: false, text: `measure error: ${e instanceof Error ? e.message : String(e)}` }
  }
}

/**
 * Does the page scroll sideways, and which elements stick out?
 *
 * A Critical in the review skill and the single most common mobile defect. It is
 * a MEASUREMENT — the document is wider than the viewport or it is not — so it
 * can be reported without arguing about taste. A screenshot of a page scrolled
 * to its left edge shows nothing at all.
 */
/** `div#hero.wp-block-group`: tag, id, first class. */
const selectorOf = (el: Element): string => {
  const id = el.id ? `#${el.id}` : ''
  const cls = el.className && typeof el.className === 'string' && el.className.trim() ? `.${el.className.trim().split(/\s+/)[0]}` : ''
  return `${el.tagName.toLowerCase()}${id}${cls}`
}

/** How many elements of the content root one look examines for clipping. */
const CLIP_SCAN_MAX = 4000

/**
 * Held inside a box that clips on purpose: an ancestor inside the content
 * root that hides its own overflow and itself sits on the screen (a slider
 * track). The body or html clipping (Hello Elementor's overflow-x: clip) is
 * not that: that is the screen edge cutting the content off.
 */
function clippedOnPurpose(el: HTMLElement, root: HTMLElement, win: Window, iw: number): boolean {
  const doc = root.ownerDocument
  for (let p = el.parentElement; p && p !== root && p !== doc.body && p !== doc.documentElement; p = p.parentElement) {
    if (win.getComputedStyle(p).overflowX === 'visible') continue
    const r = p.getBoundingClientRect()
    if (r.left >= -CLIP_TOLERANCE_PX && r.right <= iw + CLIP_TOLERANCE_PX) return true
  }
  return false
}

/**
 * Content cut off by the left or right edge of the screen (clipping.ts). Left
 * overflow never scrolls, and a body with overflow-x: clip hides right
 * overflow too, so scrollWidth alone misses both (round 3 E3: sections at
 * x=-70 on Hello Elementor reported overflows:false).
 */
function readClipped(doc: Document, win: Window): Clipped[] {
  try {
    const root = (doc.querySelector('main') || doc.querySelector('.wp-site-blocks') || doc.body) as HTMLElement | null
    if (!root) return []
    const iw = win.innerWidth
    const keyOf = new Map<Element, number>()
    const candidates: ClipCandidate[] = []
    Array.from(root.querySelectorAll<HTMLElement>('*'))
      .slice(0, CLIP_SCAN_MAX)
      .forEach((el, key) => {
        keyOf.set(el, key)
        let parentKey: number | null = null
        for (let p = el.parentElement; p && p !== root; p = p.parentElement) {
          const k = keyOf.get(p)
          if (k !== undefined) {
            parentKey = k
            break
          }
        }
        const r = el.getBoundingClientRect()
        const cut = r.left < -CLIP_TOLERANCE_PX || r.right > iw + CLIP_TOLERANCE_PX
        // Style is read only for what the geometry already flags.
        let skip = false
        if (cut) {
          const cs = win.getComputedStyle(el)
          skip = cs.display === 'none' || cs.visibility === 'hidden' || cs.position === 'fixed' || clippedOnPurpose(el, root, win, iw)
        }
        candidates.push({ key, parentKey, selector: selectorOf(el), left: r.left, right: r.right, width: r.width, height: r.height, skip })
      })
    return findClipped(candidates, iw)
  } catch {
    return []
  }
}

/**
 * Does the document really scroll sideways? Scroll right by the overflow and
 * read back where it landed, then put it back. A body or html that clips
 * overflow-x reports a wide scrollWidth and never moves.
 */
function scrollsSideways(doc: Document, win: Window, by: number): boolean {
  if (by <= 1) return false
  const x0 = win.scrollX
  const y0 = win.scrollY
  try {
    win.scrollTo(x0 + by, y0)
    const se = (doc.scrollingElement as HTMLElement | null) ?? doc.documentElement
    const moved = win.scrollX > x0 || se.scrollLeft > x0
    win.scrollTo(x0, y0)
    return moved
  } catch {
    const clips = (el: Element) => /hidden|clip/.test(win.getComputedStyle(el).overflowX)
    return !(clips(doc.documentElement) || (doc.body ? clips(doc.body) : false))
  }
}

function readOverflow(doc: Document, win: Window): PreviewResult['overflowX'] {
  try {
    const vw = doc.documentElement.clientWidth
    const sw = doc.documentElement.scrollWidth
    const by = sw - vw
    const clipped = readClipped(doc, win)
    const scrollable = scrollsSideways(doc, win, by)
    if (by <= 1) return clipped.length ? { overflows: true, by: 0, culprits: [], clipped, scrollable } : { overflows: false, by: 0, culprits: [], scrollable }
    const culprits: string[] = []
    for (const el of Array.from(doc.querySelectorAll<HTMLElement>('body *'))) {
      if (culprits.length >= 6) break
      const r = el.getBoundingClientRect()
      if (r.width === 0 && r.height === 0) continue
      if (r.right <= vw + 1) continue
      if (win.getComputedStyle(el).position === 'fixed') continue
      const id = el.id ? `#${el.id}` : ''
      const cls = el.className && typeof el.className === 'string' ? `.${el.className.trim().split(/\s+/)[0]}` : ''
      culprits.push(`${el.tagName.toLowerCase()}${id}${cls} (right edge ${round(r.right)}px)`)
    }
    return { overflows: true, by, culprits, scrollable, ...(clipped.length ? { clipped } : {}) }
  } catch {
    return undefined
  }
}

function readChrome(doc: Document, win: Window): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  try {
    // Chrome geometry is top-of-page; measure from the top regardless of any
    // scroll a previous step left behind.
    win.scrollTo(0, 0)
    const header =
      (doc.querySelector('header.wp-block-template-part') as HTMLElement | null) ||
      (doc.querySelector('.wp-site-blocks > header') as HTMLElement | null) ||
      (doc.querySelector('header') as HTMLElement | null)
    const cover = doc.querySelector('.wp-block-cover') as HTMLElement | null
    const main = doc.querySelector('.wp-site-blocks main, main') as HTMLElement | null
    const firstContent = (main?.firstElementChild as HTMLElement | null) || (header?.nextElementSibling as HTMLElement | null)

    if (header) {
      const hr = header.getBoundingClientRect()
      out.headerFound = true
      out.headerHeight = round(hr.height)
      out.headerBottom = round(hr.bottom)
      out.navLinks = Array.from(header.querySelectorAll('a'))
        .slice(0, 8)
        .map((a) => ({
          text: (a.textContent || '').trim().slice(0, 30),
          color: win.getComputedStyle(a).color,
        }))
    } else {
      out.headerFound = false
    }
    const firstTop = firstContent ? round(firstContent.getBoundingClientRect().top) : null
    out.firstContentTop = firstTop
    if (header && firstTop !== null) out.gapHeaderToContent = firstTop - round(header.getBoundingClientRect().bottom)
    out.coverPresent = !!cover
    if (cover) out.coverTop = round(cover.getBoundingClientRect().top)
    // Is there a footer ELEMENT at all? A probe selector inside it that
    // matched nothing (a classic theme's footer has no site-title block) is
    // not a missing footer; this is the fact the API reports.
    out.footerFound = !!(
      doc.querySelector('footer.wp-block-template-part') ||
      doc.querySelector('.wp-site-blocks > footer') ||
      doc.querySelector('footer') ||
      doc.querySelector('[role="contentinfo"]')
    )

    // Sticky check: scroll down, read the header's viewport top, scroll back.
    // Only a verdict when the page ACTUALLY scrolled — a short page that can't
    // scroll would otherwise read as "pinned" (a false positive).
    if (header) {
      const y0 = win.scrollY
      win.scrollTo(0, 700)
      const achieved = win.scrollY
      if (achieved >= 50) {
        const topWhileScrolled = round(header.getBoundingClientRect().top)
        out.headerPinnedOnScroll = topWhileScrolled >= -1 && topWhileScrolled <= 48
        out.headerTopWhileScrolled = topWhileScrolled
      } else {
        out.headerPinnedOnScroll = null
        out.stickyNote = 'page too short to scroll — sticky not testable'
      }
      win.scrollTo(0, y0)
    }
  } catch (e) {
    out.chromeError = e instanceof Error ? e.message : String(e)
  }
  return out
}

const wait = (ms: number) => new Promise<void>((r) => setTimeout(r, ms))

// The preview only ever shows the PUBLIC front-end. wp-admin / login / API
// paths are same-origin and authenticated — navigating there and clicking a
// nonce-carrying row action would perform a real destructive admin action.
// Confine every navigation to the front-end.
const ADMIN_PATH = /^\/(wp-admin(\/|$)|wp-login\.php|xmlrpc\.php|wp-json(\/|$)|wp-cron\.php)/i
export function frontEndUrl(base: string, rawPath: string): { url: string } | { error: string } {
  let baseOrigin: string
  try {
    baseOrigin = new URL(base).origin
  } catch {
    return { error: 'preview base URL is invalid' }
  }
  if (typeof window !== 'undefined' && baseOrigin !== window.location.origin) {
    return { error: 'preview origin mismatch: the front-end and admin are on different origins, so the panel cannot read the page' }
  }
  const clean = rawPath.startsWith('/') ? rawPath : `/${rawPath}`
  const pathOnly = clean.split('?')[0]
  if (ADMIN_PATH.test(pathOnly)) {
    return { error: 'the preview only shows the public site, not the WordPress admin' }
  }
  return { url: `${baseOrigin}${clean}${clean.includes('?') ? '&' : '?'}bb_view=1` }
}

/**
 * Wait until the page is genuinely SETTLED, rather than sleeping and hoping.
 *
 * The version of this that was deleted slept 600ms after `load` "to let the
 * block theme lay out / fonts settle", which is a coin flip on a cold cache —
 * and a measurement taken mid-layout is worse than no measurement, because it
 * looks like a fact. Fonts and images are awaited explicitly, which is also what
 * makes `imgNaturalWidth` a truthful proof-of-load rather than a race.
 */
async function settle(win: Window, doc: Document): Promise<void> {
  const deadline = Date.now() + 6000
  try {
    await Promise.race([doc.fonts?.ready ?? Promise.resolve(), wait(3000)])
  } catch {
    /* no font API — carry on */
  }
  const imgs = Array.from(doc.images || [])
  await Promise.race([
    Promise.all(
      imgs.map((im) =>
        im.complete
          ? Promise.resolve()
          : new Promise<void>((r) => {
              im.addEventListener('load', () => r(), { once: true })
              im.addEventListener('error', () => r(), { once: true })
            }),
      ),
    ),
    wait(Math.max(0, deadline - Date.now())),
  ])
  // Two frames, but NEVER unbounded. A frame the compositor never paints (a
  // collapsed ancestor, a browser that declines animation callbacks in a
  // scripts-disabled document) would otherwise hang the whole look here with no
  // deadline of its own, and the panel's 25s cap would report it as a page that
  // failed to load. Racing it means the worst case is a measurement taken one
  // tick early, which is visible in the numbers, rather than a lie about why.
  await Promise.race([
    new Promise<void>((r) => win.requestAnimationFrame(() => win.requestAnimationFrame(() => r()))),
    wait(1000),
  ])
}

/**
 * Did the page's own JavaScript run? Read off the REAL iframe element, never
 * assumed: if someone ever re-grants `allow-scripts` this flips on its own
 * rather than continuing to claim something that stopped being true.
 */
export function readScripting(iframe: HTMLIFrameElement, doc: Document): PreviewResult['scripting'] {
  try {
    const enabled = !iframe.hasAttribute('sandbox') || iframe.sandbox.contains('allow-scripts')
    return { enabled, scriptElements: doc.querySelectorAll('script').length }
  } catch {
    return undefined
  }
}

/**
 * A CLICK MAY TOGGLE UI; IT MAY NEVER ACT — decided HERE, in the parent, before
 * the click happens.
 *
 * `blockActions` below cancels navigation from inside the framed document, and
 * it is still installed. It is no longer the primary guard, because it cannot be:
 * measured in WebKit, a capture-phase listener the PARENT adds to a
 * scripts-disabled document never fires, while the parent's own `.click()` still
 * runs the browser's default action (a `<details>` really does open). So on
 * Safari that layer is inert and an anchor click would navigate. A guard that
 * silently stops existing in one of three engines is not a guard.
 *
 * Refusing BEFORE the click needs nothing to run inside the frame, so it holds
 * everywhere. It also costs nothing real: with page script blocked, clicking a
 * navigating anchor could only ever navigate — there is no handler left for the
 * click to reach — whereas the things `click` genuinely exists to open without
 * script (`<details>`/`<summary>`, a `<label>` driving a checkbox, an
 * `<a href="#">` CSS toggle, a `:focus-within` menu) are all allowed through.
 *
 * Returns null when the click is safe, or the reason it was refused.
 */
export function whyClickUnsafe(el: HTMLElement, doc: Document): string | null {
  const anchor = el.closest?.('a[href]') as HTMLAnchorElement | null
  if (anchor) {
    // Same page + different (or no) fragment = a CSS/ARIA toggle, which is the
    // legitimate case. Anything that would actually go somewhere is refused —
    // including an authenticated admin GET, which is the whole threat.
    const here = doc.location.href.split('#')[0]
    const there = (anchor.href || '').split('#')[0]
    if (there && there !== here) {
      return `refused: "${el.tagName.toLowerCase()}" is inside a link that would navigate the preview away from this page. A click may open UI; it may never act — this frame carries the owner's logged-in admin session.`
    }
  }
  const form = el.closest?.('form') as HTMLFormElement | null
  if (form) {
    const tag = el.tagName.toLowerCase()
    const type = (el.getAttribute('type') || '').toLowerCase()
    const submits =
      (tag === 'button' && type !== 'button' && type !== 'reset') ||
      (tag === 'input' && (type === 'submit' || type === 'image'))
    if (submits) {
      return `refused: "${tag}" submits a form on the page. A click may open UI; it may never act — this frame carries the owner's logged-in admin session.`
    }
  }
  return null
}

/**
 * Second layer for the same rule, inside the document.
 *
 * Still installed because it holds in Chromium and Firefox and costs one line,
 * but see `whyClickUnsafe`: it does not fire in WebKit with page script blocked,
 * so it is defence in depth and never the thing being relied on.
 */
function blockActions(doc: Document): void {
  // preventDefault ONLY, never stopPropagation. A capture-phase listener on the
  // document runs before the target's own handlers, so cancelling propagation
  // there would silence the page's JS as well — and a submenu whose toggle is an
  // `<a href="#">` (plenty of themes) would stop opening, which is the one thing
  // the agent's `click` exists to do. preventDefault alone stops the browser
  // following the href while leaving every listener on the page intact.
  const blockNavigation = (e: Event) => {
    const t = e.target as HTMLElement | null
    if (t?.closest?.('a[href]')) e.preventDefault()
  }
  const blockSubmit = (e: Event) => e.preventDefault()
  doc.addEventListener('click', blockNavigation, true)
  doc.addEventListener('submit', blockSubmit, true)
}

/** The plugin states the page's real HTTP status in a meta tag, because an
 *  iframe navigation exposes none and a soft 404 renders as a full themed page. */
function readHttpStatus(doc: Document): number | undefined {
  const meta = doc.querySelector('meta[name="bitbot-render-status"]') as HTMLMetaElement | null
  const n = meta ? parseInt(meta.content, 10) : NaN
  return Number.isFinite(n) && n > 0 ? n : undefined
}

async function navigate(iframe: HTMLIFrameElement, url: string): Promise<void> {
  await new Promise<void>((resolve) => {
    let settled = false
    const done = () => {
      if (settled) return
      settled = true
      iframe.removeEventListener('load', done)
      resolve()
    }
    iframe.addEventListener('load', done)
    iframe.src = url
    // Safety net: resolve even if load never fires (cached, blocked).
    setTimeout(done, 12000)
  })
}

/**
 * Execute one command against the (already-mounted, viewport-sized) iframe and
 * return the structured reading. The caller sizes the iframe to the requested
 * viewport BEFORE calling this so measurements reflect that width.
 */
export async function runPreviewCommand(
  iframe: HTMLIFrameElement,
  cmd: PreviewCommand,
  siteBase: string,
): Promise<PreviewResult> {
  const resolved = frontEndUrl(siteBase.replace(/\/$/, ''), cmd.path)
  if ('error' in resolved) {
    return { ok: false, error: resolved.error, url: '', viewport: cmd.viewport }
  }
  // Unique per look so the browser never reuses a prior frame and any edge
  // cache is bypassed — the agent must see the CURRENT page, not a copy from
  // before its own edit. The requestId is unique per viewSite call.
  const url = `${resolved.url}&_bb=${cmd.requestId}`
  try {
    await navigate(iframe, url)
    const win = iframe.contentWindow
    const doc = iframe.contentDocument
    if (!win || !doc) {
      return {
        ok: false,
        error:
          'the page loaded but its document is not readable from the admin — the site is refusing to be framed, or it is served from a different origin than the admin',
        url,
        viewport: cmd.viewport,
      }
    }
    await settle(win, doc)
    // Install the action block BEFORE any click can happen.
    blockActions(doc)
    let clickOutcome: PreviewResult['click']
    if (cmd.click) {
      try {
        const target = doc.querySelector(cmd.click) as HTMLElement | null
        if (!target) {
          clickOutcome = { selector: cmd.click, clicked: false, reason: 'no element matched that selector' }
        } else {
          const unsafe = whyClickUnsafe(target, doc)
          if (unsafe) {
            // SAID, never swallowed. The agent asked to open something and it did
            // not open; if that came back as silence it would read the still-shut
            // element as a broken menu and go and "fix" a page that is fine.
            clickOutcome = { selector: cmd.click, clicked: false, reason: unsafe }
          } else {
            target.click()
            await wait(500)
            clickOutcome = { selector: cmd.click, clicked: true }
          }
        }
      } catch (e) {
        clickOutcome = {
          selector: cmd.click,
          clicked: false,
          reason: `click failed: ${e instanceof Error ? e.message : String(e)}`,
        }
      }
    }
    // Chrome geometry wants top-of-page position, so read it BEFORE any
    // scrollToSelector moves the viewport (readChrome parks the scroll at 0
    // and restores it after its own sticky check).
    const chrome = readChrome(doc, win)
    const overflowX = readOverflow(doc, win)
    const nav = readNav(doc, win)
    const footerLinks = readFooterLinks(doc)
    if (cmd.scrollToSelector) {
      try {
        ;(doc.querySelector(cmd.scrollToSelector) as HTMLElement | null)?.scrollIntoView()
        await wait(300)
      } catch {
        /* ignore */
      }
    }
    const elements = cmd.measure.map((sel) => measureOne(doc, win, sel))
    const main = (doc.querySelector('main, .wp-site-blocks, body') as HTMLElement | null) ?? doc.body
    return {
      ok: true,
      url,
      viewport: cmd.viewport,
      title: doc.title,
      httpStatus: readHttpStatus(doc),
      scripting: readScripting(iframe, doc),
      click: clickOutcome,
      scrollHeight: round(doc.documentElement.scrollHeight),
      viewportWidth: win.innerWidth,
      contentWidth: doc.documentElement.clientWidth,
      viewportHeight: win.innerHeight,
      overflowX,
      renderedText: (main?.innerText || main?.textContent || '')
        .replace(/[ \t]+/g, ' ')
        .replace(/\n{3,}/g, '\n\n')
        .trim()
        .slice(0, RENDERED_TEXT_CHARS),
      chrome,
      elements,
      headings: readHeadings(doc, win),
      nav,
      footerLinks,
    }
  } catch (e) {
    return {
      ok: false,
      error: e instanceof Error ? e.message : String(e),
      url,
      viewport: cmd.viewport,
    }
  }
}
