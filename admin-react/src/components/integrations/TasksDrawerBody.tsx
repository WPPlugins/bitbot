/**
 * Tasks drawer — the Agent tab's background-Tasks surface. Lists the Tasks
 * Youami is running for you (agent-spawned, not created here), each with a
 * live-ticking checklist, a status pill, and Watch / Stop / Delete. Items tick
 * as the Task works across runs (polled while active). Opening "Watch" loads
 * the current run's conversation into the chat.
 */
import { useState } from 'react'
import {
  useTasks,
  useTask,
  useCancelTask,
  useDeleteTask,
  useRestartTask,
  isTaskActive,
  type AgentTask,
  type TaskItemStatus,
} from '@/hooks/useTasks'
import { confirm } from '@/components/ui/ConfirmDialog'
import { relativeTime } from '@/lib/utils'
import './tasks.css'

const STATUS_LABEL: Record<string, string> = {
  queued: 'Queued', running: 'Running', done: 'Done', failed: 'Failed', cancelled: 'Stopped', paused: 'Paused',
}
const ITEM_GLYPH: Record<TaskItemStatus, string> = {
  pending: '○', in_progress: '◐', done: '✓', failed: '✗', skipped: '–',
}

function TaskCard({ task }: { task: AgentTask }) {
  const { data } = useTask(task.id)
  const items = data?.items ?? []
  const done = items.filter((i) => i.status === 'done').length
  const total = items.length
  const cancel = useCancelTask()
  const del = useDeleteTask()
  const restart = useRestartTask()
  // Show the checklist by default — the whole point is watching it tick. The
  // caret still collapses it if the owner wants a tidier list.
  const [open, setOpen] = useState(true)

  return (
    <div className={`bb-task-card ${task.status}`}>
      <div className="bb-task-top">
        <button className="bb-task-title" onClick={() => setOpen(!open)}>
          <span className={`bb-task-caret ${open ? 'open' : ''}`}>▸</span>
          <span className="bb-task-name">{task.title}</span>
        </button>
        <span className="bb-task-when" title={new Date(task.createdAt).toLocaleString()}>{relativeTime(task.createdAt)}</span>
        <span className={`bb-task-pill ${task.status}`}>{STATUS_LABEL[task.status] ?? task.status}</span>
      </div>

      <div className="bb-task-progress">
        <div className="bb-task-bar">
          <div className="bb-task-bar-fill" style={{ width: total ? `${(done / total) * 100}%` : '0%' }} />
        </div>
        <span className="bb-task-count">{done} / {total || '…'}</span>
      </div>

      {task.status === 'failed' && task.lastError && <div className="bb-task-err">{task.lastError}</div>}

      {open && (
        <div className="bb-task-items">
          {items.length === 0 ? (
            <div className="bb-task-loading">Loading checklist…</div>
          ) : (
            items.map((it) => (
              <div className={`bb-task-item ${it.status}`} key={it.id}>
                <span className="bb-task-glyph">{ITEM_GLYPH[it.status]}</span>
                <span className="bb-task-label">{it.label}</span>
                {it.detail && (it.status === 'failed' || it.status === 'skipped') && (
                  <span className="bb-task-detail">{it.detail}</span>
                )}
              </div>
            ))
          )}
        </div>
      )}

      <div className="bb-task-actions">
        {/* The run transcript ("Watch") is intentionally NOT exposed here — the
            worker's thread is a superadmin/debug view, planned for later. */}
        {task.status === 'paused' && (
          <button
            className="bb-task-link"
            disabled={restart.isPending}
            onClick={() => restart.mutate(task.id)}
          >{restart.isPending ? 'Restarting…' : 'Restart'}</button>
        )}
        {isTaskActive(task.status) && (
          <button
            className="bb-task-link"
            disabled={cancel.isPending}
            onClick={async () => {
              const ok = await confirm({ title: `Stop “${task.title}”?`, message: 'Finished items stay done.', confirmLabel: 'Stop', danger: true })
              if (ok) cancel.mutate(task.id)
            }}
          >Stop</button>
        )}
        <button
          className="bb-task-link danger"
          onClick={async () => {
            const ok = await confirm({ title: `Delete “${task.title}”?`, message: 'Anything it already created stays.', confirmLabel: 'Delete', danger: true })
            if (ok) del.mutate(task.id)
          }}
        >Delete</button>
      </div>
    </div>
  )
}

export function TasksDrawerBody() {
  const { data: tasks = [], isLoading } = useTasks()
  return (
    <div className="bb-tasks">
      {isLoading ? (
        <p className="bb-tasks-empty">Loading…</p>
      ) : tasks.length === 0 ? (
        <p className="bb-tasks-empty">Nothing on the scratchpad. Youami puts work here when a job is too long for one reply, and clears it out when the job is done.</p>
      ) : (
        <div className="bb-tasks-list">
          {tasks.map((t) => <TaskCard key={t.id} task={t} />)}
        </div>
      )}
    </div>
  )
}
