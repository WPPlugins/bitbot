/**
 * Scheduler drawer — the Agent tab's "Tasks" surface. Lists scheduled tasks
 * (Hourly/Daily/Weekly), each with its schedule, next/last run, an enabled
 * toggle, Run now, and an expandable Run history. Opening a run loads the
 * conversation it produced into the chat (continuable). "+ New task" opens an
 * inline create modal. All data via the Pro+ scheduled-task RPCs.
 */
import { useEffect, useMemo, useState } from 'react'
import type { JSX } from 'react'
import { createPortal } from 'react-dom'
import {
  useScheduledTasks,
  useCreateScheduledTask,
  useUpdateScheduledTask,
  useDeleteScheduledTask,
  useRunScheduledTaskNow,
  useScheduledTaskRuns,
  useDeleteScheduledTaskRun,
  type ScheduledTask,
  type Frequency,
} from '@/hooks/useScheduledTasks'
import { confirm } from '@/components/ui/ConfirmDialog'
import './scheduler.css'

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']

function fmtTime(hhmm: string): string {
  const [h, m] = hhmm.split(':').map(Number)
  const ap = h < 12 ? 'am' : 'pm'
  const h12 = h % 12 === 0 ? 12 : h % 12
  return `${h12}:${String(m).padStart(2, '0')} ${ap}`
}

function fmtSchedule(t: ScheduledTask): string {
  if (t.frequency === 'hourly') return `Every hour at :${t.runTime.split(':')[1]}`
  if (t.frequency === 'daily') return `Every day · ${fmtTime(t.runTime)}`
  return `Every ${DAYS[t.weekday ?? 0]} · ${fmtTime(t.runTime)}`
}

function fmtWhen(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}

function RunHistory({ taskId, onOpen }: { taskId: string; onOpen: (id: string, live?: boolean) => void }) {
  const { data: runs = [], isLoading } = useScheduledTaskRuns(taskId)
  const delRun = useDeleteScheduledTaskRun(taskId)
  if (isLoading) return <div className="bb-sched-runs-empty">Loading runs…</div>
  if (runs.length === 0) return <div className="bb-sched-runs-empty">No runs yet.</div>
  return (
    <div className="bb-sched-runs">
      {runs.map((r) => (
        <div className="bb-sched-run" key={r.id}>
          <span className={`bb-sched-dot ${r.status}`} />
          <span className="bb-sched-run-when">{fmtWhen(r.startedAt)}</span>
          <span className="bb-sched-run-res">
            {r.status === 'failed'
              ? r.error || 'Failed'
              : r.status === 'skipped'
                ? r.skipReason || 'Skipped'
                : r.status === 'running'
                  ? 'Running…'
                  : r.resultSummary || 'Completed'}
          </span>
          {r.conversationId && (
            <button className="bb-sched-run-open" onClick={() => onOpen(r.conversationId!, r.status === 'running')}>open ↗</button>
          )}
          <button
            className="bb-sched-run-del"
            title="Delete this run and its conversation"
            disabled={r.status === 'running' || delRun.isPending}
            onClick={async () => {
              const ok = await confirm({ title: 'Delete this run?', message: 'Removes the run and the conversation it produced. Any draft it created stays in your Content tab.', confirmLabel: 'Delete', danger: true })
              if (ok) delRun.mutate(r.id)
            }}
          >✕</button>
        </div>
      ))}
    </div>
  )
}

function CreateModal({ onClose }: { onClose: () => void }) {
  const create = useCreateScheduledTask()
  const detectedTz = (() => { try { return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC' } catch { return 'UTC' } })()
  // Full IANA zone list, native — no hardcoded list to drift.
  const zones = useMemo<string[]>(() => {
    try {
      const sv = (Intl as unknown as { supportedValuesOf?: (k: string) => string[] }).supportedValuesOf
      const list = sv ? sv('timeZone') : []
      return list.length ? list : [detectedTz, 'UTC']
    } catch { return [detectedTz, 'UTC'] }
  }, [detectedTz])
  const [name, setName] = useState('')
  const [prompt, setPrompt] = useState('')
  const [frequency, setFrequency] = useState<Frequency>('weekly')
  const [runTime, setRunTime] = useState('09:00')
  const [weekday, setWeekday] = useState(0)
  const [timezone, setTimezone] = useState(detectedTz)

  const valid = name.trim() && prompt.trim()
  const save = () => {
    if (!valid) return
    create.mutate(
      { name: name.trim(), description: '', prompt: prompt.trim(), frequency, runTime, weekday: frequency === 'weekly' ? weekday : null, timezone },
      { onSuccess: onClose },
    )
  }

  return createPortal(
    <div className="bb-sched-modal-scrim bb-portal-scope" onClick={onClose}>
      <div className="bb-sched-modal" onClick={(e) => e.stopPropagation()}>
        <div className="bb-sched-modal-head">
          <h3>Create scheduled task</h3>
          <button className="bb-sched-x" onClick={onClose}>✕</button>
        </div>
        <div className="bb-sched-modal-body">
          <label className="bb-sched-lbl">Name *</label>
          <input className="bb-sched-inp" value={name} onChange={(e) => setName(e.target.value)} placeholder="weekly-content-brief" />

          <label className="bb-sched-lbl">Task *</label>
          <textarea className="bb-sched-ta" value={prompt} onChange={(e) => setPrompt(e.target.value)} placeholder="Tell Youami what to do each run…" />

          <label className="bb-sched-lbl">Frequency</label>
          <div className="bb-sched-freqrow">
            <select className="bb-sched-sel" value={frequency} onChange={(e) => setFrequency(e.target.value as Frequency)}>
              <option value="hourly">Hourly</option>
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
            </select>
            {frequency !== 'hourly' && (
              <input className="bb-sched-sel" type="time" value={runTime} onChange={(e) => setRunTime(e.target.value)} />
            )}
            {frequency === 'weekly' && (
              <select className="bb-sched-sel" value={weekday} onChange={(e) => setWeekday(Number(e.target.value))}>
                {DAYS.map((d, i) => <option key={d} value={i}>{d}</option>)}
              </select>
            )}
          </div>

          <label className="bb-sched-lbl">Timezone</label>
          <select className="bb-sched-inp" value={timezone} onChange={(e) => setTimezone(e.target.value)}>
            {zones.map((z) => <option key={z} value={z}>{z.replace(/_/g, ' ')}</option>)}
          </select>

          {create.isError && <p className="bb-sched-err">{(create.error as Error)?.message || 'Could not save task'}</p>}
        </div>
        <div className="bb-sched-modal-foot">
          <button className="bb-btn bb-btn-ghost" onClick={onClose}>Cancel</button>
          <button className="bb-btn bb-btn-primary" disabled={!valid || create.isPending} onClick={save}>
            {create.isPending ? 'Saving…' : 'Save'}
          </button>
        </div>
      </div>
    </div>,
    document.body,
  )
}

export function SchedulerDrawerBody({ onOpenConversation }: { onOpenConversation: (id: string, live?: boolean) => void }) {
  const { data: tasks = [], isLoading } = useScheduledTasks()
  const update = useUpdateScheduledTask()
  const del = useDeleteScheduledTask()
  const runNow = useRunScheduledTaskNow()
  const [creating, setCreating] = useState(false)
  const [openRuns, setOpenRuns] = useState<string | null>(null)
  const [planTaskId, setPlanTaskId] = useState<string | null>(null)
  const [runningId, setRunningId] = useState<string | null>(null)

  return (
    <div className="bb-sched">
      <div className="bb-sched-head">
        <p className="bb-sched-intro">Tasks Youami runs for you on a schedule. Each run is a conversation you can open and continue.</p>
        <button className="bb-btn bb-btn-primary bb-btn-small" onClick={() => setCreating(true)}>+ New task</button>
      </div>

      {isLoading ? (
        <p className="bb-sched-empty">Loading…</p>
      ) : tasks.length === 0 ? (
        <p className="bb-sched-empty">No scheduled tasks yet. Create one to have Youami run it automatically.</p>
      ) : (
        <div className="bb-sched-list">
          {tasks.map((t) => (
            <div className={`bb-sched-card ${t.enabled ? '' : 'paused'}`} key={t.id}>
              <div className="bb-sched-card-top">
                <div className="bb-sched-titles">
                  <span className="bb-sched-name">{t.name}<span className={`bb-sched-kind ${t.frequency}`}>{t.frequency}</span></span>
                  {t.description && <span className="bb-sched-desc">{t.description}</span>}
                </div>
                <button
                  className={`bb-sched-toggle ${t.enabled ? 'on' : 'off'}`}
                  title={t.enabled ? 'Active — click to pause' : 'Paused — click to activate'}
                  onClick={() => update.mutate({ taskId: t.id, enabled: !t.enabled })}
                />
              </div>
              <div className="bb-sched-meta">
                <span className="bb-sched-m"><b>Schedule</b>{fmtSchedule(t)}</span>
                <span className="bb-sched-m"><b>Next run</b>{t.enabled ? fmtWhen(t.nextRunAt) : 'Paused'}</span>
                <span className="bb-sched-m"><b>Last run</b>{fmtWhen(t.lastRunAt)}</span>
              </div>
              <div className="bb-sched-actions">
                <button
                  className="bb-sched-link"
                  disabled={runningId === t.id}
                  onClick={() => {
                    setRunningId(t.id)
                    runNow.mutate(t.id, {
                      onSuccess: (d) => { setRunningId(null); if (d?.conversationId) onOpenConversation(d.conversationId, true) },
                      onError: () => setRunningId(null),
                    })
                  }}
                >{runningId === t.id ? 'Running…' : '▷ Run now'}</button>
                <button className="bb-sched-link" onClick={() => setOpenRuns(openRuns === t.id ? null : t.id)}>
                  {openRuns === t.id ? 'Hide runs' : 'Run history'}
                </button>
                <button className="bb-sched-link" onClick={() => setPlanTaskId(t.id)}>Workflow</button>
                <button
                  className="bb-sched-link danger"
                  onClick={async () => {
                    const ok = await confirm({ title: `Delete “${t.name}”?`, message: 'Removes the task, its run history, and the run conversations.', confirmLabel: 'Delete', danger: true })
                    if (ok) del.mutate(t.id)
                  }}
                >Delete</button>
              </div>
              {openRuns === t.id && <RunHistory taskId={t.id} onOpen={onOpenConversation} />}
            </div>
          ))}
        </div>
      )}

      {creating && <CreateModal onClose={() => setCreating(false)} />}
      {planTaskId && (() => {
        const pt = tasks.find((t) => t.id === planTaskId)
        return pt ? <WorkflowModal task={pt} onClose={() => setPlanTaskId(null)} /> : null
      })()}
    </div>
  )
}

// ── Workflow preview (its own panel; renders the blueprint markdown) ──────────

function renderInline(text: string): (string | JSX.Element)[] {
  return text.split(/(\*\*[^*]+\*\*|`[^`]+`)/g).map((p, i) => {
    if (p.startsWith('**') && p.endsWith('**')) return <strong key={i}>{p.slice(2, -2)}</strong>
    if (p.startsWith('`') && p.endsWith('`')) return <code key={i} className="bb-wf-code">{p.slice(1, -1)}</code>
    return p
  })
}

function renderBlueprint(md: string): JSX.Element[] {
  return md.split('\n').map((line, i) => {
    if (line.startsWith('## ')) return <h4 key={i} className="bb-wf-h">{line.slice(3)}</h4>
    if (line.startsWith('# ')) return <h4 key={i} className="bb-wf-h">{line.slice(2)}</h4>
    if (/^\s*\d+\.\s+/.test(line)) return <div key={i} className="bb-wf-li bb-wf-ol">{renderInline(line.replace(/^\s*(\d+)\.\s+/, '$1. '))}</div>
    if (/^\s*[-*]\s+/.test(line)) return <div key={i} className="bb-wf-li">{renderInline(line.replace(/^\s*[-*]\s+/, ''))}</div>
    if (line.trim() === '') return <div key={i} className="bb-wf-sp" />
    return <p key={i} className="bb-wf-p">{renderInline(line)}</p>
  })
}

function WorkflowModal({ task, onClose }: { task: ScheduledTask; onClose: () => void }) {
  const update = useUpdateScheduledTask()
  const [editing, setEditing] = useState(false)
  const [text, setText] = useState(task.blueprint ?? '')

  // The blueprint is authored in the background after creation; keep the
  // displayed text in sync as it arrives, but never clobber an active edit.
  useEffect(() => {
    if (!editing) setText(task.blueprint ?? '')
  }, [task.blueprint, editing])

  const save = () => update.mutate({ taskId: task.id, blueprint: text }, { onSuccess: () => setEditing(false) })

  return createPortal(
    <div className="bb-sched-modal-scrim bb-portal-scope" onClick={onClose}>
      <div className="bb-wf-panel" onClick={(e) => e.stopPropagation()}>
        <div className="bb-sched-modal-head">
          <div>
            <h3>Workflow</h3>
            <span className="bb-wf-sub">{task.name} · what Youami does every run</span>
          </div>
          <div className="bb-wf-headact">
            {text && !editing && <button className="bb-sched-link" onClick={() => setEditing(true)}>Edit</button>}
            <button className="bb-sched-x" onClick={onClose}>✕</button>
          </div>
        </div>
        <div className="bb-wf-body">
          {editing ? (
            <textarea className="bb-wf-edit" value={text} onChange={(e) => setText(e.target.value)} />
          ) : text ? (
            renderBlueprint(text)
          ) : (
            <p className="bb-sched-runs-empty">The workflow is written after the first run. Youami does the run, learns what works, then saves the plan here.</p>
          )}
        </div>
        {editing && (
          <div className="bb-sched-modal-foot">
            <button className="bb-btn bb-btn-ghost" onClick={() => { setText(task.blueprint ?? ''); setEditing(false) }}>Cancel</button>
            <button className="bb-btn bb-btn-primary" disabled={update.isPending} onClick={save}>{update.isPending ? 'Saving…' : 'Save workflow'}</button>
          </div>
        )}
      </div>
    </div>,
    document.body,
  )
}
