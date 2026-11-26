/**
 * ConfirmDialog — global, styled replacement for window.confirm().
 *
 * Usage:
 *   1. Mount <ConfirmProvider/> once (main.tsx, alongside ToastProvider).
 *   2. Anywhere (component OR plain async code):
 *        if (await confirm({ message: '…', danger: true })) { …do it }
 *        if (await confirm('Quick yes/no?')) { … }
 *
 * Promise-based and single-at-a-time. Mirrors the Toast module-singleton
 * pattern so it's callable from anywhere, exactly like window.confirm —
 * but rendered as a proper, on-brand modal with real spacing.
 */
import { useCallback, useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'

export interface ConfirmOptions {
  title?: string
  message: string
  confirmLabel?: string
  cancelLabel?: string
  /** Red commit button for destructive actions (delete / remove). */
  danger?: boolean
}

interface PendingConfirm extends ConfirmOptions {
  id: number
  resolve: (ok: boolean) => void
}

// Single module-level subscriber (the mounted provider). Simpler than
// context, and lets non-component code call confirm() too.
let listener: ((p: PendingConfirm) => void) | null = null
let counter = 0

export function confirm(opts: ConfirmOptions | string): Promise<boolean> {
  const options: ConfirmOptions = typeof opts === 'string' ? { message: opts } : opts
  return new Promise<boolean>((resolve) => {
    // Fail safe to native confirm if the provider isn't mounted (e.g. tests).
    if (!listener) { resolve(window.confirm(options.message)); return }
    listener({ id: ++counter, resolve, ...options })
  })
}

export function ConfirmProvider({ children }: { children: React.ReactNode }) {
  const [pending, setPending] = useState<PendingConfirm | null>(null)
  const confirmBtnRef = useRef<HTMLButtonElement>(null)

  const resolve = useCallback((ok: boolean) => {
    setPending((prev) => { prev?.resolve(ok); return null })
  }, [])

  useEffect(() => {
    // A new confirm() supersedes any open one (resolve the old as cancelled).
    listener = (p) => setPending((prev) => { prev?.resolve(false); return p })
    return () => { listener = null }
  }, [])

  useEffect(() => {
    if (!pending) return
    confirmBtnRef.current?.focus()
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') resolve(false)
      else if (e.key === 'Enter') resolve(true)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [pending, resolve])

  return (
    <>
      {children}
      {pending && createPortal(
        // Reuses the shared .bb-modal / .bb-confirm-modal markup so this
        // looks identical to the Forms + FAQ delete dialogs (one visual
        // language for every confirm in the app).
        <div
          className="bb-modal-backdrop bb-portal-scope"
          onClick={(e) => { if (e.target === e.currentTarget) resolve(false) }}
        >
          <div
            className="bb-modal bb-confirm-modal"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="bb-confirm-dialog-title"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="bb-modal-head">
              <div className="bb-modal-titles">
                <h2 id="bb-confirm-dialog-title" className="bb-modal-title">
                  {pending.title || 'Please confirm'}
                </h2>
              </div>
              <button
                type="button"
                className="bb-modal-close"
                onClick={() => resolve(false)}
                aria-label="Cancel"
              >
                ×
              </button>
            </div>
            <div className="bb-modal-body">
              <p className="bb-confirm-modal-body">{pending.message}</p>
            </div>
            <div className="bb-confirm-modal-actions">
              <button
                type="button"
                className="bb-btn bb-btn-ghost"
                onClick={() => resolve(false)}
              >
                {pending.cancelLabel || 'Cancel'}
              </button>
              <button
                ref={confirmBtnRef}
                type="button"
                className={`bb-btn ${pending.danger ? 'bb-btn-destructive' : 'bb-btn-primary'}`}
                onClick={() => resolve(true)}
              >
                {pending.confirmLabel || 'Confirm'}
              </button>
            </div>
          </div>
        </div>,
        document.body,
      )}
    </>
  )
}
