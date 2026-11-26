/**
 * Form-related hooks. Powers the Forms tab (list, builder, AI generate,
 * delete, enable toggle) and the inline embed/shortcode helper.
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'
import { getConfig } from '@/lib/config'
import { toast } from '@/components/ui/Toast'
import { postWpAjax } from '@/lib/wpAjax'
import type { FormInfo, FormFieldSchema } from '@/api/types'

const DEV_MOCK_KEY = 'bb_devmockapikey0000000000000000'

const days = (n: number) => new Date(Date.now() - n * 86_400_000).toISOString()

const MOCK_FORMS: FormInfo[] = [
  {
    id: 'f-default', name: 'Pre-chat form',
    description: 'Captures visitor details before their first reply.',
    enabled: true, isDefault: true, chatGateEnabled: false, submissionsCount: 0, lastSubmissionAt: null, createdAt: days(60),
    fields: [
      { name: 'name',  label: 'Full name', type: 'text',  required: true,  placeholder: 'Your name' },
      { name: 'email', label: 'Email',     type: 'email', required: true,  placeholder: 'you@example.com' },
      { name: 'phone', label: 'Phone',     type: 'phone', required: false, placeholder: '+1 (555) 000-0000' },
    ],
  },
  {
    id: 'f-1', name: 'Pedir Orçamento',
    description: 'Quote request — captures event details for corporate enquiries.',
    enabled: true, isDefault: false, chatGateEnabled: false, submissionsCount: 0, lastSubmissionAt: null, createdAt: days(30),
    fields: [
      { name: 'name',      label: 'Your name',  type: 'text',     required: true,  placeholder: '' },
      { name: 'email',     label: 'Email',      type: 'email',    required: true,  placeholder: '' },
      { name: 'event_type', label: 'Event type', type: 'select',  required: true,  options: ['Corporate', 'Wedding', 'Birthday', 'Other'] },
      { name: 'guest_count', label: 'Guest count', type: 'text',  required: false, placeholder: 'Approximate' },
      { name: 'date',      label: 'Preferred date', type: 'text', required: false, placeholder: '' },
      { name: 'budget',    label: 'Budget range', type: 'select', required: false, options: ['<5k BRL', '5-15k BRL', '15-25k BRL', '25k+ BRL'] },
      { name: 'message',   label: 'Anything else?', type: 'textarea', required: false, placeholder: '' },
    ],
  },
  {
    id: 'f-2', name: 'Newsletter',
    description: 'Two-field signup for the monthly events newsletter.',
    enabled: true, isDefault: false, chatGateEnabled: false, submissionsCount: 0, lastSubmissionAt: null, createdAt: days(12),
    fields: [
      { name: 'name',  label: 'First name', type: 'text',  required: true },
      { name: 'email', label: 'Email',      type: 'email', required: true },
    ],
  },
  {
    id: 'f-3', name: 'Partner enquiry',
    description: 'For other event vendors who want to refer business.',
    enabled: false, isDefault: false, chatGateEnabled: false, submissionsCount: 0, lastSubmissionAt: null, createdAt: days(45),
    fields: [
      { name: 'name',     label: 'Your name',      type: 'text',     required: true },
      { name: 'email',    label: 'Email',          type: 'email',    required: true },
      { name: 'company',  label: 'Company',        type: 'text',     required: true },
      { name: 'services', label: 'Your services',  type: 'checkbox', required: false,
        options: ['Photography', 'Catering', 'Music', 'Decor', 'Venue'] },
      { name: 'pitch',    label: 'Why us?',        type: 'textarea', required: false },
    ],
  },
]

export function useForms() {
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useQuery({
    queryKey: ['forms'],
    queryFn: async (): Promise<FormInfo[]> => {
      if (isDev) return MOCK_FORMS
      const res = await rpc<{ forms: FormInfo[] }>('GetForms')
      if (!res.success) throw new Error(res.error)
      return res.data.forms
    },
    // 15s: the Forms list shows per-form "new submission" dots derived
    // from lastSubmissionAt. Long stale windows mean the dot stays
    // dark after the user submits a test form. Refetch on focus also.
    staleTime: 15_000,
    refetchOnWindowFocus: true,
  })
}

export function useDeleteForm() {
  const qc = useQueryClient()
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useMutation({
    mutationFn: async (formId: string) => {
      if (isDev) {
        const idx = MOCK_FORMS.findIndex((f) => f.id === formId)
        if (idx >= 0) MOCK_FORMS.splice(idx, 1)
        return { success: true }
      }
      const res = await rpc<{ success: boolean }>('DeleteForm', { formId })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => {
      toast.success('Form deleted')
      qc.invalidateQueries({ queryKey: ['forms'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Delete failed'),
  })
}

interface UpdateFormInput {
  formId: string
  name: string
  fields: FormFieldSchema[]
  successMessage?: string
}

/**
 * Save edits to a form's name + fields via the `bitbot_update_form` WP ajax. Returns the updated FormInfo so the modal can swap to display
 * mode after a successful save.
 */
export function useUpdateForm() {
  const qc = useQueryClient()
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useMutation({
    // Paint the edit at once; roll back if the save (or the plugin's schema
    // cache refresh for the chat gate) fails.
    onMutate: async (input: UpdateFormInput) => {
      await qc.cancelQueries({ queryKey: ['forms'] })
      const previous = qc.getQueryData<FormInfo[]>(['forms'])
      qc.setQueryData<FormInfo[]>(['forms'], (old) =>
        (old ?? []).map((f) => (f.id === input.formId ? { ...f, name: input.name, fields: input.fields } : f)),
      )
      return { previous }
    },
    mutationFn: async (input: UpdateFormInput): Promise<FormInfo> => {
      if (isDev) {
        const idx = MOCK_FORMS.findIndex((f) => f.id === input.formId)
        if (idx >= 0) {
          MOCK_FORMS[idx] = { ...MOCK_FORMS[idx], name: input.name, fields: input.fields }
          return MOCK_FORMS[idx]
        }
        throw new Error('Form not found')
      }
      // Through the plugin (not the RPC directly) so PHP refreshes the cached
      // pre-chat schema the public widget renders from.
      return postWpAjax<FormInfo>(
        'bitbot_update_form',
        {
          form_id: input.formId,
          name: input.name,
          fields: JSON.stringify(input.fields),
          success_message: input.successMessage,
        },
        cfg,
        'Save failed',
      )
    },
    onSuccess: () => {
      toast.success('Form saved')
      qc.invalidateQueries({ queryKey: ['forms'] })
    },
    onError: (err, _input, ctx) => {
      if (ctx?.previous) qc.setQueryData(['forms'], ctx.previous)
      toast.error(err instanceof Error ? err.message : 'Save failed')
    },
  })
}

/**
 * "Accept submissions": the form's own enabled flag. It never touches the
 * pre-chat gate (that is useSetChatGate), so the owner's gate choice is
 * never overridden. Optimistic, rolled back with a toast on failure.
 */
export function useToggleFormEnabled() {
  const qc = useQueryClient()
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useMutation({
    onMutate: async ({ formId, enabled }: { formId: string; enabled: boolean }) => {
      await qc.cancelQueries({ queryKey: ['forms'] })
      const previous = qc.getQueryData<FormInfo[]>(['forms'])
      qc.setQueryData<FormInfo[]>(['forms'], (old) =>
        (old ?? []).map((f) => (f.id === formId ? { ...f, enabled } : f)),
      )
      return { previous }
    },
    mutationFn: async ({ formId, enabled }: { formId: string; enabled: boolean }) => {
      if (isDev) {
        const idx = MOCK_FORMS.findIndex((f) => f.id === formId)
        if (idx >= 0) MOCK_FORMS[idx] = { ...MOCK_FORMS[idx], enabled }
        return MOCK_FORMS[idx]
      }
      const res = await rpc<FormInfo>('UpdateForm', { formId, enabled })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: (_data, { enabled }) => {
      toast.success(enabled ? 'Form accepting submissions' : 'Form stopped accepting submissions')
    },
    onError: (err, _vars, ctx) => {
      if (ctx?.previous) qc.setQueryData(['forms'], ctx.previous)
      toast.error(err instanceof Error ? err.message : 'Could not change the form')
    },
  })
}

/**
 * Create a blank form with two starter fields (Name + Email) — the
 * "Start blank" path on the Forms tab. Reuses the existing CreateForm
 * RPC the AI panel uses.
 */
export function useCreateForm() {
  const qc = useQueryClient()
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useMutation({
    mutationFn: async (input: { name: string; description: string; fields: FormFieldSchema[] }): Promise<FormInfo> => {
      if (isDev) {
        const created: FormInfo = {
          id: 'f-' + Date.now(),
          name: input.name,
          description: input.description,
          fields: input.fields,
          enabled: true,
          isDefault: false,
          chatGateEnabled: false,
          submissionsCount: 0,
          lastSubmissionAt: null,
          createdAt: new Date().toISOString(),
        }
        MOCK_FORMS.push(created)
        return created
      }
      const res = await rpc<FormInfo>('CreateForm', input)
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => {
      toast.success('Form created')
      qc.invalidateQueries({ queryKey: ['forms'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Create failed'),
  })
}

/**
 * Toggle the chat-gate on the default pre-chat form. Routes through the
 * `bitbot_set_chat_gate` WP ajax handler (NOT direct RPC) so PHP can
 * cache the formId + enabled flag locally for the public widget config.
 */
export function useSetChatGate() {
  const qc = useQueryClient()
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useMutation({
    // The gate flag lives on the default form (GetForms.chatGateEnabled), the
    // one source Chatbot > Widget and Forms both read. Paint it at once and
    // roll back if the save fails.
    onMutate: async (enabled: boolean) => {
      await qc.cancelQueries({ queryKey: ['forms'] })
      const previous = qc.getQueryData<FormInfo[]>(['forms'])
      qc.setQueryData<FormInfo[]>(['forms'], (old) =>
        (old ?? []).map((f) => (f.isDefault ? { ...f, chatGateEnabled: enabled } : f)),
      )
      return { previous }
    },
    mutationFn: async (enabled: boolean): Promise<{ chatGateEnabled: boolean; formId: string }> => {
      if (isDev) {
        const def = MOCK_FORMS.find((f) => f.isDefault)
        if (def) def.chatGateEnabled = enabled
        return { chatGateEnabled: enabled, formId: def?.id ?? '' }
      }
      return postWpAjax<{ chatGateEnabled: boolean; formId: string }>(
        'bitbot_set_chat_gate',
        { enabled: enabled ? '1' : '0' },
        cfg,
        'Toggle failed',
      )
    },
    onSuccess: (data) => {
      toast.success(data.chatGateEnabled ? 'Pre-chat form turned on' : 'Pre-chat form turned off')
      qc.invalidateQueries({ queryKey: ['forms'] })
    },
    onError: (err, _enabled, ctx) => {
      if (ctx?.previous) qc.setQueryData(['forms'], ctx.previous)
      toast.error(err instanceof Error ? err.message : 'Could not change the pre-chat form')
    },
  })
}

export function useGenerateForm() {
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useMutation({
    mutationFn: async (description: string): Promise<{ name: string; fields: FormFieldSchema[] }> => {
      if (isDev) {
        await new Promise((r) => setTimeout(r, 1800))
        // Stupid-but-realistic: turn the description into a 3-field form.
        return {
          name: 'AI-generated form',
          fields: [
            { name: 'name',    label: 'Your name', type: 'text',  required: true },
            { name: 'email',   label: 'Email',     type: 'email', required: true },
            { name: 'message', label: 'Message',   type: 'textarea', required: false,
              placeholder: description.slice(0, 80) },
          ],
        }
      }
      const res = await rpc<{ name: string; fields: FormFieldSchema[] }>('GenerateForm', { description })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => toast.success('Form generated'),
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Generation failed'),
  })
}

/**
 * Site-relevant, localized example form ideas for the Add-a-form modal
 * placeholder. The backend classifies the site from its own indexed
 * content and caches the result, so this is cheap to call whenever the
 * modal opens (gated by `enabled`). Empty array → the modal keeps its
 * neutral static example.
 */
export function useFormIdeas(enabled: boolean) {
  const cfg = getConfig()
  const isDev = cfg.apiKey === DEV_MOCK_KEY

  return useQuery({
    queryKey: ['form-ideas'],
    enabled,
    queryFn: async (): Promise<string[]> => {
      if (isDev) {
        return [
          'Wedding venue tour booking — date, guest count, preferred time.',
          'Catering enquiry — event type, headcount, dietary needs.',
        ]
      }
      const res = await rpc<{ examples: string[] }>('GetFormIdeas')
      if (!res.success) throw new Error(res.error)
      return res.data.examples
    },
    // Backend caches for ~30 days; no need to refetch within a session.
    staleTime: 60 * 60 * 1000,
    refetchOnWindowFocus: false,
    retry: false,
  })
}
