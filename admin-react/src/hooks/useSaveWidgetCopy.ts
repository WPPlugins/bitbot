/**
 * Writes to one language's widget copy row through the existing
 * /admin-settings route: save fields (the row becomes the owner's), mark it
 * reviewed (an empty row object), or ask the server to retranslate it.
 */
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { restPost } from '@/api/rpc'
import { getConfig } from '@/lib/config'
import { toast } from '@/components/ui/Toast'
import { copyPatch, regeneratePatch, type CopyFields } from '@/lib/widgetCopy'

const DEV_MOCK_KEY = 'bb_devmockapikey0000000000000000'

interface SettingsReply {
  success: boolean
  translationError?: string
}

function useCopyMutation<V>(
  body: (v: V) => object,
  onDone: (reply: SettingsReply | null) => void,
  failure: string,
) {
  const qc = useQueryClient()
  const isDev = getConfig().apiKey === DEV_MOCK_KEY
  return useMutation({
    mutationFn: async (v: V): Promise<SettingsReply | null> => {
      if (isDev) return null
      const res = await restPost<SettingsReply>('/admin-settings', body(v))
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: (reply) => {
      qc.invalidateQueries({ queryKey: ['admin-settings'] })
      onDone(reply)
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : failure),
  })
}

export function useSaveWidgetCopy() {
  return useCopyMutation<{ code: string; fields: CopyFields }>(
    (v) => copyPatch(v.code, v.fields),
    () => undefined,
    'Could not save',
  )
}

export function useMarkReviewed() {
  return useCopyMutation<string>(
    (code) => copyPatch(code, {}),
    () => toast.success('Marked reviewed'),
    'Could not mark reviewed',
  )
}

export function useRegenerateCopy() {
  return useCopyMutation<string>(
    (code) => regeneratePatch(code),
    (reply) => {
      if (reply?.translationError) toast.error(reply.translationError)
      else toast.success('Translation updated')
    },
    'Could not update the translation',
  )
}
