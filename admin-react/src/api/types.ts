/**
 * Domain types for the Youami admin SPA. Mirrors what
 * packages/api/src/rpc/schema.ts declares plus a few locally-typed
 * shapes for RPCs that work on the backend but aren't formally
 * declared in schema.ts yet (Conversations).
 */

import type { Plan } from '@/lib/config'

export type LeadStage = 'new' | 'contacted' | 'qualified' | 'won' | 'lost'
export type SyncStatusKind = 'idle' | 'syncing' | 'completed' | 'failed'
export type DraftStatus = 'draft' | 'scheduled' | 'published' | 'failed'
export type SubscriptionStatus = 'trialing' | 'active' | 'cancelled' | 'expired'

/**
 * Freemium signal post-migration 049. The UI branches on this:
 *   free    — forever-free, capped via FREE_LIMITS (50 chat/mo, 3 topic
 *             lifetime, 0 posts, 1 form, leads count-only)
 *   active  — paid (Pro or Pro+) or cancelled-paid still within billing
 *             period
 *   expired — explicit ToS / admin revocation; rare. Normal cancellation
 *             drops to plan='free', NOT this.
 */
export type AccessMode = 'free' | 'active' | 'expired'

/** Response shape of GetPlanStatus RPC. */
export interface PlanStatus {
  plan: Plan
  accessMode: AccessMode
  trialEndsAt: string | null
  daysRemaining: number
  /** Sources a Free site may generate a post from (backend lib/postLimit). Missing on older backends = none. */
  freePostSources?: string[]
  /** The day this month's Free counts reset, e.g. "1 December" (backend lib/creditClaim). Missing on older backends. */
  monthResetsOn?: string
  limits: {
    chatMessagesPerMonth: number
    topicCreditsPerMonth: number
    postsPerMonth: number
    postCooldownHours: number
    maxLeads: number
    maxForms: number
    /** Free page limit. 0 or missing = no limit (paid, or an older backend). */
    maxPages?: number
  }
  usage: {
    chatMessagesThisMonth: number
    topicCreditsThisMonth: number
    postsThisMonth: number
    leadsCount: number
    formsCount: number
    lastPostGeneratedAt: string | null
    /** Pages currently On (counted against maxPages). Missing on older backends. */
    pagesOn?: number
  }
  /** Pro+ monthly agent-usage allowance. Null on other plans (and on older
   *  backends that do not send it yet). The bars are ratios; the lines beside
   *  them say the dollars (usageLines.ts). */
  agentCredit?: {
    includedUsd: number
    usedUsd: number
    leftUsd: number
    periodStart: string
    periodEnd: string
    daily?: {
      spentUsd: number
      capUsd: number
      /** Retail burn per UTC day, last 30 days oldest-first, zero days
       *  included (same USD units as spentUsd — the UI renders ratios). */
      history?: Array<{ date: string; spentUsd: number; voiceMinutes?: number; voiceUsd?: number }>
    }
    /** Operator-configured purchasable amounts, for the top-up gate. */
    topupOptionsUsd?: number[]
    /** The panel's money in dollars, and the reset date the owner reads
     *  (the subscription renewal when it matches the window). Missing from
     *  backends older than plugin 2.1.342. */
    money?: {
      pool: number
      used: number
      left: number
      included: number
      purchased: number
      dailyShare: number
      last24h: number
      resetAt: string
      /** The same pool in whole credits: what the owner reads. Missing from
       *  backends older than plugin 2.1.343. */
      credits?: AgentCreditCredits
    }
    /** Credits per retail dollar, for the history and the top-up options.
     *  The one place the SPA learns the rate. */
    creditsPerUsd?: number
    /** Voice rate, present even before the first call. */
    creditsPerMinute?: number
    /** The owner's automatic top-up setting and how it stands this month.
     *  Missing from backends older than plugin 2.1.343. */
    autoTopup?: AutoTopupStatus
    /** Voice calls in this window, when there were any (plugin 2.1.346). */
    voice?: { minutes: number; credits: number; creditsPerMinute: number; pendingCredits?: number }
  } | null
}

/** Response shape of GetSubscription RPC. */
export interface SubscriptionInfo {
  tier: 'basic' | 'premium'
  trialEndsAt: string | null
  currentPeriodEnd: string | null
  isActive: boolean
  subscriptionStatus: SubscriptionStatus
  daysRemaining: number | null
}

/** Response shape of GetSyncStatus RPC. */
/** Background sitemap-fill progress (server-side resumable crawl). */
export interface SitemapFillProgress {
  active: boolean
  done: number
  total: number
}

export interface SyncStatus {
  status: SyncStatusKind
  pagesCount: number
  lastSync: string | null
  sitemapFill?: SitemapFillProgress | null
  /** True once the site's first sync finished. Absent on older backends: use lastSync. */
  firstSyncDone?: boolean
}

/** Page state under a page limit. Missing on older backends: derive from `enabled`. */
/** 'reading' = holds a slot but its content is not written yet (counts as On). */
export type PageState = 'on' | 'off' | 'left_out' | 'reading'
/** Server-side filter for GetPages. */
export type PageStateFilter = 'on' | 'off' | 'left_out' | 'all'

export interface PageInfo {
  id: string
  url: string
  title: string | null
  summary: string | null
  enabled: boolean
  lastCrawledAt: string | null
  /** Approx words from the summary (caps ~500). Flags thin, low-value pages. */
  wordCount?: number
  /** Provenance: wp_query/wp (your website), sitemap_fallback (sitemap), manual (added by you). */
  source?: string | null
  state?: PageState
}

/** IndexUrlList request. replacePageId (swap in) is valid only with exactly one plain page URL. */
export interface IndexUrlRequest {
  urls: string[]
  replacePageId?: string
}

export interface IndexUrlResult {
  fetched: number
  embedded: number
  skippedUnchanged: number
  failed: number
  errors: string[]
  sitemapQueued: number
  leftOut?: number
}

/** Per-bucket counts for the indexed-pages source filter and state filter. */
export interface PageSourceCounts {
  all: number
  auto: number
  manual: number
  on?: number
  off?: number
  leftOut?: number
  /** Rows holding a slot with no content yet. Included in `on`. */
  reading?: number
}

export interface LeadInfo {
  /** One row per form submission; a repeat email shares `id` (the lead) across rows. */
  submissionId?: string
  id: string
  name: string
  email: string
  phone: string | null
  message: string | null
  source: string
  formId: string | null
  stage: LeadStage
  researchSummary: string | null
  suggestedAction: string | null
  conversationId: string | null
  ipAddress: string | null
  country: string | null
  city: string | null
  createdAt: string
  viewedAt: string | null
  /** Set when an admin explicitly clicks "Add to Leads" on a passive
   *  form response. Null for chat-driven leads and un-promoted form
   *  responses. Drives the Responses-row CTA state ("Add to Leads" vs
   *  "Added to Leads"). */
  promotedFromFormAt: string | null
  /** Zoho CRM lead id this Youami lead was pushed to. NULL = never synced.
   *  Drives the "Push to Zoho" / "✓ In Zoho" UI state on lead rows. Stage
   *  / owner / last activity are NOT cached — fetched live from Zoho
   *  whenever the detail pane needs them. */
  zohoLeadId: string | null
  zohoSyncedAt: string | null
  pipedrivePersonId?: string | null
  pipedriveSyncedAt?: string | null
  /** Transport surface the lead was captured on. Drives the channel
   *  icon on lead rows + the "Captured from …" badge on LeadDetail. */
  channel: LeadChannel
  /** Per-channel profile blob (Messenger name + locale + timezone,
   *  Instagram username, etc.). Schema varies — read defensively. */
  channelProfile: Record<string, unknown> | null
  /** Answers to the form's custom fields, keyed by FormFieldSchema.name.
   *  Empty {} for chat leads / standard-only forms. Drives the per-field
   *  columns in the Responses table. Optional only so dev-mock fixtures can
   *  omit it; the backend always returns it. */
  customFields?: Record<string, string>
}

export type LeadChannel =
  | 'web_widget'
  | 'messenger'
  | 'instagram_dm'
  | 'whatsapp'
  | 'telegram'
  | 'sms'
  | 'discord'
  | 'form'
  | 'email'

/**
 * Live snapshot of a lead's Zoho counterpart, fetched on-demand by the
 * LeadDetail "Zoho state" card and the agent's getZohoLeadState tool.
 * Mirrors ZohoLeadState in packages/api/src/services/Zoho.ts.
 */
export interface ZohoLeadState {
  id: string
  fullName: string | null
  email: string | null
  stage: string | null
  owner: { id: string; name: string | null; email: string | null } | null
  leadSource: string | null
  modifiedAt: string | null
  createdAt: string | null
  zohoUrl: string
}

export interface PipedriveLeadState {
  personId: number
  stage: string | null
  dealTitle: string | null
  dealStatus: string | null
  modifiedAt: string | null
  pipedriveUrl: string
}

export interface LeadActivityInfo {
  id: string
  type: string  // 'note' | 'stage_change' | 'email_sent' | 'research_complete' | etc.
  content: string | null
  createdAt: string
}

export interface FormFieldSchema {
  name: string
  label: string
  type: 'text' | 'email' | 'phone' | 'textarea' | 'select' | 'radio' | 'checkbox'
  required: boolean
  options?: string[]
  placeholder?: string
}

export interface FormInfo {
  /** Post-submit confirmation the visitor reads (per-form copy). */
  successMessage?: string
  id: string
  name: string
  description: string
  fields: FormFieldSchema[]
  enabled: boolean
  isDefault: boolean
  chatGateEnabled: boolean
  /** Lead rows whose `form_id` equals this id. Drives the "N responses"
   *  badge on each Forms-tab card. Click-through deep-links to the
   *  Leads tab filtered by ?formId=. */
  submissionsCount: number
  /** ISO timestamp of the most recent submission to this form, or null.
   *  Used to drive the per-form "new entries" indicator client-side
   *  (compared against per-form localStorage seen-at marker). */
  lastSubmissionAt: string | null
  /** Passive responses to this form the admin hasn't opened yet (server-side
   *  viewed_at count). Drives the per-form red dot + Responses sub-tab badge,
   *  matching the top-level menu badge. Clears when the Responses tab opens.
   *  Optional only so dev mocks can omit it; the backend always returns it. */
  unviewedCount?: number
  createdAt: string
}

export interface LeadEmailInfo {
  id: string
  subject: string
  body: string
  status: 'draft' | 'sent' | 'scheduled'
  scheduledAt: string | null
  sentAt: string | null
  createdAt: string
}

export interface DraftSummary {
  id: string
  title: string
  status: DraftStatus
  createdAt: string
  scheduledAt: string | null
  /** WP_Post ID once the draft has been pushed to WordPress via
   *  wp_ajax_bitbot_publish_draft. Null until first push. */
  wpPostId?: number | null
}

export interface AppointmentInfo {
  id: string
  leadId: string | null
  startsAt: string
  endsAt: string
  serviceName: string
  topic: string
  visitorEmail: string
  visitorName: string | null
  status: string
  timezone: string
  createdAt: string
  viewedAt: string | null
}

/**
 * Live + recent conversation row returned by GetLiveChats. Shape
 * mirrors the route.ts handler — locally typed because schema.ts
 * doesn't have a formal Rpc.make entry yet (BLUEPRINT Open Question 1).
 */
export interface ConversationSummary {
  /** Free: true on every thread but the one with the latest activity, whose transcript Free may read. Absent on paid plans. */
  transcriptLocked?: boolean
  id: string
  sessionId: string
  /** Glanceable name: lead name → email → "Visitor from City, Country" → "Visitor #abc1234". */
  displayLabel: string
  visitorIp: string | null
  visitorId: string | null
  visitorEmail: string | null
  visitorName: string | null
  visitorCity: string | null
  visitorCountry: string | null
  /** ISO 3166-1 alpha-2 for the flag; null on threads older than migration 184. */
  visitorCountryCode?: string | null
  liveMode: boolean
  liveStartedAt: string | null
  liveEndedAt: string | null
  unreadAdminCount: number
  lastMessageAt: string
  visitorLastSeenAt: string | null
  visitorLeftAt: string | null
  /** 'needs_human' = currently live · 'chatting' = bot is handling */
  status: 'needs_human' | 'chatting'
  /** 'present' = recent heartbeat · 'idle' = idle window · 'left' = gone.
   *  Messenger / IG conversations never resolve to 'left' server-side. */
  presence: 'present' | 'idle' | 'left'
  /** Originating channel: web widget, Messenger DM, or Instagram DM.
   *  Derived from session_id prefix server-side. Drives the channel
   *  badge in the list / thread header and suppresses the 'Ended' state
   *  for meta channels (no tab-close signal exists). */
  channel: 'web_widget' | 'messenger' | 'instagram_dm' | 'email'
  /** Stamp-on-open: null = not yet opened by an admin → shows the "new" dot. */
  viewedAt: string | null
  /** The thread needs the owner: unopened, or a live handover waiting on a
   *  reply. The server's Needs reply chip count and the menu and tab badges
   *  count exactly these rows. Absent from an API older than 2.1.344. */
  needsOwner?: boolean
}

export interface ConversationMessage {
  id?: string
  role: 'user' | 'assistant' | 'admin'
  content: string
  createdAt: string
}

/**
 * Read from GET /wp-json/bitbot/v1/admin-settings (REST endpoint to be
 * built per BLUEPRINT.md Step 3). Mirrors every WP option key registered
 * for Feature 8 customisation.
 */
export interface AdminSettings {
  enabled: boolean
  widgetTitle: string
  widgetPosition: 'bottom-right' | 'bottom-left'
  widgetOffsetX: number
  widgetOffsetY: number
  primaryColor: string
  welcomeMessage: string
  /** Per-language copy: { code: { title, welcome, questions, placeholder, auto, needsReview, failed } }. auto = machine translation not yet edited by the owner. */
  widgetCopy?: Record<string, { title: string; welcome: string; questions?: string[]; placeholder?: string; auto: boolean; needsReview?: boolean; failed?: boolean }>
  customInstructions: string
  placeholderText: string
  fallbackMessage: string
  leadCapturePrompt: string
  typingIndicatorText: string
  hideOnPages: string
  mobileBehaviour: 'inherit' | 'bottom-right' | 'bottom-left' | 'hide'
  cornerRadius: number
  /** Pill button height in px, 28 to 56. Only read while widgetShape is 'pill'. */
  pillHeight: number
  widgetShape: 'rounded' | 'circle' | 'square' | 'pill' | 'hexagon'
  suggestedQuestions: Array<{ label: string; message: string }>
  avatarUrl: string
  /** 2.0.2: JSON-encoded crop transform `{ xPercent, yPercent, zoom }`.
   *  xPercent / yPercent are relative offsets of the img inside its
   *  container (so they translate cleanly across the 56-px real FAB,
   *  the 36-px preview FAB, and the 24-px header avatar). zoom is the
   *  raw scale factor. Empty string → no transform (centered, 1x). */
  avatarTransform: string
  nudge: string // JSON-encoded config; component decodes
  autoOpen: string
  pinnedMessage: string
  offTopicGuardrails: string
  notificationEmail: string
  /** Voice calls switch (default off); the Call button shows in the widget when on. */
  voiceCallsEnabled?: boolean
  /** WhatsApp click-to-chat: owner's number (E.164, optional) and the switch. */
  whatsappNumber?: string
  whatsappEnabled?: boolean
  /** What the widget shows while the owner's own field is empty, in the site's language. Read-only. */
  widgetDefaults?: { title: string; welcome: string; placeholder: string }
  /** The pre-chat form's fixed text per offered language code; the first key is the site language. Read-only. */
  gateCopy?: Record<string, { heading: string; pendingLabel: string; submit: string; back: string }>
  /** Enter sends the visitor's message. Default off: the visitor clicks Send. */
  enterToSend?: boolean
  /** Show the public "Powered by" link under the chat widget. Default off. */
  poweredByEnabled?: boolean
  /** Anonymous usage analytics in wp-admin. Default off. */
  analyticsEnabled?: boolean
}

/**
 * Response shape of GetOverviewInsights RPC. Drives the Overview tab's
 * Section 01 bento. All counts cover the last `windowDays` days; the
 * service caches the bundle per-site for 15 minutes server-side so this
 * is safe to re-fetch frequently.
 */
export interface TopQuestion {
  label: string
  count: number
  example: string
}
export interface TopCountry {
  countryName: string
  count: number
}
export interface CoverageGap {
  topic: string
  count: number
  example: string
}
export interface CitedPage {
  url: string
  title: string | null
  servedCount: number
  clickCount: number
}
/** Operator-pushed dashboard announcement (Overview banner). bodyHtml is
 *  sanitized before render. */
export interface Announcement {
  id: string
  title: string
  bodyHtml: string | null
  imageUrl: string | null
  tone: 'info' | 'success' | 'warn' | string
  ctaLabel: string | null
  ctaUrl: string | null
}

export interface OverviewInsights {
  windowDays: number
  conversations: number
  conversationsPrior: number
  avgMessagesPerConversation: number
  userMessages: number
  uniqueVisitors: number
  /** 0..1 share of in-window conversations from visitors seen before. */
  returningVisitorRate: number
  leadCaptureRate: number
  leadsCount: number
  topQuestions: TopQuestion[]
  topCountries: TopCountry[]
  otherCountriesVisitors: number
  topCitedPages: CitedPage[]
  coverageGaps: CoverageGap[]
  /** How many coverage-gap suggestions the admin discarded. Drives the
   *  "Reset discarded" link in the FAQ tab. Optional for older payloads. */
  dismissedGapCount?: number
  snapshotComputedAt: string | null
}

/** One tappable starter on the agent home. `label` is the chip text; `prompt`
 *  is what gets sent to the agent. */
export interface AgentHomeSuggestion {
  label: string
  prompt: string
}

/** Response shape of GetAgentHome, the dynamic agent-home empty state.
 *  greeting/brief/suggestions are LLM-written in the owner's language. */
export interface AgentHomeFinding {
  id: string
  severity: 'critical' | 'high' | 'medium' | 'low'
  title: string
  detail: string | null
  evidence: string | null
  /** When the audit that produced this ran — so stale findings can say so. */
  scanAt: string
  /** How many more are still waiting after this one. */
  remaining: number
}

export interface AgentHome {
  mode: 'first_run' | 'returning'
  greeting: string
  brief: string
  suggestions: AgentHomeSuggestion[]
  /** One unshown finding from the last audit, or null. Server marks it shown
   *  as it hands it over, so each visit surfaces a different one. */
  finding: AgentHomeFinding | null
  /** Where this site stands with the one free audit. 'offered' = never
   *  scanned and may; 'running' = kicked, nothing back yet; 'ready' =
   *  findings waiting; 'used' = scanned and all shown. */
  scanStatus: 'offered' | 'running' | 'ready' | 'used'
  /** When the last audit ran, so a stale one can say so. */
  scanAt: string | null
  /** What the scan is doing right now — real activity from the run's ledger.
   *  Only while scanStatus is 'running'. */
  scanProgress: { tool: string; steps: number } | null
}

export interface Faq {
  id: string
  siteId: string
  question: string
  answer: string
  autoGenerated: boolean
  sourceTopic: string | null
  active: boolean
  createdAt: string
  updatedAt: string
}
export interface FaqDraft {
  question: string
  answer: string
}

/** The Usage panel's figures in whole credits (agentCredit.money.credits). */
export interface AgentCreditCredits {
  pool: number
  used: number
  left: number
  included: number
  purchased: number
  dailyShare: number
  last24h: number
}

/** Automatic top-up (agentCredit.autoTopup), saved through SetAgentCapability. */
export interface AutoTopupStatus {
  enabled: boolean
  /** The chosen amount, in credits. */
  credits: number
  /** At most this many automatic top-ups a month. */
  monthlyCap: number
  usedThisMonth: number
  /** The purchasable amounts, in credits. */
  optionsCredits: number[]
  /** Why it did not run, when the credits are used up; null otherwise. */
  skipReason: string | null
}
