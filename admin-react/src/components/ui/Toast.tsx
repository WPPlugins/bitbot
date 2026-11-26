/**
 * Toast — global toast system using Radix's accessible primitive.
 *
 * Usage:
 *   1. Wrap the app in <ToastProvider /> (once, in App.tsx)
 *   2. Call useToast() inside any component:
 *        const toast = useToast()
 *        toast.success('Settings saved')
 *        toast.error('Could not save')
 *
 * Implementation: a tiny zustand-style store + Radix Toast primitives.
 * No external state lib needed.
 */
import * as RadixToast from '@radix-ui/react-toast'
import { useEffect, useState, useCallback } from 'react'

type ToastKind = 'success' | 'error' | 'info'
interface ToastEvent {
  id: number
  kind: ToastKind
  title: string
  description?: string
}

// Module-level singleton subscriber list. Simpler than React context.
const listeners = new Set<(t: ToastEvent) => void>()
let counter = 0

function emit(kind: ToastKind, title: string, description?: string) {
  const event: ToastEvent = { id: ++counter, kind, title, description }
  listeners.forEach((l) => l(event))
}

export const toast = {
  success: (title: string, description?: string) => emit('success', title, description),
  error:   (title: string, description?: string) => emit('error', title, description),
  info:    (title: string, description?: string) => emit('info', title, description),
}

export function useToast() {
  // Stable reference so callers can put `toast.success` in useEffect deps
  // without re-renders. Effectively a passthrough.
  return toast
}

// Max simultaneous toasts on screen. Anything beyond this drops the
// OLDEST one — keeps the screen clear during rapid-fire events (e.g.
// rapidly tweaking a setting that auto-saves). 3 is enough to show a
// recent success + an unrelated error if both happen near-simultaneously.
const MAX_VISIBLE_TOASTS = 3

export function ToastProvider({ children }: { children: React.ReactNode }) {
  const [items, setItems] = useState<ToastEvent[]>([])

  useEffect(() => {
    const handler = (t: ToastEvent) => setItems((prev) => {
      // Dedupe by (kind, title) — if an identical toast is already on
      // screen, refresh its id instead of stacking another one. Caps
      // the queue at MAX_VISIBLE_TOASTS by dropping the oldest.
      const existing = prev.findIndex((x) => x.kind === t.kind && x.title === t.title)
      if (existing >= 0) {
        const next = prev.slice()
        next[existing] = t
        return next
      }
      const next = [...prev, t]
      return next.length > MAX_VISIBLE_TOASTS
        ? next.slice(next.length - MAX_VISIBLE_TOASTS)
        : next
    })
    listeners.add(handler)
    return () => { listeners.delete(handler) }
  }, [])

  const remove = useCallback((id: number) => {
    setItems((prev) => prev.filter((t) => t.id !== id))
  }, [])

  return (
    <RadixToast.Provider swipeDirection="right" duration={5000}>
      {children}
      {items.map((t) => (
        <RadixToast.Root
          key={t.id}
          className={`bb-toast bb-toast-${t.kind}`}
          onOpenChange={(open) => { if (!open) remove(t.id) }}
        >
          <RadixToast.Title className="bb-toast-title">{t.title}</RadixToast.Title>
          {t.description && (
            <RadixToast.Description className="bb-toast-description">
              {t.description}
            </RadixToast.Description>
          )}
        </RadixToast.Root>
      ))}
      <RadixToast.Viewport className="bb-toast-viewport" />
    </RadixToast.Provider>
  )
}
