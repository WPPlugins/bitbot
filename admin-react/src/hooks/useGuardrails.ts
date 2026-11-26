/**
 * Guardrails — the site's HARD compliance constraints, each ideally carrying
 * the online source it was distilled from (affiliate program terms, regulator
 * guidance). The agent populates them by fetching live terms; every outward
 * agent action is checked against them before it executes. Owner can view,
 * add, and retire.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'

export type GuardrailCategory = 'affiliate' | 'legal' | 'platform' | 'brand' | 'general'

export interface Guardrail {
  id: string
  rule: string
  source_url: string | null
  program: string | null
  category: GuardrailCategory
  checked_at: string | null
  source: 'agent' | 'owner'
  created_at: string
}

const KEY = ['guardrails']

export function useGuardrails() {
  return useQuery({
    queryKey: KEY,
    queryFn: async (): Promise<Guardrail[]> => {
      const res = await rpc<{ guardrails: Guardrail[] }>('GetGuardrails')
      if (!res.success) throw new Error(res.error)
      return res.data.guardrails
    },
  })
}

export function useAddGuardrail() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (input: {
      rule: string
      sourceUrl?: string
      program?: string
      category?: GuardrailCategory
    }) => {
      const res = await rpc<{ guardrail: Guardrail }>('AddGuardrail', input)
      if (!res.success) throw new Error(res.error)
      return res.data.guardrail
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  })
}

export function useDeleteGuardrail() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (id: string) => {
      const res = await rpc<{ deleted: boolean }>('DeleteGuardrail', { id })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  })
}
