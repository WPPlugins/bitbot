/**
 * useSyncStop: stops a running background sync via `bitbot_sync_stop`.
 * Pages already read stay available. Shared by every sync surface.
 */
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { wpAjax } from '@/api/rpc'
import { toast } from '@/components/ui/Toast'

export function useSyncStop() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async () => (await wpAjax('bitbot_sync_stop')) ?? {},
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['sync-progress'] })
      qc.invalidateQueries({ queryKey: ['sync-status'] })
      qc.invalidateQueries({ queryKey: ['indexed-pages'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Could not stop the sync'),
  })
}
