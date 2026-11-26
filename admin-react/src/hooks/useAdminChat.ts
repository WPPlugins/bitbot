/**
 * Admin chat — the back-unit chat surface (admin talks to their own bot).
 *
 * Tied to four RPCs (AdminChatService, packages/api/src/services/AdminChat.ts):
 *   AdminChatListConversations  → left-rail conversation list
 *   AdminChatGetConversation    → transcript + summary for one convo
 *   AdminChatSend               → send a user turn, get assistant reply
 *   AdminChatArchive            → toggle live ↔ archived
 *
 * Conversations are polled lazily — once the admin opens the tab, we
 * refetch the list every 30s so new replies from background agent runs
 * surface without a manual refresh. The active transcript polls faster
 * (10s) while the admin is reading it. No realtime channel yet; this is
 * "good enough" until skill activity surges.
 */
import { useEffect, useReducer, useRef } from 'react'
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'
import { toast } from '@/components/ui/Toast'
import { getConfig } from '@/lib/config'
import { ownerDateContext } from '@/lib/resetDate'

// ── Types (mirror packages/api/src/rpc/schema.ts AdminChat* classes) ──

export type AdminChatRole = 'user' | 'assistant' | 'tool'

export interface AdminChatMessageInfo {
  id: string
  role: AdminChatRole
  content: string
  toolName: string | null
  artifacts: unknown
  sentAt: string
  /** Turn usage (assistant rows only) — powers the per-response footer. */
  inputTokens: number | null
  outputTokens: number | null
  costUsd: number | null
}

/** Token + cost totals for one assistant turn. */
export interface AdminChatUsage {
  inputTokens: number
  outputTokens: number
  costUsd: number
}

export interface AdminConversationSummary {
  id: string
  title: string | null
  messageCount: number
  lastMessageAt: string
  startedAt: string
}

export interface AdminConversationDetail {
  summary: AdminConversationSummary
  messages: AdminChatMessageInfo[]
}

export interface AdminChatSendResponse {
  conversationId: string
  /**
   * Event sequence already present on the conversation BEFORE this
   * turn was kicked off. Pass to useAdminChatStream so the SSE only
   * delivers events the current turn emits — not the whole history.
   */
  sinceSequence: number
  /**
   * Set when the send asked for a brief's next step and none was started:
   * `busy` = the last step is still settling (ask again shortly), `idle` =
   * nothing is left. No turn runs and there is nothing to stream.
   */
  brief?: 'busy' | 'idle'
}

// ── Queries ───────────────────────────────────────────────────────────

/**
 * Paginated conversation list with Load-more support. Pre-2.0.69 this
 * was a single useQuery capped server-side at 50, which silently dropped
 * older conversations as soon as the admin had been chatting for a
 * while. useInfiniteQuery + an explicit "N of TOTAL" count keeps the UI
 * honest about what's actually loaded.
 */
const CONVERSATIONS_PAGE_SIZE = 25

interface ConversationsPage {
  conversations: AdminConversationSummary[]
  total: number
  page: number
  pageSize: number
}

export function useAdminConversations() {
  const query = useInfiniteQuery({
    queryKey: ['admin-chat', 'conversations'],
    queryFn: async ({ pageParam }): Promise<ConversationsPage> => {
      const res = await rpc<ConversationsPage>('AdminChatListConversations', {
        page: pageParam,
        pageSize: CONVERSATIONS_PAGE_SIZE,
      })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    initialPageParam: 1,
    getNextPageParam: (lastPage) => {
      const loaded = lastPage.page * lastPage.pageSize
      return loaded < lastPage.total ? lastPage.page + 1 : undefined
    },
    refetchInterval: 30_000,
    staleTime: 10_000,
  })

  const conversations = query.data?.pages.flatMap((p) => p.conversations) ?? []
  const total = query.data?.pages[0]?.total ?? 0

  return {
    ...query,
    conversations,
    total,
    hasMore: !!query.hasNextPage,
    isFetchingMore: query.isFetchingNextPage,
    loadMore: () => {
      if (query.hasNextPage && !query.isFetchingNextPage) query.fetchNextPage()
    },
  }
}

export function useAdminConversation(conversationId: string | null) {
  return useQuery({
    queryKey: ['admin-chat', 'conversation', conversationId],
    queryFn: async (): Promise<AdminConversationDetail | null> => {
      if (!conversationId) return null
      const res = await rpc<AdminConversationDetail>('AdminChatGetConversation', {
        conversationId,
      })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    enabled: !!conversationId,
    // The background persists the turn AFTER the SSE `done` fires (rows land
    // over the following seconds), so invalidate-once-on-done raced it and
    // froze a PARTIAL transcript — the owner saw half a turn with no hint
    // more was coming. Data-driven self-heal: keep polling while the
    // transcript tail is unsettled (see conversationTailSettled).
    // Once settled, drop to a slow idle poll instead of stopping: background
    // Task completion reports are appended OUTSIDE any turn, and with no idle
    // poll they only surfaced on reopen. Reports carry a zero-token usage
    // stamp, so the tail stays settled when one lands (no phantom spinner).
    staleTime: 0,
    refetchOnMount: 'always',
    refetchInterval: (query) => {
      const data = query.state.data
      if (!data?.messages?.length) return false
      return conversationTailSettled(data.messages) ? 12_000 : 2_000
    },
  })
}

/**
 * A conversation is settled when its last non-tool row is the final assistant
 * reply (it carries the usage footer). A recent unsettled tail means a turn
 * is still running or persisting — poll and show the working indicator. A
 * STALE unsettled tail (>3 min) is a dead turn; stop pretending.
 */
export function conversationTailSettled(messages: readonly AdminChatMessageInfo[]): boolean {
  const last = [...messages].reverse().find((m) => m.role !== 'tool')
  if (!last) return true
  const ageMs = Date.now() - new Date(last.sentAt).getTime()
  if (last.role === 'assistant' && (last.outputTokens != null || last.costUsd != null)) return true
  return ageMs > 3 * 60_000
}

// ── Mutations ─────────────────────────────────────────────────────────

export interface ChatAttachment {
  attachmentId: number
  url: string
  mime: string
  width?: number
  height?: number
}

interface SendArgs {
  conversationId: string | null
  message: string
  attachments?: ChatAttachment[]
  artefactIds?: string[]
  /**
   * Is the owner sharing this tab, so the agent has real frames this turn?
   *
   * Only the browser knows. Without it the agent has measurements, which settle
   * geometry and contrast but cannot judge a composition — so per owner
   * directive it leaves appearance alone and works on everything else. Omitted
   * means the client did not say, which the backend treats as "unknown", never
   * as "no".
   */
  browserSessionId?: string
  canSee?: boolean
  /** Ask for this brief's next step instead of sending a message (the server composes it). */
  briefTaskId?: string
}

export function useSendAdminChat() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ conversationId, message, attachments, artefactIds, canSee, browserSessionId, briefTaskId }: SendArgs): Promise<AdminChatSendResponse> => {
      const res = await rpc<AdminChatSendResponse>('AdminChatSend', {
        conversationId,
        message,
        browserSessionId,
        // Dates in the reply (the credit reset date) read as the card shows them.
        ...ownerDateContext(),
        ...(briefTaskId ? { briefTaskId } : {}),
        ...(attachments?.length ? { attachments } : {}),
        ...(artefactIds?.length ? { artefactIds } : {}),
        ...(typeof canSee === 'boolean' ? { canSee } : {}),
      })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ['admin-chat', 'conversations'] })
      qc.invalidateQueries({ queryKey: ['admin-chat', 'conversation', data.conversationId] })
      // A message can create a brief (a checklist this chat then runs step by
      // step): fetch the task list now rather than on its slow idle poll. Not
      // on a brief ask that started nothing, which would only re-ask.
      if (!data.brief) qc.invalidateQueries({ queryKey: ['tasks'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Send failed'),
  })
}

export function useDeleteAdminConversation() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ conversationId }: { conversationId: string }) => {
      const res = await rpc<{ ok: boolean }>('AdminChatDelete', { conversationId })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => {
      toast.success('Conversation deleted')
      qc.invalidateQueries({ queryKey: ['admin-chat'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Delete failed'),
  })
}

/**
 * Request cancellation of an in-flight turn. Fire-and-forget from the UI's
 * point of view: the running background stops at its next boundary and
 * persists the partial reply, which the conversation poll then brings in.
 */
export function useCancelAdminChat() {
  return useMutation({
    mutationFn: async ({ conversationId }: { conversationId: string }) => {
      const res = await rpc<{ ok: boolean }>('AdminChatCancel', { conversationId })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    // Deliberately quiet — no toast. Stopping is a normal, expected action.
    onError: () => {},
  })
}

// ── Streaming ─────────────────────────────────────────────────────────
//
// The admin chat send pattern is now async:
//   1. POST AdminChatSend returns { conversationId, sinceSequence } the
//      moment the user message lands in the DB. The agent loop runs in
//      a Vercel waitUntil() so it survives tab navigation.
//   2. The frontend opens an SSE connection to
//      /api/admin-chat/stream/:conversationId?since=<sinceSequence>
//      and renders events as they arrive (tool boundaries, text deltas,
//      done).
//   3. If the connection drops, EventSource auto-reconnects with the
//      Last-Event-ID header set to the highest sequence we saw —
//      server replays missed events.

export type AdminChatStreamToolStatus = 'in_progress' | 'ok' | 'error'

export interface ChartArtifact {
  kind: 'chart'
  chartKind: 'line' | 'bar' | 'area' | 'pie'
  title: string | null
  xKey: string
  yKeys: string[]
  data: Array<Record<string, unknown>>
}

/** A document artefact (renderDocument) — rides the stream so the panel can
 *  auto-open live, not just after the persisted message reloads. */
export interface ArtefactArtifact {
  kind: 'artefact'
  id: string
  title: string
  docKind?: string
}

export type StreamArtifact = ChartArtifact | ArtefactArtifact

export interface AdminChatStreamTool {
  /** Stable ordering key — the sequence of the tool_call_started event. */
  startedAt: number
  toolName: string
  status: AdminChatStreamToolStatus
  error?: string
  /** Filled when the tool emitted a renderable artifact (chart or artefact). */
  artifact?: StreamArtifact
}

/**
 * The stream in EXECUTION ORDER — text fragments and tool calls interleaved
 * exactly as they happened (thought → tool → thought → tool …). Tool entries
 * reference `tools[]` by startedAt so status/artifact updates don't have to
 * touch the segment list.
 */
export type AdminChatStreamSegment =
  | { type: 'text'; text: string }
  | { type: 'tool'; startedAt: number }

export interface AdminChatStreamState {
  status: 'idle' | 'streaming' | 'done' | 'error'
  /** All tool calls observed this stream, oldest-first. */
  tools: AdminChatStreamTool[]
  /** Accumulated assistant text from text-delta events. */
  text: string
  /** Interleaved text/tool story of the turn, oldest-first. */
  segments: AdminChatStreamSegment[]
  /** Highest sequence we've consumed. Used to gate cleanup. */
  lastSequence: number
  /** Turn usage totals, delivered on the `done` event. */
  usage?: AdminChatUsage
  error?: string
}

const initialStreamState: AdminChatStreamState = {
  status: 'idle',
  tools: [],
  text: '',
  segments: [],
  lastSequence: 0,
}

type StreamAction =
  | { type: 'reset' }
  | { type: 'connecting' }
  | {
      type: 'event'
      sequence: number
      kind: 'tool_call_started'
      payload: { tool: string }
    }
  | {
      type: 'event'
      sequence: number
      kind: 'tool_call_completed'
      payload: { tool: string; ok: boolean; error?: string; artifact?: StreamArtifact }
    }
  | {
      type: 'event'
      sequence: number
      kind: 'assistant_text'
      payload: { delta: string }
    }
  | { type: 'event'; sequence: number; kind: 'done'; payload: { iterations?: number; usage?: AdminChatUsage } }
  | {
      type: 'event'
      sequence: number
      kind: 'error'
      payload: { message: string }
    }
  | { type: 'connection_error'; message: string }

function streamReducer(state: AdminChatStreamState, action: StreamAction): AdminChatStreamState {
  switch (action.type) {
    case 'reset':
      return initialStreamState
    case 'connecting':
      return { ...initialStreamState, status: 'streaming' }
    case 'event': {
      const lastSequence = Math.max(state.lastSequence, action.sequence)
      switch (action.kind) {
        case 'tool_call_started': {
          // De-dupe in case SSE replays past Last-Event-ID after a
          // reconnect — match on (toolName + startedAt sequence).
          if (state.tools.some((t) => t.startedAt === action.sequence)) {
            return { ...state, lastSequence }
          }
          return {
            ...state,
            status: 'streaming',
            lastSequence,
            tools: [
              ...state.tools,
              {
                startedAt: action.sequence,
                toolName: action.payload.tool,
                status: 'in_progress',
              },
            ],
            // A tool call closes the current text fragment — the next delta
            // starts a fresh one, preserving the interleaved story.
            segments: [...state.segments, { type: 'tool', startedAt: action.sequence }],
          }
        }
        case 'tool_call_completed': {
          // Match the OLDEST in-progress instance of this tool name (FIFO).
          // Chips start at COMPOSITION time, so with several same-name calls
          // the first composed is the first dispatched — matching the newest
          // (the old LIFO) flipped a later chip and left the first spinning
          // forever ("read a page showing as progressing when already done").
          const realIdx = state.tools.findIndex(
            (t) => t.toolName === action.payload.tool && t.status === 'in_progress',
          )
          if (realIdx === -1) {
            return { ...state, lastSequence }
          }
          const updated: AdminChatStreamTool = {
            ...state.tools[realIdx],
            status: action.payload.ok ? 'ok' : 'error',
            error: action.payload.error,
            artifact: action.payload.artifact,
          }
          return {
            ...state,
            lastSequence,
            tools: [...state.tools.slice(0, realIdx), updated, ...state.tools.slice(realIdx + 1)],
          }
        }
        case 'assistant_text': {
          const lastSeg = state.segments[state.segments.length - 1]
          const segments =
            lastSeg && lastSeg.type === 'text'
              ? [...state.segments.slice(0, -1), { type: 'text' as const, text: lastSeg.text + action.payload.delta }]
              : [...state.segments, { type: 'text' as const, text: action.payload.delta }]
          return {
            ...state,
            status: 'streaming',
            lastSequence,
            text: state.text + action.payload.delta,
            segments,
          }
        }
        case 'done':
          return { ...state, status: 'done', lastSequence, usage: action.payload?.usage ?? state.usage }
        case 'error':
          return {
            ...state,
            status: 'error',
            lastSequence,
            error: action.payload.message,
          }
      }
      return state
    }
    case 'connection_error':
      return { ...state, status: 'error', error: action.message }
  }
}

/**
 * Subscribe to the live admin-chat event stream for a conversation.
 *
 * Pass null for `params` (e.g. between turns) to keep the hook mounted
 * without an active connection. The hook automatically tears the
 * EventSource down + resets state when `params` changes or the
 * component unmounts.
 *
 * The hook does NOT trigger TanStack Query invalidations on `done` —
 * that's the consumer's responsibility (typically the message that
 * just streamed is also being persisted to admin_messages on the
 * background, and the conversation poll picks it up within 10s).
 */
export function useAdminChatStream(
  params: { conversationId: string; sinceSequence: number } | null,
  onPreviewCommand?: (cmd: Record<string, unknown>) => void,
): AdminChatStreamState {
  const [state, dispatch] = useReducer(streamReducer, initialStreamState)

  // Keep the latest preview handler in a ref so it never re-subscribes the
  // EventSource. preview_command events are the agent asking to SEE the site —
  // they drive the panel's iframe, not the transcript.
  const previewRef = useRef(onPreviewCommand)
  previewRef.current = onPreviewCommand

  useEffect(() => {
    if (!params) return
    let cancelled = false
    dispatch({ type: 'connecting' })

    let cfg: ReturnType<typeof getConfig>
    try {
      cfg = getConfig()
    } catch (e) {
      dispatch({
        type: 'connection_error',
        message: e instanceof Error ? e.message : 'config missing',
      })
      return
    }

    // apiUrl is the RPC endpoint (typically `https://.../api/rpc`).
    // The SSE route lives at the same ORIGIN under a different path
    // (`/api/admin-chat/stream/<id>`). Use the URL constructor with
    // apiUrl as the base — passing an absolute path replaces the
    // path entirely, leaving origin (and protocol / host) intact.
    let url: string
    try {
      const u = new URL(
        `/api/admin-chat/stream/${encodeURIComponent(params.conversationId)}`,
        cfg.apiUrl,
      )
      u.searchParams.set('since', String(params.sinceSequence))
      u.searchParams.set('apiKey', cfg.apiKey)
      url = u.toString()
    } catch (e) {
      dispatch({
        type: 'connection_error',
        message: e instanceof Error ? e.message : 'invalid apiUrl',
      })
      return
    }

    const es = new EventSource(url)

    type EventKind =
      | 'tool_call_started'
      | 'tool_call_completed'
      | 'assistant_text'
      | 'done'
      | 'error'

    const handle = (kind: EventKind) => (e: MessageEvent) => {
      if (cancelled) return
      const sequence = parseInt(e.lastEventId, 10) || 0
      let payload: Record<string, unknown> = {}
      try {
        if (e.data) payload = JSON.parse(e.data) as Record<string, unknown>
      } catch {
        // Malformed JSON — skip the event rather than throw.
        return
      }
      dispatch({
        type: 'event',
        sequence,
        kind,
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        payload: payload as any,
      } as StreamAction)
    }

    es.addEventListener('tool_call_started', handle('tool_call_started'))
    es.addEventListener('tool_call_completed', handle('tool_call_completed'))
    es.addEventListener('assistant_text', handle('assistant_text'))
    es.addEventListener('done', handle('done'))
    es.addEventListener('error', handle('error'))
    // preview_command is the agent asking to look at a page: the panel opens it
    // in the same-origin iframe, measures it, and posts the reading back. Never
    // part of the transcript, so it bypasses the reducer entirely.
    es.addEventListener('preview_command', (e: MessageEvent) => {
      if (cancelled || !previewRef.current) return
      try {
        if (e.data) previewRef.current(JSON.parse(e.data) as Record<string, unknown>)
      } catch {
        /* malformed — skip */
      }
    })

    // Native EventSource `error` fires on transport errors too (not
    // just our app-level error events). When readyState transitions
    // to CLOSED with no `done` received, surface it. EventSource
    // attempts auto-reconnect for transient issues automatically.
    es.onerror = () => {
      if (cancelled) return
      if (es.readyState === EventSource.CLOSED) {
        dispatch({ type: 'connection_error', message: 'stream connection lost' })
      }
    }

    return () => {
      cancelled = true
      es.close()
      dispatch({ type: 'reset' })
    }
  }, [params?.conversationId, params?.sinceSequence])

  return state
}
