/**
 * Tiny safe markdown to React renderer, shared by the Agent chat and the
 * Conversations transcript.
 *
 * react-markdown 10 + its remark/rehype dep tree was breaking the
 * Vite bundle with cross-dep type confusion that surfaced as
 * "queryClient.defaultQueryOptions is not a function" at runtime.
 * Until we revisit, this hand-rolled renderer handles the ~95% of
 * markdown a chat reply actually uses: paragraphs, **bold**, *italic*,
 * `inline code`, fenced blocks, simple unordered + ordered lists, tables,
 * links. No HTML passthrough: every non-rendered character is escaped via
 * React text nodes. Written with createElement (no JSX) so the unit tests
 * can load it straight under node.
 */
import { createElement as h, Fragment, type ReactNode } from 'react'

const SAFE_SCHEME = /^(https?|mailto|tel):/i
const ANY_SCHEME = /^[a-z][a-z0-9+.-]*:/i

/**
 * Allow http(s), mailto, tel and relative URLs only. Anything with another
 * scheme (javascript:, data:, vbscript: ...) becomes '#'. Defence in depth at
 * render time in case the model ever emits one.
 */
export function safeHref(raw: string): string {
  const href = raw.trim()
  // A browser drops tabs, newlines and other control characters while
  // parsing a URL, so "java\tscript:" would become "javascript:" after this
  // check passed it as relative. Any control character: not a link.
  if (/[\u0000-\u001F\u007F]/.test(href)) return '#'
  if (SAFE_SCHEME.test(href)) return href
  if (ANY_SCHEME.test(href)) return '#'
  return href
}

// Characters that must never appear in a link's visible text because they hide or reorder what it says.
const UNSAFE_IN_LABEL = /[\s\u0000-\u001f\u007f-\u009f\u00ad\u061c\u180e\u200b-\u200f\u202a-\u202e\u2060-\u206f\ufeff\ufff9-\ufffb]/g

/**
 * An address as a person reads it: percent-encoded letters decoded, so
 * https://example.gr/%ce%b5%cf%80%ce%b9/ shows as https://example.gr/επι/. Only the visible text changes; the
 * link keeps the address as given. An address that does not decode cleanly is shown as it was, and an invisible
 * or direction-changing character is always shown percent-encoded, whether it arrived encoded or as itself.
 * The visitor widget (assets/js/widget-links.js) and the operator dashboard (packages/api/src/lib/linkedText.ts)
 * have the same rule; tests/fixtures/link-display.json holds all three to the same answers.
 */
export function readableUrl(url: string): string {
  let decoded: string
  try { decoded = decodeURI(url) } catch { decoded = url }
  UNSAFE_IN_LABEL.lastIndex = 0
  return UNSAFE_IN_LABEL.test(decoded) ? url.replace(UNSAFE_IN_LABEL, encodeURIComponent) : decoded
}

/** A bare address as matched in running text, without the sentence punctuation that follows it. */
export function splitAddress(raw: string): { url: string; tail: string } {
  const tail = (/[.,;:!?\u2026\u00bb\u201d\u2019]+$/.exec(raw) ?? [''])[0]
  return { url: raw.slice(0, raw.length - tail.length), tail }
}

/**
 * A bare http(s) address in text. It stops at whitespace, a quote, a backtick, a bracket, an angle bracket or a
 * Markdown mark (* and [), so it never takes the bold or the link that follows it.
 */
export const BARE_ADDRESS = "https?:\\/\\/[^\\s<>\"'`()\\[\\]*]+"

export type TextPart = { readonly text: string } | { readonly href: string; readonly label: string }

/** Plain text (an email body, a visitor's message) as text parts and links: the same bare-address rule. */
export function addressParts(input: string | null | undefined): TextPart[] {
  const text = String(input ?? '')
  const parts: TextPart[] = []
  let last = 0
  for (const m of text.matchAll(new RegExp(BARE_ADDRESS, 'g'))) {
    const { url } = splitAddress(m[0])
    if (!/^https?:\/\/[^/?#]/.test(url)) continue
    const at = m.index ?? 0
    if (at > last) parts.push({ text: text.slice(last, at) })
    parts.push({ href: url, label: readableUrl(url) })
    last = at + url.length
  }
  if (last < text.length) parts.push({ text: text.slice(last) })
  return parts
}

function renderInline(text: string): ReactNode[] {
  const out: ReactNode[] = []
  // Order: code (preserves literal content) then bold, italic, link.
  const re = new RegExp('(`[^`]+`)|(\\*\\*[^*]+\\*\\*)|(\\*[^*]+\\*)|(\\[[^\\]]+\\]\\([^)]+\\))|(' + BARE_ADDRESS + ')', 'g')
  let last = 0
  let m: RegExpExecArray | null
  let key = 0
  // EVERY segment (including plain text) is keyed. Mixing keyed elements with
  // bare unkeyed strings in one array is a React reconciliation footgun: when
  // the text reflows during streaming the positional diff desyncs and unmount
  // throws "removeChild ... not a child of this node". Plain text is wrapped in
  // a keyed Fragment, which renders as a bare text node.
  while ((m = re.exec(text)) !== null) {
    if (m.index > last) {
      out.push(h(Fragment, { key: key++ }, text.slice(last, m.index)))
    }
    const tok = m[0]
    if (tok.startsWith('`')) {
      out.push(h('code', { key: key++ }, tok.slice(1, -1)))
    } else if (tok.startsWith('**')) {
      out.push(h('strong', { key: key++ }, tok.slice(2, -2)))
    } else if (tok.startsWith('*')) {
      out.push(h('em', { key: key++ }, tok.slice(1, -1)))
    } else if (tok.startsWith('[')) {
      const close = tok.indexOf('](')
      const label = tok.slice(1, close)
      const rawHref = tok.slice(close + 2, -1)
      out.push(h('a', { key: key++, href: safeHref(rawHref), target: '_blank', rel: 'noopener noreferrer' }, label))
    } else {
      // A bare address: linked as given, shown readable, the sentence's own punctuation left outside.
      const { url, tail } = splitAddress(tok)
      if (/^https?:\/\/[^/?#]/.test(url)) {
        out.push(h('a', { key: key++, href: safeHref(url), target: '_blank', rel: 'noopener noreferrer' }, readableUrl(url)))
        if (tail) out.push(h(Fragment, { key: key++ }, tail))
      } else {
        out.push(h(Fragment, { key: key++ }, tok))
      }
    }
    last = m.index + tok.length
  }
  if (last < text.length) {
    out.push(h(Fragment, { key: key++ }, text.slice(last)))
  }
  return out
}

export interface RenderMarkdownOptions {
  /**
   * Keep single line breaks inside a paragraph as line breaks (the
   * Conversations transcript and the visitor widget do, so an address or a
   * list of contact lines keeps its lines). Off by default: the agent chat
   * joins them, as it always has.
   */
  readonly breaks?: boolean
}

export function renderMarkdown(content: string, options?: RenderMarkdownOptions): ReactNode {
  const lines = content.replace(/\r\n/g, '\n').split('\n')
  const blocks: ReactNode[] = []
  let i = 0
  let key = 0
  while (i < lines.length) {
    const line = lines[i]
    // Fenced code block
    if (/^```/.test(line)) {
      const buf: string[] = []
      i++
      while (i < lines.length && !/^```/.test(lines[i])) {
        buf.push(lines[i])
        i++
      }
      if (i < lines.length) i++ // consume closing ```
      blocks.push(h('pre', { key: key++ }, h('code', null, buf.join('\n'))))
      continue
    }
    // Heading
    const hd = /^(#{1,4})\s+(.*)$/.exec(line)
    if (hd) {
      const tag = `h${hd[1].length}` as 'h1' | 'h2' | 'h3' | 'h4'
      blocks.push(h(tag, { key: key++ }, ...renderInline(hd[2])))
      i++
      continue
    }
    // Lists. The model often writes a "loose" list, one blank line between
    // items; those blank lines must not end the list, or every item becomes
    // its own list and a numbered list reads 1. 1. 1. A blank line only ends
    // the list when the next non-blank line is not another item of its kind.
    const collectList = (isItem: RegExp, strip: RegExp): ReactNode[] => {
      const items: ReactNode[] = []
      while (i < lines.length) {
        if (isItem.test(lines[i])) {
          items.push(h('li', { key: items.length }, ...renderInline(lines[i].replace(strip, ''))))
          i++
          continue
        }
        if (lines[i].trim() === '') {
          let j = i
          while (j < lines.length && lines[j].trim() === '') j++
          if (j < lines.length && isItem.test(lines[j])) { i = j; continue }
        }
        break
      }
      return items
    }
    // Unordered list
    if (/^\s*[-*]\s+/.test(line)) {
      blocks.push(h('ul', { key: key++ }, ...collectList(/^\s*[-*]\s+/, /^\s*[-*]\s+/)))
      continue
    }
    // Ordered list (keeps the first number, so a list continued after a paragraph does not restart)
    const olStart = /^\s*(\d+)\.\s+/.exec(line)
    if (olStart) {
      const start = parseInt(olStart[1], 10)
      blocks.push(h('ol', { key: key++, start: start > 1 ? start : undefined }, ...collectList(/^\s*\d+\.\s+/, /^\s*\d+\.\s+/)))
      continue
    }
    // Markdown table: a pipe-delimited row followed by a separator row; every
    // following row that still starts with `|` is a data row.
    if (/^\s*\|.*\|\s*$/.test(line) && i + 1 < lines.length && /^\s*\|[\s|:-]+\|\s*$/.test(lines[i + 1])) {
      const splitCells = (row: string): string[] =>
        row
          .trim()
          .replace(/^\||\|$/g, '')
          .split('|')
          .map((c) => c.trim())
      const headers = splitCells(line)
      i += 2 // header + separator
      const rows: string[][] = []
      while (i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i])) {
        rows.push(splitCells(lines[i]))
        i++
      }
      blocks.push(
        h('table', { key: key++, className: 'bb-md-table' },
          h('thead', null,
            h('tr', null, ...headers.map((c, idx) => h('th', { key: idx }, ...renderInline(c))))),
          h('tbody', null,
            ...rows.map((r, ridx) =>
              h('tr', { key: ridx }, ...r.map((c, cidx) => h('td', { key: cidx }, ...renderInline(c))))))),
      )
      continue
    }
    // Blank line: paragraph break
    if (line.trim() === '') {
      i++
      continue
    }
    // Paragraph (collect consecutive non-blank, non-block lines)
    const para: string[] = [line]
    i++
    while (
      i < lines.length &&
      lines[i].trim() !== '' &&
      !/^```|^#{1,4}\s+|^\s*[-*]\s+|^\s*\d+\.\s+|^\s*\|.*\|\s*$/.test(lines[i])
    ) {
      para.push(lines[i])
      i++
    }
    blocks.push(
      options?.breaks
        // Each line is its own keyed Fragment: renderInline numbers its keys
        // from 0 for every line, so flattening the lines into one list gave
        // siblings the same keys (Codex: an update then duplicated text).
        ? h('p', { key: key++ }, ...para.map((ln, li) => h(Fragment, { key: `l${li}` }, li > 0 ? h('br') : null, ...renderInline(ln))))
        : h('p', { key: key++ }, ...renderInline(para.join(' '))),
    )
  }
  return h(Fragment, null, ...blocks)
}
