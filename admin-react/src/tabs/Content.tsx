/**
 * Content Generator tab — three-up source picker (Topic / URL / Upload)
 * on top, list of drafts below, with the selected draft expanded in a
 * detail panel on the right.
 *
 *   ┌──────────────────────────────────────────────────────────────┐
 *   │ 01. Generate a post                                          │
 *   │  ┌───────────┬───────────┬───────────┐                       │
 *   │  │ Topic     │ URL       │ Upload    │                       │
 *   │  └───────────┴───────────┴───────────┘                       │
 *   │  Source value input · Optional instructions · Generate CTA   │
 *   ├──────────────────────────────────────────────────────────────┤
 *   │ 02. Drafts                                                   │
 *   │  ┌────────────────────────┬───────────────────────────────┐ │
 *   │  │ DraftsList             │ DraftDetail                   │ │
 *   │  │ • title + status pill  │ • title, seo meta, body excerpt│ │
 *   │  └────────────────────────┴───────────────────────────────┘ │
 *   └──────────────────────────────────────────────────────────────┘
 */
import { useEffect, useRef, useState } from 'react'
import DOMPurify from 'dompurify'
import { sanitizePostPreview } from '@/lib/postPreview'
import type { TopicSuggestion } from '@/hooks/useContent'
import { useDrafts, useDraft, useWpPostBody, useGeneratePost, usePublishDraftToWp, useDeleteDraft, useNewDraftsCount, markDraftsSeen, UPLOAD_ACCEPT } from '@/hooks/useContent'
import { useContentSubtab, type ContentSubtab } from '@/hooks/useContentSubtab'
import { usePlanStatus } from '@/hooks/usePlanStatus'
import type { DraftStatus } from '@/api/types'
import { Card } from '@/components/ui/Card'
import { EmptyState } from '@/components/ui/EmptyState'
import { ChangePlanModal } from '@/components/billing/ChangePlanModal'
import { TopicDiscovery } from '@/components/content/TopicDiscovery'
import { FaqSection } from '@/components/content/FaqSection'
import { IndexedPagesTable } from '@/components/chatbot/IndexedPagesTable'
import { getConfig } from '@/lib/config'
import { NO_DRAFT_PICK, draftDeleted, pickDraft, shownDraftId, type DraftPick } from '@/lib/draftSelection'
import { topicInstructions } from '@/lib/topicBrief'
import { composerLine, composerLocked, showDraftsTab, type PostPlanInput } from '@/lib/freePost'
import { toast } from '@/components/ui/Toast'
import { confirm } from '@/components/ui/ConfirmDialog'
import { relativeTime, truncate } from '@/lib/utils'

const SUBTABS: { key: ContentSubtab; label: string }[] = [
  { key: 'indexed',  label: 'Indexed pages' },
  { key: 'faqs',     label: 'FAQs' },
  { key: 'topics',   label: 'Find Topics' },
  { key: 'generate', label: 'Generate a post' },
  { key: 'drafts',   label: 'Drafts' },
]

type SourceType = 'prompt' | 'url' | 'upload'

const STATUS_LABEL: Record<DraftStatus, string> = {
  draft: 'Draft',
  scheduled: 'Scheduled',
  published: 'Published',
  failed: 'Failed',
}

// A draft can be in Youami-only "Draft" or already pushed to WordPress
// (still as a WP draft post). The pill differentiates so the user knows
// whether the "Edit in WordPress" action will create or just open.
function statusLabel(status: DraftStatus, wpPostId?: number | null): string {
  return status === 'draft' && wpPostId ? 'In WordPress' : STATUS_LABEL[status] ?? 'Draft'
}

function StatusPill({ status, wpPostId }: { status: DraftStatus; wpPostId?: number | null }) {
  const mod = status === 'draft' && wpPostId ? 'in-wordpress' : status
  return <span className={`bb-draft-status bb-draft-status-${mod}`}>{statusLabel(status, wpPostId)}</span>
}

// Backend errors are raw and not friendly. Translate the common cases so
// the user understands what to do next without copying TypeErrors.
function humanizeGenerationError(err: unknown, sourceType: SourceType): string {
  const raw = err instanceof Error ? err.message : String(err ?? '')
  if (/Failed to fetch URL/i.test(raw)) {
    if (sourceType === 'url') {
      return "Couldn't fetch that URL. The page may block bots, time out, or be a PDF. Try a different article URL, or use From a topic."
    }
    return "Couldn't load the source. Try again, or pick a different source."
  }
  if (/ACCESS_PAUSED|TRIAL_EXPIRED/i.test(raw)) return 'Your subscription has ended. Reactivate to keep generating posts.'
  if (/ASSISTANT_REQUIRED|post generation is a pro feature/i.test(raw)) return 'Post generation is on Assistant. Upgrade to keep creating.'
  if (/monthly post limit|fair-use/i.test(raw)) return "You've hit this month's post fair-use ceiling."
  // BAML couldn't coerce the model output into a post — usually a file it
  // couldn't read (scanned/empty PDF, low-text image). Never leak the raw
  // BamlValidationError to the user.
  if (/BamlValidationError|Failed to parse LLM response|Failed to generate post from (pdf|image)/i.test(raw)) {
    if (sourceType === 'upload') {
      return "Couldn't read that file. Use a PDF with selectable text (not a scan), or paste the content as a topic instead."
    }
    return "Couldn't turn that source into a post. Try a different source, or use From a topic."
  }
  return raw || 'Something went wrong. Try again in a moment.'
}

interface NewPostComposerProps {
  prefillTopic?: { topic: string; instructions: string } | null
  onPrefillConsumed?: () => void
  composerRef?: React.RefObject<HTMLDivElement | null>
}

// Sub-tabs handle navigation now, so the composer no longer needs to
// be scrolled into view — kept the ref prop to avoid breaking the
// pattern in case we restore a stacked layout later.
function NewPostComposer({ prefillTopic, onPrefillConsumed, composerRef }: NewPostComposerProps) {
  const [sourceType, setSourceType] = useState<SourceType>('prompt')
  const [sourceValue, setSourceValue] = useState('')
  const [instructions, setInstructions] = useState('')
  const [instructionsOpen, setInstructionsOpen] = useState(false)
  const [file, setFile] = useState<File | null>(null)
  const [isDragging, setIsDragging] = useState(false)
  const fileInputRef = useRef<HTMLInputElement>(null)
  const generate = useGeneratePost()
  const { data: plan } = usePlanStatus()

  // Proactively detect cap / gate states so the Generate button disables before the user clicks.
  // Free gets one post a month from "From a topic" only (decisions in lib/freePost, the rule lives in the
  // backend); other sources, a used post, or expired access show the locked button that opens the upgrade.
  // Paid fair-use shows when postsThisMonth >= postsPerMonth on the dashboard payload.
  const postsUsed     = plan?.usage.postsThisMonth ?? 0
  const postsCap      = plan?.limits.postsPerMonth ?? 0
  const isFree        = plan?.plan === 'free'
  const isPostCapHit  = !isFree && postsCap > 0 && postsUsed >= postsCap
  const isAccessPaused = plan?.accessMode === 'expired'
  const postPlan: PostPlanInput = {
    plan: plan?.plan,
    accessMode: plan?.accessMode,
    postsThisMonth: postsUsed,
    postsPerMonth: postsCap,
    freePostSources: plan?.freePostSources,
    monthResetsOn: plan?.monthResetsOn,
  }
  const showUpgradeLock = composerLocked({ ...postPlan, sourceType })
  const [upgradeOpen, setUpgradeOpen] = useState(false)

  // When a topic is pushed in from TopicDiscovery, swap to the prompt
  // source, fill the value, and let the parent know we consumed it
  // (so it can clear the pending state and re-trigger if the admin
  // clicks the same suggestion twice in a row).
  useEffect(() => {
    if (!prefillTopic) return
    setSourceType('prompt')
    setSourceValue(prefillTopic.topic)
    // The suggestion's site-specific brief becomes the instructions (shown open, editable).
    setInstructions(prefillTopic.instructions)
    onPrefillConsumed?.()
  }, [prefillTopic, onPrefillConsumed])

  const placeholder = {
    prompt: 'A topic to write about, e.g. "5 questions every wedding planner asks before quoting"',
    url:    'https://example.com/source-article',
    upload: 'Drag a PDF or image here, or click to choose',
  }[sourceType]

  return (
    <div ref={composerRef}>
    <Card>
      <p className="bb-compose-quota">
        {composerLine({ ...postPlan, refusal: generate.error?.message })}
      </p>
      <div className="bb-content-source-tabs">
        {(['prompt', 'url', 'upload'] as const).map((t) => (
          <button
            key={t}
            className={`bb-source-tab ${sourceType === t ? 'bb-source-tab-active' : ''}`}
            onClick={() => setSourceType(t)}
          >
            <span className="bb-source-tab-title">
              {t === 'prompt' ? 'From a topic' : t === 'url' ? 'From a URL' : 'From an upload'}
            </span>
            <span className="bb-source-tab-sub">
              {t === 'prompt' ? 'AI writes from a one-line prompt' :
               t === 'url'    ? 'Summarise + rewrite an article' :
                                'Extract from a PDF or image'}
            </span>
          </button>
        ))}
      </div>

      <div className="bb-field">
        <label className="bb-field-label">
          {sourceType === 'prompt' ? 'Topic' : sourceType === 'url' ? 'Source URL' : 'File'}
        </label>
        {sourceType === 'upload' ? (
          <div
            className={`bb-upload-zone ${isDragging ? 'bb-upload-zone-dragging' : ''} ${file ? 'bb-upload-zone-filled' : ''}`}
            role="button"
            tabIndex={0}
            onClick={() => fileInputRef.current?.click()}
            onKeyDown={(e) => {
              if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault()
                fileInputRef.current?.click()
              }
            }}
            onDragOver={(e) => { e.preventDefault(); setIsDragging(true) }}
            onDragLeave={(e) => { e.preventDefault(); setIsDragging(false) }}
            onDrop={(e) => {
              e.preventDefault()
              setIsDragging(false)
              const dropped = e.dataTransfer.files?.[0]
              if (dropped) setFile(dropped)
            }}
          >
            <input
              ref={fileInputRef}
              type="file"
              accept={UPLOAD_ACCEPT}
              className="bb-upload-input"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            />
            {file ? (
              <div className="bb-upload-file">
                <span className="bb-upload-file-name">{file.name}</span>
                <button
                  type="button"
                  className="bb-upload-file-remove"
                  aria-label="Remove file"
                  onClick={(e) => {
                    e.stopPropagation()
                    setFile(null)
                    if (fileInputRef.current) fileInputRef.current.value = ''
                  }}
                >
                  ×
                </button>
              </div>
            ) : (
              <>
                <p>{placeholder}</p>
                <button type="button" className="bb-btn bb-btn-ghost bb-btn-small">Choose file</button>
              </>
            )}
          </div>
        ) : (
          <input
            className="bb-input"
            value={sourceValue}
            onChange={(e) => setSourceValue(e.target.value)}
            placeholder={placeholder}
          />
        )}
      </div>

      {/* Collapsed until it has content, so the common case is one field + one button. */}
      <details
        className="bb-compose-instructions"
        open={instructionsOpen || instructions.trim() !== ''}
        onToggle={(e) => setInstructionsOpen(e.currentTarget.open)}
      >
        <summary>Tone / instructions (optional)</summary>
        <textarea
          className="bb-input bb-textarea"
          rows={2}
          aria-label="Tone and instructions (optional)"
          value={instructions}
          onChange={(e) => setInstructions(e.target.value)}
          placeholder="Friendly, conversational, around 800 words. Always end with a CTA to book a discovery call."
        />
        <p className="bb-field-help">
          Each generated post automatically respects your site's brand voice from the chatbot's Bot Instructions.
        </p>
      </details>

      {/* Free's month state is the line above (and the locked button); a paused plan gets its banner at
          the top of the page. The remaining state to surface here is the paid fair-use ceiling. */}
      {isPostCapHit && !isAccessPaused && (
        <div className="bb-topic-discovery-gate">
          <div>
            <strong>You've hit this month's post fair-use ceiling ({postsCap}).</strong>
            <p>Fair-use resets on the 1st. Talk to us for higher limits.</p>
          </div>
        </div>
      )}


      <div className="bb-content-actions">
        {showUpgradeLock ? (
          <button
            className="bb-btn bb-btn-primary"
            onClick={() => setUpgradeOpen(true)}
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={{ marginRight: 6, verticalAlign: '-2px' }}><rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V8a4 4 0 0 1 8 0v3" /></svg>
            Generate draft
          </button>
        ) : (
          <button
            className="bb-btn bb-btn-primary"
            disabled={
              generate.isPending ||
              isPostCapHit ||
              (sourceType === 'upload' ? !file : !sourceValue.trim())
            }
            onClick={() => generate.mutate(
              {
                sourceType,
                sourceValue,
                instructions: instructions || undefined,
                file: file ?? undefined,
              },
              {
                onSuccess: () => {
                  setFile(null)
                  if (fileInputRef.current) fileInputRef.current.value = ''
                },
                // Single humanized toast — no duplicate inline card. humanize
                // maps URL-fetch / gate errors too, not just BAML failures.
                onError: (err) => toast.error(humanizeGenerationError(err, sourceType)),
              },
            )}
          >
            {generate.isPending ? 'Generating… (~30s)' : 'Generate draft →'}
          </button>
        )}
      </div>

      <ChangePlanModal open={upgradeOpen} onClose={() => setUpgradeOpen(false)} />
    </Card>
    </div>
  )
}

function DraftsList({ selectedId, onSelect, onTryTopic }: {
  selectedId: string | null; onSelect: (id: string) => void; onTryTopic: () => void
}) {
  const { data: drafts, total, isLoading, hasNextPage, isFetchingNextPage, fetchNextPage } = useDrafts()
  const list = drafts ?? []

  return (
    <section className="bb-drafts-list">
      {!isLoading && list.length > 0 && list.length < total && (
        <p className="bb-field-help" style={{ margin: '0 0 var(--sp-8)' }}>
          Showing <strong>{list.length}</strong> of {total} drafts
        </p>
      )}
      {isLoading && <div className="bb-skeleton" style={{ height: 260, borderRadius: 8 }} />}
      {!isLoading && list.length === 0 && (
        <EmptyState
          headline="No drafts yet"
          body="Pick a suggested topic and generate your first post."
          ctaLabel="Try a topic"
          onCta={onTryTopic}
        />
      )}
      {!isLoading && list.length > 0 && (
        <div className="bb-draft-rows">
          {list.map((d) => (
            <button
              key={d.id}
              type="button"
              className={`bb-draft-row ${d.id === selectedId ? 'bb-draft-row-active' : ''}`}
              aria-current={d.id === selectedId ? 'true' : undefined}
              onClick={() => onSelect(d.id)}
            >
              <span className="bb-draft-row-title">{truncate(d.title, 80)}</span>
              <span className="bb-draft-row-meta">
                {statusLabel(d.status, d.wpPostId)} · Created {relativeTime(d.createdAt)}
                {d.scheduledAt && ` · Scheduled ${relativeTime(d.scheduledAt)}`}
              </span>
            </button>
          ))}
        </div>
      )}
      {hasNextPage && (
        <button
          type="button"
          className="bb-btn bb-btn-ghost bb-load-more"
          onClick={() => fetchNextPage()}
          disabled={isFetchingNextPage}
        >
          {isFetchingNextPage ? 'Loading…' : `Load more (${total - list.length} remaining)`}
        </button>
      )}
    </section>
  )
}

interface DraftDetailProps {
  draftId: string | null
  /** Delete this draft. The request and what follows it belong to the Knowledgebase tab, so they finish
   *  even when this pane is gone before the answer arrives. */
  onDelete: (id: string) => void
  /** The draft whose delete is in flight, if any. */
  deletingId: string | null
}

function DraftDetail({ draftId, onDelete, deletingId }: DraftDetailProps) {
  const { data, isLoading, isError, refetch } = useDraft(draftId)
  // A post already in WordPress keeps its body there (the backend holds none): read it from WordPress.
  const wpBody = useWpPostBody(data?.content ? null : data?.wpPostId)
  const publish = usePublishDraftToWp()
  const isDeleting = deletingId === draftId
  const cfg = getConfig()

  if (!draftId) return null
  // A failed read is not proof the draft is gone, so it is said as it is and the pick stays.
  if (isError && !data) {
    return (
      <Card>
        <p className="bb-draft-body bb-draft-body-note">
          Could not load this draft.
          <button type="button" className="bb-link-btn" onClick={() => { void refetch() }}>Try again</button>
        </p>
      </Card>
    )
  }
  if (isLoading || !data) {
    return <Card><div className="bb-skeleton" style={{ height: 240, borderRadius: 8 }} /></Card>
  }

  const inWordPress = !!data.wpPostId
  const bodyNote = (text: string, retry?: () => void) => (
    <p className="bb-draft-body bb-draft-body-note">
      {text}
      {retry && <button type="button" className="bb-link-btn" onClick={retry}>Try again</button>}
    </p>
  )

  return (
    <Card className="bb-draft-detail">
      {/* Status and the one action sit together at the top; publish and schedule live in WordPress. */}
      <header className="bb-draft-head">
        <StatusPill status={data.status as DraftStatus} wpPostId={data.wpPostId} />
        {data.wpPostId ? (
          <a
            href={`${cfg.siteUrl.replace(/\/$/, '')}/wp-admin/post.php?post=${data.wpPostId}&action=edit`}
            target="_blank"
            rel="noopener noreferrer"
            className="bb-btn bb-btn-primary bb-btn-small"
          >
            Edit in WordPress ↗
          </a>
        ) : (
          <button
            type="button"
            className="bb-btn bb-btn-primary bb-btn-small"
            disabled={publish.isPending}
            onClick={() => publish.mutate({ draftId })}
          >
            {publish.isPending ? 'Sending to WordPress…' : 'Edit in WordPress →'}
          </button>
        )}
      </header>
      <h2 className="bb-draft-title">{data.title}</h2>

      {/* The body is our generator's HTML, or this site's own WordPress post once it lives there. A
          WordPress post can carry another editor's unfiltered HTML or shortcode output, so nothing is
          rendered here without lib/postPreview (reading markup only: no scripts, handlers or styles). */}
      {data.content ? (
        <div className="bb-draft-body" dangerouslySetInnerHTML={{ __html: sanitizePostPreview(DOMPurify, data.content) }} />
      ) : !inWordPress ? (
        bodyNote('This draft has no body.')
      ) : wpBody.isFetching || !wpBody.data ? (
        <div className="bb-skeleton" style={{ height: 160, borderRadius: 8 }} />
      ) : wpBody.data.kind === 'body' ? (
        <div className="bb-draft-body" dangerouslySetInnerHTML={{ __html: sanitizePostPreview(DOMPurify, wpBody.data.html) }} />
      ) : wpBody.data.kind === 'gone' ? (
        bodyNote('This post was not found in WordPress.')
      ) : wpBody.data.kind === 'empty' ? (
        bodyNote('This post has no body in WordPress yet.')
      ) : (
        bodyNote('Could not load the body from WordPress.', () => { void wpBody.refetch() })
      )}

      <details className="bb-draft-seo">
        <summary>SEO details</summary>
        <div className="bb-seo-block">
          <div className="bb-seo-row">
            <span className="bb-seo-label">Meta title</span>
            <span className="bb-seo-value">{data.seoMeta.metaTitle}</span>
          </div>
          <div className="bb-seo-row">
            <span className="bb-seo-label">Meta description</span>
            <span className="bb-seo-value">{data.seoMeta.metaDescription}</span>
          </div>
          <div className="bb-seo-row">
            <span className="bb-seo-label">Focus keyphrase</span>
            <span className="bb-seo-value">{data.seoMeta.focusKeyphrase}</span>
          </div>
          <div className="bb-seo-row">
            <span className="bb-seo-label">Keywords</span>
            <span className="bb-seo-value">
              {data.seoMeta.keywords.map((k) => (
                <span key={k} className="bb-keyword-chip">{k}</span>
              ))}
            </span>
          </div>
        </div>
      </details>

      <footer className="bb-draft-foot">
        <span>{data.status === 'draft' ? 'Review, schedule and publish in WordPress.' : ''}</span>
        <button
          type="button"
          className="bb-link-btn bb-link-btn-danger"
          disabled={deletingId !== null}
          onClick={async () => {
            const ok = await confirm({
              title: 'Delete this draft?',
              message: `"${data.title.slice(0, 60)}" will be removed from Youami. The WordPress post (if any) is untouched.`,
              confirmLabel: 'Delete',
              danger: true,
            })
            if (!ok) return
            onDelete(draftId)
          }}
        >
          {isDeleting ? 'Deleting…' : 'Delete'}
        </button>
      </footer>
    </Card>
  )
}

// With no drafts the list shows its own empty state at full width. Otherwise the detail side always
// shows a draft: the owner's pick, or the newest one (lib/draftSelection).
function DraftsPane({ pick, onSelect, onDelete, deletingId, onTryTopic }: {
  pick: DraftPick; onSelect: (id: string) => void; onDelete: (id: string) => void; deletingId: string | null; onTryTopic: () => void
}) {
  const { data: drafts, total, isLoading } = useDrafts()
  const empty = !isLoading && total === 0
  const shownId = shownDraftId(drafts ?? [], pick.pickedId, pick.goneIds)
  return (
    <div className={empty ? undefined : 'bb-drafts-grid'}>
      <DraftsList selectedId={shownId} onSelect={onSelect} onTryTopic={onTryTopic} />
      {!empty && <DraftDetail draftId={shownId} onDelete={onDelete} deletingId={deletingId} />}
    </div>
  )
}

export function Content() {
  // Pick and deletions live here, not in the Drafts pane: the pane unmounts on every sub tab switch.
  const [draftPick, setDraftPick] = useState<DraftPick>(NO_DRAFT_PICK)
  // The delete request is owned here for the same reason: a callback given by the pane would be dropped
  // if the owner switched sub tab before the answer came, and the deleted draft would stay picked.
  const deleteDraft = useDeleteDraft()
  const deletingId = deleteDraft.isPending ? deleteDraft.variables ?? null : null
  const onDeleteDraft = (id: string) => deleteDraft.mutate(id, { onSuccess: () => setDraftPick((s) => draftDeleted(s, id)) })
  const [pendingTopic, setPendingTopic] = useState<{ topic: string; instructions: string } | null>(null)
  const composerRef = useRef<HTMLDivElement | null>(null)
  const [sub, setSub] = useContentSubtab()
  const { data: plan } = usePlanStatus()

  // Same source of truth as the parent Knowledgebase tab badge (App.tsx), so
  // the two can never disagree. Clears the moment the admin opens Drafts.
  const unseenDrafts = useNewDraftsCount()

  useEffect(() => {
    if (sub === 'drafts') markDraftsSeen()
  }, [sub])

  // When a topic is picked from Find-topics, drop into the Generate
  // sub-tab so the admin sees the prefilled composer immediately —
  // saves one click and removes the "what just happened" moment.
  const handlePickTopic = (s: TopicSuggestion) => {
    setPendingTopic({ topic: s.topic, instructions: topicInstructions(s) })
    setSub('generate')
  }

  // Free sees Drafts once it has a draft or a free post to make one with (lib/freePost). A paid deep link
  // is never bounced: until both the plan and the drafts have loaded the tab stays shown.
  const { total: draftTotal, isLoading: draftsLoading } = useDrafts()
  const planKnown = !!plan
  const draftsVisible = showDraftsTab({
    plan: plan?.plan,
    postsThisMonth: plan?.usage.postsThisMonth ?? 0,
    postsPerMonth: plan?.limits.postsPerMonth ?? 0,
    freePostSources: plan?.freePostSources,
    planKnown,
    draftsKnown: !draftsLoading,
    draftCount: draftTotal,
  })
  const visibleSubtabs = draftsVisible ? SUBTABS : SUBTABS.filter((t) => t.key !== 'drafts')
  useEffect(() => {
    if (!draftsVisible && sub === 'drafts') setSub('generate')
  }, [draftsVisible, sub, setSub])

  return (
    <>
      {/* Sub-tab strip — only the active pane below renders, so
          vertical scroll resets per sub-section instead of stacking
          all five into one giant scroll. */}
      <nav className="bb-subtab-strip" role="tablist" aria-label="Knowledgebase sub-sections">
        {visibleSubtabs.map((t) => (
          <button
            key={t.key}
            role="tab"
            aria-selected={sub === t.key}
            className={`bb-subtab ${sub === t.key ? 'bb-subtab-active' : ''}`}
            onClick={() => setSub(t.key)}
          >
            {t.label}
            {t.key === 'drafts' && unseenDrafts > 0 && (
              <span className="bb-tab-badge" aria-label={`${unseenDrafts} new drafts`}>
                {unseenDrafts}
              </span>
            )}
          </button>
        ))}
      </nav>

      {sub === 'indexed' && <IndexedPagesTable />}
      {sub === 'faqs' && <FaqSection />}
      {sub === 'topics' && <TopicDiscovery onPickTopic={handlePickTopic} />}
      {sub === 'generate' && (
        <NewPostComposer
          prefillTopic={pendingTopic}
          onPrefillConsumed={() => setPendingTopic(null)}
          composerRef={composerRef}
        />
      )}
      {sub === 'drafts' && (
        <DraftsPane pick={draftPick} onSelect={(id) => setDraftPick((s) => pickDraft(s, id))} onDelete={onDeleteDraft} deletingId={deletingId} onTryTopic={() => setSub('topics')} />
      )}
    </>
  )
}
