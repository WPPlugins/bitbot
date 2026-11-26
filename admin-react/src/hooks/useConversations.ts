/**
 * Conversation hooks — wire to the real backend handlers:
 *
 *   GetLiveChats          → list of live + recent threads
 *   GetLiveChatMessages   → full transcript for one thread
 *   GetLiveBadgeCount     → menu badge count (live now)
 *   AdminTakeOverChat     → flip a thread into live_mode, admin replies
 *   AdminReplyLive        → post a message into a live thread
 *   CloseLiveThread       → hand the thread back to the bot
 *
 * No mock branch — production wiring only. Tabs with no traffic show
 * the empty state via EmptyState (fresh sites legitimately have []).
 */
import { useEffect, useRef } from 'react'
import { useQuery, useMutation, useQueryClient, useInfiniteQuery, keepPreviousData } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'
import { toast } from '@/components/ui/Toast'
import type { ConversationSummary, ConversationMessage } from '@/api/types'

interface ConversationStateCounts {
  all: number
  live: number
  needs: number
  past: number
}

interface GetConversationsResponse {
  conversations: ConversationSummary[]
  total: number
  page: number
  pageSize: number
  /** Filter-chip totals for the WHOLE 30-day window, NOT just the
   *  loaded subset. Read off the first page so chips stay stable as
   *  Load more brings in extra rows. */
  stateCounts: ConversationStateCounts
}

interface GetLiveChatMessagesResponse {
  messages: ConversationMessage[]
}

const CONVERSATIONS_PAGE_SIZE = 50

/**
 * Conversations list with real paging. Returns flattened `items` for the
 * UI, plus `total` (server count across all pages), `hasNextPage`, and a
 * `fetchNextPage()` action the "Load more" button at the bottom of the
 * list invokes. Used to be a single useQuery capped at 50 — sites that
 * outgrew 50 threads silently lost the rest.
 *
 * Polls every 10s so new visitor messages + presence transitions show up
 * without a manual refresh; only the FIRST page is polled (additional
 * pages are archival and rarely change retroactively).
 */
export function useConversations(channel: string | null = null) {
  const query = useInfiniteQuery({
    queryKey: ['conversations', channel],
    initialPageParam: 1,
    queryFn: async ({ pageParam }) => {
      const res = await rpc<GetConversationsResponse>('GetConversations', {
        page: pageParam,
        pageSize: CONVERSATIONS_PAGE_SIZE,
        channel: channel || undefined,
      })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    getNextPageParam: (lastPage) => {
      const loaded = lastPage.page * lastPage.pageSize
      return loaded < lastPage.total ? lastPage.page + 1 : undefined
    },
    refetchInterval: 10_000,
    staleTime: 5_000,
    // Keep current rows while switching channel filter — no skeleton flash.
    placeholderData: keepPreviousData,
  })

  const items: ConversationSummary[] = (query.data?.pages ?? []).flatMap((p) => p.conversations)
  const total = query.data?.pages[0]?.total ?? items.length
  // Pull chip counts off the first page only. The server computes them
  // for the full 30-day window so they stay stable whether the admin
  // has loaded 50 rows or 500. Fallback to zeros while loading.
  const stateCounts: ConversationStateCounts =
    query.data?.pages[0]?.stateCounts ?? { all: 0, live: 0, needs: 0, past: 0 }

  return {
    data: items,
    total,
    stateCounts,
    isLoading: query.isLoading,
    isFetchingNextPage: query.isFetchingNextPage,
    hasNextPage: !!query.hasNextPage,
    fetchNextPage: () => query.fetchNextPage(),
  }
}

export interface ConversationFacets {
  channels: Array<{ value: string; count: number }>
}

/** Channel filter options + counts for the Conversations toolbar (30-day
 *  window). Mirrors the Leads facet pattern. */
export function useConversationFacets() {
  return useQuery({
    queryKey: ['conversation-facets'],
    queryFn: async (): Promise<ConversationFacets> => {
      const res = await rpc<ConversationFacets>('GetConversationFacets')
      if (!res.success) return { channels: [] }
      return res.data
    },
    staleTime: 30_000,
    placeholderData: keepPreviousData,
  })
}

/**
 * `entitled` is the plan gate: the transcript RPC is Assistant-only, so the
 * caller passes false until the plan is known and paid. Nothing is fetched
 * (or polled) before that, and a Free site never sees the RPC error.
 */
export function useConversationThread(id: string | null, entitled = true) {
  const qc = useQueryClient()
  const query = useQuery({
    queryKey: ['conversation', id],
    queryFn: async (): Promise<ConversationMessage[]> => {
      if (!id) return []
      const res = await rpc<GetLiveChatMessagesResponse>('GetLiveChatMessages', { conversationId: id })
      if (!res.success) throw new Error(res.error)
      return res.data.messages
    },
    enabled: !!id && entitled,
    // Faster polling on the open thread so admin sees visitor replies
    // within 5s while they're typing back.
    refetchInterval: 5_000,
    staleTime: 2_000,
  })

  // Opening a thread stamps viewed_at server-side (inside GetLiveChatMessages).
  // Invalidate the shared badge counts AFTER that call SUCCEEDS — invalidating
  // on `id` change alone raced ahead of the stamp, so the badge read the stale
  // count and only cleared on the next poll. Once per opened thread. (Mirrors
  // how Leads refreshes its badge after GetLead returns the stamped row.)
  const stampedFor = useRef<string | null>(null)
  useEffect(() => {
    if (id && query.isSuccess && stampedFor.current !== id) {
      stampedFor.current = id
      qc.invalidateQueries({ queryKey: ['menu-badges'] })
    }
  }, [id, query.isSuccess, qc])

  return query
}

export function useLiveConversationsCount() {
  return useQuery({
    queryKey: ['live-conversations-count'],
    queryFn: async (): Promise<number> => {
      const res = await rpc<{ count: number }>('GetLiveBadgeCount')
      if (!res.success) throw new Error(res.error)
      return res.data.count
    },
    refetchInterval: 30_000,
    staleTime: 10_000,
  })
}

// ── Mutations ──────────────────────────────────────────────────────────

export function useTakeOverConversation() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ conversationId, opener }: { conversationId: string; opener?: string }) => {
      const res = await rpc<{ ok: boolean }>('AdminTakeOverChat', {
        conversationId,
        ...(opener ? { opener } : {}),
      })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: (_data, { conversationId }) => {
      toast.success('You\'re live with the visitor')
      qc.invalidateQueries({ queryKey: ['conversations'] })
      qc.invalidateQueries({ queryKey: ['conversation', conversationId] })
      qc.invalidateQueries({ queryKey: ['live-conversations-count'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Take-over failed'),
  })
}

export function useReplyLive() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ conversationId, message }: { conversationId: string; message: string }) => {
      const res = await rpc<{ ok: boolean; sentAt: string }>('AdminReplyLive', {
        conversationId,
        message,
      })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: (_data, { conversationId }) => {
      qc.invalidateQueries({ queryKey: ['conversation', conversationId] })
      qc.invalidateQueries({ queryKey: ['conversations'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Send failed'),
  })
}

export function useCloseLiveThread() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ conversationId }: { conversationId: string }) => {
      const res = await rpc<{ ok: boolean }>('CloseLiveThread', { conversationId })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: (_data, { conversationId }) => {
      toast.success('Handed back to the bot')
      qc.invalidateQueries({ queryKey: ['conversations'] })
      qc.invalidateQueries({ queryKey: ['conversation', conversationId] })
      qc.invalidateQueries({ queryKey: ['live-conversations-count'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Close failed'),
  })
}

/** Returns which selected conversations have linked leads. Used as the
 *  pre-flight before a bulk delete so we can offer "also delete leads?"
 *  without surprising the admin. */
export function useCheckConversationLeads() {
  return useMutation({
    mutationFn: async ({ conversationIds }: { conversationIds: string[] }) => {
      const res = await rpc<{ linked: Array<{ conversationId: string; leadIds: string[] }> }>(
        'CheckConversationLeads',
        { conversationIds },
      )
      if (!res.success) throw new Error(res.error)
      return res.data
    },
  })
}

/** Bulk delete. deleteLeads=false detaches leads (sets conversation_id
 *  to null) so they stay in the inbox without a back-link.
 *  deleteLeads=true also wipes the lead rows. */
export function useDeleteConversations() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({
      conversationIds,
      deleteLeads,
    }: {
      conversationIds: string[]
      deleteLeads: boolean
    }) => {
      const res = await rpc<{
        conversationsDeleted: number
        messagesDeleted: number
        leadsDeleted: number
      }>('DeleteConversations', { conversationIds, deleteLeads })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: (data) => {
      const bits: string[] = []
      bits.push(
        `Deleted ${data.conversationsDeleted} ${data.conversationsDeleted === 1 ? 'thread' : 'threads'}`,
      )
      if (data.leadsDeleted > 0) {
        bits.push(`${data.leadsDeleted} ${data.leadsDeleted === 1 ? 'lead' : 'leads'}`)
      }
      toast.success(bits.join(' · '))
      qc.invalidateQueries({ queryKey: ['conversations'] })
      qc.invalidateQueries({ queryKey: ['leads'] })
      qc.invalidateQueries({ queryKey: ['live-conversations-count'] })
      // Refresh the nav badges — deleting threads/leads changes the
      // unviewed counts the Conversations + Leads tab badges read.
      qc.invalidateQueries({ queryKey: ['new-conversations-count'] })
      qc.invalidateQueries({ queryKey: ['unviewed-leads'] })
      qc.invalidateQueries({ queryKey: ['menu-badges'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Delete failed'),
  })
}

// Bulk mark-read: stamps viewed_at + clears unread_admin_count on the selected
// threads, then refreshes the list so the unread state clears.
export function useMarkConversationsRead() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ conversationIds }: { conversationIds: string[] }) => {
      const res = await rpc<{ marked: number }>('MarkConversationsRead', { conversationIds })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: (data) => {
      toast.success(`Marked ${data.marked} ${data.marked === 1 ? 'thread' : 'threads'} read`)
      qc.invalidateQueries({ queryKey: ['conversations'] })
      qc.invalidateQueries({ queryKey: ['live-conversations-count'] })
      qc.invalidateQueries({ queryKey: ['menu-badges'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Could not mark read'),
  })
}
