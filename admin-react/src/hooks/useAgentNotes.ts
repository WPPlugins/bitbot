/**
 * Agent standing directives — "what Youami remembers". The per-site rules the
 * admin agent captured (or the owner added) and applies to every future run.
 * Owner-facing view/add/delete so auto-captured rules stay prunable.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'

export type NoteCategory =
  | 'voice'
  | 'seo'
  | 'content'
  | 'design'
  | 'publishing'
  | 'off_limits'
  | 'general'

export interface AgentNote {
  id: string
  note: string
  category: NoteCategory
  source: 'agent' | 'owner'
  created_at: string
}

const KEY = ['agent-notes']

export function useAgentNotes() {
  return useQuery({
    queryKey: KEY,
    queryFn: async (): Promise<AgentNote[]> => {
      const res = await rpc<{ notes: AgentNote[] }>('GetAgentNotes')
      if (!res.success) throw new Error(res.error)
      return res.data.notes
    },
  })
}

export function useAddAgentNote() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (input: { note: string; category?: NoteCategory }) => {
      const res = await rpc<{ note: AgentNote }>('AddAgentNote', input)
      if (!res.success) throw new Error(res.error)
      return res.data.note
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  })
}

export function useDeleteAgentNote() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (id: string) => {
      const res = await rpc<{ deleted: boolean }>('DeleteAgentNote', { id })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  })
}
