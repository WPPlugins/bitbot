/**
 * Content Generator hooks — GeneratePost, GetDrafts, GetDraft,
 * ScheduleDraft. The Content tab uses these to drive the new-post
 * composer and the drafts list/detail.
 */
import { drawableDiscovery, latestDiscovery, type DrawableDiscovery } from '@/lib/topicBrief'
import { wpBodyFrom, wpCoreUrl, type WpBody } from '@/lib/wpCore'
import { useSyncExternalStore } from 'react'
import { useQuery, useMutation, useQueryClient, useInfiniteQuery } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'
import { getConfig } from '@/lib/config'
import { toast } from '@/components/ui/Toast'
import type { DraftSummary, DraftStatus } from '@/api/types'

const DEV_MOCK_KEY = 'bb_devmockapikey0000000000000000'

interface GeneratedPost {
  id: string
  title: string
  content: string
  slug: string
  seoMeta: {
    metaTitle: string
    metaDescription: string
    keywords: string[]
    focusKeyphrase: string
  }
  status?: DraftStatus
  scheduledAt?: string | null
  /** WP_Post ID once the draft has been pushed to WordPress. */
  wpPostId?: number | null
}

const days = (n: number) => new Date(Date.now() - n * 86_400_000).toISOString()
const hours = (n: number) => new Date(Date.now() - n * 3_600_000).toISOString()

const MOCK_DRAFTS: DraftSummary[] = [
  { id: 'd-1', title: '5 questions every wedding planner asks before quoting',
    status: 'draft' as DraftStatus, createdAt: hours(3),  scheduledAt: null },
  { id: 'd-2', title: 'Destination weddings in Trancoso: the 6-month checklist',
    status: 'scheduled' as DraftStatus, createdAt: days(1), scheduledAt: days(-2) },
  { id: 'd-3', title: 'How to budget for a 200-guest corporate event',
    status: 'published' as DraftStatus, createdAt: days(4), scheduledAt: days(3) },
  { id: 'd-4', title: 'Why Pinterest is still the best mood-board tool in 2026',
    status: 'draft' as DraftStatus, createdAt: days(7), scheduledAt: null },
  { id: 'd-5', title: 'Sample post — generation failed mid-way',
    status: 'failed' as DraftStatus, createdAt: days(10), scheduledAt: null },
]

const MOCK_GENERATED: GeneratedPost = {
  id: 'd-1',
  title: '5 questions every wedding planner asks before quoting',
  slug: '5-questions-wedding-planner-quoting',
  content: '## Why these five questions matter\n\nMost couples ask "how much" before sharing a single detail about their wedding. The smart planner flips the script. Here are the five questions that change a vague enquiry into a real quote — and a deposit.\n\n### 1. Guest count\n…',
  seoMeta: {
    metaTitle: '5 questions every wedding planner asks before quoting · Youami',
    metaDescription: 'A 2-minute framework for wedding planners to qualify enquiries faster and quote with confidence.',
    keywords: ['wedding planner', 'quote', 'qualification', 'discovery questions'],
    focusKeyphrase: 'wedding planner quote',
  },
}

const DRAFTS_PAGE_SIZE = 50

/**
 * Paginated drafts list with Load-more support. Was a single useQuery
 * with no page param — server defaults to pageSize=50 + ordered by
 * created_at desc, so older drafts silently disappeared once a site
 * generated 51+. Memory rule [[no-silent-list-truncation]].
 *
 * Exposes a hybrid surface so existing callers using `data` (array of
 * drafts) keep working, while new callers can read `total` /
 * `hasNextPage` / `fetchNextPage` to wire a Load-more affordance.
 */
/**
 * Knowledgebase "unseen drafts" — SINGLE source of truth shared by both the
 * parent tab badge (App.tsx) and the Drafts sub-tab badge (Content.tsx) so the
 * two can never drift. Counts drafts whose `createdAt` is newer than the
 * admin's last visit to the Drafts sub-tab. The seen-at timestamp lives in
 * localStorage; markDraftsSeen() advances it and notifies every consumer
 * (this tab via a custom event, other tabs via the storage event).
 *
 * Drafts is the only Knowledgebase sub-tab with an unseen badge: it's the only
 * thing created out-of-band (agent / background generation). Indexed pages,
 * FAQs, Find Topics and Generate-a-post are all admin-triggered, so there's
 * nothing async to flag there. If that changes, add a parallel counter and sum
 * them for the parent badge.
 */
export const DRAFTS_SEEN_KEY = 'bb_drafts_seen_at'
const DRAFTS_SEEN_EVENT = 'bb:drafts-seen'

function readDraftsSeenAt(): number {
  if (typeof window === 'undefined') return 0
  const existing = window.localStorage.getItem(DRAFTS_SEEN_KEY)
  if (existing) return Number(existing)
  // First time on this site — baseline now so pre-existing drafts don't flood
  // the badge; anything created after (e.g. agent-generated) counts.
  const now = Date.now()
  window.localStorage.setItem(DRAFTS_SEEN_KEY, String(now))
  return now
}

/** Mark all current drafts as seen — clears the badge everywhere at once. */
export function markDraftsSeen(): void {
  if (typeof window === 'undefined') return
  window.localStorage.setItem(DRAFTS_SEEN_KEY, String(Date.now()))
  window.dispatchEvent(new Event(DRAFTS_SEEN_EVENT))
}

function subscribeDraftsSeen(onChange: () => void): () => void {
  window.addEventListener(DRAFTS_SEEN_EVENT, onChange)
  window.addEventListener('storage', onChange) // keep cross-tab in sync
  return () => {
    window.removeEventListener(DRAFTS_SEEN_EVENT, onChange)
    window.removeEventListener('storage', onChange)
  }
}

export function useNewDraftsCount(): number {
  const { data: drafts = [] } = useDrafts()
  // Reactive read: re-renders every consumer the instant markDraftsSeen fires.
  const seenAt = useSyncExternalStore(subscribeDraftsSeen, readDraftsSeenAt, () => 0)
  return drafts.filter((d) => new Date(d.createdAt).getTime() > seenAt).length
}

export function useDrafts() {
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  const query = useInfiniteQuery({
    queryKey: ['drafts'],
    initialPageParam: 1,
    queryFn: async ({ pageParam }) => {
      if (isDev) return { drafts: MOCK_DRAFTS, total: MOCK_DRAFTS.length, page: pageParam, pageSize: MOCK_DRAFTS.length }
      const res = await rpc<{ drafts: DraftSummary[]; total: number; page: number; pageSize: number }>(
        'GetDrafts',
        { page: pageParam, pageSize: DRAFTS_PAGE_SIZE },
      )
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    getNextPageParam: (lastPage) => {
      const loaded = lastPage.page * lastPage.pageSize
      return loaded < lastPage.total ? lastPage.page + 1 : undefined
    },
    staleTime: 60_000,
  })

  const drafts: DraftSummary[] = (query.data?.pages ?? []).flatMap((p) => p.drafts)
  const total = query.data?.pages[0]?.total ?? drafts.length

  return {
    // `data` preserved as a flat array so old call sites (e.g. const
    // { data: drafts, isLoading } = useDrafts()) keep working.
    data: drafts,
    total,
    isLoading: query.isLoading,
    isFetchingNextPage: query.isFetchingNextPage,
    hasNextPage: !!query.hasNextPage,
    fetchNextPage: () => query.fetchNextPage(),
  }
}

export function useDraft(draftId: string | null) {
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useQuery({
    queryKey: ['draft', draftId],
    queryFn: async (): Promise<GeneratedPost> => {
      if (!draftId) throw new Error('No draft id')
      if (isDev) return { ...MOCK_GENERATED, id: draftId }
      const res = await rpc<GeneratedPost>('GetDraft', { draftId })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    enabled: !!draftId,
    staleTime: 30_000,
  })
}

// ── Body of a post that lives in WordPress ──────────────────────────
// Once a draft is sent to WordPress, WordPress owns its body (the backend keeps none), so the
// preview reads it from WordPress itself: always the current text, including the owner's edits.
export function useWpPostBody(wpPostId: number | null | undefined) {
  const cfg = getConfig()
  return useQuery({
    queryKey: ['wp-post-body', wpPostId],
    queryFn: async (): Promise<WpBody> => {
      try {
        const res = await fetch(wpCoreUrl(cfg.restUrl, `posts/${wpPostId}`, { context: 'edit', _fields: 'content.rendered,status' }), {
          headers: { 'X-WP-Nonce': cfg.nonce, Accept: 'application/json' },
          credentials: 'same-origin',
        })
        return wpBodyFrom(res.status, await res.json().catch(() => null))
      } catch {
        return { kind: 'error' }
      }
    },
    enabled: !!wpPostId,
    staleTime: 30_000,
  })
}

// ── Push a generated post into WordPress as a draft ────────────────
// Hits the existing wp_ajax_bitbot_publish_draft handler. After
// wp_insert_post succeeds the plugin round-trips the resulting
// wp_post_id back to the backend via LinkDraftToWp so the dashboard
// can render "Edit in WordPress ↗" as a direct deep link on
// subsequent loads. Publish/schedule lifecycle lives in WP itself —
// we don't duplicate that surface in Youami.

export interface PublishDraftInput {
  draftId: string
}

export interface PublishDraftResult {
  post_id: number
  status: 'draft'
  edit_url: string
  view_url: string
}

interface WPAjaxResponse<T> {
  success: boolean
  data: T | { error?: string }
}

export function usePublishDraftToWp() {
  const qc = useQueryClient()
  const { ajaxUrl, ajaxNonce } = getConfig()

  return useMutation({
    mutationFn: async (input: PublishDraftInput): Promise<PublishDraftResult> => {
      const body = new URLSearchParams({
        action: 'bitbot_publish_draft',
        nonce: ajaxNonce,
        draft_id: input.draftId,
      })

      const res = await fetch(ajaxUrl, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
      })
      const json: WPAjaxResponse<PublishDraftResult> = await res.json()
      if (!json.success) {
        const err = (json.data as { error?: string })?.error ?? 'Save failed'
        throw new Error(err)
      }
      return json.data as PublishDraftResult
    },
    onSuccess: (data, vars) => {
      // Optimistic cache patch so the button flips to a deep link
      // immediately without waiting for a refetch.
      qc.setQueryData(['draft', vars.draftId], (prev: any) => prev && {
        ...prev,
        wpPostId: data.post_id,
      })
      // Patch the list cache too so the row's status pill flips to
      // "In WordPress" immediately, not after the background refetch.
      qc.setQueryData(['drafts'], (prev: any) => {
        if (!prev || !Array.isArray(prev)) return prev
        return prev.map((d: any) =>
          d.id === vars.draftId ? { ...d, wpPostId: data.post_id } : d
        )
      })
      qc.invalidateQueries({ queryKey: ['drafts'] })
      toast.success('Saved to WordPress')
    },
    onError: (err: unknown) => {
      toast.error(err instanceof Error ? err.message : 'Save failed')
    },
  })
}

// ── Delete a draft ─────────────────────────────────────────────────
// Wraps the existing wp_ajax_bitbot_delete_draft handler (which calls
// DeleteDraft RPC). Same admin-ajax + nonce pattern as the publish hook.

export function useDeleteDraft() {
  const qc = useQueryClient()
  const { ajaxUrl, ajaxNonce } = getConfig()

  return useMutation({
    mutationFn: async (draftId: string): Promise<void> => {
      const body = new URLSearchParams({
        action: 'bitbot_delete_draft',
        nonce: ajaxNonce,
        draft_id: draftId,
      })
      const res = await fetch(ajaxUrl, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
      })
      const json: WPAjaxResponse<unknown> = await res.json()
      if (!json.success) {
        const err = (json.data as { error?: string })?.error ?? 'Delete failed'
        throw new Error(err)
      }
    },
    onSuccess: (_, draftId) => {
      qc.removeQueries({ queryKey: ['draft', draftId] })
      qc.invalidateQueries({ queryKey: ['drafts'] })
      toast.success('Draft deleted')
    },
    onError: (err: unknown) => {
      toast.error(err instanceof Error ? err.message : 'Delete failed')
    },
  })
}

interface GenerateInput {
  sourceType: 'prompt' | 'url' | 'upload'
  sourceValue: string
  fileName?: string
  instructions?: string
  // Present only for sourceType === 'upload'. The mutation runs the
  // three-step storage flow (GetUploadUrl → PUT bytes → GeneratePostFromStorage)
  // rather than the single GeneratePost call used by prompt/url.
  file?: File
}

// Backend accepts these extensions only (route.ts GetUploadUrl regex).
// DOCX is NOT supported despite older UI copy.
export const UPLOAD_ACCEPT = '.pdf,.png,.jpg,.jpeg,.gif,.webp'
const UPLOAD_MAX_BYTES = 10 * 1024 * 1024 // 10MB — matches the legacy admin guard

// ── Topic discovery ────────────────────────────────────────────────
// DiscoverTopics RPC hits the BAML pipeline (Reddit trends + niche
// inference) so it's slow + paid. We model it as a one-shot mutation
// rather than auto-fetching on mount.

export interface TopicSuggestion {
  topic: string
  reasoning: string
  searchQuery: string
  difficulty: string
  /** Writing instructions for THIS site (angle, reader, gap it closes). Missing on results saved before 2.2.58. */
  brief?: string
}

export interface RedditSource {
  title: string
  subreddit: string
  url: string
  numComments: number
}

export interface TopicDiscoveryResult {
  suggestions: TopicSuggestion[]
  contentGaps: string[]
  sources: RedditSource[]
  /** When the search finished (ISO). Set by the backend, which also keeps the result for the site. */
  at?: string
  /** false when the backend could not store this result: it will not be there after a reload. */
  saved?: boolean
}

const MOCK_DISCOVERY: TopicDiscoveryResult = {
  suggestions: [
    { topic: 'How to price a destination wedding without scaring couples off',
      reasoning: 'Couples Google price ranges before reaching out — owning this answer captures lead intent early.',
      searchQuery: 'destination wedding pricing breakdown',
      difficulty: 'medium' },
    { topic: 'The 6-week timeline most planners get wrong',
      reasoning: 'Existing posts talk about 6-month timelines but skip the 6-week sprint where vendors actually lock in.',
      searchQuery: 'wedding planner six week timeline',
      difficulty: 'easy' },
    { topic: 'Pinterest moodboard → real wedding: what survives the budget cut',
      reasoning: 'Direct continuation of the existing Pinterest post; answers the inevitable follow-up question.',
      searchQuery: 'pinterest wedding ideas budget reality',
      difficulty: 'easy' },
  ],
  contentGaps: [
    'No content on vendor contracts and cancellation clauses',
    'Pricing strategy posts skip taxes and travel costs',
    'No FAQ on rainy-day backup plans for outdoor venues',
  ],
  sources: [
    { title: 'How do you price a destination wedding in Trancoso?', subreddit: 'r/weddingplanning',
      url: 'https://reddit.com/r/weddingplanning/example1', numComments: 47 },
  ],
}

/** The site's latest Topic Discovery result, kept by the backend so it survives leaving the tab and reloading. */
export function useSavedTopicDiscovery() {
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY
  return useQuery({
    queryKey: ['topic-discovery'],
    queryFn: async (): Promise<TopicDiscoveryResult | null> => {
      if (isDev) return null
      const res = await rpc<{ discovery: TopicDiscoveryResult | null }>('GetTopicDiscovery')
      if (!res.success) throw new Error(res.error)
      return drawableDiscovery<TopicSuggestion>(res.data.discovery)
    },
    staleTime: 5 * 60_000,
  })
}

export function useDiscoverTopics() {
  const qc = useQueryClient()
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useMutation({
    mutationFn: async (): Promise<TopicDiscoveryResult> => {
      if (isDev) {
        await new Promise((r) => setTimeout(r, 1200))
        return MOCK_DISCOVERY
      }
      const res = await rpc<TopicDiscoveryResult>('DiscoverTopics')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: async (result) => {
      // ONE value on screen: the ['topic-discovery'] cache. A read still in flight is cancelled first so an
      // older answer cannot land on top of this newer result.
      await qc.cancelQueries({ queryKey: ['topic-discovery'] })
      // The later-finished search stays (lib/topicBrief latestDiscovery): a slow answer to an older search
      // cannot replace a newer result that is already on screen.
      qc.setQueryData<DrawableDiscovery<TopicSuggestion> | null>(['topic-discovery'], (current) =>
        latestDiscovery(current, drawableDiscovery<TopicSuggestion>(result)))
      // Server-side markTopicCreditUsed bumped usage.topic_credits, but
      // usePlanStatus has a 5-minute staleTime so the Plan card keeps
      // showing the pre-bump value until something invalidates the
      // cache. Without this, "Topic credits 0 / ∞" stays at 0 even
      // after a successful generation.
      qc.invalidateQueries({ queryKey: ['plan-status'] })
    },
    // Tier-gate errors are surfaced inline by the calling component
    // (with an upgrade CTA), so suppress the toast for those. Real
    // failures still toast.
    onError: (err) => {
      const msg = err instanceof Error ? err.message : 'Topic discovery failed'
      if (/Pro feature|Upgrade to/i.test(msg)) return
      toast.error(msg)
    },
  })
}

export function useGeneratePost() {
  const qc = useQueryClient()
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useMutation({
    mutationFn: async (input: GenerateInput): Promise<GeneratedPost> => {
      if (isDev) {
        await new Promise((r) => setTimeout(r, 2400))
        return MOCK_GENERATED
      }

      // Upload flow: a file is sent straight to Supabase Storage via a
      // presigned URL (bypasses the serverless body-size limit), then the
      // post is generated from that stored object. Mirrors the proven
      // legacy admin.js flow (GetUploadUrl → PUT → GeneratePostFromStorage).
      if (input.sourceType === 'upload') {
        const file = input.file
        if (!file) throw new Error('Choose a file to generate from.')
        if (file.size > UPLOAD_MAX_BYTES) {
          throw new Error('File is larger than 10MB. Pick a smaller file.')
        }

        const urlRes = await rpc<{ uploadUrl: string; storagePath: string }>(
          'GetUploadUrl', { fileName: file.name },
        )
        if (!urlRes.success) throw new Error(urlRes.error)

        const put = await fetch(urlRes.data.uploadUrl, {
          method: 'PUT',
          headers: { 'Content-Type': file.type || 'application/octet-stream' },
          body: file,
        })
        if (!put.ok) throw new Error('Could not upload the file. Try again.')

        const genRes = await rpc<GeneratedPost>('GeneratePostFromStorage', {
          storagePath: urlRes.data.storagePath,
          fileName: file.name,
          instructions: input.instructions,
        })
        if (!genRes.success) throw new Error(genRes.error)
        return genRes.data
      }

      const res = await rpc<GeneratedPost>('GeneratePost', { ...input })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => {
      toast.success('Draft generated')
      qc.invalidateQueries({ queryKey: ['drafts'] })
      // Same staleness as DiscoverTopics: backend bumped posts_generated,
      // but the Plan card needs an invalidate to re-fetch and show the
      // new "Posts generated N / M" count.
      qc.invalidateQueries({ queryKey: ['plan-status'] })
    },
    // Error toast is raised by the caller (Content.tsx) through
    // humanizeGenerationError so the message is friendly and the inline
    // card stays gone — a hook-level toast here would double up.
  })
}
