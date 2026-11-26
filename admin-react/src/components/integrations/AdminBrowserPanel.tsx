import { forwardRef, useEffect, useLayoutEffect, useImperativeHandle, useRef, useState } from 'react'
import { rpc } from '@/api/rpc'
import { AdminSightRequired, observeAdminSight, requireSharingForWrite } from './adminBrowserSight'
import type { SightCapture } from './previewSight'
import { afterPaint } from './afterPaint'
import { needsWriteCard, type WriteMode } from './adminWriteMode'
import { APPROVAL_HEARTBEAT_MS, approvalCeiling, unapprovedResult, waitForOwnerDecision, type OwnerDecision } from './adminApproval'
import { commandChangesSite, AdminBrowserController, AdminBrowserError, AdminPermissions, adminFailureResult, parseAdminCommand, type AdminCommand } from './adminBrowserController'

async function send(payload: Record<string, unknown>) {
  const response = await rpc('SubmitPreviewResult', payload)
  if (!response.success) throw new Error(response.error)
}
export type AdminBrowserHandle = { handle: (raw: Record<string, unknown>) => void }
/** Inline write decisions; the admin frame lives in the shared preview dock. */
export const AdminBrowserPanel = forwardRef<AdminBrowserHandle, { frame: React.RefObject<HTMLIFrameElement | null>; onActivate: () => void; capture: () => Promise<SightCapture>; sightOn: boolean; sightLive: () => boolean; adminBase: string; conversationId: string | null; browserSessionId: string; mode: WriteMode; onPermissionChange: () => void; onPendingChange?: (pending: boolean) => void; onCleared?: () => void }>(function AdminBrowserPanel({ frame, onActivate, capture, sightOn, sightLive, adminBase, conversationId, browserSessionId, mode, onPermissionChange, onPendingChange, onCleared }, ref) {
  const browserId = useRef(browserSessionId)
  const controller = useRef<AdminBrowserController | null>(null)
  const permissions = useRef(new AdminPermissions())
  const abort = useRef<AbortController | null>(null)
  const activeNeedsSight = useRef(false)
  const seen = useRef(new Set<string>())
  const chain = useRef(Promise.resolve())
  const [open, setOpen] = useState(false)
  const captureRef = useRef(capture); captureRef.current = capture
  const sightLiveRef = useRef(sightLive); sightLiveRef.current = sightLive
  const modeRef = useRef(mode)
  modeRef.current = mode
  const notify = useRef(onPermissionChange); notify.current = onPermissionChange
  const [pending, setPending] = useState<{ action: string; path?: string; ref?: string; value?: string; checked?: boolean; tool?: string; args?: unknown } | null>(null)
  const answer = useRef<((decision: OwnerDecision) => void) | null>(null)
  // The preview dock steps aside when an Allow/Deny card would sit under it.
  const pendingNotify = useRef(onPendingChange); pendingNotify.current = onPendingChange
  useEffect(() => { pendingNotify.current?.(!!pending) }, [pending])
  const [status, setStatus] = useState('')
  // A new chat or site clears grants and pending commands. The owner's Manual /
  // Auto choice is theirs and is never changed here (adminWriteMode.ts).
  const reset = () => {
    permissions.current.reset(); abort.current?.abort(); answer.current?.('cancelled'); answer.current = null
    controller.current = null; setPending(null)
    if (frame.current) frame.current.src = 'about:blank'
    // The admin screen is now a blank page; hand the dock back to the site.
    onCleared?.()
    setOpen(false)
    setStatus('')
  }
  useLayoutEffect(() => { reset(); return () => { permissions.current.reset(); abort.current?.abort(); answer.current?.('cancelled') } }, [conversationId, adminBase]) // grants never cross chats
  useLayoutEffect(() => {
    if (!sightOn && activeNeedsSight.current) {
      abort.current?.abort()
      answer.current?.('cancelled')
      controller.current?.invalidateRefs()
    }
  }, [sightOn])
  const grant = () => { answer.current?.('allow') }
  useLayoutEffect(() => { if (mode === 'auto') answer.current?.('allow') }, [mode])
  useImperativeHandle(ref, () => ({ handle(raw) {
    const requestId = String(raw.requestId || '')
    if (raw.browserSessionId !== browserSessionId) return
    if (raw.mode !== 'write-approval' && raw.action !== 'connect' && raw.browserId !== browserId.current) return
    const cid = String(raw.conversationId || '')
    if (!requestId || cid !== conversationId || seen.current.has(requestId) || typeof raw.emittedAt !== 'number' || Math.abs(Date.now() - raw.emittedAt) > 5000) return
    seen.current.add(requestId)
    const epoch = permissions.current.epoch
    const acknowledged = send({ conversationId: cid, requestId, phase: 'ack' }).then(() => {
      if (Date.now() - Number(raw.emittedAt) > 5000) throw new Error('Acknowledgement expired; no action ran')
    })
    chain.current = chain.current.catch(() => undefined).then(async () => {
      await acknowledged
      if (!navigator.locks) {
        await send( { conversationId: cid, requestId, phase: 'final', result: { ok: false, notRun: true, error: 'This browser lacks Web Locks; admin actions are disabled to prevent duplicate execution across tabs.' } })
        return
      }
      await navigator.locks.request(`bitbot-admin-${requestId}`, { ifAvailable: true }, async lock => {
      if (!lock) return // Another tab owns this request, including its approval prompt.
      const receiptKey = 'bitbot-admin-command-receipts'
      // Only IDs/timestamps are stored, never grants, fields or page contents.
      const receipts = JSON.parse(localStorage.getItem(receiptKey) || '{}') as Record<string, number>
      if (receipts[requestId]) return
      for (const [id, time] of Object.entries(receipts)) if (Date.now() - time > 300000) delete receipts[id]
      receipts[requestId] = Date.now()
      localStorage.setItem(receiptKey, JSON.stringify(receipts))
      let dispatched = false
      let unapproved: Record<string, unknown> | null = null
      // Keep the command visibly alive for the backend while this tab holds it,
      // including while the owner reads the card (adminApproval.ts).
      const heartbeat = setInterval(() => { send({ conversationId: cid, requestId, phase: 'ack' }).catch(() => undefined) }, APPROVAL_HEARTBEAT_MS)
      const ceilingMs = approvalCeiling(raw.approvalTimeoutMs)
      let result: Record<string, unknown>
      let capturedFrame: { base64: string; mediaType?: string } | undefined
      const ac = new AbortController(); abort.current = ac
      // Only a site-changing command needs sharing on, and only sharing: never a
      // particular panel closed, the dock open or this tab in front.
      activeNeedsSight.current = false
      try {
        if (permissions.current.epoch !== epoch || Date.now() - Number(raw.emittedAt) > ceilingMs) throw new Error('Command expired or permissions were reset before execution')
        if (raw.mode !== 'write-approval' && raw.action === 'connect') {
          await send({ conversationId: cid, requestId, phase: 'final', result: { ok: true, browserId: browserId.current, writeMode: modeRef.current, note: 'Connected to this tab. This conversation is bound to this tab automatically. No admin screen opened.' } })
          setOpen(true)
          return
        }
        const approvalOnly = raw.mode === 'write-approval'
        const cmd = approvalOnly ? { action: 'write', tool: String(raw.tool), args: raw.args } : parseAdminCommand(raw)
        const changesSite = !approvalOnly && commandChangesSite(cmd as AdminCommand)
        const needsApproval = approvalOnly || changesSite
        activeNeedsSight.current = changesSite
        setOpen(true)
        if (!approvalOnly) {
          onActivate()
          await afterPaint()
          requireSharingForWrite(sightLiveRef.current, changesSite)
        }
        if (needsWriteCard(modeRef.current, needsApproval)) {
          setPending(cmd); notify.current(); setStatus('Waiting for Allow or Deny. Nothing has run.')
          const decision = await waitForOwnerDecision(ceilingMs, fn => { answer.current = fn })
          setPending(null)
          if (decision !== 'allow') {
            if (decision === 'cancelled' && changesSite && !sightLiveRef.current()) throw new AdminSightRequired()
            unapproved = unapprovedResult(decision)
            throw new Error(String(unapproved.error))
          }
        }
        if (permissions.current.epoch !== epoch || ac.signal.aborted) throw new Error('Permission revoked')
        if (approvalOnly) {
          await send({ conversationId: cid, requestId, phase: 'final', result: { ok: true, approved: true } })
          setStatus('Write approved for this request.'); return
        }
        onActivate()
        await afterPaint()
        if (permissions.current.epoch !== epoch || ac.signal.aborted) throw new Error('Permission revoked')
        if (!frame.current) throw new Error('Admin browser is not mounted')
        if (!controller.current?.usesFrame(frame.current)) controller.current = new AdminBrowserController(frame.current, adminBase, undefined, c => !commandChangesSite(c) || sightLiveRef.current())
        requireSharingForWrite(sightLiveRef.current, changesSite)
        if (permissions.current.epoch !== epoch || ac.signal.aborted) throw new Error('Permission revoked')
        setStatus(`Running ${cmd.action}...`)
        dispatched = true
        result = await controller.current.run(cmd as AdminCommand, ac.signal)
        // Reuse the owner-granted share. No new capture grant, and no picture is
        // data on the result (sight.captured false + reason), never a stop.
        const shot: SightCapture = await observeAdminSight(sightLiveRef.current, captureRef.current)
        if (permissions.current.epoch !== epoch || ac.signal.aborted) throw new Error('Permission revoked during observation')
        const { base64, mediaType, ...sight } = shot
        result.sight = sight
        if (shot.captured && base64) capturedFrame = { base64, mediaType }
        setStatus(`${cmd.action} completed. Saved changes require a fresh verification.`)
      } catch (e) {
        // One typed outcome per failure (adminFailureResult); the backend owns the sentences.
        result = unapproved ?? adminFailureResult(e, { dispatched: e instanceof AdminBrowserError ? e.dispatched : dispatched,
          sightMissing: e instanceof AdminSightRequired })
        setStatus(String(result.error))
      } finally { clearInterval(heartbeat); abort.current = null; activeNeedsSight.current = false }
      await send( { conversationId: cid, requestId, phase: 'final', result: { ...result, browserId: browserId.current }, ...(capturedFrame ? { frame: capturedFrame } : {}) }).catch(() => setStatus('Could not deliver the result. Inspect the current screen before retrying.'))
      })
    }).catch(async e => {
      setStatus('Admin control failed before completion. Inspect before retrying.')
      await send( { conversationId: cid, requestId, phase: 'final', result: { ok: false, outcome: 'transport_disconnected', outcomeUnknown: true, error: String(e) } }).catch(() => undefined)
    })
  } }), [adminBase, conversationId])
  return <section aria-label="Write approval" hidden={!open} style={{ margin: '12px 0', border: '1px solid #d9ddd8', borderRadius: 12, padding: 14, background: '#f7f7f3' }}>
    {pending && <div role="alert">
      <strong>Allow this write?</strong>
      <p>{pending.tool || pending.action}{pending.path ? `: ${pending.path}` : ''}</p>
      <details><summary>Details</summary><pre style={{ whiteSpace: 'pre-wrap', maxHeight: 160, overflow: 'auto' }}>{JSON.stringify(pending, null, 2)}</pre></details>
      <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
        <button type="button" className="bb-btn bb-btn-primary" onClick={grant}>Allow</button>
        <button type="button" className="bb-btn" onClick={() => answer.current?.('deny')}>Deny</button>
      </div>
    </div>}
    <p role="status">{status}</p>
  </section>
})
