/**
 * Scheduled tasks — admin Agent tasks that run Hourly/Daily/Weekly server-side.
 * Backed by the Pro+ RPCs (List/Create/Update/Delete/RunNow/GetRuns). Each run
 * produces a continuable admin conversation; run history lists those.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'

export type Frequency = 'hourly' | 'daily' | 'weekly'

export interface ScheduledTask {
  id: string
  name: string
  description: string
  prompt: string
  frequency: Frequency
  runTime: string
  weekday: number | null
  timezone: string
  enabled: boolean
  nextRunAt: string | null
  lastRunAt: string | null
  createdAt: string
  blueprint: string | null
}

export interface ScheduledTaskRun {
  id: string
  conversationId: string | null
  status: 'running' | 'success' | 'failed' | 'skipped'
  resultSummary: string | null
  skipReason: string | null
  error: string | null
  startedAt: string
  finishedAt: string | null
}

export interface CreateTaskInput {
  name: string
  description: string
  prompt: string
  frequency: Frequency
  runTime: string
  weekday: number | null
  timezone: string
}

const KEY = ['scheduled-tasks']

export function useScheduledTasks() {
  return useQuery({
    queryKey: KEY,
    queryFn: async (): Promise<ScheduledTask[]> => {
      const res = await rpc<{ tasks: ScheduledTask[] }>('ListScheduledTasks')
      if (!res.success) throw new Error(res.error)
      return res.data.tasks
    },
    // The blueprint is learned + saved after the first run completes. Poll while
    // any task still lacks one so it appears automatically once run #1 finishes;
    // stop once every task has a blueprint.
    refetchInterval: (query) => {
      const data = query.state.data as ScheduledTask[] | undefined
      const pending = !!data?.some((t) => !t.blueprint || !t.blueprint.trim())
      return pending ? 4000 : false
    },
  })
}

export function useCreateScheduledTask() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (input: CreateTaskInput) => {
      const res = await rpc<{ task: ScheduledTask }>('CreateScheduledTask', input as unknown as Record<string, unknown>)
      if (!res.success) throw new Error(res.error)
      return res.data.task
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  })
}

export function useUpdateScheduledTask() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (patch: { taskId: string } & Partial<CreateTaskInput> & { enabled?: boolean; blueprint?: string }) => {
      const res = await rpc<{ task: ScheduledTask }>('UpdateScheduledTask', patch as unknown as Record<string, unknown>)
      if (!res.success) throw new Error(res.error)
      return res.data.task
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  })
}

export function useDeleteScheduledTask() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (taskId: string) => {
      const res = await rpc<{ ok: boolean }>('DeleteScheduledTask', { taskId })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  })
}

export function useDeleteScheduledTaskRun(taskId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (runId: string) => {
      const res = await rpc<{ ok: boolean }>('DeleteScheduledTaskRun', { taskId, runId })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['scheduled-task-runs', taskId] })
      qc.invalidateQueries({ queryKey: KEY })
    },
  })
}

export function useRunScheduledTaskNow() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (taskId: string) => {
      const res = await rpc<{ conversationId: string }>('RunScheduledTaskNow', { taskId })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: (_d, taskId) => {
      qc.invalidateQueries({ queryKey: KEY })
      qc.invalidateQueries({ queryKey: ['scheduled-task-runs', taskId] })
    },
  })
}

export function useScheduledTaskRuns(taskId: string | null) {
  return useQuery({
    queryKey: ['scheduled-task-runs', taskId],
    enabled: !!taskId,
    queryFn: async (): Promise<ScheduledTaskRun[]> => {
      const res = await rpc<{ runs: ScheduledTaskRun[] }>('GetScheduledTaskRuns', { taskId })
      if (!res.success) throw new Error(res.error)
      return res.data.runs
    },
    // runNow returns immediately and the agent loop finishes in the background;
    // poll while any run is still 'running' so it flips to Completed live, then stop.
    refetchInterval: (query) => {
      const data = query.state.data as ScheduledTaskRun[] | undefined
      return data?.some((r) => r.status === 'running') ? 4000 : false
    },
  })
}
