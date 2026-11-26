/**
 * Operator-pushed dashboard announcements. GetAnnouncements returns the
 * active, plan/site-matched, not-yet-dismissed announcements for this site;
 * dismissing one persists server-side so it never shows again.
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'
import { getConfig } from '@/lib/config'
import type { Announcement } from '@/api/types'

const DEV_MOCK_KEY = 'bb_devmockapikey0000000000000000'

export function useAnnouncements() {
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useQuery({
    queryKey: ['announcements'],
    queryFn: async (): Promise<Announcement[]> => {
      if (isDev) return []
      const res = await rpc<{ announcements: Announcement[] }>('GetAnnouncements')
      if (!res.success) return []
      return res.data.announcements ?? []
    },
    staleTime: 5 * 60 * 1000,
  })
}

export function useDismissAnnouncement() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (announcementId: string): Promise<void> => {
      const res = await rpc<{ success: true }>('DismissAnnouncement', { announcementId })
      if (!res.success) throw new Error(res.error)
    },
    // Optimistically drop it from the list so the banner closes instantly.
    onMutate: async (announcementId) => {
      await qc.cancelQueries({ queryKey: ['announcements'] })
      const prev = qc.getQueryData<Announcement[]>(['announcements'])
      qc.setQueryData<Announcement[]>(['announcements'], (cur) =>
        (cur ?? []).filter((a) => a.id !== announcementId),
      )
      return { prev }
    },
    onError: (_err, _id, ctx) => {
      if (ctx?.prev) qc.setQueryData(['announcements'], ctx.prev)
    },
    onSettled: () => {
      qc.invalidateQueries({ queryKey: ['announcements'] })
    },
  })
}
