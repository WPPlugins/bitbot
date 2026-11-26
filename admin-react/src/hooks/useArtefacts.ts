/**
 * Artefacts — documents the agent generated (rich self-contained HTML). The
 * list powers the Artefacts submenu; getArtefact pulls the full html for the
 * viewer drawer; delete removes one.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'

export interface ArtefactSummary {
  id: string
  conversationId: string | null
  title: string
  kind: string
  format: string
  createdAt: string
  /** Regeneration counter; >1 means this document was rebuilt. */
  version?: number
}
export interface ArtefactFull extends ArtefactSummary {
  html: string
}

const KEY = ['artefacts']

export function useArtefacts() {
  return useQuery({
    queryKey: KEY,
    queryFn: async (): Promise<ArtefactSummary[]> => {
      const res = await rpc<{ artefacts: ArtefactSummary[] }>('ListArtefacts')
      if (!res.success) throw new Error(res.error)
      return res.data.artefacts
    },
  })
}

export function useArtefact(id: string | null) {
  return useQuery({
    queryKey: ['artefact', id],
    enabled: !!id,
    queryFn: async (): Promise<ArtefactFull> => {
      const res = await rpc<{ artefact: ArtefactFull }>('GetArtefact', { id })
      if (!res.success) throw new Error(res.error)
      return res.data.artefact
    },
  })
}

export function useDeleteArtefact() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (id: string) => {
      const res = await rpc<{ ok: boolean }>('DeleteArtefact', { id })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  })
}
