import { AdminBrowserPanel, type AdminBrowserHandle } from './AdminBrowserPanel'
/**
 * AgentSurface — the chat-first Agent tab.
 *
 * Layout:
 *   ┌──────────────────────────────────────┐
 *   │  [New chat] [History] [Integrations] │   ← action bar
 *   │           [Tasks]                    │
 *   ├──────────────────────────────────────┤
 *   │                                      │
 *   │           transcript                 │
 *   │                                      │
 *   ├──────────────────────────────────────┤
 *   │   [Type a message…       ] [Send]    │
 *   └──────────────────────────────────────┘
 *
 *   + sliding drawers for History / Integrations / Tasks (mounted at
 *     the root so they overlay above the chat without remounting it).
 *
 * The admin never leaves the chat. Everything else slides in from the
 * right, gets used, slides out. Per user direction: "tasks/integrations/
 * history are buttons that slide something so the user doesn't have to
 * change context."
 *
 * Skills do NOT appear in the UI. They're our internal harness format
 * for wiring integrations; from the admin's POV there's only
 * "Integrations". The Skills.tsx component + useSkills hook stay
 * wired to the backend for when we decide *how* to surface them
 * (likely embedded inside specific Integration cards).
 */
import { Fragment, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import type { JSX } from 'react'
import { useQueryClient } from '@tanstack/react-query'

// Recharts statically bundled. We tried lazy-loading via React.lazy but
// the cross-chunk React instance caused React error #321 (invalid hook
// call) on the admin-react build — multiple attempts at manualChunks /
// modulePreload didn't deduplicate it reliably. Static import keeps
// React + Recharts in the same module graph; bundle size bump is
// acceptable for a paid Pro+ surface.
import AnalyticsChart from './AnalyticsChart'
import {
  useAdminConversations,
  useAdminConversation,
  useSendAdminChat,
  useDeleteAdminConversation,
  useCancelAdminChat,
  useAdminChatStream,
  conversationTailSettled,
  type AdminChatMessageInfo,
  type ChatAttachment,
  type AdminChatStreamState,
  type AdminChatStreamTool,
  type ChartArtifact,
} from '@/hooks/useAdminChat'
import { AgentDrawer } from './AgentDrawer'
import { HistoryDrawerBody } from './HistoryDrawerBody'
import { IntegrationsDrawerBody } from './IntegrationsDrawerBody'
import { SchedulerDrawerBody } from './SchedulerDrawerBody'
import { TasksDrawerBody } from './TasksDrawerBody'
import { ArtefactViewerBody, ArtefactsListBody } from './ArtefactDrawers'
import { MemoryDrawerBody } from './MemoryDrawerBody'
import { GuardrailsDrawerBody } from './GuardrailsDrawerBody'
import { useGuardrails } from '@/hooks/useGuardrails'
import { PLAN_STATUS_KEY, usePlanStatus } from '@/hooks/usePlanStatus'
import { UsageDrawerBody } from './UsageDrawerBody'
import { AgentTopupGate } from './AgentTopupGate'
import { badgeTooltip, usageCredits } from './usageLines'
import { rpc } from '@/api/rpc'
import { useScheduledTasks } from '@/hooks/useScheduledTasks'
import { useTasks } from '@/hooks/useTasks'
import { useArtefacts } from '@/hooks/useArtefacts'
import { useAgentNotes } from '@/hooks/useAgentNotes'
import { useAgentHome } from '@/hooks/useAgentHome'
import { getConfig } from '@/lib/config'
import { wpAjaxUpload } from '@/api/rpc'
import {
  runPreviewCommand,
  type PreviewCommand,
  type PreviewResult,
} from './previewController'
import { createSightSession, sightSupported, type SightSession, type SightCapture } from './previewSight'
import { afterPaint } from './afterPaint'
import { renderMarkdown } from '@/lib/renderMarkdown'
import { computeDockBox, DOCK_GAP, isBlankFrameSrc, type DockBox, type Size } from './previewDock'
import { browserStorage, readWriteMode, saveWriteMode, writeModeKey, type WriteMode } from './adminWriteMode'
import { toast } from '@/components/ui/Toast'
import { formatToolChip } from '@/lib/utils'
import { TASKS_DRAWER_LABEL } from '@/lib/drawerLabels.generated'
import { confirm } from '@/components/ui/ConfirmDialog'

function relativeTime(iso: string): string {
  const then = new Date(iso).getTime()
  const diff = Date.now() - then
  if (diff < 60_000) return 'just now'
  if (diff < 3_600_000) return `${Math.floor(diff / 60_000)}m ago`
  if (diff < 86_400_000) return `${Math.floor(diff / 3_600_000)}h ago`
  return `${Math.floor(diff / 86_400_000)}d ago`
}

/**
 * Live in-flight agent turn. Shows above the assistant bubble while
 * the SSE stream is open:
 *
 *   ┌───────────────────────────────────┐
 *   │ ⚙ Working…                         │   ← header while status===streaming
 *   │   ⚙ Searched your site (in progress)│   ← per-tool chip with status
 *   │   ✓ Listed your drafts              │
 *   │   ✗ Failed to draft FAQ — <error>   │
 *   ├───────────────────────────────────┤
 *   │ Streaming reply text appears here   │   ← grows token-by-token
 *   │ as it comes in from the model.      │
 *   └───────────────────────────────────┘
 *
 * Once `done` lands the parent component drops `streamParams` after
 * a short delay, the conversation poll catches up with the persisted
 * versions of these rows, and this component unmounts.
 */
function StreamingTurn({ stream, onOpenArtefact }: { stream: AdminChatStreamState; onOpenArtefact: (id: string) => void }) {
  // EXECUTION-ORDER rendering: text fragments and tool RUNS interleave exactly
  // as they happened. Consecutive tool calls under one thought merge into one
  // run (a single call = bare chip; several = collapsible group), positioned
  // inline where they ran — never hoisted above the reply (owner direction).
  const toolByStart = new Map(stream.tools.map((t) => [t.startedAt, t]))
  type RenderItem =
    | { type: 'text'; text: string; segIdx: number }
    | { type: 'run'; tools: AdminChatStreamTool[]; key: number }
  const items: RenderItem[] = []
  for (let i = 0; i < stream.segments.length; i++) {
    const seg = stream.segments[i]
    if (seg.type === 'text') {
      items.push({ type: 'text', text: seg.text, segIdx: i })
      continue
    }
    const t = toolByStart.get(seg.startedAt)
    if (!t) continue
    const lastItem = items[items.length - 1]
    if (lastItem && lastItem.type === 'run') lastItem.tools.push(t)
    else items.push({ type: 'run', tools: [t], key: seg.startedAt })
  }
  const lastTextItem = [...items].reverse().find((it) => it.type === 'text')
  return (
    <div className="bb-adminchat-streaming">
      {items.map((item) => {
        if (item.type === 'run') {
          return (
            <Fragment key={`run-${item.key}`}>
              <ToolRun
                steps={item.tools.flatMap((t) => {
                  const label = formatToolChip(t.toolName)
                  return label ? [{ label, status: t.status, error: t.error }] : []
                })}
              />
              {item.tools.map((t) => (
                <Fragment key={`art-${t.startedAt}`}>
                  {t.artifact && t.artifact.kind === 'chart' && (
                    <AnalyticsChart artifact={t.artifact as ChartArtifact} />
                  )}
                  {isArtefactArtifact(t.artifact) && (
                    <ArtefactCard artefact={t.artifact as unknown as ArtefactArtifact} onOpen={onOpenArtefact} />
                  )}
                </Fragment>
              ))}
            </Fragment>
          )
        }
        // Closed fragments (a tool run ended them) are stable — parse their
        // markdown once. The ACTIVE (last) fragment stays RAW TEXT while the
        // stream lives: re-parsing per token thrashed block reconciliation and
        // crashed unmount (removeChild). The settled transcript re-renders
        // everything as markdown from persistence.
        const isLastText = item === lastTextItem
        const active = isLastText && stream.status === 'streaming'
        return (
          <div key={`seg-text-${item.segIdx}`} className={`bb-adminchat-bubble bb-adminchat-bubble-assistant bb-adminchat-bubble-streaming${active ? ' is-active' : ''}`}>
            <div className="bb-adminchat-bubble-body">
              {active
                // Markdown while streaming too, so the reply does not flash raw
                // text and then reflow. The subtree is remounted on every
                // update (key = text length) instead of diffed: re-parsing a
                // growing text diffs badly and once crashed unmount.
                ? <div key={item.text.length} className="bb-md bb-md-streaming">{renderMarkdown(item.text)}</div>
                : <div className="bb-md">{renderMarkdown(item.text)}</div>}
            </div>
            {isLastText && stream.usage && (
              <UsageFooter
                inputTokens={stream.usage.inputTokens}
                outputTokens={stream.usage.outputTokens}
              />
            )}
          </div>
        )
      })}
      {stream.status === 'streaming' && stream.segments.length === 0 && (
        <div className="bb-adminchat-bubble bb-adminchat-bubble-assistant bb-adminchat-bubble-streaming">
          <div className="bb-adminchat-bubble-body">
            <span className="bb-adminchat-typing"><i/><i/><i/></span>
          </div>
        </div>
      )}
      {stream.status === 'error' && stream.error && (
        <div className="bb-adminchat-stream-error">
          ⚠ {stream.error}
        </div>
      )}
    </div>
  )
}

/**
 * One tool call, rendered inline where it happened in the story. Styled
 * IDENTICALLY to a step inside a collapsed run (same glyph, same muted label,
 * left-aligned) so single calls and grouped runs read as one visual language.
 */
function InlineToolChip({ label, status, error }: { label: string; status?: 'in_progress' | 'ok' | 'error'; error?: string }) {
  return (
    <div className={`bb-inline-tool bb-adminchat-toolgroup-step step-${status ?? 'ok'}`} title={error || undefined}>
      <span className="bb-adminchat-toolgroup-glyph" aria-hidden>
        {status === 'in_progress' ? '◐' : status === 'error' ? '✕' : '✓'}
      </span>
      <span className="bb-adminchat-toolgroup-step-label">{label}{status === 'in_progress' ? '…' : ''}</span>
    </div>
  )
}

type ToolStep = { label: string; status: 'in_progress' | 'ok' | 'error'; error?: string }

/**
 * A RUN of tool calls belonging to one thought. A single call renders as a
 * bare inline chip; parallel/consecutive calls collapse into an expandable
 * "Used N tools" group sitting INLINE where the run happened — never hoisted
 * above the reply (owner direction). Auto-expanded while any step runs.
 */
function ToolRun({ steps }: { steps: ToolStep[] }) {
  const [open, setOpen] = useState(false)
  if (steps.length === 0) return null
  if (steps.length === 1) {
    const s = steps[0]
    return <InlineToolChip label={s.label} status={s.status} error={s.error} />
  }
  const inProgress = steps.some((s) => s.status === 'in_progress')
  const expanded = open || inProgress
  const failed = steps.filter((s) => s.status === 'error').length
  const activeStep = steps.find((s) => s.status === 'in_progress')
  const summary = inProgress
    ? (activeStep?.label ?? 'Working')
    : `Used ${steps.length} tools${failed ? ` · ${failed} failed` : ''}`
  return (
    <div className="bb-adminchat-toolgroup">
      <button
        type="button"
        className="bb-adminchat-toolgroup-header"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={expanded}
      >
        <span className={`bb-adminchat-toolgroup-chevron ${expanded ? 'open' : ''}`} aria-hidden>›</span>
        <span className="bb-adminchat-toolgroup-summary">
          {inProgress && <span className="bb-adminchat-toolgroup-spinner" aria-hidden />}
          {summary}{inProgress ? '…' : ''}
        </span>
      </button>
      {expanded && (
        <div className="bb-adminchat-toolgroup-steps">
          {steps.map((s, i) => (
            <div className={`bb-adminchat-toolgroup-step step-${s.status}`} key={i} title={s.error || undefined}>
              <span className="bb-adminchat-toolgroup-glyph" aria-hidden>
                {s.status === 'in_progress' ? '◐' : s.status === 'error' ? '✕' : '✓'}
              </span>
              <span className="bb-adminchat-toolgroup-step-label">{s.label}{s.status === 'in_progress' ? '…' : ''}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

/**
 * Render a persisted message list in EXECUTION ORDER: tool rows appear
 * exactly where they ran between the assistant's thoughts. A lone call is a
 * bare chip; consecutive calls collapse into an inline expandable run —
 * never hoisted above the reply (owner direction). Artifacts (charts,
 * artefact cards) render right after their run.
 */
function renderMessages(messages: AdminChatMessageInfo[], onOpenArtefact: (id: string) => void): JSX.Element[] {
  const out: JSX.Element[] = []
  let run: AdminChatMessageInfo[] = []
  const flushRun = () => {
    if (run.length === 0) return
    const steps: ToolStep[] = run.flatMap((m) => {
      const label = formatToolChip(m.toolName)
      if (!label) return []
      const failed = typeof m.content === 'string' && m.content.startsWith('tool-error:')
      return [{ label, status: failed ? ('error' as const) : ('ok' as const) }]
    })
    if (steps.length > 0) out.push(<ToolRun key={`run-${run[0].id}`} steps={steps} />)
    for (const m of run) {
      if (isChartArtifact(m.artifacts)) {
        out.push(<AnalyticsChart key={`chart-${m.id}`} artifact={m.artifacts} />)
      } else if (isArtefactArtifact(m.artifacts)) {
        out.push(<ArtefactCard key={`art-${m.id}`} artefact={m.artifacts} onOpen={onOpenArtefact} />)
      }
    }
    run = []
  }
  for (let i = 0; i < messages.length; i++) {
    const msg = messages[i]
    if (msg.role === 'tool') { run.push(msg); continue }
    flushRun()
    out.push(<MessageBubble key={msg.id} msg={msg} />)
  }
  flushRun()
  return out
}

type Viewport = 'desktop' | 'tablet' | 'mobile'
const VIEWPORT_DIMS: Record<Viewport, { w: number; h: number }> = {
  desktop: { w: 1280, h: 820 },
  tablet: { w: 768, h: 1024 },
  mobile: { w: 390, h: 844 },
}

/** What the dock measures in the page; computeDockBox does the rest. */
interface DockMeasure { chat: Size; composerSpace: number; chrome: number }
const UNMEASURED_DOCK: DockMeasure = { chat: { width: 0, height: 0 }, composerSpace: 0, chrome: 0 }
const sameMeasure = (a: DockMeasure, b: DockMeasure): boolean =>
  a.chat.width === b.chat.width && a.chat.height === b.chat.height && a.composerSpace === b.composerSpace && a.chrome === b.chrome
/** The rows at the bottom of the chat column the dock must leave uncovered. */
const COMPOSER_ROW_SELECTOR = '.bb-composer-attachments, .bb-agent-composer, .bb-agent-gate'

/** How long the tab waits before asking again for a brief step the server called busy. */
const BRIEF_RETRY_MS = 4_000

/**
 * PreviewPanel — the live site beside the chat, and the agent's eyes.
 *
 * ONE iframe, always mounted (hidden, not unmounted, when closed) so the agent
 * can drive it whenever it looks. Rendered at the TRUE viewport width and scaled
 * to fit, so `window.innerWidth` inside the frame really is 390 on mobile and
 * every media query, container query and vw unit resolves as it would on a phone
 * — a panel-width frame would report phone geometry that no phone has.
 *
 * The owner is looking at the ACTUAL page here, not a screenshot of it. That is
 * the point of the whole design: the agent reads measurements, the human reads
 * the rendering, and neither is a re-render of the other.
 *
 * Navigation is imperative (the agent sets iframe.src through previewController),
 * so React never fights the agent for the src.
 *
 * `sandbox="allow-same-origin"` — EXACTLY ONE TOKEN, and the shape of it is the
 * security of the whole panel.
 *
 * `allow-same-origin` is kept because reading `contentDocument` IS the mechanism:
 * without it the frame gets an opaque origin, the DOM is unreadable, and the
 * agent has no eyes at all.
 *
 * `allow-scripts` is WITHHELD, and it must stay withheld. This page carries the
 * owner's real administrator session on the admin's own origin, so with
 * `allow-scripts` it is genuinely same-origin with this SPA — and any script that
 * executes inside it can reach `window.parent.document` and read or rewrite
 * wp-admin. No click, no navigation, no user interaction. A compromised plugin or
 * theme, a stored-XSS post body, or web content the agent itself published would
 * all have had that. previewController's path allowlist, origin check,
 * capture-phase click/submit block and admin-bar removal stop navigation and
 * submission; none of them stops a page's own script from reaching out.
 *
 * The eyes do not need it. Every measurement runs in THIS document, reaching into
 * the frame — nothing is injected to run inside it — and the full measurement
 * surface was checked identical with and without the token across Chromium,
 * Firefox and WebKit. What is genuinely lost is the page's own JavaScript, so
 * this is a JS-off rendition; `previewController` reports that as a FACT on every
 * result (`scripting`) rather than letting it pass for the page a visitor sees.
 * Also withheld, as before: allow-forms, allow-top-navigation, allow-downloads,
 * allow-popups, allow-modals.
 */
function PreviewPanel({
  adminFrameRef,
  activeScreen,
  iframeRef,
  scalerRef,
  viewport,
  width,
  onResize,
  sight,
  onToggleSight,
  approvalPending,
  onReserve,
  covered,
}: {
  adminFrameRef: React.RefObject<HTMLIFrameElement | null>
  activeScreen: 'site' | 'admin'
  iframeRef: React.RefObject<HTMLIFrameElement | null>
  /** The element Region Capture crops to: exactly the rendered page, nothing else. */
  scalerRef: React.RefObject<HTMLDivElement | null>
  viewport: Viewport
  width: number
  onResize: (w: number) => void
  sight: { supported: boolean; on: boolean; busy: boolean; error: string | null }
  onToggleSight: () => void
  /** An Allow/Deny card is waiting in the transcript. */
  approvalPending: boolean
  /** Transcript padding that keeps every message clear of the dock. */
  onReserve: (reserve: DockBox['reserve']) => void
  /** A drawer or side panel is open over the chat. */
  covered: boolean
}) {
  const panelRef = useRef<HTMLDivElement>(null)
  const headRef = useRef<HTMLDivElement>(null)
  const sightRef = useRef<HTMLDivElement>(null)
  const [measured, setMeasured] = useState<DockMeasure>(UNMEASURED_DOCK)
  const [expanded, setExpanded] = useState(false)
  const expandButtonRef = useRef<HTMLButtonElement>(null)
  const thumbnailButtonRef = useRef<HTMLButtonElement>(null)
  const focusAfterToggle = useRef(false)
  const toggleExpanded = (value: boolean) => { focusAfterToggle.current = true; setExpanded(value) }
  useEffect(() => {
    if (!focusAfterToggle.current) return
    focusAfterToggle.current = false
    ;(expanded ? expandButtonRef : thumbnailButtonRef).current?.focus()
  }, [expanded])
  const dims = VIEWPORT_DIMS[viewport]

  // A frame with no page paints an empty white box. Watch the shown frame's
  // address (navigation here is imperative, so the attribute is the truth) and
  // say in words that nothing is open yet.
  const [blank, setBlank] = useState(true)
  useEffect(() => {
    const frame = (activeScreen === 'admin' ? adminFrameRef : iframeRef).current
    if (!frame) return
    const check = () => setBlank(isBlankFrameSrc(frame.getAttribute('src')))
    check()
    const watch = new MutationObserver(check)
    watch.observe(frame, { attributes: true, attributeFilter: ['src'] })
    frame.addEventListener('load', check)
    return () => { watch.disconnect(); frame.removeEventListener('load', check) }
  }, [activeScreen, adminFrameRef, iframeRef])

  // THE CHAT COLUMN IS THE DOCK'S WHOLE WORLD. Measure that column, the composer
  // row at its bottom (attachments, composer or the credit gate) and the dock's
  // own head rows; computeDockBox turns those into a box that cannot leave the
  // column or cover the composer, whatever the window or the WP admin menu does.
  useLayoutEffect(() => {
    const panel = panelRef.current
    const main = panel?.parentElement
    if (!panel || !main) return
    const composerRow = () => Array.from(main.children).filter(el => el.matches(COMPOSER_ROW_SELECTOR))
    const measure = () => {
      const mainRect = main.getBoundingClientRect()
      const rowTops = composerRow().map(el => el.getBoundingClientRect().top)
      const composerSpace = rowTops.length ? Math.max(0, Math.ceil(mainRect.bottom - Math.min(...rowTops))) + DOCK_GAP : DOCK_GAP
      const next: DockMeasure = {
        chat: { width: main.clientWidth, height: main.clientHeight },
        composerSpace,
        chrome: (headRef.current?.offsetHeight ?? 0) + (sightRef.current?.offsetHeight ?? 0),
      }
      setMeasured(prev => sameMeasure(prev, next) ? prev : next)
    }
    const sizes = new ResizeObserver(measure)
    const observeAll = () => {
      sizes.disconnect()
      for (const el of [main, headRef.current, sightRef.current, ...composerRow()]) if (el) sizes.observe(el)
      measure()
    }
    observeAll()
    // The credit gate and the attachment row mount and unmount beside the
    // composer; only the column's own children matter, so no subtree watch.
    const children = new MutationObserver(observeAll)
    children.observe(main, { childList: true })
    return () => { children.disconnect(); sizes.disconnect() }
  }, [])

  const box = computeDockBox({ mode: expanded ? 'expanded' : 'compact', ...measured, frame: { width: dims.w, height: dims.h }, compactWidth: width })
  const scale = box.scale

  const reserveRight = measured.chat.width > 0 ? box.reserve.right : 0
  const reserveBottom = measured.chat.width > 0 ? box.reserve.bottom : 0
  useEffect(() => { onReserve({ right: reserveRight, bottom: reserveBottom }) }, [onReserve, reserveRight, reserveBottom])

  // An Allow/Deny card must never ARRIVE under the dock. When the expanded dock
  // covers the whole transcript, a new approval minimizes it once (focus stays
  // put, so the owner's next key press is not taken from the card). Only the
  // arrival does this: the owner may still expand again while the card waits.
  const coversTranscript = useRef(false)
  coversTranscript.current = expanded && box.reserve.right === 0
  const wasPending = useRef(false)
  useEffect(() => {
    const arrived = approvalPending && !wasPending.current
    wasPending.current = approvalPending
    if (arrived && coversTranscript.current) setExpanded(false)
  }, [approvalPending])

  // Drag the left edge to resize the compact dock. Pointer capture keeps the
  // drag smooth even when the cursor crosses the iframe; the width is measured
  // from the dock's own right edge, not the window's.
  const onHandleDown = (e: React.PointerEvent) => {
    e.preventDefault()
    ;(e.target as HTMLElement).setPointerCapture(e.pointerId)
    const right = panelRef.current?.getBoundingClientRect().right ?? window.innerWidth
    const move = (ev: PointerEvent) => onResize(right - ev.clientX)
    const up = () => {
      window.removeEventListener('pointermove', move)
      window.removeEventListener('pointerup', up)
    }
    window.addEventListener('pointermove', move)
    window.addEventListener('pointerup', up)
  }

  return (
    <div
      ref={panelRef}
      className={`bb-preview-panel bb-preview-open ${expanded ? 'bb-preview-expanded' : 'bb-preview-compact'}${covered ? ' bb-preview-covered' : ''}`}
      aria-hidden={covered || undefined}
      style={{
        left: box.left, top: box.top, width: box.width, height: box.height,
        visibility: measured.chat.width > 0 ? undefined : 'hidden',
      }}
    >
      <div
        className="bb-preview-resize"
        onPointerDown={onHandleDown}
        hidden={expanded}
        role="separator"
        aria-orientation="vertical"
        aria-label="Resize preview"
        title="Drag to resize"
      />
      <div className="bb-preview-head" ref={headRef}>
        <span className="bb-preview-title">{activeScreen === 'admin' ? 'WordPress admin' : 'Live site preview'}{` \u00b7 ${viewport}`}</span>
        <div className="bb-preview-head-actions">
          {expanded && <button type="button" className="bb-preview-pin" ref={expandButtonRef}
            aria-label="Minimize preview" onClick={() => toggleExpanded(false)}>Minimize</button>}
          {sight.supported && (
            <button
              type="button"
              className={`bb-preview-pin ${sight.on ? 'bb-preview-pin-on' : 'bb-preview-pin-needed'}`}
              onClick={onToggleSight}
              disabled={sight.busy}
              title={
                sight.on
                  ? 'Stop sharing. The agent goes back to measuring pages without seeing them.'
                  : 'Share this tab once so the agent can see each page it opens here. Your browser shows a sharing indicator the whole time, and you can stop it at any point.'
              }
            >
              {sight.on ? 'Seeing' : 'Let me see'}
            </button>
          )}

        </div>
      </div>
      {/* OFF IS THE LOUD STATE, and it is loud on purpose.
          This was one quiet beige line saying the agent "measures instead of
          seeing", which reads as a technical footnote. It is a capability
          switched off, so it is red, with a status light, and the button that
          fixes it carries the same red. ON is the calm state because nothing is
          being withheld there.

          The line states the CAUSE and the REMEDY, not the consequences. It
          listed the three things the agent would not do — judge, change or
          suggest — which is accurate, longer, and leaves the owner to work out
          that one click undoes all of it. What they need is why and what to
          press. The agent still explains the consequences itself when asked. */}
      <div
        ref={sightRef}
        className={`bb-preview-sight ${sight.on ? 'bb-preview-sight-on' : 'bb-preview-sight-off'}`}
        role="status"
      >
        <span className="bb-preview-sight-dot" aria-hidden="true" />
        <strong className="bb-preview-sight-lead">
          {sight.on ? 'Visual check ON' : 'Visual check OFF'}
        </strong>
        <span className="bb-preview-sight-what">
          {sight.on
            ? 'The agent sees what you see here.'
            : sight.supported
              ? 'The agent cannot see your site until you turn on Let me see.'
              : 'This browser cannot share a tab, so the agent cannot see your site.'}
        </span>
        {sight.error ? <span className="bb-preview-sight-why">{sight.error}</span> : null}
      </div>
      <div className="bb-preview-stage" style={{ width: box.stage.width, height: box.stage.height }}>
        {blank && (
          <p className="bb-preview-empty" role="status">
            {activeScreen === 'admin' ? 'No admin page open yet. The agent opens one here when it works in WordPress.' : 'No page open yet. The agent opens your site here when it looks.'}
          </p>
        )}
        {!expanded && <button type="button" className="bb-preview-expand-overlay" ref={thumbnailButtonRef} aria-label="Open full preview" onClick={() => toggleExpanded(true)}><span>Click to expand</span></button>}
        <div className="bb-preview-scaler" ref={scalerRef} style={{ width: box.drawn.width, height: box.drawn.height }}>
          <iframe
            ref={iframeRef}
            className="bb-preview-frame"
            title="Live site preview"
            // NEVER add allow-scripts: this frame is same-origin with wp-admin and
            // carries the owner's admin session, so page script could rewrite the
            // admin app. See the block comment above.
            sandbox="allow-same-origin"
            style={{
              display: activeScreen === 'site' ? 'block' : 'none',
              width: dims.w,
              height: dims.h,
              transform: `scale(${scale})`,
              transformOrigin: 'top left',
            }}
          />
          <iframe
            ref={adminFrameRef}
            className="bb-preview-frame"
            title="WordPress admin controls"
            sandbox="allow-same-origin allow-scripts allow-forms allow-modals"
            style={{ display: activeScreen === 'admin' ? 'block' : 'none', width: dims.w, height: dims.h, transform: `scale(${scale})`, transformOrigin: 'top left' }}
          />
        </div>
      </div>
    </div>
  )
}

function isChartArtifact(a: unknown): a is ChartArtifact {
  if (!a || typeof a !== 'object') return false
  const o = a as Record<string, unknown>
  return (
    o.kind === 'chart' &&
    typeof o.chartKind === 'string' &&
    typeof o.xKey === 'string' &&
    Array.isArray(o.yKeys) &&
    Array.isArray(o.data)
  )
}

interface ArtefactArtifact { kind: 'artefact'; id: string; title: string; docKind?: string }
function isArtefactArtifact(a: unknown): a is ArtefactArtifact {
  if (!a || typeof a !== 'object') return false
  const o = a as Record<string, unknown>
  return o.kind === 'artefact' && typeof o.id === 'string' && typeof o.title === 'string'
}

/** In-chat handle for a generated document — opens the wide viewer drawer. */
function ArtefactCard({ artefact, onOpen }: { artefact: ArtefactArtifact; onOpen: (id: string) => void }) {
  return (
    <button type="button" className="bb-art-card" onClick={() => onOpen(artefact.id)}>
      <span className="bb-art-card-icon" aria-hidden>▤</span>
      <span className="bb-art-card-body">
        <span className="bb-art-card-title">{artefact.title}</span>
        <span className="bb-art-card-sub">Document · open &amp; download PDF</span>
      </span>
      <span className="bb-art-card-open" aria-hidden>↗</span>
    </button>
  )
}

/**
 * Per-response usage footer: total tokens + our cost, shown under an
 * assistant reply. Groundwork for rate-limiting later; for now it's
 * transparency on what each turn consumed. Renders nothing when there's
 * no usage (e.g. old messages that predate capture).
 */
function UsageFooter({
  inputTokens,
  outputTokens,
}: {
  inputTokens: number | null
  outputTokens: number | null
}) {
  const inTok = inputTokens ?? 0
  const outTok = outputTokens ?? 0
  const total = inTok + outTok
  if (total <= 0) return null
  const tokenLabel = total >= 1000 ? `${(total / 1000).toFixed(1)}k` : String(total)
  // Cost is captured server-side (admin_messages.cost_usd) for later
  // rate-limiting, but deliberately NOT shown to the owner here.
  return (
    <div
      className="bb-adminchat-usage"
      title={`${inTok.toLocaleString()} in · ${outTok.toLocaleString()} out`}
    >
      <span className="bb-adminchat-usage-tokens">{tokenLabel} tokens</span>
    </div>
  )
}

function MessageBubble({ msg }: { msg: AdminChatMessageInfo }) {
  if (msg.role === 'tool') {
    // Pure-infrastructure tools (loadSkill) and any tool the admin
    // doesn't care about return null from formatToolChip — hide the
    // chip entirely instead of showing the internal name.
    const label = formatToolChip(msg.toolName)
    if (!label) return null
    // If the tool persisted a renderable artifact (e.g. showChart
    // wrote its chart spec to admin_messages.artifacts), render it
    // here so reloading a conversation replays the chart, not just
    // the chip.
    const artifact = isChartArtifact(msg.artifacts) ? msg.artifacts : null
    return (
      <>
        <div className="bb-adminchat-tool">
          <span className="bb-adminchat-tool-icon" aria-hidden>⚙</span>
          <span>{label}</span>
        </div>
        {artifact ? <AnalyticsChart artifact={artifact} /> : null}
      </>
    )
  }
  // User turns render plain text (plus any attached-image thumbnails).
  // Assistant turns go through the inline markdown renderer above so the
  // model's bold / lists / code / links surface properly.
  const chatImages = (msg.artifacts as { type?: string; images?: Array<{ attachmentId?: number; url?: string }>; docs?: Array<{ id: string; title: string }> } | null)
  const images = chatImages?.type === 'chat_images' && Array.isArray(chatImages.images) ? chatImages.images : []
  const docs = Array.isArray(chatImages?.docs) ? chatImages.docs : []
  const visibleContent = docs.length > 0 ? msg.content.replace(/\n*\[Attached documents?:[\s\S]*?read with readArtefact\]/, '').trim() : msg.content
  return (
    <div className={`bb-adminchat-bubble bb-adminchat-bubble-${msg.role}`}>
      {images.length > 0 && (
        <div className="bb-bubble-images">
          {images.map((img, i) => img.url ? <img key={img.attachmentId ?? i} src={img.url} alt="" loading="lazy" /> : null)}
        </div>
      )}
      {docs.length > 0 && (
        <div className="bb-bubble-docs">
          {docs.map((d) => (
            <span key={d.id} className="bb-composer-doc"><span aria-hidden>📄</span><span className="bb-composer-doc-title">{d.title}</span></span>
          ))}
        </div>
      )}
      <div className="bb-adminchat-bubble-body">
        {msg.role === 'assistant'
          ? <div className="bb-md">{renderMarkdown(msg.content)}</div>
          : visibleContent}
      </div>
      <div className="bb-adminchat-bubble-time">{relativeTime(msg.sentAt)}</div>
      {msg.role === 'assistant' && (
        <UsageFooter
          inputTokens={msg.inputTokens}
          outputTokens={msg.outputTokens}
        />
      )}
    </div>
  )
}

// No proposals surface at all: the agent acts autonomously and the transcript
// shows plain tool chips in execution order.
type DrawerKey = 'history' | 'integrations' | 'scheduler' | 'tasks' | 'artefacts' | 'memory' | 'guardrails' | 'usage' | null

// Artefact viewer panel state: closed, mid-generation (spinner), or viewing one.
type ArtPanel = { mode: 'closed' } | { mode: 'generating' } | { mode: 'view'; id: string; from?: 'list' | 'chat' }

/**
 * Dynamic agent-home empty state. A header layout: the LLM greeting (time-aware,
 * in the owner's language) top-left, the localized clock + date top-right, then
 * a playful niche hook and tappable action chips beneath. The greeting/hook/chips
 * are written server-side; the clock/date localize client-side off the same
 * locale. Everything stays quiet (clock only) until the brief lands.
 */
function AgentHomeEmpty({ onPick, sending, gated }: { onPick: (text: string) => void; sending: boolean; gated: boolean }) {
  const { data, isPlaceholderData } = useAgentHome()
  const fresh = !!data && !isPlaceholderData

  // Perceived-latency design: the LAST visit's greeting paints instantly
  // (placeholderData), a caret blinks where the brief goes while the fresh
  // one generates, then the fresh brief STREAMS in. The stream only plays on
  // a wait→fresh transition — a remount inside the stale window renders
  // fully, instantly.
  const [typedLen, setTypedLen] = useState<number | null>(null)
  const waitedRef = useRef(false)
  useEffect(() => {
    if (!fresh) {
      waitedRef.current = true
      return
    }
    const brief = data?.brief ?? ''
    if (!waitedRef.current) {
      setTypedLen(brief.length)
      return
    }
    waitedRef.current = false
    setTypedLen(0)
    let i = 0
    const t = window.setInterval(() => {
      i += 3
      setTypedLen(Math.min(i, brief.length))
      if (i >= brief.length) window.clearInterval(t)
    }, 16)
    return () => window.clearInterval(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fresh, data?.brief])
  const doneTyping = fresh && typedLen !== null && typedLen >= (data?.brief ?? '').length

  // An audit ages. Saying so is the difference between "here is a problem" and
  // "here is a problem, as of six weeks ago" — the second is honest and is what
  // prompts a rescan. Silent under a week: fresh findings need no apology.
  const scanAgeLabel = (iso: string): string => {
    const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000)
    if (!Number.isFinite(days) || days < 7) return 'from your site scan'
    if (days < 14) return 'from your scan last week'
    if (days < 60) return `from your scan ${Math.floor(days / 7)} weeks ago`
    return `from your scan ${Math.floor(days / 30)} months ago`
  }

  // WP gives locales like "en_US"; Intl wants BCP-47 ("en-US"). Fall back to
  // the browser locale, then undefined (Intl's runtime default).
  const rawLocale = getConfig().locale || (typeof navigator !== 'undefined' ? navigator.language : '')
  const intlLocale = rawLocale ? rawLocale.replace('_', '-') : undefined
  const now = new Date()
  const timeStr = now.toLocaleTimeString(intlLocale, { hour: 'numeric', minute: '2-digit' })
  const dateStr = now.toLocaleDateString(intlLocale, { weekday: 'short', month: 'short', day: 'numeric' })

  return (
    <div className="bb-agent-home">
      <div className="bb-agent-home-head">
        <h4 className="bb-agent-home-greeting">{data?.greeting || <span className="bb-home-caret" aria-hidden />}</h4>
        <div className="bb-agent-home-clock">
          <span className="bb-agent-home-time">{timeStr}</span>
          <span className="bb-agent-home-date">{dateStr}</span>
        </div>
      </div>
      {fresh ? (
        <>
          <p className="bb-agent-home-hook">
            {(data?.brief ?? '').slice(0, typedLen ?? 0)}
            {!doneTyping && <span className="bb-home-caret" aria-hidden />}
          </p>
          {/* ONE FINDING PER VISIT. The audit wrote its findings as rows; the
              server hands over the next unshown one and stamps it, so this
              costs a single indexed read — no model call, no agent credit.
              Severity leads because "critical" is the word that earns the
              click. `scanAt` is shown when the audit is getting old rather
              than presenting stale findings as current. */}
          {doneTyping && data?.finding && (
            <div className={`bb-agent-finding bb-agent-finding--${data.finding.severity}`}>
              <div className="bb-agent-finding-head">
                <span className="bb-agent-finding-sev">{data.finding.severity}</span>
                <span className="bb-agent-finding-age">{scanAgeLabel(data.finding.scanAt)}</span>
              </div>
              <p className="bb-agent-finding-title">{data.finding.title}</p>
              {data.finding.detail && <p className="bb-agent-finding-detail">{data.finding.detail}</p>}
              <div className="bb-agent-finding-foot">
                <button
                  type="button"
                  className="bb-agent-finding-cta"
                  disabled={sending || gated}
                  title={gated ? "This month's credits are used up. Top up below to continue." : undefined}
                  onClick={() => onPick(`Fix this: ${data.finding!.title}${data.finding!.evidence ? ` (${data.finding!.evidence})` : ''}`)}
                >
                  Fix this
                </button>
                {data.finding.remaining > 0 && (
                  <span className="bb-agent-finding-more">{data.finding.remaining} more found</span>
                )}
              </div>
            </div>
          )}
          {doneTyping && (
          <div className="bb-agent-home-chips">
            {(data?.suggestions ?? []).map((s) => (
              <button
                key={s.label}
                type="button"
                className="bb-agent-starter"
                disabled={sending || gated}
                title={gated ? "This month's credits are used up. Top up below to continue." : undefined}
                onClick={() => onPick(s.prompt)}
              >
                <span className="bb-agent-starter-arrow" aria-hidden>→</span>
                <span className="bb-agent-starter-label">{s.label}</span>
              </button>
            ))}
          </div>
          )}
        </>
      ) : (
        <p className="bb-agent-home-hook"><span className="bb-home-caret" aria-hidden /></p>
      )}
    </div>
  )
}

export function AgentSurface() {
  const [activeId, setActiveId] = useState<string | null>(null)
  const [draft, setDraft] = useState('')
  // Images attached in the composer — uploaded to the WP Media Library the
  // moment they're picked (the message later carries only the references).
  const [pendingImages, setPendingImages] = useState<(ChatAttachment & { name: string })[]>([])
  const [pendingArtefacts, setPendingArtefacts] = useState<{ id: string; title: string }[]>([])
  const [docPickerOpen, setDocPickerOpen] = useState(false)
  const [attachMenuOpen, setAttachMenuOpen] = useState(false)
  const [docQuery, setDocQuery] = useState('')
  const { data: allArtefacts = [] } = useArtefacts()
  const [uploadingCount, setUploadingCount] = useState(0)
  const fileInputRef = useRef<HTMLInputElement | null>(null)
  const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
  // Screenshots come in huge (a Retina grab is routinely 3-8MB of PNG), so
  // oversized images are optimized client-side before upload: longest edge
  // capped at 2048px (plenty for the agent's vision AND the Media Library)
  // and re-encoded as WebP. Best-effort — on any failure the original file
  // continues to the 5MB gate. Animated GIFs are skipped (canvas freezes
  // them to one frame).
  const OPTIMIZE_THRESHOLD_BYTES = 1_500_000
  const MAX_EDGE_PX = 2048
  const optimizeImage = async (file: File): Promise<File> => {
    if (file.type === 'image/gif' || file.size <= OPTIMIZE_THRESHOLD_BYTES) return file
    try {
      const bmp = await createImageBitmap(file)
      const scale = Math.min(1, MAX_EDGE_PX / Math.max(bmp.width, bmp.height))
      const w = Math.max(1, Math.round(bmp.width * scale))
      const h = Math.max(1, Math.round(bmp.height * scale))
      const canvas = document.createElement('canvas')
      canvas.width = w
      canvas.height = h
      const ctx = canvas.getContext('2d')
      if (!ctx) return file
      ctx.drawImage(bmp, 0, 0, w, h)
      bmp.close()
      const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/webp', 0.85))
      if (!blob || blob.size >= file.size) return file
      const base = file.name.replace(/\.[a-z0-9]+$/i, '')
      return new File([blob], `${base}.webp`, { type: 'image/webp' })
    } catch {
      return file
    }
  }
  const addImageFiles = async (files: FileList | File[]) => {
    const list = [...files].filter((f) => f && f.size > 0)
    for (const file of list) {
      if (pendingImages.length + uploadingCount >= 3) { toast.error('Up to 3 images per message.'); break }
      if (!IMAGE_MIMES.includes(file.type)) { toast.error(`${file.name}: only JPEG, PNG, WebP or GIF.`); continue }
      setUploadingCount((c) => c + 1)
      try {
        const upload = await optimizeImage(file)
        if (upload.size > 5 * 1024 * 1024) {
          toast.error(`${file.name} is over 5MB even after compression. Try a smaller image.`)
          continue
        }
        const fd = new FormData()
        fd.append('image', upload, upload.name)
        const up = await wpAjaxUpload<ChatAttachment>('bitbot_upload_chat_image', fd)
        setPendingImages((prev) => prev.length >= 3 ? prev : [...prev, { ...up, name: file.name }])
      } catch (e) {
        toast.error(e instanceof Error ? `${file.name}: ${e.message}` : `${file.name}: upload failed`)
      } finally {
        setUploadingCount((c) => c - 1)
      }
    }
  }
  const [drawer, setDrawer] = useState<DrawerKey>(null)
  // Live preview = the agent's eyes. One always-mounted iframe the agent drives
  // via viewSite; the panel just makes it visible to the owner, who is looking at
  // the real page rather than a picture of one.
  const browserSessionId = useRef(crypto.randomUUID()).current
  const adminBrowserRef = useRef<AdminBrowserHandle>(null)
  // The owner's Manual / Auto choice, remembered per site and kept across new
  // chats. Only the owner's own select changes it (adminWriteMode.ts).
  const writeModeStorageKey = writeModeKey(getConfig().siteUrl)
  const [writeMode, setWriteModeState] = useState<WriteMode>(() => readWriteMode(browserStorage(), writeModeStorageKey))
  const chooseWriteMode = (mode: WriteMode) => { setWriteModeState(mode); saveWriteMode(browserStorage(), writeModeStorageKey, mode) }
  const [permissionRevision, setPermissionRevision] = useState(0)
  const adminFrameRef = useRef<HTMLIFrameElement>(null)
  const [activePreviewScreen, setActivePreviewScreen] = useState<'site' | 'admin'>('site')
  const previewIframeRef = useRef<HTMLIFrameElement>(null)
  const previewScalerRef = useRef<HTMLDivElement>(null)
  const [previewViewport, setPreviewViewport] = useState<Viewport>('desktop')
  // SIGHT — opt-in, one browser prompt, a stream that stays open. Measurement
  // cannot judge a composition, a wrong-but-loaded image, or text on a busy
  // photo; a frame can. Declined or revoked, every look still works and simply
  // says it saw nothing. Never restored automatically on reload: a screen-share
  // grant needs a fresh user gesture, and pretending otherwise would be a lie
  // the owner could not see through.
  const [sightOn, setSightOn] = useState(false)
  const [sightBusy, setSightBusy] = useState(false)
  const [sightError, setSightError] = useState<string | null>(null)
  const sightSessionRef = useRef<SightSession | null>(null)
  if (!sightSessionRef.current && sightSupported()) {
    sightSessionRef.current = createSightSession(() => {
      setSightOn(false)
      setSightError(null)
    })
  }
  // Hand the grant back when the surface unmounts, so the browser's sharing
  // indicator never outlives the panel that asked for it.
  useEffect(() => () => sightSessionRef.current?.release(), [])
  // The agent's picture is of the page, not of our chrome. With Element Capture
  // (method 'element') the track holds only the page frame, so a drawer, the
  // document viewer or the expand pill over the dock is never in it. The screen
  // crop ('tab') would photograph whatever covers the dock instead.
  const captureClean = async (): Promise<SightCapture> => {
    const session = sightSessionRef.current
    if (!session) return { captured: false }
    const panel = document.querySelector('.bb-preview-panel')
    if (panel?.classList.contains('bb-preview-covered') && session.method() !== 'element') {
      // Data, never a request: the look carries on with its measurements.
      return { captured: false, method: 'tab', reason: 'a panel was open over the preview and this browser can only photograph the screen, so there is no picture of this look; the measurements are current' }
    }
    panel?.classList.add('bb-preview-capturing')
    try {
      await afterPaint(2)
      await new Promise<void>(resolve => window.setTimeout(resolve, 120))
      return await session.capture()
    } finally {
      panel?.classList.remove('bb-preview-capturing')
    }
  }
  const toggleSight = useCallback(async () => {
    const session = sightSessionRef.current
    if (!session) return
    if (session.live()) {
      session.stop()
      setSightError(null)
      return
    }
    setSightBusy(true)
    setSightError(null)
    const res = await session.grant(previewScalerRef.current)
    setSightBusy(false)
    if (res.ok) setSightOn(true)
    else setSightError(res.error)
  }, [])
  // ONE shared iframe, so preview commands MUST run one at a time: two viewSite
  // calls in a turn (desktop then mobile) would otherwise race the same frame and
  // return cross-contaminated geometry.
  const previewChain = useRef<Promise<void>>(Promise.resolve())
  const handledPreviewIds = useRef<Set<string>>(new Set())
  const siteBase = getConfig().siteUrl
  useEffect(() => {
    if (previewIframeRef.current && siteBase) previewIframeRef.current.src = siteBase
  }, [siteBase])
  // Compact live window; expanding keeps the same mounted editor and sharing session.
  const [previewWidth, setPreviewWidth] = useState(320)
  // Room the transcript leaves for the dock, and whether an Allow/Deny card waits.
  const [dockReserve, setDockReserve] = useState<DockBox['reserve']>({ right: 0, bottom: 0 })
  const [approvalPending, setApprovalPending] = useState(false)
  const setPreviewWidthPersist = useCallback((w: number) => {
    const clamped = Math.max(320, Math.min(w, window.innerWidth - 340))
    setPreviewWidth(clamped)
  }, [])
  const [artPanel, setArtPanel] = useState<ArtPanel>({ mode: 'closed' })
  const openArtefact = (id: string) => setArtPanel({ mode: 'view', id })
  const { data: scheduledTasks = [] } = useScheduledTasks()
  const { data: bgTasks = [] } = useTasks()
  const activeBgTasks = bgTasks.filter((t) => t.status === 'running' || t.status === 'queued')
  const { data: artefacts = [] } = useArtefacts()
  const { data: agentNotes = [] } = useAgentNotes()
  const { data: guardrails = [] } = useGuardrails()
  const { data: planStatus } = usePlanStatus()
  const agentCredit = planStatus?.agentCredit ?? null
  const creditGated = !!agentCredit && agentCredit.leftUsd <= 0

  // Reconcile-on-return from Stripe: credit the paid session (idempotent
  // with the webhook), then refresh the meter so the gate lifts. The param
  // is stripped immediately so back/refresh can't re-fire the flow.
  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    const sessionId = params.get('bb_topup_session')
    if (!sessionId) return
    params.delete('bb_topup_session')
    window.history.replaceState(
      null,
      '',
      `${window.location.pathname}?${params.toString()}${window.location.hash}`,
    )
    void rpc('VerifyAgentTopup', { sessionId }).then(() => {
      void qc.invalidateQueries({ queryKey: ['plan-status'] })
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])
  // Optimistic user-message bubble. The mutation only returns after the
  // model has replied (one round-trip for user persist + agent loop +
  // assistant persist), so without an optimistic echo the admin sees
  // the empty state until both messages land. Render this immediately
  // on submit, clear once the real transcript loads (or on error).
  const [pendingMessage, setPendingMessage] = useState<string | null>(null)
  // Live SSE stream params for the in-flight turn. Set by the send
  // mutation's onSuccess, cleared after a `done` event has had time
  // to land its persisted message via the conversation poll. While
  // set, the streaming bubble renders at the bottom of the transcript.
  const [streamParams, setStreamParams] = useState<
    { conversationId: string; sinceSequence: number } | null
  >(null)
  const transcriptRef = useRef<HTMLDivElement>(null)
  const composerRef = useRef<HTMLTextAreaElement>(null)
  // Tracks whether we've done the one-time "auto-pick the most recent
  // conversation on first paint" step. After it's flipped to true,
  // setActiveId(null) (i.e. clicking New chat) STAYS null and we render
  // the empty-state starter chips. Without this the effect below would
  // immediately re-select conversations[0] and silently undo New chat.
  const initialSelectDone = useRef(false)

  const qc = useQueryClient()
  const {
    conversations,
    total: conversationsTotal,
    hasMore: conversationsHasMore,
    isFetchingMore: conversationsLoadingMore,
    loadMore: loadMoreConversations,
    isSuccess: conversationsLoaded,
  } = useAdminConversations()
  const { data: detail } = useAdminConversation(activeId)
  const send = useSendAdminChat()
  const del = useDeleteAdminConversation()
  const cancel = useCancelAdminChat()
  // THE AGENT ASKED TO SEE THE SITE. Open the panel so the owner watches, drive
  // the iframe at the requested viewport, measure it, and post the reading back
  // so the waiting viewSite tool can hand it to the agent.
  //
  // The ACK goes first, before any rendering: it is the only thing that tells the
  // backend a browser exists at all. Without it the tool cannot distinguish
  // "nobody is watching" from "a browser took the look and died", and those two
  // demand opposite next moves from the agent.
  //
  // Deduped by requestId (the SSE replays on reconnect) and stale commands are
  // dropped, so reopening the tab mid-turn never re-runs an old look.
  const onPreviewCommand = useCallback(
    (cmd: Record<string, unknown>) => {
      if (cmd.mode === 'admin' || cmd.mode === 'write-approval') { adminBrowserRef.current?.handle(cmd); return }
      const requestId = String(cmd.requestId ?? '')
      const conversationId = String(cmd.conversationId ?? '')
      if (!requestId || !conversationId || handledPreviewIds.current.has(requestId)) return
      const emittedAt = typeof cmd.emittedAt === 'number' ? cmd.emittedAt : Date.now()
      if (Date.now() - emittedAt > 90_000) return
      handledPreviewIds.current.add(requestId)
      const vp: Viewport = (['desktop', 'tablet', 'mobile'] as const).includes(cmd.viewport as Viewport)
        ? (cmd.viewport as Viewport)
        : 'desktop'
      const command: PreviewCommand = {
        requestId,
        path: String(cmd.path ?? '/'),
        viewport: vp,
        measure: Array.isArray(cmd.measure) ? (cmd.measure as unknown[]).map(String) : [],
        scrollToSelector: typeof cmd.scrollToSelector === 'string' ? cmd.scrollToSelector : undefined,
        click: typeof cmd.click === 'string' ? cmd.click : undefined,
      }
      // Tell the backend a browser is here, immediately and outside the chain —
      // a look queued behind a slow one must not look like an absent browser.
      void rpc('SubmitPreviewResult', { conversationId, requestId, phase: 'ack' }).catch(() => undefined)
      // Serialize onto the shared iframe: apply the viewport, wait for the
      // resize to land, drive + measure, POST — all in one critical section so a
      // second command cannot resize or re-navigate mid-measure.
      previewChain.current = previewChain.current.then(async () => {
        setActivePreviewScreen('site')
        setPreviewViewport(vp)
        await afterPaint(2)
        // OUR OWN DEADLINE, and it is deliberately shorter than the tool's.
        // A page that will not finish loading must come back as a look that
        // FAILED, with a reason — never as silence, which the tool would have to
        // read as "no browser is connected" and blame the owner for.
        const result = previewIframeRef.current
          ? await Promise.race([
              runPreviewCommand(previewIframeRef.current, command, siteBase),
              new Promise<PreviewResult>((r) =>
                setTimeout(
                  () => r({ ok: false, error: `the page did not finish loading within 25s (${command.path})`, url: '', viewport: vp }),
                  25_000,
                ),
              ),
            ])
          : { ok: false, error: 'preview panel not mounted', url: '', viewport: vp }
        // THE PICTURE, IF THE OWNER GAVE US ONE. Taken here rather than inside
        // the controller because it captures the SCREEN, not the frame: the page
        // is settled and on show by now, at the scroll position the agent asked
        // for. It never gates the look — a refusal comes back as a reason the
        // agent is told, and the measurements stand either way.
        const sharing = !!sightSessionRef.current?.live()
        let shot: SightCapture = {
          captured: false,
          reason: sharing
            ? 'the page itself did not render, so there was nothing to photograph'
            : 'the owner has not turned on "let the agent see", so there is no picture of this page',
        }
        if (result.ok && sharing) {
          try {
            shot = await captureClean()
          } catch (e) {
            shot = { captured: false, reason: `the frame could not be taken (${e instanceof Error ? e.message : 'unknown error'}), so this look is measurements only` }
          }
        }
        const { base64, mediaType, ...sight } = shot
        try {
          await rpc('SubmitPreviewResult', {
            conversationId,
            requestId,
            phase: 'final',
            result: { ...result, sight },
            ...(shot.captured && base64 ? { frame: { base64, mediaType } } : {}),
          })
        } catch {
          /* the tool times out and tells the agent nothing was seen */
        }
      })
    },
    [siteBase],
  )
  const stream = useAdminChatStream(streamParams, onPreviewCommand)
  // Records messages.length the moment the stream opens. We unmount
  // the streaming bubble only after messages has grown by at least 2
  // (user + assistant) — i.e. the persisted versions of this turn
  // have arrived via the conversation refetch. Avoids the visual
  // gap where the streaming bubble vanished but the persisted bubble
  // hadn't loaded yet (up to 10s on the polling interval).
  const streamStartMsgCount = useRef<number | null>(null)

  // Auto-select most recent on first paint, exactly once.
  // Gated on `conversationsLoaded` — without that gate the effect fired
  // on initial render with conversations=[] (still loading), prematurely
  // flipped initialSelectDone to true, and then refused to auto-select
  // once the real data arrived. That's why coming back to the Agent tab
  // after a tab navigation always landed on the empty "Talk to your
  // bot" state even though the most recent turn was right there in
  // history.
  // Always open on the New chat screen — never auto-select the most recent
  // conversation. (User direction: every session starts fresh; History is one
  // click away in the rail.)
  void conversationsLoaded
  useEffect(() => {
    initialSelectDone.current = true
  }, [])

  const messages = useMemo(() => detail?.messages ?? [], [detail])

  // Auto-scroll to bottom on new messages / pending state.
  useEffect(() => {
    if (transcriptRef.current) {
      transcriptRef.current.scrollTop = transcriptRef.current.scrollHeight
    }
  }, [messages.length, send.isPending, permissionRevision])

  /**
   * Shared submit path used by both the composer (Enter / Send button)
   * and the starter chips. Sets the optimistic bubble, fires the
   * mutation, then clears the bubble once the real conversation
   * detail catches up (or on error).
   */
  const submit = (message: string) => {
    const hasImages = pendingImages.length > 0
    const hasDocs = pendingArtefacts.length > 0
    if ((!message.trim() && !hasImages && !hasDocs) || send.isPending) return
    const trimmed = message.trim() || (hasDocs ? '(attached document)' : '(attached image)')
    setPendingMessage(trimmed)
    const attachments = pendingImages.map(({ name: _n, ...a }) => a)
    const artefactIds = pendingArtefacts.map((a) => a.id)
    setPendingImages([])
    setPendingArtefacts([])
    setDocPickerOpen(false)
    send.mutate(
      // `canSee` is stated on every send, including false. The agent's hands
      // follow its eyes: without sight it leaves appearance alone and works on
      // content, posts, SEO, links and structure instead.
      { conversationId: activeId, message: trimmed, canSee: sightOn, browserSessionId, ...(attachments.length ? { attachments } : {}), ...(artefactIds.length ? { artefactIds } : {}) },
      {
        onSuccess: (data) => {
          if (!activeId) setActiveId(data.conversationId)
          // Kick off the SSE stream for the in-flight agent turn.
          // The stream renders a live bubble + tool group below the
          // existing transcript until `done` lands and the persisted
          // assistant message catches up via polling.
          setStreamParams({
            conversationId: data.conversationId,
            sinceSequence: data.sinceSequence,
          })
          // Don't clear pendingMessage here — the detail refetch is
          // still in-flight at this point, so clearing now would briefly
          // flash the empty state again. Clear inside the effect below
          // once the real messages have actually arrived.
        },
        onError: () => {
          setPendingMessage(null)
        },
      },
    )
  }

  // A BRIEF RUNS ONE STEP PER TURN, FROM THIS TAB. A message that named
  // several deliverables became a checklist (Tasks panel) whose steps run as
  // ordinary turns in this chat, each with this tab's eyes and Allow / Deny:
  // once a turn has settled, ask the server for the next step. It answers
  // `busy` while the last step is still settling and `idle` when nothing is
  // left. A busy answer is asked again after BRIEF_RETRY_MS, never sooner.
  const [briefRetry, setBriefRetry] = useState(0)
  const activeBrief = activeId
    ? activeBgTasks.find((t) => t.conversationId === activeId && t.originConversationId === activeId)
    : undefined
  const briefAskKey = useRef<string | null>(null)
  useEffect(() => {
    if (!activeBrief || !activeId || !detail) return
    if (send.isPending || streamParams || pendingMessage) return
    if (!conversationTailSettled(messages)) return
    const key = `${activeBrief.id}:${messages.length}:${briefRetry}`
    if (briefAskKey.current === key) return
    briefAskKey.current = key
    send.mutate(
      { conversationId: activeId, message: '', briefTaskId: activeBrief.id, canSee: sightOn, browserSessionId },
      {
        onSuccess: (data) => {
          if (data.brief === 'busy') {
            window.setTimeout(() => setBriefRetry((n) => n + 1), BRIEF_RETRY_MS)
            return
          }
          if (data.brief || data.sinceSequence < 0) return
          setStreamParams({ conversationId: data.conversationId, sinceSequence: data.sinceSequence })
        },
      },
    )
  }, [activeBrief, activeId, detail, send, streamParams, pendingMessage, messages, briefRetry, sightOn, browserSessionId])

  const onSend = () => {
    const message = draft.trim()
    if (!message) return
    setDraft('')
    submit(message)
  }

  // A turn is "generating" from the moment we POST until the stream reports
  // done/error. During that window the composer's action becomes Stop.
  const isGenerating =
    send.isPending ||
    (!!streamParams && stream.status !== 'done' && stream.status !== 'error')

  // Stop the in-flight turn. The backend aborts the model call, keeps the
  // partial reply, emits done, and persists it — the UI transitions normally.
  const stopGeneration = () => {
    const id = streamParams?.conversationId ?? activeId
    if (id) cancel.mutate({ conversationId: id })
  }

  // Once the real transcript contains our pending message (or anything
  // newer), drop the optimistic bubble. Matching on content is fine —
  // an admin double-clicking would just see the second submit replace
  // the bubble, which is correct.
  useEffect(() => {
    if (!pendingMessage) return
    // Attached-document sends persist with a hidden "[Attached document…]"
    // suffix, so match on prefix — exact equality left a stale optimistic
    // bubble duplicating the message below the reply.
    if (messages.some((m) => m.role === 'user' && (m.content === pendingMessage || m.content.startsWith(pendingMessage + '\n\n[Attached document')))) {
      setPendingMessage(null)
    }
  }, [messages, pendingMessage])

  // Record the message count when the stream opens so we can detect
  // when the persisted versions of this turn have landed via the
  // refetch we trigger on `done`. Without this baseline we'd have no
  // way to tell "new messages arrived" from "background poll completed
  // with stale data".
  useEffect(() => {
    if (streamParams) {
      streamStartMsgCount.current = messages.length
    } else {
      streamStartMsgCount.current = null
    }
    // Only track streamParams transitions — re-running this on every
    // messages change would constantly reset the baseline mid-stream.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [streamParams])

  // When the SSE stream finishes (done or error), poll the conversation
  // until the assistant row actually exists in the DB. The SSE `done`
  // event lands BEFORE AdminChat.send finishes persisting the assistant
  // row (the agent loop emits done, then the background promise writes
  // the row ~1-2 s later). A single invalidate fired on done would race
  // and return a tools-only snapshot — the streaming bubble would then
  // unmount with no assistant bubble to replace it. Retry every 800 ms
  // until the latest message is the assistant turn we just streamed.
  useEffect(() => {
    if (!streamParams) return
    if (stream.status !== 'done' && stream.status !== 'error') return
    let cancelled = false
    let tries = 0
    const tick = async () => {
      if (cancelled) return
      tries += 1
      await qc.invalidateQueries({
        queryKey: ['admin-chat', 'conversation', streamParams.conversationId],
      })
      // Give up after ~12 s. By then either something is wrong upstream
      // or the user has moved on.
      if (tries < 15) setTimeout(tick, 800)
    }
    tick()

    // Refresh the tab-strip badge counts. Agent turns can create drafts
    // (generatePostFromTopic / generatePostFromUrl / draftFaqFromGap),
    // qualify leads, or capture a handover — none of which the other
    // tabs' caches know about without an explicit invalidation. Cheap
    // blanket-invalidate; the per-tab hooks refetch on next mount or
    // background interval anyway, so we're just speeding up the badge.
    qc.invalidateQueries({ queryKey: ['drafts'] })
    qc.invalidateQueries({ queryKey: ['faqs'] })
    qc.invalidateQueries({ queryKey: ['leads'] })
    qc.invalidateQueries({ queryKey: ['unviewed-leads'] })
    qc.invalidateQueries({ queryKey: ['artefacts'] })
    // The Usage % moves with the turn just spent (and a used-up reply brings
    // the Paused card up without waiting out the 5-minute cache).
    qc.invalidateQueries({ queryKey: PLAN_STATUS_KEY })

    return () => { cancelled = true }
  }, [stream.status, streamParams, qc])

  // Again once the turn's reply has landed: the usage ledger row is written
  // just AFTER the stream's `done`, so the refresh above can read the meter a
  // moment early. A settled tail also covers turns this tab did not stream
  // (brief steps, background task reports).
  const tailSettled = conversationTailSettled(messages)
  const wasSettled = useRef(tailSettled)
  useEffect(() => {
    if (tailSettled && !wasSettled.current) qc.invalidateQueries({ queryKey: PLAN_STATUS_KEY })
    wasSettled.current = tailSettled
  }, [tailSettled, qc])

  // Drop the streaming bubble once this turn's persisted rows have landed.
  // DIRECT EVIDENCE first: the persisted tail contains the exact final text we
  // just streamed (the count-baseline walk alone could wedge — a stale
  // baseline left the streaming copy mounted forever next to the persisted
  // one, the "same turn twice" bug). The baseline walk stays as fallback for
  // clipped-at-persist texts; and a hard timeout below guarantees the bubble
  // is NEVER immortal.
  useEffect(() => {
    if (!streamParams) return
    // Evidence 1: exact final-text match in the persisted tail.
    const finalText = stream.status === 'done' ? stream.text.trim() : ''
    if (finalText) {
      const tail = messages.slice(-12)
      if (tail.some((m) => m.role === 'assistant' && m.content.trim() === finalText)) {
        setStreamParams(null)
        return
      }
    }
    // Evidence 2 (fallback): a NEW assistant row past the stream-open baseline.
    // Only once the stream has FINISHED and the persisted tail is settled: a
    // turn that calls tools persists one assistant row per text segment
    // while it is still running, and the detail poll (every 2 s while the
    // tail is unsettled) lands them mid-turn. Dropping the live bubble on
    // one of those made the reply vanish into the "Working" dots and come
    // back only when the final row arrived (seen by the owner, 2026-11-05).
    if (stream.status !== 'done' && stream.status !== 'error') return
    if (!conversationTailSettled(messages)) return
    if (streamStartMsgCount.current === null) return
    if (messages.length <= streamStartMsgCount.current) return
    for (let i = messages.length - 1; i >= streamStartMsgCount.current; i--) {
      if (messages[i]?.role === 'assistant') {
        setStreamParams(null)
        return
      }
    }
  }, [messages, streamParams, stream.status, stream.text])

  // Backstop: once the stream is finished (done or error), the bubble may live
  // only as long as the persisted-row catch-up reasonably takes. After that,
  // unmount unconditionally — a wedged heuristic must never leave the turn
  // rendered twice for minutes.
  useEffect(() => {
    if (!streamParams) return
    if (stream.status !== 'done' && stream.status !== 'error') return
    const t = setTimeout(() => setStreamParams(null), 20_000)
    return () => clearTimeout(t)
  }, [streamParams, stream.status])

  // Artefact panel auto-open: show a spinner while renderDocument runs, then
  // swap to the finished document the moment it lands in the live stream.
  // Edge-detected (prevGen) so the panel opens once per generation; seen-set so
  // a completed artefact only auto-opens once.
  const prevGenRef = useRef(false)
  const seenArtefactRef = useRef<string | null>(null)
  useEffect(() => {
    if (!streamParams) { prevGenRef.current = false; return }
    const gen = stream.tools.some((t) => t.toolName === 'renderDocument' && t.status === 'in_progress')
    if (gen && !prevGenRef.current) setArtPanel({ mode: 'generating' })
    prevGenRef.current = gen
    for (let i = stream.tools.length - 1; i >= 0; i--) {
      const art: unknown = stream.tools[i]?.artifact
      if (isArtefactArtifact(art)) {
        if (seenArtefactRef.current !== art.id) {
          seenArtefactRef.current = art.id
          setArtPanel({ mode: 'view', id: art.id })
        }
        break
      }
    }
  }, [stream.tools, streamParams])

  const newChat = () => {
    setActiveId(null)
    setDraft('')
    if (drawer === 'history') setDrawer(null)
  }

  const activeTitle = useMemo(() => {
    if (!activeId) return null
    return (
      conversations.find((c) => c.id === activeId)?.title ??
      detail?.summary.title ??
      null
    )
  }, [activeId, conversations, detail])

  return (
    <div
      className="bb-agent-surface"
    >
      {/* ── Left rail (was the top action bar) ── */}
      <nav className="bb-agent-rail">
        <button
          type="button"
          className="bb-agent-action bb-agent-action-primary"
          onClick={newChat}
          title="Start a new chat"
        >
          <span className="bb-agent-action-glyph">＋</span>
          <span>New chat</span>
        </button>
        <button
          type="button"
          className="bb-agent-action"
          onClick={() => setDrawer('history')}
        >
          <span className="bb-agent-action-glyph">⌖</span>
          <span>History</span>
          {conversations.length > 0 && (
            <span className="bb-agent-action-count">{conversations.length}</span>
          )}
        </button>
        <button
          type="button"
          className="bb-agent-action"
          onClick={() => setDrawer('scheduler')}
        >
          <span className="bb-agent-action-glyph">◷</span>
          <span>Scheduler</span>
          {scheduledTasks.length > 0 && (
            <span className="bb-agent-action-count">{scheduledTasks.length}</span>
          )}
        </button>
        <button
          type="button"
          className="bb-agent-action"
          onClick={() => setDrawer('tasks')}
        >
          <span className="bb-agent-action-glyph">☑</span>
          <span>{TASKS_DRAWER_LABEL}</span>
          {activeBgTasks.length > 0 && (
            <span className="bb-agent-action-count">{activeBgTasks.length}</span>
          )}
        </button>
        <button
          type="button"
          className="bb-agent-action"
          onClick={() => setDrawer('artefacts')}
        >
          <span className="bb-agent-action-glyph">▤</span>
          <span>Artefacts</span>
          {artefacts.length > 0 && (
            <span className="bb-agent-action-count">{artefacts.length}</span>
          )}
        </button>


        <button
          type="button"
          className="bb-agent-action"
          onClick={() => setDrawer('memory')}
          title="What Youami remembers — standing rules it applies to every run"
        >
          <span className="bb-agent-action-glyph">✲</span>
          <span>Memory</span>
          {agentNotes.length > 0 && (
            <span className="bb-agent-action-count">{agentNotes.length}</span>
          )}
        </button>
        <button
          type="button"
          className="bb-agent-action"
          onClick={() => setDrawer('guardrails')}
          title="Guardrails — hard compliance rules every outward action is checked against"
        >
          <span className="bb-agent-action-glyph">⛨</span>
          <span>Guardrails</span>
          {guardrails.length > 0 && (
            <span className="bb-agent-action-count">{guardrails.length}</span>
          )}
        </button>
        <button
          type="button"
          className="bb-agent-action"
          onClick={() => setDrawer('integrations')}
        >
          <span className="bb-agent-action-glyph">⚙</span>
          <span>Integrations</span>
        </button>

        {agentCredit && (
          <button
            type="button"
            className="bb-agent-action"
            onClick={() => setDrawer('usage')}
            title={badgeTooltip(usageCredits(agentCredit))}
          >
            <span className="bb-agent-action-glyph">◈</span>
            <span>Usage</span>
            <span className="bb-agent-action-count">
              {agentCredit.includedUsd > 0
                ? Math.min(100, Math.max(0, Math.round((agentCredit.usedUsd / agentCredit.includedUsd) * 100)))
                : 0}
              %
            </span>
          </button>
        )}
      </nav>

      {/* ── Main column ── */}
      <div className="bb-agent-main">
        {activeTitle && (
          <header className="bb-agent-chathead">
            <span className="bb-agent-active-title" title={activeTitle}>
              {activeTitle}
            </span>
          </header>
        )}

      {/* ── Transcript ── */}
      <div
        className="bb-agent-transcript"
        ref={transcriptRef}
        style={{ paddingRight: dockReserve.right || undefined, paddingBottom: dockReserve.bottom || undefined }}
      >
        {messages.length === 0 && !pendingMessage ? (
          <AgentHomeEmpty onPick={submit} sending={send.isPending} gated={creditGated} />
        ) : (
          <>
            {renderMessages(messages, openArtefact)}
            {/* A reopened conversation whose turn is still running/persisting
                shows a working indicator instead of a silent half-transcript
                (the query above keeps polling until the tail settles). */}
            {!streamParams && !pendingMessage && messages.length > 0 && !conversationTailSettled(messages) && (
              <div key="tail-working" className="bb-adminchat-bubble bb-adminchat-bubble-assistant bb-adminchat-bubble-streaming">
                <div className="bb-adminchat-bubble-body">
                  <span className="bb-adminchat-typing"><i/><i/><i/></span>
                </div>
              </div>
            )}
            {/* These two trail a KEYED message array. Without their own stable
                keys React reconciles them positionally, and when a stream
                settles (the messages array grows AND these toggle off in the
                same commit) the keyed/unkeyed position diff desyncs → unmount
                throws "removeChild ... not a child of this node". Stable keys
                pin them. */}
            {pendingMessage && (
              <div key="pending-bubble" className="bb-adminchat-bubble bb-adminchat-bubble-user">
                <div className="bb-adminchat-bubble-body">{pendingMessage}</div>
                {/* Match the persisted MessageBubble's timestamp so the
                    bubble doesn't visually shift when the optimistic
                    version is swapped for the persisted one. */}
                <div className="bb-adminchat-bubble-time">just now</div>
              </div>
            )}
            {streamParams && (stream.status === 'streaming' || stream.tools.length > 0 || stream.text) && (
              <StreamingTurn key="streaming-turn" stream={stream} onOpenArtefact={openArtefact} />
            )}
          </>
        )}
        {/* Show the typing indicator only while the POST is in flight
            (kickoff). Once the stream is open, the streaming bubble
            itself takes over with live chips + text. */}
        {send.isPending && stream.status !== 'streaming' && (
          <div className="bb-adminchat-pending">
            <span className="bb-adminchat-typing"><i/><i/><i/></span>
          </div>
        )}
        <AdminBrowserPanel sightOn={sightOn} sightLive={() => !!sightSessionRef.current?.live()} capture={async () => {
          const frame = adminFrameRef.current
          const visible = () => !!frame?.isConnected && getComputedStyle(frame).display !== 'none'
          if (!visible() || !sightSessionRef.current?.live()) return { captured: false, reason: 'The admin screen is not visible or sharing is off.' }
          const shot = await captureClean()
          return visible() && frame === adminFrameRef.current ? shot : { captured: false, reason: 'The preview switched screens during capture; no editor image was returned.' }
        }} frame={adminFrameRef} onActivate={() => setActivePreviewScreen('admin')} ref={adminBrowserRef} adminBase={new URL('.', getConfig().ajaxUrl).href} conversationId={activeId} browserSessionId={browserSessionId} mode={writeMode} onPermissionChange={() => setPermissionRevision(v => v + 1)} onPendingChange={setApprovalPending} onCleared={() => setActivePreviewScreen('site')} />
      </div>

      {/* ── Composer (or the credit gate when the allowance is spent) ── */}
      {creditGated && agentCredit ? (
        <AgentTopupGate credit={agentCredit} />
      ) : (
      <>
      {(pendingImages.length > 0 || uploadingCount > 0 || pendingArtefacts.length > 0) && (
        <div className="bb-composer-attachments">
          {pendingArtefacts.map((d) => (
            <span key={d.id} className="bb-composer-doc">
              <span aria-hidden>📄</span>
              <span className="bb-composer-doc-title">{d.title}</span>
              <button type="button" aria-label={`Remove ${d.title}`} onClick={() => setPendingArtefacts((prev) => prev.filter((p) => p.id !== d.id))}>✕</button>
            </span>
          ))}
          {pendingImages.map((img) => (
            <span key={img.attachmentId} className="bb-composer-thumb">
              <img src={img.url} alt={img.name} />
              <button type="button" aria-label={`Remove ${img.name}`} onClick={() => setPendingImages((prev) => prev.filter((p) => p.attachmentId !== img.attachmentId))}>✕</button>
            </span>
          ))}
          {uploadingCount > 0 && <span className="bb-composer-thumb bb-composer-thumb-uploading">…</span>}
        </div>
      )}
      <div
        className="bb-agent-composer"
        onDragOver={(e) => { e.preventDefault() }}
        onDrop={(e) => {
          if (e.dataTransfer?.files?.length) { e.preventDefault(); void addImageFiles(e.dataTransfer.files) }
        }}
      >
        <input
          ref={fileInputRef}
          type="file"
          accept="image/jpeg,image/png,image/webp,image/gif"
          multiple
          style={{ display: 'none' }}
          onChange={(e) => { if (e.target.files?.length) { void addImageFiles(e.target.files); e.target.value = '' } }}
        />
        <button
          type="button"
          className="bb-composer-attach bb-composer-plus"
          title="Attach"
          aria-expanded={attachMenuOpen}
          onClick={() => { setAttachMenuOpen((v) => !v); setDocPickerOpen(false) }}
        >＋</button>
        {attachMenuOpen && (
          <div className="bb-composer-attachmenu">
            <button
              type="button"
              onClick={() => { setAttachMenuOpen(false); fileInputRef.current?.click() }}
            ><span aria-hidden>🖼</span> Add photos</button>
            <button
              type="button"
              onClick={() => { setAttachMenuOpen(false); setDocPickerOpen(true) }}
            ><span aria-hidden>📄</span> Refer to a document</button>
          </div>
        )}
        {docPickerOpen && (
          <div className="bb-composer-docpicker">
            <input
              className="bb-art-search"
              type="search"
              autoFocus
              placeholder="Search documents…"
              value={docQuery}
              onChange={(e) => setDocQuery(e.target.value)}
            />
            <div className="bb-composer-docpicker-list">
              {allArtefacts
                .filter((a) => !docQuery.trim() || a.title.toLowerCase().includes(docQuery.trim().toLowerCase()))
                .slice(0, 30)
                .map((a) => (
                  <button
                    key={a.id}
                    type="button"
                    className="bb-composer-docpicker-item"
                    disabled={pendingArtefacts.some((p) => p.id === a.id) || pendingArtefacts.length >= 3}
                    onClick={() => {
                      setPendingArtefacts((prev) => prev.some((p) => p.id === a.id) ? prev : [...prev, { id: a.id, title: a.title }])
                      setDocPickerOpen(false)
                      setDocQuery('')
                    }}
                  >
                    <span className="bb-composer-docpicker-title">{a.title}</span>
                    <span className="bb-composer-docpicker-meta">{new Date(a.createdAt).toLocaleDateString()}</span>
                  </button>
                ))}
              {allArtefacts.length === 0 && <div className="bb-art-empty">No documents yet.</div>}
            </div>
          </div>
        )}
        <textarea
          ref={composerRef}
          className="bb-adminchat-input"
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          placeholder="Type a message…"
          rows={2}
          onPaste={(e) => {
            const files = [...(e.clipboardData?.files ?? [])]
            if (files.length) { e.preventDefault(); void addImageFiles(files) }
          }}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
              e.preventDefault()
              if (!isGenerating) onSend()
            }
          }}
        />
        <label className="bb-write-mode">
          <span className="screen-reader-text">Write permissions</span>
          <select aria-label="Write permissions" value={writeMode} onChange={e => chooseWriteMode(e.target.value === 'auto' ? 'auto' : 'manual')} title="Auto runs writes without asking. Manual asks before each write. Reads never ask.">
            <option value="auto">⚡ Auto</option><option value="manual">Manual</option>
          </select>
          <span className="bb-write-mode-hint">With Auto on, the agent acts in your wp-admin without asking each time.</span>
        </label>
        {isGenerating ? (
          <button
            type="button"
            className="bb-btn bb-btn-stop"
            onClick={stopGeneration}
            disabled={cancel.isPending}
            title="Stop generating"
          >
            <span className="bb-btn-stop-icon" aria-hidden="true" />
            {cancel.isPending ? 'Stopping…' : 'Stop'}
          </button>
        ) : (
          <button
            type="button"
            className="bb-btn bb-btn-primary"
            disabled={!draft.trim() && pendingImages.length === 0}
            onClick={onSend}
          >
            Send
          </button>
        )}
      </div>
      </>
      )}
      <PreviewPanel
        adminFrameRef={adminFrameRef}
        activeScreen={activePreviewScreen}
        iframeRef={previewIframeRef}
        scalerRef={previewScalerRef}
        viewport={previewViewport}
        width={previewWidth}
        onResize={setPreviewWidthPersist}
        sight={{ supported: sightSupported(), on: sightOn, busy: sightBusy, error: sightError }}
        onToggleSight={() => void toggleSight()}
        approvalPending={approvalPending}
        onReserve={setDockReserve}
        covered={drawer !== null || artPanel.mode !== 'closed'}
      />
      </div>{/* /bb-agent-main */}

      {/* ── Drawers ── */}
      <AgentDrawer
        open={drawer === 'history'}
        title="Recent chats"
        onClose={() => setDrawer(null)}
      >
        <HistoryDrawerBody
          conversations={conversations}
          total={conversationsTotal}
          hasMore={conversationsHasMore}
          isLoadingMore={conversationsLoadingMore}
          onLoadMore={loadMoreConversations}
          activeId={activeId}
          onSelect={(id) => {
            setActiveId(id)
            setDrawer(null)
          }}
          onDelete={async (id) => {
            const ok = await confirm({
              title: 'Delete this conversation?',
              message: 'This permanently removes the conversation. This cannot be undone.',
              confirmLabel: 'Delete',
              danger: true,
            })
            if (!ok) return
            del.mutate(
              { conversationId: id },
              {
                onSuccess: () => {
                  // If we deleted the open conversation, jump to the next
                  // one in the list — falling back to "new chat" empty
                  // state if it was the last. Without this the right pane
                  // shows stale cached content for a deleted id.
                  if (id === activeId) {
                    const next = conversations.find((c) => c.id !== id)?.id ?? null
                    setActiveId(next)
                  }
                },
              },
            )
          }}
          onDeleteBulk={async (ids) => {
            if (ids.length === 0) return
            const ok = await confirm({
              title: ids.length === 1 ? 'Delete this conversation?' : `Delete ${ids.length} conversations?`,
              message: 'This permanently removes them. This cannot be undone.',
              confirmLabel: ids.length === 1 ? 'Delete' : `Delete ${ids.length}`,
              danger: true,
            })
            if (!ok) return
            // Fire delete mutations in parallel — the AdminChatDelete
            // RPC handles one id per call. For typical admin volume
            // (10-30 convs) the parallel fan-out is well under a second.
            // If the active conversation is in the batch, jump to the
            // first survivor before the requests fire so the right
            // pane doesn't render stale content mid-flight.
            const idSet = new Set(ids)
            if (activeId && idSet.has(activeId)) {
              const survivor = conversations.find((c) => !idSet.has(c.id))?.id ?? null
              setActiveId(survivor)
            }
            for (const id of ids) {
              del.mutate({ conversationId: id })
            }
          }}
        />
      </AgentDrawer>

      <AgentDrawer
        open={drawer === 'integrations'}
        title="Integrations"
        onClose={() => setDrawer(null)}
      >
        {/* Mount only while open so closing resets the body to the integration
            list (instead of reopening on the last-viewed card). */}
        {drawer === 'integrations' && <IntegrationsDrawerBody />}
      </AgentDrawer>

      <AgentDrawer
        open={drawer === 'scheduler'}
        title="Scheduler"
        onClose={() => setDrawer(null)}
      >
        {drawer === 'scheduler' && (
          <SchedulerDrawerBody
            onOpenConversation={(id, live) => {
              setActiveId(id)
              setDrawer(null)
              // A scheduled run streams its work to admin_chat_events exactly
              // like an interactive turn. If it's still in flight, subscribe to
              // that same stream (since=0 replays the run from its start) so the
              // user watches it live instead of a dead screen. Otherwise clear
              // any prior stream and just show the persisted transcript.
              setStreamParams(live ? { conversationId: id, sinceSequence: 0 } : null)
            }}
          />
        )}
      </AgentDrawer>

      <AgentDrawer
        open={drawer === 'tasks'}
        title={TASKS_DRAWER_LABEL}
        onClose={() => setDrawer(null)}
      >
        {drawer === 'tasks' && <TasksDrawerBody />}
      </AgentDrawer>

      {/* Memory — standing rules Youami applies to every run. */}
      <AgentDrawer
        open={drawer === 'memory'}
        title="What Youami remembers"
        onClose={() => setDrawer(null)}
      >
        {drawer === 'memory' && <MemoryDrawerBody />}
      </AgentDrawer>

      {/* Guardrails — hard compliance constraints, enforced on outward actions. */}
      <AgentDrawer
        open={drawer === 'guardrails'}
        title="Guardrails"
        onClose={() => setDrawer(null)}
      >
        {drawer === 'guardrails' && <GuardrailsDrawerBody />}
      </AgentDrawer>

      {/* Usage: percentage meters for the monthly allowance + today's pace. */}
      <AgentDrawer
        open={drawer === 'usage'}
        title="Usage"
        onClose={() => setDrawer(null)}
      >
        {drawer === 'usage' && <UsageDrawerBody />}
      </AgentDrawer>
      {/* Live preview — always mounted so the agent's viewSite can drive it
          whether or not the panel is visible; the panel just shows it. */}



      {/* Artefacts submenu — list of saved documents. */}
      <AgentDrawer
        open={drawer === 'artefacts'}
        title="Artefacts"
        onClose={() => setDrawer(null)}
      >
        {drawer === 'artefacts' && (
          <ArtefactsListBody onOpen={(id) => { setArtPanel({ mode: 'view', id, from: 'list' }); setDrawer(null) }} />
        )}
      </AgentDrawer>

      {/* Artefact viewer — wide drawer. Auto-opens on generation: a spinner
          while renderDocument runs, then the document preview + Download PDF. */}
      <AgentDrawer
        open={artPanel.mode !== 'closed'}
        title="Document"
        wide
        onClose={() => {
          // Opened from the Artefacts list ⇒ closing goes BACK there, not all
          // the way out (re-opening the list by hand every time was tedious).
          const cameFromList = artPanel.mode === 'view' && artPanel.from === 'list'
          setArtPanel({ mode: 'closed' })
          if (cameFromList) setDrawer('artefacts')
        }}
      >
        {artPanel.mode === 'view'
          ? <>
              {artPanel.from === 'list' && (
                <button
                  type="button"
                  className="bb-art-back"
                  onClick={() => { setArtPanel({ mode: 'closed' }); setDrawer('artefacts') }}
                >← All documents</button>
              )}
              <ArtefactViewerBody artefactId={artPanel.id} />
            </>
          : artPanel.mode === 'generating'
            ? <div className="bb-art-empty">Generating your document…</div>
            : null}
      </AgentDrawer>
    </div>
  )
}
