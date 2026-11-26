/**
 * LeadsList — left column of the Leads tab. Search input + the row
 * list with an un-viewed dot indicator on leads that haven't been
 * opened yet. Click a row → bubbles up via onSelect.
 *
 * Stage filter tabs lived here before; removed 2026-05-20 because
 * Youami's actual user replies and moves on — they don't run a
 * pipeline. Backend stage column stays in case a future Agent-tier
 * feature wires it in.
 *
 * Delete affordances (added 2026-06-07):
 *   - Trash 🗑 icon on each row, visible on hover, for single-row delete.
 *   - Checkbox on each row, visible on hover. Once at least one is
 *     ticked, a bulk bar slides in with "Select all" + "Delete N".
 *   - Both routes go through one simple confirm modal — leads are
 *     leaves (no linked-children prompt like Conversations needs).
 */
import { useMemo, useState } from 'react'
import type { LeadInfo } from '@/api/types'
import { isPaid } from '@/lib/config'
import { useLeads, useLeadFacets, useDeleteLead, useDeleteLeads, useMarkLeadsRead } from '@/hooks/useLeads'
import { usePlanStatus } from '@/hooks/usePlanStatus'
import { useZohoIntegration, usePushAllLeadsToZoho } from '@/hooks/useZoho'
import { ChangePlanModal } from '@/components/billing/ChangePlanModal'
import { EmptyState } from '@/components/ui/EmptyState'
import { confirm } from '@/components/ui/ConfirmDialog'
import { relativeTime, formatLeadSource, formatLeadChannel } from '@/lib/utils'

interface LeadsListProps {
  selectedId: string | null
  onSelect: (lead: LeadInfo) => void
}

export function LeadsList({ selectedId, onSelect }: LeadsListProps) {
  const [search, setSearch] = useState('')
  const [country, setCountry] = useState<string | null>(null)
  const [via, setVia] = useState<string | null>(null)
  const [changePlanOpen, setChangePlanOpen] = useState(false)
  const { leads, total, isLoading, hasNextPage, isFetchingNextPage, fetchNextPage } = useLeads({ search, country, via })
  const { data: facets } = useLeadFacets({ search, country, via })
  const { data: planStatus } = usePlanStatus()
  const isFreePlan = !!planStatus && !isPaid(planStatus.plan)
  const zohoStatus = useZohoIntegration()
  const pushAll = usePushAllLeadsToZoho()
  const zohoConnected = zohoStatus.data?.integration?.status === 'connected'

  const [checked, setChecked] = useState<Set<string>>(new Set())
  const delOne = useDeleteLead()
  const delMany = useDeleteLeads()
  const markRead = useMarkLeadsRead()

  // Filter checked down to ids still visible so stale ids (after a
  // delete or search-filter change) don't keep counting.
  const activeChecked = useMemo(() => {
    const valid = new Set<string>()
    for (const l of leads) if (checked.has(l.id)) valid.add(l.id)
    return valid
  }, [leads, checked])
  const checkedCount = activeChecked.size
  const allListChecked = checkedCount > 0 && checkedCount === leads.length

  const toggleCheck = (id: string) => {
    const next = new Set(checked)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    setChecked(next)
  }
  const toggleAll = () => {
    if (allListChecked) setChecked(new Set())
    else setChecked(new Set(leads.map((l) => l.id)))
  }
  const clearChecks = () => setChecked(new Set())

  const confirmAndDelete = async (ids: string[]) => {
    if (ids.length === 0) return
    const ok = await confirm({
      title: `Delete ${ids.length} ${ids.length === 1 ? 'lead' : 'leads'}?`,
      message: `The selected ${ids.length === 1 ? 'lead will be' : 'leads will be'} permanently removed from your inbox. This can't be undone.`,
      confirmLabel: `Delete ${ids.length} ${ids.length === 1 ? 'lead' : 'leads'}`,
      danger: true,
    })
    if (!ok) return
    if (ids.length === 1) {
      await delOne.mutateAsync({ leadId: ids[0] }).catch(() => null)
    } else {
      await delMany.mutateAsync({ leadIds: ids }).catch(() => null)
    }
    clearChecks()
  }

  const runMarkRead = async () => {
    if (checkedCount === 0) return
    await markRead.mutateAsync({ leadIds: Array.from(activeChecked) }).catch(() => null)
    clearChecks()
  }

  return (
    <section className="bb-leads-list">
      <header className="bb-leads-toolbar">
        <input
          type="search"
          className="bb-input bb-leads-search"
          placeholder="Search by name or email"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        {/* Faceted filters — counts react to the other active filter + search.
            Empty (hidden) on Free, since rows are gated there. */}
        {((facets?.locations.length ?? 0) > 0 || (facets?.via.length ?? 0) > 0) && (
          <div className="bb-leads-filters">
            {(facets?.locations.length ?? 0) > 0 && (
              <select
                className="bb-input bb-leads-filter"
                value={country ?? ''}
                onChange={(e) => setCountry(e.target.value || null)}
                aria-label="Filter by location"
              >
                <option value="">All locations</option>
                {facets!.locations.map((l) => (
                  <option key={l.value} value={l.value}>{l.value} ({l.count})</option>
                ))}
              </select>
            )}
            {(facets?.via.length ?? 0) > 0 && (
              <select
                className="bb-input bb-leads-filter"
                value={via ?? ''}
                onChange={(e) => setVia(e.target.value || null)}
                aria-label="Filter by source"
              >
                <option value="">All sources</option>
                {facets!.via.map((v) => (
                  <option key={v.value} value={v.value}>{v.value} ({v.count})</option>
                ))}
              </select>
            )}
          </div>
        )}
        {total > 0 && (
          <span className="bb-leads-count">
            {leads.length < total
              ? <>Showing <strong>{leads.length}</strong> of {total}</>
              : <>{total} {total === 1 ? 'lead' : 'leads'}</>
            }
            {zohoConnected && !isFreePlan && (
              <button
                type="button"
                className="bb-leads-sync-all"
                onClick={() => pushAll.mutate()}
                disabled={pushAll.isPending}
                title="Push every lead in this inbox to your Zoho CRM. Duplicates in Zoho are skipped."
              >
                {pushAll.isPending ? 'Syncing…' : 'Sync all to Zoho ↗'}
              </button>
            )}
          </span>
        )}
      </header>

      {/* Bulk bar only when selection ≥1 — matches Conversations tab. */}
      {!isLoading && !isFreePlan && checkedCount > 0 && (
        <div className="bb-conv-bulk-bar">
          <label className="bb-conv-bulk-select-all">
            <input
              type="checkbox"
              checked={allListChecked}
              ref={(el) => {
                if (el) el.indeterminate = checkedCount > 0 && !allListChecked
              }}
              onChange={toggleAll}
              aria-label={allListChecked ? 'Deselect all' : 'Select all'}
            />
            <span>
              {allListChecked ? `${checkedCount} selected (all)` : `${checkedCount} selected`}
            </span>
          </label>
          <div className="bb-conv-bulk-actions">
            <button
              type="button"
              className="bb-btn bb-btn-ghost bb-btn-small"
              onClick={clearChecks}
            >
              Clear
            </button>
            {!isFreePlan && <button
              type="button"
              className="bb-btn bb-btn-ghost bb-btn-small"
              onClick={runMarkRead}
              disabled={markRead.isPending}
            >
              {markRead.isPending ? 'Marking…' : 'Mark read'}
            </button>}
            {!isFreePlan && <button
              type="button"
              className="bb-btn bb-btn-danger bb-btn-small"
              onClick={() => confirmAndDelete(Array.from(activeChecked))}
              disabled={delMany.isPending}
            >
              {`Delete ${checkedCount}`}
            </button>}
          </div>
        </div>
      )}

      <div className="bb-leads-rows">
        {isLoading && (
          <div className="bb-skeleton" style={{ height: 320, margin: 16, borderRadius: 8 }} />
        )}
        {!isLoading && leads.length === 0 && (
          <EmptyState
            headline="No leads yet"
            body="Leads from chat conversations show up here. The chatbot starts capturing emails as soon as visitors ask about pricing, services, or booking."
            ctaLabel="Open the chat to test"
            onCta={() => window.open('/', '_blank')}
          />
        )}
        {!isLoading && leads.map((lead) => {
          const isActive = lead.id === selectedId
          const isUnviewed = lead.viewedAt === null
          const isChecked = activeChecked.has(lead.id)
          return (
            <div
              key={lead.id}
              className={`bb-lead-row ${isActive ? 'bb-lead-row-active' : ''} ${isChecked ? 'bb-lead-row-selected' : ''}`}
            >
              {!isFreePlan && <label
                className={`bb-lead-row-check ${checkedCount > 0 || isChecked ? 'bb-lead-row-check-visible' : ''}`}
                onClick={(e) => e.stopPropagation()}
              >
                <input
                  type="checkbox"
                  checked={isChecked}
                  onChange={() => toggleCheck(lead.id)}
                  aria-label={isChecked ? `Deselect ${lead.name || 'lead'}` : `Select ${lead.name || 'lead'}`}
                />
              </label>}
              <button className="bb-lead-row-body" onClick={() => onSelect(lead)}>
                <div className="bb-lead-row-top">
                  <div className="bb-lead-row-name">
                    {isUnviewed && <span className="bb-lead-row-dot" aria-label="New" />}
                    <span>{lead.name || '(no name)'}</span>
                  </div>
                  <span className="bb-lead-row-time">{relativeTime(lead.createdAt)}</span>
                </div>
                <div className="bb-lead-row-email">{lead.email}</div>
                <div className="bb-lead-row-meta">
                  {(() => {
                    const ch = formatLeadChannel(lead.channel)
                    return lead.channel && lead.channel !== 'web_widget' ? (
                      <span className="bb-lead-row-channel" title={`Captured via ${ch.label}`}>
                        {ch.icon} {ch.label} ·
                      </span>
                    ) : null
                  })()}
                  <span className="bb-lead-row-source">via {formatLeadSource(lead.source)}</span>
                  {lead.country && <span className="bb-lead-row-loc">· {lead.city ?? lead.country}</span>}
                  {lead.zohoLeadId && (
                    <span className="bb-lead-row-zoho" title="Synced to Zoho CRM">· ✓ Zoho</span>
                  )}
                </div>
              </button>
              <button
                type="button"
                className="bb-lead-row-delete"
                title="Delete this lead"
                aria-label={`Delete ${lead.name || 'lead'}`}
                onClick={(e) => {
                  e.stopPropagation()
                  confirmAndDelete([lead.id])
                }}
                hidden={isFreePlan}
              >
                🗑
              </button>
            </div>
          )
        })}
        {hasNextPage && (
          <button
            type="button"
            className="bb-btn bb-btn-ghost bb-load-more"
            onClick={() => fetchNextPage()}
            disabled={isFetchingNextPage}
          >
            {isFetchingNextPage ? 'Loading…' : `Load more (${total - leads.length} remaining)`}
          </button>
        )}
      </div>

      <ChangePlanModal open={changePlanOpen} onClose={() => setChangePlanOpen(false)} />
    </section>
  )
}

