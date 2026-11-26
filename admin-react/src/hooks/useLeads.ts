/**
 * Lead-related TanStack Query hooks. One hook per RPC the Leads tab
 * needs, plus a useUnviewedLeadsCount that the BrandBar uses for the
 * "leads" tab badge.
 *
 * Dev-mock returns realistic sample leads when apiKey is the dev token,
 * so the Leads UI renders without a live RPC endpoint.
 */
import { useQuery, useMutation, useQueryClient, useInfiniteQuery, keepPreviousData } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'
import { getConfig } from '@/lib/config'
import { toast } from '@/components/ui/Toast'
import type {
  LeadInfo, LeadStage, LeadActivityInfo, LeadEmailInfo, ConversationMessage,
} from '@/api/types'

const DEV_MOCK_KEY = 'bb_devmockapikey0000000000000000'

const now = Date.now()
const minutes = (n: number) => new Date(now - n * 60_000).toISOString()
const hours = (n: number) => new Date(now - n * 60 * 60_000).toISOString()
const days  = (n: number) => new Date(now - n * 24 * 60 * 60_000).toISOString()

const DEV_MOCK_LEADS: LeadInfo[] = [
  { id: '1', name: 'Maria Silva', email: 'maria.silva@email.com', phone: '+55 21 9988-1234',
    message: 'Olá, gostaria de saber valores para evento corporativo no Rio em julho.',
    source: 'chat', formId: null, stage: 'new',
    researchSummary: null, suggestedAction: null,
    conversationId: 'c-1', ipAddress: '189.5.12.7', country: 'Brazil', city: 'Rio de Janeiro',
    createdAt: minutes(3), viewedAt: null, promotedFromFormAt: null, zohoLeadId: null, zohoSyncedAt: null, channel: 'web_widget' as const, channelProfile: null },
  { id: '2', name: 'Ricardo Fontes', email: 'ricardo.fontes@empresa.com.br', phone: '+55 11 9555-2233',
    message: 'Quero orçamento para evento corporativo. 200 convidados, julho.',
    source: 'Pedir Orçamento', formId: 'f1', stage: 'new',
    researchSummary: null, suggestedAction: null,
    conversationId: null, ipAddress: '177.33.4.10', country: 'Brazil', city: 'São Paulo',
    createdAt: minutes(38), viewedAt: null, promotedFromFormAt: null, zohoLeadId: null, zohoSyncedAt: null, channel: 'web_widget' as const, channelProfile: null },
  { id: '3', name: 'João Almeida', email: 'j.almeida@hotmail.com', phone: null,
    message: 'Queria saber se vocês cobrem São Paulo, e qual o pacote mínimo.',
    source: 'chat', formId: null, stage: 'contacted',
    researchSummary: 'Small wedding planner in SP, 80% Instagram referrals. Likely budget-conscious.',
    suggestedAction: 'Send the "starter package" PDF with the 4-hr option.',
    conversationId: 'c-3', ipAddress: '201.42.99.1', country: 'Brazil', city: 'São Paulo',
    createdAt: hours(2), viewedAt: hours(1.5), promotedFromFormAt: null, zohoLeadId: null, zohoSyncedAt: null, channel: 'web_widget' as const, channelProfile: null },
  { id: '4', name: 'Camila Rocha', email: 'camila@rochaeventos.com.br', phone: '+55 21 9876-1111',
    message: null, source: 'chat', formId: null, stage: 'qualified',
    researchSummary: 'Wedding planner with 14 years experience, owns 2 venues in RJ.',
    suggestedAction: 'Offer the partnership rate (15% off) for >5 referrals/yr.',
    conversationId: 'c-4', ipAddress: '189.0.5.4', country: 'Brazil', city: 'Rio de Janeiro',
    createdAt: days(1), viewedAt: hours(8), promotedFromFormAt: null, zohoLeadId: null, zohoSyncedAt: null, channel: 'web_widget' as const, channelProfile: null },
  { id: '5', name: 'Eduardo Lima', email: 'lima.edu@gmail.com', phone: null,
    message: 'Vocês fazem destination weddings?', source: 'chat',
    formId: null, stage: 'won',
    researchSummary: null, suggestedAction: null,
    conversationId: 'c-5', ipAddress: '177.12.3.9', country: 'Brazil', city: 'Porto Alegre',
    createdAt: days(3), viewedAt: days(2.5), promotedFromFormAt: null, zohoLeadId: null, zohoSyncedAt: null, channel: 'web_widget' as const, channelProfile: null },
  { id: '6', name: 'Bia Cardoso', email: 'bia@bcardoso.io', phone: null,
    message: 'Saber mais sobre vocês.', source: 'chat',
    formId: null, stage: 'lost',
    researchSummary: null, suggestedAction: null,
    conversationId: 'c-6', ipAddress: '187.5.5.5', country: 'Brazil', city: 'Belo Horizonte',
    createdAt: days(7), viewedAt: days(7), promotedFromFormAt: null, zohoLeadId: null, zohoSyncedAt: null, channel: 'web_widget' as const, channelProfile: null },
]

const DEV_MOCK_ACTIVITIES: Record<string, LeadActivityInfo[]> = {
  '3': [
    { id: 'a1', type: 'note', content: 'Replied with starter package PDF.', createdAt: hours(1.5) },
    { id: 'a2', type: 'stage_change', content: 'new → contacted', createdAt: hours(1.4) },
    { id: 'a3', type: 'research_complete', content: 'AI research finished — see panel.', createdAt: hours(1.2) },
  ],
  '4': [
    { id: 'a4', type: 'note', content: 'Connected on LinkedIn after research.', createdAt: hours(7) },
    { id: 'a5', type: 'stage_change', content: 'contacted → qualified', createdAt: hours(5) },
  ],
}

const DEV_MOCK_EMAILS: Record<string, LeadEmailInfo[]> = {
  '3': [{
    id: 'e1', subject: 'Your starter package · Youami Events', body: 'Olá João,\n\nObrigado pelo interesse...',
    status: 'sent', scheduledAt: null, sentAt: hours(1.5), createdAt: hours(1.5),
  }],
}

const DEV_MOCK_CONVERSATION: Record<string, ConversationMessage[]> = {
  '1': [
    { role: 'user', content: 'Olá, gostaria de saber valores para evento corporativo no Rio em julho.', createdAt: minutes(3) },
    { role: 'assistant', content: 'Olá Maria! Temos pacotes a partir de R$ 12.000 para eventos corporativos. Quantos convidados e qual a data?', createdAt: minutes(2) },
  ],
  '3': [
    { role: 'user', content: 'Queria saber se vocês cobrem São Paulo, e qual o pacote mínimo.', createdAt: hours(2) },
    { role: 'assistant', content: 'Olá João! Cobrimos sim, e nosso pacote inicial é R$ 4.800 para 4 horas. Posso te enviar o PDF detalhado?', createdAt: hours(2) },
    { role: 'user', content: 'Por favor, e-mail j.almeida@hotmail.com', createdAt: hours(1.95) },
  ],
}

interface UseLeadsParams {
  stage?: LeadStage | 'all'
  search?: string
  formId?: string | null
  /** Facet filters: country (Location) + via (derived origin). null = none. */
  country?: string | null
  via?: string | null
  /** false skips the fetch (callers that only read a shared cache entry). */
  enabled?: boolean
}

export interface LeadFacets {
  locations: Array<{ value: string; count: number }>
  /** Derived origin buckets: Chat / form name / Facebook / Instagram / etc.
   *  `value` is already a display label AND the filter token. */
  via: Array<{ value: string; count: number }>
}

const LEADS_PAGE_SIZE = 50

/**
 * Paginated leads with Load-more support. Returns a flattened `leads`
 * array spanning every page the admin has scrolled to, plus `total`
 * (server count across the FULL filtered set) and the pagination
 * actions. Both the Leads inbox AND each form's Responses tab consume
 * this; the formId filter applies server-side so pagination math is
 * correct after filtering.
 *
 * Was a single useQuery with page=1 hardcoded — older leads silently
 * disappeared once a site grew past 50. Memory rule
 * [[no-silent-list-truncation]] forbids that pattern.
 */
export function useLeads({ stage = 'all', search = '', formId = null, country = null, via = null, enabled = true }: UseLeadsParams = {}) {
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  const query = useInfiniteQuery({
    enabled,
    queryKey: ['leads', stage, search, formId, country, via],
    initialPageParam: 1,
    queryFn: async ({ pageParam }) => {
      if (isDev) {
        const filtered = DEV_MOCK_LEADS.filter((l) => {
          if (stage !== 'all' && l.stage !== stage) return false
          if (search && !`${l.name} ${l.email}`.toLowerCase().includes(search.toLowerCase())) return false
          if (formId && l.formId !== formId) return false
          if (country && l.country !== country) return false
          return true
        })
        return { leads: filtered, total: filtered.length, page: pageParam, pageSize: filtered.length }
      }
      const res = await rpc<{ leads: LeadInfo[]; total: number; page: number; pageSize: number }>(
        'GetLeads',
        {
          stage: stage === 'all' ? undefined : stage,
          search: search || undefined,
          formId: formId || undefined,
          country: country || undefined,
          via: via || undefined,
          page: pageParam,
          pageSize: LEADS_PAGE_SIZE,
        },
      )
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    getNextPageParam: (lastPage) => {
      const loaded = lastPage.page * lastPage.pageSize
      return loaded < lastPage.total ? lastPage.page + 1 : undefined
    },
    staleTime: 30 * 1000,
    // Keep the current rows on screen while a filter/search change refetches,
    // so the list updates in place instead of blanking to a skeleton (flash).
    placeholderData: keepPreviousData,
  })

  const leads: LeadInfo[] = (query.data?.pages ?? []).flatMap((p) => p.leads)
  const total = query.data?.pages[0]?.total ?? leads.length

  return {
    // `data` preserved for callers that destructured the old shape; new
    // callers prefer the flat surface below.
    data: query.data ? { leads, total, page: 1, pageSize: leads.length } : undefined,
    leads,
    total,
    isLoading: query.isLoading,
    isFetchingNextPage: query.isFetchingNextPage,
    hasNextPage: !!query.hasNextPage,
    fetchNextPage: () => query.fetchNextPage(),
  }
}

/**
 * Faceted filter options for the Leads toolbar — Location (country) + Via
 * (channel), each with a count. Counts reflect the OTHER active filters so
 * the dropdowns stay truthful as you narrow down. Same filter inputs as
 * useLeads (minus pagination).
 */
export function useLeadFacets({ stage = 'all', search = '', formId = null, country = null, via = null }: UseLeadsParams = {}) {
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useQuery({
    queryKey: ['lead-facets', stage, search, formId, country, via],
    queryFn: async (): Promise<LeadFacets> => {
      if (isDev) return { locations: [], via: [] }
      const res = await rpc<LeadFacets>('GetLeadFacets', {
        stage: stage === 'all' ? undefined : stage,
        search: search || undefined,
        formId: formId || undefined,
        country: country || undefined,
        via: via || undefined,
      })
      if (!res.success) return { locations: [], via: [] }
      return res.data
    },
    staleTime: 30 * 1000,
    // Keep prior counts visible while filters change — no dropdown flicker.
    placeholderData: keepPreviousData,
  })
}

export interface MenuBadgeCounts {
  leads: number
  forms: number
  conversations: number
  appointments: number
  total: number
}

/**
 * Single source for every nav-tab attention badge AND the signal the WP
 * menu bubble mirrors. One RPC, one poll — replaces the four separate
 * count hooks that each polled the backend independently every 30-60s.
 * Counts are server-side "unviewed" (stamp-on-open), so they match the
 * top-level menu badge exactly and survive across devices.
 */
export function useMenuBadgeCounts() {
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY
  const zero: MenuBadgeCounts = { leads: 0, forms: 0, conversations: 0, appointments: 0, total: 0 }

  return useQuery({
    queryKey: ['menu-badges'],
    queryFn: async (): Promise<MenuBadgeCounts> => {
      if (isDev) {
        const leads = DEV_MOCK_LEADS.filter((l) => l.viewedAt === null).length
        return { leads, forms: 0, conversations: 0, appointments: 0, total: leads }
      }
      const res = await rpc<MenuBadgeCounts>('GetMenuBadgeCounts')
      if (!res.success) return zero
      return res.data
    },
    refetchInterval: 30_000,
    refetchIntervalInBackground: false,
    staleTime: 15_000,
  })
}

export function useUnviewedLeadsCount() {
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useQuery({
    queryKey: ['unviewed-leads'],
    queryFn: async () => {
      if (isDev) return DEV_MOCK_LEADS.filter((l) => l.viewedAt === null).length
      const res = await rpc<{ count: number }>('GetUnviewedLeadsCount')
      if (!res.success) throw new Error(res.error)
      return res.data.count
    },
    refetchInterval: 60_000,
    staleTime: 30_000,
  })
}

/**
 * Count of live conversations currently in handover mode. Drives the
 * Conversations tab badge. Mirrors the same signal the WP admin menu
 * sums into its top-level Youami bubble.
 */
/**
 * Forms-tab attention badge — counts new form submissions since the admin
 * last visited the Forms tab. Same shape as the Drafts sub-tab badge:
 * localStorage stores `bb_forms_responses_seen_at` (ms epoch); the hook
 * passes it through to the server which returns a fresh count.
 *
 * Caller is responsible for stamping seen-at when the admin opens the
 * Forms tab + invalidating the query so the badge clears.
 */
export const FORMS_RESPONSES_SEEN_KEY = 'bb_forms_responses_seen_at'
export const CONVERSATIONS_SEEN_KEY = 'bb_conversations_seen_at'

export function useNewResponsesCount() {
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY
  const sinceMs = (() => {
    if (typeof window === 'undefined') return 0
    const v = window.localStorage.getItem(FORMS_RESPONSES_SEEN_KEY)
    return v ? Number(v) : 0
  })()

  return useQuery({
    queryKey: ['new-responses-count', sinceMs],
    queryFn: async () => {
      if (isDev) return 0
      const res = await rpc<{ count: number }>('GetNewResponsesCount', { sinceMs })
      if (!res.success) return 0
      return res.data.count
    },
    refetchInterval: 30_000,
    staleTime: 15_000,
  })
}

/**
 * Conversations-tab attention badge — counts conversations with a
 * `last_message_at` newer than the admin's last visit. Mirrors the Forms
 * tab pattern (localStorage seen-at marker, stamped by App.tsx when the
 * admin lands on tab=conversations). Replaces the old useLiveChatsCount
 * semantics, which counted "live_mode + idle window" — a less useful
 * signal than "did something happen since I last looked".
 */
export function useNewConversationsCount() {
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY
  const sinceMs = (() => {
    if (typeof window === 'undefined') return 0
    const v = window.localStorage.getItem(CONVERSATIONS_SEEN_KEY)
    return v ? Number(v) : 0
  })()

  return useQuery({
    queryKey: ['new-conversations-count', sinceMs],
    queryFn: async () => {
      if (isDev) return 0
      const res = await rpc<{ count: number }>('GetNewConversationsCount', { sinceMs })
      if (!res.success) return 0
      return res.data.count
    },
    refetchInterval: 30_000,
    staleTime: 15_000,
  })
}

export function useLiveChatsCount() {
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useQuery({
    queryKey: ['live-chats-count'],
    queryFn: async () => {
      if (isDev) return 0
      const res = await rpc<{ count: number }>('GetLiveBadgeCount')
      if (!res.success) return 0
      return res.data.count
    },
    refetchInterval: 30_000,
    staleTime: 15_000,
  })
}

export interface LeadDetail {
  lead: LeadInfo
  activities: LeadActivityInfo[]
  emails: LeadEmailInfo[]
  conversationMessages: ConversationMessage[]
  /** Free: identity and message only; chat, submission, research, emails and activities stay behind Assistant. */
  freeLocked?: boolean
}

export function useLead(leadId: string | null) {
  const qc = useQueryClient()
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useQuery({
    queryKey: ['lead', leadId],
    queryFn: async (): Promise<LeadDetail> => {
      if (!leadId) throw new Error('No lead id')
      if (isDev) {
        const lead = DEV_MOCK_LEADS.find((l) => l.id === leadId)
        if (!lead) throw new Error('Lead not found')
        return {
          lead,
          activities: DEV_MOCK_ACTIVITIES[leadId] ?? [],
          emails: DEV_MOCK_EMAILS[leadId] ?? [],
          conversationMessages: DEV_MOCK_CONVERSATION[leadId] ?? [],
        }
      }
      const res = await rpc<LeadDetail>('GetLead', { leadId })
      if (!res.success) throw new Error(res.error)
      // GetLead stamps viewed_at server-side on first open. Refresh the nav
      // badge (queryKey ['unviewed-leads']) so the count drops to match.
      if (res.data.lead.viewedAt === null) {
        qc.invalidateQueries({ queryKey: ['unviewed-leads'] })
      }
      return res.data
    },
    enabled: !!leadId,
    staleTime: 15 * 1000,
    // Keep the previously-open lead's detail on screen while the newly
    // selected one loads, so clicking between leads morphs in place instead
    // of flashing an empty/skeleton panel.
    placeholderData: keepPreviousData,
  })
}

/**
 * Promote a passive form response (no chat conversation behind it) into
 * the Leads inbox. Sets `promoted_from_form_at` server-side; the row then
 * passes the Leads-tab inbox filter and appears alongside chat leads.
 *
 * Stays visible in the parent form's Responses tab too (with a "In Leads"
 * tag instead of the button) so the audit trail is two-way.
 */
export function usePromoteToLead() {
  const qc = useQueryClient()
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useMutation({
    mutationFn: async (leadId: string) => {
      if (isDev) {
        const idx = DEV_MOCK_LEADS.findIndex((l) => l.id === leadId)
        if (idx >= 0) DEV_MOCK_LEADS[idx] = { ...DEV_MOCK_LEADS[idx], promotedFromFormAt: new Date().toISOString() }
        return { success: true }
      }
      const res = await rpc<{ success: boolean }>('PromoteFormResponseToLead', { leadId })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: (_data, leadId) => {
      toast.success('Added to Leads')
      // Optimistic patch so the Responses row's CTA flips to the "In Leads" tag
      // straight away. Invalidate also kicks a background refetch.
      qc.setQueriesData({ queryKey: ['leads'] }, (prev: any) => {
        if (!prev || !prev.leads) return prev
        return {
          ...prev,
          leads: prev.leads.map((l: any) =>
            l.id === leadId ? { ...l, promotedFromFormAt: new Date().toISOString() } : l
          ),
        }
      })
      qc.invalidateQueries({ queryKey: ['leads'] })
      qc.invalidateQueries({ queryKey: ['unviewed-leads-count'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Could not promote'),
  })
}

export function useUpdateLeadStage() {
  const qc = useQueryClient()
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useMutation({
    mutationFn: async ({ leadId, stage }: { leadId: string; stage: LeadStage }) => {
      if (isDev) {
        const idx = DEV_MOCK_LEADS.findIndex((l) => l.id === leadId)
        if (idx >= 0) DEV_MOCK_LEADS[idx] = { ...DEV_MOCK_LEADS[idx], stage }
        return { success: true }
      }
      const res = await rpc<{ success: boolean }>('UpdateLeadStage', { leadId, stage })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => {
      toast.success('Stage updated')
      qc.invalidateQueries({ queryKey: ['leads'] })
      qc.invalidateQueries({ queryKey: ['lead'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Update failed'),
  })
}

export function useRunLeadResearch() {
  const qc = useQueryClient()
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useMutation({
    mutationFn: async ({ leadId }: { leadId: string }) => {
      if (isDev) {
        await new Promise((r) => setTimeout(r, 1500))
        return {
          summary: 'Wedding planner in São Paulo, ~5 years experience. Active on Instagram (@plannerinha) with 8k followers.',
          suggestedAction: 'Send the partnership rate (15% off, paid yearly).',
          nextSteps: ['Send pricing PDF', 'Schedule a 20-min discovery call', 'Add to monthly newsletter'],
        }
      }
      const res = await rpc<{ summary: string; suggestedAction: string; nextSteps: string[] }>(
        'RunLeadResearch', { leadId },
      )
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: (_, { leadId }) => {
      toast.success('Research complete')
      qc.invalidateQueries({ queryKey: ['lead', leadId] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Research failed'),
  })
}

export function useDraftLeadEmail() {
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useMutation({
    mutationFn: async ({ leadId }: { leadId: string }) => {
      if (isDev) {
        await new Promise((r) => setTimeout(r, 800))
        return {
          subject: 'Following up on your enquiry · Youami Events',
          body: 'Hi there,\n\nThanks for reaching out about our events services. I noticed you...\n\nBest,\nThe Youami team',
        }
      }
      const res = await rpc<{ subject: string; body: string }>('DraftLeadEmail', { leadId })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => toast.success('Draft generated'),
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Draft failed'),
  })
}

/** Delete a single lead. */
export function useDeleteLead() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ leadId }: { leadId: string }) => {
      const res = await rpc<{ success: boolean }>('DeleteLead', { leadId })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => {
      toast.success('Lead deleted')
      qc.invalidateQueries({ queryKey: ['leads'] })
      qc.invalidateQueries({ queryKey: ['lead'] })
      // Deleting an unviewed lead drops the count behind the nav badge.
      qc.invalidateQueries({ queryKey: ['unviewed-leads'] })
      qc.invalidateQueries({ queryKey: ['menu-badges'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Delete failed'),
  })
}

/** Bulk delete leads. Returns the count actually removed. */
export function useDeleteLeads() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ leadIds }: { leadIds: string[] }) => {
      const res = await rpc<{ leadsDeleted: number }>('DeleteLeads', { leadIds })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: (data) => {
      toast.success(
        `Deleted ${data.leadsDeleted} ${data.leadsDeleted === 1 ? 'lead' : 'leads'}`,
      )
      qc.invalidateQueries({ queryKey: ['leads'] })
      qc.invalidateQueries({ queryKey: ['lead'] })
      qc.invalidateQueries({ queryKey: ['unviewed-leads'] })
      qc.invalidateQueries({ queryKey: ['menu-badges'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Delete failed'),
  })
}

// Bulk mark-read: stamps viewed_at on the selected leads. Invalidates the
// list (the unread highlight clears) and the nav badge (count drops).
export function useMarkLeadsRead() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ leadIds }: { leadIds: string[] }) => {
      const res = await rpc<{ marked: number }>('MarkLeadsRead', { leadIds })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: (data) => {
      toast.success(`Marked ${data.marked} ${data.marked === 1 ? 'lead' : 'leads'} read`)
      qc.invalidateQueries({ queryKey: ['leads'] })
      qc.invalidateQueries({ queryKey: ['unviewed-leads'] })
      qc.invalidateQueries({ queryKey: ['menu-badges'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Could not mark read'),
  })
}
