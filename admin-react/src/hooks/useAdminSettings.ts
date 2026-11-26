/**
 * useAdminSettings — load + mutate the WP option set behind the
 * Feature 8 customisation surface, via REST endpoints registered in
 * bitbot.php / class-admin.php.
 *
 * Pattern:
 *   const { settings, isLoading, save, isSaving } = useAdminSettings()
 *   save({ widgetTitle: 'New title' })  // optimistic + toast
 *
 * Dev-mock fallback returns the default WP options when the apiKey is
 * the canonical dev token, so the SPA renders without a live REST
 * endpoint.
 */
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { restGet, restPost } from '@/api/rpc'
import { getConfig } from '@/lib/config'
import { toast } from '@/components/ui/Toast'
import type { AdminSettings } from '@/api/types'

const DEV_MOCK_KEY = 'bb_devmockapikey0000000000000000'

// Mutable so dev-mock saves actually persist between refetches —
// without this, optimistic updates flash and then revert when TanStack
// Query invalidates the cache.
const DEV_MOCK_SETTINGS: AdminSettings = {
  enabled: true,
  widgetTitle: 'Chat with us',
  widgetPosition: 'bottom-right',
  widgetOffsetX: 20,
  widgetOffsetY: 20,
  primaryColor: '#0073aa',
  welcomeMessage: 'Hi! How can I help you today?',
  customInstructions: '',
  placeholderText: 'Type your message...',
  fallbackMessage: "I'm not sure about that yet. Let me know how I can help.",
  leadCapturePrompt: 'Drop your email so we can follow up.',
  typingIndicatorText: 'Typing…',
  hideOnPages: '',
  mobileBehaviour: 'inherit',
  cornerRadius: 32,
  pillHeight: 28,
  widgetShape: 'rounded',
  suggestedQuestions: [
    { label: 'Pricing', message: 'What are your prices?' },
    { label: 'Services', message: 'What services do you offer?' },
    { label: 'Contact', message: 'How do I get in touch?' },
  ],
  avatarUrl: '',
  avatarTransform: '',
  nudge: '',
  autoOpen: '',
  pinnedMessage: '',
  offTopicGuardrails: '',
  notificationEmail: '',
}

const SETTINGS_KEY = ['admin-settings'] as const

/** Debounce window for the REST POST. UI stays optimistic so typing is
 *  instant; the server sync coalesces per-field. 500ms is long enough
 *  to fold a typed burst into one request but short enough that
 *  switching tabs / pages doesn't lose the last edit. */
const SAVE_DEBOUNCE_MS = 500

export function useAdminSettings() {
  const cfg = getConfig()
  const qc = useQueryClient()
  const isDev = cfg.apiKey === DEV_MOCK_KEY
  // Tracks the last successful save time so the SettingsCards header can
  // render a brief "Saved ✓" indicator. Empty until the first save lands.
  const [lastSavedAt, setLastSavedAt] = useState<number | null>(null)
  // Mirrors the debounce-buffer state so the indicator can show
  // "Saving…" the moment the user starts typing, not only after the
  // mutation actually fires. Toggled on `save()` + cleared after the
  // mutation completes or errors.
  const [isDebouncing, setIsDebouncing] = useState(false)

  const query = useQuery({
    queryKey: SETTINGS_KEY,
    queryFn: async (): Promise<AdminSettings> => {
      if (isDev) return DEV_MOCK_SETTINGS
      const res = await restGet<AdminSettings>('/admin-settings')
      if (!res.success) throw new Error(res.error)
      return res.data
    },
    staleTime: 60 * 1000,
  })

  const mutation = useMutation({
    mutationFn: async (patch: Partial<AdminSettings>) => {
      if (isDev) {
        await new Promise((r) => setTimeout(r, 250))
        Object.assign(DEV_MOCK_SETTINGS, patch)
        return { patch, translationError: undefined }
      }
      const res = await restPost<{ success: boolean; saved: string[]; translationError?: string }>(
        '/admin-settings',
        patch,
      )
      if (!res.success) throw new Error(res.error)
      return { patch, translationError: res.data.translationError }
    },
    onSuccess: (done) => {
      setLastSavedAt(Date.now())
      setIsDebouncing(false)
      if (!('patch' in done)) return
      if (done.translationError) toast.error('Translations could not be updated')
      // New base text means new translations on the server. Fetch them unless
      // the owner is already typing again (a refetch would undo that).
      const baseMoved = 'widgetTitle' in done.patch || 'welcomeMessage' in done.patch
      if (baseMoved && !timer.current) qc.invalidateQueries({ queryKey: SETTINGS_KEY })
    },
    onError: (err) => {
      // No previous-cache rollback — the optimistic update lives in
      // the local debounce buffer + query cache, and reverting on a
      // single failed POST would clobber the value the user is still
      // typing. Surface the error and let the next save retry.
      toast.error(err instanceof Error ? err.message : 'Save failed')
      setIsDebouncing(false)
    },
    // Settings save on every keystroke — no success toast (it'd pile
    // up). Errors still toast above.
  })

  // Debounce + coalesce server sync. The query cache is updated
  // OPTIMISTICALLY on every keystroke so the textarea stays responsive;
  // the actual POST coalesces all pending field changes into a single
  // request fired after the user stops typing for SAVE_DEBOUNCE_MS.
  //
  // Why this matters: without debounce, typing 50 chars fires 50
  // parallel POSTs on different PHP-FPM workers, finishing in
  // non-deterministic order — last-to-finish wins on the DB, which
  // isn't last-typed. Result: random characters dropped from the
  // final stored value.
  const pendingPatch = useRef<Partial<AdminSettings>>({})
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  // The last patch sent, so a failed save can be run again.
  const lastFlush = useRef<Partial<AdminSettings> | null>(null)

  const save = (patch: Partial<AdminSettings>) => {
    // Optimistic: merge into the query cache immediately so React
    // controlled inputs re-render with the latest value.
    const previous = qc.getQueryData<AdminSettings>(SETTINGS_KEY)
    if (previous) {
      qc.setQueryData<AdminSettings>(SETTINGS_KEY, { ...previous, ...patch })
    }
    // Buffer the patch into the debounce window.
    pendingPatch.current = { ...pendingPatch.current, ...patch }
    setIsDebouncing(true)
    if (timer.current) clearTimeout(timer.current)
    timer.current = setTimeout(() => {
      const flush = pendingPatch.current
      pendingPatch.current = {}
      timer.current = null
      if (Object.keys(flush).length > 0) {
        lastFlush.current = flush
        mutation.mutate(flush)
      }
    }, SAVE_DEBOUNCE_MS)
  }

  const retry = () => {
    if (lastFlush.current) mutation.mutate(lastFlush.current)
  }

  return {
    settings: query.data,
    isLoading: query.isLoading,
    isError: query.isError,
    error: query.error,
    save,
    isSaving: isDebouncing || mutation.isPending,
    /** The last save failed (cleared by the next save). */
    saveFailed: mutation.isError,
    /** Run the last failed save again. */
    retry,
    lastSavedAt,
  }
}

/**
 * Master kill-switch for the public widget. Flips the bitbot_enabled WP
 * option via the bitbot_set_widget_enabled ajax handler. The next public
 * page render reads the new value; the next admin SPA load picks up
 * widgetEnabled in bitbotAdminConfig and the Overview banner re-evaluates.
 *
 * Seeds initial state from getConfig().widgetEnabled — that's the value
 * PHP stamped when this page loaded. After a successful toggle we keep
 * the in-memory state in sync so the toggle UI reflects reality
 * without a full reload.
 */
export function useWidgetEnabledToggle() {
  const cfg = getConfig()
  const initial = (() => {
    const raw = cfg.widgetEnabled
    if (typeof raw === 'boolean') return raw
    if (typeof raw === 'string') return raw !== '' && raw !== '0'
    return true
  })()
  const [enabled, setEnabled] = useState<boolean>(initial)
  const [pending, setPending] = useState(false)

  const toggle = async (next: boolean) => {
    const prev = enabled
    setEnabled(next) // optimistic
    setPending(true)
    try {
      const body = new URLSearchParams({
        action: 'bitbot_set_widget_enabled',
        nonce: cfg.ajaxNonce,
        enabled: next ? '1' : '0',
      })
      const res = await fetch(cfg.ajaxUrl, {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
      })
      const json = (await res.json()) as { success: boolean; data?: { widgetEnabled: boolean }; error?: string }
      if (!json.success) throw new Error(json.error ?? 'Toggle failed')
      // Mirror the new value into bitbotAdminConfig so the Overview
      // banner re-derives correctly on the next render. The config
      // object is a runtime snapshot, not Suspense-tied, so a direct
      // write is fine here.
      ;(window as unknown as { bitbotAdminConfig?: { widgetEnabled?: boolean | string } }).bitbotAdminConfig &&
        ((window as unknown as { bitbotAdminConfig: { widgetEnabled: boolean } }).bitbotAdminConfig.widgetEnabled = next)
      toast.success(next ? 'Widget turned on' : 'Widget turned off')
    } catch (err) {
      setEnabled(prev) // rollback
      toast.error(err instanceof Error ? err.message : 'Toggle failed')
    } finally {
      setPending(false)
    }
  }

  return { enabled, pending, toggle }
}
