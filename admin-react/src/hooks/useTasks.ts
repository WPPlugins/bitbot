/**
 * Background Tasks — one-shot, self-continuing agent jobs with a ticking
 * checklist. The agent spins a long batch (e.g. "write 8 posts") into a Task;
 * it runs itself past a single turn until every item is done. Backed by the
 * Pro+ RPCs (List/Get/Cancel/Delete). Polls while a task is active so the
 * checklist ticks live.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'
import { toast } from '@/components/ui/Toast'

export type TaskStatus = 'queued' | 'running' | 'done' | 'failed' | 'cancelled' | 'paused'
export type TaskItemStatus = 'pending' | 'in_progress' | 'done' | 'failed' | 'skipped'

export interface AgentTask {
  id: string
  title: string
  goal: string
  status: TaskStatus
  runCount: number
  conversationId: string | null
  originConversationId: string | null
  lastError: string | null
  createdAt: string
  finishedAt: string | null
}

export interface AgentTaskItem {
  id: string
  label: string
  status: TaskItemStatus
  detail: string | null
  order: number
}

const KEY = ['tasks']
export const isTaskActive = (s: TaskStatus) => s === 'running' || s === 'queued'

export function useTasks() {
  return useQuery({
    queryKey: KEY,
    queryFn: async (): Promise<AgentTask[]> => {
      const res = await rpc<{ tasks: AgentTask[] }>('ListTasks')
      if (!res.success) throw new Error(res.error)
      return res.data.tasks
    },
    // Keep the list live: poll fast while a task is working, and keep a slow
    // heartbeat otherwise so a task the agent spins up from CHAT (which doesn't
    // invalidate this query) appears here within a few seconds without a reopen.
    refetchInterval: (query) => {
      const data = query.state.data as AgentTask[] | undefined
      return data?.some((t) => isTaskActive(t.status)) ? 4000 : 12000
    },
  })
}

export function useTask(taskId: string | null) {
  return useQuery({
    queryKey: ['task', taskId],
    enabled: !!taskId,
    queryFn: async (): Promise<{ task: AgentTask; items: AgentTaskItem[] }> => {
      const res = await rpc<{ task: AgentTask; items: AgentTaskItem[] }>('GetTask', { taskId })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    // Poll the checklist while the task is running so items tick live.
    refetchInterval: (query) => {
      const data = query.state.data as { task: AgentTask } | undefined
      return data && isTaskActive(data.task.status) ? 3000 : false
    },
  })
}

export function useCancelTask() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (taskId: string) => {
      const res = await rpc<{ ok: boolean }>('CancelTask', { taskId })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  })
}

export function useDeleteTask() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (taskId: string) => {
      const res = await rpc<{ ok: boolean }>('DeleteTask', { taskId })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  })
}


export function useRestartTask() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (taskId: string) => {
      const res = await rpc<{ ok: boolean }>('RestartTask', { taskId })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => {
      toast.success('Task restarted. It picks up on the next run.')
      qc.invalidateQueries({ queryKey: KEY })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Could not restart the task.'),
  })
}
