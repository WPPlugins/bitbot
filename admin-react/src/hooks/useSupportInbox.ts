/**
 * AI Support Inbox setup hooks (Agent / Pro+). The inbox address, the
 * auto-send setting, and optional branded sending (reply from the owner's own
 * domain). Threads live in the Conversations tab (channel = email).
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { rpc } from '@/api/rpc'

export interface ReplyDomainRecord {
  record?: string
  type: string
  name: string
  value: string
  priority?: number
  ttl?: string | number
  status?: string
}

export type ReplyDomainStatus = 'none' | 'pending' | 'verified' | 'failed'

export type SmtpStatus = 'none' | 'connected'

export interface SupportInboxInfo {
  address: string
  autoSend: boolean
  replyDomain: string | null
  replyDomainStatus: ReplyDomainStatus
  replyDomainRecords: ReplyDomainRecord[]
  smtpStatus: SmtpStatus
  smtpFrom: string | null
}

export interface SmtpSetupInput {
  host?: string // omitted → backend auto-detects from the email domain (MX)
  port?: number
  secure?: boolean
  user: string
  pass: string
  fromEmail?: string
}

export function useSupportInbox() {
  return useQuery({
    queryKey: ['support-inbox'],
    queryFn: async (): Promise<SupportInboxInfo> => {
      const res = await rpc<SupportInboxInfo>('EnsureSupportInbox')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    staleTime: 5 * 60 * 1000,
  })
}

export function useSetSupportAutoSend() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (autoSend: boolean): Promise<void> => {
      const res = await rpc<{ autoSend: boolean }>('SetSupportAutoSend', { autoSend })
      if (!res.success) throw new Error(res.error)
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['support-inbox'] }),
  })
}

interface ReplyDomainResult {
  domain: string | null
  status: ReplyDomainStatus
  records: ReplyDomainRecord[]
}

export function useSetupReplyDomain() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (domain: string): Promise<ReplyDomainResult> => {
      const res = await rpc<ReplyDomainResult>('SetupSupportReplyDomain', { domain })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['support-inbox'] }),
  })
}

export function useVerifyReplyDomain() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (): Promise<ReplyDomainResult> => {
      const res = await rpc<ReplyDomainResult>('VerifySupportReplyDomain')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['support-inbox'] }),
  })
}

export function useRemoveReplyDomain() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (): Promise<void> => {
      const res = await rpc<{ status: string }>('RemoveSupportReplyDomain')
      if (!res.success) throw new Error(res.error)
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['support-inbox'] }),
  })
}

export function useSetupSmtp() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (cfg: SmtpSetupInput): Promise<{ status: SmtpStatus; fromEmail: string }> => {
      const res = await rpc<{ status: SmtpStatus; fromEmail: string }>('SetupSupportSmtp', cfg as unknown as Record<string, unknown>)
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['support-inbox'] }),
  })
}

export function useRemoveSmtp() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (): Promise<void> => {
      const res = await rpc<{ status: string }>('RemoveSupportSmtp')
      if (!res.success) throw new Error(res.error)
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['support-inbox'] }),
  })
}
