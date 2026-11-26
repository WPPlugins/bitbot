/**
 * useGatherContinue: nudges a stalled render pass via `bitbot_gather_continue`.
 * Shared by every sync surface that shows the "Stalled, continue" state.
 */
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { wpAjax } from '@/api/rpc'
import { toast } from '@/components/ui/Toast'

export function useGatherContinue() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async () => (await wpAjax('bitbot_gather_continue')) ?? {},
    onSuccess: () => qc.invalidateQueries({ queryKey: ['sync-progress'] }),
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Could not continue'),
  })
}
