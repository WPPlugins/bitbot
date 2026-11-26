/**
 * Responses tab: one row per submission. Identity and message always show
 * (full message expands); answers show on paid plans and read "Assistant
 * feature" on Free, so a blank cell always means unanswered. Search and CSV
 * cover the whole result set, not just the pages loaded so far.
 */
import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import type { FormInfo, LeadInfo } from '@/api/types'
import { useLeads, usePromoteToLead } from '@/hooks/useLeads'
import { usePlanStatus } from '@/hooks/usePlanStatus'
import { isPaid } from '@/lib/config'
import { rpc } from '@/api/rpc'
import { toast } from '@/components/ui/Toast'
import { relativeTime, truncate } from '@/lib/utils'
import {
  ANSWERS_LOCKED_LABEL, RESPONSES_PAGE_SIZE, answerColumns, buildResponsesCsv, fetchAllPages, matchesSearch, responseKey,
} from '@/lib/formResponses'

const PREVIEW_CHARS = 80

async function loadAllResponses(formId: string): Promise<LeadInfo[]> {
  return fetchAllPages(async (page) => {
    const res = await rpc<{ leads: LeadInfo[]; total: number }>('GetLeads', { formId, page, pageSize: RESPONSES_PAGE_SIZE })
    if (!res.success) throw new Error(res.error)
    return res.data
  }) as Promise<LeadInfo[]>
}

export function ResponsesTab({ form }: { form: FormInfo }) {
  const [search, setSearch] = useState('')
  const [exporting, setExporting] = useState(false)
  const { leads, total, isLoading, hasNextPage, isFetchingNextPage, fetchNextPage } = useLeads({ formId: form.id })
  const { data: plan } = usePlanStatus()
  const promote = usePromoteToLead()
  const isFreePlan = !!plan && !isPaid(plan.plan)
  const searching = search.trim().length > 0
  const columns = useMemo(() => answerColumns(form.fields), [form.fields])

  // Searching needs every row, so fetch the full set once a query exists.
  const all = useQuery({
    queryKey: ['form-responses-all', form.id],
    queryFn: () => loadAllResponses(form.id),
    enabled: searching,
    staleTime: 30_000,
  })

  const shown = useMemo(() => {
    const base = searching ? (all.data ?? []) : leads
    return searching ? base.filter((r) => matchesSearch(r, search)) : base
  }, [searching, all.data, leads, search])

  const exportCsv = async () => {
    setExporting(true)
    try {
      const rows = await loadAllResponses(form.id)
      const csv = buildResponsesCsv(rows, columns, isFreePlan)
      const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }))
      const a = document.createElement('a')
      a.href = url
      a.download = `${form.name.replace(/[^a-z0-9]+/gi, '_')}_responses.csv`
      a.click()
      URL.revokeObjectURL(url)
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Export failed')
    } finally {
      setExporting(false)
    }
  }

  return (
    <div className="bb-form-responses">
      <div className="bb-form-responses-toolbar">
        <input
          type="search"
          className="bb-input"
          aria-label="Search responses"
          placeholder="Search responses"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <button className="bb-btn bb-btn-ghost bb-btn-small" onClick={exportCsv} disabled={total === 0 || exporting}>
          {exporting ? 'Exporting…' : 'Export CSV'}
        </button>
      </div>

      {isLoading && <div className="bb-skeleton" style={{ height: 200, borderRadius: 8 }} />}
      {!isLoading && total === 0 && (
        <p className="bb-field-help">No submissions yet. Once this form goes live, responses show up here.</p>
      )}
      {searching && all.isFetching && <p className="bb-field-help" role="status">Searching every response…</p>}
      {searching && all.isError && (
        <p className="bb-field-help bb-field-help-error" role="alert">
          {all.error instanceof Error ? all.error.message : 'Search failed.'}
        </p>
      )}

      {!isLoading && total > 0 && (
        <>
          <p className="bb-field-help" style={{ marginTop: 0, marginBottom: 'var(--sp-8)' }}>
            {searching
              ? `${shown.length} of ${total} responses match`
              : <>Showing <strong>{leads.length}</strong> of {total} responses</>}
          </p>
          <ResponsesTable
            rows={shown}
            columns={columns}
            showPhone={form.fields.some((f) => f.name === 'phone') || leads.some((r) => !!r.phone)}
            answersLocked={isFreePlan}
            promote={promote}
            canPromote={!isFreePlan}
          />
          {hasNextPage && !searching && (
            <button
              type="button"
              className="bb-btn bb-btn-ghost bb-load-more"
              onClick={() => fetchNextPage()}
              disabled={isFetchingNextPage}
              style={{ marginTop: 'var(--sp-12)' }}
            >
              {isFetchingNextPage ? 'Loading…' : `Load more (${total - leads.length} remaining)`}
            </button>
          )}
        </>
      )}
    </div>
  )
}

function MessageCell({ message }: { message: string }) {
  const [open, setOpen] = useState(false)
  if (!message) return <span className="bb-form-responses-muted">No message</span>
  const long = message.length > PREVIEW_CHARS
  return (
    <div className="bb-form-responses-message">
      <span>{open || !long ? message : truncate(message, PREVIEW_CHARS)}</span>
      {long && (
        <button type="button" className="bb-link-btn" aria-expanded={open} onClick={() => setOpen(!open)}>
          {open ? 'Show less' : 'Show more'}
        </button>
      )}
    </div>
  )
}

function ResponsesTable({
  rows, columns, showPhone, answersLocked, promote, canPromote,
}: {
  rows: LeadInfo[]
  columns: { name: string; label: string }[]
  showPhone: boolean
  answersLocked: boolean
  promote: ReturnType<typeof usePromoteToLead>
  canPromote: boolean
}) {
  const answer = (r: LeadInfo, name: string) => {
    if (answersLocked) return <span className="bb-form-responses-muted">{ANSWERS_LOCKED_LABEL}</span>
    const v = r.customFields?.[name]
    return v ? truncate(v, PREVIEW_CHARS) : '—'
  }

  return (
    <div className="bb-form-responses-scroll">
      <table className="bb-form-responses-table">
        <thead>
          <tr>
            <th>When</th>
            <th>Name</th>
            <th>Email</th>
            {showPhone && <th>Phone</th>}
            <th>Message</th>
            {columns.map((c) => <th key={c.name}>{c.label}</th>)}
            <th></th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={responseKey(r)}>
              <td title={new Date(r.createdAt).toLocaleString()}>{relativeTime(r.createdAt)}</td>
              <td>{r.name || '(no name)'}</td>
              <td>{r.email}</td>
              {showPhone && <td>{r.phone ?? ''}</td>}
              <td><MessageCell message={r.message ?? ''} /></td>
              {columns.map((c) => <td key={c.name}>{answer(r, c.name)}</td>)}
              <td className="bb-form-responses-actions">
                {r.conversationId || r.promotedFromFormAt ? (
                  <span
                    className="bb-form-responses-leadtag"
                    title={r.conversationId ? 'Captured during a chat, already in Leads' : 'Added to Leads'}
                  >
                    {r.conversationId ? 'In Leads · chat' : 'In Leads'}
                  </span>
                ) : canPromote ? (
                  <button type="button" className="bb-link-btn" onClick={() => promote.mutate(r.id)} disabled={promote.isPending}>
                    Add to Leads
                  </button>
                ) : null}
                <a className="bb-link-btn" href={`mailto:${r.email}?subject=Re: your enquiry`}>Reply</a>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
