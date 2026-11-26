/**
 * usePagesActions — the write side of Knowledgebase → Indexed pages: turn a
 * page on/off, remove (keep out), resync / swap in, bulk turn off, and add
 * URLs. Toggle / remove go straight to the backend. Reading a page: a page of
 * your WordPress goes through WP AJAX (only the plugin can read its live
 * content); any other page is fetched by the backend (IndexUrlList).
 */
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { rpc, wpAjax } from '@/api/rpc'
import { getConfig } from '@/lib/config'
import { toast } from '@/components/ui/Toast'
import { PLAN_STATUS_KEY } from './usePlanStatus'
import type { IndexUrlRequest, IndexUrlResult } from '@/api/types'
import { PAGE_LIMIT_TOAST, addUrlToast, indexUrlOutcome, pageErrorKind, readPage } from '@/lib/pagesStatus'

const DEV_MOCK_KEY = 'bb_devmockapikey0000000000000000'
const wait = (ms: number) => new Promise((r) => setTimeout(r, ms))

/** source: the page's provenance (PageInfo.source); it decides who can read the page (see readPath). */
interface ResyncVars { url: string; replacePageId?: string; source?: string | null }

export function usePagesActions(opts: { onClearSelection: () => void; onAdded: () => void }) {
  const qc = useQueryClient()
  const isDev = getConfig().apiKey === DEV_MOCK_KEY

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['indexed-pages'] })
    qc.invalidateQueries({ queryKey: PLAN_STATUS_KEY })
  }

  // The plugin reads a page of your WordPress (ajax resync). Any other page (a sitemap URL, an external or manual URL) has no
  // WordPress post, so the backend fetches it (IndexUrlList with replacePageId: one transaction, the old page keeps
  // answering if the fetch or the write fails).
  const readViaPlugin = async (url: string, replacePageId?: string) => {
    const fields: Record<string, string> = { page_url: url }
    if (replacePageId) fields.replace_page_id = replacePageId
    await wpAjax('bitbot_resync_page', fields)
  }
  const readViaBackend = async (url: string, replacePageId?: string) => {
    const res = await rpc<IndexUrlResult>('IndexUrlList', { urls: [url], ...(replacePageId ? { replacePageId } : {}) } satisfies IndexUrlRequest)
    if (!res.success) throw new Error(res.error)
    const outcome = indexUrlOutcome(res.data)
    if (!outcome.ok) throw new Error(outcome.message)
  }

  const resyncPage = useMutation({
    mutationFn: async ({ url, replacePageId, source }: ResyncVars) => {
      if (isDev) { await wait(400); return }
      await readPage({ url, replacePageId, source }, { viaPlugin: readViaPlugin, viaBackend: readViaBackend })
    },
    onSuccess: (_d, vars) => {
      toast.success(vars.replacePageId ? 'Pages swapped' : 'Page resynced')
      refresh()
      qc.invalidateQueries({ queryKey: ['sync-status'] })
    },
    onError: (err) => toast.error(pageErrorKind(err) === 'limit' ? PAGE_LIMIT_TOAST : err instanceof Error ? err.message : 'Resync failed'),
  })

  const togglePage = useMutation({
    mutationFn: async ({ pageId, enabled }: { pageId: string; url: string; source?: string | null; enabled: boolean }) => {
      if (isDev) { await wait(200); return }
      const res = await rpc('TogglePage', { pageId, enabled })
      if (!res.success) throw new Error(res.error)
    },
    onSuccess: refresh,
    onError: (err, vars) => {
      const kind = pageErrorKind(err)
      if (kind === 'limit') toast.error(PAGE_LIMIT_TOAST)
      // The stored copy is gone or stale: read the page again instead.
      else if (kind === 'needs_sync') resyncPage.mutate({ url: vars.url, source: vars.source })
      else toast.error(err instanceof Error ? err.message : 'Toggle failed')
    },
  })

  // Several pages off at once: one TogglePage per page, errors counted not thrown.
  const turnOffMany = useMutation({
    mutationFn: async (pageIds: string[]): Promise<{ off: number; failed: number }> => {
      if (isDev) { await wait(300); return { off: pageIds.length, failed: 0 } }
      let off = 0
      let failed = 0
      for (const pageId of pageIds) {
        const res = await rpc('TogglePage', { pageId, enabled: false })
        if (res.success) off += 1; else failed += 1
      }
      return { off, failed }
    },
    onSuccess: (r) => {
      const msg = `Turned off ${r.off} page${r.off === 1 ? '' : 's'}`
      if (r.failed > 0) toast.error(`${msg}, ${r.failed} failed`); else toast.success(msg)
      opts.onClearSelection()
      refresh()
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Could not turn pages off'),
  })

  const removePage = useMutation({
    mutationFn: async (pageId: string) => {
      if (isDev) { await wait(200); return }
      const res = await rpc('RemovePage', { pageId })
      if (!res.success) throw new Error(res.error)
    },
    onSuccess: () => { toast.success('Page removed'); refresh() },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Remove failed'),
  })

  const removePages = useMutation({
    mutationFn: async (pageIds: string[]): Promise<{ removed: number }> => {
      if (isDev) { await wait(300); return { removed: pageIds.length } }
      const res = await rpc<{ removed: number }>('RemovePages', { pageIds })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: (r) => {
      toast.success(`Removed ${r.removed} page${r.removed === 1 ? '' : 's'}`)
      opts.onClearSelection()
      refresh()
      qc.invalidateQueries({ queryKey: ['sync-status'] })
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Bulk remove failed'),
  })

  const indexUrls = useMutation({
    mutationFn: async (urls: string[]): Promise<IndexUrlResult> => {
      if (isDev) {
        await wait(600)
        return { fetched: urls.length, embedded: urls.length, skippedUnchanged: 0, failed: 0, errors: [], sitemapQueued: 0 }
      }
      const res = await rpc<IndexUrlResult>('IndexUrlList', { urls })
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    onSuccess: (r) => {
      const ok = r.fetched + r.skippedUnchanged
      const leftOut = r.leftOut ?? 0
      const leftOutPart = leftOut > 0 ? ` ${leftOut} left out (page limit).` : ''
      // A pasted sitemap is expanded + crawled in the background, so its URLs
      // aren't in the synchronous counts — report the queued count separately.
      if (r.sitemapQueued > 0) {
        const pagePart = ok > 0 ? `Indexed ${ok} URL${ok === 1 ? '' : 's'}. ` : ''
        toast.success(`${pagePart}Sitemap added. Indexing ${r.sitemapQueued} pages in the background.${leftOutPart}`)
      } else {
        const msg = addUrlToast(r)
        const text = msg + (leftOutPart ? `.${leftOutPart}` : '')
        if (r.fetched === 0 && r.failed > 0) toast.error(text); else toast.success(text)
      }
      refresh()
      qc.invalidateQueries({ queryKey: ['sync-status'] })
      // A pasted sitemap kicks the server-side fill in the BACKGROUND, so the
      // sitemap_fill row may not read 'running' the instant this returns. Re-pull
      // sync-status a couple of times so the unified indexing state reliably
      // catches it without a manual refresh.
      if (r.sitemapQueued > 0) {
        [1500, 4000].forEach((ms) => setTimeout(() => qc.invalidateQueries({ queryKey: ['sync-status'] }), ms))
      }
      opts.onAdded()
    },
    onError: (err) => toast.error(err instanceof Error ? err.message : 'Could not index URLs'),
  })

  return { resyncPage, togglePage, turnOffMany, removePage, removePages, indexUrls }
}
